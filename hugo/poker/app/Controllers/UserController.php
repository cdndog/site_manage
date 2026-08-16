<?php

namespace App\Controllers;

use App\Config;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Support\Security;

class UserController
{
    public static function dispatchList()
    {
        try {
            Security::requireApiToken(true);
            Security::requirePermission('user.manage');
            if (isset($_GET['format']) && $_GET['format'] === 'json') {
                self::listJson();
                return;
            }
            self::shell('用户管理', 'user_list', self::listData());
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    private static function listJson()
    {
        list($page, $perPage) = self::pageParams();
        $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
        $sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'id';
        $order = isset($_GET['order']) ? (string)$_GET['order'] : 'desc';
        $result = UserRepository::all($search, $page, $perPage, $sort, $order);
        $currentUser = Security::currentUser();
        $currentId = $currentUser !== null ? (int)$currentUser['id'] : 0;
        $rows = array_map(function ($row) use ($currentId) {
            return [
                'id' => $row['id'],
                'username' => $row['username'],
                'display_name' => $row['display_name'],
                'status' => $row['status'],
                'roles' => implode(', ', $row['roles']),
                'created_at' => $row['created_at'],
                'last_login_at' => $row['last_login_at'] !== null ? $row['last_login_at'] : '',
                'protected' => (int)$row['id'] === $currentId || in_array('admin', $row['roles'], true),
            ];
        }, $result['rows']);
        self::emitJson(['total' => $result['total'], 'rows' => $rows]);
    }

    public static function dispatchEdit()
    {
        try {
            Security::requireApiToken(true);
            Security::requirePermission('user.manage');
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            if ($method === 'POST') {
                if (isset($_POST['action']) && $_POST['action'] === 'delete') {
                    self::handleDelete();
                    return;
                }
                self::handleEditPost();
                return;
            }
            self::shell('用户编辑', 'user_form', self::editData());
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    private static function handleDelete()
    {
        if (!Security::csrfVerify(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            http_response_code(403);
            render('error', ['message' => 'CSRF token invalid, please reload the page and try again.']);
            return;
        }
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $target = $id > 0 ? UserRepository::findById($id) : null;
        if ($target === null) {
            self::shell('用户管理', 'user_list', self::listDataWithMessage('user not found', true));
            return;
        }
        $current = Security::currentUser();
        $error = null;
        if ($current !== null && (int)$current['id'] === $id) {
            $error = 'cannot delete the current logged-in user';
        } else {
            $targetRoles = UserRepository::roleNames($id);
            if (in_array('admin', $targetRoles, true)) {
                $adminCount = UserRepository::countAdmins();
                if ($adminCount <= 1) {
                    $error = 'cannot delete the last admin user';
                }
            }
        }
        if ($error === null) {
            UserRepository::delete($id);
            self::shell('用户管理', 'user_list', self::listDataWithMessage('deleted', false));
            return;
        }
        self::shell('用户管理', 'user_list', self::listDataWithMessage($error, true));
    }

    private static function listDataWithMessage($message, $isError)
    {
        $data = self::listData();
        $data['message'] = $message;
        $data['error'] = $isError;
        return $data;
    }

    public static function dispatchRoles()
    {
        try {
            Security::requireApiToken(true);
            Security::requirePermission('user.manage');
            if (isset($_GET['format']) && $_GET['format'] === 'json') {
                self::rolesJson();
                return;
            }
            self::shell('角色权限', 'role_list', self::rolesData());
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    private static function rolesJson()
    {
        $rows = [];
        $builtIn = array_flip(['admin', 'editor', 'viewer']);
        foreach (RoleRepository::all() as $role) {
            $userCount = RoleRepository::userCount((int)$role['id']);
            $rows[] = [
                'id' => $role['id'],
                'name' => $role['name'],
                'description' => $role['description'],
                'users' => $userCount,
                'permission_count' => count(RoleRepository::permissionIdsOf((int)$role['id'])),
                'protected' => isset($builtIn[$role['name']]) || $userCount > 0,
            ];
        }
        self::emitJson(['total' => count($rows), 'rows' => $rows]);
    }

    public static function dispatchRoleEdit()
    {
        try {
            Security::requireApiToken(true);
            Security::requirePermission('user.manage');
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
            if ($method === 'POST') {
                if (isset($_POST['action']) && $_POST['action'] === 'delete') {
                    self::handleRoleDelete();
                    return;
                }
                self::handleRolePost();
                return;
            }
            self::shell('角色编辑', 'role_form', self::roleEditData());
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }

    private static function handleRoleDelete()
    {
        if (!Security::csrfVerify(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            http_response_code(403);
            render('error', ['message' => 'CSRF token invalid, please reload the page and try again.']);
            return;
        }
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $target = $id > 0 ? RoleRepository::findById($id) : null;
        if ($target === null) {
            self::shell('角色权限', 'role_list', self::rolesDataWithMessage('role not found', true));
            return;
        }
        $builtIn = array_flip(['admin', 'editor', 'viewer']);
        $error = null;
        if (isset($builtIn[$target['name']])) {
            $error = 'cannot delete built-in role: ' . $target['name'];
        } elseif (RoleRepository::userCount($id) > 0) {
            $error = 'cannot delete role assigned to users';
        }
        if ($error === null) {
            RoleRepository::delete($id);
            self::shell('角色权限', 'role_list', self::rolesDataWithMessage('deleted', false));
            return;
        }
        self::shell('角色权限', 'role_list', self::rolesDataWithMessage($error, true));
    }

    private static function rolesDataWithMessage($message, $isError)
    {
        $data = self::rolesData();
        $data['message'] = $message;
        $data['error'] = $isError;
        return $data;
    }

    public static function listData()
    {
        list($page, $perPage) = self::pageParams();
        $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
        $sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'id';
        $order = isset($_GET['order']) ? (string)$_GET['order'] : 'desc';
        $result = UserRepository::all($search, 1, 1000, $sort, $order);
        $currentUser = Security::currentUser();
        $currentId = $currentUser !== null ? (int)$currentUser['id'] : 0;
        $rows = array_map(function ($row) use ($currentId) {
            return [
                'id' => $row['id'],
                'username' => $row['username'],
                'display_name' => $row['display_name'],
                'status' => $row['status'],
                'roles' => implode(', ', $row['roles']),
                'created_at' => $row['created_at'],
                'last_login_at' => $row['last_login_at'] !== null ? $row['last_login_at'] : '',
                'protected' => (int)$row['id'] === $currentId || in_array('admin', $row['roles'], true),
            ];
        }, $result['rows']);
        return [
            'rows' => $rows,
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
            'search' => $search,
            'csrf_token' => Security::csrfToken(),
        ];
    }

    private static function editData()
    {
        $eid = isset($_GET['eid']) ? (int)$_GET['eid'] : 0;
        $record = $eid > 0 ? UserRepository::findById($eid) : null;
        $form = [
            'id' => $record !== null ? (int)$record['id'] : 0,
            'username' => $record !== null ? $record['username'] : '',
            'display_name' => $record !== null ? $record['display_name'] : '',
            'status' => $record !== null ? $record['status'] : 'active',
            'password' => '',
            'role_ids' => $record !== null ? UserRepository::roleIds((int)$record['id']) : [],
        ];
        return [
            'form' => $form,
            'roles' => RoleRepository::all(),
            'csrf_token' => Security::csrfToken(),
        ];
    }

    private static function handleEditPost()
    {
        if (!Security::csrfVerify(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            http_response_code(403);
            render('error', ['message' => 'CSRF token invalid, please reload the page and try again.']);
            return;
        }
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $username = trim(isset($_POST['username']) ? (string)$_POST['username'] : '');
        $displayName = trim(isset($_POST['display_name']) ? (string)$_POST['display_name'] : '');
        $status = isset($_POST['status']) && $_POST['status'] === 'disabled' ? 'disabled' : 'active';
        $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
        $roleIds = isset($_POST['roles']) && is_array($_POST['roles']) ? array_map('intval', $_POST['roles']) : [];

        $error = null;
        if ($username === '' || !preg_match('/^[A-Za-z0-9_.-]{2,32}$/', $username)) {
            $error = 'username must be 2-32 chars of letters/digits/._-';
        } elseif ($id > 0) {
            $existing = UserRepository::findByUsername($username);
            if ($existing !== null && (int)$existing['id'] !== $id) {
                $error = 'username already exists';
            }
        } else {
            if (UserRepository::findByUsername($username) !== null) {
                $error = 'username already exists';
            }
            if (strlen($password) < 6) {
                $error = 'password must be at least 6 chars';
            }
        }

        if ($error === null) {
            if ($id > 0) {
                UserRepository::update($id, [
                    'username' => $username,
                    'display_name' => $displayName,
                    'status' => $status,
                ]);
                if ($password !== '') {
                    UserRepository::update($id, ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
                }
            } else {
                $id = UserRepository::create([
                    'username' => $username,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'display_name' => $displayName,
                    'status' => $status,
                ]);
            }
            UserRepository::setRoles($id, $roleIds);
        }

        $form = [
            'id' => $id,
            'username' => $username,
            'display_name' => $displayName,
            'status' => $status,
            'password' => '',
            'role_ids' => $roleIds,
        ];
        self::shell('用户编辑', 'user_form', [
            'form' => $form,
            'roles' => RoleRepository::all(),
            'csrf_token' => Security::csrfToken(),
            'message' => $error === null ? 'saved' : $error,
            'error' => $error !== null,
        ]);
    }

    private static function rolesData()
    {
        $roles = RoleRepository::all();
        $permissions = Config::permissions();
        $builtIn = array_flip(['admin', 'editor', 'viewer']);
        $rows = [];
        foreach ($roles as $role) {
            $userCount = RoleRepository::userCount((int)$role['id']);
            $rows[] = [
                'id' => $role['id'],
                'name' => $role['name'],
                'description' => $role['description'],
                'users' => $userCount,
                'permission_count' => count(RoleRepository::permissionIdsOf((int)$role['id'])),
                'protected' => isset($builtIn[$role['name']]) || $userCount > 0,
            ];
        }
        return [
            'rows' => $rows,
            'permissions' => $permissions,
            'csrf_token' => Security::csrfToken(),
        ];
    }

    private static function roleEditData()
    {
        $eid = isset($_GET['eid']) ? (int)$_GET['eid'] : 0;
        $record = $eid > 0 ? RoleRepository::findById($eid) : null;
        return [
            'form' => [
                'id' => $record !== null ? (int)$record['id'] : 0,
                'name' => $record !== null ? $record['name'] : '',
                'description' => $record !== null ? $record['description'] : '',
                'permission_ids' => $record !== null ? RoleRepository::permissionIdsOf((int)$record['id']) : [],
            ],
            'permissions' => Config::permissions(),
            'permission_ids_by_code' => self::permissionIdsByCode(),
            'csrf_token' => Security::csrfToken(),
        ];
    }

    private static function handleRolePost()
    {
        if (!Security::csrfVerify(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            http_response_code(403);
            render('error', ['message' => 'CSRF token invalid, please reload the page and try again.']);
            return;
        }
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $name = trim(isset($_POST['name']) ? (string)$_POST['name'] : '');
        $description = trim(isset($_POST['description']) ? (string)$_POST['description'] : '');
        $permissionIds = isset($_POST['permissions']) && is_array($_POST['permissions']) ? array_map('intval', $_POST['permissions']) : [];

        $error = null;
        if ($name === '' || !preg_match('/^[A-Za-z0-9_-]{2,32}$/', $name)) {
            $error = 'role name must be 2-32 chars of letters/digits/_-';
        } elseif ($id > 0) {
            $existing = RoleRepository::findByName($name);
            if ($existing !== null && (int)$existing['id'] !== $id) {
                $error = 'role name already exists';
            }
        } else {
            if (RoleRepository::findByName($name) !== null) {
                $error = 'role name already exists';
            }
        }

        if ($error === null) {
            if ($id > 0) {
                RoleRepository::update($id, ['name' => $name, 'description' => $description]);
            } else {
                $id = RoleRepository::create(['name' => $name, 'description' => $description]);
            }
            RoleRepository::setPermissions($id, $permissionIds);
        }

        self::shell('角色编辑', 'role_form', [
            'form' => [
                'id' => $id,
                'name' => $name,
                'description' => $description,
                'permission_ids' => $permissionIds,
            ],
            'permissions' => Config::permissions(),
            'permission_ids_by_code' => self::permissionIdsByCode(),
            'csrf_token' => Security::csrfToken(),
            'message' => $error === null ? 'saved' : $error,
            'error' => $error !== null,
        ]);
    }

    private static function permissionIdsByCode()
    {
        $rows = \App\Database::fetchAll('SELECT "id", "code" FROM "permissions"');
        $map = [];
        foreach ($rows as $row) {
            $map[$row['code']] = (int)$row['id'];
        }
        return $map;
    }

    private static function pageParams()
    {
        if (isset($_GET['offset']) || isset($_GET['limit'])) {
            $perPage = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $page = floor($offset / max(1, $perPage)) + 1;
        } else {
            $page = isset($_GET['pageNumber']) ? (int)$_GET['pageNumber'] : 1;
            $perPage = isset($_GET['pageSize']) ? (int)$_GET['pageSize'] : 20;
        }
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        return [$page, $perPage];
    }

    private static function emitJson(array $result)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['total' => $result['total'], 'rows' => array_values($result['rows'])], JSON_UNESCAPED_UNICODE);
    }

    private static function shell($pageTitle, $view, array $data = [])
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        try {
            Security::ensureUidCookie();
            if (!Security::authValid()) {
                AuthController::handle();
                return;
            }
            render('layout_head', ['page_title' => $pageTitle]);
            render('header');
            render($view, $data);
            render('footer');
            render('layout_tail');
        } catch (\Throwable $e) {
            renderErrorPage($e);
        }
    }
}