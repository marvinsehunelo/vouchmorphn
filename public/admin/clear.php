<?php
/**
 * Password Hash Generator
 * Run: php hash_passwords.php
 * or access via web browser
 */

// If running in CLI mode
if (php_sapi_name() === 'cli') {
    echo "=== PASSWORD HASH GENERATOR ===\n\n";
    
    while (true) {
        echo "Enter password to hash (or 'quit' to exit): ";
        $password = trim(fgets(STDIN));
        
        if (strtolower($password) === 'quit') {
            break;
        }
        
        if (empty($password)) {
            echo "Password cannot be empty!\n\n";
            continue;
        }
        
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        
        echo "\nPassword: " . str_repeat('*', strlen($password)) . "\n";
        echo "Hash: " . $hash . "\n";
        echo "Length: " . strlen($hash) . " characters\n";
        echo "Algorithm: BCRYPT (cost=12)\n";
        echo "Verification test: " . (password_verify($password, $hash) ? '✓ PASSED' : '✗ FAILED') . "\n";
        echo "\n---\n\n";
    }
    
    echo "Goodbye!\n";
    exit;
}

// If running in web browser
?>
<!DOCTYPE html>
<html>
<head>
    <title>Password Hash Generator</title>
    <style>
        body {
            font-family: 'IBM Plex Mono', monospace;
            background: #001B44;
            color: #fff;
            padding: 30px;
            max-width: 800px;
            margin: 0 auto;
        }
        .container {
            background: #f7f9fc;
            color: #001B44;
            padding: 30px;
            border-radius: 5px;
            border: 3px solid #FFDA63;
        }
        h1 {
            margin-top: 0;
            border-bottom: 3px solid #FFDA63;
            padding-bottom: 10px;
        }
        label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
        }
        input[type="password"] {
            width: 100%;
            padding: 12px;
            font-family: 'IBM Plex Mono', monospace;
            border: 2px solid #001B44;
            margin-bottom: 20px;
            font-size: 16px;
        }
        input[type="submit"] {
            background: #001B44;
            color: #FFDA63;
            border: none;
            padding: 12px 30px;
            font-family: 'IBM Plex Mono', monospace;
            font-weight: 600;
            cursor: pointer;
            border: 2px solid #FFDA63;
            transition: all 0.2s;
        }
        input[type="submit"]:hover {
            background: #FFDA63;
            color: #001B44;
        }
        .result {
            margin-top: 30px;
            padding: 20px;
            background: #001B44;
            color: #FFDA63;
            border: 2px solid #FFDA63;
            word-break: break-all;
        }
        .info {
            margin-top: 20px;
            padding: 15px;
            background: #e9ecef;
            border-left: 5px solid #FFDA63;
        }
        .error {
            color: #f00;
            font-weight: 600;
        }
        hr {
            border: 1px solid #001B44;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 PASSWORD HASH GENERATOR</h1>
        <p>Generate secure BCRYPT hashes for admin passwords</p>
        
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
            $password = $_POST['password'];
            
            if (empty($password)) {
                echo '<div class="result" style="color: #f00;">❌ Password cannot be empty!</div>';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $verify = password_verify($password, $hash) ? '✓ PASSED' : '✗ FAILED';
                
                echo '<div class="result">';
                echo '<strong>✅ HASH GENERATED</strong><br><br>';
                echo '<strong>Password:</strong> ' . str_repeat('•', strlen($password)) . '<br>';
                echo '<strong>Hash:</strong> ' . $hash . '<br>';
                echo '<strong>Length:</strong> ' . strlen($hash) . ' characters<br>';
                echo '<strong>Algorithm:</strong> BCRYPT (cost=12)<br>';
                echo '<strong>Verification:</strong> ' . $verify . '<br>';
                echo '</div>';
            }
        }
        ?>
        
        <form method="POST" style="margin-top: 20px;">
            <label for="password">Enter password to hash:</label>
            <input type="password" id="password" name="password" required placeholder="Enter password...">
            <input type="submit" value="GENERATE HASH">
        </form>
        
        <hr>
        
        <div class="info">
            <h3>📋 Instructions</h3>
            <ul>
                <li>Use for admin passwords, not user passwords</li>
                <li>Copy the hash and paste into your database</li>
                <li>BCRYPT automatically includes salt - no need to add your own</li>
                <li>Each hash is unique even for the same password</li>
            </ul>
            
            <h3 style="margin-top: 20px;">🔍 Sample Hashes</h3>
            <table style="width:100%; border-collapse: collapse;">
                <tr>
                    <th style="text-align: left; padding: 5px;">Password</th>
                    <th style="text-align: left; padding: 5px;">Hash (first 20 chars)</th>
                </tr>
                <tr>
                    <td style="padding: 5px;">admin123</td>
                    <td style="padding: 5px; font-family: monospace;"><?php echo substr(password_hash('admin123', PASSWORD_BCRYPT), 0, 20); ?>...</td>
                </tr>
                <tr>
                    <td style="padding: 5px;">Regulator@2025</td>
                    <td style="padding: 5px; font-family: monospace;"><?php echo substr(password_hash('Regulator@2025', PASSWORD_BCRYPT), 0, 20); ?>...</td>
                </tr>
                <tr>
                    <td style="padding: 5px;">Compliance!1</td>
                    <td style="padding: 5px; font-family: monospace;"><?php echo substr(password_hash('Compliance!1', PASSWORD_BCRYPT), 0, 20); ?>...</td>
                </tr>
            </table>
        </div>
        
        <div style="margin-top: 30px; text-align: center; font-size: 0.8rem;">
            <p>⚠️ Store hashes securely · Never share plaintext passwords</p>
        </div>
    </div>
</body>
</html>
