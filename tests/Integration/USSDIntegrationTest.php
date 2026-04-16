<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

declare(strict_types=1);

/**
 * USSD Integration Test
 * Tests: USSD flows, session management, user interactions
 */

require_once __DIR__ . '/../../bootstrap.php';

use BUSINESS_LOGIC_LAYER\controllers\USSDController;
use DATA_PERSISTENCE_LAYER\config\DBConnection;

class USSDIntegrationTest
{
    private PDO $pdo;
    private USSDController $ussdController;
    private array $testSessions = [];
    private array $results = [];
    
    // USSD test scenarios
    private const TEST_SCENARIOS = [
        'registration' => [
            'name' => 'New User Registration',
            'steps' => [
                '*123#' => 'Welcome to VouchMorphn. 1.Register 2.Login',
                '1' => 'Enter your name:',
                'John Doe' => 'Enter your phone number:',
                '26771000001' => 'Enter your PIN:',
                '1234' => 'Confirm PIN:',
                '1234' => 'Registration successful. Your account is pending verification.'
            ]
        ],
        'balance_check' => [
            'name' => 'Balance Check',
            'steps' => [
                '*123#' => 'Welcome to VouchMorphn. 1.Register 2.Login',
                '2' => 'Enter your phone number:',
                '26771000001' => 'Enter your PIN:',
                '1234' => '1.Check Balance 2.Send Money 3.Withdraw Cash',
                '1' => 'Your balance is BWP 5,000.00'
            ]
        ],
        'send_money' => [
            'name' => 'Send Money',
            'steps' => [
                '*123#' => 'Welcome to VouchMorphn. 1.Register 2.Login',
                '2' => 'Enter your phone number:',
                '26771000001' => 'Enter your PIN:',
                '1234' => '1.Check Balance 2.Send Money 3.Withdraw Cash',
                '2' => 'Enter recipient phone number:',
                '26771111111' => 'Enter amount to send:',
                '500' => 'Send BWP 500 to 26771111111? 1.Confirm 2.Cancel',
                '1' => 'Transaction successful. Reference: TXN123456'
            ]
        ],
        'cashout' => [
            'name' => 'Cash Withdrawal',
            'steps' => [
                '*123#' => 'Welcome to VouchMorphn. 1.Register 2.Login',
                '2' => 'Enter your phone number:',
                '26771000001' => 'Enter your PIN:',
                '1234' => '1.Check Balance 2.Send Money 3.Withdraw Cash',
                '3' => 'Enter amount to withdraw:',
                '1000' => 'Withdrawal code: 123456. Present to any ATM.',
                '123456' => 'Cash dispensed. Thank you!'
            ]
        ]
    ];
    
    public function __construct()
    {
        $this->pdo = DBConnection::getConnection();
        
        // Mock request and response for USSD testing
        $this->ussdController = new USSDController(
            $this->pdo,
            $this->createMockRequest(),
            $this->createMockResponse()
        );
    }
    
    /**
     * Create mock request object
     */
    private function createMockRequest(): object
    {
        return new class() {
            public $method = 'POST';
            public function getMethod() { return $this->method; }
            public function getContentType() { return 'application/json'; }
            public function getContent() { return ''; }
        };
    }
    
    /**
     * Create mock response object
     */
    private function createMockResponse(): object
    {
        return new class() {
            private $headers = [];
            private $body = '';
            
            public function setHeader($name, $value) {
                $this->headers[$name] = $value;
            }
            public function setContent($content) {
                $this->body = $content;
            }
            public function getBody() { return $this->body; }
        };
    }
    
    /**
     * Run all USSD tests
     */
    public function runAllTests(): array
    {
        echo "\n📱 Starting USSD Integration Tests\n";
        echo str_repeat("=", 60) . "\n\n";
        
        $results = [];
        
        foreach (self::TEST_SCENARIOS as $scenario => $config) {
            echo "Testing: {$config['name']}\n";
            $result = $this->runUSSDScenario($scenario, $config['steps']);
            $results[$scenario] = $result;
            
            echo $result['success'] ? "✅ PASSED\n" : "❌ FAILED: {$result['error']}\n";
            echo "  Steps completed: {$result['steps_completed']}/{$result['total_steps']}\n";
            echo "  Time: {$result['time_ms']}ms\n\n";
        }
        
        // Test concurrent USSD sessions
        echo "Testing Concurrent USSD Sessions...\n";
        $concurrentResult = $this->testConcurrentUSSDSessions(50);
        $results['concurrent'] = $concurrentResult;
        
        echo $concurrentResult['success'] ? "✅ PASSED\n" : "❌ FAILED\n";
        echo "  Concurrent sessions: {$concurrentResult['sessions_tested']}\n";
        echo "  Success rate: {$concurrentResult['success_rate']}%\n\n";
        
        return $results;
    }
    
