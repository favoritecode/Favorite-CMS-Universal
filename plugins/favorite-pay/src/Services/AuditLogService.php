<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Services;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\AuditLogServiceInterface;
use FavoriteCMS\Pay\Support\SafeLogger;
use Throwable;

/**
 * Class AuditLogService
 *
 * Lightweight, shared-hosting friendly audit logger for Favorite Pay.
 * Completely decoupled from accounting ledgers.
 * Non-blocking: failures are logged via SafeLogger and never break caller transactions.
 */
class AuditLogService implements AuditLogServiceInterface
{
    private ?Database $db;

    /**
     * In-memory storage for test/cache isolation when DB is absent.
     * @var array<int, array>
     */
    private array $inMemoryLogs = [];
    private int $inMemoryNextId = 1;

    public function __construct(?Database $db = null)
    {
        $this->db = $db;
    }

    /**
     * {@inheritdoc}
     */
    public function log(
        string $action,
        string $subjectType,
        ?string $subjectId = null,
        ?int $targetUserId = null,
        array $metadata = [],
        ?string $description = null,
        ?int $actorUserId = null,
        ?string $actorType = null,
        ?string $actorName = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $withdrawalId = null,
        ?string $paymentId = null
    ): ?int {
        try {
            // 1. Resolve Actor identity
            $resolvedActorId = $actorUserId;
            if ($resolvedActorId === null) {
                if (isset($GLOBALS['_test_current_user']) && $GLOBALS['_test_current_user'] instanceof User) {
                    $resolvedActorId = (int)$GLOBALS['_test_current_user']->id;
                } else {
                    $sessionUid = (int)($_SESSION['auth_user_id'] ?? $_SESSION['user_id'] ?? 0);
                    if ($sessionUid > 0) {
                        $resolvedActorId = $sessionUid;
                    } else {
                        try {
                            if (function_exists('current_user') && ($cu = current_user()) instanceof User) {
                                $resolvedActorId = (int)$cu->id;
                            }
                        } catch (Throwable) {
                        }
                    }
                }
            }

            // 2. Resolve Actor Type and Name
            $resolvedActorType = $actorType;
            $resolvedActorName = $actorName;

            if ($resolvedActorId !== null && $resolvedActorId > 0) {
                if ($resolvedActorType === null) {
                    $isSuper = false;
                    $isAdmin = false;
                    if (isset($GLOBALS['_test_current_user']) && $GLOBALS['_test_current_user'] instanceof User) {
                        $isSuper = $GLOBALS['_test_current_user']->hasRole('super-admin');
                        $isAdmin = $GLOBALS['_test_current_user']->hasRole('admin');
                    } else {
                        try {
                            if (function_exists('current_user') && ($cu = current_user()) instanceof User && (int)$cu->id === $resolvedActorId) {
                                $isSuper = $cu->hasRole('super-admin');
                                $isAdmin = $cu->hasRole('admin');
                            }
                        } catch (Throwable) {
                        }
                    }

                    if ($isSuper || $isAdmin) {
                        $resolvedActorType = 'admin';
                    } elseif ($targetUserId !== null && $resolvedActorId === $targetUserId) {
                        $resolvedActorType = 'customer';
                    } else {
                        $resolvedActorType = 'admin';
                    }
                }

                if ($resolvedActorName === null) {
                    $resolvedActorName = $this->resolveUserName($resolvedActorId);
                }
            } else {
                $resolvedActorType = $resolvedActorType ?? 'system';
                $resolvedActorName = $resolvedActorName ?? 'System';
            }

            // 3. Resolve IP and User Agent
            $resolvedIp = $ipAddress;
            if ($resolvedIp === null && isset($_SERVER['REMOTE_ADDR'])) {
                $resolvedIp = substr((string)$_SERVER['REMOTE_ADDR'], 0, 45);
            }

            $resolvedUa = $userAgent;
            if ($resolvedUa === null && isset($_SERVER['HTTP_USER_AGENT'])) {
                $resolvedUa = substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255);
            }

            // 4. Resolve Withdrawal & Payment references
            $resolvedWdId = $withdrawalId;
            if ($resolvedWdId === null && $subjectType === 'withdrawal' && $subjectId !== null) {
                $resolvedWdId = $subjectId;
            }

            $resolvedPayId = $paymentId;
            if ($resolvedPayId === null && ($subjectType === 'recharge' || $subjectType === 'payment') && $subjectId !== null) {
                $resolvedPayId = $subjectId;
            }

            // 5. Sanitize metadata (remove secrets/passwords/keys)
            $sanitizedMeta = SafeLogger::sanitize($metadata);
            $metaJson = !empty($sanitizedMeta) ? json_encode($sanitizedMeta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

            // 6. Generate fallback description if missing
            $desc = $description ?? $this->generateDefaultDescription($action, $subjectType, $subjectId, $resolvedActorName);
            $desc = substr($desc, 0, 255);

            $now = date('Y-m-d H:i:s');

            $record = [
                'actor_user_id'  => $resolvedActorId,
                'actor_type'     => substr((string)$resolvedActorType, 0, 32),
                'actor_name'     => $resolvedActorName !== null ? substr($resolvedActorName, 0, 128) : null,
                'action'         => substr($action, 0, 64),
                'subject_type'   => substr($subjectType, 0, 64),
                'subject_id'     => $subjectId !== null ? substr($subjectId, 0, 64) : null,
                'withdrawal_id'  => $resolvedWdId !== null ? substr($resolvedWdId, 0, 64) : null,
                'payment_id'     => $resolvedPayId !== null ? substr($resolvedPayId, 0, 64) : null,
                'target_user_id' => $targetUserId,
                'description'    => $desc,
                'metadata'       => $metaJson,
                'ip_address'     => $resolvedIp,
                'user_agent'     => $resolvedUa,
                'created_at'     => $now,
            ];

            // 7. Persist to DB or in-memory
            if ($this->db !== null && $this->db->tableExists('favorite_pay_audit_logs')) {
                $this->db->insert('favorite_pay_audit_logs', $record);
                $pdo = method_exists($this->db, 'getPdo') ? $this->db->getPdo() : $this->db->getConnection();
                $insertedId = (int)$pdo->lastInsertId();
                return $insertedId > 0 ? $insertedId : null;
            }

            // In-memory fallback
            $id = $this->inMemoryNextId++;
            $record['id'] = $id;
            $this->inMemoryLogs[$id] = $record;
            return $id;

        } catch (Throwable $e) {
            // Non-blocking: log failure and never bubble up exception
            SafeLogger::error("Failed to write Favorite Pay audit log: " . $e->getMessage(), [
                'action'       => $action,
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
            ]);
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function listLogs(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        if ($this->db !== null && $this->db->tableExists('favorite_pay_audit_logs')) {
            [$whereSql, $bindings] = $this->buildFilterClauses($filters);

            $countRow = $this->db->selectOne("SELECT COUNT(*) as cnt FROM favorite_pay_audit_logs {$whereSql}", $bindings);
            $total = (int)($countRow->cnt ?? 0);

            $sql = "SELECT * FROM favorite_pay_audit_logs {$whereSql} ORDER BY created_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}";
            $rows = $this->db->select($sql, $bindings);

            $items = [];
            foreach ($rows as $r) {
                $item = (array)$r;
                if (!empty($item['metadata']) && is_string($item['metadata'])) {
                    $decoded = json_decode($item['metadata'], true);
                    $item['metadata_parsed'] = is_array($decoded) ? $decoded : [];
                } else {
                    $item['metadata_parsed'] = [];
                }
                $items[] = $item;
            }

            $totalPages = $limit > 0 ? (int)ceil($total / $limit) : 1;
            return [
                'items'      => $items,
                'total'      => $total,
                'page'       => (int)floor($offset / $limit) + 1,
                'limit'      => $limit,
                'totalPages' => max(1, $totalPages),
            ];
        }

        // In-memory fallback
        $filtered = [];
        foreach ($this->inMemoryLogs as $item) {
            if (!$this->matchesInMemoryFilters($item, $filters)) {
                continue;
            }
            $filtered[] = $item;
        }

        // Sort newest first
        usort($filtered, function ($a, $b) {
            $cmp = strcmp($b['created_at'], $a['created_at']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return ($b['id'] ?? 0) <=> ($a['id'] ?? 0);
        });

        $total = count($filtered);
        $sliced = array_slice($filtered, $offset, $limit);

        foreach ($sliced as &$item) {
            if (!empty($item['metadata']) && is_string($item['metadata'])) {
                $decoded = json_decode($item['metadata'], true);
                $item['metadata_parsed'] = is_array($decoded) ? $decoded : [];
            } else {
                $item['metadata_parsed'] = [];
            }
        }
        unset($item);

        $totalPages = $limit > 0 ? (int)ceil($total / $limit) : 1;
        return [
            'items'      => $sliced,
            'total'      => $total,
            'page'       => (int)floor($offset / $limit) + 1,
            'limit'      => $limit,
            'totalPages' => max(1, $totalPages),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getLog(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        if ($this->db !== null && $this->db->tableExists('favorite_pay_audit_logs')) {
            $row = $this->db->selectOne("SELECT * FROM favorite_pay_audit_logs WHERE id = ? LIMIT 1", [$id]);
            if (!$row) {
                return null;
            }
            $item = (array)$row;
            if (!empty($item['metadata']) && is_string($item['metadata'])) {
                $decoded = json_decode($item['metadata'], true);
                $item['metadata_parsed'] = is_array($decoded) ? $decoded : [];
            } else {
                $item['metadata_parsed'] = [];
            }
            return $item;
        }

        if (isset($this->inMemoryLogs[$id])) {
            $item = $this->inMemoryLogs[$id];
            if (!empty($item['metadata']) && is_string($item['metadata'])) {
                $decoded = json_decode($item['metadata'], true);
                $item['metadata_parsed'] = is_array($decoded) ? $decoded : [];
            } else {
                $item['metadata_parsed'] = [];
            }
            return $item;
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getWithdrawalLogs(string $withdrawalId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));

        if ($this->db !== null && $this->db->tableExists('favorite_pay_audit_logs')) {
            $rows = $this->db->select(
                "SELECT * FROM favorite_pay_audit_logs 
                 WHERE withdrawal_id = ? OR (subject_type = 'withdrawal' AND subject_id = ?)
                 ORDER BY created_at DESC, id DESC LIMIT {$limit}",
                [$withdrawalId, $withdrawalId]
            );

            $items = [];
            foreach ($rows as $r) {
                $item = (array)$r;
                if (!empty($item['metadata']) && is_string($item['metadata'])) {
                    $decoded = json_decode($item['metadata'], true);
                    $item['metadata_parsed'] = is_array($decoded) ? $decoded : [];
                } else {
                    $item['metadata_parsed'] = [];
                }
                $items[] = $item;
            }
            return $items;
        }

        $items = [];
        foreach ($this->inMemoryLogs as $item) {
            if (($item['withdrawal_id'] ?? '') === $withdrawalId || (($item['subject_type'] ?? '') === 'withdrawal' && ($item['subject_id'] ?? '') === $withdrawalId)) {
                $copy = $item;
                if (!empty($copy['metadata']) && is_string($copy['metadata'])) {
                    $decoded = json_decode($copy['metadata'], true);
                    $copy['metadata_parsed'] = is_array($decoded) ? $decoded : [];
                } else {
                    $copy['metadata_parsed'] = [];
                }
                $items[] = $copy;
            }
        }

        usort($items, function ($a, $b) {
            return strcmp($b['created_at'], $a['created_at']);
        });

        return array_slice($items, 0, $limit);
    }

    private function buildFilterClauses(array $filters): array
    {
        $clauses = [];
        $bindings = [];

        if (!empty($filters['action']) && $filters['action'] !== 'all') {
            $clauses[] = 'action = ?';
            $bindings[] = (string)$filters['action'];
        }

        if (!empty($filters['actor_type']) && $filters['actor_type'] !== 'all') {
            $clauses[] = 'actor_type = ?';
            $bindings[] = (string)$filters['actor_type'];
        }

        if (isset($filters['actor_user_id']) && (int)$filters['actor_user_id'] > 0) {
            $clauses[] = 'actor_user_id = ?';
            $bindings[] = (int)$filters['actor_user_id'];
        }

        if (isset($filters['target_user_id']) && (int)$filters['target_user_id'] > 0) {
            $clauses[] = 'target_user_id = ?';
            $bindings[] = (int)$filters['target_user_id'];
        }

        if (!empty($filters['subject_type']) && $filters['subject_type'] !== 'all') {
            $clauses[] = 'subject_type = ?';
            $bindings[] = (string)$filters['subject_type'];
        }

        if (!empty($filters['withdrawal_id'])) {
            $clauses[] = '(withdrawal_id = ? OR (subject_type = \'withdrawal\' AND subject_id = ?))';
            $bindings[] = trim((string)$filters['withdrawal_id']);
            $bindings[] = trim((string)$filters['withdrawal_id']);
        }

        if (!empty($filters['payment_id'])) {
            $clauses[] = '(payment_id = ? OR ((subject_type = \'payment\' OR subject_type = \'recharge\') AND subject_id = ?))';
            $bindings[] = trim((string)$filters['payment_id']);
            $bindings[] = trim((string)$filters['payment_id']);
        }

        if (!empty($filters['date_from'])) {
            $from = trim((string)$filters['date_from']);
            if (strlen($from) === 10) {
                $from .= ' 00:00:00';
            }
            $clauses[] = 'created_at >= ?';
            $bindings[] = $from;
        }

        if (!empty($filters['date_to'])) {
            $to = trim((string)$filters['date_to']);
            if (strlen($to) === 10) {
                $to .= ' 23:59:59';
            }
            $clauses[] = 'created_at <= ?';
            $bindings[] = $to;
        }

        if (!empty($filters['search'])) {
            $term = '%' . trim((string)$filters['search']) . '%';
            $clauses[] = '(description LIKE ? OR action LIKE ? OR actor_name LIKE ? OR subject_id LIKE ? OR metadata LIKE ?)';
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }

        $whereSql = !empty($clauses) ? 'WHERE ' . implode(' AND ', $clauses) : '';
        return [$whereSql, $bindings];
    }

    private function matchesInMemoryFilters(array $item, array $filters): bool
    {
        if (!empty($filters['action']) && $filters['action'] !== 'all' && ($item['action'] ?? '') !== $filters['action']) {
            return false;
        }

        if (!empty($filters['actor_type']) && $filters['actor_type'] !== 'all' && ($item['actor_type'] ?? '') !== $filters['actor_type']) {
            return false;
        }

        if (isset($filters['actor_user_id']) && (int)$filters['actor_user_id'] > 0 && (int)($item['actor_user_id'] ?? 0) !== (int)$filters['actor_user_id']) {
            return false;
        }

        if (isset($filters['target_user_id']) && (int)$filters['target_user_id'] > 0 && (int)($item['target_user_id'] ?? 0) !== (int)$filters['target_user_id']) {
            return false;
        }

        if (!empty($filters['subject_type']) && $filters['subject_type'] !== 'all' && ($item['subject_type'] ?? '') !== $filters['subject_type']) {
            return false;
        }

        if (!empty($filters['withdrawal_id'])) {
            $wd = trim((string)$filters['withdrawal_id']);
            if (($item['withdrawal_id'] ?? '') !== $wd && !(($item['subject_type'] ?? '') === 'withdrawal' && ($item['subject_id'] ?? '') === $wd)) {
                return false;
            }
        }

        if (!empty($filters['payment_id'])) {
            $p = trim((string)$filters['payment_id']);
            if (($item['payment_id'] ?? '') !== $p && !(($item['subject_id'] ?? '') === $p)) {
                return false;
            }
        }

        if (!empty($filters['date_from'])) {
            $from = trim((string)$filters['date_from']);
            if (strlen($from) === 10) {
                $from .= ' 00:00:00';
            }
            if (($item['created_at'] ?? '') < $from) {
                return false;
            }
        }

        if (!empty($filters['date_to'])) {
            $to = trim((string)$filters['date_to']);
            if (strlen($to) === 10) {
                $to .= ' 23:59:59';
            }
            if (($item['created_at'] ?? '') > $to) {
                return false;
            }
        }

        if (!empty($filters['search'])) {
            $s = strtolower(trim((string)$filters['search']));
            $haystack = strtolower(
                ($item['description'] ?? '') . ' ' .
                ($item['action'] ?? '') . ' ' .
                ($item['actor_name'] ?? '') . ' ' .
                ($item['subject_id'] ?? '') . ' ' .
                ($item['metadata'] ?? '')
            );
            if (!str_contains($haystack, $s)) {
                return false;
            }
        }

        return true;
    }

    private function resolveUserName(int $userId): string
    {
        if ($userId <= 0) {
            return 'System';
        }

        if (isset($GLOBALS['_test_current_user']) && $GLOBALS['_test_current_user'] instanceof User && (int)$GLOBALS['_test_current_user']->id === $userId) {
            return (string)($GLOBALS['_test_current_user']->username ?? $GLOBALS['_test_current_user']->name ?? "User #{$userId}");
        }

        if ($this->db !== null && $this->db->tableExists('users')) {
            $row = $this->db->selectOne("SELECT username, name FROM users WHERE id = ? LIMIT 1", [$userId]);
            if ($row) {
                return (string)($row->name ?? $row->username ?? "User #{$userId}");
            }
        }

        if (class_exists(User::class)) {
            try {
                $u = User::find($userId);
                if ($u && !empty($u->username)) {
                    return (string)($u->name ?? $u->username);
                }
            } catch (Throwable) {
            }
        }

        return "User #{$userId}";
    }

    private function generateDefaultDescription(string $action, string $subjectType, ?string $subjectId, string $actorName): string
    {
        $idPart = $subjectId !== null ? " #{$subjectId}" : '';
        return "{$actorName} performed '{$action}' on {$subjectType}{$idPart}";
    }
}
