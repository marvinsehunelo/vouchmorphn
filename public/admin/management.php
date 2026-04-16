<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// ADMIN_LAYER/admin_management.php

require_once __DIR__ . '/../../APP_LAYER/utils/session_manager.php';
require_once __DIR__ . '/../../DATA_PERSISTENCE_LAYER/config/DBConnection.php';

use APP_LAYER\utils\SessionManager;
use DATA_PERSISTENCE_LAYER\Config\DBConnection;

SessionManager::start();
if (!SessionManager::isLoggedIn() || SessionManager::getUser()['role'] !== 'admin') {
    header('Location: admin_login.php');
    exit;
}

// Load config and DB
$country = require __DIR__ . '/../../CORE_CONFIG/system_country.php';
$config = require __DIR__ . '/../../CORE_CONFIG/config_' . $country . '.php';
$db = DBConnection::getInstance($config['db']['swap']);

// --- ROLE NAME MAP ---
$roleNames = [
    2 => "Admin",
    3 => "Compliance",
    4 => "Auditor",
    5 => "Superadmin"
];

// --- Handle actions ---
$action = $_POST['action'] ?? null;

try {
    if ($action === 'create') {
        $stmt = $db->prepare("
            INSERT INTO admins (username, password_hash, email, phone, role_id, mfa_enabled, created_at, updated_at)
            VALUES (:username, :password_hash, :email, :phone, :role_id, :mfa_enabled, NOW(), NOW())
        ");
        $stmt->execute([
            ':username' => $_POST['username'],
            $passwordHash = password_hash($tempPassword, VM_HASH_ALGO, VM_HASH_OPTIONS);
            ':email' => $_POST['email'],
            ':phone' => $_POST['phone'],
            ':role_id' => $_POST['role_id'],
            ':mfa_enabled' => $_POST['mfa_enabled'] ?? 0
        ]);
        $admin_id = $db->lastInsertId();

        $stmtLog = $db->prepare("
            INSERT INTO admin_actions 
            (entity_type, entity_id, action_type, status, assigned_admin_id, request_data, created_at)
            VALUES ('admin', :entity_id, 'create', 'success', :assigned_admin_id, :request_data, NOW())
        ");
        $stmtLog->execute([
            ':entity_id' => $admin_id,
            ':assigned_admin_id' => SessionManager::getUser()['admin_id'],
            ':request_data' => json_encode($_POST)
        ]);

        echo json_encode(['success' => true, 'message' => 'Admin created successfully']);
        exit;
    }

    if ($action === 'update') {
        $stmt = $db->prepare("
            UPDATE admins 
            SET username=:username, email=:email, phone=:phone, role_id=:role_id, mfa_enabled=:mfa_enabled, updated_at=NOW()
            WHERE admin_id=:admin_id
        ");
        $stmt->execute([
            ':username' => $_POST['username'],
            ':email' => $_POST['email'],
            ':phone' => $_POST['phone'],
            ':role_id' => $_POST['role_id'],
            ':mfa_enabled' => $_POST['mfa_enabled'] ?? 0,
            ':admin_id' => $_POST['admin_id']
        ]);

        echo json_encode(['success' => true, 'message' => 'Admin updated successfully']);
        exit;
    }

    if ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM admins WHERE admin_id=:admin_id");
        $stmt->execute([':admin_id' => $_POST['admin_id']]);

        echo json_encode(['success' => true, 'message' => 'Admin deleted successfully']);
        exit;
    }

    // Fetch all admins
    $stmt = $db->query("SELECT admin_id, username, email, phone, role_id, mfa_enabled, created_at, updated_at 
                        FROM admins 
                        ORDER BY created_at DESC");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

// Output JSON if requested
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['json'])) {
    echo json_encode($admins);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Management</title>
    <style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>

<h1>Admin Management</h1>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Role</th>
            <th>MFA Enabled</th>
            <th>Created At</th>
            <th>Updated At</th>
        </tr>
    </thead>
    <tbody>
        <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 foreach ($admins as $admin): ?>
        <tr>
            <td><?= htmlspecialchars($admin['admin_id']) ?></td>
            <td><?= htmlspecialchars($admin['username']) ?></td>
            <td><?= htmlspecialchars($admin['email']) ?></td>
            <td><?= htmlspecialchars($admin['phone']) ?></td>

            <!-- Show ROLE NAME instead of role_id -->
            <td><?= htmlspecialchars($roleNames[$admin['role_id']] ?? 'Unknown') ?></td>

            <td><?= $admin['mfa_enabled'] ? 'Yes' : 'No' ?></td>
            <td><?= htmlspecialchars($admin['created_at']) ?></td>
            <td><?= htmlspecialchars($admin['updated_at']) ?></td>
        </tr>
        <?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 endforeach; ?>
    </tbody>
</table>

</body>
</html>

