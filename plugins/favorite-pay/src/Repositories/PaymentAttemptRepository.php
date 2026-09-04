<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Repositories;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentStatus;

class PaymentAttemptRepository
{
    private ?Database $db;
    private PaymentServiceInterface $paymentService;

    public function __construct(PaymentServiceInterface $paymentService, ?Database $db = null)
    {
        $this->paymentService = $paymentService;
        $this->db = $db;
    }

    /**
     * Paginate and filter payment attempts for the verification queue.
     *
     * @param array{status?: string, gateway_id?: string, search?: string} $filters
     * @param int $page
     * @param int $perPage
     * @return array{items: array, total: int, page: int, perPage: int, totalPages: int, counts: array<string, int>}
     */
    public function listAttempts(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $statusFilter = isset($filters['status']) ? trim((string)$filters['status']) : 'awaiting_verification';
        $gatewayFilter = isset($filters['gateway_id']) ? trim((string)$filters['gateway_id']) : '';
        $search = isset($filters['search']) ? trim((string)$filters['search']) : '';

        // If DB table is available, perform optimized SQL queries
        if ($this->db !== null && $this->db->tableExists('favorite_pay_attempts')) {
            return $this->listAttemptsFromDatabase($statusFilter, $gatewayFilter, $search, $page, $perPage, $offset);
        }

        // Fallback to in-memory reflection / service storage for isolated testing
        return $this->listAttemptsFromService($statusFilter, $gatewayFilter, $search, $page, $perPage, $offset);
    }

