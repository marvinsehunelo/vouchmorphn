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

    /**
     * Internal source asset map for USSD display -> payload format
     */
    private const SOURCE_TYPES = [
        '1' => ['type' => 'e-wallet', 'label' => 'E-Wallet'],
        '2' => ['type' => 'voucher',  'label' => 'Voucher'],
        '3' => ['type' => 'wallet',   'label' => 'Wallet'],
        '4' => ['type' => 'account',  'label' => 'Bank Account'],
    ];

    public function __construct(array $config)
    {
        $this->config = $config;

        $instance = DBConnection::getInstance($config);
        $this->db = method_exists($instance, 'getConnection') ? $instance->getConnection() : $instance;

        $this->loadParticipants();

        $flowsPath = __DIR__ . '/../../INTEGRATION_LAYER/config/flows.php';
        if (file_exists($flowsPath)) {
            $flowsConfig = require $flowsPath;
            $this->flows = $flowsConfig['all_supported_flows'] ?? [];
        }

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
            $walletType = strtolower((string)($p['wallet_type'] ?? ''));

            $normalizedWalletType = match ($walletType) {
                'ewallet'  => 'e-wallet',
                'e-wallet' => 'e-wallet',
                'wallet'   => 'wallet',
                'voucher'  => 'voucher',
                'card'     => 'card',
                'bank',
                'account'  => 'account',
                default    => null,
            };

            if ($normalizedWalletType) {
                $this->participants[$name] = [
                    'code' => $name,
                    'name' => strtoupper($name),
                    'display_name' => $p['display_name'] ?? strtoupper($name),
                    'wallet_type' => $normalizedWalletType,
                    'type' => $p['type'] ?? 'bank',
                    'requires_voucher_number' => ($normalizedWalletType === 'voucher'),
                    'requires_pin' => in_array($normalizedWalletType, ['wallet', 'voucher'], true),
                    'api_url' => $p['api_url'] ?? null
                ];
            }
        }
    }

    public function handleUSSDRequest(array $request): string
    {
        $sessionId   = trim((string)($request['sessionId'] ?? $request['SESSION_ID'] ?? ''));
        $phoneNumber = $this->cleanPhoneNumber((string)($request['phoneNumber'] ?? $request['MSISDN'] ?? ''));
        $text        = trim((string)($request['text'] ?? $request['INPUT'] ?? ''));

        $this->logUSSD('INCOMING', [
            'session' => $sessionId,
            'phone'   => $phoneNumber,
            'text'    => $text
        ]);

        $input = ($text === '') ? [] : explode('*', trim($text, '*'));

        try {
            // Auto-register / auto-load user on every session
            $user = $this->findOrCreateUserByPhone($phoneNumber);

            if ($text === '') {
                $this->clearSession($sessionId);
                $this->setSession($sessionId, 'msisdn', $this->formatMsisdnForSwap($phoneNumber));
                $this->setSession($sessionId, 'local_phone', $phoneNumber);

                return $this->showMainMenu($user);
            }

            return $this->routeMenu($input, $user, $sessionId, $phoneNumber);
        } catch (Exception $e) {
            $this->logUSSD('ERROR', ['message' => $e->getMessage()]);
            return "END System error. Please try again later.";
        }
    }

    private function showMainMenu(array $user): string
    {
        return "CON Welcome to VouchMorph\n"
            . "1. New Swap\n"
            . "2. My Swaps\n"
            . "0. Exit";
    }

    private function routeMenu(array $input, array $user, string $sessionId, string $phoneNumber): string
    {
        $mainOption = $input[0] ?? '';

        return match ($mainOption) {
            '1' => $this->handleNewSwap($input, $user, $sessionId, $phoneNumber),
            '2' => $this->handleMySwaps($user),
            '0' => "END Thank you for using VouchMorph.",
            default => "END Invalid option."
        };
    }

    /**
     * USSD FLOW
     *
     * 1
     * 1*sourceType
     * 1*sourceType*sourceInstitution
     * 1*sourceType*sourceInstitution*extraField1
     * ...
     */
    private function handleNewSwap(array $input, array $user, string $sessionId, string $phoneNumber): string
    {
        $step = count($input);

        // 1
        if ($step === 1) {
            return "CON Select Source Type\n"
                . "1. E-Wallet\n"
                . "2. Voucher\n"
                . "3. Wallet\n"
                . "4. Bank Account";
        }

        $sourceTypeChoice = $input[1] ?? '';
        $sourceType = self::SOURCE_TYPES[$sourceTypeChoice]['type'] ?? null;

        if (!$sourceType) {
            return "END Invalid source type.";
        }

        $this->setSession($sessionId, 'source_type', $sourceType);
        $this->setSession($sessionId, 'source_phone', $this->formatMsisdnForSwap($phoneNumber));

        // 2
        if ($step === 2) {
            return $this->showInstitutionsMenu($sourceType, "Select Source Institution");
        }

        $sourceInstitution = $this->resolveInstitutionFromChoice($sourceType, $input[2] ?? '');
        if (!$sourceInstitution) {
            return "END Invalid source institution.";
        }

        $this->setSession($sessionId, 'source_institution', $sourceInstitution);

        // Voucher needs voucher number first
        if ($sourceType === 'voucher') {
            if ($step === 3) {
                return "CON Enter voucher number";
            }

            $voucherNumber = trim((string)($input[3] ?? ''));
            if ($voucherNumber === '') {
                return "END Voucher number required.";
            }
            $this->setSession($sessionId, 'voucher_number', $voucherNumber);

            if ($step === 4) {
                return "CON Enter voucher PIN";
            }

            $voucherPin = trim((string)($input[4] ?? ''));
            if ($voucherPin === '') {
                return "END Voucher PIN required.";
            }
            $this->setSession($sessionId, 'voucher_pin', $voucherPin);

            if ($step === 5) {
                return "CON Enter amount";
            }

            $amount = (float)($input[5] ?? 0);
            if ($amount <= 0) {
                return "END Invalid amount.";
            }
            $this->setSession($sessionId, 'amount', (string)$amount);

            if ($step === 6) {
                return "CON Select delivery mode\n1. Cashout\n2. Deposit";
            }

            return $this->continueToDestination($input, 6, $sessionId, $phoneNumber, $sourceType, $amount);
        }

        // Wallet source needs PIN
        if ($sourceType === 'wallet') {
            if ($step === 3) {
                return "CON Enter wallet PIN";
            }

            $walletPin = trim((string)($input[3] ?? ''));
            if ($walletPin === '') {
                return "END Wallet PIN required.";
            }
            $this->setSession($sessionId, 'wallet_pin', $walletPin);

            if ($step === 4) {
                return "CON Enter amount";
            }

            $amount = (float)($input[4] ?? 0);
            if ($amount <= 0) {
                return "END Invalid amount.";
            }
            $this->setSession($sessionId, 'amount', (string)$amount);

            if ($step === 5) {
                return "CON Select delivery mode\n1. Cashout\n2. Deposit";
            }

            return $this->continueToDestination($input, 5, $sessionId, $phoneNumber, $sourceType, $amount);
        }

        // Account source needs account number
        if ($sourceType === 'account') {
            if ($step === 3) {
                return "CON Enter account number";
            }

            $accountNumber = trim((string)($input[3] ?? ''));
            if ($accountNumber === '') {
                return "END Account number required.";
            }
            $this->setSession($sessionId, 'account_number', $accountNumber);

            if ($step === 4) {
                return "CON Enter amount";
            }

            $amount = (float)($input[4] ?? 0);
            if ($amount <= 0) {
                return "END Invalid amount.";
            }
            $this->setSession($sessionId, 'amount', (string)$amount);

            if ($step === 5) {
                return "CON Select delivery mode\n1. Cashout\n2. Deposit";
            }

            return $this->continueToDestination($input, 5, $sessionId, $phoneNumber, $sourceType, $amount);
        }

        // E-wallet source: no extra source field, just amount
        if ($sourceType === 'e-wallet') {
            if ($step === 3) {
                return "CON Enter amount";
            }

            $amount = (float)($input[3] ?? 0);
            if ($amount <= 0) {
                return "END Invalid amount.";
            }
            $this->setSession($sessionId, 'amount', (string)$amount);

            if ($step === 4) {
                return "CON Select delivery mode\n1. Cashout\n2. Deposit";
            }

            return $this->continueToDestination($input, 4, $sessionId, $phoneNumber, $sourceType, $amount);
        }

        return "END Unsupported source type.";
    }

    /**
     * Continue flow after delivery mode.
     * $deliveryStepIndex is the position of delivery mode in the input array.
     */
    private function continueToDestination(
        array $input,
        int $deliveryStepIndex,
        string $sessionId,
        string $phoneNumber,
        string $sourceType,
        float $amount
    ): string {
        $deliveryChoice = $input[$deliveryStepIndex] ?? '';
        $deliveryMode = match ($deliveryChoice) {
            '1' => 'cashout',
            '2' => 'deposit',
            default => null
        };

        if (!$deliveryMode) {
            return "END Invalid delivery mode.";
        }

        $this->setSession($sessionId, 'delivery_mode', $deliveryMode);

        $nextIndex = $deliveryStepIndex + 1;

        if (!isset($input[$nextIndex])) {
            return $this->showDestinationInstitutionMenu($deliveryMode);
        }

        $destinationInstitution = $this->resolveDestinationInstitutionFromChoice($deliveryMode, $input[$nextIndex]);
        if (!$destinationInstitution) {
            return "END Invalid destination institution.";
        }

        $this->setSession($sessionId, 'destination_institution', $destinationInstitution);

        $beneficiaryIndex = $nextIndex + 1;

        if ($deliveryMode === 'cashout') {
            if (!isset($input[$beneficiaryIndex])) {
                return "CON Use this number for cashout?\n1. Yes\n2. No";
            }

            $useSameNumber = $input[$beneficiaryIndex];

            if ($useSameNumber === '1') {
                $beneficiaryPhone = $this->formatMsisdnForSwap($phoneNumber);
                $this->setSession($sessionId, 'beneficiary_phone', $beneficiaryPhone);

                return $this->executeSwapFromSession($sessionId, $sourceType, $amount);
            }

            if ($useSameNumber === '2') {
                $customPhoneIndex = $beneficiaryIndex + 1;

                if (!isset($input[$customPhoneIndex])) {
                    return "CON Enter beneficiary phone";
                }

                $beneficiaryPhone = $this->formatMsisdnForSwap((string)$input[$customPhoneIndex]);
                $this->setSession($sessionId, 'beneficiary_phone', $beneficiaryPhone);

                return $this->executeSwapFromSession($sessionId, $sourceType, $amount);
            }

            return "END Invalid option.";
        }

        // Deposit
        if (!isset($input[$beneficiaryIndex])) {
            return "CON Enter beneficiary account/phone";
        }

        $beneficiaryAccount = trim((string)$input[$beneficiaryIndex]);
        if ($beneficiaryAccount === '') {
            return "END Beneficiary account/phone required.";
        }

        $this->setSession($sessionId, 'beneficiary_account', $beneficiaryAccount);

        return $this->executeSwapFromSession($sessionId, $sourceType, $amount);
    }

    private function executeSwapFromSession(string $sessionId, string $sourceType, float $amount): string
    {
        $sourceInstitution      = (string)$this->getSession($sessionId, 'source_institution');
        $destinationInstitution = (string)$this->getSession($sessionId, 'destination_institution');
        $deliveryMode           = (string)$this->getSession($sessionId, 'delivery_mode');
        $sourcePhone            = (string)$this->getSession($sessionId, 'source_phone');

        $source = [
            'institution' => $sourceInstitution,
            'asset_type'  => $sourceType,
            'amount'      => $amount
        ];

        switch ($sourceType) {
            case 'e-wallet':
                $source['ewallet'] = [
                    'ewallet_phone' => $sourcePhone
                ];
                break;

            case 'wallet':
                $source['wallet'] = [
                    'wallet_phone' => $sourcePhone,
                    'wallet_pin'   => (string)$this->getSession($sessionId, 'wallet_pin')
                ];
                break;

            case 'voucher':
                $source['voucher'] = [
                    'voucher_number' => (string)$this->getSession($sessionId, 'voucher_number'),
                    'claimant_phone' => $sourcePhone,
                    'voucher_pin'    => (string)$this->getSession($sessionId, 'voucher_pin')
                ];
                break;

            case 'account':
                $source['account'] = [
                    'account_number' => (string)$this->getSession($sessionId, 'account_number')
                ];
                break;
        }

        $destination = [
            'institution'   => $destinationInstitution,
            'delivery_mode' => $deliveryMode,
            'amount'        => $amount
        ];

        if ($deliveryMode === 'cashout') {
            $destination['beneficiary_phone'] = (string)$this->getSession($sessionId, 'beneficiary_phone');
        } else {
            $destination['beneficiary_account'] = (string)$this->getSession($sessionId, 'beneficiary_account');
        }

        $payload = [
            'currency'    => 'BWP',
            'source'      => $source,
            'destination' => $destination
        ];

        $this->logUSSD('SWAP_PAYLOAD', $payload);

        try {
            $result = $this->swapService->executeSwap($payload);

            $this->logUSSD('SWAP_RESULT', $result);
            $this->clearSession($sessionId);

            $status = strtolower((string)($result['status'] ?? ''));

            if ($status === 'success') {
                $ref = $result['swap_reference'] ?? $result['reference'] ?? 'N/A';
                $holdRef = $result['hold_reference'] ?? null;

                $message = "END Swap successful\nRef: {$ref}\nAmt: {$amount} BWP";

                if ($holdRef) {
                    $message .= "\nHold: {$holdRef}";
                }

                if (!empty($result['voucher']['code_suffix'])) {
                    $message .= "\nVoucher: " . $result['voucher']['code_suffix'];
                }

                return $message;
            }

            $error = $result['message'] ?? 'Swap failed';
            return "END " . $this->truncateForUssd($error, 140);
        } catch (Exception $e) {
            $this->logUSSD('SWAP_EXCEPTION', ['message' => $e->getMessage()]);
            $this->clearSession($sessionId);
            return "END Swap failed. Please try again.";
        }
    }

    private function handleMySwaps(array $user): string
    {
        $phone = $user['phone'] ?? '';

        $stmt = $this->db->prepare("
            SELECT 
                swap_uuid,
                amount,
                status,
                created_at
            FROM swap_requests
            WHERE metadata->>'source_phone' = ?
               OR metadata->>'beneficiary_phone' = ?
            ORDER BY created_at DESC
            LIMIT 5
        ");

        try {
            $stmt->execute([$phone, $phone]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                return "END No recent swaps found.";
            }

            $lines = ["END Recent Swaps"];
            foreach ($rows as $row) {
                $ref = substr((string)($row['swap_uuid'] ?? ''), 0, 8);
                $amt = $row['amount'] ?? '0';
                $status = strtoupper((string)($row['status'] ?? 'UNKNOWN'));
                $lines[] = "{$ref} {$amt} {$status}";
            }

            return implode("\n", $lines);
        } catch (Exception $e) {
            $this->logUSSD('MY_SWAPS_ERROR', ['message' => $e->getMessage()]);
            return "END Could not load swaps.";
        }
    }

    private function showInstitutionsMenu(string $walletType, string $title): string
    {
        $matching = $this->getParticipantsByWalletType($walletType);

        if (empty($matching)) {
            return "END No institutions available for {$walletType}.";
        }

        $menu = "CON {$title}\n";
        $i = 1;

        foreach ($matching as $participant) {
            $menu .= $i . ". " . $participant['display_name'] . "\n";
            $i++;
        }

        return rtrim($menu);
    }

    private function showDestinationInstitutionMenu(string $deliveryMode): string
    {
        $title = $deliveryMode === 'cashout'
            ? 'Select Cashout Institution'
            : 'Select Deposit Institution';

        $menu = "CON {$title}\n";
        $i = 1;

        foreach ($this->participants as $participant) {
            $menu .= $i . ". " . $participant['display_name'] . "\n";
            $i++;
        }

        return rtrim($menu);
    }

    private function getParticipantsByWalletType(string $walletType): array
    {
        $matches = [];

        foreach ($this->participants as $participant) {
            if (($participant['wallet_type'] ?? '') === $walletType) {
                $matches[] = $participant;
            }
        }

        return array_values($matches);
    }

    private function resolveInstitutionFromChoice(string $walletType, string $choice): ?string
    {
        $matching = $this->getParticipantsByWalletType($walletType);
        $index = ((int)$choice) - 1;

        return $matching[$index]['code'] ?? null;
    }

    private function resolveDestinationInstitutionFromChoice(string $deliveryMode, string $choice): ?string
    {
        $all = array_values($this->participants);
        $index = ((int)$choice) - 1;

        return $all[$index]['code'] ?? null;
    }

    private function setSession(string $sessionId, string $key, mixed $value): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO ussd_sessions (session_id, session_key, session_value)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE session_value = VALUES(session_value)
        ");
        $stmt->execute([$sessionId, $key, (string)$value]);
    }

    private function getSession(string $sessionId, string $key): ?string
    {
        $stmt = $this->db->prepare("
            SELECT session_value
            FROM ussd_sessions
            WHERE session_id = ? AND session_key = ?
        ");
        $stmt->execute([$sessionId, $key]);

        $value = $stmt->fetchColumn();
        return ($value === false) ? null : (string)$value;
    }

    private function clearSession(string $sessionId): void
    {
        $stmt = $this->db->prepare("DELETE FROM ussd_sessions WHERE session_id = ?");
        $stmt->execute([$sessionId]);
    }

    private function cleanPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($phone, '267')) {
            $phone = substr($phone, 3);
        }

        return ltrim($phone, '0');
    }

    private function formatMsisdnForSwap(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '267')) {
            $digits = substr($digits, 3);
        }

        $digits = ltrim($digits, '0');

        return '+267' . $digits;
    }

    private function findOrCreateUserByPhone(string $phone): array
    {
        $clean = $this->cleanPhoneNumber($phone);
        $msisdn = $this->formatMsisdnForSwap($clean);

        // Try exact match on full stored value
        $stmt = $this->db->prepare("
            SELECT id, username, phone
            FROM users
            WHERE phone = ?
            LIMIT 1
        ");
        $stmt->execute([$msisdn]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            return $user;
        }

        // Fallback match for older local-format rows
        $stmt = $this->db->prepare("
            SELECT id, username, phone
            FROM users
            WHERE phone LIKE ?
            LIMIT 1
        ");
        $stmt->execute(['%' . $clean]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            return $user;
        }

        $username = 'ussd_' . $clean;

        $stmt = $this->db->prepare("
            INSERT INTO users (username, phone)
            VALUES (?, ?)
        ");
        $stmt->execute([$username, $msisdn]);

        return [
            'id' => (int)$this->db->lastInsertId(),
            'username' => $username,
            'phone' => $msisdn
        ];
    }

    private function truncateForUssd(string $text, int $max = 140): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return mb_substr($text, 0, $max - 3) . '...';
    }

    private function logUSSD(string $event, array $data): void
    {
        $logEntry = json_encode([
            'timestamp' => date('c'),
            'event' => $event,
            'data' => $data
        ]);

        file_put_contents(self::USSD_LOG, $logEntry . PHP_EOL, FILE_APPEND);
    }
}
