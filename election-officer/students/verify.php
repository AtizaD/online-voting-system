<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['election_officer']);

$page_title = 'Student Verification';
$breadcrumbs = [
    ['title' => 'Election Officer', 'url' => SITE_URL . 'election-officer/'],
    ['title' => 'Student Verification']
];

$db = Database::getInstance()->getConnection();

// Handle pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = max(10, min(100, intval($_GET['per_page'] ?? 15))); // Allow 10-100, default 15
$offset = ($page - 1) * $per_page;

// Handle filters
$status_filter = $_GET['status'] ?? 'pending';
$program_filter = $_GET['program'] ?? '';
$class_filter = $_GET['class'] ?? '';
$search = $_GET['search'] ?? '';

// Build WHERE clause
$where_conditions = ['s.is_active = 1'];
$params = [];

if ($status_filter) {
    if ($status_filter === 'verified') {
        $where_conditions[] = 's.is_verified = 1';
    } elseif ($status_filter === 'pending') {
        $where_conditions[] = 's.is_verified = 0';
    }
}

if ($program_filter) {
    $where_conditions[] = 'prog.program_name = ?';
    $params[] = $program_filter;
}

if ($class_filter) {
    $where_conditions[] = 'cl.class_name = ?';
    $params[] = $class_filter;
}

