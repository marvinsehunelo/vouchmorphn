<?php
namespace BUSINESS_LOGIC_LAYER\Controllers;

use PDO;
use Exception;
use BUSINESS_LOGIC_LAYER\Services\LedgerService;
use BUSINESS_LOGIC_LAYER\Services\TransactionService;
use APP_LAYER\utils\AuditLogger;

class TransactionController {
    private LedgerService $ledger;
    private TransactionService $transactions;

    public function __construct(PDO $db) {
        $this->ledger = new LedgerService($db);
        $this->transactions = new TransactionService($db);
    }

    public function postManualEntry(array $data): array {
        try {
            $this->ledger->postEntries($data['entries'], $data['reference'] ?? 'manual');
            AuditLogger::write('transactions', null, 'manual_entry', null, json_encode($data), $data['performed_by'] ?? 'admin');
            return ['success'=>true,'message'=>'Entries posted'];
        } catch (Exception $e) {
            return ['success'=>false,'message'=>$e->getMessage()];
        }
    }

    public function getTransactions(array $filters = []): array {
        if (!empty($filters)) {
            return $this->transactions->filterTransactions($filters);
        }
        return $this->transactions->getAllTransactions();
    }
}

