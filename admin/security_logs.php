<?php
session_start();
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/functions.php';
require_once __DIR__ . '/../include/classes/SecurityManager.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'] ?? 0;
$role_name = $_SESSION['role_name'] ?? 'User';

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

// Check permissions - admin users and users with security logs permission can view
$isAdmin = (
    $role_name === 'Admin' || 
    $role_name === 'admin' || 
    $role_name === 'Administrator' || 
    $role_name === 'administrator' ||
    hasPermission('manage_users', $permissions) ||
    hasPermission('manage_settings', $permissions) ||
    hasPermission('manage_roles', $permissions)
);

if (!hasPermission('view_security_logs', $permissions) && !$isAdmin) {
    header("Location: ../dashboard/dashboard.php");
    exit();
}

// Initialize security manager
$security = new SecurityManager($conn);

// Get system settings
$settings = [];
$stmt = $conn->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle filters
$severity_filter = $_GET['severity'] ?? '';
$event_type_filter = $_GET['event_type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Build query with filters
$where_conditions = [];
$params = [];

if (!empty($severity_filter)) {
    $where_conditions[] = "severity = :severity";
    $params[':severity'] = $severity_filter;
}

if (!empty($event_type_filter)) {
    $where_conditions[] = "event_type LIKE :event_type";
    $params[':event_type'] = '%' . $event_type_filter . '%';
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(created_at) >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(created_at) <= :date_to";
    $params[':date_to'] = $date_to;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM security_logs $where_clause";
$stmt = $conn->prepare($count_sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $per_page);

// Get security logs
$sql = "SELECT * FROM security_logs $where_clause ORDER BY created_at DESC LIMIT :offset, :per_page";
$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
$stmt->execute();
$security_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique event types for filter dropdown
$event_types = [];
$stmt = $conn->query("SELECT DISTINCT event_type FROM security_logs ORDER BY event_type");
$event_types = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get security statistics
$stats = [];
$stmt = $conn->query("
    SELECT 
        severity,
        COUNT(*) as count
    FROM security_logs 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY severity
");
$severity_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($severity_stats as $stat) {
    $stats[$stat['severity']] = $stat['count'];
}

// Get recent critical events
$stmt = $conn->query("
    SELECT * FROM security_logs 
    WHERE severity IN ('high', 'critical') 
    ORDER BY created_at DESC 
    LIMIT 10
");
$critical_events = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Logs - <?php echo htmlspecialchars($settings['company_name'] ?? 'POS System'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        :root {
            --primary-color: <?php echo $settings['theme_color'] ?? '#6366f1'; ?>;
            --sidebar-color: <?php echo $settings['sidebar_color'] ?? '#1e293b'; ?>;
        }
        .severity-low { color: #28a745; }
        .severity-medium { color: #ffc107; }
        .severity-high { color: #fd7e14; }
        .severity-critical { color: #dc3545; }
        .log-details { max-height: 200px; overflow-y: auto; }
        .stats-card { transition: transform 0.2s; }
        .stats-card:hover { transform: translateY(-2px); }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php
    $current_page = 'security';
    include __DIR__ . '/../include/navmenu.php';
    ?>

    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="bi bi-shield-check me-2"></i>Security Logs</h1>
                    <p class="header-subtitle">Monitor security events and system activity</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-outline-primary" onclick="exportLogs()">
                        <i class="bi bi-download me-1"></i>Export Logs
                    </button>
                    <button class="btn btn-primary" onclick="refreshLogs()">
                        <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                    </button>
                </div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stats-card">
                        <div class="card-body text-center">
                            <i class="bi bi-shield-exclamation text-danger fs-1"></i>
                            <h3 class="mt-2"><?php echo $stats['critical'] ?? 0; ?></h3>
                            <p class="text-muted mb-0">Critical Events</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card">
                        <div class="card-body text-center">
                            <i class="bi bi-shield-x text-warning fs-1"></i>
                            <h3 class="mt-2"><?php echo $stats['high'] ?? 0; ?></h3>
                            <p class="text-muted mb-0">High Severity</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card">
                        <div class="card-body text-center">
                            <i class="bi bi-shield-minus text-info fs-1"></i>
                            <h3 class="mt-2"><?php echo $stats['medium'] ?? 0; ?></h3>
                            <p class="text-muted mb-0">Medium Severity</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card">
                        <div class="card-body text-center">
                            <i class="bi bi-shield-check text-success fs-1"></i>
                            <h3 class="mt-2"><?php echo $stats['low'] ?? 0; ?></h3>
                            <p class="text-muted mb-0">Low Severity</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Filters</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="severity" class="form-label">Severity</label>
                            <select class="form-select" id="severity" name="severity">
                                <option value="">All Severities</option>
                                <option value="low" <?php echo $severity_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                                <option value="medium" <?php echo $severity_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="high" <?php echo $severity_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                <option value="critical" <?php echo $severity_filter === 'critical' ? 'selected' : ''; ?>>Critical</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="event_type" class="form-label">Event Type</label>
                            <select class="form-select" id="event_type" name="event_type">
                                <option value="">All Event Types</option>
                                <?php foreach ($event_types as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $event_type_filter === $type ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $type))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="date_from" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="date_to" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search me-1"></i>Filter
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Security Logs Table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Security Events (<?php echo number_format($total_records); ?> total)</h5>
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">
                            <i class="bi bi-x-circle me-1"></i>Clear Filters
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($security_logs)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-shield-check text-muted" style="font-size: 3rem;"></i>
                            <h5 class="mt-3 text-muted">No security events found</h5>
                            <p class="text-muted">No events match your current filters.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Timestamp</th>
                                        <th>Event Type</th>
                                        <th>Severity</th>
                                        <th>IP Address</th>
                                        <th>Details</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($security_logs as $log): ?>
                                        <tr>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $log['event_type']))); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $log['severity'] === 'critical' ? 'danger' : ($log['severity'] === 'high' ? 'warning' : ($log['severity'] === 'medium' ? 'info' : 'success')); ?>">
                                                    <?php echo ucfirst($log['severity']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <code><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></code>
                                            </td>
                                            <td>
                                                <?php if (!empty($log['details'])): ?>
                                                    <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#details-<?php echo $log['id']; ?>">
                                                        <i class="bi bi-eye me-1"></i>View Details
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted">No details</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-outline-info" onclick="viewLogDetails(<?php echo $log['id']; ?>)" title="View Full Details">
                                                        <i class="bi bi-info-circle"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php if (!empty($log['details'])): ?>
                                            <tr class="collapse" id="details-<?php echo $log['id']; ?>">
                                                <td colspan="6">
                                                    <div class="p-3 bg-light">
                                                        <h6>Event Details:</h6>
                                                        <pre class="log-details mb-2"><?php echo htmlspecialchars($log['details']); ?></pre>
                                                        <?php if (!empty($log['user_agent'])): ?>
                                                            <h6>User Agent:</h6>
                                                            <small class="text-muted"><?php echo htmlspecialchars($log['user_agent']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="card-footer">
                                <nav aria-label="Security logs pagination">
                                    <ul class="pagination justify-content-center mb-0">
                                        <?php if ($page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Previous</a>
                                            </li>
                                        <?php endif; ?>
                                        
                                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <?php if ($page < $total_pages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Log Details Modal -->
    <div class="modal fade" id="logDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Security Log Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="logDetailsContent">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function viewLogDetails(logId) {
            // This would typically make an AJAX call to get full log details
            // For now, we'll show a placeholder
            document.getElementById('logDetailsContent').innerHTML = `
                <div class="text-center">
                    <i class="bi bi-info-circle text-primary fs-1"></i>
                    <h5 class="mt-3">Log ID: ${logId}</h5>
                    <p class="text-muted">Full details would be loaded here via AJAX.</p>
                </div>
            `;
            new bootstrap.Modal(document.getElementById('logDetailsModal')).show();
        }

        function clearFilters() {
            window.location.href = 'security_logs.php';
        }

        function refreshLogs() {
            window.location.reload();
        }

        function exportLogs() {
            // Get current filter parameters
            const params = new URLSearchParams(window.location.search);
            window.open('../api/security/export_logs.php?' + params.toString(), '_blank');
        }

        // Auto-refresh every 30 seconds
        setInterval(function() {
            // Only refresh if no modal is open and user is on the page
            if (!document.querySelector('.modal.show')) {
                // You could implement a subtle refresh indicator here
            }
        }, 30000);
    </script>
</body>
</html>
