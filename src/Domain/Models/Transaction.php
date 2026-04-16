<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

declare(strict_types=1);

namespace BUSINESS_LOGIC_LAYER\models;

use PDO;
use InvalidArgumentException;
use RuntimeException;

/**
 * Transaction Model - Immutable, Fee/Split, Ledger-Linked
 * Fully Sandbox-Ready (Botswana & EU)
 */
class Transaction
{
    private PDO $db;

    // Immutable properties
    private ?int $id = null;
    private string $type;
    private string $fromAccount;
    private string $toAccount;
    private string $amount; // string to preserve decimals
    private string $currency;
    private string $status;
    private float $fee = 0.0;
    private float $satPurchased = 0.0;
    private bool $scaRequired = false;
    private ?string $scaVerifiedAt = null;

    public function __construct(PDO $db) 
    { 
        $this->db = $db; 
    }

    /**
     * Records a transaction + ledger entries
     * @param array $data
     * @return int Transaction ID
     */
    public function record(array $data): int 
    {
        $this->validateInput($data);

        $this->type        = strtoupper($data['transaction_type']);
        $this->fromAccount = $data['from_account'];
        $this->toAccount   = $data['to_account'];
        $this->currency    = strtoupper($data['currency_code']);
        $this->amount      = number_format((float)$data['amount'], 8, '.', '');
        $this->fee         = (float)($data['fee'] ?? 0.0);
        $this->satPurchased = (float)($data['sat_purchased'] ?? $this->amount);
        $this->scaRequired = (bool)($data['sca_required'] ?? false);
        $this->status      = $data['status'] ?? 'PDNG';
        $referenceId       = $data['reference_id'] ?? uniqid('txn_');

        $this->db->beginTransaction();
        try {
            // 1️⃣ Insert into transactions table
            $stmt = $this->db->prepare("
                INSERT INTO transactions (
                    transaction_type, from_account, to_account, amount, fee, sat_purchased,
                    currency_code, status, sca_required, sca_verified_at, reference, created_at
                ) VALUES (
                    :type, :from, :to, :amt, :fee, :sat, :ccy, :status, :sca_req, :sca_ver, :ref, NOW()
                )
            ");
            $stmt->execute([
                ':type'    => $this->type,
                ':from'    => $this->fromAccount,
                ':to'      => $this->toAccount,
                ':amt'     => $this->amount,
                ':fee'     => $this->fee,
                ':sat'     => $this->satPurchased,
                ':ccy'     => $this->currency,
                ':status'  => $this->status,
                ':sca_req' => $this->scaRequired,
                ':sca_ver' => $this->scaVerifiedAt,
                ':ref'     => $referenceId
            ]);

            $this->id = (int)$this->db->lastInsertId();

            // 2️⃣ Post ledger entries: debit, credit, and fee
            $entries = [];

            // Debit sender
            $entries[] = [
                'debit_account'  => $this->fromAccount,
                'credit_account' => $this->toAccount,
                'amount'         => $this->satPurchased,
                'currency'       => $this->currency,
                'description'    => strtoupper("TRANSFER-{$referenceId}")
            ];

            // Fee entry (to middleman/fee account)
            if ($this->fee > 0) {
                $entries[] = [
                    'debit_account'  => $this->fromAccount,
                    'credit_account' => 'fee_account',
                    'amount'         => $this->fee,
                    'currency'       => $this->currency,
                    'description'    => strtoupper("FEE-{$referenceId}")
                ];
            }

            $this->postLedgerEntries($entries, $referenceId);

            $this->db->commit();
            return $this->id;

        } catch (\PDOException $e) {
            $this->db->rollBack();
            if ($e->getCode() === '23505') {
                throw new RuntimeException("Duplicate transaction detected (Idempotency Violation).");
            }
            throw new RuntimeException("Database Error: Could not record transaction. {$e->getMessage()}");
        }
    }

    /**
     * Post ledger entries atomically
     */
    private function postLedgerEntries(array $entries, string $reference): void
    {
        $stmtInsert = $this->db->prepare("
            INSERT INTO ledger_entries
            (transaction_id, debit_account_id, credit_account_id, amount, currency_code, created_at, description)
            VALUES
            (:txn_id, :debit, :credit, :amt, :ccy, NOW(), :desc)
        ");

        foreach ($entries as $e) {
            $stmtInsert->execute([
                ':txn_id' => $this->id,
                ':debit'  => $e['debit_account'],
                ':credit' => $e['credit_account'],
                ':amt'    => $e['amount'],
                ':ccy'    => $e['currency'] ?? $this->currency,
                ':desc'   => $e['description'] ?? strtoupper("LEDGER-{$reference}")
            ]);
        }
    }

    /**
     * Validation for sandbox regulatory standards
     */
    private function validateInput(array $data): void
    {
        $required = ['transaction_type', 'from_account', 'to_account', 'amount', 'currency_code'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new InvalidArgumentException("Missing mandatory ISO field: $field");
            }
        }

        if (!is_numeric($data['amount']) || (float)$data['amount'] <= 0) {
            throw new InvalidArgumentException("Transaction amount must be a positive numeric value.");
        }

        if (strlen($data['currency_code']) !== 3) {
            throw new InvalidArgumentException("Currency must be a 3-letter ISO 4217 code.");
        }

        if (isset($data['fee']) && (!is_numeric($data['fee']) || (float)$data['fee'] < 0)) {
            throw new InvalidArgumentException("Fee must be non-negative numeric.");
        }

        if (isset($data['sat_purchased']) && (!is_numeric($data['sat_purchased']) || (float)$data['sat_purchased'] <= 0)) {
            throw new InvalidArgumentException("sat_purchased must be positive numeric.");
        }
    }

    // Getters only for immutability
    public function getId(): ?int { return $this->id; }
    public function getAmount(): string { return $this->amount; }
    public function getFee(): float { return $this->fee; }
    public function getSatPurchased(): float { return $this->satPurchased; }
}

