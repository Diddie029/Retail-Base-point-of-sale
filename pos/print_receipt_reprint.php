<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Get system settings
$settings = [];
try {
    $stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $settings = [];
}

// Default settings
$allow_receipt_reprint = $settings['allow_receipt_reprint'] ?? '1';
$max_reprint_attempts = intval($settings['max_reprint_attempts'] ?? '3');
$require_password_for_reprint = $settings['require_password_for_reprint'] ?? '1';

// Check if receipt reprinting is allowed
if ($allow_receipt_reprint !== '1') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Receipt reprinting is disabled by administrator']);
    exit();
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    if ($action === 'verify_password') {
        $sale_id = intval($input['sale_id'] ?? 0);
        $password = $input['password'] ?? '';
        
        if (!$sale_id) {
            echo json_encode(['success' => false, 'error' => 'Invalid sale ID']);
            exit();
        }
        
        // Verify user password
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($password, $user['password'])) {
            // Log failed attempt
            logReprintAttempt($conn, $user_id, $sale_id, 'failed', 'Invalid password');
            echo json_encode(['success' => false, 'error' => 'Invalid password']);
            exit();
        }
        
        // Check reprint limit
        $reprint_count = getReprintCount($conn, $sale_id);
        if ($reprint_count >= $max_reprint_attempts) {
            echo json_encode(['success' => false, 'error' => "Maximum reprint attempts ($max_reprint_attempts) reached for this receipt"]);
            exit();
        }
        
        // Get sale data
        $sale_data = getSaleData($conn, $sale_id);
        if (!$sale_data) {
            echo json_encode(['success' => false, 'error' => 'Sale not found']);
            exit();
        }
        
        // Log successful reprint attempt
        logReprintAttempt($conn, $user_id, $sale_id, 'success', 'Password verified, receipt reprinted');
        
        // Generate receipt data
        $receipt_data = generateReceiptData($sale_data, $settings);
        
        echo json_encode([
            'success' => true, 
            'receipt_data' => $receipt_data,
            'reprint_count' => $reprint_count + 1,
            'max_attempts' => $max_reprint_attempts
        ]);
        exit();
    }
    
    if ($action === 'check_reprint_status') {
        $sale_id = intval($input['sale_id'] ?? 0);
        
        if (!$sale_id) {
            echo json_encode(['success' => false, 'error' => 'Invalid sale ID']);
            exit();
        }
        
        $reprint_count = getReprintCount($conn, $sale_id);
        $can_reprint = ($reprint_count < $max_reprint_attempts);
        
        echo json_encode([
            'success' => true,
            'can_reprint' => $can_reprint,
            'reprint_count' => $reprint_count,
            'max_attempts' => $max_reprint_attempts,
            'require_password' => ($require_password_for_reprint === '1')
        ]);
        exit();
    }
}

// Helper functions
function getReprintCount($conn, $sale_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM receipt_reprint_log WHERE sale_id = ? AND status = 'success'");
    $stmt->execute([$sale_id]);
    return intval($stmt->fetchColumn());
}

