<?php
// ============================================================================
// GENIUS DEBUGGING TOOL - regulationdemo_debug.php (FIXED)
// Run this first to identify the exact issue
// ============================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/opt/lampp/logs/php_error.log');

// Function to test and report
function test_step($name, $callback) {
    echo "<div style='margin:10px 0; padding:10px; border-left:4px solid #ccc; background:#f9f9f9; font-family:monospace;'>";
    echo "<strong style='color:#333;'>🔍 Testing: {$name}</strong><br>";
    
    try {
        $start = microtime(true);
        $result = $callback();
        $time = round((microtime(true) - $start) * 1000, 2);
        
        if ($result === true) {
            echo "<span style='color:green;'>✅ PASSED</span> (⏱️ {$time}ms)<br>";
        } else {
            echo "<span style='color:orange;'>⚠️ WARNING</span> - " . htmlspecialchars($result) . " (⏱️ {$time}ms)<br>";
        }
    } catch (Throwable $e) {
        echo "<span style='color:red;'>❌ FAILED</span><br>";
        echo "<div style='background:#ffebee; padding:10px; margin-top:5px; border-radius:3px;'>";
        echo "<strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "<br>";
        echo "<strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "<br>";
        echo "<strong>Trace:</strong><br><pre style='font-size:11px;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        echo "</div>";
    }
    
    echo "</div>";
}

