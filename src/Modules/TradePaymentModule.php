<?php
declare(strict_types=1);

namespace Modules;

use PDO;
use Domain\Services\SwapService;

/**
 * TradePaymentModule - Generic config-driven trade payments
 * 
 * NO HARDCODED TRADE TYPES.
 * 
 * All trade types (minerals, agriculture, oil, etc.) are defined in config files.
 * Add a new trade type = add a JSON file, no code changes.
 */
class TradePaymentModule
{
    private PDO $db;
    private SwapService $swapService;
    private array $tradeTemplates = [];
    private string $templatePath;
    
    public function __construct(
        PDO $db,
        SwapService $swapService,
        string $templatePath = __DIR__ . '/../../config/trade_templates/'
    ) {
        $this->db = $db;
        $this->swapService = $swapService;
        $this->templatePath = rtrim($templatePath, '/') . '/';
        $this->loadTemplates();
        $this->ensureTablesExist();
    }
    
    private function ensureTablesExist(): void
    {
        // Add trade columns to swap_requests (handled by migration)
        // trade_metadata JSONB column already added
    }
    
    /**
     * Load all trade templates from config directory
     */
    private function loadTemplates(): void
    {
        if (!is_dir($this->templatePath)) {
            mkdir($this->templatePath, 0755, true);
            return;
        }
        
        foreach (glob($this->templatePath . '*.json') as $file) {
            $content = file_get_contents($file);
            $template = json_decode($content, true);
            
            if ($template && isset($template['trade_type'])) {
                $this->tradeTemplates[$template['trade_type']] = $template;
            }
        }
        
        // Ensure at least minerals template exists
        if (empty($this->tradeTemplates)) {
            $this->createDefaultTemplates();
        }
    }
    
    /**
     * Create default templates if none exist
     */
    private function createDefaultTemplates(): void
    {
        $minerals = [
            'trade_type' => 'minerals',
            'version' => '1.0',
            'required_documents' => ['invoice', 'certificate_of_origin', 'export_permit'],
            'optional_documents' => ['assay_report', 'transport_manifest'],
            'settlement_flow' => 'hold_then_release',
            'compliance_level' => 'HIGH',
            'fx_required' => true,
            'min_amount' => 1000,
            'max_amount' => 10000000
        ];
        
        $agriculture = [
            'trade_type' => 'agriculture',
            'version' => '1.0',
            'required_documents' => ['invoice', 'phytosanitary_certificate'],
            'optional_documents' => ['quality_certificate'],
            'settlement_flow' => 'escrow',
            'compliance_level' => 'MEDIUM',
            'fx_required' => false,
            'min_amount' => 100,
            'max_amount' => 500000
        ];
        
        file_put_contents($this->templatePath . 'minerals.json', json_encode($minerals, JSON_PRETTY_PRINT));
        file_put_contents($this->templatePath . 'agriculture.json', json_encode($agriculture, JSON_PRETTY_PRINT));
        
        $this->tradeTemplates['minerals'] = $minerals;
        $this->tradeTemplates['agriculture'] = $agriculture;
    }
    
    /**
     * Get a trade template by type
     */
    public function getTradeTemplate(string $tradeType): ?array
    {
        return $this->tradeTemplates[$tradeType] ?? null;
    }
    
    /**
     * Get all available trade types
     */
    public function getAvailableTradeTypes(): array
    {
        return array_keys($this->tradeTemplates);
    }
    
    /**
     * Validate trade payload against template
     */
    public function validateTradePayload(string $tradeType, array $payload): array
    {
        $template = $this->getTradeTemplate($tradeType);
        
        if (!$template) {
            throw new \RuntimeException("Unknown trade type: {$tradeType}");
        }
        
        $errors = [];
        
        // Check required documents
        foreach ($template['required_documents'] as $doc) {
            if (empty($payload['documents'][$doc])) {
                $errors[] = "Missing required document: {$doc}";
            }
        }
        
        // Check amount range
        $amount = $payload['amount'] ?? 0;
        if ($amount < ($template['min_amount'] ?? 0)) {
            $errors[] = "Amount {$amount} is below minimum {$template['min_amount']}";
        }
        
        if ($amount > ($template['max_amount'] ?? PHP_FLOAT_MAX)) {
            $errors[] = "Amount {$amount} exceeds maximum {$template['max_amount']}";
        }
        
        // Check FX requirement
        if (($template['fx_required'] ?? false) && empty($payload['fx_info'])) {
            $errors[] = "FX info required for this trade type";
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'template' => $template
        ];
    }
    
    /**
     * Initiate a trade payment
     */
    public function initiateTradePayment(array $payload): array
    {
        $tradeType = $payload['trade_type'];
        
        // Validate against template
        $validation = $this->validateTradePayload($tradeType, $payload);
        
        if (!$validation['valid']) {
            return [
                'status' => 'error',
                'errors' => $validation['errors']
            ];
        }
        
        $template = $validation['template'];
        
        // Build swap payload
        $swapPayload = [
            'source' => $payload['buyer'],
            'destination' => $payload['seller'],
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'trade_type' => $tradeType,
            'documents' => $payload['documents'],
            'metadata' => [
                'trade_type' => $tradeType,
                'settlement_flow' => $template['settlement_flow'],
                'compliance_level' => $template['compliance_level'],
                'documents_provided' => array_keys($payload['documents']),
                'initiated_at' => date('c')
            ]
        ];
        
        // Add FX info if provided
        if (isset($payload['fx_info'])) {
            $swapPayload['fx_info'] = $payload['fx_info'];
        }
        
        // Store trade metadata
        $swapPayload['metadata']['trade_metadata'] = [
            'trade_type' => $tradeType,
            'required_documents' => $template['required_documents'],
            'optional_documents' => $template['optional_documents'] ?? []
        ];
        
        // Execute the swap
        $result = $this->swapService->executeSwap($swapPayload);
        
        // Record trade payment in tracking table
        if ($result['status'] === 'success') {
            $this->recordTradePayment($payload, $result, $template);
        }
        
        return $result;
    }
    
    /**
     * Record trade payment for audit/reporting
     */
    private function recordTradePayment(array $payload, array $swapResult, array $template): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO trade_payments 
            (swap_reference, trade_type, buyer_institution, seller_institution,
             amount, currency, documents, settlement_flow, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, 'COMPLETED', NOW())
        ");
        
        $stmt->execute([
            $swapResult['swap_reference'],
            $payload['trade_type'],
            $payload['buyer']['institution'],
            $payload['seller']['institution'],
            $payload['amount'],
            $payload['currency'],
            json_encode($payload['documents']),
            $template['settlement_flow']
        ]);
    }
    
    /**
     * Get trade payment by swap reference
     */
    public function getTradePayment(string $swapRef): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM trade_payments WHERE swap_reference = ?
        ");
        $stmt->execute([$swapRef]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($payment) {
            $payment['documents'] = json_decode($payment['documents'], true);
        }
        
        return $payment ?: null;
    }
    
    /**
     * Get all trade payments for a buyer or seller
     */
    public function getTradePaymentsForParticipant(string $institution, string $role = 'buyer'): array
    {
        $column = ($role === 'buyer') ? 'buyer_institution' : 'seller_institution';
        
        $stmt = $this->db->prepare("
            SELECT * FROM trade_payments WHERE {$column} = ? ORDER BY created_at DESC
        ");
        $stmt->execute([$institution]);
        
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($payments as &$payment) {
            $payment['documents'] = json_decode($payment['documents'], true);
        }
        
        return $payments;
    }
}
