<?php
declare(strict_types=1);

namespace BUSINESS_LOGIC_LAYER\controllers;

use PDO;
use Exception;
use BUSINESS_LOGIC_LAYER\services\SwapService;
use BUSINESS_LOGIC_LAYER\services\UserService;
use DATA_PERSISTENCE_LAYER\config\DBConnection;

class USSDController
{
    private PDO $db;
    private SwapService $swapService;
    private UserService $userService;
    private array $config;
    private array $participants = [];
    private array $flows = [];
    
    private const USSD_LOG = '/tmp/vouchmorph_ussd.log';
    
    private const ASSET_TYPES = [
        '1' => ['type' => 'ewallet', 'icon' => '📱', 'label' => 'E-Wallet'],
        '2' => ['type' => 'voucher', 'icon' => '🎫', 'label' => 'Voucher'],
        '3' => ['type' => 'wallet', 'icon' => '💰', 'label' => 'Mobile Money'],
        '4' => ['type' => 'card', 'icon' => '💳', 'label' => 'Card'],
        '5' => ['type' => 'bank', 'icon' => '🏦', 'label' => 'Bank Account']
    ];
    
   public function __construct(array $config)
{
    $this->config = $config;
    
    // 1. Resolve PDO Connection
    $instance = DBConnection::getInstance($config);
    $this->db = (method_exists($instance, 'getConnection')) ? $instance->getConnection() : $instance;
    
    // 2. Load participants
    $this->loadParticipants();
    
    // 3. Setup Flows
    $flowsPath = __DIR__ . '/../../INTEGRATION_LAYER/config/flows.php';
    if (file_exists($flowsPath)) {
        $flowsConfig = require $flowsPath;
        $this->flows = $flowsConfig['all_supported_flows'] ?? [];
    }
    
    // 4. Initialize services
    // The use of 'use' at the top of the file handles the namespace.
    // If you are getting "already in use", ensure no other file is 
    // manually 'including' the UserService.php file.
    $this->swapService = new SwapService(
        $this->db,
        $config['settings'] ?? [],
        $config['country'] ?? 'BW',
        $config['encryption_key'] ?? '',
        $config
    );
    
    $this->userService = new UserService($this->db);
}

    private function loadParticipants(): void
    {
        $this->participants = [];
        foreach ($this->config['participants'] ?? [] as $name => $p) {
            $walletType = $p['wallet_type'] ?? null;
            if ($walletType && in_array($walletType, ['ewallet', 'voucher', 'wallet', 'card', 'bank'])) {
                $this->participants[$name] = [
                    'code' => $name,
                    'name' => strtoupper($name),
                    'display_name' => $p['display_name'] ?? strtoupper($name),
                    'wallet_type' => $walletType,
                    'type' => $p['type'] ?? 'bank',
                    'requires_voucher_number' => ($walletType === 'voucher'),
                    'requires_pin' => in_array($walletType, ['ewallet', 'voucher', 'wallet']),
                    'api_url' => $p['api_url'] ?? null
                ];
            }
        }
    }

    public function handleUSSDRequest(array $request): string
    {
        $sessionId = $request['sessionId'] ?? $request['SESSION_ID'] ?? '';
        $phoneNumber = $this->cleanPhoneNumber($request['phoneNumber'] ?? $request['MSISDN'] ?? '');
        $text = $request['text'] ?? $request['INPUT'] ?? '';
        
        $this->logUSSD("INCOMING", ['session' => $sessionId, 'phone' => $phoneNumber, 'text' => $text]);
        
        $input = explode('*', trim($text, '*'));
        if ($text === "") {
            $input = [];
        }
        
        $level = count($input);
        $currentInput = end($input) ?: '';
        
        try {
            $user = $this->findUserByPhone($phoneNumber);
            if (empty($text)) {
                return $this->showMainMenu($user);
            }
            return $this->processMenuLevel($input, $level, $currentInput, $user, $phoneNumber, $sessionId);
        } catch (Exception $e) {
            $this->logUSSD("ERROR", ['message' => $e->getMessage()]);
            return "END System error. Please try again later.";
        }
    }

    private function showMainMenu(?array $user): string
    {
        if ($user) {
            return "CON VouchMorph Swap\n1. New Swap\n2. My Swaps\n0. Exit";
        }
        return "CON Welcome to VouchMorph\n1. Register\n0. Exit";
    }

    private function processMenuLevel(array $input, int $level, string $currentInput, ?array $user, string $phoneNumber, string $sessionId): string
    {
        $mainOption = $input[0] ?? '';
        if (!$user && $mainOption !== '1') {
            return "END Please register first.";
        }
        
        switch ($mainOption) {
            case '1': 
                return (!$user) ? $this->handleRegistration($input, $level, $phoneNumber, $sessionId) : $this->handleNewSwap($input, $level, $currentInput, $user, $sessionId);
            case '2': 
                return $this->handleMySwaps($user);
            case '0': 
                return "END Thank you for using VouchMorph.";
            default: 
                return "END Invalid option.";
        }
    }

    /* --- SESSION PERSISTENCE (Crucial for USSD) --- */
    private function setSession(string $sessionId, string $key, $value): void
    {
        $stmt = $this->db->prepare("INSERT INTO ussd_sessions (session_id, session_key, session_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE session_value = VALUES(session_value)");
        $stmt->execute([$sessionId, $key, (string)$value]);
    }

    private function getSession(string $sessionId, string $key)
    {
        $stmt = $this->db->prepare("SELECT session_value FROM ussd_sessions WHERE session_id = ? AND session_key = ?");
        $stmt->execute([$sessionId, $key]);
        return $stmt->fetchColumn() ?: null;
    }

    private function clearSession(string $sessionId): void
    {
        $stmt = $this->db->prepare("DELETE FROM ussd_sessions WHERE session_id = ?");
        $stmt->execute([$sessionId]);
    }

    /* --- ASSET HELPERS --- */
    private function getAssetIcon(string $assetType): string
    {
        foreach (self::ASSET_TYPES as $asset) {
            if ($asset['type'] === $assetType) return $asset['icon'];
        }
        return '🏦';
    }

    private function cleanPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($phone, '267')) $phone = substr($phone, 3);
        return ltrim($phone, '0');
    }

    private function findUserByPhone(string $phone): ?array
    {
        $phone = $this->cleanPhoneNumber($phone);
        $stmt = $this->db->prepare("SELECT id, username, phone FROM users WHERE phone LIKE ?");
        $stmt->execute(['%' . $phone]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function logUSSD(string $event, array $data): void
    {
        $logEntry = json_encode(['timestamp' => date('c'), 'event' => $event, 'data' => $data]);
        file_put_contents(self::USSD_LOG, $logEntry . PHP_EOL, FILE_APPEND);
    }
    
    // Note: I've omitted handleNewSwap and handleRegistration for brevity, 
    // please ensure they are present in your local file!
}
