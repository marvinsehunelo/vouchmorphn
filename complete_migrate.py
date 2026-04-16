import os
import re
import shutil
import json
from pathlib import Path

PROJECT_ROOT = Path.cwd()

# Complete mapping for EVERY file
MIGRATION_MAP = {
    # === ROOT LEVEL FILES ===
    ".env": ".env",
    "Dockerfile": "Dockerfile",
    "docker-compose.yml": "docker-compose.yml",
    "composer.json": "composer.json",
    "composer.lock": "composer.lock",
    "README_FIRST.txt": "docs/README_FIRST.txt",
    "add-variables.sh": "scripts/add-variables.sh",
    "backup.sql": "database/backup.sql",
    "swap_system_bw.sql": "database/swap_system_bw.sql",
    "swap_system_bw_backup.tar": "backups/swap_system_bw_backup.tar",
    "railway.json": "railway.json",
    "nixpacks.toml": "nixpacks.toml",
    
    # These are corrupt files - they should be deleted
    "PDO::ERRMODE_EXCEPTION,": "TO_DELETE",
    "PDO::FETCH_ASSOC": "TO_DELETE",
    
    # === CERTIFICATION FILES ===
    "certification_result_20260215_094809.txt": "tests/certification/results/20260215_094809.txt",
    "certification_result_20260215_095116.txt": "tests/certification/results/20260215_095116.txt",
    
    # === DOCKER ===
    "docker/nginx.conf": "docker/nginx/nginx.conf",
    "docker/php.ini": "docker/php/php.ini",
    
    # === CONFIG ===
    "config/database.php": "config/database.php",
    
    # === DEBUG FILES (DELETE BEFORE VISA) ===
    "debug_connection.php": "TO_DELETE",
    "debug_env.php": "TO_DELETE",
    "test.php": "TO_DELETE",
    "testing.php": "TO_DELETE",
    "src/test.php": "TO_DELETE",
    
    # === PUBLIC FILES MAPPING ===
    "public/index.php": "public/index.php",
    "public/health.php": "public/health.php",
    
    # Admin
    "public/admin/admin_dashboard.php": "public/admin/dashboard.php",
    "public/admin/admin_login.php": "public/admin/login.php",
    "public/admin/admin_logout.php": "public/admin/logout.php",
    "public/admin/admin_management.php": "public/admin/management.php",
    "public/admin/clear.php": "public/admin/clear.php",
    "public/admin/process_expired_swaps.php": "public/admin/process_expired_swaps.php",
    "public/admin/api/get_metrics.php": "public/admin/api/metrics.php",
    "public/admin/api/get_settlements.php": "public/admin/api/settlements.php",
    "public/admin/api/get_swaps.php": "public/admin/api/swaps.php",
    "public/admin/reports/audit_trails.php": "public/admin/reports/audit-trails.php",
    "public/admin/reports/daily_reconciliations.php": "public/admin/reports/daily.php",
    "public/admin/reports/monthly_reconciliations.php": "public/admin/reports/monthly.php",
    "public/admin/reports/suspicious_activity_report.php": "public/admin/reports/suspicious.php",
    "public/admin/reports/weekly_reconciliations.php": "public/admin/reports/weekly.php",
    "public/admin/views/reports_view.php": "public/admin/views/reports.php",
    
    # API
    "public/api/MojaloopAdapter.php": "public/api/mojaloop/adapter.php",
    "public/api/MojaloopAdapter_debug.php": "TO_DELETE",
    "public/api/minimal.php": "TO_DELETE",
    "public/api/simple.php": "TO_DELETE",
    "public/api/test.php": "TO_DELETE",
    "public/api/test_callback.php": "TO_DELETE",
    "public/api/ussd.php": "public/api/ussd/index.php",
    "public/api/callback/index.php": "public/api/callback/index.php",
    "public/api/callback/sms_delivery.php": "public/api/callback/sms-delivery.php",
    "public/api/callback/fix-permissions.sh": "scripts/fix-permissions.sh",
    "public/api/handlers/ParticipantsHandler.php": "src/Application/Handlers/ParticipantsHandler.php",
    "public/api/handlers/PartiesHandler.php": "src/Application/Handlers/PartiesHandler.php",
    "public/api/handlers/QoutesHandler.php": "src/Application/Handlers/QuotesHandler.php",
    "public/api/handlers/TransfersHandler.php": "src/Application/Handlers/TransfersHandler.php",
    "public/api/mapper/ErrorMapper.php": "src/Infrastructure/Mojaloop/Mappers/ErrorMapper.php",
    "public/api/mapper/ResponseMapper.php": "src/Infrastructure/Mojaloop/Mappers/ResponseMapper.php",
    
    # API v1 Cards
    "public/api/v1/cards/.htaccess": "public/api/v1/cards/.htaccess",
    "public/api/v1/cards/admin/batch_create.php": "public/api/v1/cards/admin/batch.php",
    "public/api/v1/cards/admin/inventory.php": "public/api/v1/cards/admin/inventory.php",
    "public/api/v1/cards/admin/ship.php": "public/api/v1/cards/admin/ship.php",
    "public/api/v1/cards/apply.php": "public/api/v1/cards/apply.php",
    "public/api/v1/cards/authorize.php": "public/api/v1/cards/authorize.php",
    "public/api/v1/cards/balance.php": "public/api/v1/cards/balance.php",
    "public/api/v1/cards/block.php": "public/api/v1/cards/block.php",
    "public/api/v1/cards/issue.php": "public/api/v1/cards/issue.php",
    "public/api/v1/cards/kyc/upload.php": "public/api/v1/cards/kyc/upload.php",
    "public/api/v1/cards/search.php": "public/api/v1/cards/search.php",
    "public/api/v1/cards/shipping.php": "public/api/v1/cards/shipping.php",
    "public/api/v1/cards/status.php": "public/api/v1/cards/status.php",
    "public/api/v1/cards/transactions.php": "public/api/v1/cards/transactions.php",
    "public/api/v1/cards/verify.php": "public/api/v1/cards/verify.php",
    
    # API v1 Swap
    "public/api/v1/swap/cancel_cashout.php": "public/api/v1/swap/cancel.php",
    "public/api/v1/swap/execute.php": "public/api/v1/swap/execute.php",
    
    # Control Panel (looks like internal admin)
    "public/control/api/compliance.php": "public/admin/api/compliance.php",
    "public/control/api/connections.php": "public/admin/api/connections.php",
    "public/control/api/health.php": "public/health.php",
    "public/control/assets/css/control.css": "public/assets/css/admin.css",
    "public/control/assets/js/control.js": "public/assets/js/admin.js",
    "public/control/modules/audit_viewer.php": "src/Application/Admin/Modules/AuditViewer.php",
    "public/control/modules/compliance_checker.php": "src/Application/Admin/Modules/ComplianceChecker.php",
    "public/control/modules/partner_manager.php": "src/Application/Admin/Modules/PartnerManager.php",
    "public/control/modules/report_generator.php": "src/Application/Admin/Modules/ReportGenerator.php",
    "public/control/workcontrol.php": "public/admin/workcontrol.php",
    
    # User portal
    "public/user/Flow.php": "src/Domain/ValueObjects/Flow.php",
    "public/user/assets/image.php": "public/assets/images/image.php",
    "public/user/assets/vouchcover.png": "public/assets/images/vouchcover.png",
    "public/user/index.php": "public/user/index.php",
    "public/user/list.php": "public/user/list.php",
    "public/user/login.php": "public/user/login.php",
    "public/user/logout.php": "public/user/logout.php",
    "public/user/messageorchestra.php": "public/user/message.php",
    "public/user/partials/footer.php": "public/partials/footer.php",
    "public/user/partials/header.php": "public/partials/header.php",
    "public/user/register.php": "public/user/register.php",
    "public/user/regulationdemo.php": "public/user/demo/regulation.php",
    "public/user/user_dashboard.php": "public/user/dashboard.php",
    "public/user/verify_otp.php": "public/user/verify-otp.php",
    "public/user/waitlist-handler.php": "public/api/waitlist/handler.php",
    
    # === SRC LAYER MAPPING ===
    # ADMIN_LAYER -> Application/Admin
    "src/ADMIN_LAYER/Auth/AdminAuth.php": "src/Application/Admin/Auth/AdminAuth.php",
    "src/ADMIN_LAYER/Middleware/RoleMiddleware.php": "src/Application/Admin/Middleware/RoleMiddleware.php",
    "src/ADMIN_LAYER/api/add_admin.php": "src/Application/Admin/Api/AddAdmin.php",
    "src/ADMIN_LAYER/api/fetch_bank_balance.php": "src/Application/Admin/Api/FetchBankBalance.php",
    "src/ADMIN_LAYER/tools/NotificationBroadcaster.php": "src/Application/Admin/Tools/NotificationBroadcaster.php",
    "src/ADMIN_LAYER/tools/UserAccessManager.php": "src/Application/Admin/Tools/UserAccessManager.php",
    "src/ADMIN_LAYER/tools/configEditor.php": "src/Application/Admin/Tools/ConfigEditor.php",
    
    # APP_LAYER -> Application
    "src/APP_LAYER/utils/AuditLogger.php": "src/Application/Utils/AuditLogger.php",
    "src/APP_LAYER/utils/Logger.php": "src/Application/Utils/Logger.php",
    "src/APP_LAYER/utils/PhoneHelper.php": "src/Application/Utils/PhoneHelper.php",
    "src/APP_LAYER/utils/PinHelper.php": "src/Application/Utils/PinHelper.php",
    "src/APP_LAYER/utils/SecretManagerClient.php": "src/Infrastructure/Secrets/SecretManagerClient.php",
    "src/APP_LAYER/utils/SessionManager.php": "src/Application/Utils/SessionManager.php",
    "src/APP_LAYER/utils/access_control.php": "src/Application/Middleware/AccessControl.php",
    "src/APP_LAYER/utils/access_denied.php": "src/Application/Views/AccessDenied.php",
    "src/APP_LAYER/utils/token_validator.php": "src/Security/Auth/TokenValidator.php",
    
    # BUSINESS_LOGIC_LAYER -> Domain + Application
    "src/BUSINESS_LOGIC_LAYER/Helpers/CardHelper.php": "src/Domain/Helpers/CardHelper.php",
    "src/BUSINESS_LOGIC_LAYER/Helpers/SwapStatusResolver.php": "src/Domain/ValueObjects/SwapStatus.php",
    "src/BUSINESS_LOGIC_LAYER/controllers/AdminController.php": "src/Application/Controllers/AdminController.php",
    "src/BUSINESS_LOGIC_LAYER/controllers/AuthController.php": "src/Application/Controllers/AuthController.php",
    "src/BUSINESS_LOGIC_LAYER/controllers/ComplianceController.php": "src/Application/Controllers/ComplianceController.php",
    "src/BUSINESS_LOGIC_LAYER/controllers/DashboardController.php": "src/Application/Controllers/DashboardController.php",
    "src/BUSINESS_LOGIC_LAYER/controllers/ExpiredSwapsController.php": "src/Application/Controllers/ExpiredSwapsController.php",
    "src/BUSINESS_LOGIC_LAYER/controllers/TransactionController.php": "src/Application/Controllers/TransactionController.php",
    "src/BUSINESS_LOGIC_LAYER/controllers/USSDController.php": "src/Application/Controllers/USSDController.php",
    "src/BUSINESS_LOGIC_LAYER/controllers/UserController.php": "src/Application/Controllers/UserController.php",
    "src/BUSINESS_LOGIC_LAYER/services/AdminService.php": "src/Domain/Services/AdminService.php",
    "src/BUSINESS_LOGIC_LAYER/services/AuditTrailService.php": "src/Domain/Services/AuditTrailService.php",
    "src/BUSINESS_LOGIC_LAYER/services/AuthService.php": "src/Domain/Services/AuthService.php",
    "src/BUSINESS_LOGIC_LAYER/services/CardApplicationService.php": "src/Domain/Services/CardApplicationService.php",
    "src/BUSINESS_LOGIC_LAYER/services/CardService.php": "src/Domain/Services/CardService.php",
    "src/BUSINESS_LOGIC_LAYER/services/CashoutService.php": "src/Domain/Services/CashoutService.php",
    "src/BUSINESS_LOGIC_LAYER/services/ComplianceService/AMLService.php": "src/Domain/Services/Compliance/AMLService.php",
    "src/BUSINESS_LOGIC_LAYER/services/ExpiredSwapsService.php": "src/Domain/Services/ExpiredSwapsService.php",
    "src/BUSINESS_LOGIC_LAYER/services/KYCDocumentService.php": "src/Domain/Services/KYCDocumentService.php",
    "src/BUSINESS_LOGIC_LAYER/services/LedgerService.php": "src/Domain/Services/LedgerService.php",
    "src/BUSINESS_LOGIC_LAYER/services/LicenceEnforcementService.php": "src/Domain/Services/LicenceEnforcementService.php",
    "src/BUSINESS_LOGIC_LAYER/services/NotificationService.php": "src/Domain/Services/NotificationService.php",
    "src/BUSINESS_LOGIC_LAYER/services/SmsNotificationService.php": "src/Infrastructure/SMS/SmsNotificationService.php",
    "src/BUSINESS_LOGIC_LAYER/services/SwapService.php": "src/Domain/Services/SwapService.php",
    "src/BUSINESS_LOGIC_LAYER/services/TransactionService.php": "src/Domain/Services/TransactionService.php",
    "src/BUSINESS_LOGIC_LAYER/services/UserService.php": "src/Domain/Services/UserService.php",
    "src/BUSINESS_LOGIC_LAYER/services/process_expired_swaps.php": "src/cron/process_expired_swaps.php",
    "src/BUSINESS_LOGIC_LAYER/services/settlement/HybridSettlementStrategy.php": "src/Domain/Services/Settlement/HybridSettlementStrategy.php",
    "src/BUSINESS_LOGIC_LAYER/services/swapservice_endpoint.php": "public/api/v1/swap/service-endpoint.php",
    
    # CORE_CONFIG -> Core/Config
    "src/CORE_CONFIG/CountryBankRegistry.php": "src/Core/Config/CountryBankRegistry.php",
    "src/CORE_CONFIG/channel.php": "src/Core/Config/channel.php",
    "src/CORE_CONFIG/flows.php": "src/Core/Config/flows.php",
    "src/CORE_CONFIG/load_country.php": "src/Core/Config/LoadCountry.php",
    "src/CORE_CONFIG/mojaloop.php": "src/Core/Config/mojaloop.php",
    "src/CORE_CONFIG/system_country.php": "src/Core/Config/SystemCountry.php",
    "src/CORE_CONFIG/countries/BW/config_BW.php": "src/Core/Config/Countries/Botswana/config.php",
    "src/CORE_CONFIG/countries/NG/config_NG.php": "src/Core/Config/Countries/Nigeria/config.php",
    "src/CORE_CONFIG/licences/global_licence_registry.json": "config/licences/global_registry.json",
    "src/CORE_CONFIG/licences/middlemen.json": "config/licences/middlemen.json",
    "src/CORE_CONFIG/security_policies.md": "docs/SECURITY_POLICIES.md",
    
    # Country config files (keep in config directory)
    "src/CORE_CONFIG/countries/BW/.env_BW": "config/countries/botswana/.env",
    "src/CORE_CONFIG/countries/BW/atm_notes_BW.json": "config/countries/botswana/atm_notes.json",
    "src/CORE_CONFIG/countries/BW/bank_config_BW.json": "config/countries/botswana/banks.json",
    "src/CORE_CONFIG/countries/BW/card_config_BW.json": "config/countries/botswana/cards.json",
    "src/CORE_CONFIG/countries/BW/communication_config_BW.json": "config/countries/botswana/communication.json",
    "src/CORE_CONFIG/countries/BW/fees_BW.json": "config/countries/botswana/fees.json",
    "src/CORE_CONFIG/countries/BW/participants_BW.json": "config/countries/botswana/participants.json",
    "src/CORE_CONFIG/countries/NG/.env_NG": "config/countries/nigeria/.env",
    "src/CORE_CONFIG/countries/NG/env_NG.example.json": "config/countries/nigeria/.env.example",
    
    # DATA_PERSISTENCE_LAYER -> Core/Database + Domain/Models
    "src/DATA_PERSISTENCE_LAYER/config/DBConnection.php": "src/Core/Database/DBConnection.php",
    "src/DATA_PERSISTENCE_LAYER/config/settings.php": "config/database_settings.php",
    "src/DATA_PERSISTENCE_LAYER/models/Admin.php": "src/Domain/Models/Admin.php",
    "src/DATA_PERSISTENCE_LAYER/models/Auditlog.php": "src/Domain/Models/AuditLog.php",
    "src/DATA_PERSISTENCE_LAYER/models/ComplianceFlag.php": "src/Domain/Models/ComplianceFlag.php",
    "src/DATA_PERSISTENCE_LAYER/models/Permission.php": "src/Domain/Models/Permission.php",
    "src/DATA_PERSISTENCE_LAYER/models/Role.php": "src/Domain/Models/Role.php",
    "src/DATA_PERSISTENCE_LAYER/models/SwapTransaction.php": "src/Domain/Models/SwapTransaction.php",
    "src/DATA_PERSISTENCE_LAYER/models/Transaction.php": "src/Domain/Models/Transaction.php",
    "src/DATA_PERSISTENCE_LAYER/models/User.php": "src/Domain/Models/User.php",
    "src/DATA_PERSISTENCE_LAYER/models/migrations/vouchmorph_schema.sql": "database/migrations/schema.sql",
    "src/DATA_PERSISTENCE_LAYER/repositories/UserRepository.php": "src/Domain/Repositories/UserRepository.php",
    
    # DFSP_ADAPTER_LAYER -> Infrastructure/Mojaloop
    "src/DFSP_ADAPTER_LAYER/FspiopHeaderValidator.php": "src/Infrastructure/Mojaloop/FspiopHeaderValidator.php",
    "src/DFSP_ADAPTER_LAYER/IdempotencyService.php": "src/Infrastructure/Mojaloop/IdempotencyService.php",
    "src/DFSP_ADAPTER_LAYER/MojaloopAdapter.php": "src/Infrastructure/Mojaloop/Adapter.php",
    "src/DFSP_ADAPTER_LAYER/MojaloopErrorMapper.php": "src/Infrastructure/Mojaloop/ErrorMapper.php",
    "src/DFSP_ADAPTER_LAYER/MojaloopHttpClient.php": "src/Infrastructure/Mojaloop/HttpClient.php",
    "src/DFSP_ADAPTER_LAYER/MojaloopRequestParser.php": "src/Infrastructure/Mojaloop/RequestParser.php",
    "src/DFSP_ADAPTER_LAYER/MojaloopResponseBuilder.php": "src/Infrastructure/Mojaloop/ResponseBuilder.php",
    "src/DFSP_ADAPTER_LAYER/MojaloopRouter.php": "src/Infrastructure/Mojaloop/Router.php",
    "src/DFSP_ADAPTER_LAYER/dto/PartyLookupRequest.php": "src/Infrastructure/Mojaloop/DTO/PartyLookupRequest.php",
    "src/DFSP_ADAPTER_LAYER/dto/QouteRequest.php": "src/Infrastructure/Mojaloop/DTO/QuoteRequest.php",
    "src/DFSP_ADAPTER_LAYER/dto/TransferRequest.php": "src/Infrastructure/Mojaloop/DTO/TransferRequest.php",
    "src/DFSP_ADAPTER_LAYER/mapper/ErrorMapper.php": "src/Infrastructure/Mojaloop/Mappers/ErrorMapper.php",
    "src/DFSP_ADAPTER_LAYER/mapper/ResponseMapper.php": "src/Infrastructure/Mojaloop/Mappers/ResponseMapper.php",
    "src/DFSP_ADAPTER_LAYER/mojaloop/health.php": "public/api/mojaloop/health.php",
    "src/DFSP_ADAPTER_LAYER/mojaloop/participants.php": "public/api/mojaloop/participants.php",
    "src/DFSP_ADAPTER_LAYER/mojaloop/parties.php": "public/api/mojaloop/parties.php",
    "src/DFSP_ADAPTER_LAYER/mojaloop/quotes.php": "public/api/mojaloop/quotes.php",
    "src/DFSP_ADAPTER_LAYER/mojaloop/transfers.php": "public/api/mojaloop/transfers.php",
    
    # FACTORY_LAYER -> Core/Factories
    "src/FACTORY_LAYER/CommunicationFactory.php": "src/Core/Factories/CommunicationFactory.php",
    "src/FACTORY_LAYER/ReportingFactory.php": "src/Core/Factories/ReportingFactory.php",
    
    # INTEGRATION_LAYER -> Infrastructure
    "src/INTEGRATION_LAYER/CLIENTS/BankClients/BankFactory.php": "src/Infrastructure/Banks/BankFactory.php",
    "src/INTEGRATION_LAYER/CLIENTS/BankClients/GenericBankClient.php": "src/Infrastructure/Banks/GenericBankClient.php",
    "src/INTEGRATION_LAYER/CLIENTS/CardSchemes/CardNumberGenerator.php": "src/Infrastructure/Cards/CardNumberGenerator.php",
    "src/INTEGRATION_LAYER/CLIENTS/CommunicationClients/SmsGatewayClient.php": "src/Infrastructure/SMS/SmsGatewayClient.php",
    "src/INTEGRATION_LAYER/CLIENTS/ReportingClients/FATFClient.php": "src/Infrastructure/Reporting/FATFClient.php",
    "src/INTEGRATION_LAYER/CLIENTS/ReportingClients/LocalRegulatorClient.php": "src/Infrastructure/Reporting/LocalRegulatorClient.php",
    "src/INTEGRATION_LAYER/INTERFACES/BankAPIInterface.php": "src/Infrastructure/Banks/Contracts/BankAPIInterface.php",
    "src/INTEGRATION_LAYER/INTERFACES/CommunicationProviderInterface.php": "src/Infrastructure/SMS/Contracts/ProviderInterface.php",
    "src/INTEGRATION_LAYER/INTERFACES/ReportingProviderInterface.php": "src/Infrastructure/Reporting/Contracts/ProviderInterface.php",
    
    # SCHEME_LAYER -> Core/Schemes
    "src/SCHEME_LAYER/FlowValidator.php": "src/Core/Schemes/FlowValidator.php",
    
    # SECURITY_LAYER -> Security
    "src/SECURITY_LAYER/Auth/JwtAuth.php": "src/Security/Auth/JwtAuth.php",
    "src/SECURITY_LAYER/Auth/MultifactorAuth.php": "src/Security/Auth/MultifactorAuth.php",
    "src/SECURITY_LAYER/Encryption/KeyVault.php": "src/Security/Encryption/KeyVault.php",
    "src/SECURITY_LAYER/Encryption/SecretManagerClient.php": "src/Security/Encryption/SecretManagerClient.php",
    "src/SECURITY_LAYER/Encryption/TokenEncryptor.php": "src/Security/Encryption/TokenEncryptor.php",
    "src/SECURITY_LAYER/Monitoring/ApiRateLimiter.php": "src/Security/Monitoring/ApiRateLimiter.php",
    "src/SECURITY_LAYER/Monitoring/IntrussionDetection.php": "src/Security/Monitoring/IntrusionDetection.php",
    "src/SECURITY_LAYER/Monitoring/MonitoringService.php": "src/Security/Monitoring/MonitoringService.php",
    "src/SECURITY_LAYER/Monitoring/ThreatMonitor.php": "src/Security/Monitoring/ThreatMonitor.php",
    
    # SYSTEM_DAEMONS -> scripts/daemons
    "src/SYSTEM_DAEMONS/mojaloop_callback_worker.php": "scripts/daemons/mojaloop-callback-worker.php",
    "src/SYSTEM_DAEMONS/outbox_worker.php": "scripts/daemons/outbox-worker.php",
    
    # TESTS -> tests/
    "src/TESTS/certification/test.php": "tests/Certification/CertificationTest.php",
    "src/TESTS/compliance/OTPCompliancetest.php": "tests/Compliance/OTPComplianceTest.php",
    "src/TESTS/integration/Bank_Integration_Test.php": "tests/Integration/BankIntegrationTest.php",
    "src/TESTS/integration/USSDIntegrationTest.php": "tests/Integration/USSDIntegrationTest.php",
    "src/TESTS/performance/HighVolumeTest.php": "tests/Performance/HighVolumeTest.php",
    "src/TESTS/performance/TestCardSystem.php": "tests/Performance/CardSystemTest.php",
    "src/TESTS/performance/worker_process.php": "tests/Performance/WorkerProcess.php",
    "src/TESTS/security/authenticationtest.php": "tests/Security/AuthenticationTest.php",
    "src/TESTS/security/loginstresstest.php": "tests/Security/LoginStressTest.php",
    "src/TESTS/system/final_sandbox_evaluation.txt": "tests/System/SandboxEvaluation.txt",
    "src/TESTS/system/licence_runtime_test.php": "tests/System/LicenceRuntimeTest.php",
    "src/TESTS/system/sandbox_certificate_test.php": "tests/System/SandboxCertificateTest.php",
    "src/TESTS/system/test_swapservice_integrity.php": "tests/System/SwapServiceIntegrityTest.php",
    "src/TESTS/system/toughsandbox.php": "tests/System/ToughSandbox.php",
    "src/TESTS/system_e2e_test.php": "tests/EndToEnd/SystemE2ETest.php",
    "src/TESTS/unit/LicenceEnforcementTest.php": "tests/Unit/LicenceEnforcementTest.php",
    "src/TESTS/unit/swapservicetest.php": "tests/Unit/SwapServiceTest.php",
    
    # Misc
    "src/bootstrap.php": "src/bootstrap.php",
    "src/cron/process_expired.php": "scripts/cron/process-expired.php",
    
    # Documentation
    "src/LICENCE AND DOCS/API_REFERENCE.json": "docs/api/reference.json",
    "src/LICENCE AND DOCS/COMPLIANCE_GUIDE.md": "docs/compliance/guide.md",
    "src/LICENCE AND DOCS/Licence_template.md": "docs/licence/template.md",
    "src/LICENCE AND DOCS/Operational_Security_Policy.pdf": "docs/security/operational-policy.pdf",
    
    # Management scripts
    "src/MANAGEMENT/backup.php": "scripts/management/backup.php",
    "src/MANAGEMENT/deploy_update.php": "scripts/management/deploy.php",
    "src/MANAGEMENT/licence_fee_monitor.php": "scripts/management/licence-monitor.php",
}

