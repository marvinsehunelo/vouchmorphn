#!/bin/bash

# ============================================
# VOUCHMORPH - SACCUSSALIS & ZURUBANK TEST SUITE
# Including Message Storing Cards Integration
# ============================================

BASE_URL="http://localhost:8080"  # Change to your actual URL
API_ENDPOINT="/user/regulationdemo.php"

# Test phone number (same for both banks)
TEST_PHONE="+26770000000"

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m'

# Counters
TOTAL=0
PASSED=0
FAILED=0

# ============================================
# HELPER FUNCTIONS
# ============================================

print_header() {
    echo -e "\n${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}\n"
}

print_subheader() {
    echo -e "\n${CYAN}▶ $1${NC}"
    echo -e "${CYAN}───────────────────────────────────────────────────${NC}"
}

run_test() {
    local test_name="$1"
    local payload="$2"
    local expected="$3"
    
    echo -e "\n${YELLOW}→ Testing: ${test_name}${NC}"
    echo -e "  ${PURPLE}Phone: ${TEST_PHONE}${NC}"
    
    response=$(curl -s -X POST "${BASE_URL}${API_ENDPOINT}" \
        -H "Content-Type: application/json" \
        -d "$payload" 2>&1)
    
    if echo "$response" | grep -q "$expected"; then
        echo -e "${GREEN}  ✅ PASS${NC}"
        ((PASSED++))
    else
        echo -e "${RED}  ❌ FAIL${NC}"
        echo -e "${RED}     Response: $response${NC}"
        ((FAILED++))
    fi
    ((TOTAL++))
    
    # Pretty print response for debugging
    echo "$response" | python3 -m json.tool 2>/dev/null || echo "$response"
}

# ============================================
# SECTION 1: MESSAGE STORING CARDS (VouchMorph)
# ============================================
print_header "SECTION 1: VOUCHMORPH MESSAGE STORING CARDS"

print_subheader "1.1 Card Issuance - Student Card"

run_test "Issue Student Message Card (SACCUSSALIS → VouchMorph)" \
'{
    "source": {
        "institution": "SACCUSSALIS",
        "asset_type": "E-WALLET",
        "amount": 500.00,
        "ewallet": {
            "phone": "+26770000000"
        }
    },
    "destination": {
        "institution": "VOUCHMORPH",
        "delivery_mode": "card",
        "amount": 500.00,
        "card": {
            "cardholder_name": "Thabo Student",
            "student_id": "STU2025001",
            "purpose": "student",
            "daily_limit": 1000.00,
            "monthly_limit": 5000.00,
            "metadata": {
                "school": "University of Botswana",
                "course": "Computer Science",
                "year": "2026"
            }
        }
    },
    "currency": "BWP"
}' "card_details"

print_subheader "1.2 Card Issuance - Gift Card"

run_test "Issue Gift Message Card (SACCUSSALIS → VouchMorph)" \
'{
    "source": {
        "institution": "SACCUSSALIS",
        "asset_type": "E-WALLET",
        "amount": 250.00,
        "ewallet": {
            "phone": "+26770000000"
        }
    },
    "destination": {
        "institution": "VOUCHMORPH",
        "delivery_mode": "card",
        "amount": 250.00,
        "card": {
            "cardholder_name": "Gift Recipient",
            "purpose": "gift",
            "message": "Happy Birthday! 🎂",
            "expiry_days": 90
        }
    },
    "currency": "BWP"
}' "card_details"

print_subheader "1.3 Card Issuance - Disbursement Card"

run_test "Issue Disbursement Card (ZURUBANK → VouchMorph)" \
'{
    "source": {
        "institution": "ZURUBANK",
        "asset_type": "ACCOUNT",
        "amount": 1000.00,
        "account": {
            "account_number": "ACC123456",
            "account_pin": "1234",
            "account_holder": "Company Payroll"
        }
    },
    "destination": {
        "institution": "VOUCHMORPH",
        "delivery_mode": "card",
        "amount": 1000.00,
        "card": {
            "cardholder_name": "Employee Name",
            "purpose": "salary",
            "employee_id": "EMP001",
            "department": "Engineering"
        }
    },
    "currency": "BWP"
}' "card_details"

print_subheader "1.4 Card Issuance - Travel Card"

run_test "Issue Travel Card (SACCUSSALIS → VouchMorph)" \
'{
    "source": {
        "institution": "SACCUSSALIS",
        "asset_type": "E-WALLET",
        "amount": 750.00,
        "ewallet": {
            "phone": "+26770000000"
        }
    },
    "destination": {
        "institution": "VOUCHMORPH",
        "delivery_mode": "card",
        "amount": 750.00,
        "card": {
            "cardholder_name": "Traveler Name",
            "purpose": "travel",
            "travel_dates": "2026-04-01 to 2026-04-15",
            "destination": "South Africa"
        }
    },
    "currency": "BWP"
}' "card_details"