    /**
     * Fetch complete detail record for one payment attempt, including transaction and customer info.
     */
    public function getAttemptDetail(string $attemptId): ?array
    {
        if ($this->db !== null && $this->db->tableExists('favorite_pay_attempts')) {
            $sql = "SELECT a.*, t.user_id, t.source_plugin, t.source_reference, t.base_amount as tx_base_amount, 
                           t.base_currency as tx_base_currency, t.status as tx_status, t.metadata as tx_metadata
                    FROM favorite_pay_attempts a
                    LEFT JOIN favorite_pay_transactions t ON a.transaction_id = t.transaction_id
                    WHERE a.attempt_id = ?
                    LIMIT 1";

            $row = $this->db->selectOne($sql, [$attemptId]);
            if (!$row) {
                return null;
            }

            $attemptData = (array)$row;

            // Resolve Customer / User information
            $userId = !empty($attemptData['user_id']) ? (int)$attemptData['user_id'] : null;
            $customer = null;
            if ($userId !== null && class_exists(User::class)) {
                try {
                    $user = User::find($userId);
                    if ($user) {
                        $customer = [
                            'id'       => $user->id,
                            'username' => $user->username ?? '',
                            'email'    => $user->email ?? '',
                            'name'     => $user->name ?? $user->username ?? '',
                        ];
                    }
                } catch (\Throwable) {
                }
            }
            $attemptData['customer'] = $customer;

            // Resolve Verifier name if verified
            $verifierId = !empty($attemptData['verified_by']) ? (int)$attemptData['verified_by'] : null;
            $verifierName = null;
            if ($verifierId !== null && class_exists(User::class)) {
                try {
                    $vUser = User::find($verifierId);
                    if ($vUser) {
                        $verifierName = $vUser->name ?? $vUser->username ?? ("User #" . $verifierId);
                    }
                } catch (\Throwable) {
                }
            }
            $attemptData['verifier_name'] = $verifierName;

            // Decode payloads
            $payload = [];
            if (!empty($attemptData['request_payload'])) {
                $decoded = json_decode((string)$attemptData['request_payload'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
            $attemptData['decoded_payload'] = $payload;
            $attemptData['sender_account'] = $payload['sender_account'] ?? null;
            $attemptData['customer_notes'] = $payload['notes'] ?? null;

            return $attemptData;
        }

        // Fallback to service layer domain objects
        $attempt = $this->paymentService->getAttempt($attemptId);
        if (!$attempt) {
            return null;
        }

        $intent = $this->paymentService->getIntent($attempt->getIntentId());

        return [
            'attempt_id'         => $attempt->getId(),
            'transaction_id'     => $attempt->getIntentId(),
            'gateway_id'         => $attempt->getGatewayId(),
            'amount'             => $attempt->getAmount()->getAmount(),
            'currency'           => $attempt->getAmount()->getCurrency(),
            'status'             => $attempt->getStatus()->value,
            'provider_reference' => $attempt->getTransactionReference(),
            'operator_notes'     => $attempt->getVerificationNotes(),
            'verified_by'        => $attempt->getVerifiedBy(),
            'verified_at'        => $attempt->getVerifiedAt(),
            'error_message'      => $attempt->getRejectionReason(),
            'created_at'         => date('Y-m-d H:i:s'),
            'user_id'            => $intent ? $intent->getUserId() : null,
            'source_plugin'      => $intent ? $intent->getSourcePlugin() : '',
            'source_reference'   => $intent ? $intent->getSourceReference() : '',
            'customer'           => null,
            'verifier_name'      => $attempt->getVerifiedBy() ? "Operator #" . $attempt->getVerifiedBy() : null,
            'decoded_payload'    => $attempt->getMetadata(),
            'sender_account'     => $attempt->getSenderAccount(),
            'customer_notes'     => $attempt->getMetadata()['notes'] ?? null,
        ];
    }

    private function listAttemptsFromDatabase(
        string $statusFilter,
        string $gatewayFilter,
        string $search,
        int $page,
        int $perPage,
        int $offset
    ): array {
        // Status counts for tabs
        $countsRows = $this->db->select("SELECT status, COUNT(*) as cnt FROM favorite_pay_attempts GROUP BY status");
        $counts = [
            'all'                   => 0,
            'awaiting_verification' => 0,
            'succeeded'             => 0,
            'failed'                => 0,
            'pending'               => 0,
        ];
        $totalAll = 0;
        foreach ($countsRows as $row) {
            $st = (string)$row->status;
            $c = (int)$row->cnt;
            $counts[$st] = $c;
            $totalAll += $c;
        }
        $counts['all'] = $totalAll;

        // Build filtered query
        $whereClauses = [];
        $bindings = [];

        if ($statusFilter !== 'all' && $statusFilter !== '') {
            $whereClauses[] = "a.status = ?";
            $bindings[] = $statusFilter;
        }

        if ($gatewayFilter !== 'all' && $gatewayFilter !== '') {
            $whereClauses[] = "a.gateway_id = ?";
            $bindings[] = $gatewayFilter;
        }

        if ($search !== '') {
            $whereClauses[] = "(a.transaction_id LIKE ? OR a.provider_reference LIKE ? OR a.attempt_id LIKE ?)";
            $searchTerm = "%{$search}%";
            $bindings[] = $searchTerm;
            $bindings[] = $searchTerm;
            $bindings[] = $searchTerm;
        }

        $whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

        // Total count for current filter
        $countRow = $this->db->selectOne(
            "SELECT COUNT(*) as total FROM favorite_pay_attempts a {$whereSql}",
            $bindings
        );
        $total = (int)($countRow->total ?? 0);

        // Fetch paginated rows
        $itemsSql = "SELECT a.*, t.user_id, t.source_plugin, t.source_reference
                     FROM favorite_pay_attempts a
                     LEFT JOIN favorite_pay_transactions t ON a.transaction_id = t.transaction_id
                     {$whereSql}
                     ORDER BY a.id DESC
                     LIMIT {$perPage} OFFSET {$offset}";

        $rawItems = $this->db->select($itemsSql, $bindings);
        $items = [];
        foreach ($rawItems as $row) {
            $item = (array)$row;
            $payload = [];
            if (!empty($item['request_payload'])) {
                $decoded = json_decode((string)$item['request_payload'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
            $item['sender_account'] = $payload['sender_account'] ?? null;
            $item['customer_notes'] = $payload['notes'] ?? null;
            $items[] = $item;
        }

        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => (int)ceil($total / $perPage),
            'counts'     => $counts,
        ];
    }

    private function listAttemptsFromService(
        string $statusFilter,
        string $gatewayFilter,
        string $search,
        int $page,
        int $perPage,
        int $offset
    ): array {
        $allAttempts = [];
        if ($this->paymentService instanceof \FavoriteCMS\Pay\Services\PaymentService) {
            $ref = new \ReflectionClass($this->paymentService);
            if ($ref->hasProperty('attempts')) {
                $prop = $ref->getProperty('attempts');
                $prop->setAccessible(true);
                $allAttempts = array_values($prop->getValue($this->paymentService));
            }
        }

        $counts = [
            'all'                   => count($allAttempts),
            'awaiting_verification' => 0,
            'succeeded'             => 0,
            'failed'                => 0,
            'pending'               => 0,
        ];

        foreach ($allAttempts as $att) {
            $st = $att->getStatus()->value;
            if (isset($counts[$st])) {
                $counts[$st]++;
            }
        }

        // Apply filters
        $filtered = array_filter($allAttempts, function (PaymentAttempt $att) use ($statusFilter, $gatewayFilter, $search) {
            if ($statusFilter !== 'all' && $statusFilter !== '' && $att->getStatus()->value !== $statusFilter) {
                return false;
            }
            if ($gatewayFilter !== 'all' && $gatewayFilter !== '' && $att->getGatewayId() !== $gatewayFilter) {
                return false;
            }
            if ($search !== '') {
                $searchLower = strtolower($search);
                $matchTrx = str_contains(strtolower((string)$att->getTransactionReference()), $searchLower);
                $matchTx = str_contains(strtolower($att->getIntentId()), $searchLower);
                $matchAtt = str_contains(strtolower($att->getId()), $searchLower);
                if (!$matchTrx && !$matchTx && !$matchAtt) {
                    return false;
                }
            }
            return true;
        });

        $total = count($filtered);
        $slice = array_slice(array_values($filtered), $offset, $perPage);

        $items = [];
        foreach ($slice as $att) {
            $intent = $this->paymentService->getIntent($att->getIntentId());
            $items[] = [
                'attempt_id'         => $att->getId(),
                'transaction_id'     => $att->getIntentId(),
                'gateway_id'         => $att->getGatewayId(),
                'amount'             => $att->getAmount()->getAmount(),
                'currency'           => $att->getAmount()->getCurrency(),
                'status'             => $att->getStatus()->value,
                'provider_reference' => $att->getTransactionReference(),
                'sender_account'     => $att->getSenderAccount(),
                'customer_notes'     => $att->getMetadata()['notes'] ?? null,
                'verified_by'        => $att->getVerifiedBy(),
                'verified_at'        => $att->getVerifiedAt(),
                'error_message'      => $att->getRejectionReason(),
                'created_at'         => date('Y-m-d H:i:s'),
                'user_id'            => $intent ? $intent->getUserId() : null,
                'source_plugin'      => $intent ? $intent->getSourcePlugin() : '',
                'source_reference'   => $intent ? $intent->getSourceReference() : '',
            ];
        }

        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => (int)ceil($total / $perPage),
            'counts'     => $counts,
        ];
    }
}
