<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Controllers;

use DateTimeImmutable;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Contracts\WalletServiceInterface;
use FavoriteCMS\Pay\Contracts\WithdrawalServiceInterface;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Permissions\PaymentPermission;
use ReflectionClass;
use Throwable;

/**
 * Favorite Pay — Phase 9: Admin Financial Dashboard & Wallet Monitoring Controller.
 *
 * Strictly READ-ONLY: performs zero financial mutations, zero hold mutations,
 * zero settlements, and zero notification dispatches.
 */
class FinancialDashboardAdminController
{
    private Application $app;
    private WalletServiceInterface $walletService;
    private WithdrawalServiceInterface $withdrawalService;
    private PaymentServiceInterface $paymentService;
    private ?Database $db;
    private ?CurrencyServiceInterface $currencyService;

    public function __construct(
        Application $app,
        WalletServiceInterface $walletService,
        WithdrawalServiceInterface $withdrawalService,
        PaymentServiceInterface $paymentService,
        ?CurrencyServiceInterface $currencyService = null,
        ?Database $db = null
    ) {
        $this->app = $app;
        $this->walletService = $walletService;
        $this->withdrawalService = $withdrawalService;
        $this->paymentService = $paymentService;
        $this->currencyService = $currencyService;
        $this->db = $db;

        if ($this->db === null && method_exists($this->app, 'has') && $this->app->has(Database::class)) {
            $this->db = $this->app->make(Database::class);
        }
        if ($this->currencyService === null && method_exists($this->app, 'has') && $this->app->has(CurrencyServiceInterface::class)) {
            $this->currencyService = $this->app->make(CurrencyServiceInterface::class);
        }
    }

    /**
     * Main dispatcher.
     */
    public function handle(Request $request): Response|string
    {
        // 1. Authenticate user
        $userId = (int)($_SESSION['auth_user_id'] ?? $_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return Response::redirect('/admin/login');
        }

        $currentUser = $this->resolveCurrentUser($userId);
        if (!$currentUser || (method_exists($currentUser, 'isBanned') && $currentUser->isBanned())) {
            return Response::make('<h1>403 Access Denied</h1><p>Your account is banned or inactive.</p>', 403);
        }

        // 2. Authorize via existing read-only permissions
        if (!PaymentPermission::canView($currentUser) && !PaymentPermission::canViewWithdrawals($currentUser)) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to view Favorite Pay financial dashboard.</p>', 403);
        }