# Files to DELETE before Visa visit
FILES_TO_DELETE = [
    "PDO::ERRMODE_EXCEPTION,",
    "PDO::FETCH_ASSOC",
    "debug_connection.php",
    "debug_env.php",
    "test.php",
    "testing.php",
    "src/test.php",
    "public/api/MojaloopAdapter_debug.php",
    "public/api/minimal.php",
    "public/api/simple.php",
    "public/api/test.php",
    "public/api/test_callback.php",
    "src/ADMIN_LAYER/api/debug_saccussalis.log",
    "src/ADMIN_LAYER/api/debug_zurubank.log",
    "src/APP_LAYER/logs/*.log",
    "src/BUSINESS_LOGIC_LAYER/services/cookie.txt",
    "src/BUSINESS_LOGIC_LAYER/services/*.log",
    "src/CORE_CONFIG/countries/BW/.env_BW.backup",
    "src/CORE_CONFIG/countries/BW/.env_BW.save",
    "src/DATA_PERSISTENCE_LAYER/config/DBConnection.php.backup",
    "src/DATA_PERSISTENCE_LAYER/config/DBConnection.php.bak",
    "src/DATA_PERSISTENCE_LAYER/config/test.php",
    "src/DATA_PERSISTENCE_LAYER/models/Intrusion.log",
    "src/DATA_PERSISTENCE_LAYER/models/ThreatAlerts.log",
    "public/api/callback/ttk_debug.log",
    "public/control/logs/compliance_audit.log",
    "src/logs/fetch_bank_balances_debug.log",
]

