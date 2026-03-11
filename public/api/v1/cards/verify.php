<?php
declare(strict_types=1);

// Headers - good!
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-Correlation-ID'); // Added X-Correlation-ID

// Fix paths - they look correct now (4 levels up to root)
require_once __DIR__ . '/../../../../src/bootstrap.php';
require_once __DIR__ . '/../../../../src/BUSINESS_LOGIC_LAYER/services/CardService.php';

use BUSINESS_LOGIC_LAYER\services\CardService;

try {
    // Better API key validation - use your existing auth function if available
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $apiKey = str_replace('Bearer ', '', $apiKey);
    
    // Use your existing validateApiKey function if it exists
    if (function_exists('validateApiKey')) {
        if (!validateApiKey($apiKey)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid API key']);
            exit;
        }
    } else {
        // Fallback to hardcoded check (temporary)
        if ($apiKey !== 'sys_key_2026_sandbox_001') {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid API key']);
            exit;
        }
    }

    // Get request body
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_GET; // Fallback to GET params
    }
    
    // IMPORTANT: Don't redefine getDatabaseConnection if bootstrap already provides it
    // Just use the existing one from bootstrap
    if (!function_exists('getDatabaseConnection')) {
        // Define it only if bootstrap doesn't provide it
        function getDatabaseConnection() {
            static $pdo = null;
            if ($pdo === null) {
                // Use environment variables from Railway
                $host = $_ENV['PGHOST'] ?? $_ENV['DB_HOST'] ?? 'localhost';
                $port = $_ENV['PGPORT'] ?? $_ENV['DB_PORT'] ?? '5432';
                $dbname = $_ENV['PGDATABASE'] ?? $_ENV['DB_NAME'] ?? 'postgres';
                $user = $_ENV['PGUSER'] ?? $_ENV['DB_USER'] ?? 'postgres';
                $pass = $_ENV['PGPASSWORD'] ?? $_ENV['DB_PASS'] ?? '';
                
                // Check for Railway's DATABASE_URL
                if (getenv('DATABASE_URL')) {
                    $dbUrl = parse_url(getenv('DATABASE_URL'));
                    $host = $dbUrl['host'] ?? $host;
                    $port = $dbUrl['port'] ?? $port;
                    $user = $dbUrl['user'] ?? $user;
                    $pass = $dbUrl['pass'] ?? $pass;
                    $dbname = ltrim($dbUrl['path'] ?? '', '/') ?: $dbname;
                }
                
                $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
                $pdo = new PDO($dsn, $user, $pass);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }
            return $pdo;
        }
    }
    
    // Get database connection (from bootstrap or our fallback)
    $db = getDatabaseConnection();
    
    // Load participant config - bootstrap should provide this
    $participants = [];
    if (function_exists('loadParticipantsConfig')) {
        $config = loadParticipantsConfig();
        $participants = $config['participants'] ?? [];
    }
    
    $cardService = new CardService(
        $db, 
        'BWP', 
        $participants['vouchmorph'] ?? []  // Pass VouchMorph config
    );
    
    // Extract verification data
    $assetType = $input['asset_type'] ?? '';
    $amount = (float)($input['amount'] ?? 0);
    $reference = $input['reference'] ?? '';
    
    // Handle different verification types
    if ($assetType === 'CARD') {
        // Verifying a card authorization (message-based)
        $cardSuffix = $input['card']['card_suffix'] ?? 
                     $input['card_suffix'] ?? 
                     $input['card_number'] ?? null;
        
        if (!$cardSuffix) {
            throw new Exception("Card identifier required");
        }
        
        // Get card authorization (the message)
        $cardInfo = $cardService->getCardAuthorization($cardSuffix);
        
        // Check if enough authorized amount remains
        if ($cardInfo['remaining_balance'] < $amount) {
            echo json_encode([
                'verified' => false,
                'message' => 'Insufficient authorization on card',
                'available' => $cardInfo['remaining_balance'],
                'requested' => $amount
            ]);
            exit;
        }
        
        // Return verification of the MESSAGE, not real money
        echo json_encode([
            'verified' => true,
            'asset_id' => $cardInfo['authorization_id'],
            'asset_type' => 'CARD_AUTHORIZATION',
            'available_balance' => $cardInfo['remaining_balance'],
            'holder_name' => $cardInfo['cardholder_name'],
            'hold_reference' => $cardInfo['hold_reference'],
            'is_message' => true,
            'metadata' => [
                'card_suffix' => $cardSuffix,
                'authorized_amount' => $cardInfo['authorized_amount'],
                'expiry' => $cardInfo['expiry']
            ]
        ]);
        
    } elseif ($assetType === 'E-WALLET' || $assetType === 'ACCOUNT') {
        // Regular asset verification - forward to bank
        echo json_encode([
            'verified' => false,
            'message' => 'E-WALLET verification not implemented in this endpoint'
        ]);
        
    } else {
        throw new Exception("Unsupported asset type: {$assetType}");
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'verified' => false,
        'error' => $e->getMessage()
    ]);
}