# ============================================
# SECTION 2: SACCUSSALIS AS SOURCE (E-WALLET)
# ============================================
print_header "SECTION 2: SACCUSSALIS E-WALLET TESTS"

print_subheader "2.1 SACCUSSALIS → ZURUBANK (E-WALLET to ACCOUNT Deposit)"

run_test "SACCUSSALIS E-WALLET to ZURUBANK ACCOUNT" \
'{
    "source": {
        "institution": "SACCUSSALIS",
        "asset_type": "E-WALLET",
        "amount": 150.00,
        "ewallet": {
            "phone": "+26770000000"
        }
    },
    "destination": {
        "institution": "ZURUBANK",
        "amount": 150.00,
        "beneficiary_account": "ACC987654321"
    },
    "currency": "BWP"
}' "swap_reference"

print_subheader "2.2 SACCUSSALIS → ZURUBANK (E-WALLET to VOUCHER Cashout)"

run_test "SACCUSSALIS E-WALLET to ZURUBANK VOUCHER" \
'{
    "source": {
        "institution": "SACCUSSALIS",
        "asset_type": "E-WALLET",
        "amount": 200.00,
        "ewallet": {
            "phone": "+26770000000"
        }
    },
    "destination": {
        "institution": "ZURUBANK",
        "delivery_mode": "cashout",
        "amount": 200.00,
        "cashout": {
            "beneficiary_phone": "+26770000000"
        }
    },
    "currency": "BWP"
}' "swap_reference"

print_subheader "2.3 SACCUSSALIS → ZURUBANK (E-WALLET to ATM Cashout)"

run_test "SACCUSSALIS E-WALLET to ZURUBANK ATM" \
'{
    "source": {
        "institution": "SACCUSSALIS",
        "asset_type": "E-WALLET",
        "amount": 500.00,
        "ewallet": {
            "phone": "+26770000000"
        }
    },
    "destination": {
        "institution": "ZURUBANK",
        "delivery_mode": "cashout",
        "amount": 500.00,
        "cashout": {
            "beneficiary_phone": "+26770000000",
            "atm_id": "ATM001"
        }
    },
    "currency": "BWP"
}' "swap_reference"

print_subheader "2.4 SACCUSSALIS E-WALLET Validation Tests"

run_test "SACCUSSALIS E-WALLET - All phone locations" \
'{
    "source": {
        "institution": "SACCUSSALIS",
        "asset_type": "E-WALLET",
        "amount": 100.00,
        "beneficiary_phone": "+26770000000"
    },
    "destination": {
        "institution": "ZURUBANK",
        "amount": 100.00,
        "beneficiary_account": "ACC123"
    },
    "currency": "BWP"
}' "swap_reference"

run_test "SACCUSSALIS E-WALLET - Phone in ewallet.phone" \
'{
    "source": {
        "institution": "SACCUSSALIS",
        "asset_type": "E-WALLET",
        "amount": 100.00,
        "ewallet": {
            "phone": "+26770000000"
        }
    },
    "destination": {
        "institution": "ZURUBANK",
        "amount": 100.00
    },
    "currency": "BWP"
}' "swap_reference"

# ============================================
# SECTION 3: ZURUBANK AS SOURCE
# ============================================
print_header "SECTION 3: ZURUBANK SOURCE TESTS"

print_subheader "3.1 ZURUBANK ACCOUNT → SACCUSSALIS E-WALLET"

run_test "ZURUBANK ACCOUNT to SACCUSSALIS E-WALLET" \
'{
    "source": {
        "institution": "ZURUBANK",
        "asset_type": "ACCOUNT",
        "amount": 300.00,
        "account": {
            "account_number": "ACC123456",
            "account_pin": "1234",
            "account_holder": "John Doe"
        }
    },
    "destination": {
        "institution": "SACCUSSALIS",
        "amount": 300.00,
        "beneficiary_wallet": "+26770000000"
    },
    "currency": "BWP"
}' "swap_reference"

print_subheader "3.2 ZURUBANK VOUCHER → SACCUSSALIS E-WALLET"

run_test "ZURUBANK VOUCHER to SACCUSSALIS E-WALLET" \
'{
    "source": {
        "institution": "ZURUBANK",
        "asset_type": "VOUCHER",
        "amount": 150.00,
        "voucher": {
            "voucher_number": "VCH987654",
            "voucher_pin": "5678",
            "claimant_phone": "+26770000000"
        }
    },
    "destination": {
        "institution": "SACCUSSALIS",
        "amount": 150.00,
        "beneficiary_wallet": "+26770000000"
    },
    "currency": "BWP"
}' "swap_reference"

