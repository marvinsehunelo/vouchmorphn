<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

declare(strict_types=1);

/**
 * Message-Based Clearing Report
 * NO FLOATS - NO PREFUNDING - Pure message clearing system
 */

require_once __DIR__ . '/../../bootstrap.php';

use DATA_PERSISTENCE_LAYER\config\DBConnection;

class MessageClearingReportTest
{
    private PDO $pdo;
    
    public function __construct()
    {
        $this->pdo = DBConnection::getConnection();
    }
    
    /**
     * Safe number formatting - converts string to float
     */
    private function formatAmount($value): string
    {
        return number_format((float)$value, 2);
    }
    
    /**
     * Real-time Clearing Status
     */
    public function showRealTimeClearingStatus(): void
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "⚡ REAL-TIME CLEARING STATUS\n";
        echo str_repeat("=", 100) . "\n\n";
        
        // 1. QUEUE DEPTHS
        echo "📊 CURRENT QUEUE DEPTHS:\n";
        echo str_repeat("-", 50) . "\n";
        
        // Message Outbox
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM message_outbox WHERE status = 'PENDING'");
        $outboxPending = $stmt->fetchColumn();
        
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM message_outbox");
        $outboxTotal = $stmt->fetchColumn();
        echo "  Message Outbox: $outboxTotal total ($outboxPending pending)\n";
        
        // Settlement Queue
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM settlement_queue");
        $queueCount = $stmt->fetchColumn();
        echo "  Settlement Queue: $queueCount items\n";
        
        // Net Positions (just tracking, not prefunding)
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM net_positions WHERE amount > 0");
        $netCount = $stmt->fetchColumn();
        
        $stmt = $this->pdo->query("SELECT COALESCE(SUM(amount), 0) FROM net_positions WHERE amount > 0");
        $netTotal = $stmt->fetchColumn();
        echo "  Net Positions: $netCount positions, Total: BWP " . $this->formatAmount($netTotal) . "\n";
        
        // Settlement Messages
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM settlement_messages WHERE status = 'PENDING'");
        $settlementPending = $stmt->fetchColumn();
        
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM settlement_messages");
        $settlementTotal = $stmt->fetchColumn();
        echo "  Settlement Messages: $settlementTotal total ($settlementPending pending)\n\n";
        
        // 2. RECENT MESSAGES
        echo "📨 RECENT MESSAGES:\n";
        echo str_repeat("-", 50) . "\n";
        
