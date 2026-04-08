<?php
declare(strict_types=1);

namespace BUSINESS_LOGIC_LAYER\controllers;

use PDO;
use Throwable;
use RuntimeException;
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
    private array $participantsByWalletType = [];
    private array $flows = [];

    private const USSD_LOG = '/tmp/vouchmorph_ussd.log';
    private const DEFAULT_ROLE_ID = 1;
    private const DEFAULT_CURRENCY = 'BWP';
    private const QUICK_SWAP_LIMIT = 4;

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
        $this->db = method_exists($instance, 'getConnection')
            ? $instance->getConnection()
            : $instance;

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
        $this->participantsByWalletType = [];

        foreach ($this->config['participants'] ?? [] as $key => $participant) {
            if (!isset($participant['type'])) {
                continue;
            }

            $participantInfo = [
                'participant_id'   => $key,
                'participant_name' => $key,
                'code'             => $key,
                'name'             => strtoupper($key),
                'display_name'     => $participant['display_name'] ?? strtoupper($key),
                'type'             => $participant['type'] ?? 'UNKNOWN',
                'category'         => $participant['category'] ?? 'UNKNOWN',
                'provider_code'    => $participant['provider_code'] ?? '',
                'status'           => $participant['status'] ?? 'ACTIVE',
                'capabilities'     => $participant['capabilities'] ?? [],
                'api_url'          => $participant['api_url'] ?? null,
            ];

            $this->participants[] = $participantInfo;

            $walletTypes = $participant['capabilities']['wallet_types'] ?? ['ACCOUNT'];

            foreach ($walletTypes as $type) {
                $typeLower = strtolower((string)$type);

                $normalized = match ($typeLower) {
                    'ewallet', 'e-wallet' => 'e-wallet',
                    'wallet'              => 'wallet',
                    'voucher'             => 'voucher',
                    'card'                => 'card',
                    'bank', 'account'     => 'account',
                    'atm'                 => 'atm',
                    'agent'               => 'agent',
                    default               => $typeLower,
                };

                if (!isset($this->participantsByWalletType[$normalized])) {
                    $this->participantsByWalletType[$normalized] = [];
                }

                $this->participantsByWalletType[$normalized][] = $participantInfo;
            }
        }

        if (isset($this->participantsByWalletType['e-wallet']) || isset($this->participantsByWalletType['ewallet'])) {
            $this->participantsByWalletType['e-wallet'] = array_merge(
                $this->participantsByWalletType['e-wallet'] ?? [],
                $this->participantsByWalletType['ewallet'] ?? []
            );
        }

        foreach (['account', 'wallet', 'e-wallet', 'card', 'atm', 'agent', 'voucher'] as $type) {
            if (!isset($this->participantsByWalletType[$type])) {
                $this->participantsByWalletType[$type] = [];
            }
        }

        $this->logUSSD('PARTICIPANTS_LOADED', [
            'participant_count' => count($this->participants),
            'wallet_type_counts' => array_map('count', $this->participantsByWalletType),
        ]);
    }

    public function handleUSSDRequest(array $request): string
    {
        $sessionId   = trim((string)($request['sessionId'] ?? $request['SESSION_ID'] ?? ''));
        $rawPhone    = (string)($request['phoneNumber'] ?? $request['MSISDN'] ?? '');
        $phoneNumber = $this->cleanPhoneNumber($rawPhone);
        $text        = trim((string)($request['text'] ?? $request['INPUT'] ?? ''));

        $this->logUSSD('INCOMING', [
            'session_id' => $sessionId,
            'raw_phone' => $rawPhone,
            'clean_phone' => $phoneNumber,
            'text' => $text,
        ]);

        if ($sessionId === '' || $phoneNumber === '') {
            return "END Invalid USSD request.";
        }

        $input = ($text === '') ? [] : explode('*', trim($text, '*'));
        $level = count($input);

        try {
            $user = $this->findUserByPhone($phoneNumber);

            if ($text === '') {
                $this->clearSession($sessionId);
                $this->setSession($sessionId, 'msisdn', $this->formatMsisdnForSwap($phoneNumber));
                $this->setSession($sessionId, 'local_phone', $phoneNumber);

                return $this->showMainMenu($user);
            }

            return $this->processMenuLevel($input, $level, $user, $phoneNumber, $sessionId);
        } catch (Throwable $e) {
            $this->logUSSD('HANDLE_USSD_ERROR', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return "END System error. Please try again later.";
        }
    }

    private function showMainMenu(?array $user): string
    {
        if ($user) {
            return "CON Welcome to VouchMorph\n"
                . "1. Quick Swap\n"
                . "2. Custom Swap\n"
                . "3. My Swaps\n"
                . "0. Exit";
        }

        return "CON Welcome to VouchMorph\n"
            . "1. Register\n"
            . "0. Exit";
    }

    private function processMenuLevel(array $input, int $level, ?array $user, string $phoneNumber, string $sessionId): string
    {
        $mainOption = $input[0] ?? '';

        if (!$user) {
            return match ($mainOption) {
                '1' => $this->handleRegistration($input, $level, $phoneNumber, $sessionId),
                '0' => "END Thank you for using VouchMorph.",
                default => "END Please register first."
            };
        }

        return match ($mainOption) {
            '1' => $this->handleQuickSwap($input, $user, $sessionId, $phoneNumber),
            '2' => $this->handleNewSwap($input, $user, $sessionId, $phoneNumber),
            '3' => $this->handleMySwaps($user),
            '0' => "END Thank you for using VouchMorph.",
            default => "END Invalid option."
        };
    }

    private function handleRegistration(array $input, int $level, string $phoneNumber, string $sessionId): string
    {
        if ($level === 1) {
            return "CON Enter username";
        }

        if ($level === 2) {
            $username = trim((string)$input[1]);

            if (!$this->isValidUsername($username)) {
                return "END Username must be 3-20 letters/numbers/_ only.";
            }

            if ($this->usernameExists($username)) {
                return "END Username already taken.";
            }

            $this->setSession($sessionId, 'reg_username', $username);
            return "CON Enter 4-digit PIN";
        }

        if ($level === 3) {
            $pin = trim((string)$input[2]);

            if (!preg_match('/^\d{4}$/', $pin)) {
                return "END PIN must be exactly 4 digits.";
            }

            $this->setSession($sessionId, 'reg_pin', $pin);
            return "CON Confirm 4-digit PIN";
        }

        if ($level === 4) {
            $confirmPin = trim((string)$input[3]);
            $pin = (string)$this->getSession($sessionId, 'reg_pin');
            $username = (string)$this->getSession($sessionId, 'reg_username');

            if (!preg_match('/^\d{4}$/', $confirmPin)) {
                return "END Confirmation PIN must be exactly 4 digits.";
            }

            if ($confirmPin !== $pin) {
                $this->clearSession($sessionId);
                return "END PINs do not match. Please try again.";
            }

            try {
                $user = $this->createUssdUser($phoneNumber, $username, $pin);
            } catch (Throwable $e) {
                $this->logUSSD('REGISTRATION_ERROR', [
                    'message' => $e->getMessage(),
                    'username' => $username,
                    'phone' => $phoneNumber,
                ]);
                $this->clearSession($sessionId);
                return "END Registration failed.";
            }

            $this->clearSession($sessionId);
            $this->logUSSD('REGISTERED', [
                'user_id' => $user['user_id'] ?? null,
                'username' => $user['username'] ?? null,
                'phone' => $user['phone'] ?? null,
            ]);

            return "END Registration successful. Dial again to continue.";
        }

        return "END Invalid registration step.";
    }

    private function handleQuickSwap(array $input, array $user, string $sessionId, string $phoneNumber): string
    {
        $favorites = $this->getQuickSwapFavorites((int)$user['user_id']);

        if (count($input) === 1) {
            if (empty($favorites)) {
                return "END No Quick Swaps saved. Use Custom Swap.";
            }

            $menu = "CON Quick Swap\n";
            $i = 1;

            foreach ($favorites as $favorite) {
                $menu .= $i . ". " . $this->formatFavoriteLabel($favorite) . "\n";
                $i++;
            }

            $menu .= $i . ". Custom Swap";

            return rtrim($menu);
        }

        $choice = (int)($input[1] ?? 0);
        $customOption = count($favorites) + 1;

        if ($choice === $customOption) {
            return "END Use option 2 for Custom Swap.";
        }

        $favorite = $favorites[$choice - 1] ?? null;
        if (!$favorite) {
            return "END Invalid Quick Swap option.";
        }

        $this->setSession($sessionId, 'quick_swap_favorite_id', (string)$favorite['id']);
        $this->setSession($sessionId, 'source_type', (string)$favorite['source_type']);
        $this->setSession($sessionId, 'source_institution', (string)$favorite['source_institution']);
        $this->setSession($sessionId, 'source_phone', $this->formatMsisdnForSwap($phoneNumber));
        $this->setSession($sessionId, 'user_id', (string)$user['user_id']);
        $this->setSession($sessionId, 'delivery_mode', (string)$favorite['destination_mode']);
        $this->setSession($sessionId, 'destination_institution', (string)$favorite['destination_institution']);

        if (!empty($favorite['source_identifier'])) {
            switch ((string)$favorite['source_type']) {
                case 'account':
                    $this->setSession($sessionId, 'account_number', (string)$favorite['source_identifier']);
                    break;
                case 'voucher':
                    $this->setSession($sessionId, 'voucher_number', (string)$favorite['source_identifier']);
                    break;
            }
        }

        if (!empty($favorite['beneficiary'])) {
            if ((string)$favorite['destination_mode'] === 'cashout') {
                $this->setSession($sessionId, 'beneficiary_phone', (string)$favorite['beneficiary']);
            } else {
                $this->setSession($sessionId, 'beneficiary_account', (string)$favorite['beneficiary']);
            }
        }

        if (count($input) === 2) {
            return "CON Enter amount";
        }

        $amount = (float)($input[2] ?? 0);
        if ($amount <= 0) {
            return "END Invalid amount.";
        }
        $this->setSession($sessionId, 'amount', (string)$amount);

        if (count($input) === 3) {
            $summary = $this->buildQuickSwapSummary($favorite, $amount, $phoneNumber);
            return "CON {$summary}\n1. Confirm\n2. Cancel";
        }

        $confirm = $input[3] ?? '';
        if ($confirm !== '1') {
            $this->clearSession($sessionId);
            return "END Quick Swap cancelled.";
        }

        $sourceType = (string)$favorite['source_type'];

        if (in_array($sourceType, ['e-wallet', 'wallet', 'voucher'], true)) {
            if (count($input) === 4) {
                $label = strtoupper((string)$favorite['source_institution']);
                return "CON Enter {$label} PIN";
            }

            $pin = trim((string)($input[4] ?? ''));
            if (!preg_match('/^\d{4}$/', $pin)) {
                return "END PIN must be 4 digits.";
            }

            if ($sourceType === 'e-wallet') {
                $this->setSession($sessionId, 'ewallet_pin', $pin);
            } elseif ($sourceType === 'wallet') {
                $this->setSession($sessionId, 'wallet_pin', $pin);
            } elseif ($sourceType === 'voucher') {
                $this->setSession($sessionId, 'voucher_pin', $pin);
            }
        }

        return $this->executeSwapFromSession($sessionId, $sourceType, $amount);
    }

    private function handleNewSwap(array $input, array $user, string $sessionId, string $phoneNumber): string
    {
        $step = count($input);

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
        $this->setSession($sessionId, 'user_id', (string)($user['user_id'] ?? ''));

        if ($step === 2) {
            return $this->showInstitutionsMenu($sourceType, "Select Source Institution");
        }

        $sourceInstitution = $this->resolveInstitutionFromChoice($sourceType, $input[2] ?? '');
        if (!$sourceInstitution) {
            return "END Invalid source institution.";
        }

        $this->setSession($sessionId, 'source_institution', $sourceInstitution);

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
            if (!preg_match('/^\d{4}$/', $voucherPin)) {
                return "END Voucher PIN must be 4 digits.";
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

        if ($sourceType === 'wallet') {
            if ($step === 3) {
                return "CON Enter wallet PIN";
            }

            $walletPin = trim((string)($input[3] ?? ''));
            if (!preg_match('/^\d{4}$/', $walletPin)) {
                return "END Wallet PIN must be 4 digits.";
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

            $useSameNumber = $input[$beneficiaryIndex] ?? '';

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
        $userId                 = (string)$this->getSession($sessionId, 'user_id');

        $source = [
            'institution' => $sourceInstitution,
            'asset_type'  => $sourceType,
            'amount'      => $amount,
        ];

        switch ($sourceType) {
            case 'e-wallet':
                $source['ewallet'] = [
                    'ewallet_phone' => $sourcePhone,
                ];

                $ewalletPin = $this->getSession($sessionId, 'ewallet_pin');
                if ($ewalletPin !== null && $ewalletPin !== '') {
                    $source['ewallet']['ewallet_pin'] = $ewalletPin;
                }
                break;

            case 'wallet':
                $source['wallet'] = [
                    'wallet_phone' => $sourcePhone,
                    'wallet_pin'   => (string)$this->getSession($sessionId, 'wallet_pin'),
                ];
                break;

            case 'voucher':
                $source['voucher'] = [
                    'voucher_number' => (string)$this->getSession($sessionId, 'voucher_number'),
                    'claimant_phone' => $sourcePhone,
                    'voucher_pin'    => (string)$this->getSession($sessionId, 'voucher_pin'),
                ];
                break;

            case 'account':
                $source['account'] = [
                    'account_number' => (string)$this->getSession($sessionId, 'account_number'),
                ];
                break;
        }

        $destination = [
            'institution'   => $destinationInstitution,
            'delivery_mode' => $deliveryMode,
            'amount'        => $amount,
        ];

        if ($deliveryMode === 'cashout') {
            $destination['beneficiary_phone'] = (string)$this->getSession($sessionId, 'beneficiary_phone');
        } else {
            $destination['beneficiary_account'] = (string)$this->getSession($sessionId, 'beneficiary_account');
        }

        $payload = [
            'currency'    => self::DEFAULT_CURRENCY,
            'source'      => $source,
            'destination' => $destination,
            'metadata'    => [
                'user_id' => $userId,
                'source_phone' => $sourcePhone,
                'beneficiary_phone' => $destination['beneficiary_phone'] ?? null,
                'channel' => 'USSD',
            ],
        ];

        $this->logUSSD('SWAP_PAYLOAD', $this->maskSensitiveData($payload));

        try {
            $result = $this->swapService->executeSwap($payload);
            $this->logUSSD('SWAP_RESULT', $this->maskSensitiveData($result));
            $this->clearSession($sessionId);

            $status = strtolower((string)($result['status'] ?? ''));

            if ($status === 'success') {
                $ref = $result['swap_reference'] ?? $result['reference'] ?? 'N/A';
                $holdRef = $result['hold_reference'] ?? null;

                $this->saveQuickSwapFavoriteFromPayload($payload);

                $message = "END Swap successful\nRef: {$ref}\nAmt: {$amount} BWP";

                if ($holdRef) {
                    $message .= "\nHold: {$holdRef}";
                }

                $voucherSuffix = $this->findVoucherSuffixByReference((string)$ref);
                if ($voucherSuffix !== null) {
                    $message .= "\nCode: {$voucherSuffix}";
                }

                return $this->truncateForUssd($message, 180);
            }

            $error = $result['message'] ?? 'Swap failed';
            return "END " . $this->truncateForUssd($error, 150);
        } catch (Throwable $e) {
            $this->logUSSD('SWAP_EXCEPTION', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->clearSession($sessionId);
            return "END Swap failed. Please try again.";
        }
    }

    private function handleMySwaps(array $user): string
    {
        $phone = (string)($user['phone'] ?? '');
        $userId = (string)($user['user_id'] ?? '');

        try {
            $stmt = $this->db->prepare("
                SELECT
                    swap_uuid,
                    amount,
                    status,
                    created_at,
                    metadata
                FROM swap_requests
                WHERE
                    metadata->>'source_phone' = :phone
                    OR metadata->>'beneficiary_phone' = :phone
                    OR metadata->>'user_id' = :user_id
                ORDER BY created_at DESC
                LIMIT 5
            ");
            $stmt->execute([
                ':phone' => $phone,
                ':user_id' => $userId,
            ]);

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                return "END No recent swaps found.";
            }

            $lines = ["END Recent Swaps"];
            foreach ($rows as $row) {
                $ref = substr((string)($row['swap_uuid'] ?? ''), 0, 8);
                $amt = (string)($row['amount'] ?? '0');
                $status = strtoupper((string)($row['status'] ?? 'UNKNOWN'));
                $lines[] = "{$ref} {$amt} {$status}";
            }

            return $this->truncateForUssd(implode("\n", $lines), 180);
        } catch (Throwable $e) {
            $this->logUSSD('MY_SWAPS_ERROR', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return "END Could not load swaps.";
        }
    }

    private function getQuickSwapFavorites(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    id,
                    user_id,
                    source_type,
                    source_institution,
                    source_identifier,
                    destination_mode,
                    destination_institution,
                    beneficiary,
                    usage_count,
                    last_used_at
                FROM ussd_favorites
                WHERE user_id = ?
                ORDER BY usage_count DESC, last_used_at DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, self::QUICK_SWAP_LIMIT, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $this->logUSSD('GET_FAVORITES_ERROR', [
                'message' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function saveQuickSwapFavoriteFromPayload(array $payload): void
    {
        try {
            $userId = (int)($payload['metadata']['user_id'] ?? 0);
            if ($userId <= 0) {
                return;
            }

            $sourceType = (string)($payload['source']['asset_type'] ?? '');
            $sourceInstitution = (string)($payload['source']['institution'] ?? '');
            $destinationMode = (string)($payload['destination']['delivery_mode'] ?? '');
            $destinationInstitution = (string)($payload['destination']['institution'] ?? '');

            $sourceIdentifier = $this->extractSourceIdentifier($payload['source'] ?? [], $sourceType);
            $beneficiary = $this->extractBeneficiary($payload['destination'] ?? [], $destinationMode);

            $stmt = $this->db->prepare("
                INSERT INTO ussd_favorites (
                    user_id,
                    source_type,
                    source_institution,
                    source_identifier,
                    destination_mode,
                    destination_institution,
                    beneficiary,
                    usage_count,
                    created_at,
                    updated_at,
                    last_used_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ON CONFLICT (user_id, source_type, source_institution, source_identifier, destination_mode, destination_institution, beneficiary)
                DO UPDATE SET
                    usage_count = ussd_favorites.usage_count + 1,
                    updated_at = CURRENT_TIMESTAMP,
                    last_used_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([
                $userId,
                $sourceType,
                $sourceInstitution,
                $sourceIdentifier,
                $destinationMode,
                $destinationInstitution,
                $beneficiary,
            ]);
        } catch (Throwable $e) {
            $this->logUSSD('SAVE_FAVORITE_ERROR', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function extractSourceIdentifier(array $source, string $sourceType): string
    {
        return match ($sourceType) {
            'account'  => (string)($source['account']['account_number'] ?? ''),
            'voucher'  => (string)($source['voucher']['voucher_number'] ?? ''),
            'wallet'   => (string)($source['wallet']['wallet_phone'] ?? ''),
            'e-wallet' => (string)($source['ewallet']['ewallet_phone'] ?? ''),
            default    => '',
        };
    }

    private function extractBeneficiary(array $destination, string $deliveryMode): string
    {
        return $deliveryMode === 'cashout'
            ? (string)($destination['beneficiary_phone'] ?? '')
            : (string)($destination['beneficiary_account'] ?? '');
    }

    private function formatFavoriteLabel(array $favorite): string
    {
        $inst = (string)($favorite['source_institution'] ?? '');
        $type = (string)($favorite['source_type'] ?? '');
        return strtoupper($inst) . ' ' . ucfirst($type);
    }

    private function buildQuickSwapSummary(array $favorite, float $amount, string $phoneNumber): string
    {
        $source = strtoupper((string)$favorite['source_institution']) . ' ' . ucfirst((string)$favorite['source_type']);
        $destinationMode = strtoupper((string)$favorite['destination_mode']);
        $beneficiary = (string)($favorite['beneficiary'] ?? '');

        if ($beneficiary === '') {
            $beneficiary = $this->formatMsisdnForSwap($phoneNumber);
        }

        return $this->truncateForUssd(
            "Send BWP {$amount} from {$source} to {$destinationMode} {$beneficiary}",
            120
        );
    }

    private function showInstitutionsMenu(string $walletType, string $title): string
    {
        $matching = $this->getParticipantsByWalletType($walletType);

        if (empty($matching)) {
            $this->logUSSD('NO_INSTITUTIONS_FOR_TYPE', [
                'wallet_type' => $walletType,
                'available_types' => array_keys($this->participantsByWalletType),
            ]);
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

        foreach (array_values($this->participants) as $participant) {
            $menu .= $i . ". " . $participant['display_name'] . "\n";
            $i++;
        }

        return rtrim($menu);
    }

    private function getParticipantsByWalletType(string $walletType): array
    {
        return $this->participantsByWalletType[$walletType] ?? [];
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

    private function findUserByPhone(string $phone): ?array
    {
        $clean = $this->cleanPhoneNumber($phone);
        $msisdn = $this->formatMsisdnForSwap($clean);

        $stmt = $this->db->prepare("
            SELECT user_id, username, email, phone, role_id
            FROM users
            WHERE phone = ?
               OR phone = ?
               OR phone = ?
               OR phone LIKE ?
            LIMIT 1
        ");
        $stmt->execute([
            $msisdn,
            '267' . $clean,
            $clean,
            '%' . $clean
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function createUssdUser(string $phone, string $username, string $pin): array
    {
        $clean = $this->cleanPhoneNumber($phone);
        $msisdn = $this->formatMsisdnForSwap($clean);

        if ($this->findUserByPhone($phone)) {
            throw new RuntimeException('Phone number already registered.');
        }

        if ($this->usernameExists($username)) {
            throw new RuntimeException('Username already taken.');
        }

        $email = 'ussd_' . strtolower($username) . '_' . time() . '_' . $clean . '@ussd.vouchmorph.local';
        $passwordHash = password_hash($pin, PASSWORD_BCRYPT);

        try {
            $stmt = $this->db->prepare("
                INSERT INTO users (
                    username,
                    email,
                    phone,
                    password_hash,
                    role_id,
                    verified,
                    kyc_verified,
                    aml_score,
                    mfa_enabled,
                    created_at,
                    updated_at
                )
                VALUES (?, ?, ?, ?, ?, false, false, 0.00, false, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                RETURNING user_id, username, email, phone, role_id
            ");
            $stmt->execute([
                $username,
                $email,
                $msisdn,
                $passwordHash,
                self::DEFAULT_ROLE_ID
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new RuntimeException('Insert succeeded but no user returned.');
            }

            return $user;
        } catch (Throwable $e) {
            $this->logUSSD('CREATE_USER_SQL_ERROR', [
                'message' => $e->getMessage(),
                'username' => $username,
                'email' => $email,
                'phone' => $msisdn,
                'role_id' => self::DEFAULT_ROLE_ID,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function usernameExists(string $username): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1
            FROM users
            WHERE LOWER(username) = LOWER(?)
            LIMIT 1
        ");
        $stmt->execute([$username]);

        return (bool)$stmt->fetchColumn();
    }

    private function isValidUsername(string $username): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_]{3,20}$/', $username);
    }

    private function findVoucherSuffixByReference(string $swapReference): ?string
    {
        if ($swapReference === '' || $swapReference === 'N/A') {
            return null;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT sv.code_suffix
                FROM swap_vouchers sv
                INNER JOIN swap_requests sr ON sr.swap_id = sv.swap_id
                WHERE sr.swap_uuid::text = ?
                ORDER BY sv.created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$swapReference]);
            $value = $stmt->fetchColumn();

            return ($value === false || $value === null) ? null : (string)$value;
        } catch (Throwable $e) {
            $this->logUSSD('VOUCHER_LOOKUP_ERROR', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    private function setSession(string $sessionId, string $key, mixed $value): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO ussd_sessions (session_id, session_key, session_value, updated_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT (session_id, session_key)
            DO UPDATE SET
                session_value = EXCLUDED.session_value,
                updated_at = CURRENT_TIMESTAMP
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
        $stmt = $this->db->prepare("
            DELETE FROM ussd_sessions
            WHERE session_id = ?
        ");
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

    private function truncateForUssd(string $text, int $max = 160): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 3) . '...';
    }

    private function maskSensitiveData(array $data): array
    {
        $json = json_encode($data);
        if ($json === false) {
            return $data;
        }

        $masked = preg_replace('/"wallet_pin"\s*:\s*"[^"]*"/', '"wallet_pin":"****"', $json);
        $masked = preg_replace('/"voucher_pin"\s*:\s*"[^"]*"/', '"voucher_pin":"****"', $masked ?? '');
        $masked = preg_replace('/"ewallet_pin"\s*:\s*"[^"]*"/', '"ewallet_pin":"****"', $masked ?? '');

        $decoded = json_decode($masked ?? $json, true);
        return is_array($decoded) ? $decoded : $data;
    }

    private function logUSSD(string $event, array $data): void
    {
        $logEntry = json_encode([
            'timestamp' => date('c'),
            'event' => $event,
            'data' => $data,
        ]);

        @file_put_contents(self::USSD_LOG, $logEntry . PHP_EOL, FILE_APPEND);
        error_log('[USSD] ' . $logEntry);
    }
}