# ============================================
# SECTION 4: PHONE FORMATTING TESTS
# ============================================
print_header "SECTION 4: PHONE FORMATTING FOR BOTH BANKS"

print_subheader "4.1 Different Phone Formats (Should all work)"

# Test 4.1.1: Full international format
run_test "Phone: +26770000000 (Full international)" \
'{
    "source": {
        "institution": "SACCUSSALIS",
        "asset_type": "E-WALLET",
        "amount": 50.00,
        "phone": "+26770000000"
    },
    "destination": {
        "institution": "ZURUBANK",
        "amount": 50.00
    },
    "currency": "BWP"
}' "swap_reference"

# Test 4.1.2: Without plus
run_test "Phone: 26770000000 (Without plus)" \
'{
    "source": {
        "institution": "SACCUSSALIS",
        "asset_type": "E-WALLET",
        "amount": 50.00,
        "phone": "26770000000"
    },
    "destination": {
        "institution": "ZURUBANK",
        "amount": 50.00
    },
    "currency": "BWP"
}' "swap_reference"

# Test 4.1.3: Local format
run_test "Phone: 70000000 (Local format)" \
'{
    "source": {
        "institution": "SACCUSSALIS",
        "asset_type": "E-WALLET",
        "amount": 50.00,
        "phone": "70000000"
    },
    "destination": {
        "institution": "ZURUBANK",
        "amount": 50.00
    },
    "currency": "BWP"
}' "swap_reference"

# Test 4.1.4: With spaces
run_test "Phone: +267 70 000 000 (With spaces)" \
'{
    "source": {
        "institution": "SACCUSSALIS",
        "asset_type": "E-WALLET",
        "amount": 50.00,
        "phone": "+267 70 000 000"
    },
    "destination": {
        "institution": "ZURUBANK",
        "amount": 50.00
    },
    "currency": "BWP"
}' "swap_reference"

# Test 4.1.5: With dashes
run_test "Phone: +267-70-000-000 (With dashes)" \
'{
    "source": {
        "institution": "SACCUSSALIS",
        "asset_type": "E-WALLET",
        "amount": 50.00,
        "phone": "+267-70-000-000"
    },
    "destination": {
        "institution": "ZURUBANK",
        "amount": 50.00
    },
    "currency": "BWP"
}' "swap_reference"

# ============================================
# SECTION 5: CROSS-BANK SCENARIOS
# ============================================
print_header "SECTION 5: CROSS-BANK COMPLEX SCENARIOS"

print_subheader "5.1 Multi-leg Transactions"

# Test 5.1.1: SACCUSSALIS E-WALLET → VouchMorph Card → ZURUBANK ATM
run_test "SACCUSSALIS → VouchMorph Card → ZURUBANK (Complex flow)" \
'{
    "source": {
        "institution": "SACCUSSALIS",
        "asset_type": "E-WALLET",
        "amount": 1000.00,
        "ewallet": {
            "phone": "+26770000000"
        }
    },
    "destination": {
        "institution": "VOUCHMORPH",
        "delivery_mode": "card",
        "amount": 1000.00,
        "card": {
            "cardholder_name": "Multi-purpose Card",
            "purpose": "multi_leg",
            "metadata": {
                "can_cashout_at": ["ZURUBANK", "SACCUSSALIS"],
                "expiry": "2027-01-01"
            }
        }
    },
    "currency": "BWP"
}' "card_details"

print_subheader "5.2 Fee Deduction Tests"

# Test 5.2.1: CASHOUT fee deduction
run_test "Cashout with fee deduction" \
'{
    "source": {
        "institution": "SACCUSSALIS",
        "asset_type": "E-WALLET",
        "amount": 1000.00,
        "ewallet": {
            "phone": "+26770000000"
        }
    },
    "destination": {
        "institution": "ZURUBANK",
        "delivery_mode": "cashout",
        "amount": 1000.00,
        "cashout": {
            "beneficiary_phone": "+26770000000"
        }
    },
    "currency": "BWP"
}' "fee"

# ============================================
# SECTION 6: ERROR SCENARIOS
# ============================================
print_header "SECTION 6: ERROR HANDLING TESTS"

print_subheader "6.1 Invalid Source Scenarios"

# Test 6.1.1: SACCUSSALIS missing phone
run_test "SACCUSSALIS E-WALLET missing phone (Should fail)" \
'{
    "source": {
        "institution": "SACCUSSALIS",
        "asset_type": "E-WALLET",
        "amount": 100.00,
        "ewallet": {}
    },
    "destination": {
        "institution": "ZURUBANK",
        "amount": 100.00
    },
    "currency": "BWP"
}' "Phone number required"

