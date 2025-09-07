<?php
session_start();
require_once '../../include/db.php';
require_once '../../include/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role_name = $_SESSION['role_name'] ?? 'User';
$role_id = $_SESSION['role_id'] ?? 0;

// Get user permissions
$permissions = [];
if ($role_id) {
    $stmt = $conn->prepare("
        SELECT p.name 
        FROM permissions p 
        JOIN role_permissions rp ON p.id = rp.permission_id 
        WHERE rp.role_id = :role_id
    ");
    $stmt->bindParam(':role_id', $role_id);
    $stmt->execute();
    $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Check if user has permission to view reconciliation
if (!hasPermission('view_finance', $permissions) && !hasPermission('view_reconciliation', $permissions)) {
    header('Location: ../../dashboard/dashboard.php?error=access_denied');
    exit();
}

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$reconciliation_id = $_GET['id'] ?? null;

if (!$reconciliation_id) {
    header('Location: ../reconciliation.php?error=invalid_id');
    exit();
}

// Get reconciliation details
$stmt = $conn->prepare("
    SELECT r.*, ba.account_name, ba.bank_name, u.username as reconciled_by_name
    FROM reconciliation_records r
    LEFT JOIN bank_accounts ba ON r.bank_account_id = ba.id
    LEFT JOIN users u ON r.reconciled_by = u.id
    WHERE r.id = ?
");
$stmt->execute([$reconciliation_id]);
$reconciliation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reconciliation) {
    header('Location: ../reconciliation.php?error=not_found');
    exit();
}

// Get transaction matches for this reconciliation
$stmt = $conn->prepare("
    SELECT tm.*, bt.transaction_date as bank_date, bt.description as bank_description, bt.amount as bank_amount,
           s.sale_date as pos_date, s.customer_name, s.final_amount as pos_amount
    FROM transaction_matches tm
    LEFT JOIN bank_transactions bt ON tm.bank_transaction_id = bt.id
    LEFT JOIN sales s ON tm.pos_transaction_id = s.id
    WHERE tm.reconciliation_id = ?
    ORDER BY tm.created_at DESC
");
$stmt->execute([$reconciliation_id]);
$matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get discrepancies for this reconciliation
$stmt = $conn->prepare("
    SELECT rd.*, u.username as resolved_by_name
    FROM reconciliation_discrepancies rd
    LEFT JOIN users u ON rd.resolved_by = u.id
    WHERE rd.reconciliation_id = ?
    ORDER BY rd.created_at DESC
");
$stmt->execute([$reconciliation_id]);
$discrepancies = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Reconciliation - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        
        .balance-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .match-row {
            border-left: 4px solid #28a745;
        }
        
        .discrepancy-row {
            border-left: 4px solid #dc3545;
        }
    </style>
</head>
<body>
    <?php include '../../include/navmenu.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../index.php">Finance Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="../reconciliation.php">Reconciliation</a></li>
                            <li class="breadcrumb-item active">View Reconciliation</li>
                        </ol>
                    </nav>
                    <h1><i class="bi bi-eye"></i> View Reconciliation</h1>
                    <p class="header-subtitle"><?php echo htmlspecialchars($reconciliation['account_name']); ?> - <?php echo date('M d, Y', strtotime($reconciliation['reconciliation_date'])); ?></p>
                </div>
                <div class="header-actions">
                    <a href="../reconciliation.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Reconciliation
                    </a>
                    <?php if ($reconciliation['status'] == 'draft' && hasPermission('manage_reconciliation', $permissions)): ?>
                    <a href="edit.php?id=<?php echo $reconciliation['id']; ?>" class="btn btn-warning">
                        <i class="bi bi-pencil"></i> Edit
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                <!-- Reconciliation Summary -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-info-circle"></i> Reconciliation Details</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-borderless">
                                            <tr>
                                                <td><strong>Account:</strong></td>
                                                <td><?php echo htmlspecialchars($reconciliation['account_name']); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Bank:</strong></td>
                                                <td><?php echo htmlspecialchars($reconciliation['bank_name'] ?? 'N/A'); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Date:</strong></td>
                                                <td><?php echo date('M d, Y', strtotime($reconciliation['reconciliation_date'])); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Status:</strong></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $reconciliation['status'] == 'completed' ? 'success' : ($reconciliation['status'] == 'in_progress' ? 'warning' : 'secondary'); ?> status-badge">
                                                        <?php echo ucfirst($reconciliation['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-borderless">
                                            <tr>
                                                <td><strong>Reconciled By:</strong></td>
                                                <td><?php echo htmlspecialchars($reconciliation['reconciled_by_name']); ?></td>
                                            </tr>
                                            <tr>
                                                <td><strong>Created:</strong></td>
                                                <td><?php echo date('M d, Y H:i', strtotime($reconciliation['created_at'])); ?></td>
                                            </tr>
                                            <?php if ($reconciliation['completed_at']): ?>
                                            <tr>
                                                <td><strong>Completed:</strong></td>
                                                <td><?php echo date('M d, Y H:i', strtotime($reconciliation['completed_at'])); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                        </table>
                                    </div>
                                </div>
                                
                                <?php if ($reconciliation['notes']): ?>
                                <div class="mt-3">
                                    <strong>Notes:</strong>
                                    <p class="text-muted"><?php echo nl2br(htmlspecialchars($reconciliation['notes'])); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="balance-card">
                            <h6 class="mb-3">Balance Summary</h6>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between">
                                    <span>Opening Balance:</span>
                                    <span><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($reconciliation['opening_balance'], 2); ?></span>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between">
                                    <span>Closing Balance:</span>
                                    <span><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($reconciliation['closing_balance'], 2); ?></span>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between">
                                    <span>Expected Balance:</span>
                                    <span><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($reconciliation['expected_balance'], 2); ?></span>
                                </div>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between fw-bold">
                                <span>Difference:</span>
                                <span class="<?php echo $reconciliation['difference_amount'] == 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($reconciliation['difference_amount'], 2); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transaction Matches -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-check2-square"></i> Transaction Matches</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($matches)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-check2-square fs-1 text-muted mb-3"></i>
                                    <h5 class="text-muted">No Matches Yet</h5>
                                    <p class="text-muted">Start matching transactions to reconcile this account</p>
                                    <?php if ($reconciliation['status'] == 'draft' && hasPermission('manage_reconciliation', $permissions)): ?>
                                    <button class="btn btn-primary" onclick="startMatching()">
                                        <i class="bi bi-plus"></i> Start Matching
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Bank Transaction</th>
                                                <th>POS Transaction</th>
                                                <th>Match Amount</th>
                                                <th>Match Type</th>
                                                <th>Confidence</th>
                                                <th>Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($matches as $match): ?>
                                            <tr class="match-row">
                                                <td>
                                                    <?php if ($match['bank_date']): ?>
                                                    <div class="fw-semibold"><?php echo date('M d, Y', strtotime($match['bank_date'])); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($match['bank_description']); ?></small>
                                                    <div class="text-primary"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($match['bank_amount'], 2); ?></div>
                                                    <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($match['pos_date']): ?>
                                                    <div class="fw-semibold"><?php echo date('M d, Y', strtotime($match['pos_date'])); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($match['customer_name']); ?></small>
                                                    <div class="text-success"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($match['pos_amount'], 2); ?></div>
                                                    <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="fw-bold"><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($match['match_amount'], 2); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $match['match_type'] == 'automatic' ? 'success' : ($match['match_type'] == 'manual' ? 'primary' : 'warning'); ?> status-badge">
                                                        <?php echo ucfirst($match['match_type']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($match['match_confidence'] > 0): ?>
                                                    <div class="progress" style="height: 20px;">
                                                        <div class="progress-bar" role="progressbar" style="width: <?php echo $match['match_confidence']; ?>%">
                                                            <?php echo $match['match_confidence']; ?>%
                                                        </div>
                                                    </div>
                                                    <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('M d, Y H:i', strtotime($match['created_at'])); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Discrepancies -->
                <?php if (!empty($discrepancies)): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Discrepancies</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Type</th>
                                                <th>Description</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                                <th>Resolved By</th>
                                                <th>Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($discrepancies as $discrepancy): ?>
                                            <tr class="discrepancy-row">
                                                <td>
                                                    <span class="badge bg-danger status-badge">
                                                        <?php echo ucfirst(str_replace('_', ' ', $discrepancy['discrepancy_type'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($discrepancy['description']); ?></td>
                                                <td><?php echo htmlspecialchars($settings['currency_symbol'] ?? 'KES'); ?> <?php echo number_format($discrepancy['amount'], 2); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $discrepancy['status'] == 'resolved' ? 'success' : ($discrepancy['status'] == 'investigating' ? 'warning' : 'danger'); ?> status-badge">
                                                        <?php echo ucfirst($discrepancy['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($discrepancy['resolved_by_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo date('M d, Y H:i', strtotime($discrepancy['created_at'])); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function startMatching() {
            // Redirect to matching interface
            window.location.href = 'match.php?id=<?php echo $reconciliation['id']; ?>';
        }
    </script>
</body>
</html>
