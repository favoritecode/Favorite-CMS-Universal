<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
use FavoriteCMS\Models\Role;

class UserController
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function index(Request $request): Response
    {
        $users = User::all();
        $roles = Role::all();

        $viewData = [
            'pageTitle'   => 'Users',
            'activeMenu'  => 'users',
            'users'       => $users,
            'roles'       => $roles,
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

        $name     = trim((string)$request->post('name', ''));
        $email    = trim((string)$request->post('email', ''));
        $password = (string)$request->post('password', '');
        $roleId   = (int)$request->post('role_id', 0);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'A valid email address is required.';
            return Response::redirect('/admin/users/edit?id=' . $id);
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
            $db = $this->app->make(Database::class);
            $db->execute("DELETE FROM `user_roles` WHERE `user_id` = ?", [$id]);
            $db->execute("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$id, $roleId]);
        }

        $_SESSION['flash_success'] = 'User updated successfully.';
        return Response::redirect('/admin/users/edit?id=' . $id);
    }

    public function profile(Request $request): Response
    {
        $id = (int)($_SESSION['auth_user_id'] ?? 1);
        $user = User::find($id);

        $viewData = [
            'pageTitle'   => 'Profile',
            'activeMenu'  => 'profile',
            'user'        => $user,
            'contentView' => APP_ROOT . '/resources/views/admin/users/profile.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function updateProfile(Request $request): Response
    {
        $id = (int)($_SESSION['auth_user_id'] ?? 1);
        $user = User::find($id);
        if ($user) {
            $name     = trim((string)$request->post('name', ''));
            $email    = trim((string)$request->post('email', ''));
            $bio      = trim((string)$request->post('bio', ''));
            $password = (string)$request->post('password', '');

            $data = [
                'name'       => $name,
                'email'      => $email,
                'bio'        => $bio,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($password !== '') {
                $data['password'] = password_hash($password, PASSWORD_DEFAULT);
            }

            $user->update($data);
            $_SESSION['auth_user_name'] = $name;
            $_SESSION['auth_user_email'] = $email;
            $_SESSION['flash_success'] = 'Profile updated.';
        }

        return Response::redirect('/admin/users/profile');
    }

    public function delete(Request $request): Response
    {
        $id = (int)$request->get('id', 0);
        $currentId = (int)($_SESSION['auth_user_id'] ?? 0);

        if ($id === $currentId) {
            $_SESSION['flash_error'] = 'You cannot delete your own account.';
            return Response::redirect('/admin/users');
        }

        $user = User::find($id);
        if ($user) {
            $db = $this->app->make(Database::class);
            $db->execute("DELETE FROM `user_roles` WHERE `user_id` = ?", [$id]);
            $user->delete();
            $_SESSION['flash_success'] = 'User deleted.';
        }

        return Response::redirect('/admin/users');
    }
}