# Test 6.1.2: ZURUBANK unsupported asset type
run_test "ZURUBANK unsupported asset type (Should fail)" \
'{
    "source": {
        "institution": "ZURUBANK",
        "asset_type": "E-WALLET",
        "amount": 100.00,
        "ewallet": {
            "phone": "+26770000000"
        }
    },
    "destination": {
        "institution": "SACCUSSALIS",
        "amount": 100.00
    },
    "currency": "BWP"
}' "does not support"

# Test 6.1.3: Invalid ATM amount
run_test "Invalid ATM cashout amount (Should fail)" \
'{
    "source": {
        "institution": "SACCUSSALIS",
        "asset_type": "E-WALLET",
        "amount": 247.00,
        "ewallet": {
            "phone": "+26770000000"
        }
    },
    "destination": {
        "institution": "ZURUBANK",
        "delivery_mode": "cashout",
        "amount": 247.00,
        "cashout": {
            "beneficiary_phone": "+26770000000"
        }
    },
    "currency": "BWP"
}' "cannot be dispensed"

# ============================================
# SECTION 7: BATCH & BULK TESTS
# ============================================
print_header "SECTION 7: BATCH PROCESSING TESTS"

print_subheader "7.1 Multiple Small Transactions"

for i in {1..5}; do
    run_test "Batch Transaction $i: SACCUSSALIS → ZURUBANK (Small amount)" \
    '{
        "source": {
            "institution": "SACCUSSALIS",
            "asset_type": "E-WALLET",
            "amount": 10.00,
            "ewallet": {
                "phone": "+26770000000"
            }
        },
        "destination": {
            "institution": "ZURUBANK",
            "amount": 10.00,
            "beneficiary_account": "ACC_BATCH_'$i'"
        },
        "currency": "BWP"
    }' "swap_reference"
done

# ============================================
# SECTION 8: VOUCHMORPH CARD OPERATIONS
# ============================================
print_header "SECTION 8: ADVANCED CARD OPERATIONS"

print_subheader "8.1 Card with Custom Limits"

run_test "Issue Card with Custom Limits" \
'{
    "source": {
        "institution": "SACCUSSALIS",
        "asset_type": "E-WALLET",
        "amount": 2000.00,
        "ewallet": {
            "phone": "+26770000000"
        }
    },
    "destination": {
        "institution": "VOUCHMORPH",
        "delivery_mode": "card",
        "amount": 2000.00,
        "card": {
            "cardholder_name": "Premium User",
            "purpose": "premium",
            "daily_limit": 5000.00,
            "weekly_limit": 20000.00,
            "monthly_limit": 50000.00,
            "atm_daily_limit": 3000.00,
            "pos_daily_limit": 4000.00,
            "online_daily_limit": 2000.00,
            "international_enabled": true
        }
    },
    "currency": "BWP"
}' "card_details"

print_subheader "8.2 Card with Auto-Top Up"

run_test "Issue Card with Auto-Top Up Feature" \
'{
    "source": {
        "institution": "SACCUSSALIS",
        "asset_type": "E-WALLET",
        "amount": 500.00,
        "ewallet": {
            "phone": "+26770000000"
        }
    },
    "destination": {
        "institution": "VOUCHMORPH",
        "delivery_mode": "card",
        "amount": 500.00,
        "card": {
            "cardholder_name": "Auto Top-up User",
            "purpose": "auto_topup",
            "auto_topup": {
                "threshold": 100.00,
                "amount": 200.00,
                "source_institution": "SACCUSSALIS",
                "source_phone": "+26770000000"
            }
        }
    },
    "currency": "BWP"
}' "card_details"

# ============================================
# SECTION 9: SETTLEMENT & RECONCILIATION
# ============================================
print_header "SECTION 9: SETTLEMENT VERIFICATION"

print_subheader "9.1 Check Net Positions"

echo -e "\n${YELLOW}→ Checking net positions after tests${NC}"
curl -s "${BASE_URL}/api/settlement/net-positions.php" | python3 -m json.tool

# ============================================
# TEST SUMMARY
# ============================================
print_header "TEST SUMMARY"

echo -e "${GREEN}Total Tests: ${TOTAL}${NC}"
echo -e "${GREEN}Passed: ${PASSED}${NC}"
echo -e "${RED}Failed: ${FAILED}${NC}"

if [ $FAILED -eq 0 ]; then
    echo -e "\n${GREEN}🎉 ALL TESTS PASSED!${NC}"
else
    echo -e "\n${RED}⚠️  Some tests failed. Check logs for details.${NC}"
fi

# Save results
echo "{
    \"timestamp\": \"$(date -Iseconds)\",
    \"total\": $TOTAL,
    \"passed\": $PASSED,
    \"failed\": $FAILED,
    \"phone\": \"$TEST_PHONE\",
    \"institutions\": [\"SACCUSSALIS\", \"ZURUBANK\", \"VOUCHMORPH\"]
}" > test_results.json

echo -e "\n${PURPLE}Results saved to test_results.json${NC}"
