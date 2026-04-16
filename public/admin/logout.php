<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// ADMIN_LAYER/dashboards/admin_logout.php

require_once __DIR__ . '/../../src/Application/utils/session_manager.php';

// FIX: Import the namespaced class
use APP_LAYER\Utils\SessionManager;

// Start session (if not started)
SessionManager::start();

// Destroy session and log out
SessionManager::destroy();

// Redirect to login page
header('Location: admin_login.php');
exit();
?>
