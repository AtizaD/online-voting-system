<?php
require_once '../../config/config.php';
require_once '../../config/database.php';

if (!isLoggedIn() || (!hasPermission('view_reports') && !hasPermission('manage_system'))) {
    redirectTo('auth/index.php');
}

$db = Database::getInstance()->getConnection();
$current_user = getCurrentUser();

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = isset($_GET['per_page']) ? max(10, min(500, intval($_GET['per_page']))) : 50; // Allow 10-500 per page
$offset = ($page - 1) * $per_page;

// Filter parameters
$action_filter = isset($_GET['action']) ? $_GET['action'] : '';
$user_filter = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build WHERE clause
$where_conditions = [];
$params = [];

if ($action_filter) {
    $where_conditions[] = "al.action = ?";
    $params[] = $action_filter;
}

if ($user_filter) {
    $where_conditions[] = "al.user_id = ?";
    $params[] = $user_filter;
}

if ($date_from) {
    $where_conditions[] = "DATE(al.timestamp) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "DATE(al.timestamp) <= ?";
    $params[] = $date_to;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count
$count_sql = "
    SELECT COUNT(*) as total
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    LEFT JOIN students s ON al.student_id = s.student_id
    $where_clause
";

$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetch()['total'];
$total_pages = ceil($total_records / $per_page);

// Get audit logs
$sql = "
    SELECT al.*, 
           u.first_name, u.last_name, u.email, ur.role_name,
           s.first_name as student_first_name, s.last_name as student_last_name, s.student_number
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    LEFT JOIN user_roles ur ON u.role_id = ur.role_id
    LEFT JOIN students s ON al.student_id = s.student_id
    $where_clause
    ORDER BY al.timestamp DESC
    LIMIT $per_page OFFSET $offset
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$audit_logs = $stmt->fetchAll();

// Get available actions for filter
$stmt = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action");
$available_actions = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get available users for filter
$stmt = $db->query("
    SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.email 
    FROM audit_logs al 
    JOIN users u ON al.user_id = u.user_id 
    ORDER BY u.first_name, u.last_name
");
$available_users = $stmt->fetchAll();

// Get action counts for statistics
$stmt = $db->query("
    SELECT action, COUNT(*) as count 
    FROM audit_logs 
    GROUP BY action
    ORDER BY count DESC
    LIMIT 10
");
$action_counts = $stmt->fetchAll();

$page_title = "Audit Logs";
include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-shield-alt"></i> Audit Logs</h2>
                <div class="btn-group">
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Reports
                    </a>
                    <button class="btn btn-primary" onclick="exportAuditLogs()">
                        <i class="fas fa-download"></i> Export
                    </button>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <h4><?= number_format($total_records) ?></h4>
                            <p class="mb-0">Total Logs</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <h4><?= count($action_counts) ?></h4>
                            <p class="mb-0">Action Types</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-primary text-white">
                        <div class="card-body text-center">
                            <h4><?= count($available_users) ?></h4>
                            <p class="mb-0">Active Users</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-filter"></i> Filters
                        <button class="btn btn-sm btn-outline-secondary float-right" onclick="clearFilters()">
                            Clear Filters
                        </button>
                    </h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row">
                        <div class="col-md-2">
                            <label for="action">Action</label>
                            <select name="action" id="action" class="form-control form-control-sm">
                                <option value="">All Actions</option>
                                <?php foreach ($available_actions as $action): ?>
                                    <option value="<?= $action ?>" <?= $action_filter === $action ? 'selected' : '' ?>>
                                        <?= ucfirst(str_replace('_', ' ', $action)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="user_id">User</label>
                            <select name="user_id" id="user_id" class="form-control form-control-sm">
                                <option value="">All Users</option>
                                <?php foreach ($available_users as $user): ?>
                                    <option value="<?= $user['user_id'] ?>" <?= $user_filter == $user['user_id'] ? 'selected' : '' ?>>
                                        <?= sanitize($user['first_name'] . ' ' . $user['last_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="date_from">Date From</label>
                            <input type="date" name="date_from" id="date_from" class="form-control form-control-sm" value="<?= $date_from ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="date_to">Date To</label>
                            <input type="date" name="date_to" id="date_to" class="form-control form-control-sm" value="<?= $date_to ?>">
                        </div>
                        <div class="col-md-2">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary btn-sm btn-block">
                                <i class="fas fa-search"></i> Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Audit Logs Table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-list"></i> Audit Trail
                        <span class="badge badge-secondary float-right">
                            Showing <?= count($audit_logs) ?> of <?= number_format($total_records) ?> records
                        </span>
                    </h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($audit_logs)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-search fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No audit logs found</h5>
                            <p class="text-muted">Try adjusting your search filters</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Timestamp</th>
                                        <th>Action</th>
                                        <th>User</th>
                                        <th>IP Address</th>
                                        <th>Table/Record</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($audit_logs as $log): ?>
                                        <tr>
                                            <td>
                                                <small>
                                                    <?= date('M j, Y g:i:s A', strtotime($log['timestamp'])) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php
                                                $action_icons = [
                                                    'login' => 'fas fa-sign-in-alt text-success',
                                                    'logout' => 'fas fa-sign-out-alt text-warning',
                                                    'vote' => 'fas fa-vote-yea text-primary',
                                                    'create' => 'fas fa-plus text-info',
                                                    'update' => 'fas fa-edit text-warning',
                                                    'delete' => 'fas fa-trash text-danger',
                                                    'access_denied' => 'fas fa-ban text-danger',
                                                    'permission_denied' => 'fas fa-shield-alt text-warning'
                                                ];
                                                $icon = $action_icons[$log['action']] ?? 'fas fa-info-circle text-muted';
                                                ?>
                                                <i class="<?= $icon ?>"></i>
                                                <?= ucfirst(str_replace('_', ' ', $log['action'])) ?>
                                            </td>
                                            <td>
                                                <?php if ($log['user_id']): ?>
                                                    <div>
                                                        <strong><?= sanitize($log['first_name'] . ' ' . $log['last_name']) ?></strong>
                                                        <br><small class="text-muted"><?= sanitize($log['email']) ?></small>
                                                        <br><span class="badge badge-info badge-sm"><?= ucfirst($log['role_name'] ?? 'Unknown') ?></span>
                                                    </div>
                                                <?php elseif ($log['student_id']): ?>
                                                    <div>
                                                        <strong><?= sanitize($log['student_first_name'] . ' ' . $log['student_last_name']) ?></strong>
                                                        <br><small class="text-muted">ID: <?= $log['student_number'] ?></small>
                                                        <br><span class="badge badge-success badge-sm">Student</span>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">System</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small class="font-monospace"><?= sanitize($log['ip_address']) ?></small>
                                            </td>
                                            <td>
                                                <div>
                                                    <?php if ($log['table_name']): ?>
                                                        <small class="text-muted"><?= sanitize($log['table_name']) ?></small>
                                                        <?php if ($log['record_id']): ?>
                                                            <br><span class="badge badge-info badge-sm">#<?= $log['record_id'] ?></span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($log['old_values'] || $log['new_values']): ?>
                                                    <button class="btn btn-sm btn-outline-secondary" onclick="showDetails('<?= base64_encode(json_encode(['old' => $log['old_values'], 'new' => $log['new_values']])) ?>')">
                                                        <i class="fas fa-eye"></i> View Changes
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted">No details</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Enhanced Pagination -->
                        <div class="card-footer">
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <!-- Pagination Info -->
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="text-muted">
                                            <?php
                                            $start_record = ($page - 1) * $per_page + 1;
                                            $end_record = min($page * $per_page, $total_records);
                                            ?>
                                            Showing <?= number_format($start_record) ?> to <?= number_format($end_record) ?> of <?= number_format($total_records) ?> entries
                                        </span>
                                        
                                        <!-- Per Page Selector -->
                                        <div class="d-flex align-items-center">
                                            <label for="perPageSelect" class="form-label mb-0 me-2 text-nowrap">Show:</label>
                                            <select id="perPageSelect" class="form-select form-select-sm" style="width: auto;" onchange="changePerPage(this.value)">
                                                <option value="10" <?= $per_page == 10 ? 'selected' : '' ?>>10</option>
                                                <option value="25" <?= $per_page == 25 ? 'selected' : '' ?>>25</option>
                                                <option value="50" <?= $per_page == 50 ? 'selected' : '' ?>>50</option>
                                                <option value="100" <?= $per_page == 100 ? 'selected' : '' ?>>100</option>
                                                <option value="250" <?= $per_page == 250 ? 'selected' : '' ?>>250</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <?php if ($total_pages > 1): ?>
                                        <nav aria-label="Audit logs pagination">
                                            <div class="d-flex justify-content-end align-items-center gap-2">
                                                <!-- Jump to Page -->
                                                <div class="d-flex align-items-center">
                                                    <span class="text-muted me-2">Go to:</span>
                                                    <input type="number" id="jumpToPage" class="form-control form-control-sm" 
                                                           style="width: 80px;" min="1" max="<?= $total_pages ?>" 
                                                           value="<?= $page ?>" onkeypress="handleJumpToPage(event)">
                                                    <span class="text-muted ms-1">of <?= $total_pages ?></span>
                                                </div>
                                                
                                                <!-- Navigation Buttons -->
                                                <ul class="pagination pagination-sm mb-0">
                                                    <?php
                                                    $query_params = $_GET;
                                                    unset($query_params['page']);
                                                    $base_url = '?' . http_build_query($query_params);
                                                    ?>
                                                    
                                                    <!-- First Page -->
                                                    <li class="page-item <?= $page == 1 ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="<?= $base_url ?>&page=1" title="First Page">
                                                            <i class="fas fa-angle-double-left"></i>
                                                        </a>
                                                    </li>
                                                    
                                                    <!-- Previous Page -->
                                                    <li class="page-item <?= $page == 1 ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="<?= $base_url ?>&page=<?= $page - 1 ?>" title="Previous Page">
                                                            <i class="fas fa-angle-left"></i>
                                                        </a>
                                                    </li>

                                                    <?php
                                                    // Show fewer pages on mobile, more on desktop
                                                    $show_pages = 3; // Show 3 pages on each side of current page
                                                    $start_page = max(1, $page - $show_pages);
                                                    $end_page = min($total_pages, $page + $show_pages);
                                                    
                                                    // Show first page if we're not near it
                                                    if ($start_page > 2) {
                                                        echo '<li class="page-item"><a class="page-link" href="' . $base_url . '&page=1">1</a></li>';
                                                        if ($start_page > 3) {
                                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                                        }
                                                    }
                                                    
                                                    // Show page numbers
                                                    for ($i = $start_page; $i <= $end_page; $i++):
                                                    ?>
                                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                            <a class="page-link" href="<?= $base_url ?>&page=<?= $i ?>"><?= $i ?></a>
                                                        </li>
                                                    <?php endfor; 
                                                    
                                                    // Show last page if we're not near it
                                                    if ($end_page < $total_pages - 1) {
                                                        if ($end_page < $total_pages - 2) {
                                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                                        }
                                                        echo '<li class="page-item"><a class="page-link" href="' . $base_url . '&page=' . $total_pages . '">' . $total_pages . '</a></li>';
                                                    }
                                                    ?>

                                                    <!-- Next Page -->
                                                    <li class="page-item <?= $page == $total_pages ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="<?= $base_url ?>&page=<?= $page + 1 ?>" title="Next Page">
                                                            <i class="fas fa-angle-right"></i>
                                                        </a>
                                                    </li>
                                                    
                                                    <!-- Last Page -->
                                                    <li class="page-item <?= $page == $total_pages ? 'disabled' : '' ?>">
                                                        <a class="page-link" href="<?= $base_url ?>&page=<?= $total_pages ?>" title="Last Page">
                                                            <i class="fas fa-angle-double-right"></i>
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>
                                        </nav>
                                    <?php else: ?>
                                        <div class="text-end text-muted">
                                            <small>Page 1 of 1</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Additional Details</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <pre id="modalContent" class="bg-light p-3"></pre>
            </div>
        </div>
    </div>
</div>

<script>
function showDetails(encodedData) {
    try {
        const data = JSON.parse(atob(encodedData));
        let content = '';
        
        if (data.old) {
            content += 'OLD VALUES:\n' + JSON.stringify(JSON.parse(data.old), null, 2) + '\n\n';
        }
        
        if (data.new) {
            content += 'NEW VALUES:\n' + JSON.stringify(JSON.parse(data.new), null, 2);
        }
        
        document.getElementById('modalContent').textContent = content || 'No change details available';
        $('#detailsModal').modal('show');
    } catch (e) {
        document.getElementById('modalContent').textContent = 'Error parsing change details';
        $('#detailsModal').modal('show');
    }
}

function clearFilters() {
    window.location.href = 'audit-logs.php';
}

function exportAuditLogs() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', '1');
    window.location.href = 'audit-logs.php?' + params.toString();
}

// Auto-refresh every 30 seconds
setInterval(function() {
    if (document.getElementById('auto-refresh') && document.getElementById('auto-refresh').checked) {
        window.location.reload();
    }
}, 30000);

// Pagination functions
function changePerPage(perPage) {
    const url = new URL(window.location);
    url.searchParams.set('per_page', perPage);
    url.searchParams.set('page', '1'); // Reset to first page when changing per_page
    window.location.href = url.toString();
}

function handleJumpToPage(event) {
    if (event.key === 'Enter') {
        const page = parseInt(event.target.value);
        const maxPages = <?= $total_pages ?>;
        
        if (page && page >= 1 && page <= maxPages) {
            const url = new URL(window.location);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        } else {
            event.target.value = <?= $page ?>; // Reset to current page if invalid
            alert('Please enter a valid page number between 1 and ' + maxPages);
        }
    }
}
</script>

<style>
.font-monospace {
    font-family: monospace;
}

.badge-sm {
    font-size: 0.65em;
}

.table td {
    vertical-align: middle;
}

.card {
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border: none;
}

@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .btn-group {
        flex-direction: column;
    }
    
    .btn-group .btn {
        margin-bottom: 0.25rem;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>