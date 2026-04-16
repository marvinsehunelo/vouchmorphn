<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

declare(strict_types=1);

namespace BUSINESS_LOGIC_LAYER\Services;

use PDO;
use Throwable;

class ExpiredSwapsService
{
    private PDO $swapDB;
    private array $banksDB;
    private array $participants;
    private float $creationFee = 10.0;

    public function __construct(PDO $swapDB, array $banksDB, array $participants)
    {
        $this->swapDB = $swapDB;
        $this->banksDB = array_change_key_case($banksDB, CASE_LOWER);
        $this->participants = array_change_key_case($participants, CASE_LOWER);
    }

    public function processExpiredSwaps(): array
    {
        $totalProcessed = 0;
        $banksProcessed = 0;
        $reportLines = [];

        foreach ($this->participants as $bankName => $participant) {
            if (($participant['type'] ?? '') !== 'bank') continue;

            $b = strtolower($bankName);
            if (!isset($this->banksDB[$b])) {
                $reportLines[] = "[WARN] No DB connection for $bankName";
                continue;
            }

            if (empty($participant['swap_table']) || empty($participant['expire_column']) || empty($participant['enabled_column'])) {
                $reportLines[] = "[SKIP] {$bankName} missing swap table configuration.";
                continue;
            }

            $db = $this->banksDB[$b];
            $table = $participant['swap_table'];
            $expire = $participant['expire_column'];
            $enabled = $participant['enabled_column'];

            try {
                // Fetch expired swaps
                $stmtExpired = $db->prepare("
                    SELECT * FROM `{$table}`
                    WHERE `{$enabled}` = 1
                    AND `{$expire}` < NOW()
                    FOR UPDATE
                ");
                $stmtExpired->execute();
                $expiredSwaps = $stmtExpired->fetchAll(PDO::FETCH_ASSOC);

                if (!$expiredSwaps) {
                    $reportLines[] = "[INFO] {$bankName}: No expired swaps found.";
                    continue;
                }

                $reportLines[] = "------------------------------\n[BANK] {$bankName}\n[FOUND] " . count($expiredSwaps) . " expired swaps to process.";

                $bankShare = round($this->creationFee * 0.6, 6);
                $midShare  = round($this->creationFee * 0.4, 6);

                // Ledger & audit statements
                $stmtLedger = $this->swapDB->prepare("
                    INSERT INTO swap_ledgers (
                        swap_reference, from_participant, to_participant, from_type, to_type,
                        from_account, to_account, original_amount, final_amount, currency_code,
                        swap_fee, creation_fee, admin_fee, sms_fee, token,
                        status, reverse_logic, performed_by, notes, created_at, updated_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");

                $stmtAudit = $this->swapDB->prepare("
                    INSERT INTO audit_logs
                        (entity, entity_id, action, category, severity, old_value, new_value, performed_by, ip_address, user_agent, geo_location, performed_at, immutable)
                    VALUES (?, ?, ?, 'system', 'info', ?, ?, 1, '127.0.0.1', 'system-service', 'N/A', NOW(), 1)
                ");

                foreach ($expiredSwaps as $swap) {
                    $swapId  = $swap[$participant['primary_key'] ?? 'id'] ?? null;

                    // ✅ Generate a unique swap reference per expired swap
                    $swapRef = $swap['reference'] ?? 'EXPIRED-' . strtoupper($bankName) . '-' . $swapId . '-' . time();
                    $bankRef = $swapRef . '-BANK';
                    $midRef  = $swapRef . '-MID';

                    try {
                        $this->swapDB->beginTransaction();
                        $db->beginTransaction();

                        // --- Fetch accounts dynamically by account_type ---
                        $stmtAcc = $db->prepare("
                            SELECT account_id, account_number, balance 
                            FROM accounts 
                            WHERE account_type = ? 
                            FOR UPDATE
                        ");

                        // 1️⃣ Middleman escrow
                        $stmtAcc->execute(['middleman_escrow']);
                        $escrow = $stmtAcc->fetch(PDO::FETCH_ASSOC);
                        if (!$escrow || $escrow['balance'] < $this->creationFee) {
                            throw new \Exception("Insufficient escrow balance to collect fee ({$this->creationFee})");
                        }

                        // 2️⃣ Partner bank settlement
                        $stmtAcc->execute(['partner_bank_settlement']);
                        $bankAccount = $stmtAcc->fetch(PDO::FETCH_ASSOC);
                        if (!$bankAccount) throw new \Exception("Partner bank settlement account not found");

                        // 3️⃣ Middleman revenue
                        $stmtAcc->execute(['middleman_revenue']);
                        $revAccount = $stmtAcc->fetch(PDO::FETCH_ASSOC);
                        if (!$revAccount) throw new \Exception("Middleman revenue account not found");

                        // --- Update balances ---
                        $stmtUpdateAcc = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?");
                        $stmtUpdateAcc->execute([-1 * $this->creationFee, $escrow['account_id']]);
                        $stmtUpdateAcc->execute([$bankShare, $bankAccount['account_id']]);
                        $stmtUpdateAcc->execute([$midShare, $revAccount['account_id']]);

                        // --- Ledger entries ---
                        $stmtLedger->execute([
                            $bankRef,
                            $bankName,
                            $bankName,
                            'system_escrow',
                            'system_revenue',
                            $escrow['account_number'],
                            $bankAccount['account_number'],
                            $this->creationFee,
                            $bankShare,
                            'BWP',
                            0.0,
                            $this->creationFee,
                            0.0,
                            0.0,
                            null,
                            'COMPLETED',
                            1,
                            1,
                            'Expired swap fee collection - bank share'
                        ]);

                        $stmtLedger->execute([
                            $midRef,
                            $bankName,
                            $bankName,
                            'system_escrow',
                            'system_revenue',
                            $escrow['account_number'],
                            $revAccount['account_number'],
                            $this->creationFee,
                            $midShare,
                            'BWP',
                            0.0,
                            $this->creationFee,
                            0.0,
                            0.0,
                            null,
                            'COMPLETED',
                            1,
                            1,
                            'Expired swap fee collection - middleman revenue'
                        ]);

                        // Disable swap in bank DB
                        $stmtUpdateSwap = $db->prepare("UPDATE `{$table}` SET `{$enabled}` = 0 WHERE `{$participant['primary_key']}` = ?");
                        $stmtUpdateSwap->execute([$swapId]);

                        // Audit log
                        $stmtAudit->execute([
                            'swap',
                            $swapId,
                            'expire_swap',
                            json_encode(['swap_enabled' => 1]),
                            json_encode(['swap_enabled' => 0])
                        ]);

                        $this->swapDB->commit();
                        $db->commit();
                        $totalProcessed++;

                    } catch (Throwable $ex) {
                        if ($this->swapDB->inTransaction()) $this->swapDB->rollBack();
                        if ($db->inTransaction()) $db->rollBack();
                        $reportLines[] = "[ERROR] Bank {$bankName} failed processing swap PK={$swapId}: " . $ex->getMessage();
                    }
                }

                $banksProcessed++;

            } catch (Throwable $e) {
                $reportLines[] = "[ERROR] {$bankName} failed: " . $e->getMessage();
            }
        }

        $reportLines[] = "[INFO] Expired swaps processing complete. Banks processed: {$banksProcessed}, Total swaps updated: {$totalProcessed}";

        return [
            'banksProcessed' => $banksProcessed,
            'totalProcessed' => $totalProcessed,
            'report' => implode("\n", $reportLines)
        ];
    }
}