function logReprintAttempt($conn, $user_id, $sale_id, $status, $notes = '') {
    try {
        $stmt = $conn->prepare("
            INSERT INTO receipt_reprint_log 
            (sale_id, user_id, reprint_time, status, notes, user_ip) 
            VALUES (?, ?, NOW(), ?, ?, ?)
        ");
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt->execute([$sale_id, $user_id, $status, $notes, $user_ip]);
    } catch (Exception $e) {
        error_log("Failed to log reprint attempt: " . $e->getMessage());
    }
}

function getSaleData($conn, $sale_id) {
    try {
        // Get sale information
        $stmt = $conn->prepare("
            SELECT s.*, u.username as cashier_name 
            FROM sales s 
            LEFT JOIN users u ON s.user_id = u.id 
            WHERE s.id = ?
        ");
        $stmt->execute([$sale_id]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sale) return null;
        
        // Get sale items
        $stmt = $conn->prepare("
            SELECT si.*, p.name as product_name,
                   COALESCE(si.product_name, p.name) as display_name
            FROM sale_items si 
            LEFT JOIN products p ON si.product_id = p.id 
            WHERE si.sale_id = ?
        ");
        $stmt->execute([$sale_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return ['sale' => $sale, 'items' => $items];
    } catch (Exception $e) {
        error_log("Failed to get sale data: " . $e->getMessage());
        return null;
    }
}

function generateReceiptData($sale_data, $settings) {
    $sale = $sale_data['sale'];
    $items = $sale_data['items'];
    
    // Format items for receipt
    $receipt_items = [];
    foreach ($items as $item) {
        $receipt_items[] = [
            'name' => $item['display_name'] ?: $item['product_name'],
            'qty' => $item['quantity'] . ' × ' . formatCurrency($item['unit_price'] ?: $item['price'], $settings),
            'price' => formatCurrency($item['total_price'] ?: ($item['price'] * $item['quantity']), $settings)
        ];
    }
    
    return [
        'transaction_id' => generateReceiptNumber($sale['id']),
        'date' => date('Y-m-d', strtotime($sale['created_at'])),
        'time' => date('H:i:s', strtotime($sale['created_at'])),
        'payment_method' => ucfirst($sale['payment_method']),
        'company_name' => $settings['company_name'] ?? 'POS System',
        'company_address' => $settings['company_address'] ?? '',
        'subtotal' => formatCurrency($sale['total_amount'], $settings),
        'tax' => formatCurrency($sale['tax_amount'] ?? 0, $settings),
        'total' => formatCurrency($sale['final_amount'], $settings),
        'items' => $receipt_items
    ];
}

// If not AJAX request, show the interface
$sale_id = intval($_GET['sale_id'] ?? 0);

if (!$sale_id) {
    header('Location: ../sales/index.php');
    exit();
}

// Check if sale exists
$sale_data = getSaleData($conn, $sale_id);
if (!$sale_data) {
    header('Location: ../sales/index.php?error=' . urlencode('Sale not found'));
    exit();
}

$reprint_count = getReprintCount($conn, $sale_id);
$can_reprint = ($reprint_count < $max_reprint_attempts);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reprint Receipt - Sale #<?= $sale_id ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .reprint-container {
            max-width: 500px;
            margin: 2rem auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .reprint-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .reprint-body {
            padding: 2rem;
        }
        
        .sale-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .reprint-status {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        
        .status-allowed {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .status-limited {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        .status-blocked {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .password-form {
            display: none;
        }
        
        .receipt-preview {
            display: none;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 1rem;
        }
        
        .btn-reprint {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 8px;
            color: white;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .btn-reprint:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .btn-reprint:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <div class="reprint-container">
        <div class="reprint-header">
            <h3><i class="bi bi-printer"></i> Reprint Receipt</h3>
            <p class="mb-0">Sale #<?= $sale_id ?></p>
        </div>
        
        <div class="reprint-body">
            <!-- Sale Information -->
            <div class="sale-info">
                <h6><i class="bi bi-receipt"></i> Sale Information</h6>
                <div class="row">
                    <div class="col-6">
                        <small class="text-muted">Date</small><br>
                        <strong><?= date('M j, Y', strtotime($sale_data['sale']['created_at'])) ?></strong>
                    </div>
                    <div class="col-6">
                        <small class="text-muted">Total</small><br>
                        <strong><?= formatCurrency($sale_data['sale']['final_amount'], $settings) ?></strong>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-6">
                        <small class="text-muted">Customer</small><br>
                        <strong><?= htmlspecialchars($sale_data['sale']['customer_name'] ?: 'Walking Customer') ?></strong>
                    </div>
                    <div class="col-6">
                        <small class="text-muted">Cashier</small><br>
                        <strong><?= htmlspecialchars($sale_data['sale']['cashier_name']) ?></strong>
                    </div>
                </div>
            </div>
            
            <!-- Reprint Status -->
            <div class="reprint-status <?= $can_reprint ? ($reprint_count > 0 ? 'status-limited' : 'status-allowed') : 'status-blocked' ?>">
                <?php if ($can_reprint): ?>
                    <?php if ($reprint_count === 0): ?>
                        <i class="bi bi-check-circle"></i>
                        <strong>Ready to Print</strong><br>
                        <small>This receipt has not been reprinted yet</small>
                    <?php else: ?>
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Limited Reprints Remaining</strong><br>
                        <small>Reprinted <?= $reprint_count ?> of <?= $max_reprint_attempts ?> times</small>
                    <?php endif; ?>
                <?php else: ?>
                    <i class="bi bi-x-circle"></i>
                    <strong>Reprint Limit Reached</strong><br>
                    <small>Maximum <?= $max_reprint_attempts ?> reprints allowed</small>
                <?php endif; ?>
            </div>
            
            <?php if ($can_reprint): ?>
                <!-- Password Form -->
                <?php if ($require_password_for_reprint === '1'): ?>
                <div class="password-form" id="passwordForm">
                    <h6><i class="bi bi-shield-lock"></i> Verify Your Password</h6>
                    <div class="mb-3">
                        <label for="userPassword" class="form-label">Enter your password to authorize reprint</label>
                        <input type="password" class="form-control" id="userPassword" placeholder="Your password" required>
                        <div class="invalid-feedback" id="passwordError"></div>
                    </div>
                    <button type="button" class="btn btn-reprint w-100" onclick="verifyPassword()">
                        <i class="bi bi-unlock"></i> Verify & Print Receipt
                    </button>
                </div>
                <?php endif; ?>
                
                <!-- Direct Print Button (if no password required) -->
                <?php if ($require_password_for_reprint !== '1'): ?>
                <button type="button" class="btn btn-reprint w-100" onclick="directReprint()">
                    <i class="bi bi-printer"></i> Reprint Receipt
                </button>
                <?php endif; ?>
                
                <!-- Loading State -->
                <div class="loading" id="loadingState">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Processing...</span>
                    </div>
                    <p class="mt-2 mb-0">Preparing receipt...</p>
                </div>
                
                <!-- Receipt Preview -->
                <div class="receipt-preview" id="receiptPreview"></div>
                
            <?php else: ?>
                <div class="text-center">
                    <button type="button" class="btn btn-secondary" onclick="window.close()">
                        <i class="bi bi-x-circle"></i> Close
                    </button>
                </div>
            <?php endif; ?>
            
            <!-- Action Buttons -->
            <div class="mt-3 text-center">
                <a href="../sales/index.php" class="btn btn-outline-secondary me-2">
                    <i class="bi bi-arrow-left"></i> Back to Sales
                </a>
                <?php if ($can_reprint && $require_password_for_reprint === '1'): ?>
                <button type="button" class="btn btn-outline-primary" onclick="showPasswordForm()">
                    <i class="bi bi-printer"></i> Reprint Receipt
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        const saleId = <?= $sale_id ?>;
        const requirePassword = <?= $require_password_for_reprint === '1' ? 'true' : 'false' ?>;
        
        function showPasswordForm() {
            document.getElementById('passwordForm').style.display = 'block';
            document.getElementById('userPassword').focus();
        }
        
        function verifyPassword() {
            const password = document.getElementById('userPassword').value;
            const passwordError = document.getElementById('passwordError');
            const loadingState = document.getElementById('loadingState');
            
            if (!password) {
                showError('Please enter your password');
                return;
            }
            
            // Clear previous errors
            passwordError.textContent = '';
            document.getElementById('userPassword').classList.remove('is-invalid');
            
            // Show loading state
            loadingState.style.display = 'block';
            
            fetch('print_receipt_reprint.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'verify_password',
                    sale_id: saleId,
                    password: password
                })
            })
            .then(response => response.json())
            .then(data => {
                loadingState.style.display = 'none';
                
                if (data.success) {
                    showReceiptPreview(data.receipt_data);
                    printReceipt(data.receipt_data);
                    
                    // Show success message
                    showSuccess('Receipt reprinted successfully! (' + data.reprint_count + '/' + data.max_attempts + ')');
                } else {
                    showError(data.error || 'Failed to verify password');
                }
            })
            .catch(error => {
                loadingState.style.display = 'none';
                showError('Network error. Please try again.');
            });
        }
        
        function directReprint() {
            const loadingState = document.getElementById('loadingState');
            loadingState.style.display = 'block';
            
            fetch('print_receipt_reprint.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'verify_password',
                    sale_id: saleId,
                    password: 'direct' // No password required
                })
            })
            .then(response => response.json())
            .then(data => {
                loadingState.style.display = 'none';
                
                if (data.success) {
                    printReceipt(data.receipt_data);
                    showSuccess('Receipt reprinted successfully!');
                } else {
                    showError(data.error || 'Failed to reprint receipt');
                }
            })
            .catch(error => {
                loadingState.style.display = 'none';
                showError('Network error. Please try again.');
            });
        }
        
        function showReceiptPreview(receiptData) {
            const preview = document.getElementById('receiptPreview');
            preview.innerHTML = `
                <h6>Receipt Preview</h6>
                <div class="text-center">
                    <strong>${receiptData.company_name}</strong><br>
                    <small>${receiptData.company_address}</small><br><br>
                    Transaction: ${receiptData.transaction_id}<br>
                    Date: ${receiptData.date} ${receiptData.time}<br>
                    Payment: ${receiptData.payment_method}<br><br>
                </div>
                <div class="receipt-items">
                    ${receiptData.items.map(item => `
                        <div style="display: flex; justify-content: space-between;">
                            <div>
                                ${item.name}<br>
                                <small>${item.qty}</small>
                            </div>
                            <div>${item.price}</div>
                        </div>
                    `).join('<br>')}
                </div>
                <hr>
                <div style="display: flex; justify-content: space-between;">
                    <span>Subtotal:</span><span>${receiptData.subtotal}</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span>Tax:</span><span>${receiptData.tax}</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-weight: bold;">
                    <span>TOTAL:</span><span>${receiptData.total}</span>
                </div>
            `;
            preview.style.display = 'block';
        }
        
        function printReceipt(receiptData) {
            // Open print receipt window
            const printUrl = 'print_receipt.php?data=' + encodeURIComponent(JSON.stringify(receiptData)) + '&auto_print=true';
            window.open(printUrl, '_blank');
        }
        
        function showError(message) {
            const passwordError = document.getElementById('passwordError');
            const passwordInput = document.getElementById('userPassword');
            
            if (passwordError && passwordInput) {
                passwordError.textContent = message;
                passwordInput.classList.add('is-invalid');
            } else {
                alert('Error: ' + message);
            }
        }
        
        function showSuccess(message) {
            // Create success alert
            const alert = document.createElement('div');
            alert.className = 'alert alert-success alert-dismissible fade show';
            alert.innerHTML = `
                <i class="bi bi-check-circle"></i> ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.querySelector('.reprint-body').insertBefore(alert, document.querySelector('.reprint-body').firstChild);
            
            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.remove();
                }
            }, 5000);
        }
        
        // Auto-show password form if required
        if (requirePassword) {
            document.addEventListener('DOMContentLoaded', () => {
                // Don't auto-show, let user click the button
            });
        }
        
        // Handle Enter key in password field
        document.addEventListener('DOMContentLoaded', () => {
            const passwordInput = document.getElementById('userPassword');
            if (passwordInput) {
                passwordInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        verifyPassword();
                    }
                });
            }
        });
    </script>
</body>
</html>