def create_directory_structure():
    """Create all needed directories"""
    dirs = [
        "src/Core/Database",
        "src/Core/Cache",
        "src/Core/Config/Countries/Botswana",
        "src/Core/Config/Countries/Nigeria",
        "src/Core/Factories",
        "src/Core/Schemes",
        "src/Domain/Models",
        "src/Domain/Services/Settlement",
        "src/Domain/Repositories",
        "src/Domain/ValueObjects",
        "src/Domain/Helpers",
        "src/Application/Controllers",
        "src/Application/Middleware",
        "src/Application/Utils",
        "src/Application/Admin/Auth",
        "src/Application/Admin/Middleware",
        "src/Application/Admin/Api",
        "src/Application/Admin/Tools",
        "src/Application/Admin/Modules",
        "src/Application/Handlers",
        "src/Infrastructure/Mojaloop/DTO",
        "src/Infrastructure/Mojaloop/Mappers",
        "src/Infrastructure/Banks/Contracts",
        "src/Infrastructure/SMS/Contracts",
        "src/Infrastructure/Reporting/Contracts",
        "src/Infrastructure/Secrets",
        "src/Infrastructure/Cards",
        "src/Security/Auth",
        "src/Security/Encryption",
        "src/Security/Monitoring",
        "tests/Unit",
        "tests/Integration",
        "tests/Performance",
        "tests/Security",
        "tests/System",
        "tests/EndToEnd",
        "tests/Certification/results",
        "tests/Compliance",
        "config/countries/botswana",
        "config/countries/nigeria",
        "config/licences",
        "database/migrations",
        "database/seeds",
        "scripts/cron",
        "scripts/daemons",
        "scripts/management",
        "docs/api",
        "docs/compliance",
        "docs/licence",
        "docs/security",
        "storage/logs",
        "storage/cache",
        "storage/sessions",
        "storage/uploads/kyc",
        "public/assets/css",
        "public/assets/js",
        "public/assets/images",
        "public/partials",
        "public/api/mojaloop",
        "public/api/ussd",
        "public/api/waitlist",
        "public/admin/reports",
        "public/admin/views",
        "public/admin/api",
        "public/user/demo",
        "backups",
    ]
    
    for d in dirs:
        (PROJECT_ROOT / d).mkdir(parents=True, exist_ok=True)
        print(f"📁 Created: {d}")