if ($search) {
    $where_conditions[] = '(CONCAT(s.first_name, " ", s.last_name) LIKE ? OR s.student_number LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Get total count
$count_sql = "
    SELECT COUNT(*) 
    FROM students s
    LEFT JOIN programs prog ON s.program_id = prog.program_id
    LEFT JOIN classes cl ON s.class_id = cl.class_id
    $where_clause
";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Get students
$sql = "
    SELECT s.*, prog.program_name, cl.class_name,
           CASE 
               WHEN s.is_verified = 1 THEN s.verified_at
               ELSE s.created_at 
           END as status_date,
           CASE 
               WHEN s.is_verified = 1 THEN 'verified'
               ELSE 'pending'
           END as verification_status
    FROM students s
    LEFT JOIN programs prog ON s.program_id = prog.program_id
    LEFT JOIN classes cl ON s.class_id = cl.class_id
    $where_clause
    ORDER BY 
        CASE s.is_verified 
            WHEN 0 THEN 1 
            WHEN 1 THEN 2 
            ELSE 3 
        END,
        s.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $per_page;
$params[] = $offset;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Get filter options
$stmt = $db->prepare("
    SELECT DISTINCT prog.program_name 
    FROM students s 
    JOIN programs prog ON s.program_id = prog.program_id 
    WHERE s.is_active = 1 
    ORDER BY prog.program_name
");
$stmt->execute();
$programs = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get classes - filter by program if program is selected
if ($program_filter) {
    $stmt = $db->prepare("
        SELECT DISTINCT cl.class_name 
        FROM students s 
        JOIN classes cl ON s.class_id = cl.class_id 
        JOIN programs prog ON s.program_id = prog.program_id
        WHERE s.is_active = 1 AND prog.program_name = ?
        ORDER BY cl.class_name
    ");
    $stmt->execute([$program_filter]);
} else {
    $stmt = $db->prepare("
        SELECT DISTINCT cl.class_name 
        FROM students s 
        JOIN classes cl ON s.class_id = cl.class_id 
        WHERE s.is_active = 1 
        ORDER BY cl.class_name
    ");
    $stmt->execute();
}
$classes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get verification statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_verified = 0 THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) as verified
    FROM students WHERE is_active = 1
");
$stmt->execute();
$stats = $stmt->fetch();

// Handle bulk actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'bulk_verify') {
        $student_ids = $_POST['student_ids'] ?? [];
        if (!empty($student_ids)) {
            try {
                $db->beginTransaction();
                
                $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
                $stmt = $db->prepare("
                    UPDATE students 
                    SET is_verified = 1, verified_by = ?, verified_at = NOW(), updated_at = NOW()
                    WHERE student_id IN ($placeholders) AND is_verified = 0
                ");
                $params = [getCurrentUser()['id']];
                $params = array_merge($params, $student_ids);
                $stmt->execute($params);
                
                $count = $stmt->rowCount();
                $db->commit();
                
                logActivity('bulk_student_verify', "Bulk verified $count students");
                $_SESSION['success'] = "$count students verified successfully!";
                
            } catch (Exception $e) {
                $db->rollBack();
                $_SESSION['error'] = 'Failed to verify students. Please try again.';
                error_log("Bulk verify error: " . $e->getMessage());
            }
        }
        redirectTo('election-officer/students/verify.php?' . http_build_query($_GET));
    }
    
    if ($action === 'bulk_reject') {
        $student_ids = $_POST['student_ids'] ?? [];
        if (!empty($student_ids)) {
            try {
                $db->beginTransaction();
                
                $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
                $stmt = $db->prepare("
                    UPDATE students 
                    SET is_verified = 0, verified_by = NULL, verified_at = NULL, updated_at = NOW()
                    WHERE student_id IN ($placeholders) AND is_verified = 1
                ");
                $stmt->execute($student_ids);
                
                $count = $stmt->rowCount();
                $db->commit();
                
                logActivity('bulk_student_unverify', "Bulk unverified $count students");
                $_SESSION['success'] = "$count students unverified successfully!";
                
            } catch (Exception $e) {
                $db->rollBack();
                $_SESSION['error'] = 'Failed to unverify students. Please try again.';
                error_log("Bulk unverify error: " . $e->getMessage());
            }
        }
        redirectTo('election-officer/students/verify.php?' . http_build_query($_GET));
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    border-radius: 0.5rem;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    text-align: center;
}

.stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}

.stat-label {
    color: #64748b;
    font-size: 0.875rem;
    margin: 0.5rem 0 0;
}

.filter-card {
    background: white;
    border-radius: 0.5rem;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
}

.students-table {
    background: white;
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    overflow: hidden;
}

.table th {
    background: #f8fafc;
    border-bottom: 2px solid #e2e8f0;
    color: #1e293b;
    font-weight: 600;
    padding: 1rem 0.75rem;
}

.table td {
    padding: 1rem 0.75rem;
    vertical-align: middle;
}

.student-avatar {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    margin-right: 0.75rem;
}

.student-info {
    display: flex;
    align-items: center;
}

.student-details h6 {
    margin: 0;
    font-weight: 600;
    color: #1e293b;
}

.student-details small {
    color: #64748b;
}

.status-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 500;
}

.status-pending { background: #fef3c7; color: #d97706; }
.status-verified { background: #dcfce7; color: #166534; }

.bulk-actions {
    background: #f8fafc;
    padding: 1rem;
    border: 1px solid #e2e8f0;
    border-bottom: none;
    border-radius: 0.5rem 0.5rem 0 0;
    display: none;
}

.bulk-actions.show {
    display: block;
}

.bulk-actions.show + .table {
    border-radius: 0 0 0.5rem 0.5rem;
}
</style>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= $_SESSION['success'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= $_SESSION['error'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <h3 class="stat-number"><?= number_format($stats['total']) ?></h3>
        <p class="stat-label">Total Students</p>
    </div>
    <div class="stat-card">
        <h3 class="stat-number"><?= number_format($stats['pending']) ?></h3>
        <p class="stat-label">Pending</p>
    </div>
    <div class="stat-card">
        <h3 class="stat-number"><?= number_format($stats['verified']) ?></h3>
        <p class="stat-label">Verified</p>
    </div>
</div>

<!-- Filters -->
<div class="filter-card">
    <form method="GET" class="row g-3 align-items-end">
        <div class="col-md-2">
            <label for="search" class="form-label">Search</label>
            <input type="text" name="search" id="search" class="form-control" 
                   placeholder="Name, ID..." value="<?= sanitize($search) ?>">
        </div>
        
        <div class="col-md-2">
            <label for="status" class="form-label">Status</label>
            <select name="status" id="status" class="form-select">
                <option value="">All Status</option>
                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="verified" <?= $status_filter === 'verified' ? 'selected' : '' ?>>Verified</option>
            </select>
        </div>
        
        <div class="col-md-2">
            <label for="program" class="form-label">Program</label>
            <select name="program" id="program" class="form-select">
                <option value="">All Programs</option>
                <?php foreach ($programs as $program): ?>
                    <option value="<?= sanitize($program) ?>" <?= $program_filter === $program ? 'selected' : '' ?>>
                        <?= sanitize($program) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="col-md-2">
            <label for="class" class="form-label">Class</label>
            <select name="class" id="class" class="form-select">
                <option value="">All Classes</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?= sanitize($class) ?>" <?= $class_filter === $class ? 'selected' : '' ?>>
                        <?= sanitize($class) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="col-md-1">
            <label for="per_page" class="form-label">Show</label>
            <select name="per_page" id="per_page" class="form-select">
                <option value="15" <?= $per_page === 15 ? 'selected' : '' ?>>15</option>
                <option value="25" <?= $per_page === 25 ? 'selected' : '' ?>>25</option>
                <option value="50" <?= $per_page === 50 ? 'selected' : '' ?>>50</option>
                <option value="100" <?= $per_page === 100 ? 'selected' : '' ?>>100</option>
            </select>
        </div>
        
        <div class="col-md-3">
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="fas fa-search me-1"></i>Filter
                </button>
                <?php if ($search || $status_filter || $program_filter || $class_filter): ?>
                    <a href="?" class="btn btn-outline-secondary">
                        <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- Results Info -->
<?php if (!empty($students)): ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="text-muted">
            Showing <?= number_format(min($offset + 1, $total_records)) ?> to <?= number_format(min($offset + count($students), $total_records)) ?> of <?= number_format($total_records) ?> students
        </div>
        <div class="text-muted">
            Page <?= $page ?> of <?= $total_pages ?>
        </div>
    </div>
<?php endif; ?>

<!-- Students Table -->
<div class="students-table">
    <?php if (empty($students)): ?>
        <div class="text-center py-5">
            <i class="fas fa-user-graduate fa-4x text-muted mb-3"></i>
            <h4>No Students Found</h4>
            <p class="text-muted">
                <?php if ($search || $status_filter || $program_filter || $class_filter): ?>
                    No students match your current filters.
                <?php else: ?>
                    No students found in the system.
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <form method="POST" id="bulkForm">
            
            <!-- Bulk Actions -->
            <div class="bulk-actions" id="bulkActions">
                <div class="d-flex justify-content-between align-items-center">
                    <span id="selectedCount">0 students selected</span>
                    <div>
                        <button type="submit" class="btn btn-success btn-sm me-2" name="action" value="bulk_verify"
                                onclick="return confirm('Verify selected students?')">
                            <i class="fas fa-check me-1"></i>Verify Selected
                        </button>
                        <button type="submit" class="btn btn-warning btn-sm me-2" name="action" value="bulk_reject"
                                onclick="return confirm('Unverify selected students?')">
                            <i class="fas fa-undo me-1"></i>Unverify Selected
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearSelection()">
                            Clear Selection
                        </button>
                    </div>
                </div>
            </div>
            
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width: 50px;">
                            <input type="checkbox" id="selectAll" class="form-check-input">
                        </th>
                        <th>Student</th>
                        <th>Student ID</th>
                        <th>Program</th>
                        <th>Class</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="student_ids[]" value="<?= $student['student_id'] ?>" 
                                       class="form-check-input student-checkbox">
                            </td>
                            <td>
                                <div class="student-info">
                                    <div class="student-avatar">
                                        <?= substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1) ?>
                                    </div>
                                    <div class="student-details">
                                        <h6><?= sanitize($student['first_name'] . ' ' . $student['last_name']) ?></h6>
                                        <small><?= sanitize($student['student_number']) ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <strong><?= sanitize($student['student_number']) ?></strong>
                            </td>
                            <td><?= sanitize($student['program_name']) ?></td>
                            <td><?= sanitize($student['class_name']) ?></td>
                            <td>
                                <span class="status-badge status-<?= $student['verification_status'] ?>">
                                    <?= ucfirst($student['verification_status']) ?>
                                </span>
                                <div class="small text-muted">
                                    <?= date('M j, Y', strtotime($student['status_date'])) ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($student['verification_status'] === 'pending'): ?>
                                    <button type="button" class="btn btn-success btn-sm" 
                                            onclick="verifyStudent(<?= $student['student_id'] ?>, 'verified')">
                                        <i class="fas fa-check"></i> Verify
                                    </button>
                                <?php elseif ($student['verification_status'] === 'verified'): ?>
                                    <button type="button" class="btn btn-warning btn-sm" 
                                            onclick="verifyStudent(<?= $student['student_id'] ?>, 'pending')">
                                        <i class="fas fa-undo"></i> Unverify
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="p-3">
                <nav aria-label="Students pagination">
                    <ul class="pagination justify-content-center mb-0">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=1&<?= http_build_query(array_filter(array_diff_key($_GET, ['page' => '']))) ?>">First</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?= $page - 1 ?>&<?= http_build_query(array_filter(array_diff_key($_GET, ['page' => '']))) ?>">Previous</a>
                            </li>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>&<?= http_build_query(array_filter(array_diff_key($_GET, ['page' => '']))) ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?= $page + 1 ?>&<?= http_build_query(array_filter(array_diff_key($_GET, ['page' => '']))) ?>">Next</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?= $total_pages ?>&<?= http_build_query(array_filter(array_diff_key($_GET, ['page' => '']))) ?>">Last</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
// Handle select all functionality
document.getElementById('selectAll').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.student-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = this.checked;
    });
    updateBulkActions();
});

// Handle individual checkbox changes
document.querySelectorAll('.student-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', updateBulkActions);
});

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.student-checkbox:checked');
    const count = checkboxes.length;
    const bulkActions = document.getElementById('bulkActions');
    const selectedCount = document.getElementById('selectedCount');
    
    if (count > 0) {
        bulkActions.classList.add('show');
        selectedCount.textContent = `${count} student${count === 1 ? '' : 's'} selected`;
    } else {
        bulkActions.classList.remove('show');
    }
    
    // Update select all checkbox
    const allCheckboxes = document.querySelectorAll('.student-checkbox');
    const selectAll = document.getElementById('selectAll');
    selectAll.checked = allCheckboxes.length > 0 && count === allCheckboxes.length;
    selectAll.indeterminate = count > 0 && count < allCheckboxes.length;
}