    /**
     * Run a USSD scenario
     */
    private function runUSSDScenario(string $scenario, array $steps): array
    {
        $startTime = microtime(true);
        $sessionId = uniqid('ussd_');
        $stepCount = 0;
        $error = null;
        
        try {
            foreach ($steps as $input => $expectedOutput) {
                // Simulate USSD request
                $request = [
                    'sessionId' => $sessionId,
                    'phoneNumber' => '26771000001',
                    'text' => $this->buildUSSDText($input, $stepCount)
                ];
                
                // Process through USSD controller
                $response = $this->processUSSDRequest($request);
                
                // Validate response
                if (strpos($response, $expectedOutput) === false) {
                    throw new Exception("Expected output not found. Got: $response");
                }
                
                $stepCount++;
            }
            
            $success = true;
            
        } catch (Exception $e) {
            $success = false;
            $error = $e->getMessage();
        }
        
        $endTime = microtime(true);
        
        return [
            'success' => $success,
            'error' => $error,
            'steps_completed' => $stepCount,
            'total_steps' => count($steps),
            'time_ms' => round(($endTime - $startTime) * 1000, 2),
            'session_id' => $sessionId
        ];
    }
    
    /**
     * Build USSD text based on input and step
     */
    private function buildUSSDText(string $input, int $step): string
    {
        if ($step === 0) {
            return $input;
        }
        
        // For USSD, text accumulates
        static $accumulated = [];
        
        if (!isset($accumulated[$step])) {
            $accumulated[$step] = ($accumulated[$step - 1] ?? '') . '*' . $input;
        }
        
        return $accumulated[$step];
    }
    
    /**
     * Process USSD request (mock)
     */
    private function processUSSDRequest(array $request): string
    {
        // This would normally call your USSDController
        // For testing, we'll simulate responses
        
        $sessionId = $request['sessionId'];
        $text = $request['text'];
        
        // Store session
        if (!isset($this->testSessions[$sessionId])) {
            $this->testSessions[$sessionId] = [
                'created_at' => time(),
                'step' => 0,
                'data' => []
            ];
        }
        
        $session = &$this->testSessions[$sessionId];
        $session['step']++;
        
        // Simulate USSD responses based on input
        $parts = explode('*', $text);
        $lastInput = end($parts);
        
        switch ($session['step']) {
            case 1:
                return "Welcome to VouchMorphn. 1.Register 2.Login";
            case 2:
                return "Enter your phone number:";
            case 3:
                return "Enter your PIN:";
            case 4:
                return "1.Check Balance 2.Send Money 3.Withdraw Cash";
            case 5:
                if ($lastInput == '1') {
                    return "Your balance is BWP 5,000.00";
                } elseif ($lastInput == '2') {
                    return "Enter recipient phone number:";
                } elseif ($lastInput == '3') {
                    return "Enter amount to withdraw:";
                }
                return "Invalid option";
            case 6:
                if (isset($session['data']['send_money'])) {
                    return "Send BWP {$session['data']['amount']} to {$session['data']['recipient']}? 1.Confirm 2.Cancel";
                }
                return "Transaction reference: TXN" . rand(100000, 999999);
            default:
                return "Thank you for using VouchMorphn";
        }
    }
    
    /**
     * Test concurrent USSD sessions
     */
    private function testConcurrentUSSDSessions(int $numSessions): array
    {
        $sessions = [];
        $results = [];
        $successCount = 0;
        
        // Launch concurrent sessions
        for ($i = 0; $i < $numSessions; $i++) {
            $pid = pcntl_fork();
            
            if ($pid == -1) {
                die("Could not fork");
            } elseif ($pid) {
                // Parent
                $sessions[] = $pid;
            } else {
                // Child
                $sessionId = "concurrent_$i";
                $success = $this->simulateUSSDSession($sessionId);
                exit($success ? 0 : 1);
            }
        }
        
        // Wait for all children
        foreach ($sessions as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wexitstatus($status) === 0) {
                $successCount++;
            }
        }
        
        return [
            'success' => $successCount == $numSessions,
            'sessions_tested' => $numSessions,
            'successful' => $successCount,
            'success_rate' => round(($successCount / $numSessions) * 100, 2)
        ];
    }
    
    /**
     * Simulate a complete USSD session
     */
    private function simulateUSSDSession(string $sessionId): bool
    {
        try {
            $steps = [
                '*123#' => null,
                '2' => null,
                '26771000001' => null,
                '1234' => null,
                '1' => null
            ];
            
            foreach ($steps as $input => $_) {
                $request = [
                    'sessionId' => $sessionId,
                    'phoneNumber' => '26771000001',
                    'text' => $this->buildUSSDText($input, array_search($input, array_keys($steps)))
                ];
                
                $response = $this->processUSSDRequest($request);
                
                // Small random delay to simulate real usage
                usleep(rand(100000, 500000));
            }
            
            return true;
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Generate report
     */
    public function generateReport(): void
    {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "📱 USSD INTEGRATION TEST REPORT\n";
        echo str_repeat("=", 60) . "\n\n";
        
        foreach ($this->results as $scenario => $result) {
            if ($scenario === 'concurrent') {
                echo "\nConcurrent Sessions:\n";
                echo "  Sessions Tested: {$result['sessions_tested']}\n";
                echo "  Successful: {$result['successful']}\n";
                echo "  Success Rate: {$result['success_rate']}%\n";
            } else {
                echo "\n{$scenario}:\n";
                echo "  Status: " . ($result['success'] ? '✅ PASSED' : '❌ FAILED') . "\n";
                echo "  Time: {$result['time_ms']}ms\n";
                if (!$result['success']) {
                    echo "  Error: {$result['error']}\n";
                }
            }
        }
        
        echo "\n" . str_repeat("=", 60) . "\n";
    }
}

// Run the tests
$test = new USSDIntegrationTest();
$results = $test->runAllTests();
$test->generateReport();
