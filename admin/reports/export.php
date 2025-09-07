<?php
require_once '../../config/config.php';
require_once '../../config/database.php';

if (!isLoggedIn() || (!hasPermission('view_reports') && !hasPermission('manage_system'))) {
    redirectTo('auth/index.php');
}

$db = Database::getInstance()->getConnection();
$current_user = getCurrentUser();

// Handle export requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_type'])) {
    handleExport($_POST);
    exit;
}

// Get available elections for export
$stmt = $db->query("
    SELECT election_id, name, status, start_date, end_date
    FROM elections 
    ORDER BY start_date DESC
");
$elections = $stmt->fetchAll();

// Get export statistics
$stmt = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM elections) as total_elections,
        (SELECT COUNT(*) FROM students WHERE is_active = 1) as total_students,
        (SELECT COUNT(*) FROM users WHERE is_active = 1) as total_users,
        (SELECT COUNT(*) FROM voting_sessions WHERE status = 'completed') as total_votes,
        (SELECT COUNT(*) FROM audit_logs) as total_audit_logs
");
$export_stats = $stmt->fetch();

function handleExport($post_data) {
    global $db, $current_user;
    
    $export_type = $post_data['export_type'];
    $format = $post_data['format'] ?? 'csv';
    $election_id = isset($post_data['election_id']) ? intval($post_data['election_id']) : 0;
    $date_from = $post_data['date_from'] ?? '';
    $date_to = $post_data['date_to'] ?? '';
    
    // Log export activity
    logActivity('data_export', "Data export: $export_type ($format)", $current_user['id']);
    
    switch ($export_type) {
        case 'election_results':
            exportElectionResults($election_id, $format);
            break;
        case 'voting_sessions':
            exportVotingSessions($election_id, $format);
            break;
        case 'audit_logs':
            exportAuditLogs($date_from, $date_to, $format);
            break;
        case 'student_data':
            exportStudentData($format);
            break;
        case 'user_data':
            exportUserData($format);
            break;
        case 'system_statistics':
            exportSystemStatistics($format);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid export type']);
            break;
    }
}