function clearSelection() {
    document.querySelectorAll('.student-checkbox').forEach(checkbox => {
        checkbox.checked = false;
    });
    document.getElementById('selectAll').checked = false;
    updateBulkActions();
}

function verifyStudent(studentId, status) {
    const action = status === 'verified' ? 'verify' : 'reject';
    if (confirm(`Are you sure you want to ${action} this student?`)) {
        fetch('<?= SITE_URL ?>/api/students/verify.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                student_id: studentId,
                status: status
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while updating student status.');
        });
    }
}

// Initialize bulk actions state
updateBulkActions();

// Auto-submit form when program changes to update class dropdown
document.getElementById('program').addEventListener('change', function() {
    // Preserve other form values but clear class selection
    const form = this.closest('form');
    const classSelect = document.getElementById('class');
    classSelect.value = ''; // Clear class selection when program changes
    
    // Create a temporary form to submit with current values
    const tempForm = document.createElement('form');
    tempForm.method = 'GET';
    
    // Copy all form fields except class
    const formData = new FormData(form);
    for (const [key, value] of formData.entries()) {
        if (key !== 'class') {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = value;
            tempForm.appendChild(input);
        }
    }
    
    document.body.appendChild(tempForm);
    tempForm.submit();
});

// Auto-submit form when per_page changes
document.getElementById('per_page').addEventListener('change', function() {
    const form = this.closest('form');
    form.submit();
});

</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>