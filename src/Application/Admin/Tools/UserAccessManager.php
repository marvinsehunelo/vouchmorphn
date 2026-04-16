<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// ADMINISTRATION_LAYER/tools/UserAccessManager.php
require_once __DIR__ . '/../../APP_LAYER/utils/session_manager.php';
require_once __DIR__ . '/../../BUSINESS_LOGIC_LAYER/services/AdminService.php';

use APP_LAYER\Utils\SessionManager;
use BUSINESS_LOGIC_LAYER\Services\AdminService;

SessionManager::start();
if (!SessionManager::isLoggedIn() || SessionManager::getUser()['role'] !== 'admin') {
    header('Location: ../../APP_LAYER/views/admin_login.php');
    exit;
}

$adminService = new AdminService();
$users = $adminService->getAllAdmins();

?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>User Access Manager</title></head>
<body>
<h1>Admin Accounts</h1>
<table border="1">
<tr><th>ID</th><th>Username</th><th>Role</th><th>Actions</th></tr>
<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 foreach($users as $u): ?>
<tr>
    <td><?= $u['admin_id'] ?></td>
    <td><?= $u['username'] ?></td>
    <td><?= $u['role_id'] ?></td>
    <td>
        <a href="#">Edit</a> | <a href="#">Disable</a>
    </td>
</tr>
<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 endforeach; ?>
</table>
</body>
</html>
