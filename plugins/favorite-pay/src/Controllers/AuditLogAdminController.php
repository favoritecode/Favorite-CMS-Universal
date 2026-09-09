<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\AuditLogServiceInterface;
use FavoriteCMS\Pay\Permissions\PaymentPermission;
use Throwable;

/**
 * Class AuditLogAdminController
 *
 * Controller for Favorite Pay Admin Audit & Operational Activity Center.
 * Read-only: inspect administrative and customer operational actions.
 * Zero financial mutations: viewing audit records never alters balances or statuses.
 */
class AuditLogAdminController
{
    private Application $app;
    private AuditLogServiceInterface $auditService;
    private ?Database $db;

    public function __construct(
        Application $app,
        AuditLogServiceInterface $auditService,
        ?Database $db = null
    ) {
        $this->app = $app;
        $this->auditService = $auditService;
        $this->db = $db;

        if ($this->db === null && method_exists($this->app, 'has') && $this->app->has(Database::class)) {
            $this->db = $this->app->make(Database::class);
        }
    }

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

        // 2. Authorize audit viewing permission
        if (!PaymentPermission::canViewAudit($currentUser)) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to view Favorite Pay audit logs.</p>', 403);
        }

        // 3. Dispatch
        $action = (string)$request->get('action', 'index');
        return match ($action) {
            'detail' => $this->detail($request, $currentUser),
            default  => $this->index($request, $currentUser),
        };
    }

    public function index(Request $request, ?User $currentUser = null): string
    {
        $page = max(1, (int)$request->get('p', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $filters = [
            'action'         => (string)$request->get('action_filter', 'all'),
            'actor_type'     => (string)$request->get('actor_type', 'all'),
            'actor_user_id'  => (int)$request->get('actor_user_id', 0) ?: null,
            'target_user_id' => (int)$request->get('target_user_id', 0) ?: null,
            'subject_type'   => (string)$request->get('subject_type', 'all'),
            'withdrawal_id'  => trim((string)$request->get('withdrawal_id', '')),
            'payment_id'     => trim((string)$request->get('payment_id', '')),
            'date_from'      => trim((string)$request->get('date_from', '')),
            'date_to'        => trim((string)$request->get('date_to', '')),
            'search'         => trim((string)$request->get('search', '')),
        ];

        $data = $this->auditService->listLogs($filters, $limit, $offset);

        // Batch resolve user identities to avoid N+1 queries
        $userIds = [];
        foreach ($data['items'] as $item) {
            if (!empty($item['actor_user_id'])) {
                $userIds[] = (int)$item['actor_user_id'];
            }
            if (!empty($item['target_user_id'])) {
                $userIds[] = (int)$item['target_user_id'];
            }
        }
        $userMap = $this->batchResolveUsers(array_unique($userIds));

        return $this->renderView('audit_logs', [
            'items'       => $data['items'],
            'total'       => $data['total'],
            'page'        => $data['page'],
            'totalPages'  => $data['totalPages'],
            'limit'       => $data['limit'],
            'filters'     => $filters,
            'userMap'     => $userMap,
            'currentUser' => $currentUser,
        ]);
    }

    public function detail(Request $request, ?User $currentUser = null): Response|string
    {
        $id = (int)$request->get('id', 0);
        if ($id <= 0) {
            return Response::redirect('/admin/page/favorite-pay-audit');
        }

        $log = $this->auditService->getLog($id);
        if (!$log) {
            return Response::make('<h1>404 Not Found</h1><p>Audit record not found.</p>', 404);
        }

        $actorUser = !empty($log['actor_user_id']) ? $this->resolveUserDetails((int)$log['actor_user_id']) : null;
        $targetUser = !empty($log['target_user_id']) ? $this->resolveUserDetails((int)$log['target_user_id']) : null;

        return $this->renderView('audit_detail', [
            'log'         => $log,
            'actorUser'   => $actorUser,
            'targetUser'  => $targetUser,
            'currentUser' => $currentUser,
        ]);
    }

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

    private function resolveUserDetails(int $userId): array
    {
        if (isset($GLOBALS['_test_current_user']) && $GLOBALS['_test_current_user'] instanceof User && (int)$GLOBALS['_test_current_user']->id === $userId) {
            return [
                'id'       => $userId,
                'username' => (string)($GLOBALS['_test_current_user']->username ?? $GLOBALS['_test_current_user']->name ?? "User #{$userId}"),
                'email'    => (string)($GLOBALS['_test_current_user']->email ?? ''),
            ];
        }

        if ($this->db !== null && $this->db->tableExists('users')) {
            $row = $this->db->selectOne("SELECT id, username, email FROM users WHERE id = ? LIMIT 1", [$userId]);
            if ($row) {
                return ['id' => (int)$row->id, 'username' => (string)($row->username ?? ''), 'email' => (string)($row->email ?? '')];
            }
        }

        if (class_exists(User::class)) {
            try {
                $u = User::find($userId);
                if ($u) {
                    return ['id' => (int)$u->id, 'username' => (string)($u->username ?? ''), 'email' => (string)($u->email ?? '')];
                }
            } catch (Throwable) {
            }
        }

        return ['id' => $userId, 'username' => "User #{$userId}", 'email' => ''];
    }

    private function batchResolveUsers(array $userIds): array
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds), fn($id) => $id > 0));
        if (empty($userIds)) {
            return [];
        }

        $map = [];

        if ($this->db !== null && $this->db->tableExists('users')) {
            $inList = implode(',', $userIds);
            $rows = $this->db->select("SELECT id, username, email FROM users WHERE id IN ({$inList})");
            foreach ($rows as $r) {
                $map[(int)$r->id] = [
                    'id'       => (int)$r->id,
                    'username' => (string)($r->username ?? ''),
                    'email'    => (string)($r->email ?? ''),
                ];
            }
        }

        foreach ($userIds as $uid) {
            if (!isset($map[$uid])) {
                $map[$uid] = $this->resolveUserDetails($uid);
            }
        }

        return $map;
    }

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
