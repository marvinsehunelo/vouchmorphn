<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

declare(strict_types=1);

namespace BUSINESS_LOGIC_LAYER\Controllers;

use PDO;
use PDOException;

class DashboardController
{
    private PDO $db;

    public function __construct(PDO $swapDB)
    {
        $this->db = $swapDB;
    }

    /**
     * TOTAL USERS IN swap_system.users
     */
    public function getTotalUsers(): int
    {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM users");
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("DashboardController getTotalUsers ERROR: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * TOTAL TRANSACTIONS IN swap_system.transactions
     */
    public function getTotalTransactions(): int
    {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM transactions");
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("DashboardController getTotalTransactions ERROR: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * TOTAL WALLET VALUE FROM swap_system.wallets (sum of balances)
     */
    public function getTotalWalletValue(): float
    {
        try {
            $stmt = $this->db->query("SELECT COALESCE(SUM(balance), 0) FROM wallets");
            return (float)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("DashboardController getTotalWalletValue ERROR: " . $e->getMessage());
            return 0.00;
        }
    }

    /**
     * TOTAL VOUCHERS CREATED IN swap_system.vouchers
     */
    public function getTotalVouchers(): int
    {
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM vouchers");
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("DashboardController getTotalVouchers ERROR: " . $e->getMessage());
            return 0;
        }
    }
}

