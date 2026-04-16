<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// ADMINISTRATION_LAYER/tools/NotificationBroadcaster.php
require_once __DIR__ . '/../../APP_LAYER/utils/session_manager.php';
require_once __DIR__ . '/../../BUSINESS_LOGIC_LAYER/services/NotificationService.php';

use APP_LAYER\Utils\SessionManager;
use BUSINESS_LOGIC_LAYER\Services\NotificationService;

SessionManager::start();
if (!SessionManager::isLoggedIn() || SessionManager::getUser()['role'] !== 'admin') {
    header('Location: ../../APP_LAYER/views/admin_login.php');
    exit;
}

$notificationService = new NotificationService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = $_POST['message'] ?? '';
    $notificationService->broadcast($message);
    $status = "Message broadcasted successfully!";
}

?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Notification Broadcaster</title></head>
<body>
<h1>Broadcast Notifications</h1>
<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
 if (!empty($status)) echo "<p>$status</p>"; ?>
<form method="POST">
    <textarea name="message" placeholder="Enter message" rows="5" cols="50"></textarea><br>
    <button type="submit">Send Notification</button>
</form>
</body>
</html>