def delete_unwanted_files():
    """Delete files that shouldn't be shown to Visa"""
    for pattern in FILES_TO_DELETE:
        if '*' in pattern:
            for f in PROJECT_ROOT.glob(pattern):
                if f.exists():
                    f.unlink()
                    print(f"🗑️ Deleted: {f}")
        else:
            f = PROJECT_ROOT / pattern
            if f.exists():
                f.unlink()
                print(f"🗑️ Deleted: {f}")

def update_php_paths(file_path):
    """Update PHP file paths after move"""
    if not file_path.suffix == '.php':
        return
    
    with open(file_path, 'r', encoding='utf-8', errors='ignore') as f:
        content = f.read()
    
    original = content
    
    # Replace old path references
    replacements = [
        (r"src/ADMIN_LAYER/", "src/Application/Admin/"),
        (r"src/APP_LAYER/", "src/Application/"),
        (r"src/BUSINESS_LOGIC_LAYER/", "src/Domain/"),
        (r"src/CORE_CONFIG/", "src/Core/Config/"),
        (r"src/DATA_PERSISTENCE_LAYER/", "src/Core/Database/"),
        (r"src/DFSP_ADAPTER_LAYER/", "src/Infrastructure/Mojaloop/"),
        (r"src/FACTORY_LAYER/", "src/Core/Factories/"),
        (r"src/INTEGRATION_LAYER/", "src/Infrastructure/"),
        (r"src/SECURITY_LAYER/", "src/Security/"),
        (r"src/SCHEME_LAYER/", "src/Core/Schemes/"),
        (r"src/SYSTEM_DAEMONS/", "scripts/daemons/"),
        (r"src/TESTS/", "tests/"),
        (r"src/MANAGEMENT/", "scripts/management/"),
    ]
    
    for old, new in replacements:
        content = content.replace(old, new)
    
    # Add bootstrap if not present
    if 'PROJECT_ROOT' not in content and 'src/bootstrap.php' not in content:
        if content.startswith('<?php'):
            content = content.replace('<?php', "<?php\n\nrequire_once dirname(__DIR__, 2) . '/src/bootstrap.php';\n")
    
    if content != original:
        with open(file_path, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f"✓ Updated paths: {file_path.name}")