function exportElectionResults($election_id, $format) {
    global $db;
    
    if (!$election_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Election ID required']);
        return;
    }
    
    // Get election info
    $stmt = $db->prepare("
        SELECT e.*, et.name as election_type_name 
        FROM elections e
        LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
        WHERE e.election_id = ?
    ");
    $stmt->execute([$election_id]);
    $election = $stmt->fetch();
    
    if (!$election) {
        http_response_code(404);
        echo json_encode(['error' => 'Election not found']);
        return;
    }
    
    // Get detailed results
    $stmt = $db->prepare("
        SELECT 
            p.title as position,
            c.first_name,
            c.last_name,
            COUNT(v.vote_id) as vote_count,
            ROUND((COUNT(v.vote_id) * 100.0 / (
                SELECT COUNT(*) 
                FROM votes v2 
                JOIN election_positions ep2 ON v2.position_id = ep2.position_id 
                WHERE ep2.election_id = ?
            )), 2) as percentage
        FROM election_positions p
        LEFT JOIN candidates c ON p.position_id = c.position_id
        LEFT JOIN votes v ON c.candidate_id = v.candidate_id
        WHERE p.election_id = ?
        GROUP BY p.position_id, c.candidate_id
        ORDER BY p.title, vote_count DESC
    ");
    $stmt->execute([$election_id, $election_id]);
    $results = $stmt->fetchAll();
    
    $filename = sanitizeFilename("election_results_" . $election['name'] . "_" . date('Y-m-d'));
    
    if ($format === 'json') {
        exportToJSON([
            'election' => $election,
            'results' => $results
        ], $filename);
    } else {
        $headers = ['Position', 'Candidate First Name', 'Candidate Last Name', 'Vote Count', 'Percentage'];
        $data = array_map(function($row) {
            return [
                $row['position'],
                $row['first_name'] ?? 'N/A',
                $row['last_name'] ?? 'N/A',
                $row['vote_count'],
                $row['percentage'] . '%'
            ];
        }, $results);
        
        exportToCSV($data, $headers, $filename);
    }
}

function exportVotingSessions($election_id, $format) {
    global $db;
    
    $where = '';
    $params = [];
    
    if ($election_id) {
        $where = 'WHERE vs.election_id = ?';
        $params[] = $election_id;
    }
    
    $stmt = $db->prepare("
        SELECT 
            vs.session_id,
            vs.student_id,
            s.first_name,
            s.last_name,
            s.student_number,
            s.program,
            s.year_level,
            e.name as election_name,
            vs.ip_address,
            vs.user_agent,
            vs.created_at,
            vs.status
        FROM voting_sessions vs
        LEFT JOIN students s ON vs.student_id = s.student_id
        LEFT JOIN elections e ON vs.election_id = e.election_id
        $where
        ORDER BY vs.created_at DESC
    ");
    $stmt->execute($params);
    $sessions = $stmt->fetchAll();
    
    $filename = $election_id 
        ? sanitizeFilename("voting_sessions_election_" . $election_id . "_" . date('Y-m-d'))
        : "voting_sessions_all_" . date('Y-m-d');
    
    if ($format === 'json') {
        exportToJSON($sessions, $filename);
    } else {
        $headers = ['Session ID', 'Student ID', 'First Name', 'Last Name', 'Student Number', 'Program', 'Year Level', 'Election', 'IP Address', 'User Agent', 'Timestamp', 'Status'];
        $data = array_map(function($row) {
            return [
                $row['session_id'],
                $row['student_id'],
                $row['first_name'],
                $row['last_name'],
                $row['student_number'],
                $row['program'],
                $row['year_level'],
                $row['election_name'],
                $row['ip_address'],
                $row['user_agent'],
                $row['created_at'],
                $row['status']
            ];
        }, $sessions);
        
        exportToCSV($data, $headers, $filename);
    }
}

function exportAuditLogs($date_from, $date_to, $format) {
    global $db;
    
    $where_conditions = [];
    $params = [];
    
    if ($date_from) {
        $where_conditions[] = "DATE(al.timestamp) >= ?";
        $params[] = $date_from;
    }
    
    if ($date_to) {
        $where_conditions[] = "DATE(al.timestamp) <= ?";
        $params[] = $date_to;
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    $stmt = $db->prepare("
        SELECT 
            al.log_id,
            al.action,
            al.table_name,
            al.record_id,
            al.ip_address,
            al.user_agent,
            al.timestamp,
            u.first_name as user_first_name,
            u.last_name as user_last_name,
            u.email as user_email,
            ur.role_name,
            s.first_name as student_first_name,
            s.last_name as student_last_name,
            s.student_number
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        LEFT JOIN user_roles ur ON u.role_id = ur.role_id
        LEFT JOIN students s ON al.student_id = s.student_id
        $where_clause
        ORDER BY al.timestamp DESC
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
    
    $filename = "audit_logs_" . ($date_from ?: 'all') . "_to_" . ($date_to ?: 'now') . "_" . date('Y-m-d');
    
    if ($format === 'json') {
        exportToJSON($logs, $filename);
    } else {
        $headers = ['Log ID', 'Action', 'Table Name', 'Record ID', 'IP Address', 'User Agent', 'Timestamp', 'User Name', 'User Email', 'User Role', 'Student Name', 'Student Number'];
        $data = array_map(function($row) {
            $user_name = ($row['user_first_name'] && $row['user_last_name']) 
                ? $row['user_first_name'] . ' ' . $row['user_last_name'] 
                : '';
            $student_name = ($row['student_first_name'] && $row['student_last_name']) 
                ? $row['student_first_name'] . ' ' . $row['student_last_name'] 
                : '';
                
            return [
                $row['log_id'],
                $row['action'],
                $row['table_name'],
                $row['record_id'],
                $row['ip_address'],
                $row['user_agent'],
                $row['timestamp'],
                $user_name,
                $row['user_email'],
                $row['role_name'],
                $student_name,
                $row['student_number']
            ];
        }, $logs);
        
        exportToCSV($data, $headers, $filename);
    }
}

function exportStudentData($format) {
    global $db;
    
    $stmt = $db->query("
        SELECT 
            student_id,
            student_number,
            first_name,
            last_name,
            email,
            program,
            year_level,
            is_active,
            created_at
        FROM students
        ORDER BY student_number
    ");
    $students = $stmt->fetchAll();
    
    $filename = "students_data_" . date('Y-m-d');
    
    if ($format === 'json') {
        exportToJSON($students, $filename);
    } else {
        $headers = ['Student ID', 'Student Number', 'First Name', 'Last Name', 'Email', 'Program', 'Year Level', 'Active Status', 'Created At'];
        $data = array_map(function($row) {
            return [
                $row['student_id'],
                $row['student_number'],
                $row['first_name'],
                $row['last_name'],
                $row['email'],
                $row['program'],
                $row['year_level'],
                $row['is_active'] ? 'Active' : 'Inactive',
                $row['created_at']
            ];
        }, $students);
        
        exportToCSV($data, $headers, $filename);
    }
}

function exportUserData($format) {
    global $db;
    
    $stmt = $db->query("
        SELECT 
            u.user_id,
            u.first_name,
            u.last_name,
            u.email,
            ur.role_name,
            u.is_active,
            u.last_login,
            u.created_at
        FROM users u
        LEFT JOIN user_roles ur ON u.role_id = ur.role_id
        ORDER BY u.created_at DESC
    ");
    $users = $stmt->fetchAll();
    
    $filename = "users_data_" . date('Y-m-d');
    
    if ($format === 'json') {
        exportToJSON($users, $filename);
    } else {
        $headers = ['User ID', 'First Name', 'Last Name', 'Email', 'Role', 'Active Status', 'Last Login', 'Created At'];
        $data = array_map(function($row) {
            return [
                $row['user_id'],
                $row['first_name'],
                $row['last_name'],
                $row['email'],
                $row['role_name'],
                $row['is_active'] ? 'Active' : 'Inactive',
                $row['last_login'],
                $row['created_at']
            ];
        }, $users);
        
        exportToCSV($data, $headers, $filename);
    }
}

function exportSystemStatistics($format) {
    global $db;
    
    // Gather comprehensive system statistics
    $stats = [];
    
    // Basic counts
    $stmt = $db->query("
        SELECT 
            (SELECT COUNT(*) FROM elections) as total_elections,
            (SELECT COUNT(*) FROM students WHERE is_active = 1) as active_students,
            (SELECT COUNT(*) FROM users WHERE is_active = 1) as active_users,
            (SELECT COUNT(*) FROM voting_sessions WHERE status = 'completed') as completed_votes,
            (SELECT COUNT(*) FROM audit_logs) as total_audit_logs
    ");
    $basic_stats = $stmt->fetch();
    
    // Elections by status
    $stmt = $db->query("SELECT status, COUNT(*) as count FROM elections GROUP BY status");
    $elections_by_status = $stmt->fetchAll();
    
    // Students by program
    $stmt = $db->query("SELECT program, COUNT(*) as count FROM students WHERE is_active = 1 GROUP BY program");
    $students_by_program = $stmt->fetchAll();
    
    // Recent activity (last 30 days)
    $stmt = $db->query("
        SELECT action, COUNT(*) as count 
        FROM audit_logs 
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
        GROUP BY action
    ");
    $recent_activity = $stmt->fetchAll();
    
    $stats = [
        'generated_at' => date('Y-m-d H:i:s'),
        'basic_statistics' => $basic_stats,
        'elections_by_status' => $elections_by_status,
        'students_by_program' => $students_by_program,
        'recent_activity_30_days' => $recent_activity
    ];
    
    $filename = "system_statistics_" . date('Y-m-d');
    
    if ($format === 'json') {
        exportToJSON($stats, $filename);
    } else {
        // For CSV, flatten the statistics
        $csv_data = [];
        $csv_data[] = ['Metric', 'Value'];
        $csv_data[] = ['Generated At', $stats['generated_at']];
        $csv_data[] = ['Total Elections', $basic_stats['total_elections']];
        $csv_data[] = ['Active Students', $basic_stats['active_students']];
        $csv_data[] = ['Active Users', $basic_stats['active_users']];
        $csv_data[] = ['Completed Votes', $basic_stats['completed_votes']];
        $csv_data[] = ['Total Audit Logs', $basic_stats['total_audit_logs']];
        
        $csv_data[] = ['', ''];
        $csv_data[] = ['Elections by Status', ''];
        foreach ($elections_by_status as $item) {
            $csv_data[] = [ucfirst($item['status']), $item['count']];
        }
        
        $csv_data[] = ['', ''];
        $csv_data[] = ['Students by Program', ''];
        foreach ($students_by_program as $item) {
            $csv_data[] = [$item['program'], $item['count']];
        }
        
        exportToCSV($csv_data, [], $filename);
    }
}

function exportToCSV($data, $headers, $filename) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Cache-Control: no-store, no-cache');
    
    $output = fopen('php://output', 'w');
    
    if (!empty($headers)) {
        fputcsv($output, $headers);
    }
    
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
}

function exportToJSON($data, $filename) {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '.json"');
    header('Cache-Control: no-store, no-cache');
    
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function sanitizeFilename($filename) {
    return preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
}

$page_title = "Data Export";
include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-download"></i> Data Export</h2>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Reports
                </a>
            </div>

            <!-- Export Statistics -->
            <div class="row mb-4">
                <div class="col-md-2">
                    <div class="card bg-primary text-white">
                        <div class="card-body text-center">
                            <h4><?= number_format($export_stats['total_elections']) ?></h4>
                            <p class="mb-0">Elections</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <h4><?= number_format($export_stats['total_students']) ?></h4>
                            <p class="mb-0">Students</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <h4><?= number_format($export_stats['total_users']) ?></h4>
                            <p class="mb-0">Users</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-warning text-white">
                        <div class="card-body text-center">
                            <h4><?= number_format($export_stats['total_votes']) ?></h4>
                            <p class="mb-0">Votes</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-dark text-white">
                        <div class="card-body text-center">
                            <h4><?= number_format($export_stats['total_audit_logs']) ?></h4>
                            <p class="mb-0">Audit Logs</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Export Options -->
            <div class="row">
                <!-- Election Results Export -->
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-bar"></i> Election Results</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">Export detailed election results including vote counts and percentages.</p>
                            <form method="POST" class="export-form">
                                <input type="hidden" name="export_type" value="election_results">
                                <div class="form-group">
                                    <label for="election_select">Select Election</label>
                                    <select name="election_id" id="election_select" class="form-control" required>
                                        <option value="">Choose an election...</option>
                                        <?php foreach ($elections as $election): ?>
                                            <option value="<?= $election['election_id'] ?>">
                                                <?= sanitize($election['name']) ?> (<?= ucfirst($election['status']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="format_results">Export Format</label>
                                    <select name="format" id="format_results" class="form-control">
                                        <option value="csv">CSV (Excel Compatible)</option>
                                        <option value="json">JSON (Raw Data)</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary btn-block">
                                    <i class="fas fa-download"></i> Export Results
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Voting Sessions Export -->
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-users"></i> Voting Sessions</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">Export voting session data with student information and timestamps.</p>
                            <form method="POST" class="export-form">
                                <input type="hidden" name="export_type" value="voting_sessions">
                                <div class="form-group">
                                    <label for="election_select_sessions">Filter by Election (Optional)</label>
                                    <select name="election_id" id="election_select_sessions" class="form-control">
                                        <option value="">All Elections</option>
                                        <?php foreach ($elections as $election): ?>
                                            <option value="<?= $election['election_id'] ?>">
                                                <?= sanitize($election['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="format_sessions">Export Format</label>
                                    <select name="format" id="format_sessions" class="form-control">
                                        <option value="csv">CSV (Excel Compatible)</option>
                                        <option value="json">JSON (Raw Data)</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-info btn-block">
                                    <i class="fas fa-download"></i> Export Sessions
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Audit Logs Export -->
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-shield-alt"></i> Audit Logs</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">Export security audit logs for compliance and monitoring.</p>
                            <form method="POST" class="export-form">
                                <input type="hidden" name="export_type" value="audit_logs">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="date_from_audit">From Date</label>
                                            <input type="date" name="date_from" id="date_from_audit" class="form-control">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="date_to_audit">To Date</label>
                                            <input type="date" name="date_to" id="date_to_audit" class="form-control">
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="format_audit">Export Format</label>
                                    <select name="format" id="format_audit" class="form-control">
                                        <option value="csv">CSV (Excel Compatible)</option>
                                        <option value="json">JSON (Raw Data)</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-warning btn-block">
                                    <i class="fas fa-download"></i> Export Audit Logs
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Student Data Export -->
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-graduation-cap"></i> Student Data</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">Export comprehensive student database information.</p>
                            <form method="POST" class="export-form">
                                <input type="hidden" name="export_type" value="student_data">
                                <div class="form-group">
                                    <label for="format_students">Export Format</label>
                                    <select name="format" id="format_students" class="form-control">
                                        <option value="csv">CSV (Excel Compatible)</option>
                                        <option value="json">JSON (Raw Data)</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-success btn-block">
                                    <i class="fas fa-download"></i> Export Students
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- User Data Export -->
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-users-cog"></i> User Data</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">Export system user accounts and role information.</p>
                            <form method="POST" class="export-form">
                                <input type="hidden" name="export_type" value="user_data">
                                <div class="form-group">
                                    <label for="format_users">Export Format</label>
                                    <select name="format" id="format_users" class="form-control">
                                        <option value="csv">CSV (Excel Compatible)</option>
                                        <option value="json">JSON (Raw Data)</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-secondary btn-block">
                                    <i class="fas fa-download"></i> Export Users
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- System Statistics Export -->
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-line"></i> System Statistics</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">Export comprehensive system statistics and analytics.</p>
                            <form method="POST" class="export-form">
                                <input type="hidden" name="export_type" value="system_statistics">
                                <div class="form-group">
                                    <label for="format_stats">Export Format</label>
                                    <select name="format" id="format_stats" class="form-control">
                                        <option value="csv">CSV (Excel Compatible)</option>
                                        <option value="json">JSON (Raw Data)</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-dark btn-block">
                                    <i class="fas fa-download"></i> Export Statistics
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Export Guidelines -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5><i class="fas fa-info-circle"></i> Export Guidelines</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>CSV Format</h6>
                            <ul class="text-muted small">
                                <li>Excel and spreadsheet compatible</li>
                                <li>Easy to view and analyze</li>
                                <li>Suitable for data analysis</li>
                                <li>Can be imported into other systems</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>JSON Format</h6>
                            <ul class="text-muted small">
                                <li>Machine-readable raw data</li>
                                <li>Preserves data structure</li>
                                <li>Suitable for technical integration</li>
                                <li>Can be processed by APIs</li>
                            </ul>
                        </div>
                    </div>
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-shield-alt"></i>
                        <strong>Security Notice:</strong> All data exports are logged for security audit purposes. 
                        Handle exported data according to your institution's data protection policies.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Add loading state to export buttons
document.querySelectorAll('.export-form').forEach(form => {
    form.addEventListener('submit', function(e) {
        const button = this.querySelector('button[type="submit"]');
        const originalText = button.innerHTML;
        
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
        button.disabled = true;
        
        // Re-enable button after 5 seconds (in case of issues)
        setTimeout(() => {
            button.innerHTML = originalText;
            button.disabled = false;
        }, 5000);
    });
});

// Set default date range for audit logs (last 30 days)
document.getElementById('date_from_audit').value = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
document.getElementById('date_to_audit').value = new Date().toISOString().split('T')[0];
</script>

<style>
.card {
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border: none;
    margin-bottom: 1.5rem;
}

.export-form {
    margin-bottom: 0;
}

.alert {
    border: none;
    border-radius: 8px;
}

@media (max-width: 768px) {
    .row .col-md-6 {
        margin-bottom: 1rem;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>