        // 3. Dispatch action
        $action = (string)$request->get('action', 'index');
        return match ($action) {
            'customer' => $this->customerDetail($request, $currentUser),
            default    => $this->index($request, $currentUser),
        };
    }

    /**
     * Main Financial Dashboard view.
     */
    public function index(Request $request, ?User $currentUser = null): string
    {
        $primaryCurrency = $this->walletService->getPrimaryCurrency();
        $canViewPayments = PaymentPermission::canView($currentUser);
        $canViewWithdrawals = PaymentPermission::canViewWithdrawals($currentUser);

        // 1. Authoritative Current Global Wallet Overview (Lifetime/Current balances)
        $walletOverview = $this->walletService->getGlobalWalletOverview();

        // 2. Date Range Parsing
        $range = $this->parseDateRange($request);
        $periodFilters = [];
        if ($range['startDate'] !== null) {
            $periodFilters['date_from'] = substr($range['startDate'], 0, 10);
        }
        if ($range['endDate'] !== null) {
            $periodFilters['date_to'] = substr($range['endDate'], 0, 10);
        }

        // 3. Actionable Withdrawal Queue (Pending, Approved, Processing) - Gated by canViewWithdrawals
        $actionableQueue = $canViewWithdrawals ? $this->getActionableWithdrawalQueue($primaryCurrency) : null;

        // 4. Period Withdrawal Summary - Gated by canViewWithdrawals
        $withdrawalSummary = $canViewWithdrawals ? $this->withdrawalService->getSummary($periodFilters) : null;

        // 5. Period Recharge & Payment Summary - Gated by canViewPayments
        $rechargeSummary = $canViewPayments ? $this->getPeriodRechargeSummary($range['startDate'], $range['endDate'], $primaryCurrency) : null;

        // 6. Period Financial Movement Flow
        $rechargeVolumeCents = $rechargeSummary ? $rechargeSummary['credited_base_cents'] : 0;
        $paidWithdrawalCents = $withdrawalSummary ? (int)($withdrawalSummary['totals']['paid_net_cents'] ?? 0) : 0;
        $recordedFeesCents = $withdrawalSummary ? (int)($withdrawalSummary['totals']['fee_cents'] ?? 0) : 0;
        $netFlowCents = $rechargeVolumeCents - $paidWithdrawalCents;

        $financialFlow = [
            'recharge_volume' => new Money($rechargeVolumeCents, $primaryCurrency),
            'paid_withdrawal' => new Money($paidWithdrawalCents, $primaryCurrency),
            'recorded_fees'   => new Money($recordedFeesCents, $primaryCurrency),
            'net_flow'        => new Money($netFlowCents, $primaryCurrency),
        ];

        // 7. Customer Wallet Search
        $searchQuery = trim((string)$request->get('search', ''));
        $searchedWallets = $this->walletService->searchCustomerWallets($searchQuery, 15);

        // 8. Global Recent Financial Activity
        $recentActivity = $this->walletService->getGlobalRecentActivity(15);

        return $this->renderView('dashboard', [
            'currentUser'        => $currentUser,
            'canViewPayments'    => $canViewPayments,
            'canViewWithdrawals' => $canViewWithdrawals,
            'primaryCurrency'    => $primaryCurrency,
            'walletOverview'     => $walletOverview,
            'range'              => $range,
            'actionableQueue'    => $actionableQueue,
            'withdrawalSummary'  => $withdrawalSummary,
            'rechargeSummary'    => $rechargeSummary,
            'financialFlow'      => $financialFlow,
            'searchQuery'        => $searchQuery,
            'searchedWallets'    => $searchedWallets,
            'recentActivity'     => $recentActivity,
        ]);
    }

    /**
     * Customer Wallet Detail view (/admin/page/favorite-pay-dashboard?action=customer&user_id=...).
     */
    public function customerDetail(Request $request, ?User $currentUser = null): Response|string
    {
        $targetUserId = (int)$request->get('user_id', 0);
        if ($targetUserId <= 0) {
            return Response::redirect('/admin/page/favorite-pay-dashboard');
        }

        $primaryCurrency = $this->walletService->getPrimaryCurrency();
        $canViewPayments = PaymentPermission::canView($currentUser);
        $canViewWithdrawals = PaymentPermission::canViewWithdrawals($currentUser);

        // Customer balances (authoritative)
        $avail = $this->walletService->getAvailableBalance($targetUserId);
        $held = $this->walletService->getHeldBalance($targetUserId);
        $total = $this->walletService->getTotalBalance($targetUserId);

        // Customer identity
        $targetUser = null;
        if (class_exists(User::class)) {
            try {
                $targetUser = User::find($targetUserId);
            } catch (Throwable) {
            }
        }
        $username = $targetUser?->username ?? ('User #' . $targetUserId);
        $email = $targetUser?->email ?? '—';
        $status = $targetUser?->status ?? 'active';

        // Recent ledger entries (bounded to 20)
        $ledgerEntries = $this->walletService->getLedgerHistory($targetUserId, 20);

        // Recent recharges - Gated by canViewPayments
        $recharges = $canViewPayments ? $this->getCustomerRecharges($targetUserId, 15) : null;

        // Recent withdrawals - Gated by canViewWithdrawals
        $withdrawals = $canViewWithdrawals ? $this->withdrawalService->getUserWithdrawals($targetUserId, 15) : null;

        return $this->renderView('customer_wallet', [
            'currentUser'        => $currentUser,
            'canViewPayments'    => $canViewPayments,
            'canViewWithdrawals' => $canViewWithdrawals,
            'targetUserId'       => $targetUserId,
            'username'           => $username,
            'email'              => $email,
            'status'             => $status,
            'primaryCurrency'    => $primaryCurrency,
            'available'          => $avail,
            'held'               => $held,
            'total'              => $total,
            'ledgerEntries'      => $ledgerEntries,
            'recharges'          => $recharges,
            'withdrawals'        => $withdrawals,
        ]);
    }

    /**
     * Actionable withdrawal queue: counts & amount held by active withdrawals.
     */
    protected function getActionableWithdrawalQueue(string $currency): array
    {
        if ($this->db !== null && $this->db->tableExists('favorite_pay_withdrawals')) {
            $row = $this->db->selectOne(
                "SELECT 
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_cnt,
                    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_cnt,
                    COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing_cnt,
                    COALESCE(SUM(CASE WHEN status IN ('pending', 'approved', 'processing') THEN amount ELSE 0 END), 0) as held_amt
                 FROM favorite_pay_withdrawals"
            );

            return [
                'pending_count'    => (int)($row->pending_cnt ?? 0),
                'approved_count'   => (int)($row->approved_cnt ?? 0),
                'processing_count' => (int)($row->processing_cnt ?? 0),
                'total_held'       => new Money((int)($row->held_amt ?? 0), $currency),
            ];
        }

        // In-memory fallback
        $summary = $this->withdrawalService->getSummary();
        $counts = $summary['counts'] ?? [];
        $pending = (int)($counts['pending'] ?? 0);
        $approved = (int)($counts['approved'] ?? 0);
        $processing = (int)($counts['processing'] ?? 0);

        // Calculate held funds in in-memory list
        $all = $this->withdrawalService->listWithdrawals([], 500)['items'] ?? [];
        $heldCents = 0;
        foreach ($all as $w) {
            if (in_array($w->getStatus()->value, ['pending', 'approved', 'processing'], true)) {
                $heldCents += $w->getAmount()->getAmount();
            }
        }

        return [
            'pending_count'    => $pending,
            'approved_count'   => $approved,
            'processing_count' => $processing,
            'total_held'       => new Money($heldCents, $currency),
        ];
    }

    /**
     * Period recharge and payment summary.
     */
    protected function getPeriodRechargeSummary(?string $startDate, ?string $endDate, string $currency): array
    {
        if ($this->db !== null && $this->db->tableExists('favorite_pay_transactions')) {
            $where = [];
            $params = [];

            if ($startDate !== null) {
                $where[] = 'created_at >= ?';
                $params[] = $startDate;
            }
            if ($endDate !== null) {
                $where[] = 'created_at <= ?';
                $params[] = $endDate;
            }

            $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

            $row = $this->db->selectOne(
                "SELECT 
                    COUNT(*) as total_cnt,
                    COUNT(CASE WHEN status = 'succeeded' THEN 1 END) as succ_cnt,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pend_cnt,
                    COUNT(CASE WHEN status IN ('failed', 'cancelled') THEN 1 END) as fail_cnt,
                    COALESCE(SUM(CASE WHEN status = 'succeeded' THEN base_amount ELSE 0 END), 0) as succ_base_amt,
                    COALESCE(SUM(CASE WHEN status = 'succeeded' THEN charge_amount ELSE 0 END), 0) as succ_charge_amt
                 FROM favorite_pay_transactions {$whereSql}",
                $params
            );

            $succBaseCents = (int)($row->succ_base_amt ?? 0);
            $succChargeCents = (int)($row->succ_charge_amt ?? 0);

            return [
                'total_count'          => (int)($row->total_cnt ?? 0),
                'succeeded_count'      => (int)($row->succ_cnt ?? 0),
                'pending_count'        => (int)($row->pend_cnt ?? 0),
                'failed_count'         => (int)($row->fail_cnt ?? 0),
                'credited_base_cents'  => $succBaseCents,
                'credited_base_money'  => new Money($succBaseCents, $currency),
                'charge_amount_cents'  => $succChargeCents,
            ];
        }

        // In-memory fallback
        $total = 0;
        $succ = 0;
        $pend = 0;
        $fail = 0;
        $succBaseCents = 0;
        $succChargeCents = 0;

        if ($this->paymentService instanceof \FavoriteCMS\Pay\Services\PaymentService) {
            try {
                $ref = new ReflectionClass($this->paymentService);
                if ($ref->hasProperty('intents')) {
                    $prop = $ref->getProperty('intents');
                    $prop->setAccessible(true);
                    $intents = $prop->getValue($this->paymentService) ?: [];
                    foreach ($intents as $intent) {
                        $created = $intent->getCreatedAt();
                        if ($startDate !== null && $created < $startDate) {
                            continue;
                        }
                        if ($endDate !== null && $created > $endDate) {
                            continue;
                        }

                        $total++;
                        $st = $intent->getStatus();
                        if ($st === PaymentStatus::SUCCEEDED) {
                            $succ++;
                            $succBaseCents += $intent->getBaseAmount()->getAmount();
                            $succChargeCents += $intent->getChargeAmount()->getAmount();
                        } elseif ($st === PaymentStatus::PENDING) {
                            $pend++;
                        } elseif ($st === PaymentStatus::FAILED || $st === PaymentStatus::CANCELLED) {
                            $fail++;
                        }
                    }
                }
            } catch (Throwable) {
            }
        }

        return [
            'total_count'          => $total,
            'succeeded_count'      => $succ,
            'pending_count'        => $pend,
            'failed_count'         => $fail,
            'credited_base_cents'  => $succBaseCents,
            'credited_base_money'  => new Money($succBaseCents, $currency),
            'charge_amount_cents'  => $succChargeCents,
        ];
    }

    /**
     * Retrieve recent recharges for a customer.
     */
    protected function getCustomerRecharges(int $userId, int $limit = 15): array
    {
        if ($this->db !== null && $this->db->tableExists('favorite_pay_transactions')) {
            $rows = $this->db->select(
                "SELECT * FROM favorite_pay_transactions 
                 WHERE user_id = ? 
                 ORDER BY id DESC LIMIT {$limit}",
                [$userId]
            );
            return array_map(fn($r) => (array)$r, $rows);
        }

        // In-memory fallback
        $results = [];
        if ($this->paymentService instanceof \FavoriteCMS\Pay\Services\PaymentService) {
            try {
                $ref = new ReflectionClass($this->paymentService);
                if ($ref->hasProperty('intents')) {
                    $prop = $ref->getProperty('intents');
                    $prop->setAccessible(true);
                    $intents = $prop->getValue($this->paymentService) ?: [];
                    foreach ($intents as $intent) {
                        if ((int)$intent->getUserId() === $userId) {
                            $results[] = [
                                'transaction_id'      => $intent->getId(),
                                'gateway_id'          => $intent->getGatewayId() ?? 'manual_payment',
                                'base_amount'         => $intent->getBaseAmount()->getAmount(),
                                'base_currency'       => $intent->getBaseAmount()->getCurrency(),
                                'charge_amount'       => $intent->getChargeAmount()->getAmount(),
                                'charge_currency'     => $intent->getChargeAmount()->getCurrency(),
                                'status'              => $intent->getStatus()->value,
                                'created_at'          => $intent->getCreatedAt(),
                            ];
                        }
                    }
                }
            } catch (Throwable) {
            }
        }
        return array_slice(array_reverse($results), 0, $limit);
    }

    /**
     * Parse date range selection from request with proper calendar boundaries.
     *
     * @return array{
     *     period: string,
     *     periodLabel: string,
     *     startDate: string|null,
     *     endDate: string|null,
     *     date_from: string,
     *     date_to: string
     * }
     */
    public function parseDateRange(Request $request): array
    {
        $period = (string)$request->get('period', 'this_month');
        $rawFrom = trim((string)$request->get('date_from', ''));
        $rawTo = trim((string)$request->get('date_to', ''));

        // If custom dates provided, switch period to custom
        if ($rawFrom !== '' || $rawTo !== '') {
            $period = 'custom';
        }

        $now = new DateTimeImmutable();

        return match ($period) {
            'today' => [
                'period'      => 'today',
                'periodLabel' => 'Today (' . $now->format('M j, Y') . ')',
                'startDate'   => $now->format('Y-m-d 00:00:00'),
                'endDate'     => $now->format('Y-m-d 23:59:59'),
                'date_from'   => $now->format('Y-m-d'),
                'date_to'     => $now->format('Y-m-d'),
            ],
            'last_7_days' => [
                'period'      => 'last_7_days',
                'periodLabel' => 'Last 7 Days',
                'startDate'   => $now->modify('-6 days')->format('Y-m-d 00:00:00'),
                'endDate'     => $now->format('Y-m-d 23:59:59'),
                'date_from'   => $now->modify('-6 days')->format('Y-m-d'),
                'date_to'     => $now->format('Y-m-d'),
            ],
            'last_30_days' => [
                'period'      => 'last_30_days',
                'periodLabel' => 'Last 30 Days',
                'startDate'   => $now->modify('-29 days')->format('Y-m-d 00:00:00'),
                'endDate'     => $now->format('Y-m-d 23:59:59'),
                'date_from'   => $now->modify('-29 days')->format('Y-m-d'),
                'date_to'     => $now->format('Y-m-d'),
            ],
            'previous_month' => [
                'period'      => 'previous_month',
                'periodLabel' => 'Previous Month (' . $now->modify('first day of last month')->format('F Y') . ')',
                'startDate'   => $now->modify('first day of last month')->format('Y-m-01 00:00:00'),
                'endDate'     => $now->modify('last day of last month')->format('Y-m-t 23:59:59'),
                'date_from'   => $now->modify('first day of last month')->format('Y-m-01'),
                'date_to'     => $now->modify('last day of last month')->format('Y-m-t'),
            ],
            'custom' => $this->resolveCustomRange($rawFrom, $rawTo),
            default => [ // 'this_month'
                'period'      => 'this_month',
                'periodLabel' => 'This Month (' . $now->format('F Y') . ')',
                'startDate'   => $now->format('Y-m-01 00:00:00'),
                'endDate'     => $now->format('Y-m-t 23:59:59'),
                'date_from'   => $now->format('Y-m-01'),
                'date_to'     => $now->format('Y-m-t'),
            ],
        };
    }

    private function resolveCustomRange(string $rawFrom, string $rawTo): array
    {
        $startDate = null;
        $endDate = null;
        $fromClean = '';
        $toClean = '';

        if ($rawFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawFrom)) {
            $startDate = $rawFrom . ' 00:00:00';
            $fromClean = $rawFrom;
        }

        if ($rawTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawTo)) {
            $endDate = $rawTo . ' 23:59:59';
            $toClean = $rawTo;
        }

        $label = 'Custom Range';
        if ($fromClean !== '' && $toClean !== '') {
            $label = ($fromClean === $toClean)
                ? 'Date: ' . $fromClean
                : $fromClean . ' to ' . $toClean;
        } elseif ($fromClean !== '') {
            $label = 'From ' . $fromClean;
        } elseif ($toClean !== '') {
            $label = 'Through ' . $toClean;
        }

        return [
            'period'      => 'custom',
            'periodLabel' => $label,
            'startDate'   => $startDate,
            'endDate'     => $endDate,
            'date_from'   => $fromClean,
            'date_to'     => $toClean,
        ];
    }

    /**
     * Resolve current authenticated user.
     */
    protected function resolveCurrentUser(int $userId): ?User
    {
        if (isset($GLOBALS['_test_current_user']) && $GLOBALS['_test_current_user'] instanceof User) {
            return $GLOBALS['_test_current_user'];
        }

        if (class_exists(User::class)) {
            try {
                $user = User::find($userId);
                if ($user instanceof User) {
                    return $user;
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    /**
     * Render an admin view file.
     */
    protected function renderView(string $viewName, array $data = []): string
    {
        extract($data);
        $viewFile = dirname(__DIR__, 2) . '/views/admin/' . $viewName . '.php';

        if (!file_exists($viewFile)) {
            return "<!-- View not found: {$viewName} -->";
        }

        ob_start();
        include $viewFile;
        return (string)ob_get_clean();
    }
}
