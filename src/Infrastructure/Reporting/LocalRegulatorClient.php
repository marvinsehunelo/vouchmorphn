<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// 5. INTEGRATION_LAYER/clients/ReportingClients/LocalRegulatorClient.php

require_once __DIR__ . '/../../interfaces/ReportingProviderInterface.php';

class LocalRegulatorClient implements ReportingProviderInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function sendReport(array $data): bool
    {
        $reportId = $data['report_id'] ?? $this->generateReportId();
        $timestamp = gmdate('c');

        $payload = [
            'report_id' => $reportId,
            'timestamp' => $timestamp,
            'body'      => $data
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', $json);

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO regulator_outbox
                (report_id, payload, integrity_hash, status, attempts, created_at)
                VALUES (:id, :payload, :hash, 'PENDING', 0, NOW())
                ON CONFLICT (report_id) DO NOTHING
            ");

            $stmt->execute([
                ':id'      => $reportId,
                ':payload' => $json,
                ':hash'    => $hash
            ]);

            return true;

        } catch (\Throwable $e) {
            error_log("REGULATOR_QUEUE_ERROR: ".$e->getMessage());
            return false;
        }
    }

    private function generateReportId(): string
    {
        return 'RPT-'.bin2hex(random_bytes(12));
    }
}