def migrate_file(src, dst):
    """Move a single file"""
    if dst == "TO_DELETE":
        if src.exists():
            src.unlink()
            print(f"🗑️ Deleted: {src}")
        return
    
    dst_path = PROJECT_ROOT / dst
    dst_path.parent.mkdir(parents=True, exist_ok=True)
    
    if src.exists():
        shutil.copy2(src, dst_path)
        if dst_path.suffix == '.php':
            update_php_paths(dst_path)
        print(f"📦 Migrated: {src.name} -> {dst}")
    else:
        print(f"⚠️ Source not found: {src}")

def main():
    print("🚀 Starting complete migration of 373 files...\n")
    
    # Create structure
    create_directory_structure()
    
    # Delete unwanted files first
    delete_unwanted_files()
    
    # Migrate all files
    for src_rel, dst_rel in MIGRATION_MAP.items():
        src = PROJECT_ROOT / src_rel
        migrate_file(src, dst_rel)
    
    # Create .gitignore for storage
    gitignore_content = """# Storage directory
storage/logs/*.log
storage/cache/*
storage/sessions/*
storage/uploads/temp/*

# Environment files
.env
.env.*.local

# Debug files
debug_*.php
*_debug.log

# Test files
test*.php
*_test.php

# Backups
*.backup
*.bak
*.tar
backups/
"""
    with open(PROJECT_ROOT / 'storage/.gitignore', 'w') as f:
        f.write(gitignore_content)
    
    print("\n" + "="*60)
    print("✅ MIGRATION COMPLETE!")
    print("="*60)
    print("\n📋 Next steps:")
    print("1. Run: composer dump-autoload")
    print("2. Update your web server document root to 'public/'")
    print("3. Create storage/ directory with write permissions")
    print("4. Update .env file with new paths")
    print("5. Test: php public/index.php")
    print("\n⚠️ Files marked for deletion have been removed.")
    print("   These won't be shown to the Visa team.")

if __name__ == '__main__':
    main()
