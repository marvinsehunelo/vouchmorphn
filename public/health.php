<?php
// public/test.php
declare(strict_types=1);

use BUSINESS_LOGIC_LAYER\services\CardService;

// Require CardService directly
require_once __DIR__ . '/../src/BUSINESS_LOGIC_LAYER/services/CardService.php';

// Dummy PDO connection (adjust these credentials)
try {
    $pdo = new PDO('pgsql:host=localhost;port=5432;dbname=mydb', 'myuser', 'mypassword');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB connection failed: " . $e->getMessage());
}

// Dummy config and country code
$config = [];
$countryCode = 'BW';

// Instantiate CardService
$cardService = new CardService($pdo, $countryCode, $config);

// Output the class name
echo "<pre>";
echo "Class: " . get_class($cardService) . PHP_EOL;

// List all methods
echo "Methods available:" . PHP_EOL;
print_r(get_class_methods($cardService));

// Check if authorizeCardLoad exists
if (method_exists($cardService, 'authorizeCardLoad')) {
    echo "✅ authorizeCardLoad exists!" . PHP_EOL;
} else {
    echo "❌ authorizeCardLoad does NOT exist!" . PHP_EOL;
}

// Test calling authorizeCardLoad
try {
    $result = $cardService->authorizeCardLoad([
        'hold_reference' => 'TESTHOLD123',
        'swap_reference' => 'TESTSWAP123',
        'card_suffix' => '1234',
        'amount' => 1000.0
    ]);
    echo "Test call result:" . PHP_EOL;
    print_r($result);
} catch (Throwable $e) {
    echo "Error calling authorizeCardLoad: " . $e->getMessage() . PHP_EOL;
}
echo "</pre>";
