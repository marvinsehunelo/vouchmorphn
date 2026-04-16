<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

declare(strict_types=1);

namespace BUSINESS_LOGIC_LAYER\services;

use PDO;
use InvalidArgumentException;
use Throwable;

/**
 * TransactionService – Sandbox & Regulatory Ready
 * Standards: ISO 20022 (Financial Messaging), ISO 4217 (Currency Codes),
 * GDPR (Data Protection), PCI-DSS (Sensitive Data Handling)
 */
class TransactionService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Fetch unified transaction history with:
     * - Fee splits
     * - Net purchased amounts
     * - ISO 20022 compliant statuses
     * - Multi-currency support
     * - GDPR / PII masking
     */
    public function getUnifiedHistory(int $limit = 50, int $offset = 0, bool $maskPii = true): array {
        $sql = "
            WITH UnifiedTransactions AS (
                -- General Transactions
                SELECT 
                    t.transaction_id::text as txn_id,
                    t.user_id,
                    u.username as initiator,
                    t.transaction_type as category,
                    t.amount as gross_amount,
                    COALESCE(f.fee_amount, 0) as fee_amount,
                    t.amount - COALESCE(f.fee_amount, 0) as net_amount,
                    t.currency_code as ccy,
                    t.reference as external_ref,
                    CASE 
                        WHEN t.status = 'completed' THEN 'ACCP'
                        WHEN t.status = 'failed' THEN 'RJCT'
                        ELSE 'PDNG'
                    END as iso_status,
                    t.created_at,
                    s.sca_required,
                    s.sca_verified_at
                FROM transactions t
                LEFT JOIN users u ON t.user_id = u.user_id
                LEFT JOIN fees f ON f.transaction_type = t.transaction_type
                LEFT JOIN swap_transactions s ON s.transaction_id = t.transaction_id

                UNION ALL

                -- Swap Transactions
                SELECT
                    st.swap_transaction_id::text as txn_id,
                    st.user_id,
                    st.from_phone as initiator,
                    'SWAP' as category,
                    st.amount as gross_amount,
                    COALESCE(f.fee_amount, 0) as fee_amount,
                    st.amount - COALESCE(f.fee_amount, 0) as net_amount,
                    st.currency_code as ccy,
                    st.idempotency_key as external_ref,
                    CASE 
                        WHEN st.status = 'completed' THEN 'ACCP'
                        WHEN st.status = 'failed' THEN 'RJCT'
                        ELSE 'PDNG'
                    END as iso_status,
                    st.created_at,
                    st.sca_required,
                    st.sca_verified_at
                FROM swap_transactions st
                LEFT JOIN fees f ON f.transaction_type = 'SWAP'
            )
            SELECT *
            FROM UnifiedTransactions
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($row) => $this->formatRow($row, $maskPii), $results);
    }

    /**
     * Filter transactions for compliance audit purposes
     */
    public function filterTransactions(array $filters, bool $maskPii = true): array {
        $params = [];
        $whereClauses = [];

        if (!empty($filters['start_date'])) {
            $whereClauses[] = "created_at >= :start_date";
            $params[':start_date'] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $whereClauses[] = "created_at <= :end_date";
            $params[':end_date'] = $filters['end_date'];
        }
        if (!empty($filters['user_id'])) {
            $whereClauses[] = "user_id = :u_id";
            $params[':u_id'] = $filters['user_id'];
        }
        if (!empty($filters['min_amount'])) {
            $whereClauses[] = "net_amount >= :min_amt";
            $params[':min_amt'] = $filters['min_amount'];
        }
        if (!empty($filters['currency'])) {
            $whereClauses[] = "ccy = :ccy";
            $params[':ccy'] = strtoupper($filters['currency']);
        }

        $whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : "";

        $sql = "
            SELECT *
            FROM (
                SELECT 
                    t.transaction_id::text as txn_id,
                    t.user_id,
                    u.username as initiator,
                    t.transaction_type as category,
                    t.amount as gross_amount,
                    COALESCE(f.fee_amount,0) as fee_amount,
                    t.amount - COALESCE(f.fee_amount,0) as net_amount,
                    t.currency_code as ccy,
                    t.reference as external_ref,
                    CASE WHEN t.status='completed' THEN 'ACCP' WHEN t.status='failed' THEN 'RJCT' ELSE 'PDNG' END as iso_status,
                    t.created_at
                FROM transactions t
                LEFT JOIN users u ON t.user_id=u.user_id
                LEFT JOIN fees f ON f.transaction_type=t.transaction_type

                UNION ALL

                SELECT 
                    st.swap_transaction_id::text as txn_id,
                    st.user_id,
                    st.from_phone as initiator,
                    'SWAP' as category,
                    st.amount as gross_amount,
                    COALESCE(f.fee_amount,0) as fee_amount,
                    st.amount - COALESCE(f.fee_amount,0) as net_amount,
                    st.currency_code as ccy,
                    st.idempotency_key as external_ref,
                    CASE WHEN st.status='completed' THEN 'ACCP' WHEN st.status='failed' THEN 'RJCT' ELSE 'PDNG' END as iso_status,
                    st.created_at
                FROM swap_transactions st
                LEFT JOIN fees f ON f.transaction_type='SWAP'
            ) AS combined
            $whereSql
            ORDER BY created_at DESC
        ";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();

        return array_map(fn($row) => $this->formatRow($row, $maskPii), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Format rows with PII masking, ISO 8601 date, currency precision
     */
    private function formatRow(array $row, bool $maskPii): array {
        if ($maskPii && isset($row['initiator'])) {
            if (preg_match('/^\+?\d{7,15}$/', $row['initiator'])) {
                $row['initiator'] = substr($row['initiator'],0,4).'****'.substr($row['initiator'],-2);
            }
        }

        $row['gross_amount'] = number_format((float)$row['gross_amount'], 2, '.', '');
        $row['net_amount']   = number_format((float)$row['net_amount'], 2, '.', '');
        $row['fee_amount']   = number_format((float)$row['fee_amount'], 2, '.', '');
        $row['created_at']   = date('c', strtotime($row['created_at']));

        // Default SCA flags if missing
        $row['sca_required'] = $row['sca_required'] ?? false;
        $row['sca_verified_at'] = $row['sca_verified_at'] ?? null;

        return $row;
    }

    /**
     * Log regulatory/audit fetch (EU/BW Sandbox requirement)
     */
    public function logAudit(int $userId, string $action, array $filters = []): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO audit_logs (table_name, record_id, action, old_data, new_data, performed_by, ip_address, created_at)
                VALUES ('transactions', NULL, :action, NULL, :filters, :userId, inet_client_addr(), NOW())
            ");
            $stmt->bindValue(':action', $action);
            $stmt->bindValue(':filters', json_encode($filters));
            $stmt->bindValue(':userId', $userId);
            $stmt->execute();
        } catch (Throwable $e) {
            // Never fail main transaction flow on audit failure
        }
    }
}

