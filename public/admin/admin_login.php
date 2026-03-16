<?php
declare(strict_types=1);

ob_start();

// Define project root explicitly
define('PROJECT_ROOT', dirname(__DIR__, 2)); // Goes up 2 levels: /public/admin/ -> /var/www/html/

// Debug
error_log("[LOGIN] Starting login process");
error_log("[LOGIN] PROJECT_ROOT: " . PROJECT_ROOT);

// Load bootstrap FIRST - this initializes autoloader and database
require_once PROJECT_ROOT . '/src/bootstrap.php';

// Now use autoloader - NO require_once for these classes
use ADMIN_LAYER\Auth\AdminAuth;
use DATA_PERSISTENCE_LAYER\config\DBConnection;

// Get country from URL or default to BW
$countryCode = $_GET['country'] ?? $_POST['country'] ?? 'BW';
$systemCountry = strtoupper($countryCode);
error_log("[LOGIN] Country: " . $systemCountry);

// Initialize database connection - USE BOOTSTRAP'S CONNECTION
try {
    // First try to use bootstrap's global connection
    if (isset($GLOBALS['databases']['primary']) && $GLOBALS['databases']['primary'] instanceof PDO) {
        $db = $GLOBALS['databases']['primary'];
        error_log("[LOGIN] Using bootstrap's global PDO connection");
    } else {
        error_log("[LOGIN] WARNING: Global PDO not available, creating new connection");
        
        // Load country config
        $configPath = PROJECT_ROOT . "/src/CORE_CONFIG/countries/{$systemCountry}/config_{$systemCountry}.php";
        if (!file_exists($configPath)) {
            throw new Exception("Configuration not found for country: {$systemCountry}");
        }
        $config = require $configPath;
        
        // Get database connection
        $db = DBConnection::getInstance($config['db']['swap'] ?? $config['database'] ?? []);
    }
    
    // Initialize AdminAuth with the connection
    $auth = new AdminAuth($db);
    error_log("[LOGIN] AdminAuth initialized successfully");
    
} catch (Exception $e) {
    error_log("[LOGIN CRITICAL] Failed to initialize: " . $e->getMessage());
    error_log("[LOGIN CRITICAL] Trace: " . $e->getTraceAsString());
    
    // Show user-friendly error
    $error = "Authentication service unavailable. Please try again later.";
    
    // In development, show more details
    if (getenv('APP_ENV') === 'development') {
        $error .= " (" . $e->getMessage() . ")";
    }
    
    // Don't proceed with login form - show error page
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>System Error</title>
        <style>
            body { font-family: 'IBM Plex Mono', monospace; background: #001B44; color: #fff; display: flex; align-items: center; justify-content: center; height: 100vh; }
            .error-box { background: #fff; color: #001B44; padding: 40px; border: 3px solid #FFDA63; max-width: 500px; }
            h1 { color: #c62828; margin-bottom: 20px; }
            .details { background: #f5f5f5; padding: 15px; margin-top: 20px; font-size: 0.8rem; }
        </style>
    </head>
    <body>
        <div class="error-box">
            <h1>🔐 SYSTEM UNAVAILABLE</h1>
            <p><?php echo htmlspecialchars($error); ?></p>
            <div class="details">
                <strong>Debug Info:</strong><br>
                Country: <?php echo $systemCountry; ?><br>
                Time: <?php echo date('Y-m-d H:i:s'); ?>
            </div>
            <p style="margin-top: 20px;"><a href="?country=<?php echo $systemCountry; ?>" style="color: #001B44;">Retry</a></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$error = '';
$mfaRequired = false;
$adminId = null;

// Handle login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['mfa_code'])) {
            // MFA verification
            error_log("[LOGIN] Verifying MFA for admin");
            $result = $auth->verifyMfa($_POST['mfa_code'], $systemCountry);
            if ($result['success']) {
                error_log("[LOGIN] MFA verification successful, redirecting to dashboard");
                header('Location: admin_dashboard.php');
                exit;
            } else {
                $error = $result['message'];
                error_log("[LOGIN] MFA verification failed: " . $error);
            }
        } else {
            // Initial login
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            if (empty($username) || empty($password)) {
                $error = 'Username and password are required';
                error_log("[LOGIN] Empty credentials");
            } else {
                error_log("[LOGIN] Attempting login for user: " . $username);
                $result = $auth->login($username, $password, $systemCountry);
                
                if ($result['success']) {
                    error_log("[LOGIN] Login successful for: " . $username);
                    if (isset($result['mfa_required'])) {
                        error_log("[LOGIN] MFA required");
                        $mfaRequired = true;
                        $adminId = $result['admin_id'];
                    } else {
                        error_log("[LOGIN] Redirecting to dashboard");
                        header('Location: admin_dashboard.php');
                        exit;
                    }
                } else {
                    $error = $result['message'];
                    error_log("[LOGIN] Login failed: " . $error);
                }
            }
        }
    } catch (Exception $e) {
        error_log("[LOGIN EXCEPTION] " . $e->getMessage());
        $error = "Authentication error occurred. Please try again.";
    }
}

// Get available countries from config
$countriesDir = PROJECT_ROOT . '/src/CORE_CONFIG/countries/';
$availableCountries = [];
if (is_dir($countriesDir)) {
    $availableCountries = array_filter(scandir($countriesDir), function($item) use ($countriesDir) {
        return is_dir($countriesDir . $item) && !in_array($item, ['.', '..']);
    });
}
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
