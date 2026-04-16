<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

// 5. INTEGRATION_LAYER/interfaces/ReportingProviderInterface.php

interface ReportingProviderInterface
{
    /**
     * Send a report to regulator/AML service
     * @param array $data
     * @return bool Success
     */
    public function sendReport(array $data): bool;
}
