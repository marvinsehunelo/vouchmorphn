<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// cron/process_expired.php
// Run this every 5-10 minutes via cron: */5 * * * * php /path/to/cron/process_expired.php

require_once __DIR__ . '/../bootstrap.php';

use BUSINESS_LOGIC_LAYER\services\SwapService;

class ExpiryProcessor
{
    private PDO $db;
    private array $participants;
    
    public function __construct(PDO $db, array $participants)
    {
        $this->db = $db;
        $this->participants = $participants;
    }
    
    public function processExpiredHolds(): array
    {
        $stats = ['processed' => 0, 'failed' => 0, 'errors' => []];
        
        // Find expired holds that are still ACTIVE
        $expired = $this->db->prepare("
            SELECT ht.*, sr.swap_uuid, p.config as participant_config
            FROM hold_transactions ht
            JOIN swap_requests sr ON ht.swap_reference = sr.swap_uuid
            LEFT JOIN participants p ON ht.participant_id = p.participant_id
            WHERE ht.status = 'ACTIVE' 
            AND ht.hold_expiry < NOW()
            FOR UPDATE SKIP LOCKED
        ");
        $expired->execute();
        
        while ($hold = $expired->fetch(PDO::FETCH_ASSOC)) {
            try {
                $this->db->beginTransaction();
                
                error_log("[EXPIRY] Processing expired hold: {$hold['hold_reference']}");
                
                // 1. Release the hold at the bank (if we have participant config)
                if ($hold['participant_config']) {
                    $participant = json_decode($hold['participant_config'], true);
                    $bankClient = new GenericBankClient($participant);
                    
                    $result = $bankClient->releaseHold([
                        'hold_reference' => $hold['hold_reference'],
                        'reason' => 'Hold expired after 24 hours'
                    ]);
                    
                    if (!isset($result['success']) || $result['success'] !== true) {
                        throw new Exception("Bank release failed: " . json_encode($result));
                    }
                }
                
                // 2. Update hold status to EXPIRED
                $updateHold = $this->db->prepare("
                    UPDATE hold_transactions 
                    SET status = 'EXPIRED', 
                        released_at = NOW(),
                        metadata = jsonb_set(
                            COALESCE(metadata, '{}'::jsonb),
                            '{expiry_reason}',
                            '"Auto-expired after 24 hours"'
                        )
                    WHERE hold_reference = ?
                ");
                $updateHold->execute([$hold['hold_reference']]);
                
                // 3. Update swap request status
                $updateSwap = $this->db->prepare("
                    UPDATE swap_requests 
                    SET status = 'expired',
                        metadata = jsonb_set(
                            COALESCE(metadata, '{}'::jsonb),
                            '{expired_at}',
                            to_jsonb(NOW())
                        )
                    WHERE swap_uuid = ?
                ");
                $updateSwap->execute([$hold['swap_reference']]);
                
                // 4. Void any associated vouchers
                $voidVoucher = $this->db->prepare("
                    UPDATE swap_vouchers 
                    SET status = 'EXPIRED', 
                        voided_at = NOW(),
                        void_reason = 'Associated hold expired'
                    WHERE swap_id = (SELECT swap_id FROM swap_requests WHERE swap_uuid = ?)
                    AND status = 'ACTIVE'
                ");
                $voidVoucher->execute([$hold['swap_reference']]);
                
                // 5. Remove from settlement queue if any
                $removeSettlement = $this->db->prepare("
                    DELETE FROM settlement_queue 
                    WHERE hold_reference = ? AND status = 'PENDING'
                ");
                $removeSettlement->execute([$hold['hold_reference']]);
                
                $this->db->commit();
                $stats['processed']++;
                
                error_log("[EXPIRY] Successfully processed hold: {$hold['hold_reference']}");
                
            } catch (Exception $e) {
                $this->db->rollBack();
                $stats['failed']++;
                $stats['errors'][] = [
                    'hold' => $hold['hold_reference'],
                    'error' => $e->getMessage()
                ];
                error_log("[EXPIRY] FAILED for hold {$hold['hold_reference']}: " . $e->getMessage());
            }
        }
        
        return $stats;
    }
    
    public function processExpiredVouchers(): array
    {
        $stats = ['processed' => 0];
        
        // Find expired vouchers that are still ACTIVE
        $expired = $this->db->prepare("
            UPDATE swap_vouchers 
            SET status = 'EXPIRED',
                metadata = jsonb_set(
                    COALESCE(metadata, '{}'::jsonb),
                    '{expired_at}',
                    to_jsonb(NOW())
                )
            WHERE expiry_at < NOW() 
            AND status = 'ACTIVE'
            RETURNING voucher_id
        ");
        $expired->execute();
        
        $stats['processed'] = $expired->rowCount();
        
        return $stats;
    }
}

// Execute
$processor = new ExpiryProcessor($db, $participants);
$holdStats = $processor->processExpiredHolds();
$voucherStats = $processor->processExpiredVouchers();

echo json_encode([
    'holds_processed' => $holdStats,
    'vouchers_expired' => $voucherStats,
    'timestamp' => date('Y-m-d H:i:s')
]);
