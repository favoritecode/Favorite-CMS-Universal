<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
use FavoriteCMS\Models\Role;
use FavoriteCMS\Services\AvatarService;
use FavoriteCMS\Services\EmailVerificationService;

class UserController
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /** Users shown per list page. */
    protected const PER_PAGE = 12;

    public function index(Request $request): Response
    {
        $db = $this->app->make(Database::class);

        // Bounded page of users (same ascending ID order as before); the total is counted separately
        $totalItems  = (int)($db->selectOne("SELECT COUNT(*) AS cnt FROM `users`")->cnt ?? 0);
        $totalPages  = max(1, (int)ceil($totalItems / self::PER_PAGE));
        $currentPage = min(max(1, (int)$request->get('p', 1)), $totalPages);

        $rows = $db->select("SELECT * FROM `users` ORDER BY `id` ASC LIMIT ? OFFSET ?", [self::PER_PAGE, ($currentPage - 1) * self::PER_PAGE]);
        $users = array_map(fn($row) => new User((array)$row), $rows);
        $roles = Role::all();

        $viewData = [
            'pageTitle'   => 'Users',
            'activeMenu'  => 'users',
            'users'       => $users,
            'roles'       => $roles,
            'currentPage' => $currentPage,
            'totalPages'  => $totalPages,
            'totalItems'  => $totalItems,
            'contentView' => APP_ROOT . '/resources/views/admin/users/index.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function create(Request $request): Response
    {
        $roles = Role::all();

        $viewData = [
            'pageTitle'   => 'Add New User',
            'activeMenu'  => 'users-new',
            'user'        => null,
            'roles'       => $roles,
            'contentView' => APP_ROOT . '/resources/views/admin/users/edit.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function store(Request $request): Response
    {
        $username = trim((string)$request->post('username', ''));
        $name     = trim((string)$request->post('name', ''));
        $email    = trim((string)$request->post('email', ''));
        $password = (string)$request->post('password', '');
        $roleId   = (int)$request->post('role_id', 0);

        if ($username === '' || $email === '' || $password === '') {
            $_SESSION['flash_error'] = 'Username, email, and password are required.';
            return Response::redirect('/admin/users/new');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Invalid email address.';
            return Response::redirect('/admin/users/new');
        }

        $db = $this->app->make(Database::class);
        $existing = $db->selectOne("SELECT id FROM `users` WHERE `username` = ? OR `email` = ? LIMIT 1", [$username, $email]);
        if ($existing) {
            $_SESSION['flash_error'] = 'A user with this username or email already exists.';
            return Response::redirect('/admin/users/new');
        }

        $now = date('Y-m-d H:i:s');
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $userId = $db->insert('users', [
            'username'          => $username,
            'name'              => $name !== '' ? $name : $username,
            'email'             => $email,
            'password'          => $hash,
            'status'            => 'active',
            'email_verified_at' => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        if ($roleId > 0) {
            $db->execute("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$userId, $roleId]);
        }

        $_SESSION['flash_success'] = 'User created successfully.';
        return Response::redirect('/admin/users');
    }

    public function edit(Request $request): Response
    {
        $id = (int)$request->get('id', 0);
        $user = User::find($id);
        if (!$user) {
            $_SESSION['flash_error'] = 'User not found.';
            return Response::redirect('/admin/users');
        }

        $roles = Role::all();
        $userRoles = array_map(fn($r) => (int)$r->id, $user->getRoles());

        $viewData = [
            'pageTitle'   => 'Edit User',
            'activeMenu'  => 'users',
            'user'        => $user,
            'roles'       => $roles,
            'userRoles'   => $userRoles,
            'contentView' => APP_ROOT . '/resources/views/admin/users/edit.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function update(Request $request): Response
    {
        $id = (int)$request->post('id', 0);
        $user = User::find($id);
        if (!$user) {
            $_SESSION['flash_error'] = 'User not found.';
            return Response::redirect('/admin/users');
        }

        $currentId = (int)($_SESSION['auth_user_id'] ?? 0);
        $name     = trim((string)$request->post('name', ''));
        $email    = trim((string)$request->post('email', ''));
        $password = (string)$request->post('password', '');
        $roleId   = (int)$request->post('role_id', 0);
        $status   = strtolower(trim((string)$request->post('status', '')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'A valid email address is required.';
            return Response::redirect('/admin/users/edit?id=' . $id);
        }

        $db = $this->app->make(Database::class);

        try {
            $db->transaction(function() use ($db, $user, $id, $currentId, $name, $email, $password, $roleId, $status) {
                try {
                    $db->select("SELECT `id` FROM `roles` WHERE `slug` IN ('super-admin', 'super_admin') OR `name` = 'Super Admin' FOR UPDATE");
                } catch (\Throwable) {
                }

                $isTargetSuperAdmin = $user->hasRole('super-admin');
                $targetIsActive = $user->isActive();
                $newRole = $roleId > 0 ? Role::find($roleId) : null;
                $isChangingRole = ($newRole !== null && !$user->hasRole($newRole->slug));

                // 1. Self-demotion check: Super Admin cannot demote themselves directly
                if ($id === $currentId && $isTargetSuperAdmin && $isChangingRole && $newRole->slug !== 'super-admin') {
                    throw new \DomainException('You cannot demote yourself. Another active Super Admin must be created or promoted before you can relinquish the Super Admin role.');
                }

                // 2. Last Super Admin demotion guard
                if ($isTargetSuperAdmin && $targetIsActive && $isChangingRole && $newRole->slug !== 'super-admin') {
                    if (User::getActiveSuperAdminCount($db, true) <= 1) {
                        throw new \DomainException('Cannot demote the last remaining active Super Admin.');
                    }
                }

                // 3. Last Super Admin deactivation / suspension / banning guard
                if ($isTargetSuperAdmin && $targetIsActive && in_array($status, ['suspended', 'banned'], true)) {
                    if ($id === $currentId) {
                        throw new \DomainException('You cannot modify your own account status.');
                    }
                    if (User::getActiveSuperAdminCount($db, true) <= 1) {
                        throw new \DomainException('Cannot suspend or ban the last remaining active Super Admin.');
                    }
                }

                $data = [
                    'name'       => $name !== '' ? $name : $user->username,
                    'email'      => $email,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];

                if ($password !== '') {
                    $data['password'] = password_hash($password, PASSWORD_DEFAULT);
                }

                $user->update($data);

                if ($roleId > 0) {
                    $db->execute("DELETE FROM `user_roles` WHERE `user_id` = ?", [$id]);
                    $db->execute("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$id, $roleId]);

                    if ($id === $currentId) {
                        $_SESSION['auth_user_role'] = $newRole ? $newRole->slug : 'subscriber';
                    }
                }

                if (in_array($status, ['active', 'suspended', 'banned'], true) && $id !== $currentId) {
                    $user->update(['status' => $status]);
                }
            });
        } catch (\DomainException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return Response::redirect('/admin/users/edit?id=' . $id);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'An error occurred while updating the user: ' . $e->getMessage();
            return Response::redirect('/admin/users/edit?id=' . $id);
        }

        $_SESSION['flash_success'] = 'User updated successfully.';
        return Response::redirect('/admin/users/edit?id=' . $id);
    }

    public function changeStatus(Request $request): Response
    {
        $currentId = (int)($_SESSION['auth_user_id'] ?? 0);
        $currentUser = User::find($currentId);
        if (!$currentUser || !$currentUser->canManageUsers()) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to change user status.</p>', 403);
        }

        $id = (int)$request->get('id', $request->post('id', 0));
        $status = strtolower(trim((string)$request->get('status', $request->post('status', ''))));

        if ($id === $currentId) {
            $_SESSION['flash_error'] = 'You cannot modify your own account status.';
            return Response::redirect('/admin/users');
        }

        if (!in_array($status, ['active', 'suspended', 'banned'], true)) {
            $_SESSION['flash_error'] = 'Invalid account status specified.';
            return Response::redirect('/admin/users');
        }

        $targetUser = User::find($id);
        if (!$targetUser) {
            $_SESSION['flash_error'] = 'User not found.';
            return Response::redirect('/admin/users');
        }

        $db = $this->app->make(Database::class);

        try {
            $db->transaction(function() use ($db, $targetUser, $status) {
                try {
                    $db->select("SELECT `id` FROM `roles` WHERE `slug` IN ('super-admin', 'super_admin') OR `name` = 'Super Admin' FOR UPDATE");
                } catch (\Throwable) {
                }

                if ($targetUser->hasRole('super-admin') && $targetUser->isActive() && in_array($status, ['suspended', 'banned'], true)) {
                    if (User::getActiveSuperAdminCount($db, true) <= 1) {
                        throw new \DomainException('Cannot suspend or ban the last remaining active Super Admin.');
                    }
                }

                $targetUser->update([
                    'status'     => $status,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            });
        } catch (\DomainException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return Response::redirect('/admin/users');
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to update user status: ' . $e->getMessage();
            return Response::redirect('/admin/users');
        }

        $statusLabel = match ($status) {
            'suspended' => 'suspended',
            'banned'    => 'banned',
            default     => 'activated',
        };

        $_SESSION['flash_success'] = "User \"{$targetUser->username}\" has been {$statusLabel}.";
        return Response::redirect('/admin/users');
    }

    public function changeRole(Request $request): Response
    {
        $currentId = (int)($_SESSION['auth_user_id'] ?? 0);
        $currentUser = User::find($currentId);
        if (!$currentUser || !$currentUser->canManageUsers()) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to change user roles.</p>', 403);
        }

        $id = (int)$request->post('id', 0);
        $roleId = (int)$request->post('role_id', 0);

        if ($id === $currentId) {
            $_SESSION['flash_error'] = 'You cannot change your own role.';
            return Response::redirect('/admin/users');
        }

        $targetUser = User::find($id);
        if (!$targetUser) {
            $_SESSION['flash_error'] = 'User not found.';
            return Response::redirect('/admin/users');
        }

        $role = Role::find($roleId);
        if (!$role) {
            $_SESSION['flash_error'] = 'Selected role does not exist.';
            return Response::redirect('/admin/users');
        }

        $db = $this->app->make(Database::class);

        try {
            $db->transaction(function() use ($db, $targetUser, $id, $roleId, $role) {
                try {
                    $db->select("SELECT `id` FROM `roles` WHERE `slug` IN ('super-admin', 'super_admin') OR `name` = 'Super Admin' FOR UPDATE");
                } catch (\Throwable) {
                }

                if ($targetUser->hasRole('super-admin') && $targetUser->isActive() && $role->slug !== 'super-admin') {
                    if (User::getActiveSuperAdminCount($db, true) <= 1) {
                        throw new \DomainException('Cannot demote the last remaining active Super Admin.');
                    }
                }

                $db->execute("DELETE FROM `user_roles` WHERE `user_id` = ?", [$id]);
                $db->execute("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$id, $roleId]);
            });
        } catch (\DomainException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return Response::redirect('/admin/users');
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to change user role: ' . $e->getMessage();
            return Response::redirect('/admin/users');
        }

        $_SESSION['flash_success'] = "Role for \"{$targetUser->username}\" changed to {$role->name}.";
        return Response::redirect('/admin/users');
    }

    public function profile(Request $request): Response
    {
        $id = (int)($_SESSION['auth_user_id'] ?? 0);
        if ($id <= 0) {
            return Response::redirect('/admin/login');
        }

        $user = User::find($id);
        if (!$user || $user->isBanned()) {
            return Response::redirect('/admin/login');
        }

        $db = $this->app->make(Database::class);
        $verifService = new EmailVerificationService($db);
        $pendingEmail = $verifService->getPendingEmailChange((int)$user->id);

        $viewData = [
            'pageTitle'       => 'Profile & Account Settings',
            'activeMenu'      => 'profile',
            'user'            => $user,
            'primaryRole'     => $user->getPrimaryRoleName(),
            'avatarUrl'       => $user->getAvatarUrl(),
            'postCount'       => $user->getPostCount(),
            'isEmailVerified'        => $user->isEmailVerified(),
            'pendingEmail'           => $pendingEmail,
            'canSelfDelete'          => $user->canSelfDelete(),
            'isEligibleForRecovery'  => $user->isEligibleForSuperAdminRecovery(),
            'contentView'            => APP_ROOT . '/resources/views/admin/users/profile.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function updateProfile(Request $request): Response
    {
        $id = (int)($_SESSION['auth_user_id'] ?? 0);
        if ($id <= 0) {
            return Response::redirect('/admin/login');
        }

        $user = User::find($id);
        if (!$user || $user->isBanned()) {
            return Response::redirect('/admin/login');
        }

        // 1. Verify CSRF Token
        $token = (string)$request->post('_token', '');
        $storedToken = (string)($_SESSION['_token'] ?? '');
        if ($storedToken === '' || !hash_equals($storedToken, $token)) {
            $_SESSION['flash_error'] = 'Invalid security token. Please reload the page and try again.';
            return Response::redirect('/admin/users/profile');
        }

        $avatarService = new AvatarService();
        $avatarAction = (string)$request->post('avatar_action', '');

        // 2. Handle Avatar Removal
        if ($avatarAction === 'remove' || $request->post('remove_avatar') === '1') {
            $avatarService->removeAvatar($user);
            $_SESSION['flash_success'] = 'Profile picture removed successfully.';
            return Response::redirect('/admin/users/profile');
        }

        // 3. Handle External Avatar URL
        if ($avatarAction === 'set_url' || !empty($request->post('avatar_url_submit'))) {
            $urlInput = trim((string)$request->post('avatar_url', ''));
            $urlValidation = $avatarService->validateExternalUrl($urlInput);
            if (!$urlValidation['valid']) {
                $_SESSION['flash_error'] = $urlValidation['error'];
                return Response::redirect('/admin/users/profile');
            }

            // Remove any old local uploaded file if switching to external URL
            $avatarService->deleteLocalAvatarFile($user->avatar);
            $user->update([
                'avatar'     => $urlValidation['url'],
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $_SESSION['flash_success'] = 'External profile image URL updated successfully.';
            return Response::redirect('/admin/users/profile');
        }

        // 4. Handle Uploaded Avatar File
        if (isset($_FILES['avatar']) && is_array($_FILES['avatar']) && ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $newAvatarPath = $avatarService->storeUploadedAvatar($_FILES['avatar'], (int)$user->id, $user->avatar);
                $user->update([
                    'avatar'     => $newAvatarPath,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $_SESSION['flash_success'] = 'Profile picture uploaded and updated successfully.';
                if ($avatarAction === 'upload_avatar') {
                    return Response::redirect('/admin/users/profile');
                }
            } catch (\Throwable $e) {
                $_SESSION['flash_error'] = 'Avatar upload failed: ' . $e->getMessage();
                return Response::redirect('/admin/users/profile');
            }
        }

        // 5. Update Profile Fields (Name, Email, Bio, Password)
        $name            = trim((string)$request->post('name', $user->name ?? ''));
        $email           = trim((string)$request->post('email', $user->email ?? ''));
        $bio             = trim((string)$request->post('bio', ''));
        $password        = (string)$request->post('password', '');
        $passwordConfirm = (string)$request->post('password_confirmation', '');

        if ($name === '') {
            $_SESSION['flash_error'] = 'Display name cannot be empty.';
            return Response::redirect('/admin/users/profile');
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Please enter a valid email address.';
            return Response::redirect('/admin/users/profile');
        }

        $targetEmail = (string)$user->email;
        $emailNotice = '';

        // Check email uniqueness if modified
        if (strtolower($email) !== strtolower((string)$user->email)) {
            $existing = User::findByEmail($email);
            if ($existing && (int)$existing->id !== (int)$user->id) {
                $_SESSION['flash_error'] = 'The email address is already in use by another user.';
                return Response::redirect('/admin/users/profile');
            }

            if (EmailVerificationService::isRequired()) {
                $db = $this->app->make(Database::class);
                $verifService = new EmailVerificationService($db);
                $verifToken = $verifService->createVerificationToken($user, $email);
                $verifService->sendVerificationEmail($user, $email, $verifToken, true);
                $emailNotice = ' A confirmation link has been sent to ' . htmlspecialchars($email) . '. Your email address will be updated once confirmed.';
            } else {
                $targetEmail = $email;
                $_SESSION['auth_user_email'] = $email;
            }
        }

        $data = [
            'name'       => $name,
            'email'      => $targetEmail,
            'bio'        => $bio,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // Password update check
        if ($password !== '') {
            if (strlen($password) < 6) {
                $_SESSION['flash_error'] = 'New password must be at least 6 characters long.';
                return Response::redirect('/admin/users/profile');
            }
            if ($passwordConfirm !== '' && $password !== $passwordConfirm) {
                $_SESSION['flash_error'] = 'New password and password confirmation do not match.';
                return Response::redirect('/admin/users/profile');
            }
            $data['password'] = password_hash($password, PASSWORD_DEFAULT);
        }

        // Strictly prevent user from modifying their own role or status through profile update
        // (Role and status are excluded from $data)

        $user->update($data);
        $_SESSION['auth_user_name'] = $name;

        if ($emailNotice !== '') {
            $_SESSION['flash_success'] = 'Profile updated.' . $emailNotice;
        } elseif (empty($_SESSION['flash_success'])) {
            $_SESSION['flash_success'] = 'Profile updated successfully.';
        }

        return Response::redirect('/admin/users/profile');
    }

    public function delete(Request $request): Response
    {
        $id = (int)$request->get('id', (int)$request->post('id', 0));
        $currentId = (int)($_SESSION['auth_user_id'] ?? 0);

        if ($id === $currentId) {
            $_SESSION['flash_error'] = 'You cannot delete your own account.';
            return Response::redirect('/admin/users');
        }

        $currentUser = User::find($currentId);
        if (!$currentUser || !$currentUser->canManageUsers()) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to delete users.</p>', 403);
        }

        $user = User::find($id);
        if ($user) {
            $db = $this->app->make(Database::class);
            try {
                $db->transaction(function() use ($db, $user, $currentId) {
                    try {
                        $db->select("SELECT `id` FROM `roles` WHERE `slug` IN ('super-admin', 'super_admin') OR `name` = 'Super Admin' FOR UPDATE");
                    } catch (\Throwable) {
                    }

                    if ($user->hasRole('super-admin') && $user->isActive() && User::getActiveSuperAdminCount($db, true) <= 1) {
                        throw new \DomainException('Cannot delete the last remaining active Super Admin.');
                    }

                    $user->deleteAccount($currentId);
                });
                $_SESSION['flash_success'] = 'User deleted.';
            } catch (\DomainException $e) {
                $_SESSION['flash_error'] = $e->getMessage();
            } catch (\Throwable $e) {
                $_SESSION['flash_error'] = 'Failed to delete user: ' . $e->getMessage();
            }
        }

        return Response::redirect('/admin/users');
    }

    public function deleteOwnAccount(Request $request): Response
    {
        $id = (int)($_SESSION['auth_user_id'] ?? 0);
        if ($id <= 0) {
            return Response::redirect('/admin/login');
        }

        $user = User::find($id);
        if (!$user) {
            return Response::redirect('/admin/login');
        }

        // Suspended or banned accounts cannot self-delete
        if (!$user->isActive() || !$user->canSelfDelete()) {
            $_SESSION['flash_error'] = 'Your account is not eligible for self-deletion.';
            return Response::redirect('/admin/users/profile');
        }

        // Verify CSRF Token
        $token = (string)$request->post('_token', '');
        $storedToken = (string)($_SESSION['_token'] ?? '');
        if ($storedToken === '' || !hash_equals($storedToken, $token)) {
            $_SESSION['flash_error'] = 'Security verification failed (invalid CSRF token). Please try again.';
            return Response::redirect('/admin/users/profile');
        }

        // Verify confirmation checkbox
        if ($request->post('confirm_delete') !== '1') {
            $_SESSION['flash_error'] = 'Please check the confirmation box to confirm account deletion.';
            return Response::redirect('/admin/users/profile');
        }

        // Verify password
        $password = (string)$request->post('password', '');
        if ($password === '' || !$user->verifyPassword($password)) {
            $_SESSION['flash_error'] = 'Incorrect password. Account deletion cancelled.';
            return Response::redirect('/admin/users/profile');
        }

        // Find a fallback administrator to inherit authored content
        $db = $this->app->make(Database::class);
        $adminRow = $db->selectOne(
            "SELECT u.id FROM `users` u
             JOIN `user_roles` ur ON u.id = ur.user_id
             JOIN `roles` r ON ur.role_id = r.id
             WHERE r.slug IN ('super-admin', 'admin') AND u.id != ? AND u.status = 'active'
             ORDER BY u.id ASC LIMIT 1",
            [$user->id]
        );
        $fallbackAdminId = $adminRow ? (int)$adminRow->id : (int)$user->id;

        try {
            $db->transaction(function() use ($db, $user, $fallbackAdminId) {
                try {
                    $db->select("SELECT `id` FROM `roles` WHERE `slug` IN ('super-admin', 'super_admin') OR `name` = 'Super Admin' FOR UPDATE");
                } catch (\Throwable) {
                }

                if ($user->hasRole('super-admin') && User::getActiveSuperAdminCount($db, true) <= 1) {
                    throw new \DomainException('Cannot delete the last remaining active Super Admin account.');
                }

                $user->deleteAccount($fallbackAdminId);
            });

            // Destroy session and log out
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            session_start();
            $_SESSION['flash_success'] = 'Your account has been permanently deleted.';
            return Response::redirect('/');
        } catch (\DomainException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return Response::redirect('/admin/users/profile');
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Account deletion failed: ' . $e->getMessage();
            return Response::redirect('/admin/users/profile');
        }
    }

    public function bulkAction(Request $request): Response
    {
        $token = (string)$request->post('_token', '');
        if (empty($_SESSION['_token']) || !hash_equals($_SESSION['_token'], $token)) {
            $_SESSION['flash_error'] = 'Security verification failed (invalid CSRF token).';
            return Response::redirect('/admin/users');
        }

        $currentId = (int)($_SESSION['auth_user_id'] ?? 0);
        $currentUser = User::find($currentId);
        if (!$currentUser || !$currentUser->canManageUsers()) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage users.</p>', 403);
        }

        $action = trim((string)$request->post('bulk_action', ''));
        $rawIds = (array)$request->post('ids', []);
        $ids = array_filter(array_map('intval', $rawIds), fn($id) => $id > 0);

        if (empty($action) || empty($ids)) {
            $_SESSION['flash_error'] = 'Please select at least one user and a bulk action.';
            return Response::redirect('/admin/users');
        }

        $targetStatus = match ($action) {
            'activate' => 'active',
            'suspend'  => 'suspended',
            'ban'      => 'banned',
            default    => null,
        };

        if ($targetStatus === null) {
            $_SESSION['flash_error'] = 'Invalid user bulk action specified.';
            return Response::redirect('/admin/users');
        }

        $count = 0;
        $now = date('Y-m-d H:i:s');
        $db = $this->app->make(Database::class);

        try {
            $db->transaction(function() use ($db, $ids, $currentId, $currentUser, $action, $targetStatus, $now, &$count) {
                try {
                    $db->select("SELECT `id` FROM `roles` WHERE `slug` IN ('super-admin', 'super_admin') OR `name` = 'Super Admin' FOR UPDATE");
                } catch (\Throwable) {
                }

                $isSuperAdmin = $currentUser->hasRole('super-admin');
                $activeSuperAdminCount = User::getActiveSuperAdminCount($db, true);

                foreach ($ids as $id) {
                    // Guard: Cannot modify self
                    if ($id === $currentId) {
                        continue;
                    }

                    $targetUser = User::find($id);
                    if (!$targetUser) {
                        continue;
                    }

                    // Guard: Cannot suspend or ban a super-admin unless acting user is super-admin
                    if ($targetUser->hasRole('super-admin') && !$isSuperAdmin) {
                        continue;
                    }

                    // Guard: Bulk suspend/ban cannot reduce active super admin count to 0
                    if (in_array($targetStatus, ['suspended', 'banned'], true) && $targetUser->hasRole('super-admin') && $targetUser->isActive()) {
                        if ($activeSuperAdminCount <= 1) {
                            continue; // Skip this user so at least one active Super Admin remains
                        }
                        $activeSuperAdminCount--;
                    }

                    $targetUser->update([
                        'status'     => $targetStatus,
                        'updated_at' => $now,
                    ]);
                    $count++;
                }
            });
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Bulk action failed: ' . $e->getMessage();
            return Response::redirect('/admin/users');
        }

        if ($count > 0) {
            $label = match ($action) {
                'activate' => 'activated',
                'suspend'  => 'suspended',
                'ban'      => 'banned',
                default    => 'processed',
            };
            $_SESSION['flash_success'] = "{$count} user(s) successfully {$label}.";
        } else {
            $_SESSION['flash_error'] = 'No users were updated (you cannot modify your own account or protected accounts).';
        }

        return Response::redirect('/admin/users');
    }

    public function recoverSuperAdmin(Request $request): Response
    {
        $id = (int)($_SESSION['auth_user_id'] ?? 0);
        if ($id <= 0) {
            return Response::redirect('/admin/login');
        }

        $user = User::find($id);
        if (!$user) {
            return Response::redirect('/admin/login');
        }

        // Verify CSRF Token
        $token = (string)$request->post('_token', '');
        $storedToken = (string)($_SESSION['_token'] ?? '');
        if ($storedToken === '' || !hash_equals($storedToken, $token)) {
            $_SESSION['flash_error'] = 'Security verification failed (invalid CSRF token). Please try again.';
            return Response::redirect('/admin/users/profile');
        }

        // Verify eligibility first
        if (!$user->isEligibleForSuperAdminRecovery()) {
            $_SESSION['flash_error'] = 'Your account is not eligible for Super Admin emergency recovery.';
            return Response::redirect('/admin/users/profile');
        }

        // Rate limiting: max 5 attempts per 15 minutes by IP and User
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $rateKey = 'recover_sa_' . $ip . '_' . $user->id;
        $limiter = new \FavoriteCMS\Services\AuthRateLimiter();
        if (!$limiter->allow($rateKey, 5, 900)) {
            $_SESSION['flash_error'] = 'Too many recovery attempts. Please wait 15 minutes before trying again.';
            return Response::redirect('/admin/users/profile');
        }

        $password = (string)$request->post('password', '');
        if ($password === '') {
            $_SESSION['flash_error'] = 'Password is required to confirm emergency recovery.';
            return Response::redirect('/admin/users/profile');
        }

        if (!$user->recoverSuperAdmin($password)) {
            $_SESSION['flash_error'] = 'Authentication failed. Please verify your current password.';
            return Response::redirect('/admin/users/profile');
        }

        $_SESSION['flash_success'] = 'Super Admin role successfully restored! Full administrator capabilities are now active.';
        return Response::redirect('/admin/users/profile');
    }
}

