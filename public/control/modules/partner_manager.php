<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/DATA_PERSISTENCE_LAYER/config/DBConnection.php';

use DATA_PERSISTENCE_LAYER\config\DBConnection;
use INTEGRATION_LAYER\CLIENTS\BankClients\GenericBankClient;

$db = DBConnection::getConnection();

// Handle actions
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'test_partner' && isset($_POST['participant_id'])) {
        $result = testPartnerConnection($db, (int)$_POST['participant_id']);
        if ($result['success']) {
            $message = "Connection test successful! Response time: {$result['response_time']}ms";
        } else {
            $error = "Connection failed: {$result['error']}";
        }
    }
    
    if ($action === 'update_partner' && isset($_POST['participant_id'])) {
        $updateResult = updatePartner($db, $_POST);
        if ($updateResult) {
            $message = "Partner updated successfully";
        } else {
            $error = "Failed to update partner";
        }
    }
    
    if ($action === 'add_partner') {
        $addResult = addPartner($db, $_POST);
        if ($addResult) {
            $message = "Partner added successfully";
        } else {
            $error = "Failed to add partner";
        }
    }
}

// Get all participants
$participants = $db->query("
    SELECT p.*, 
           COUNT(a.log_id) as api_calls,
           AVG(a.duration_ms) as avg_response,
           SUM(CASE WHEN a.success THEN 1 ELSE 0 END) as successful
    FROM participants p
    LEFT JOIN api_message_logs a ON p.name = a.participant_name
    GROUP BY p.participant_id
    ORDER BY p.name
")->fetchAll(PDO::FETCH_ASSOC);

// Helper functions
function testPartnerConnection($db, $participantId) {
    $stmt = $db->prepare("SELECT * FROM participants WHERE participant_id = ?");
    $stmt->execute([$participantId]);
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$participant) {
        return ['success' => false, 'error' => 'Participant not found'];
    }
    
    $start = microtime(true);
    try {
        $client = new GenericBankClient($participant);
        $result = $client->testConnection();
        $responseTime = round((microtime(true) - $start) * 1000, 2);
        
        // Log the test
        $logStmt = $db->prepare("
            INSERT INTO api_message_logs 
            (message_id, message_type, direction, participant_id, participant_name, endpoint, success, duration_ms, created_at)
            VALUES (?, 'CONNECTION_TEST', 'outgoing', ?, ?, '/test', ?, ?, NOW())
        ");
        $logStmt->execute([
            'TEST-' . uniqid(),
            $participantId,
            $participant['name'],
            $result['success'] ? 1 : 0,
            $responseTime
        ]);
        
        return [
            'success' => $result['success'],
            'response_time' => $responseTime,
            'data' => $result
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function updatePartner($db, $data) {
    $stmt = $db->prepare("
        UPDATE participants SET
            name = ?,
            type = ?::participant_type,
            category = ?::participant_category,
            provider_code = ?,
            auth_type = ?,
            base_url = ?,
            status = ?,
            updated_at = NOW()
        WHERE participant_id = ?
    ");
    
    return $stmt->execute([
        $data['name'],
        $data['type'],
        $data['category'],
        $data['provider_code'],
        $data['auth_type'],
        $data['base_url'],
        $data['status'],
        $data['participant_id']
    ]);
}

function addPartner($db, $data) {
    $stmt = $db->prepare("
        INSERT INTO participants (
            name, type, category, provider_code, auth_type, base_url, status, created_at, updated_at
        ) VALUES (
            ?, ?::participant_type, ?::participant_category, ?, ?, ?, ?, NOW(), NOW()
        )
    ");
    
    return $stmt->execute([
        $data['name'],
        $data['type'],
        $data['category'],
        $data['provider_code'],
        $data['auth_type'],
        $data['base_url'],
        $data['status'] ?? 'ACTIVE'
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Partner Manager</title>
    <link rel="stylesheet" href="../assets/css/control.css">
</head>
<body>
    <div class="control-container">
        <div class="control-header">
            <div class="logo">
                <h1>VOUCHMORPH <span>PARTNER MANAGER</span></h1>
            </div>
            <div class="badge">
                <a href="../index.php" style="color: #FFDA63; text-decoration: none;">← BACK TO DASHBOARD</a>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Add New Partner Button -->
        <div style="margin-bottom: 2rem;">
            <button onclick="showAddForm()" class="btn btn-primary">➕ Add New Partner</button>
        </div>

        <!-- Add Partner Form (hidden by default) -->
        <div id="addForm" style="display: none; margin-bottom: 2rem;">
            <div class="compliance-card">
                <div class="card-header">
                    <span class="card-title">Add New Partner Institution</span>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="add_partner">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Name</label>
                            <input type="text" name="name" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Type</label>
                            <select name="type" required class="form-control">
                                <option value="FINANCIAL_INSTITUTION">Financial Institution</option>
                                <option value="MOBILE_MONEY_OPERATOR">Mobile Money Operator</option>
                                <option value="CARD_DISTRIBUTOR">Card Distributor</option>
                                <option value="TECHNICAL_PROVIDER">Technical Provider</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Category</label>
                            <select name="category" required class="form-control">
                                <option value="BANK">Bank</option>
                                <option value="MNO">MNO</option>
                                <option value="EMI_CARD">EMI/Card</option>
                                <option value="PAYMENT_PROCESSOR">Payment Processor</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Provider Code</label>
                            <input type="text" name="provider_code" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Auth Type</label>
                            <input type="text" name="auth_type" class="form-control" placeholder="API Key / OAuth / Basic">
                        </div>
                        <div class="form-group">
                            <label>Base URL</label>
                            <input type="url" name="base_url" class="form-control" placeholder="https://api.partner.com">
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <option value="ACTIVE">Active</option>
                                <option value="INACTIVE">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div style="margin-top: 1rem;">
                        <button type="submit" class="btn btn-success">Save Partner</button>
                        <button type="button" onclick="hideAddForm()" class="btn">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Partners List -->
        <div class="compliance-card">
            <div class="card-header">
                <span class="card-title">Partner Institutions</span>
                <span class="card-badge"><?php echo count($participants); ?> Total</span>
            </div>
            
            <table style="width:100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left; padding:1rem;">Name</th>
                        <th style="text-align:left; padding:1rem;">Type</th>
                        <th style="text-align:left; padding:1rem;">Provider Code</th>
                        <th style="text-align:left; padding:1rem;">Status</th>
                        <th style="text-align:left; padding:1rem;">API Calls</th>
                        <th style="text-align:left; padding:1rem;">Success Rate</th>
                        <th style="text-align:left; padding:1rem;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($participants as $p): ?>
                    <tr>
                        <td style="padding:1rem; border-bottom:1px solid #222;">
                            <strong><?php echo htmlspecialchars($p['name']); ?></strong>
                        </td>
                        <td style="padding:1rem; border-bottom:1px solid #222;">
                            <?php echo $p['type']; ?><br>
                            <small><?php echo $p['category']; ?></small>
                        </td>
                        <td style="padding:1rem; border-bottom:1px solid #222;">
                            <?php echo $p['provider_code'] ?: '—'; ?>
                        </td>
                        <td style="padding:1rem; border-bottom:1px solid #222;">
                            <span class="status-badge status-<?php echo strtolower($p['status']); ?>">
                                <?php echo $p['status']; ?>
                            </span>
                        </td>
                        <td style="padding:1rem; border-bottom:1px solid #222;">
                            <?php echo $p['api_calls'] ?: 0; ?>
                        </td>
                        <td style="padding:1rem; border-bottom:1px solid #222;">
                            <?php 
                            if ($p['api_calls'] > 0) {
                                $rate = round(($p['successful'] / $p['api_calls']) * 100, 1);
                                echo $rate . '%';
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td style="padding:1rem; border-bottom:1px solid #222;">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="test_partner">
                                <input type="hidden" name="participant_id" value="<?php echo $p['participant_id']; ?>">
                                <button type="submit" class="btn-small">🔌 Test</button>
                            </form>
                            <button onclick="editPartner(<?php echo htmlspecialchars(json_encode($p)); ?>)" class="btn-small">✏️ Edit</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Edit Partner Modal (hidden by default) -->
        <div id="editModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:1000;">
            <div style="background:#111; max-width:600px; margin:100px auto; padding:2rem; border-radius:16px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:1rem;">
                    <h2 style="color:#FFDA63;">Edit Partner</h2>
                    <button onclick="closeEditModal()" style="background:none; border:none; color:#fff; font-size:1.5rem;">✕</button>
                </div>
                <form method="POST" id="editForm">
                    <input type="hidden" name="action" value="update_partner">
                    <input type="hidden" name="participant_id" id="edit_id">
                    
                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" name="name" id="edit_name" required class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label>Type</label>
                        <select name="type" id="edit_type" required class="form-control">
                            <option value="FINANCIAL_INSTITUTION">Financial Institution</option>
                            <option value="MOBILE_MONEY_OPERATOR">Mobile Money Operator</option>
                            <option value="CARD_DISTRIBUTOR">Card Distributor</option>
                            <option value="TECHNICAL_PROVIDER">Technical Provider</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category" id="edit_category" required class="form-control">
                            <option value="BANK">Bank</option>
                            <option value="MNO">MNO</option>
                            <option value="EMI_CARD">EMI/Card</option>
                            <option value="PAYMENT_PROCESSOR">Payment Processor</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Provider Code</label>
                        <input type="text" name="provider_code" id="edit_provider_code" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label>Auth Type</label>
                        <input type="text" name="auth_type" id="edit_auth_type" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label>Base URL</label>
                        <input type="url" name="base_url" id="edit_base_url" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="edit_status" class="form-control">
                            <option value="ACTIVE">Active</option>
                            <option value="INACTIVE">Inactive</option>
                        </select>
                    </div>
                    
                    <div style="margin-top:1rem;">
                        <button type="submit" class="btn btn-success">Update Partner</button>
                        <button type="button" onclick="closeEditModal()" class="btn">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function showAddForm() {
            document.getElementById('addForm').style.display = 'block';
        }
        
        function hideAddForm() {
            document.getElementById('addForm').style.display = 'none';
        }
        
        function editPartner(partner) {
            document.getElementById('edit_id').value = partner.participant_id;
            document.getElementById('edit_name').value = partner.name;
            document.getElementById('edit_type').value = partner.type;
            document.getElementById('edit_category').value = partner.category;
            document.getElementById('edit_provider_code').value = partner.provider_code || '';
            document.getElementById('edit_auth_type').value = partner.auth_type || '';
            document.getElementById('edit_base_url').value = partner.base_url || '';
            document.getElementById('edit_status').value = partner.status;
            document.getElementById('editModal').style.display = 'block';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
    </script>
</body>
</html>