// Start HTML output
echo "<!DOCTYPE html><html><head><title>🔬 VouchMorph Genius Debugger</title>";
echo "<style>
    body{background:#1e1e2e; color:#e0e0e0; font-family:'Inter',monospace; padding:20px;}
    h1{color:#fff; border-bottom:2px solid #4CAF50; padding-bottom:10px;}
    .container{background:#2d2d3a; padding:20px; border-radius:5px;}
    code{background:#1e1e2e; padding:2px 5px; border-radius:3px; color:#ffa500;}
    .success{color:#4CAF50;}
    .warning{color:#ffa500;}
    .error{color:#ff6b6b;}
</style>";
echo "</head><body>";
echo "<h1>🔬 VOUCHMORPH GENIUS DEBUGGER</h1>";
echo "<div class='container'>";

// ============================================================================
// TEST 1: PHP Environment
// ============================================================================
test_step("PHP Environment", function() {
    $issues = [];
    if (version_compare(PHP_VERSION, '7.4.0', '<')) $issues[] = "PHP version too old: " . PHP_VERSION;
    if (!extension_loaded('pdo')) $issues[] = "PDO extension missing";
    if (!extension_loaded('pdo_pgsql')) $issues[] = "PostgreSQL PDO driver missing";
    if (!extension_loaded('json')) $issues[] = "JSON extension missing";
    if (!extension_loaded('session')) $issues[] = "Session extension missing";
    
    echo "<div>PHP Version: " . phpversion() . "</div>";
    echo "<div>Loaded Extensions: " . implode(', ', get_loaded_extensions()) . "</div>";
    echo "<div>Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "</div>";
    
    return empty($issues) ? true : "Issues: " . implode(', ', $issues);
});

// ============================================================================
// TEST 2: File System & Permissions
// ============================================================================
test_step("File System & Permissions", function() {
    $baseDir = __DIR__;
    $issues = [];
    
    // Check file existence
    $files = [
        'regulationdemo.php' => __DIR__ . '/regulationdemo.php',
        '../../src/bootstrap.php' => realpath(__DIR__ . '/../../src/bootstrap.php'),
        '../../src/CORE_CONFIG/countries/BW/participants_BW.json' => realpath(__DIR__ . '/../../src/CORE_CONFIG/countries/BW/participants_BW.json')
    ];
    
    foreach ($files as $name => $path) {
        if (!$path || !file_exists($path)) {
            $issues[] = "Missing: $name";
            echo "<div class='error'>❌ Missing: $name</div>";
        } else {
            echo "<div class='success'>✅ Found: $name</div>";
            
            // Check readability
            if (!is_readable($path)) {
                $issues[] = "Not readable: $name";
                echo "<div class='error'>  └─ Not readable</div>";
            } else {
                echo "<div class='success'>  └─ Readable</div>";
            }
            
            // Check filesize
            $size = filesize($path);
            echo "<div>  └─ Size: " . round($size / 1024, 2) . " KB</div>";
        }
    }
    
    // Check write permissions for logs
    $logDir = '/opt/lampp/logs';
    if (is_dir($logDir)) {
        if (is_writable($logDir)) {
            echo "<div class='success'>✅ Log directory writable</div>";
        } else {
            $issues[] = "Log directory not writable";
            echo "<div class='error'>❌ Log directory not writable</div>";
        }
    } else {
        echo "<div class='warning'>⚠️ Log directory not found: $logDir</div>";
    }
    
    return empty($issues) ? true : "Issues: " . implode(', ', $issues);
});

// ============================================================================
// TEST 3: Bootstrap Loading
// ============================================================================
test_step("Bootstrap Loading", function() {
    $bootstrapPath = __DIR__ . '/../../src/bootstrap.php';
    
    if (!file_exists($bootstrapPath)) {
        throw new Exception("Bootstrap file not found at: $bootstrapPath");
    }
    
    // Capture any output from bootstrap
    ob_start();
    require_once $bootstrapPath;
    $output = ob_get_clean();
    
    if (!empty($output)) {
        echo "<div class='warning'>⚠️ Bootstrap produced output: " . htmlspecialchars(substr($output, 0, 200)) . "</div>";
    }
    
    // Check if global config exists
    if (!isset($GLOBALS['config'])) {
        echo "<div class='warning'>⚠️ GLOBALS['config'] not set</div>";
    } else {
        echo "<div class='success'>✅ GLOBALS['config'] exists</div>";
        
        // Check config structure
        if (isset($GLOBALS['config']['db'])) {
            echo "<div>  └─ Database config present</div>";
        }
    }
    
    return true;
});

// ============================================================================
// TEST 4: Database Connection
// ============================================================================
test_step("Database Connection", function() {
    if (!class_exists('DATA_PERSISTENCE_LAYER\config\DBConnection')) {
        throw new Exception("DBConnection class not found - check autoloading");
    }
    
    try {
        $db = DATA_PERSISTENCE_LAYER\config\DBConnection::getConnection();
    } catch (Exception $e) {
        throw new Exception("Failed to connect: " . $e->getMessage());
    }
    
    if (!$db) {
        throw new Exception("getConnection() returned null");
    }
    
    echo "<div class='success'>✅ Connection established</div>";
    
    // Test basic query
    $stmt = $db->query("SELECT 1 as test");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && $result['test'] == 1) {
        echo "<div class='success'>✅ Basic query works</div>";
    } else {
        throw new Exception("Basic query failed");
    }
    
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "<div>Database driver: " . $driver . "</div>";
    
    if ($driver === 'pgsql') {
        $version = $db->query("SELECT version()")->fetchColumn();
        echo "<div>PostgreSQL: " . substr($version, 0, 50) . "...</div>";
    }
    
    return true;
});

// ============================================================================
// TEST 5: Table Access
// ============================================================================
test_step("Table Access", function() {
    $db = DATA_PERSISTENCE_LAYER\config\DBConnection::getConnection();
    
    // Check if PostgreSQL
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    
    if ($driver === 'pgsql') {
        // List all tables
        $tables = $db->query("
            SELECT tablename 
            FROM pg_catalog.pg_tables 
            WHERE schemaname = 'public'
            ORDER BY tablename
        ")->fetchAll(PDO::FETCH_COLUMN);
    } else {
        // MySQL
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    }
    
    echo "<div>📊 Found " . count($tables) . " tables:</div>";
    echo "<div style='display:flex; flex-wrap:wrap; gap:5px; margin:10px 0; max-height:200px; overflow-y:auto; padding:10px; background:#1e1e2e;'>";
    foreach ($tables as $table) {
        $style = '';
        if (in_array($table, ['net_positions', 'supervisory_heartbeat', 'swap_requests', 'swap_vouchers', 'settlement_messages', 'message_outbox', 'audit_logs', 'regulator_outbox'])) {
            $style = 'background:#4CAF50; color:white;';
        } else {
            $style = 'background:#444; color:#e0e0e0;';
        }
        echo "<span style='padding:3px 8px; border-radius:3px; font-size:12px; $style'>$table</span>";
    }
    echo "</div>";
    
    // Check specific tables needed
    $requiredTables = [
        'supervisory_heartbeat',
        'net_positions',
        'swap_requests',
        'swap_vouchers',
        'settlement_messages',
        'message_outbox',
        'swap_ledgers',
        'audit_logs',
        'regulator_outbox'
    ];
    
    $missing = [];
    $existing = [];
    foreach ($requiredTables as $table) {
        if (in_array($table, $tables)) {
            $existing[] = $table;
            
            // Test query each table
            try {
                $count = $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
                echo "<div>  └─ $table: $count rows</div>";
            } catch (Exception $e) {
                echo "<div class='warning'>  └─ $table: Error counting: " . $e->getMessage() . "</div>";
            }
            
        } else {
            $missing[] = $table;
        }
    }
    
    if (!empty($missing)) {
        echo "<div class='warning'>⚠️ Missing tables: " . implode(', ', $missing) . "</div>";
    }
    
    echo "<div class='success'>✅ Existing tables: " . implode(', ', $existing) . "</div>";
    
    return true;
});

// ============================================================================
// TEST 6: Memory & Performance
// ============================================================================
test_step("Memory & Performance", function() {
    $memoryLimit = ini_get('memory_limit');
    $maxExecution = ini_get('max_execution_time');
    $postMaxSize = ini_get('post_max_size');
    $uploadMax = ini_get('upload_max_filesize');
    $displayErrors = ini_get('display_errors');
    $errorReporting = error_reporting();
    
    echo "<div>Memory Limit: $memoryLimit</div>";
    echo "<div>Max Execution: {$maxExecution}s</div>";
    echo "<div>Post Max Size: $postMaxSize</div>";
    echo "<div>Upload Max: $uploadMax</div>";
    echo "<div>Display Errors: " . ($displayErrors ? 'ON' : 'OFF') . "</div>";
    echo "<div>Error Reporting: " . $errorReporting . "</div>";
    
    $memoryUsage = memory_get_usage(true);
    $memoryPeak = memory_get_peak_usage(true);
    
    echo "<div>Current Memory: " . round($memoryUsage / 1024 / 1024, 2) . " MB</div>";
    echo "<div>Peak Memory: " . round($memoryPeak / 1024 / 1024, 2) . " MB</div>";
    
    if ($memoryPeak > 100 * 1024 * 1024) {
        return "High memory usage detected";
    }
    
    return true;
});

// ============================================================================
// TEST 7: Session Handling
// ============================================================================
test_step("Session Handling", function() {
    if (session_status() === PHP_SESSION_DISABLED) {
        throw new Exception("Sessions are disabled in PHP configuration");
    }
    
    $sessionSavePath = session_save_path();
    echo "<div>Session save path: " . ($sessionSavePath ?: 'default') . "</div>";
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
        echo "<div class='success'>✅ Session started</div>";
    } else {
        echo "<div class='success'>✅ Session already active</div>";
    }
    
    // Test session write
    $_SESSION['debug_test_' . time()] = time();
    echo "<div>Session ID: " . session_id() . "</div>";
    
    return true;
});

// ============================================================================
// TEST 8: JSON Configuration Files
// ============================================================================
test_step("JSON Configuration Files", function() {
    $countries = ['BW', 'KE', 'NG'];
    $issues = [];
    
    foreach ($countries as $country) {
        $path = __DIR__ . "/../../src/CORE_CONFIG/countries/{$country}/participants_{$country}.json";
        
        if (!file_exists($path)) {
            $issues[] = "Missing participants file for {$country}";
            echo "<div class='warning'>⚠️ Missing: {$country}</div>";
            continue;
        }
        
        $content = file_get_contents($path);
        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $issues[] = "Invalid JSON in {$country}: " . json_last_error_msg();
            echo "<div class='error'>❌ {$country}: " . json_last_error_msg() . "</div>";
        } else {
            $participantCount = isset($data['participants']) ? count($data['participants']) : 0;
            $bankCount = isset($data['banks']) ? count($data['banks']) : 0;
            $mnoCount = isset($data['mno']) ? count($data['mno']) : 0;
            
            echo "<div class='success'>✅ {$country}: " . ($participantCount ?: $bankCount + $mnoCount) . " participants</div>";
            
            // Check structure
            if (isset($data['participants'])) {
                echo "<div>  └─ Has 'participants' structure</div>";
            } elseif (isset($data['banks']) || isset($data['mno'])) {
                echo "<div>  └─ Has separate banks/mno structure</div>";
            }
        }
    }
    
    return empty($issues) ? true : "Issues: " . implode(', ', $issues);
});

// ============================================================================
// TEST 9: Try to Load the Actual Page (with error suppression)
// ============================================================================
test_step("Simulated Page Load", function() {
    $pagePath = __DIR__ . '/regulationdemo.php';
    
    if (!file_exists($pagePath)) {
        throw new Exception("regulationdemo.php not found at: $pagePath");
    }
    
    // Check syntax
    $syntaxCheck = shell_exec("php -l " . escapeshellarg($pagePath) . " 2>&1");
    if (strpos($syntaxCheck, 'No syntax errors') === false) {
        echo "<div class='error'>❌ Syntax error detected:</div>";
        echo "<pre style='background:#1e1e2e; color:#ff6b6b; padding:10px;'>" . htmlspecialchars($syntaxCheck) . "</pre>";
        throw new Exception("PHP syntax error in regulationdemo.php");
    } else {
        echo "<div class='success'>✅ No syntax errors</div>";
    }
    
    // Set up environment
    $_GET = ['view' => 'audit', 'country' => 'BW', 'regulator_view' => 'operations'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = null;
    
    // Capture output
    ob_start();
    $included = false;
    $error = null;
    
    try {
        include $pagePath;
        $included = true;
    } catch (Throwable $e) {
        $error = $e;
    }
    
    $output = ob_get_clean();
    
    if ($error) {
        echo "<div class='error'>❌ Page threw exception during include:</div>";
        echo "<pre style='background:#1e1e2e; color:#ff6b6b; padding:10px;'>" . htmlspecialchars($error->getMessage()) . "</pre>";
        throw $error;
    }
    
    if (!$included) {
        throw new Exception("Page could not be included");
    }
    
    // Check output
    if (empty($output)) {
        echo "<div class='warning'>⚠️ Page produced no output (blank page)</div>";
    } else {
        $outputLength = strlen($output);
        echo "<div class='success'>✅ Page loaded, output length: $outputLength bytes</div>";
        
        // Check for error indicators in output
        if (preg_match('/Fatal error|Warning|Notice|Exception/i', $output)) {
            echo "<div class='warning'>⚠️ Output contains error indicators</div>";
            echo "<pre style='background:#1e1e2e; color:#ffa500; padding:10px; max-height:200px; overflow:auto;'>" . htmlspecialchars(substr($output, 0, 1000)) . "...</pre>";
        }
    }
    
    return true;
});

// ============================================================================
// TEST 10: Error Log Analysis
// ============================================================================
test_step("Error Log Analysis", function() {
    $logFile = '/opt/lampp/logs/php_error.log';
    $apacheLog = '/opt/lampp/logs/error_log';
    
    $logs = [];
    if (file_exists($logFile)) $logs[] = $logFile;
    if (file_exists($apacheLog)) $logs[] = $apacheLog;
    
    if (empty($logs)) {
        echo "<div class='warning'>⚠️ No log files found</div>";
        return true;
    }
    
    $foundErrors = false;
    foreach ($logs as $logPath) {
        if (!is_readable($logPath)) {
            echo "<div class='warning'>⚠️ Log not readable: $logPath</div>";
            continue;
        }
        
        echo "<div>📋 Analyzing: $logPath</div>";
        
        // Get last 50 lines
        $lines = [];
        $fp = fopen($logPath, 'r');
        if ($fp) {
            $pos = -2;
            $t = " ";
            while (count($lines) < 50 && ($pos > -filesize($logPath))) {
                fseek($fp, $pos, SEEK_END);
                $t = fgetc($fp);
                if ($t == "\n") {
                    $line = fgets($fp);
                    if ($line !== false) {
                        array_unshift($lines, trim($line));
                    }
                }
                $pos--;
            }
            fclose($fp);
        }
        
        if (empty($lines)) {
            echo "<div>  └─ No recent log entries</div>";
            continue;
        }
        
        echo "<div style='background:#1e1e2e; padding:10px; border-radius:3px; max-height:200px; overflow:auto; font-size:11px;'>";
        
        foreach ($lines as $line) {
            if (empty($line)) continue;
            
            // Highlight errors related to regulationdemo
            if (strpos($line, 'regulationdemo') !== false) {
                echo "<span style='color:#ff6b6b;'>🔴 " . htmlspecialchars($line) . "</span><br>";
                $foundErrors = true;
            } else if (preg_match('/Fatal|Error|Warning|Exception/i', $line)) {
                if (strpos($line, 'regulationdemo') !== false) {
                    echo "<span style='color:#ffa500;'>🟠 " . htmlspecialchars($line) . "</span><br>";
                }
            }
        }
        echo "</div>";
    }
    
    if (!$foundErrors) {
        echo "<div class='success'>✅ No regulationdemo-specific errors found in logs</div>";
    }
    
    return true;
});

// ============================================================================
// FINAL SUMMARY
// ============================================================================
echo "<div style='margin-top:30px; padding:20px; background:#2d2d3a; border-radius:5px;'>";
echo "<h2 style='color:#fff;'>📋 DIAGNOSIS COMPLETE</h2>";

echo "<div style='background:#1e1e2e; padding:15px; border-radius:3px;'>";

// Check for common issues
$recommendations = [];

// Check PHP version
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    $recommendations[] = "Upgrade PHP to 8.0+ (current: " . PHP_VERSION . ")";
}

// Check if regulationdemo.php exists
if (!file_exists(__DIR__ . '/regulationdemo.php')) {
    $recommendations[] = "regulationdemo.php file is missing!";
}

// Check error log for fatal errors
$logFile = '/opt/lampp/logs/php_error.log';
if (file_exists($logFile) && is_readable($logFile)) {
    $fp = fopen($logFile, 'r');
    fseek($fp, -5000, SEEK_END);
    $lastLines = fread($fp, 5000);
    fclose($fp);
    
    if (preg_match('/Fatal error.*regulationdemo.*on line (\d+)/', $lastLines, $matches)) {
        $recommendations[] = "Fatal error on line " . $matches[1] . " of regulationdemo.php - check this line";
    }
}

echo "<h3 style='color:#fff;'>🚀 RECOMMENDATIONS:</h3>";
if (empty($recommendations)) {
    echo "<p class='success'>✅ No specific recommendations - the issue might be in the database data or configuration</p>";
    echo "<p>Try these manual checks:</p>";
} else {
    foreach ($recommendations as $rec) {
        echo "<p class='warning'>⚠️ " . htmlspecialchars($rec) . "</p>";
    }
}

echo "<ol style='color:#e0e0e0;'>";
echo "<li><strong>Check the actual error in real-time:</strong><br>";
echo "<code style='background:#1e1e2e; padding:5px; display:block; margin:5px 0;'>sudo tail -f /opt/lampp/logs/php_error.log | grep -i regulationdemo</code></li>";
echo "<li><strong>Then refresh: </strong><a href='regulationdemo.php?view=audit&country=BW&regulator_view=operations' target='_blank' style='color:#4CAF50;'>regulationdemo.php?view=audit&country=BW&regulator_view=operations</a></li>";
echo "<li><strong>Check database tables have data:</strong><br>";
echo "<code style='background:#1e1e2e; padding:5px; display:block; margin:5px 0;'>SELECT COUNT(*) FROM net_positions;</code></li>";
echo "<li><strong>Create a minimal test:</strong><br>";
echo "<code style='background:#1e1e2e; padding:5px; display:block; margin:5px 0;'>echo \"<?php phpinfo(); ?>\" > " . __DIR__ . "/phpinfo.php</code><br>";
echo "<a href='phpinfo.php' target='_blank'>Check phpinfo()</a></li>";
echo "</ol>";

echo "</div>";
echo "</div>";

echo "<div style='margin-top:20px; text-align:right; color:#888; font-size:12px;'>";
echo "Debug completed at: " . date('Y-m-d H:i:s') . "<br>";
echo "Peak Memory: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB";
echo "</div>";

echo "</div>";
echo "</body></html>";
?>