        $stmt = $this->pdo->query("
            SELECT message_id, channel, destination, status, created_at
            FROM message_outbox
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($recent as $msg) {
            $time = date('H:i:s', strtotime($msg['created_at']));
            echo "  [{$time}] {$msg['channel']} → {$msg['destination']}: {$msg['status']}\n";
        }
        echo "\n";
        
        // 3. MESSAGE FLOW STATUS
        echo "📊 MESSAGE FLOW STATUS:\n";
        echo str_repeat("-", 50) . "\n";
        
        // Messages in last hour
        $stmt = $this->pdo->query("
            SELECT COUNT(*) FROM message_outbox 
            WHERE created_at > NOW() - INTERVAL '1 hour'
        ");
        $lastHour = $stmt->fetchColumn();
        echo "  Messages (last hour): $lastHour\n";
        
        // Failed messages
        $stmt = $this->pdo->query("
            SELECT COUNT(*) FROM message_outbox 
            WHERE status = 'FAILED'
        ");
        $failed = $stmt->fetchColumn();
        echo "  Failed Messages: $failed\n";
        
        // Messages by status
        $stmt = $this->pdo->query("
            SELECT status, COUNT(*) as count
            FROM message_outbox
            GROUP BY status
            ORDER BY count DESC
        ");
        $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($statuses as $s) {
            echo "  {$s['status']}: {$s['count']} messages\n";
        }
        echo "\n";
    }
    
    /**
     * Daily Clearing Report
     */
    public function generateDailyClearingReport(string $date = null): void
    {
        $date = $date ?? date('Y-m-d');
        
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "📅 DAILY CLEARING REPORT - $date\n";
        echo str_repeat("=", 100) . "\n\n";
        
        // 1. MESSAGE VOLUME SUMMARY
        echo "📊 MESSAGE VOLUME SUMMARY:\n";
        echo str_repeat("-", 50) . "\n";
        
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_messages,
                COUNT(DISTINCT message_id) as unique_messages,
                COUNT(CASE WHEN status = 'PENDING' THEN 1 END) as pending,
                COUNT(CASE WHEN status = 'PROCESSED' THEN 1 END) as processed,
                COUNT(CASE WHEN status = 'FAILED' THEN 1 END) as failed
            FROM message_outbox
            WHERE DATE(created_at) = ?
        ");
        $stmt->execute([$date]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "  Total Messages:  " . number_format($summary['total_messages'] ?? 0) . "\n";
        echo "  Pending:         " . number_format($summary['pending'] ?? 0) . "\n";
        echo "  Processed:       " . number_format($summary['processed'] ?? 0) . "\n";
        echo "  Failed:          " . number_format($summary['failed'] ?? 0) . "\n\n";
        
        // 2. MESSAGES BY CHANNEL
        echo "📨 MESSAGES BY CHANNEL:\n";
        echo str_repeat("-", 50) . "\n";
        
        $stmt = $this->pdo->prepare("
            SELECT 
                channel,
                COUNT(*) as message_count
            FROM message_outbox
            WHERE DATE(created_at) = ?
            GROUP BY channel
            ORDER BY message_count DESC
        ");
        $stmt->execute([$date]);
        $channels = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($channels as $channel) {
            echo "  {$channel['channel']}: {$channel['message_count']} messages\n";
        }
        echo "\n";
        
        // 3. SETTLEMENT MESSAGES
        echo "💰 SETTLEMENT MESSAGES:\n";
        echo str_repeat("-", 50) . "\n";
        
        $stmt = $this->pdo->prepare("
            SELECT 
                type,
                COUNT(*) as count,
                COALESCE(SUM(amount), 0) as total_amount
            FROM settlement_messages
            WHERE DATE(created_at) = ?
            GROUP BY type
        ");
        $stmt->execute([$date]);
        $settlementTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($settlementTypes as $type) {
            echo "  {$type['type']}: {$type['count']} messages, " .
                 "Total: BWP " . $this->formatAmount($type['total_amount']) . "\n";
        }
        echo "\n";
        
        // 4. NET POSITIONS SUMMARY
        echo "⚖️  NET POSITIONS SUMMARY:\n";
        echo str_repeat("-", 50) . "\n";
        
        $stmt = $this->pdo->query("
            SELECT 
                debtor,
                creditor,
                amount
            FROM net_positions
            WHERE amount > 0
            ORDER BY amount DESC
            LIMIT 10
        ");
        $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($positions as $pos) {
            echo "  {$pos['debtor']} → {$pos['creditor']}: BWP " . 
                 $this->formatAmount($pos['amount']) . "\n";
        }
        echo "\n";
        
        // 5. FEE SUMMARY
        echo "💰 FEE SUMMARY:\n";
        echo str_repeat("-", 50) . "\n";
        
        $stmt = $this->pdo->prepare("
            SELECT 
                from_institution,
                COALESCE(SUM(swap_fee), 0) as total_fees
            FROM swap_ledgers
            WHERE DATE(created_at) = ?
            GROUP BY from_institution
            ORDER BY total_fees DESC
        ");
        $stmt->execute([$date]);
        $fees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $totalFees = 0;
        foreach ($fees as $fee) {
            echo "  {$fee['from_institution']}: BWP " . $this->formatAmount($fee['total_fees']) . "\n";
            $totalFees += (float)$fee['total_fees'];
        }
        echo "  TOTAL FEES: BWP " . $this->formatAmount($totalFees) . "\n\n";
    }
    
    /**
     * Weekly Clearing Summary
     */
    public function generateWeeklyClearingSummary(string $startDate = null): void
    {
        $startDate = $startDate ?? date('Y-m-d', strtotime('monday this week'));
        $endDate = date('Y-m-d', strtotime($startDate . ' +6 days'));
        
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "📆 WEEKLY CLEARING SUMMARY - $startDate to $endDate\n";
        echo str_repeat("=", 100) . "\n\n";
        
        // Daily message breakdown
        echo "📊 DAILY MESSAGE BREAKDOWN:\n";
        echo str_repeat("-", 50) . "\n";
        
        $daily = [];
        for ($i = 0; $i <= 6; $i++) {
            $date = date('Y-m-d', strtotime($startDate . " +$i days"));
            
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as messages,
                    COUNT(CASE WHEN status = 'PENDING' THEN 1 END) as pending,
                    COUNT(CASE WHEN status = 'PROCESSED' THEN 1 END) as processed
                FROM message_outbox
                WHERE DATE(created_at) = ?
            ");
            $stmt->execute([$date]);
            $day = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $dayName = date('l', strtotime($date));
            $messages = $day['messages'] ?? 0;
            $pending = $day['pending'] ?? 0;
            $processed = $day['processed'] ?? 0;
            
            echo "  $dayName ($date):\n";
            echo "    Messages:  $messages total ($pending pending, $processed processed)\n";
            
            $daily[] = [
                'date' => $date,
                'messages' => $messages,
                'pending' => $pending,
                'processed' => $processed
            ];
        }
        echo "\n";
        
        // Weekly totals
        $totalMessages = array_sum(array_column($daily, 'messages'));
        $totalPending = array_sum(array_column($daily, 'pending'));
        $totalProcessed = array_sum(array_column($daily, 'processed'));
        
        echo "📈 WEEKLY TOTALS:\n";
        echo str_repeat("-", 50) . "\n";
        echo "  Total Messages: " . number_format($totalMessages) . "\n";
        echo "  Total Pending:  " . number_format($totalPending) . "\n";
        echo "  Total Processed: " . number_format($totalProcessed) . "\n";
        echo "  Avg Daily:      " . number_format($totalMessages / 7) . " messages\n\n";
        
        // Top message destinations
        echo "🎯 TOP MESSAGE DESTINATIONS:\n";
        echo str_repeat("-", 50) . "\n";
        
        $stmt = $this->pdo->prepare("
            SELECT 
                destination,
                COUNT(*) as msg_count
            FROM message_outbox
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY destination
            ORDER BY msg_count DESC
            LIMIT 5
        ");
        $stmt->execute([$startDate, $endDate]);
        $top = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($top as $idx => $dest) {
            $rank = $idx + 1;
            echo "  $rank. {$dest['destination']}: {$dest['msg_count']} messages\n";
        }
        echo "\n";
    }
    
    /**
     * Outstanding Fees Report
     */
    public function generateOutstandingFeesReport(): void
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "💰 OUTSTANDING FEES REPORT\n";
        echo str_repeat("=", 100) . "\n\n";
        
        // Fees by institution
        echo "📊 FEES BY INSTITUTION:\n";
        echo str_repeat("-", 50) . "\n";
        
        $stmt = $this->pdo->query("
            SELECT 
                from_institution,
                COUNT(*) as transaction_count,
                COALESCE(SUM(swap_fee), 0) as total_fees
            FROM swap_ledgers
            WHERE status != 'failed'
            GROUP BY from_institution
            ORDER BY total_fees DESC
        ");
        $fees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $grandTotal = 0;
        foreach ($fees as $fee) {
            echo "  {$fee['from_institution']}:\n";
            echo "    Transactions: {$fee['transaction_count']}\n";
            echo "    Total Fees:   BWP " . $this->formatAmount($fee['total_fees']) . "\n\n";
            $grandTotal += (float)$fee['total_fees'];
        }
        
        echo "  GRAND TOTAL FEES: BWP " . $this->formatAmount($grandTotal) . "\n\n";
        
        // Pending fee settlements
        echo "⏳ PENDING FEE SETTLEMENTS:\n";
        echo str_repeat("-", 50) . "\n";
        
        $stmt = $this->pdo->query("
            SELECT 
                sm.from_participant,
                COUNT(*) as pending_count,
                COALESCE(SUM(sm.amount), 0) as total_amount
            FROM settlement_messages sm
            WHERE sm.type = 'FEE' AND sm.status = 'PENDING'
            GROUP BY sm.from_participant
        ");
        $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($pending as $p) {
            echo "  {$p['from_participant']}: {$p['pending_count']} pending, " .
                 "BWP " . $this->formatAmount($p['total_amount']) . "\n";
        }
        if (empty($pending)) {
            echo "  No pending fee settlements\n";
        }
        echo "\n";
    }
    
    /**
     * Message Throughput Report
     */
    public function generateMessageThroughputReport(): void
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "📈 MESSAGE THROUGHPUT REPORT\n";
        echo str_repeat("=", 100) . "\n\n";
        
        // Messages per hour today
        echo "⏱️  MESSAGES PER HOUR (TODAY):\n";
        echo str_repeat("-", 50) . "\n";
        
        for ($hour = 0; $hour < 24; $hour++) {
            $start = date('Y-m-d') . " " . str_pad((string)$hour, 2, '0', STR_PAD_LEFT) . ":00:00";
            $end = date('Y-m-d') . " " . str_pad((string)$hour, 2, '0', STR_PAD_LEFT) . ":59:59";
            
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM message_outbox
                WHERE created_at BETWEEN ? AND ?
            ");
            $stmt->execute([$start, $end]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                echo "  Hour $hour:00: $count messages\n";
            }
        }
        echo "\n";
        
        // Peak throughput
        $stmt = $this->pdo->query("
            SELECT 
                DATE_TRUNC('hour', created_at) as hour,
                COUNT(*) as msg_count
            FROM message_outbox
            GROUP BY DATE_TRUNC('hour', created_at)
            ORDER BY msg_count DESC
            LIMIT 1
        ");
        $peak = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($peak) {
            $peakHour = date('Y-m-d H:00', strtotime($peak['hour']));
            echo "🚀 PEAK THROUGHPUT:\n";
            echo "  $peakHour: {$peak['msg_count']} messages/hour\n";
            echo "  " . round($peak['msg_count'] / 60, 2) . " messages/minute\n\n";
        }
        
        // Message processing success rate
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN status = 'PROCESSED' THEN 1 END) as processed,
                COUNT(CASE WHEN status = 'FAILED' THEN 1 END) as failed
            FROM message_outbox
            WHERE created_at > NOW() - INTERVAL '24 hours'
        ");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($stats && $stats['total'] > 0) {
            $successRate = round(($stats['processed'] / $stats['total']) * 100, 2);
            echo "✅ SUCCESS RATE (24h): $successRate%\n";
            echo "  Processed: {$stats['processed']}\n";
            echo "  Failed: {$stats['failed']}\n\n";
        }
    }
    
    /**
     * Run all reports
     */
    public function runAllReports(): void
    {
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "🚀 MESSAGE CLEARING REPORTS (NO FLOATS - NO PREFUNDING)\n";
        echo str_repeat("=", 100) . "\n";
        
        $this->showRealTimeClearingStatus();
        $this->generateDailyClearingReport();
        $this->generateWeeklyClearingSummary();
        $this->generateOutstandingFeesReport();
        $this->generateMessageThroughputReport();
        
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "✅ ALL REPORTS GENERATED SUCCESSFULLY\n";
        echo str_repeat("=", 100) . "\n";
    }
}

// Run the reports
$test = new MessageClearingReportTest();
$test->runAllReports();
