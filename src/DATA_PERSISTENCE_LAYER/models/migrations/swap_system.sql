-- =========================================================
-- SWAP SYSTEM CLEAN SCHEMA FOR DEPLOYMENT
-- =========================================================
-- This schema is organized for clarity and maintainability
-- =========================================================

-- Drop existing objects (use with caution in production!)
DROP SCHEMA IF EXISTS public CASCADE;
CREATE SCHEMA public;

-- =========================================================
-- ENUMS
-- =========================================================
CREATE TYPE swap_status AS ENUM ('pending', 'processing', 'completed', 'failed', 'cancelled');
CREATE TYPE fraud_status AS ENUM ('unchecked', 'passed', 'failed', 'manual_review');
CREATE TYPE kyc_status AS ENUM ('pending', 'approved', 'rejected', 'expired');
CREATE TYPE ledger_type AS ENUM ('customer', 'escrow', 'treasury', 'fee', 'settlement');
CREATE TYPE participant_type AS ENUM ('FINANCIAL_INSTITUTION', 'MOBILE_MONEY_OPERATOR', 'CARD_DISTRIBUTOR', 'TECHNICAL_PROVIDER');
CREATE TYPE participant_category AS ENUM ('BANK', 'MNO', 'EMI_CARD', 'PAYMENT_PROCESSOR');
CREATE TYPE transaction_direction AS ENUM ('inbound', 'outbound', 'internal');
CREATE TYPE notification_status AS ENUM ('pending', 'acknowledged', 'failed');

-- =========================================================
-- TIMESTAMP UPDATE FUNCTION
-- =========================================================
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- =========================================================
-- SECTION 1: IDENTITY & ACCESS
-- =========================================================

-- 1.1 Roles
CREATE TABLE roles (
    role_id BIGSERIAL PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    permissions JSONB DEFAULT '[]'::jsonb,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT valid_role_name CHECK (role_name IN ('user', 'admin', 'compliance', 'auditor', 'super_admin'))
);

