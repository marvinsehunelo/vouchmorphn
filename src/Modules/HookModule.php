<?php
declare(strict_types=1);

namespace Modules;

use PDO;
use Domain\Services\FeeService;

/**
 * HookModule - Aggregate multiple assets from a user
 * 
 * Allows hooking multiple assets (accounts, wallets, e-wallets, cards, vouchers)
 * and swapping them as a bundle to one destination
 * 
 * Example: 9 Pula in Account A + 4 Pula in Wallet B + 8 Pula in E-Wallet C = 21 Pula total
 * Micro fees: 0.5% per small balance (min 0.50, max 5.00)
 */
class HookModule
{
    private PDO $db;
    private FeeService $feeService;
    
    public function __construct(PDO $db, FeeService $feeService)
    {
        $this->db = $db;
        $this->feeService = $feeService;
        $this->ensureTablesExist();
    }
    
    private function ensureTablesExist(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS user_hooks (
                id BIGSERIAL PRIMARY KEY,
                user_identifier VARCHAR(100) NOT NULL,
                hook_name VARCHAR(50) NOT NULL,
                asset_type VARCHAR(30) NOT NULL,
                institution VARCHAR(100) NOT NULL,
                asset_reference VARCHAR(100) NOT NULL,
                credentials JSONB DEFAULT '{}'::jsonb,
                is_active BOOLEAN DEFAULT TRUE,
                priority INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW(),
                UNIQUE(user_identifier, hook_name)
            )
        ");
        
        $this->db->exec("
            CREATE INDEX IF NOT EXISTS idx_user_hooks_user ON user_hooks(user_identifier);
            CREATE INDEX IF NOT EXISTS idx_user_hooks_active ON user_hooks(user_identifier, is_active);
        ");
    }
    
    /**
     * Add a new hook for a user
     */
    public function addHook(
        string $userId,
        string $hookName,
        string $assetType,
        string $institution,
        string $reference,
        array $credentials = [],
        int $priority = 0
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO user_hooks 
            (user_identifier, hook_name, asset_type, institution, asset_reference, credentials, priority)
            VALUES (?, ?, ?, ?, ?, ?::jsonb, ?)
            ON CONFLICT (user_identifier, hook_name) DO UPDATE SET
                asset_type = EXCLUDED.asset_type,
                institution = EXCLUDED.institution,
                asset_reference = EXCLUDED.asset_reference,
                credentials = EXCLUDED.credentials,
                priority = EXCLUDED.priority,
                is_active = TRUE,
                updated_at = NOW()
        ");
        $stmt->execute([$userId, $hookName, $assetType, $institution, $reference, json_encode($credentials), $priority]);
    }
    
    /**
     * Remove a hook (soft delete)
     */
    public function removeHook(string $userId, string $hookName): void
    {
        $stmt = $this->db->prepare("
            UPDATE user_hooks 
            SET is_active = FALSE, updated_at = NOW()
            WHERE user_identifier = ? AND hook_name = ?
        ");
        $stmt->execute([$userId, $hookName]);
    }
    
    /**
     * Get all active hooks for a user
     */
    public function getUserHooks(string $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM user_hooks 
            WHERE user_identifier = ? AND is_active = TRUE
            ORDER BY priority DESC, created_at ASC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get balances for all hooks (with real-time API calls)
     */
    public function getHookedBalances(string $userId): array
    {
        $hooks = $this->getUserHooks($userId);
        $balances = [];
        $totalBalance = 0;
        $totalMicroFees = 0;
        
        foreach ($hooks as $hook) {
            // Get real balance from institution API
            $balance = $this->fetchBalanceFromInstitution($hook);
            $microFee = $this->feeService->getMicroFee($balance);
            
            $balances[] = [
                'hook_name' => $hook['hook_name'],
                'asset_type' => $hook['asset_type'],
                'institution' => $hook['institution'],
                'reference' => $this->maskReference($hook['asset_reference']),
                'balance' => $balance,
                'micro_fee' => $microFee,
                'currency' => $this->getCurrencyForAsset($hook)
            ];
            
            $totalBalance += $balance;
            $totalMicroFees += $microFee;
        }
        
        $netAmount = $totalBalance - $totalMicroFees;
        
        return [
            'user_id' => $userId,
            'total_balance' => round($totalBalance, 2),
            'total_micro_fees' => round($totalMicroFees, 2),
            'net_amount' => round($netAmount, 2),
            'hook_count' => count($hooks),
            'currency' => 'BWP', // Default, should be determined
            'hooks' => $balances,
            'as_at' => date('c')
        ];
    }
    
    /**
     * Prepare a swap payload using all hooked assets
     */
    public function prepareAggregatedSwap(string $userId, array $destination): array
    {
        $balances = $this->getHookedBalances($userId);
        
        if ($balances['hook_count'] == 0) {
            throw new \RuntimeException("No active hooks found for user: {$userId}");
        }
        
        if ($balances['net_amount'] <= 0) {
            throw new \RuntimeException("Net amount after micro fees is non-positive: {$balances['net_amount']}");
        }
        
        // Build source payload with all hooks
        $sourceHooks = [];
        foreach ($balances['hooks'] as $hook) {
            $sourceHooks[] = [
                'hook_name' => $hook['hook_name'],
                'asset_type' => $hook['asset_type'],
                'institution' => $hook['institution'],
                'amount' => $hook['balance'],
                'micro_fee' => $hook['micro_fee']
            ];
        }
        
        return [
            'source' => [
                'type' => 'HOOKED_ASSETS',
                'user_id' => $userId,
                'total_balance' => $balances['total_balance'],
                'total_micro_fees' => $balances['total_micro_fees'],
                'net_amount' => $balances['net_amount'],
                'hooks' => $sourceHooks
            ],
            'destination' => $destination,
            'metadata' => [
                'is_hook_aggregation' => true,
                'hook_count' => $balances['hook_count'],
                'aggregated_at' => date('c')
            ]
        ];
    }
    
    /**
     * Simulate or actually fetch balance from institution
     * In production, replace with actual API call to each institution
     */
    private function fetchBalanceFromInstitution(array $hook): float
    {
        // DEMO ONLY - in production, call institution API using credentials
        // For demo, return mock balances based on asset type
        
        $mockBalances = [
            'ACCOUNT' => 9.00,
            'WALLET' => 4.00,
            'E-WALLET' => 8.00,
            'CARD' => 15.00,
            'VOUCHER' => 25.00
        ];
        
        // In production, you would do:
        // $client = new GenericBankClient($hook['institution'], $hook['credentials']);
        // return $client->getBalance($hook['asset_reference']);
        
        return $mockBalances[$hook['asset_type']] ?? 0;
    }
    
    private function getCurrencyForAsset(array $hook): string
    {
        // In production, fetch from institution or participant config
        return 'BWP';
    }
    
    private function maskReference(string $reference): string
    {
        if (strlen($reference) <= 4) {
            return '****';
        }
        return '...' . substr($reference, -4);
    }
}
