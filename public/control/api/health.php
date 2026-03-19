<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';

use DATA_PERSISTENCE_LAYER\config\DBConnection;

$db = DBConnection::getConnection();

header('Content-Type: application/json');

$health = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'uptime' => getUptime(),
    'database' => checkDatabase($db),
    'api' => checkAPI(),
    'memory' => getMemoryUsage(),
    'disk' => getDiskUsage(),
    'services' => checkServices(),
    'alerts' => []
];

// Add alerts if any issues
if (!$health['database']['connected']) {
    $health['status'] = 'degraded';
    $health['alerts'][] = 'Database connection issues';
}

if ($health['memory']['usage_percent'] > 85) {
    $health['status'] = 'degraded';
    $health['alerts'][] = 'High memory usage';
}

if ($health['disk']['usage_percent'] > 90) {
    $health['status'] = 'degraded';
    $health['alerts'][] = 'Low disk space';
}

echo json_encode($health, JSON_PRETTY_PRINT);

function getUptime() {
    if (file_exists('/proc/uptime')) {
        $uptime = (float) file_get_contents('/proc/uptime');
        $days = floor($uptime / 86400);
        $hours = floor(($uptime % 86400) / 3600);
        return "$days days, $hours hours";
    }
    return 'unknown';
}

function checkDatabase($db) {
    try {
        $start = microtime(true);
        $db->query('SELECT 1')->fetch();
        $latency = (microtime(true) - $start) * 1000;
        
        // Get connection count
        $connQuery = $db->query("SELECT count(*) FROM pg_stat_activity");
        $connections = $connQuery->fetchColumn();
        
        return [
            'connected' => true,
            'latency_ms' => round($latency, 2),
            'connections' => $connections,
            'version' => $db->getAttribute(PDO::ATTR_SERVER_VERSION)
        ];
    } catch (Exception $e) {
        return [
            'connected' => false,
            'error' => $e->getMessage()
        ];
    }
}

function checkAPI() {
    $endpoints = [
        '/api/v1/health' => 'Health Check',
        '/api/v1/swap/execute' => 'Swap Execute',
        '/api/v1/verify-asset' => 'Asset Verification'
    ];
    
    $results = [];
    foreach ($endpoints as $endpoint => $name) {
        $start = microtime(true);
        $ch = curl_init('http://localhost' . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $latency = (microtime(true) - $start) * 1000;
        
        $results[$name] = [
            'endpoint' => $endpoint,
            'status' => $httpCode >= 200 && $httpCode < 300 ? 'up' : 'down',
            'http_code' => $httpCode,
            'latency_ms' => round($latency, 2)
        ];
        
        curl_close($ch);
    }
    
    return $results;
}

function getMemoryUsage() {
    $memInfo = file_get_contents('/proc/meminfo');
    preg_match('/MemTotal:\s+(\d+)/', $memInfo, $total);
    preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $available);
    
    if (isset($total[1]) && isset($available[1])) {
        $totalMem = $total[1] / 1024; // MB
        $usedMem = ($total[1] - $available[1]) / 1024; // MB
        $usagePercent = ($usedMem / $totalMem) * 100;
        
        return [
            'total_mb' => round($totalMem),
            'used_mb' => round($usedMem),
            'free_mb' => round($totalMem - $usedMem),
            'usage_percent' => round($usagePercent, 2)
        ];
    }
    
    return ['error' => 'Unable to read memory info'];
}

function getDiskUsage() {
    $total = disk_total_space('/');
    $free = disk_free_space('/');
    $used = $total - $free;
    
    return [
        'total_gb' => round($total / 1024 / 1024 / 1024, 2),
        'used_gb' => round($used / 1024 / 1024 / 1024, 2),
        'free_gb' => round($free / 1024 / 1024 / 1024, 2),
        'usage_percent' => round(($used / $total) * 100, 2)
    ];
}

function checkServices() {
    $services = [
        'postgresql' => 'pg_isready',
        'nginx' => 'systemctl is-active nginx',
        'php-fpm' => 'systemctl is-active php8.1-fpm',
        'redis' => 'redis-cli ping'
    ];
    
    $results = [];
    foreach ($services as $service => $command) {
        $output = shell_exec($command . ' 2>&1');
        $results[$service] = [
            'status' => strpos($output, 'active') !== false || strpos($output, 'PONG') !== false ? 'running' : 'stopped',
            'output' => trim($output)
        ];
    }
    
    return $results;
}
?>