-- 1.2 Users
CREATE TABLE users (
    user_id BIGSERIAL PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(20) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role_id BIGINT REFERENCES roles(role_id) DEFAULT 1,
    verified BOOLEAN DEFAULT FALSE,
    kyc_verified BOOLEAN DEFAULT FALSE,
    aml_score NUMERIC(5,2) DEFAULT 0,
    mfa_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 1.3 Admins
CREATE TABLE admins (
    admin_id BIGSERIAL PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(20),
    password_hash VARCHAR(255) NOT NULL,
    role_id BIGINT REFERENCES roles(role_id),
    mfa_enabled BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- =========================================================
-- SECTION 2: PARTICIPANTS & CONFIGURATION
-- =========================================================

-- 2.1 Participants (Banks, MNOs, Distributors)
CREATE TABLE participants (
    participant_id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    type participant_type NOT NULL,
    category participant_category NOT NULL,
    provider_code VARCHAR(50),
    auth_type VARCHAR(50),
    base_url TEXT,
    system_user_id BIGINT,
    legal_entity_identifier VARCHAR(50),
    license_number VARCHAR(50),
    settlement_account VARCHAR(50),
    settlement_type VARCHAR(50),
    status VARCHAR(20) DEFAULT 'ACTIVE',
    
    -- JSON configurations
    capabilities JSONB,
    resource_endpoints JSONB,
    phone_format JSONB,
    security_config JSONB,
    message_profile JSONB,
    routing_info JSONB,
    metadata JSONB,
    
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 2.2 Participant Fee Overrides
CREATE TABLE participant_fee_overrides (
    override_id BIGSERIAL PRIMARY KEY,
    participant_id BIGINT REFERENCES participants(participant_id) ON DELETE CASCADE,
    transaction_type VARCHAR(20) NOT NULL, -- 'CASHOUT', 'DEPOSIT', 'TRANSFER'
    fee_amount NUMERIC(12,2) NOT NULL,
    split_config JSONB,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(participant_id, transaction_type)
);

-- =========================================================
-- SECTION 3: TRANSACTION MANAGEMENT
-- =========================================================

-- 3.1 Transaction Fees (Standard rates)
CREATE TABLE transaction_fees (
    fee_id BIGSERIAL PRIMARY KEY,
    transaction_type VARCHAR(20) NOT NULL UNIQUE, -- 'CASHOUT', 'DEPOSIT'
    amount NUMERIC(12,2) NOT NULL,
    currency VARCHAR(5) DEFAULT 'BWP',
    split_config JSONB, -- e.g., {"source_participant":2, "vouchmorph":4, "destination_participant":4}
    taxable BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- Insert default fees
INSERT INTO transaction_fees (transaction_type, amount, split_config) VALUES
('CASHOUT', 10.00, '{"source_participant":2, "vouchmorph":4, "destination_participant":4}'::jsonb),
('DEPOSIT', 6.00, '{"source_participant":1.2, "vouchmorph":2.4, "destination_participant":2.4}'::jsonb);

-- 3.2 Swap Requests (Main transaction table)
CREATE TABLE swap_requests (
    swap_id BIGSERIAL PRIMARY KEY,
    swap_uuid VARCHAR(100) UNIQUE NOT NULL DEFAULT gen_random_uuid()::text,
    user_id BIGINT REFERENCES users(user_id) NOT NULL,
    from_currency CHAR(3) NOT NULL,
    to_currency CHAR(3) NOT NULL,
    amount NUMERIC(20,8) NOT NULL,
    converted_amount NUMERIC(20,8),
    exchange_rate NUMERIC(20,8) DEFAULT 1,
    fee_amount NUMERIC(20,8) DEFAULT 0,
    total_amount NUMERIC(20,8),
    status swap_status DEFAULT 'pending',
    fraud_check_status fraud_status DEFAULT 'unchecked',
    processor_reference VARCHAR(255),
    metadata JSONB DEFAULT '{}'::jsonb,
    completed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 3.3 Hold Transactions (Funds reservation)
CREATE TABLE hold_transactions (
    hold_id BIGSERIAL PRIMARY KEY,
    hold_reference VARCHAR(100) UNIQUE NOT NULL,
    swap_reference VARCHAR(100) REFERENCES swap_requests(swap_uuid),
    participant_id BIGINT REFERENCES participants(participant_id),
    participant_name VARCHAR(100),
    asset_type VARCHAR(50) NOT NULL,
    amount NUMERIC(20,8) NOT NULL,
    currency CHAR(3) DEFAULT 'BWP',
    status VARCHAR(20) DEFAULT 'ACTIVE', -- 'ACTIVE', 'RELEASED', 'DEBITED', 'EXPIRED'
    hold_expiry TIMESTAMPTZ,
    source_details JSONB,
    destination_institution VARCHAR(100),
    destination_participant_id BIGINT REFERENCES participants(participant_id),
    metadata JSONB,
    placed_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    released_at TIMESTAMPTZ,
    debited_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- =========================================================
-- SECTION 4: LEDGER & ACCOUNTING
-- =========================================================

-- 4.1 Ledger Accounts
CREATE TABLE ledger_accounts (
    account_id BIGSERIAL PRIMARY KEY,
    account_code VARCHAR(20) UNIQUE,
    account_name VARCHAR(100) NOT NULL,
    account_type ledger_type NOT NULL,
    balance NUMERIC(20,8) DEFAULT 0,
    participant_id BIGINT REFERENCES participants(participant_id),
    currency_code CHAR(3) DEFAULT 'BWP',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 4.2 Transactions
CREATE TABLE transactions (
    transaction_id BIGSERIAL PRIMARY KEY,
    transaction_reference VARCHAR(100) UNIQUE DEFAULT gen_random_uuid()::text,
    user_id BIGINT REFERENCES users(user_id),
    origin_participant_id BIGINT REFERENCES participants(participant_id),
    destination_participant_id BIGINT REFERENCES participants(participant_id),
    origin_name VARCHAR(100),
    destination_name VARCHAR(100),
    transaction_type VARCHAR(50) NOT NULL,
    amount NUMERIC(20,8) DEFAULT 0,
    fee NUMERIC(20,8) DEFAULT 0,
    currency_code CHAR(3) DEFAULT 'BWP',
    status VARCHAR(20) DEFAULT 'PENDING',
    sca_required BOOLEAN DEFAULT FALSE,
    sca_verified_at TIMESTAMPTZ,
    completed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 4.3 Ledger Entries (Double-entry accounting)
CREATE TABLE ledger_entries (
    entry_id BIGSERIAL PRIMARY KEY,
    transaction_id BIGINT REFERENCES transactions(transaction_id),
    debit_account_id BIGINT REFERENCES ledger_accounts(account_id),
    credit_account_id BIGINT REFERENCES ledger_accounts(account_id),
    amount NUMERIC(20,8) NOT NULL,
    currency_code CHAR(3) DEFAULT 'BWP',
    reference VARCHAR(50),
    entry_type VARCHAR(50) DEFAULT 'main',
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 4.4 Swap Fee Collections (For later settlement)
CREATE TABLE swap_fee_collections (
    fee_id BIGSERIAL PRIMARY KEY,
    swap_reference VARCHAR(100) REFERENCES swap_requests(swap_uuid),
    fee_type VARCHAR(20) NOT NULL, -- 'CASHOUT_SWAP_FEE', 'DEPOSIT_SWAP_FEE'
    total_amount NUMERIC(20,8) NOT NULL,
    currency CHAR(3) DEFAULT 'BWP',
    source_institution VARCHAR(100) NOT NULL,
    destination_institution VARCHAR(100) NOT NULL,
    split_config JSONB NOT NULL,
    vat_amount NUMERIC(20,8) DEFAULT 0,
    status VARCHAR(20) DEFAULT 'COLLECTED', -- 'COLLECTED', 'SETTLED'
    collected_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    settled_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- =========================================================
-- SECTION 5: SWAP-SPECIFIC TABLES
-- =========================================================

-- 5.1 Swap Vouchers (For cashouts)
CREATE TABLE swap_vouchers (
    voucher_id BIGSERIAL PRIMARY KEY,
    swap_id BIGINT REFERENCES swap_requests(swap_id),
    code_hash VARCHAR(255) NOT NULL,
    code_suffix CHAR(4),
    amount NUMERIC(20,8) NOT NULL,
    expiry_at TIMESTAMPTZ NOT NULL,
    status VARCHAR(20) DEFAULT 'ACTIVE', -- 'ACTIVE', 'USED', 'EXPIRED', 'VOIDED'
    redeemed_at TIMESTAMPTZ,
    redeemed_at_participant_id BIGINT REFERENCES participants(participant_id),
    claimant_phone VARCHAR(20),
    is_cardless_redemption BOOLEAN DEFAULT TRUE,
    attempts INT DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 5.2 Swap Ledgers (External transaction records)
CREATE TABLE swap_ledgers (
    ledger_id BIGSERIAL PRIMARY KEY,
    swap_reference VARCHAR(100) UNIQUE REFERENCES swap_requests(swap_uuid),
    swap_id BIGINT REFERENCES swap_requests(swap_id),
    from_institution VARCHAR(100),
    to_institution VARCHAR(100),
    from_account VARCHAR(100),
    to_account VARCHAR(100),
    amount NUMERIC(20,8) NOT NULL,
    currency_code CHAR(3),
    swap_fee NUMERIC(20,8) DEFAULT 0,
    direction transaction_direction,
    notes TEXT,
    status swap_status DEFAULT 'pending',
    external_reference VARCHAR(255),
    settled_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- =========================================================
-- SECTION 6: TRANSACTION TYPES (SIMPLIFIED)
-- =========================================================

-- 6.1 Deposit Transactions (Self-to-self)
CREATE TABLE deposit_transactions (
    deposit_id BIGSERIAL PRIMARY KEY,
    transaction_reference VARCHAR(100) UNIQUE NOT NULL,
    client_phone VARCHAR(20) NOT NULL,
    source_type VARCHAR(50) NOT NULL, -- 'ewallet', 'bank_account', 'voucher'
    source_institution VARCHAR(100) NOT NULL,
    source_account VARCHAR(100) NOT NULL,
    destination_type VARCHAR(50) NOT NULL, -- 'ewallet', 'bank_account', 'same_wallet'
    destination_institution VARCHAR(100),
    destination_account VARCHAR(100),
    amount NUMERIC(20,2) NOT NULL CHECK (amount > 0),
    currency CHAR(3) NOT NULL DEFAULT 'BWP',
    fee_amount NUMERIC(20,2) DEFAULT 0.00,
    status VARCHAR(50) NOT NULL DEFAULT 'PENDING',
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    completed_at TIMESTAMPTZ,
    metadata JSONB DEFAULT '{}'::jsonb
);

-- 6.2 Cashout Authorizations
CREATE TABLE cashout_authorizations (
    auth_id BIGSERIAL PRIMARY KEY,
    swap_reference VARCHAR(100) UNIQUE NOT NULL,
    client_phone VARCHAR(20) NOT NULL,
    source_institution VARCHAR(100) NOT NULL,
    source_wallet VARCHAR(100) NOT NULL,
    amount NUMERIC(20,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'BWP',
    fee_amount NUMERIC(20,2) DEFAULT 0.00,
    swap_code VARCHAR(50) UNIQUE,
    pin_code VARCHAR(10),
    code_expiry TIMESTAMPTZ,
    cashout_point VARCHAR(50) NOT NULL, -- 'ATM', 'AGENT', 'BRANCH'
    cashout_provider VARCHAR(100),
    status VARCHAR(50) NOT NULL DEFAULT 'PENDING',
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    completed_at TIMESTAMPTZ,
    code_used_at TIMESTAMPTZ,
    metadata JSONB DEFAULT '{}'::jsonb
);

-- 6.3 Send-to-Other Transactions
CREATE TABLE send_to_other_transactions (
    send_id BIGSERIAL PRIMARY KEY,
    transaction_reference VARCHAR(100) UNIQUE NOT NULL,
    sender_phone VARCHAR(20) NOT NULL,
    sender_institution VARCHAR(100) NOT NULL,
    sender_account VARCHAR(100) NOT NULL,
    receiver_phone VARCHAR(20) NOT NULL,
    receiver_institution VARCHAR(100) NOT NULL,
    receiver_account VARCHAR(100) NOT NULL,
    amount NUMERIC(20,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'BWP',
    fee_amount NUMERIC(20,2) DEFAULT 0.00,
    status VARCHAR(50) NOT NULL DEFAULT 'PENDING',
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    completed_at TIMESTAMPTZ,
    notification_sent BOOLEAN DEFAULT FALSE,
    metadata JSONB DEFAULT '{}'::jsonb
);

-- =========================================================
-- SECTION 7: COMPLIANCE & SECURITY
-- =========================================================

-- 7.1 KYC Documents
CREATE TABLE kyc_documents (
    kyc_id BIGSERIAL PRIMARY KEY,
    user_id BIGINT REFERENCES users(user_id),
    document_type VARCHAR(50) CHECK (document_type IN ('passport', 'national_id', 'drivers_license', 'utility_bill', 'bank_statement')),
    document_number VARCHAR(100),
    status kyc_status DEFAULT 'pending',
    document_path VARCHAR(255),
    document_hash VARCHAR(255),
    expiry_date DATE,
    admin_reviewer_id BIGINT REFERENCES admins(admin_id),
    review_date TIMESTAMPTZ,
    review_notes TEXT,
    metadata JSONB DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 7.2 AML Checks
CREATE TABLE aml_checks (
    check_id BIGSERIAL PRIMARY KEY,
    user_id BIGINT REFERENCES users(user_id),
    check_type VARCHAR(50),
    check_reference VARCHAR(255),
    risk_score NUMERIC(5,2),
    status VARCHAR(20) DEFAULT 'pending',
    findings JSONB,
    performed_by VARCHAR(100),
    performed_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    expiry_date TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 7.3 OTP Logs
CREATE TABLE otp_logs (
    otp_id BIGSERIAL PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL,
    identifier_type VARCHAR(20) CHECK (identifier_type IN ('phone', 'email', 'user_id')),
    code_hash VARCHAR(255) NOT NULL,
    purpose VARCHAR(50) CHECK (purpose IN ('login', 'transaction', 'kyc', 'password_reset', 'phone_verification')),
    expires_at TIMESTAMPTZ NOT NULL,
    used_at TIMESTAMPTZ,
    attempts INT DEFAULT 0,
    ip_address INET,
    user_agent TEXT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- =========================================================
-- SECTION 8: MESSAGING & INTEGRATION
-- =========================================================

-- 8.1 Message Outbox (For async processing)
CREATE TABLE message_outbox (
    id SERIAL PRIMARY KEY,
    message_id TEXT UNIQUE NOT NULL,
    channel VARCHAR(50) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    payload JSONB NOT NULL,
    status TEXT NOT NULL,
    attempts INT DEFAULT 0,
    last_error TEXT,
    last_attempt TIMESTAMPTZ,
    processed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- 8.2 Regulator Outbox (For regulatory reporting)
CREATE TABLE regulator_outbox (
    id SERIAL PRIMARY KEY,
    report_id TEXT UNIQUE NOT NULL,
    payload JSONB NOT NULL,
    integrity_hash TEXT NOT NULL,
    status TEXT NOT NULL,
    attempts INT DEFAULT 0,
    last_attempt TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- 8.3 Settlement Messages
CREATE TABLE settlement_messages (
    message_id BIGSERIAL PRIMARY KEY,
    transaction_id VARCHAR(50),
    from_participant VARCHAR(50),
    to_participant VARCHAR(50),
    amount NUMERIC(12,2),
    type VARCHAR(50), -- 'INITIATION', 'FEE', 'ACK'
    status VARCHAR(20) DEFAULT 'PENDING',
    attempts INT DEFAULT 0,
    last_error TEXT,
    processed_at TIMESTAMPTZ,
    last_attempt TIMESTAMPTZ,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    metadata JSONB
);

-- 8.4 Settlement Queue
CREATE TABLE settlement_queue (
    id BIGSERIAL PRIMARY KEY,
    debtor VARCHAR(100) NOT NULL,
    creditor VARCHAR(100) NOT NULL,
    amount NUMERIC(20,8) DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(debtor, creditor)
);

-- 8.5 VouchMorph Notifications
CREATE TABLE vouchmorph_notifications (
    id SERIAL PRIMARY KEY,
    swap_number VARCHAR(255) NOT NULL,
    swap_pin VARCHAR(255) NOT NULL,
    amount NUMERIC(20,4) NOT NULL,
    user_phone VARCHAR(20),
    destination_bank_id INT NOT NULL,
    status VARCHAR(50) DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    acknowledged_at TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 8.6 API Message Logs
CREATE TABLE api_message_logs (
    log_id BIGSERIAL PRIMARY KEY,
    message_id VARCHAR(100) NOT NULL,
    message_type VARCHAR(50) NOT NULL,
    direction VARCHAR(10) NOT NULL, -- 'outgoing', 'incoming'
    participant_id BIGINT REFERENCES participants(participant_id),
    participant_name VARCHAR(100),
    endpoint VARCHAR(255),
    request_payload JSONB,
    response_payload JSONB,
    http_status_code INT,
    curl_error TEXT,
    success BOOLEAN DEFAULT FALSE,
    duration_ms INT,
    retry_count INT DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMPTZ,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- =========================================================
-- SECTION 9: AUDIT & MONITORING
-- =========================================================

-- 9.1 Audit Logs
CREATE TABLE audit_logs (
    audit_id BIGSERIAL PRIMARY KEY,
    audit_uuid UUID DEFAULT gen_random_uuid() UNIQUE,
    entity_type VARCHAR(50),
    entity_id BIGINT,
    action VARCHAR(50),
    category VARCHAR(50),
    severity VARCHAR(20) CHECK (severity IN ('info', 'warning', 'error', 'critical')) DEFAULT 'info',
    old_value JSONB,
    new_value JSONB,
    changes JSONB,
    performed_by_type VARCHAR(20) CHECK (performed_by_type IN ('user', 'admin', 'system')),
    performed_by_id BIGINT,
    ip_address INET,
    user_agent TEXT,
    geo_location JSONB,
    request_id VARCHAR(100),
    performed_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- 9.2 Supervisory Heartbeat
CREATE TABLE supervisory_heartbeat (
    heartbeat_id SERIAL PRIMARY KEY,
    status VARCHAR(20) DEFAULT 'ACTIVE',
    latency_ms INT DEFAULT 0,
    system_load NUMERIC(5,2) DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- Insert initial heartbeat
INSERT INTO supervisory_heartbeat (status) VALUES ('ACTIVE');

-- =========================================================
-- SECTION 10: SANDBOX & TESTING
-- =========================================================

CREATE TABLE sandbox_disclosures (
    id SERIAL PRIMARY KEY,
    user_id BIGINT REFERENCES users(user_id),
    consent_version VARCHAR(10),
    has_accepted BOOLEAN DEFAULT FALSE,
    disclosure_text TEXT,
    experimental_risk_acknowledged_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- =========================================================
-- SECTION 11: VIEWS
-- =========================================================

-- 11.1 Transaction Log View
CREATE OR REPLACE VIEW transaction_log_view AS
SELECT 
    COALESCE(ht.swap_reference, aml.message_id) as transaction_id,
    ht.hold_reference,
    ht.status as hold_status,
    ht.placed_at as hold_placed_at,
    ht.debited_at,
    ht.amount as hold_amount,
    ht.asset_type,
    p.name as participant_name,
    p.provider_code,
    p.type as participant_type,
    aml.message_type,
    aml.success as api_success,
    aml.http_status_code,
    aml.created_at as api_called_at,
    aml.endpoint,
    aml.direction
FROM hold_transactions ht
FULL OUTER JOIN api_message_logs aml ON ht.swap_reference = aml.message_id
LEFT JOIN participants p ON COALESCE(ht.participant_id, aml.participant_id) = p.participant_id;

-- =========================================================
-- SECTION 12: INDEXES (Performance)
-- =========================================================

-- Participants indexes
CREATE INDEX idx_participants_status ON participants(status);
CREATE INDEX idx_participants_type ON participants(type);

-- Swap requests indexes
CREATE INDEX idx_swap_requests_user_id ON swap_requests(user_id);
CREATE INDEX idx_swap_requests_status ON swap_requests(status);
CREATE INDEX idx_swap_requests_created ON swap_requests(created_at);
CREATE INDEX idx_swap_requests_uuid ON swap_requests(swap_uuid);

-- Hold transactions indexes
CREATE INDEX idx_holds_reference ON hold_transactions(hold_reference);
CREATE INDEX idx_holds_swap ON hold_transactions(swap_reference);
CREATE INDEX idx_holds_status ON hold_transactions(status);
CREATE INDEX idx_holds_participant ON hold_transactions(participant_id);

-- Transaction indexes
CREATE INDEX idx_transactions_user ON transactions(user_id);
CREATE INDEX idx_transactions_reference ON transactions(transaction_reference);
CREATE INDEX idx_transactions_participants ON transactions(origin_participant_id, destination_participant_id);
CREATE INDEX idx_transactions_created ON transactions(created_at);

-- Deposit transactions indexes
CREATE INDEX idx_deposit_phone ON deposit_transactions(client_phone, created_at);
CREATE INDEX idx_deposit_status ON deposit_transactions(status);
CREATE INDEX idx_deposit_created ON deposit_transactions(created_at);

-- Cashout authorizations indexes
CREATE INDEX idx_cashout_phone ON cashout_authorizations(client_phone, created_at);
CREATE INDEX idx_cashout_code ON cashout_authorizations(swap_code) WHERE status = 'PENDING';
CREATE INDEX idx_cashout_status ON cashout_authorizations(status);
CREATE INDEX idx_cashout_expiry ON cashout_authorizations(code_expiry) WHERE status = 'PENDING';

-- Send-to-other indexes
CREATE INDEX idx_send_sender ON send_to_other_transactions(sender_phone, created_at);
CREATE INDEX idx_send_receiver ON send_to_other_transactions(receiver_phone, created_at);
CREATE INDEX idx_send_status ON send_to_other_transactions(status);

-- Message outbox indexes
CREATE INDEX idx_message_outbox_status_created ON message_outbox(status, created_at);
CREATE INDEX idx_message_outbox_channel ON message_outbox(channel);
CREATE INDEX idx_message_outbox_status ON message_outbox(status);

-- API logs indexes
CREATE INDEX idx_api_logs_message_id ON api_message_logs(message_id);
CREATE INDEX idx_api_logs_type ON api_message_logs(message_type);
CREATE INDEX idx_api_logs_created ON api_message_logs(created_at);
CREATE INDEX idx_api_logs_participant ON api_message_logs(participant_id);
CREATE INDEX idx_api_logs_success ON api_message_logs(success) WHERE success = false;

-- Fee collections indexes
CREATE INDEX idx_fee_collections_swap_ref ON swap_fee_collections(swap_reference);
CREATE INDEX idx_fee_collections_status ON swap_fee_collections(status);
CREATE INDEX idx_fee_collections_date ON swap_fee_collections(collected_at);

-- Audit logs indexes
CREATE INDEX idx_audit_logs_entity ON audit_logs(entity_type, entity_id);
CREATE INDEX idx_audit_logs_performed ON audit_logs(performed_by_type, performed_by_id);
CREATE INDEX idx_audit_logs_performed_at ON audit_logs(performed_at);

-- =========================================================
-- SECTION 13: TRIGGERS
-- =========================================================

-- Function to apply updated_at triggers to all tables
CREATE OR REPLACE FUNCTION apply_updated_at_triggers()
RETURNS void AS $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN SELECT table_name 
             FROM information_schema.tables 
             WHERE table_schema = 'public' 
               AND table_type = 'BASE TABLE'
    LOOP
        EXECUTE format('
            DROP TRIGGER IF EXISTS trg_%1$s_updated ON %1$s;
            CREATE TRIGGER trg_%1$s_updated
                BEFORE UPDATE ON %1$s
                FOR EACH ROW
                EXECUTE FUNCTION update_updated_at_column();
        ', r.table_name);
    END LOOP;
END;
$$ LANGUAGE plpgsql;

-- Apply all triggers
SELECT apply_updated_at_triggers();

-- =========================================================
-- SECTION 14: INITIAL DATA
-- =========================================================

-- Insert default roles
INSERT INTO roles (role_name, description, permissions) VALUES
('user', 'Regular system user', '["basic_access", "create_swaps", "view_own_transactions"]'::jsonb),
('admin', 'System administrator', '["full_access", "manage_users", "manage_swaps", "view_all_transactions"]'::jsonb),
('compliance', 'Compliance officer', '["view_kyc", "approve_kyc", "view_audit_logs", "fraud_investigation"]'::jsonb),
('auditor', 'System auditor', '["view_audit_logs", "view_transactions", "view_reports"]'::jsonb),
('super_admin', 'Super administrator', '["full_access", "manage_admins", "system_configuration"]'::jsonb)
ON CONFLICT (role_name) DO NOTHING;

-- =========================================================
-- SECTION 15: LOAD PARTICIPANTS FUNCTION
-- =========================================================

CREATE OR REPLACE FUNCTION load_participants_from_json(json_file_path TEXT)
RETURNS TEXT AS $$
DECLARE
    json_content TEXT;
    json_data JSONB;
    participant_key TEXT;
    participant_data JSONB;
    inserted_count INT := 0;
BEGIN
    -- Read the JSON file
    BEGIN
        json_content := pg_read_file(json_file_path, 0, 1000000);
    EXCEPTION WHEN OTHERS THEN
        RETURN 'Error reading file: ' || SQLERRM;
    END;
    
    -- Parse JSON
    BEGIN
        json_data := json_content::JSONB;
    EXCEPTION WHEN OTHERS THEN
        RETURN 'Error parsing JSON: ' || SQLERRM;
    END;
    
    -- Check if it has the expected structure
    IF json_data ? 'participants' THEN
        -- Loop through each participant
        FOR participant_key, participant_data IN SELECT * FROM jsonb_each(json_data->'participants')
        LOOP
            INSERT INTO participants (
                name,
                type,
                category,
                provider_code,
                auth_type,
                base_url,
                system_user_id,
                legal_entity_identifier,
                license_number,
                settlement_account,
                settlement_type,
                status,
                capabilities,
                resource_endpoints,
                phone_format,
                security_config,
                message_profile,
                routing_info
            ) VALUES (
                participant_key,
                (participant_data->>'type')::participant_type,
                (participant_data->>'category')::participant_category,
                participant_data->>'provider_code',
                participant_data->>'auth_type',
                participant_data->>'base_url',
                (participant_data->'identity'->>'system_user_id')::BIGINT,
                participant_data->'identity'->>'legal_entity_identifier',
                participant_data->'identity'->>'license_number',
                participant_data->'routing'->>'settlement_account',
                participant_data->'routing'->>'settlement_type',
                COALESCE(participant_data->>'status', 'ACTIVE'),
                participant_data->'capabilities',
                participant_data->'resource_endpoints',
                participant_data->'phone_format',
                participant_data->'security',
                participant_data->'message_profile',
                participant_data->'routing'
            )
            ON CONFLICT (name) DO UPDATE SET
                type = EXCLUDED.type,
                category = EXCLUDED.category,
                provider_code = EXCLUDED.provider_code,
                auth_type = EXCLUDED.auth_type,
                base_url = EXCLUDED.base_url,
                system_user_id = EXCLUDED.system_user_id,
                legal_entity_identifier = EXCLUDED.legal_entity_identifier,
                license_number = EXCLUDED.license_number,
                settlement_account = EXCLUDED.settlement_account,
                settlement_type = EXCLUDED.settlement_type,
                status = EXCLUDED.status,
                capabilities = EXCLUDED.capabilities,
                resource_endpoints = EXCLUDED.resource_endpoints,
                phone_format = EXCLUDED.phone_format,
                security_config = EXCLUDED.security_config,
                message_profile = EXCLUDED.message_profile,
                routing_info = EXCLUDED.routing_info,
                updated_at = CURRENT_TIMESTAMP;
                
            inserted_count := inserted_count + 1;
        END LOOP;
        
        RETURN format('Loaded/Updated %s participants successfully.', inserted_count);
    ELSE
        RETURN 'Invalid JSON format: missing "participants" key';
    END IF;
END;
$$ LANGUAGE plpgsql;

-- =========================================================
-- SCHEMA CREATION COMPLETE
-- =========================================================

COMMENT ON SCHEMA public IS 'Swap System Database Schema - Clean Version for Deployment';
COMMENT ON TABLE participants IS 'Financial institutions, MNOs, and distributors participating in the swap system';
COMMENT ON TABLE swap_requests IS 'Main swap transaction requests';
COMMENT ON TABLE hold_transactions IS 'Funds held during swap processing';
COMMENT ON TABLE swap_fee_collections IS 'Fees collected from swaps for later settlement';
COMMENT ON TABLE api_message_logs IS 'Logs of all API calls to/from participants';

-- =========================================================
-- END OF SCHEMA
-- =========================================================

ALTER TABLE swap_vouchers 
ADD COLUMN IF NOT EXISTS code_hash VARCHAR(255),
ADD COLUMN IF NOT EXISTS attempts INT DEFAULT 0;

-- 3. Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_net_positions_debtor_creditor ON net_positions(debtor, creditor);
CREATE INDEX IF NOT EXISTS idx_swap_vouchers_code_hash ON swap_vouchers(code_hash) WHERE status = 'ACTIVE';

-- =========================================================
-- FIX 1: Create message_outbox table if it doesn't exist
-- =========================================================
CREATE TABLE IF NOT EXISTS message_outbox (
    id SERIAL PRIMARY KEY,
    message_id VARCHAR(255) UNIQUE NOT NULL,
    channel VARCHAR(50) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    payload JSONB NOT NULL,
    status VARCHAR(50) DEFAULT 'PENDING',
    attempts INT DEFAULT 0,
    last_error TEXT,
    last_attempt TIMESTAMP,
    processed_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- =========================================================
-- FIX 2: Create swap_vouchers table if not exists (for cashouts)
-- =========================================================
CREATE TABLE IF NOT EXISTS swap_vouchers (
    voucher_id SERIAL PRIMARY KEY,
    swap_id INTEGER,
    code_hash VARCHAR(255) NOT NULL,
    code_suffix VARCHAR(4),
    amount NUMERIC(20,4) NOT NULL,
    expiry_at TIMESTAMP NOT NULL,
    status VARCHAR(20) DEFAULT 'ACTIVE',
    redeemed_at TIMESTAMP,
    claimant_phone VARCHAR(20),
    is_cardless_redemption BOOLEAN DEFAULT TRUE,
    attempts INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- =========================================================
-- FIX 3: Ensure swap_requests has all needed columns
-- =========================================================
ALTER TABLE swap_requests 
ADD COLUMN IF NOT EXISTS source_details JSONB,
ADD COLUMN IF NOT EXISTS destination_details JSONB;

-- =========================================================
-- FIX 4: Create indexes for performance
-- =========================================================
CREATE INDEX IF NOT EXISTS idx_message_outbox_status ON message_outbox(status);
CREATE INDEX IF NOT EXISTS idx_message_outbox_created ON message_outbox(created_at);
CREATE INDEX IF NOT EXISTS idx_swap_vouchers_code_hash ON swap_vouchers(code_hash);
CREATE INDEX IF NOT EXISTS idx_swap_vouchers_status ON swap_vouchers(status);

ALTER TABLE swap_requests 
ADD COLUMN IF NOT EXISTS metadata JSONB DEFAULT '{}'::jsonb;

-- Add if missing
ALTER TABLE hold_transactions 
ADD COLUMN IF NOT EXISTS hold_expiry TIMESTAMPTZ,
ADD COLUMN IF NOT EXISTS released_at TIMESTAMPTZ,
ADD COLUMN IF NOT EXISTS debited_at TIMESTAMPTZ;

-- Index for expiry queries
CREATE INDEX IF NOT EXISTS idx_holds_expiry_status 
ON hold_transactions(hold_expiry, status) 
WHERE status = 'ACTIVE';

private const HOLD_EXPIRY_HOURS = 24;  // Standard hold duration
private const VOUCHER_EXPIRY_HOURS = 24;  // Standard voucher duration
private const EXPIRY_BATCH_SIZE = 100;  // For cron job

-- =========================================================
-- MIGRATION: 015_create_card_tables.sql
-- Run this on your VouchMorph database
-- =========================================================

-- 1. MESSAGE CARDS TABLE (links holds to cards)
CREATE TABLE IF NOT EXISTS message_cards (
    card_id BIGSERIAL PRIMARY KEY,
    
    -- Card identifiers (security: never store plain PAN!)
    card_number_hash VARCHAR(255) NOT NULL UNIQUE,
    card_suffix VARCHAR(4) NOT NULL,
    cvv_hash VARCHAR(255) NOT NULL,
    
    -- Links to your existing systems
    hold_reference VARCHAR(100) NOT NULL REFERENCES hold_transactions(hold_reference),
    swap_reference VARCHAR(100) REFERENCES swap_requests(swap_uuid),
    user_id BIGINT REFERENCES users(user_id),
    
    -- Cardholder details
    cardholder_name VARCHAR(200) NOT NULL,
    
    -- Balance tracking (mirrors the hold)
    initial_amount NUMERIC(20,4) NOT NULL,
    remaining_amount NUMERIC(20,4) NOT NULL,
    currency CHAR(3) DEFAULT 'BWP',
    
    -- Card status
    status VARCHAR(20) DEFAULT 'ACTIVE',
    -- 'ACTIVE', 'BLOCKED', 'EXPIRED', 'USED', 'CANCELLED'
    
    -- Dates
    issued_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    activated_at TIMESTAMPTZ,
    last_used_at TIMESTAMPTZ,
    blocked_at TIMESTAMPTZ,
    block_reason TEXT,
    
    -- Expiry (standard 3 years)
    expiry_year INT NOT NULL,
    expiry_month INT NOT NULL,
    
    -- Spending controls
    daily_limit NUMERIC(20,4) DEFAULT 10000,
    monthly_limit NUMERIC(20,4) DEFAULT 50000,
    atm_daily_limit NUMERIC(20,4) DEFAULT 2000,
    
    -- Metadata
    metadata JSONB DEFAULT '{}'::jsonb,
    
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Indexes for performance
CREATE INDEX idx_message_cards_hash ON message_cards(card_number_hash);
CREATE INDEX idx_message_cards_hold ON message_cards(hold_reference);
CREATE INDEX idx_message_cards_user ON message_cards(user_id);
CREATE INDEX idx_message_cards_status ON message_cards(status);
CREATE INDEX idx_message_cards_expiry ON message_cards(expiry_year, expiry_month);

-- 2. CARD TRANSACTIONS TABLE
CREATE TABLE IF NOT EXISTS card_transactions (
    transaction_id BIGSERIAL PRIMARY KEY,
    card_id BIGINT NOT NULL REFERENCES message_cards(card_id),
    
    -- Transaction details
    transaction_type VARCHAR(30) NOT NULL,
    -- 'ISSUANCE', 'ATM_WITHDRAWAL', 'PURCHASE', 'REFUND', 'VOID', 'AUTHORIZATION'
    
    amount NUMERIC(20,4) NOT NULL,
    currency CHAR(3) DEFAULT 'BWP',
    
    -- Authorization
    auth_code VARCHAR(20),
    auth_status VARCHAR(20) DEFAULT 'APPROVED',
    -- 'APPROVED', 'DECLINED', 'PENDING', 'REVERSED'
    
    -- Merchant/ATM details
    merchant_name VARCHAR(200),
    merchant_id VARCHAR(50),
    merchant_category VARCHAR(10),
    terminal_id VARCHAR(50),
    
    -- ATM specific
    atm_id VARCHAR(50),
    atm_location VARCHAR(200),
    
    -- Channel
    channel VARCHAR(20), -- 'POS', 'ATM', 'ECOMMERCE', 'MOTO'
    
    -- Links
    settlement_queue_id BIGINT REFERENCES settlement_queue(id),
    hold_reference VARCHAR(100),
    reference VARCHAR(100),
    
    -- Timestamps
    created_at TIMESTAMPTZ DEFAULT NOW(),
    settled_at TIMESTAMPTZ,
    
    -- Response codes
    response_code VARCHAR(2),
    response_message TEXT
);

CREATE INDEX idx_card_transactions_card ON card_transactions(card_id);
CREATE INDEX idx_card_transactions_date ON card_transactions(created_at);
CREATE INDEX idx_card_transactions_auth ON card_transactions(auth_code);

-- 3. CARD AUTHORIZATION LOGS (for debugging and audit)
CREATE TABLE IF NOT EXISTS card_auth_logs (
    log_id BIGSERIAL PRIMARY KEY,
    card_id BIGINT REFERENCES message_cards(card_id),
    request_payload JSONB,
    response_payload JSONB,
    http_status_code INT,
    response_time_ms INT,
    success BOOLEAN,
    error_message TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- 4. VIEW FOR CARD BALANCES
CREATE OR REPLACE VIEW card_balances_view AS
SELECT 
    mc.card_id,
    mc.card_suffix,
    mc.cardholder_name,
    mc.remaining_amount as balance,
    mc.currency,
    mc.expiry_month || '/' || mc.expiry_year as expiry,
    mc.status,
    ht.hold_reference,
    ht.source_institution,
    COALESCE(SUM(ct.amount) FILTER (WHERE ct.auth_status = 'APPROVED'), 0) as total_spent,
    COUNT(ct.transaction_id) as transaction_count,
    MAX(ct.created_at) as last_transaction
FROM message_cards mc
LEFT JOIN hold_transactions ht ON mc.hold_reference = ht.hold_reference
LEFT JOIN card_transactions ct ON mc.card_id = ct.card_id
GROUP BY mc.card_id, mc.card_suffix, mc.cardholder_name, mc.remaining_amount,
         mc.currency, mc.expiry_month, mc.expiry_year, mc.status,
         ht.hold_reference, ht.source_institution;

-- 5. Add source_institution to hold_transactions if missing
ALTER TABLE hold_transactions 
ADD COLUMN IF NOT EXISTS source_institution VARCHAR(100),
ADD COLUMN IF NOT EXISTS source_details JSONB;

-- =========================================================
-- SECTION 16: CARD MANAGEMENT SYSTEM
-- Supports both Virtual and Physical cards
-- Clear separation of concerns for easy understanding
-- =========================================================

-- 16.1 Card Batches (For Physical Card Inventory)
-- Tracks batches of pre-printed cards from manufacturer
CREATE TABLE IF NOT EXISTS card_batches (
    batch_id BIGSERIAL PRIMARY KEY,
    batch_reference VARCHAR(50) UNIQUE NOT NULL,
    
    -- Card details (same for all cards in batch)
    bin_prefix VARCHAR(6) NOT NULL, -- First 6 digits (e.g., 411111)
    card_scheme VARCHAR(20) NOT NULL, -- 'VISA', 'MASTERCARD'
    card_type VARCHAR(20) NOT NULL, -- 'PHYSICAL', 'VIRTUAL' (for this batch)
    
    -- Production details
    quantity_produced INT NOT NULL,
    quantity_remaining INT NOT NULL,
    expiry_year INT NOT NULL,
    expiry_month INT NOT NULL,
    
    -- Status tracking
    status VARCHAR(20) DEFAULT 'PRODUCED',
    -- 'ORDERED', 'PRODUCED', 'RECEIVED', 'INVENTORY', 'DEPLETED'
    
    -- Timestamps
    ordered_at TIMESTAMPTZ,
    produced_at TIMESTAMPTZ,
    received_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    
    -- Metadata
    metadata JSONB DEFAULT '{}'::jsonb,
    
    CONSTRAINT valid_card_type CHECK (card_type IN ('PHYSICAL', 'VIRTUAL'))
);

-- 16.2 Message Cards (Unified table for both card types)
CREATE TABLE IF NOT EXISTS message_cards (
    card_id BIGSERIAL PRIMARY KEY,
    
    -- Card Identifiers (Security: never store plain PAN!)
    card_number_hash VARCHAR(255) NOT NULL UNIQUE,
    card_suffix VARCHAR(4) NOT NULL, -- Last 4 digits for display
    cvv_hash VARCHAR(255) NOT NULL,
    pin_hash VARCHAR(255), -- Optional for ATM PIN
    
    -- Card Classification
    card_category VARCHAR(20) NOT NULL, -- 'VIRTUAL', 'PHYSICAL'
    card_scheme VARCHAR(20) NOT NULL, -- 'VISA', 'MASTERCARD', 'VOUCHMORPH'
    
    -- For Physical Cards: Link to batch
    batch_id BIGINT REFERENCES card_batches(batch_id),
    batch_sequence INT, -- Which number in the batch (e.g., card 123 of 1000)
    
    -- Links to your existing systems
    hold_reference VARCHAR(100) REFERENCES hold_transactions(hold_reference),
    swap_reference VARCHAR(100) REFERENCES swap_requests(swap_uuid),
    user_id BIGINT REFERENCES users(user_id),
    
    -- Cardholder details
    cardholder_name VARCHAR(200) NOT NULL,
    cardholder_phone VARCHAR(20),
    student_id VARCHAR(50),
    
    -- Balance tracking (mirrors the hold)
    initial_amount NUMERIC(20,4) NOT NULL DEFAULT 0,
    remaining_amount NUMERIC(20,4) NOT NULL DEFAULT 0,
    currency CHAR(3) DEFAULT 'BWP',
    
    -- Card Lifecycle Status
    lifecycle_status VARCHAR(30) NOT NULL DEFAULT 'PENDING_ACTIVATION',
    -- For VIRTUAL cards:
    --   'PENDING_ISSUANCE' → 'ISSUED' → 'ACTIVE' → 'BLOCKED' → 'EXPIRED'
    -- For PHYSICAL cards:
    --   'IN_BATCH' → 'ASSIGNED' → 'SHIPPED' → 'DELIVERED' → 'ACTIVE' → 'BLOCKED' → 'EXPIRED'
    
    -- Financial Status (separate from lifecycle)
    financial_status VARCHAR(20) DEFAULT 'UNFUNDED',
    -- 'UNFUNDED', 'FUNDED', 'ACTIVE', 'DEPLETED'
    
    -- Important Dates
    issued_at TIMESTAMPTZ, -- When card record created
    batch_assigned_at TIMESTAMPTZ, -- When PHYSICAL card assigned to student
    shipped_at TIMESTAMPTZ,
    delivered_at TIMESTAMPTZ,
    activated_at TIMESTAMPTZ, -- When first funded/swiped
    last_used_at TIMESTAMPTZ,
    blocked_at TIMESTAMPTZ,
    block_reason TEXT,
    
    -- Expiry (from batch or generated for virtual)
    expiry_year INT NOT NULL,
    expiry_month INT NOT NULL,
    
    -- Spending controls (can override batch defaults)
    daily_limit NUMERIC(20,4),
    monthly_limit NUMERIC(20,4),
    atm_daily_limit NUMERIC(20,4),
    
    -- Delivery tracking for physical cards
    delivery_address TEXT,
    delivery_method VARCHAR(50), -- 'COURIER', 'BRANCH_PICKUP', 'MAIL'
    delivery_status VARCHAR(30),
    tracking_number VARCHAR(100),
    delivered_at TIMESTAMPTZ,
    
    -- Metadata
    metadata JSONB DEFAULT '{}'::jsonb,
    
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    
    -- Constraints
    CONSTRAINT valid_card_category CHECK (card_category IN ('VIRTUAL', 'PHYSICAL')),
    CONSTRAINT valid_lifecycle_status CHECK (lifecycle_status IN (
        'PENDING_ISSUANCE', 'ISSUED', 'IN_BATCH', 'ASSIGNED', 'SHIPPED', 
        'DELIVERED', 'ACTIVE', 'BLOCKED', 'EXPIRED', 'CANCELLED'
    )),
    CONSTRAINT valid_financial_status CHECK (financial_status IN (
        'UNFUNDED', 'FUNDED', 'ACTIVE', 'DEPLETED'
    ))
);

-- 16.3 Card Transactions
CREATE TABLE IF NOT EXISTS card_transactions (
    transaction_id BIGSERIAL PRIMARY KEY,
    card_id BIGINT NOT NULL REFERENCES message_cards(card_id),
    
    -- Transaction details
    transaction_type VARCHAR(30) NOT NULL,
    -- 'ISSUANCE', 'LOAD', 'ATM_WITHDRAWAL', 'PURCHASE', 'REFUND', 'VOID', 'AUTHORIZATION'
    
    amount NUMERIC(20,4) NOT NULL,
    currency CHAR(3) DEFAULT 'BWP',
    
    -- Authorization
    auth_code VARCHAR(20),
    auth_status VARCHAR(20) DEFAULT 'APPROVED',
    rrn VARCHAR(12), -- Retrieval Reference Number
    stan VARCHAR(12), -- System Trace Audit Number
    
    -- Merchant/ATM details
    merchant_name VARCHAR(200),
    merchant_id VARCHAR(50),
    merchant_category VARCHAR(10),
    terminal_id VARCHAR(50),
    
    -- ATM specific
    atm_id VARCHAR(50),
    atm_location VARCHAR(200),
    
    -- Channel
    channel VARCHAR(20), -- 'POS', 'ATM', 'ECOMMERCE', 'MOTO', 'LOAD'
    
    -- Links
    settlement_queue_id BIGINT REFERENCES settlement_queue(id),
    hold_reference VARCHAR(100),
    swap_reference VARCHAR(100),
    
    -- Timestamps
    created_at TIMESTAMPTZ DEFAULT NOW(),
    settled_at TIMESTAMPTZ,
    
    -- Response codes
    response_code VARCHAR(2),
    response_message TEXT
);

-- 16.4 Card Loads (Funding cards via swaps)
CREATE TABLE IF NOT EXISTS card_loads (
    load_id BIGSERIAL PRIMARY KEY,
    card_id BIGINT NOT NULL REFERENCES message_cards(card_id),
    swap_reference VARCHAR(100) REFERENCES swap_requests(swap_uuid),
    hold_reference VARCHAR(100) REFERENCES hold_transactions(hold_reference),
    
    amount NUMERIC(20,4) NOT NULL,
    currency CHAR(3) DEFAULT 'BWP',
    
    -- Status
    status VARCHAR(20) DEFAULT 'PENDING',
    -- 'PENDING', 'COMPLETED', 'FAILED', 'REVERSED'
    
    -- Timestamps
    initiated_at TIMESTAMPTZ DEFAULT NOW(),
    completed_at TIMESTAMPTZ,
    
    -- Metadata
    metadata JSONB DEFAULT '{}'::jsonb
);

-- =========================================================
-- INDEXES FOR PERFORMANCE
-- =========================================================

-- Card batches indexes
CREATE INDEX idx_card_batches_status ON card_batches(status);
CREATE INDEX idx_card_batches_expiry ON card_batches(expiry_year, expiry_month);

-- Message cards indexes
CREATE INDEX idx_message_cards_hash ON message_cards(card_number_hash);
CREATE INDEX idx_message_cards_hold ON message_cards(hold_reference);
CREATE INDEX idx_message_cards_user ON message_cards(user_id);
CREATE INDEX idx_message_cards_lifecycle ON message_cards(lifecycle_status);
CREATE INDEX idx_message_cards_financial ON message_cards(financial_status);
CREATE INDEX idx_message_cards_expiry ON message_cards(expiry_year, expiry_month);
CREATE INDEX idx_message_cards_batch ON message_cards(batch_id);

-- Card transactions indexes
CREATE INDEX idx_card_transactions_card ON card_transactions(card_id);
CREATE INDEX idx_card_transactions_date ON card_transactions(created_at);
CREATE INDEX idx_card_transactions_auth ON card_transactions(auth_code);
CREATE INDEX idx_card_transactions_rrn ON card_transactions(rrn);

-- Card loads indexes
CREATE INDEX idx_card_loads_card ON card_loads(card_id);
CREATE INDEX idx_card_loads_swap ON card_loads(swap_reference);
CREATE INDEX idx_card_loads_status ON card_loads(status);

-- =========================================================
-- VIEWS FOR CLEAR REPORTING
-- =========================================================

-- 16.5 View: Card Inventory (Physical cards only)
CREATE OR REPLACE VIEW card_inventory_view AS
SELECT 
    cb.batch_id,
    cb.batch_reference,
    cb.bin_prefix,
    cb.card_scheme,
    cb.quantity_produced,
    cb.quantity_remaining,
    cb.expiry_month || '/' || cb.expiry_year as expiry,
    COUNT(mc.card_id) as cards_assigned,
    cb.quantity_remaining - COUNT(mc.card_id) as available_in_inventory
FROM card_batches cb
LEFT JOIN message_cards mc ON cb.batch_id = mc.batch_id
WHERE cb.card_type = 'PHYSICAL'
GROUP BY cb.batch_id, cb.batch_reference, cb.bin_prefix, cb.card_scheme,
         cb.quantity_produced, cb.quantity_remaining, cb.expiry_month, cb.expiry_year;

-- 16.6 View: Card Lifecycle Status
CREATE OR REPLACE VIEW card_lifecycle_view AS
SELECT 
    lifecycle_status,
    card_category,
    COUNT(*) as card_count,
    SUM(CASE WHEN financial_status = 'FUNDED' THEN 1 ELSE 0 END) as funded_count,
    SUM(remaining_amount) as total_remaining_value
FROM message_cards
GROUP BY lifecycle_status, card_category
ORDER BY lifecycle_status;

-- 16.7 View: Student Card Summary
CREATE OR REPLACE VIEW student_cards_summary AS
SELECT 
    u.user_id,
    u.full_name,
    u.phone,
    COUNT(mc.card_id) as total_cards,
    SUM(CASE WHEN mc.financial_status IN ('FUNDED', 'ACTIVE') THEN 1 ELSE 0 END) as active_cards,
    SUM(mc.remaining_amount) as total_available_balance,
    MAX(mc.last_used_at) as last_card_activity
FROM users u
LEFT JOIN message_cards mc ON u.user_id = mc.user_id
GROUP BY u.user_id, u.full_name, u.phone;
