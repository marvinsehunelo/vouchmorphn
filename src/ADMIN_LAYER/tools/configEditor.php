<?php
// ADMINISTRATION_LAYER/tools/ConfigEditor.php
require_once __DIR__ . '/../../APP_LAYER/utils/session_manager.php';
use APP_LAYER\Utils\SessionManager;

SessionManager::start();
if (!SessionManager::isLoggedIn() || SessionManager::getUser()['role'] !== 'admin') {
    header('Location: ../../APP_LAYER/views/admin_login.php');
    exit;
}

// Load config file
$country = require __DIR__ . '/CORE_CONFIG/system_country.php';
$configPath = __DIR__ . '/../../CORE_CONFIG/config_' . $country . '.php';
$config = require $configPath;

// Handle updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    file_put_contents($configPath, '<?php return ' . var_export($_POST, true) . ';');
    $message = "Configuration updated successfully.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Config Editor</title></head>
<body>
<h1>Edit System Configuration</h1>
<?php if (!empty($message)) echo "<p>$message</p>"; ?>
<form method="POST">
<?php foreach ($config as $key => $value): ?>
    <label><?= htmlspecialchars($key) ?>: </label>
    <input name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($value) ?>"><br>
<?php endforeach; ?>
<button type="submit">Save Config</button>
</form>
</body>
</html>
