<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/ADMIN_LAYER/Auth/AdminAuth.php';
require_once __DIR__ . '/../../src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';

use ADMIN_LAYER\Auth\AdminAuth;
use DATA_PERSISTENCE_LAYER\config\DBConnection;

// Get country from URL or default to BW
$countryCode = $_GET['country'] ?? $_POST['country'] ?? 'BW';
$systemCountry = strtoupper($countryCode);

// Load configuration
$configPath = __DIR__ . "/../../src/CORE_CONFIG/countries/{$systemCountry}/config_{$systemCountry}.php";
if (!file_exists($configPath)) {
    die("Configuration not found for country: {$systemCountry}");
}
$config = require $configPath;

// Initialize database connection
try {
    $db = DBConnection::getInstance($config['db']['swap']);
    $auth = new AdminAuth($db);
} catch (Exception $e) {
    error_log("Login init failed: " . $e->getMessage());
    die("System unavailable. Please try again later.");
}

$error = '';
$mfaRequired = false;
$adminId = null;

// Handle login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mfa_code'])) {
        // MFA verification
        $result = $auth->verifyMfa($_POST['mfa_code'], $systemCountry);
        if ($result['success']) {
            header('Location: dashboard.php');
            exit;
        } else {
            $error = $result['message'];
        }
    } else {
        // Initial login
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Username and password are required';
        } else {
            $result = $auth->login($username, $password, $systemCountry);
            
            if ($result['success']) {
                if (isset($result['mfa_required'])) {
                    $mfaRequired = true;
                    $adminId = $result['admin_id'];
                } else {
                    header('Location: dashboard.php');
                    exit;
                }
            } else {
                $error = $result['message'];
            }
        }
    }
}

// Get available countries from config
$countriesDir = __DIR__ . '/../../src/CORE_CONFIG/countries/';
$availableCountries = array_filter(scandir($countriesDir), function($item) {
    return is_dir(__DIR__ . '/../../src/CORE_CONFIG/countries/' . $item) && !in_array($item, ['.', '..']);
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · ADMIN LOGIN</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'IBM Plex Mono', monospace;
            background: linear-gradient(135deg, #001B44 0%, #002B6A 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            max-width: 420px;
            width: 100%;
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header h1 {
            color: #FFDA63;
            font-size: 1.8rem;
            letter-spacing: 3px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .login-header p {
            color: #A1B5D8;
            font-size: 0.9rem;
            letter-spacing: 1px;
        }

        .login-card {
            background: #fff;
            border: 3px solid #001B44;
            border-radius: 0;
            padding: 40px 30px;
            box-shadow: 8px 8px 0 #FFDA63;
        }

        .country-selector {
            margin-bottom: 25px;
        }

        .country-selector label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #001B44;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        .country-selector select {
            width: 100%;
            padding: 12px;
            border: 2px solid #001B44;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 0.9rem;
            background: #fff;
            cursor: pointer;
        }

        .country-selector select:focus {
            outline: none;
            border-color: #FFDA63;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #001B44;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #001B44;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 0.9rem;
            transition: border-color 0.2s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #FFDA63;
        }

        .error-message {
            background: #ffebee;
            border: 2px solid #c62828;
            color: #c62828;
            padding: 12px;
            margin-bottom: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-align: center;
        }

        .login-btn {
            width: 100%;
            padding: 14px;
            background: #001B44;
            border: none;
            color: #fff;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 1rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid #001B44;
        }

        .login-btn:hover {
            background: #FFDA63;
            color: #001B44;
            border-color: #FFDA63;
        }

        .login-footer {
            margin-top: 20px;
            text-align: center;
            color: #A1B5D8;
            font-size: 0.8rem;
        }

        .login-footer a {
            color: #FFDA63;
            text-decoration: none;
        }

        .login-footer a:hover {
            text-decoration: underline;
        }

        .system-badge {
            display: inline-block;
            padding: 4px 12px;
            background: rgba(255, 218, 99, 0.1);
            border: 1px solid #FFDA63;
            color: #FFDA63;
            font-size: 0.7rem;
            text-transform: uppercase;
            margin-top: 20px;
        }

        .mfa-info {
            background: #e3f2fd;
            border: 2px solid #1976d2;
            color: #1976d2;
            padding: 12px;
            margin-bottom: 20px;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>VOUCHMORPH</h1>
            <p>ADMINISTRATIVE ACCESS</p>
        </div>

        <div class="login-card">
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($mfaRequired): ?>
                <div class="mfa-info">Please enter your authentication code</div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="country" value="<?php echo htmlspecialchars($systemCountry); ?>">

                <?php if ($mfaRequired): ?>
                    <div class="form-group">
                        <label>AUTHENTICATION CODE</label>
                        <input type="text" name="mfa_code" placeholder="000000" maxlength="6" autofocus required>
                    </div>
                <?php else: ?>
                    <div class="country-selector">
                        <label>SYSTEM</label>
                        <select name="country" onchange="this.form.submit()">
                            <?php foreach ($availableCountries as $country): ?>
                                <option value="<?php echo $country; ?>" <?php echo $country === $systemCountry ? 'selected' : ''; ?>>
                                    <?php echo strtoupper($country); ?> · VOUCHMORPH
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>USERNAME</label>
                        <input type="text" name="username" placeholder="Enter your username" autofocus required>
                    </div>

                    <div class="form-group">
                        <label>PASSWORD</label>
                        <input type="password" name="password" placeholder="••••••••" required>
                    </div>
                <?php endif; ?>

                <button type="submit" class="login-btn">
                    <?php echo $mfaRequired ? 'VERIFY CODE' : 'SIGN IN'; ?>
                </button>
            </form>

            <div class="login-footer">
                <div class="system-badge"><?php echo $systemCountry; ?> · PRODUCTION</div>
            </div>
        </div>
    </div>
</body>
</html>
