<?php
// ROOT_DIRECTORY/access_denied.php

// 1. Basic Setup (Optional, but good practice)
// Set HTTP response code to 403 Forbidden to signal an access restriction
http_response_code(403); 

// Include necessary files if using shared headers/footers
// require_once __DIR__ . '/bootstrap.php'; 
// use APP_LAYER\Utils\SessionManager; 
// SessionManager::start(); 

$pageTitle = "Access Denied";
$errorMessage = "You do not have the necessary permissions to view this page.";
$errorDescription = "Your current user role is not authorized to access the requested resource. If you believe this is an error, please contact your system administrator.";

// Optional: Get the username if the SessionManager is accessible
// $username = SessionManager::getUserName() ?? 'Guest';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | PrestagedSWAP</title>
    <link rel="stylesheet" href="/path/to/your/main.css">
    <style>
        /* Minimal inline styling for clarity and immediate display */
        body { font-family: Arial, sans-serif; background-color: #f8f9fa; color: #333; margin: 0; padding: 0; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .container { background-color: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); max-width: 500px; text-align: center; }
        .icon { font-size: 5rem; color: #dc3545; margin-bottom: 20px; display: block; }
        h1 { color: #dc3545; margin-bottom: 10px; font-size: 2.5rem; }
        p { margin-bottom: 25px; line-height: 1.6; }
        .actions a { display: inline-block; padding: 10px 20px; margin: 5px; text-decoration: none; border-radius: 5px; font-weight: bold; transition: background-color 0.3s; }
        .actions .btn-home { background-color: #007bff; color: white; }
        .actions .btn-home:hover { background-color: #0056b3; }
        .actions .btn-logout { background-color: #6c757d; color: white; }
        .actions .btn-logout:hover { background-color: #5a6268; }
    </style>
</head>
<body>
    <div class="container">
        <span class="icon">🚫</span>
        <h1>Access Forbidden (403)</h1>
        <p><strong><?= htmlspecialchars($errorMessage) ?></strong></p>
        <p><?= htmlspecialchars($errorDescription) ?></p>

        <div class="actions">
            <a href="/ADMIN_LAYER/dashboards/admin_dashboard.php" class="btn-home">Go to Dashboard</a>
            
            <a href="/auth/logout.php" class="btn-logout">Logout</a>
        </div>

        <hr style="margin-top: 20px; border: 0; border-top: 1px solid #eee;">
        <small>&copy; <?= date('Y') ?> PrestagedSWAP System Security</small>
    </div>
</body>
</html>
