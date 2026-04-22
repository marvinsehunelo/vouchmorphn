<?php

namespace Infrastructure\Banks\Contracts;
/**
 * Complete Bank API Interface for VouchMorph Swap System
 * Supports all wallet_types: ACCOUNT, VOUCHER, E-WALLET, WALLET, CARD, ATM, AGENT
 * Handles both Source and Destination roles
 */
interface BankAPIInterface
{
    // ============================================================================
    // SOURCE ROLE METHODS - Used by BOTH flows
    // ============================================================================

    /**
     * verify_asset - Verifies the customer owns the asset and has funds
     * Used for: e-wallet, voucher, account, card
     * 
     * @param array $payload Contains:
     * - reference: string
     * - asset_type: string (E-WALLET|VOUCHER|ACCOUNT|CARD)
     * - amount: float
     * - credentials: array (phone, number, pin, etc.)
     * @return array ['verified' => bool, 'asset_id' => string, 'balance' => float]
     */
    public function verifyAsset(array $payload): array;

    /**
     * place_hold - Locks funds for pending transaction
     * Used for: e-wallet, voucher, account, card
     * 
     * @param array $payload Contains:
     * - reference: string
     * - asset_id: string
     * - amount: float
     * - expiry: string (hold expiry)
     * @return array ['hold_placed' => bool, 'hold_reference' => string]
     */
    public function placeHold(array $payload): array;

    /**
     * debit_funds - Final debit after successful transaction
     * Used for: e-wallet, voucher, account, card
     * 
     * @param array $payload Contains:
     * - hold_reference: string
     * - amount: float
     * - destination_details: array
     * @return array ['debited' => bool, 'transaction_reference' => string]
     */
    public function debitFunds(array $payload): array;

    /**
     * release_hold - Releases locked funds (for failures/reversals)
     * Used for: e-wallet, voucher, account, card
     * 
     * @param array $payload Contains:
     * - hold_reference: string
     * - reason: string
     * @return array ['released' => bool]
     */
    public function releaseHold(array $payload): array;

    // ============================================================================
    // DESTINATION ROLE METHODS - CASHOUT FLOW (ATM/AGENT)
    // ============================================================================

    /**
     * generate_token - Creates ATM/agent withdrawal code
     * Used for: atm, agent
     * 
     * @param array $payload Contains:
     * - reference: string
     * - beneficiary_phone: string
     * - amount: float
     * - code_hash: string
     * - expiry: string
     * @return array ['token_generated' => bool, 'token_reference' => string, 'atm_pin' => string]
     */
    public function generateToken(array $payload): array;

    /**
     * verify_token - Validates token at cashout time
     * Used for: atm, agent
     * 
     * @param array $payload Contains:
     * - token_reference: string
     * - entered_code: string
     * @return array ['verified' => bool, 'amount' => float, 'beneficiary' => string]
     */
    public function verifyToken(array $payload): array;

    /**
     * confirm_cashout - Confirms cash was dispensed
     * Used for: atm, agent
     * 
     * @param array $payload Contains:
     * - token_reference: string
     * - dispensed_notes: array
     * - completed_at: string
     * @return array ['confirmed' => bool, 'settlement_triggered' => bool]
     */
    public function confirmCashout(array $payload): array;

    // ============================================================================
    // DESTINATION ROLE METHODS - DEPOSIT FLOW (ACCOUNT/WALLET)
    // ============================================================================

    /**
     * process_deposit - Credits funds to account/wallet/e-wallet/card
     * Used for: account, wallet, e-wallet, card
     * 
     * @param array $payload Contains:
     * - reference: string
     * - source_institution: string
     * - destination_type: string (ACCOUNT|WALLET|E-WALLET|CARD)
     * - destination_id: string (account number, wallet phone, card number)
     * - amount: float
     * - source_hold_reference: string
     * @return array ['processed' => bool, 'transaction_reference' => string, 'new_balance' => float]
     */
    public function processDeposit(array $payload): array;
}
