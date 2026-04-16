<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

declare(strict_types=1);

namespace BUSINESS_LOGIC_LAYER\models;

use PDO;
use Exception;

class SwapTransaction
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Record a swap transaction
     * @param array $data
     *  Required keys: swap_reference, sender_legal_name, sender_id_number, receiver_legal_name,
     *                 from_institution, to_institution, from_account_mask, to_account_mask,
     *                 amount, currency_code, direction
     *  Optional keys: sat_purchased, exchange_rate, payment_purpose_code,
     *                 sca_required, sca_method, kyc_verified, aml_screening_ref, notes
     */
    public function record(array $data): int
    {
        // 1️⃣ Validation
        $required = [
            'swap_reference', 'sender_legal_name', 'receiver_legal_name',
            'from_institution', 'to_institution', 'from_account_mask', 'to_account_mask',
            'amount', 'currency_code', 'direction'
        ];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }

        $data['sat_purchased'] = $data['sat_purchased'] ?? 0;
        $data['exchange_rate'] = $data['exchange_rate'] ?? 1.0;
        $data['payment_purpose_code'] = $data['payment_purpose_code'] ?? 'OTHR';
        $data['sca_required'] = $data['sca_required'] ?? true;
        $data['sca_method'] = $data['sca_method'] ?? 'OTP';
        $data['kyc_verified'] = $data['kyc_verified'] ?? false;
        $data['aml_screening_ref'] = $data['aml_screening_ref'] ?? null;
        $data['notes'] = $data['notes'] ?? null;
        $data['status'] = $data['status'] ?? 'PDNG';

        // 2️⃣ Insert main swap
        $stmt = $this->db->prepare("
            INSERT INTO swap_ledgers
            (swap_reference, sender_legal_name, sender_id_number, receiver_legal_name,
             from_institution, to_institution, from_account_mask, to_account_mask,
             amount, currency_code, sat_purchased, exchange_rate, payment_purpose_code,
             direction, status, sca_required, sca_method, kyc_verified, aml_screening_ref, notes)
            VALUES
            (:swap_reference, :sender_legal_name, :sender_id_number, :receiver_legal_name,
             :from_institution, :to_institution, :from_account_mask, :to_account_mask,
             :amount, :currency_code, :sat_purchased, :exchange_rate, :payment_purpose_code,
             :direction, :status, :sca_required, :sca_method, :kyc_verified, :aml_screening_ref, :notes)
            RETURNING swap_id
        ");

        $stmt->execute([
            ':swap_reference' => $data['swap_reference'],
            ':sender_legal_name' => $data['sender_legal_name'],
            ':sender_id_number' => $data['sender_id_number'] ?? null,
            ':receiver_legal_name' => $data['receiver_legal_name'],
            ':from_institution' => $data['from_institution'],
            ':to_institution' => $data['to_institution'],
            ':from_account_mask' => $data['from_account_mask'],
            ':to_account_mask' => $data['to_account_mask'],
            ':amount' => $data['amount'],
            ':currency_code' => strtoupper($data['currency_code']),
            ':sat_purchased' => $data['sat_purchased'],
            ':exchange_rate' => $data['exchange_rate'],
            ':payment_purpose_code' => $data['payment_purpose_code'],
            ':direction' => strtoupper($data['direction']),
            ':status' => $data['status'],
            ':sca_required' => $data['sca_required'],
            ':sca_method' => $data['sca_method'],
            ':kyc_verified' => $data['kyc_verified'],
            ':aml_screening_ref' => $data['aml_screening_ref'],
            ':notes' => $data['notes'],
        ]);

        $swapId = (int)$stmt->fetchColumn();

        // 3️⃣ Auto-log initial audit entry
        $auditStmt = $this->db->prepare("
            INSERT INTO swap_audit_log
            (swap_id, previous_status, new_status, changed_by, change_reason)
            VALUES (:swap_id, NULL, :new_status, 'SYSTEM_AUTO', 'Initial creation')
        ");
        $auditStmt->execute([
            ':swap_id' => $swapId,
            ':new_status' => $data['status']
        ]);

        return $swapId;
    }

    /**
     * Record fee splits for this swap
     * @param int $swapId
     * @param array $splits ['split_type' => amount]
     */
    public function recordFeeSplits(int $swapId, array $splits): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO swap_fee_splits (swap_id, split_type, amount, currency_code)
            VALUES (:swap_id, :split_type, :amount, :currency_code)
        ");

        foreach ($splits as $type => $amount) {
            if ($amount <= 0) continue;
            $stmt->execute([
                ':swap_id' => $swapId,
                ':split_type' => $type,
                ':amount' => $amount,
                ':currency_code' => 'BWP'
            ]);
        }
    }
}

