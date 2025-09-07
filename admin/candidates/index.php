<?php
require_once '../../config/config.php';
require_once '../../auth/session.php';
require_once '../../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

$page_title = 'Candidates Overview';
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index'],
    ['title' => 'Candidates Overview']
];

// Handle success/error messages from session
$success = '';
$error = '';

if (isset($_SESSION['candidates_success'])) {
    $success = $_SESSION['candidates_success'];
    unset($_SESSION['candidates_success']);
}

if (isset($_SESSION['candidates_error'])) {
    $error = $_SESSION['candidates_error'];
    unset($_SESSION['candidates_error']);
}

// Get data for the page
try {
    $db = Database::getInstance()->getConnection();
    
    // Get all elections for dropdown
    $stmt = $db->prepare("SELECT election_id, name, status FROM elections ORDER BY created_at DESC");
    $stmt->execute();
    $elections = $stmt->fetchAll();
    
    // Get all candidates with detailed info
    $stmt = $db->prepare("
        SELECT c.candidate_id, c.student_id, c.position_id, c.election_id, c.photo_url, c.vote_count, c.created_at, c.updated_at, c.created_by,
               s.student_number, s.first_name, s.last_name, s.gender,
               p.title as position_title, p.max_candidates, p.display_order,
               e.name as election_name, e.status as election_status,
               COUNT(v.vote_id) as vote_count
        FROM candidates c
        JOIN students s ON c.student_id = s.student_id
        JOIN positions p ON c.position_id = p.position_id
        JOIN elections e ON c.election_id = e.election_id
        LEFT JOIN votes v ON c.candidate_id = v.candidate_id
        GROUP BY c.candidate_id, c.student_id, c.position_id, c.election_id, c.photo_url, c.vote_count, c.created_at, c.updated_at, c.created_by,
                 s.student_number, s.first_name, s.last_name, s.gender,
                 p.title, p.max_candidates, p.display_order,
                 e.name, e.status
        ORDER BY e.created_at DESC, p.display_order ASC, s.first_name ASC
    ");
    $stmt->execute();
    $candidates = $stmt->fetchAll();
    
    // Get candidate statistics
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_candidates,
            COUNT(CASE WHEN s.gender = 'Male' THEN 1 END) as male_candidates,
            COUNT(CASE WHEN s.gender = 'Female' THEN 1 END) as female_candidates,
            COUNT(CASE WHEN e.status = 'active' THEN 1 END) as active_election_candidates
        FROM candidates c
        JOIN students s ON c.student_id = s.student_id
        JOIN elections e ON c.election_id = e.election_id
    ");
    $stmt->execute();
    $stats = $stmt->fetch();
    
    // Get positions grouped by election for reference
    $stmt = $db->prepare("
        SELECT e.election_id, e.name as election_name, e.status,
               p.position_id, p.title as position_title, p.max_candidates,
               COUNT(c.candidate_id) as current_candidates
        FROM elections e
        LEFT JOIN positions p ON e.election_id = p.election_id
        LEFT JOIN candidates c ON p.position_id = c.position_id
        WHERE e.status != 'completed'
        GROUP BY e.election_id, e.name, e.status, e.created_at, p.position_id, p.title, p.max_candidates, p.display_order
        ORDER BY e.created_at DESC, p.display_order ASC
    ");
    $stmt->execute();
    $election_positions = $stmt->fetchAll();
    
} catch (Exception $e) {
    logError("Candidates data fetch error: " . $e->getMessage());
    $_SESSION['candidates_error'] = "Unable to load candidates data";
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

include '../../includes/header.php';
?>

<!-- Candidates Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="fas fa-users me-2"></i>Candidates Overview
        </h4>
        <small class="text-muted">Monitor and manage all election candidates</small>
    </div>
    <div class="d-flex gap-2">
        <a href="manage" class="btn btn-primary">
            <i class="fas fa-user-plus me-2"></i>Manage Candidates
        </a>
        <a href="photos" class="btn btn-outline-secondary">
            <i class="fas fa-images me-2"></i>Manage Photos
        </a>
    </div>
</div>

<!-- Alert Messages -->
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <?= sanitize($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?= sanitize($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Overview Cards -->
<div class="row mb-4">
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-primary">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['total_candidates'] ?></h3>
                <p>Total Candidates</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-info">
                <i class="fas fa-male"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['male_candidates'] ?></h3>
                <p>Male Candidates</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-success">
                <i class="fas fa-female"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['female_candidates'] ?></h3>
                <p>Female Candidates</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-warning">
                <i class="fas fa-vote-yea"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['active_election_candidates'] ?></h3>
                <p>Active Elections</p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Quick Actions Panel -->
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-tachometer-alt me-2"></i>Quick Actions
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="manage" class="btn btn-outline-primary">
                        <i class="fas fa-user-plus me-2"></i>Add New Candidate
                    </a>
                    <a href="photos" class="btn btn-outline-info">
                        <i class="fas fa-images me-2"></i>Manage Photos
                    </a>
                    <a href="../students/" class="btn btn-outline-success">
                        <i class="fas fa-graduation-cap me-2"></i>Manage Students
                    </a>
                    <a href="../positions/" class="btn btn-outline-warning">
                        <i class="fas fa-bookmark me-2"></i>Manage Positions
                    </a>
                    <a href="../elections/" class="btn btn-outline-secondary">
                        <i class="fas fa-vote-yea me-2"></i>Manage Elections
                    </a>
                </div>
                
                <hr class="my-3">
                
                <h6 class="fw-bold mb-2">Available Students</h6>
                <div class="small">
                    <?php
                    $stmt = $db->prepare("SELECT student_number, first_name, last_name FROM students WHERE is_verified = 1 ORDER BY first_name LIMIT 5");
                    $stmt->execute();
                    $sample_students = $stmt->fetchAll();
                    ?>
                    <?php if ($sample_students): ?>
                        <?php foreach ($sample_students as $student): ?>
                            <div class="d-flex justify-content-between py-1">
                                <span><?= sanitize($student['first_name']) ?> <?= sanitize($student['last_name']) ?></span>
                                <span class="text-muted"><?= sanitize($student['student_number']) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <div class="text-center mt-2">
                            <small class="text-muted">
                                <a href="../students/">View all students →</a>
                            </small>
                        </div>
                    <?php else: ?>
                        <p class="text-muted">No verified students found</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Position Status -->
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-chart-pie me-2"></i>Position Candidate Status
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($election_positions)): ?>
                    <p class="text-muted text-center py-3">No active positions found</p>
                <?php else: ?>
                    <?php 
                    $current_election = '';
                    foreach ($election_positions as $ep): 
                        if ($current_election !== $ep['election_name']):
                            if ($current_election !== '') echo '</div><hr class="my-3">';
                            $current_election = $ep['election_name'];
                    ?>
                        <h6 class="fw-bold text-primary mb-2"><?= sanitize($current_election) ?></h6>
                        <div class="row">
                    <?php endif; ?>
                        <?php if ($ep['position_id']): ?>
                        <div class="col-md-6 mb-2">
                            <div class="d-flex justify-content-between align-items-center p-2 bg-light rounded">
                                <div>
                                    <span class="fw-medium"><?= sanitize($ep['position_title']) ?></span>
                                    <br><small class="text-muted">
                                        <?= $ep['current_candidates'] ?>/<?= $ep['max_candidates'] ?> candidates
                                    </small>
                                </div>
                                <div>
                                    <?php 
                                    $percentage = $ep['max_candidates'] > 0 ? ($ep['current_candidates'] / $ep['max_candidates']) * 100 : 0;
                                    $color = $percentage >= 100 ? 'danger' : ($percentage >= 50 ? 'warning' : 'success');
                                    ?>
                                    <span class="badge bg-<?= $color ?>"><?= round($percentage) ?>%</span>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($current_election !== '') echo '</div>'; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- All Candidates List -->
<div class="card">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">
                <i class="fas fa-list me-2"></i>All Candidates
            </h5>
            <div class="d-flex gap-2">
                <select class="form-select form-select-sm" id="electionFilter" style="width: auto;">
                    <option value="">All Elections</option>
                    <?php foreach ($elections as $election): ?>
                        <option value="<?= $election['election_id'] ?>"><?= sanitize($election['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="form-select form-select-sm" id="genderFilter" style="width: auto;">
                    <option value="">All Genders</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($candidates)): ?>
            <div class="text-center py-5">
                <i class="fas fa-users text-muted" style="font-size: 4rem;"></i>
                <h4 class="text-muted mt-3">No Candidates Found</h4>
                <p class="text-muted">Add candidates to election positions to get started.</p>
                <a href="manage" class="btn btn-primary">
                    <i class="fas fa-user-plus me-2"></i>Add First Candidate
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover" id="candidatesTable">
                    <thead>
                        <tr>
                            <th>Candidate</th>
                            <th>Position</th>
                            <th>Election</th>
                            <th>Status</th>
                            <th>Votes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($candidates as $candidate): ?>
                            <tr data-election-id="<?= $candidate['election_id'] ?>" data-gender="<?= $candidate['gender'] ?>">
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="candidate-avatar me-3">
                                            <?php if (!empty($candidate['photo_url'])): ?>
                                                <img src="<?= sanitize($candidate['photo_url']) ?>" alt="<?= sanitize($candidate['first_name']) ?>" class="candidate-photo">
                                            <?php else: ?>
                                                <i class="fas fa-<?= $candidate['gender'] === 'Male' ? 'male' : 'female' ?>"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <strong><?= sanitize($candidate['first_name']) ?> <?= sanitize($candidate['last_name']) ?></strong>
                                            <br><small class="text-muted">ID: <?= sanitize($candidate['student_number']) ?></small>
                                            <br><span class="badge bg-<?= $candidate['gender'] === 'Male' ? 'info' : 'success' ?> badge-sm">
                                                <?= $candidate['gender'] ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <strong><?= sanitize($candidate['position_title']) ?></strong>
                                </td>
                                <td>
                                    <div>
                                        <?= sanitize($candidate['election_name']) ?>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $status_class = match($candidate['election_status']) {
                                        'active' => 'success',
                                        'draft' => 'warning',
                                        'completed' => 'info',
                                        'cancelled' => 'danger',
                                        default => 'secondary'
                                    };
                                    ?>
                                    <span class="badge bg-<?= $status_class ?>"><?= ucfirst($candidate['election_status']) ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $candidate['vote_count'] > 0 ? 'primary' : 'secondary' ?>">
                                        <?= $candidate['vote_count'] ?> votes
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="manage?candidate_id=<?= $candidate['candidate_id'] ?>" class="btn btn-outline-primary" title="Edit Candidate">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="photos?candidate_id=<?= $candidate['candidate_id'] ?>" class="btn btn-outline-info" title="Manage Photo">
                                            <i class="fas fa-image"></i>
                                        </a>
                                        <a href="../elections/positions?election_id=<?= $candidate['election_id'] ?>" class="btn btn-outline-secondary" title="Manage in Election">
                                            <i class="fas fa-cog"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* Candidates Overview Styles */

.stats-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    border: 1px solid #e9ecef;
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
}

.stats-icon {
    width: 60px;
    height: 60px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
}

.stats-info h3 {
    font-size: 2rem;
    font-weight: 700;
    margin: 0;
    color: #212529;
}

.stats-info p {
    margin: 0;
    color: #6c757d;
    font-size: 0.9rem;
    font-weight: 500;
}

.candidate-avatar {
    width: 40px;
    height: 40px;
    background: #f8f9fa;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: #6c757d;
    overflow: hidden;
}

.candidate-photo {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}

.badge-sm {
    font-size: 0.7rem;
}

.table th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    color: #495057;
    font-size: 0.875rem;
}

.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .stats-card {
        flex-direction: column;
        text-align: center;
    }
    
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .candidate-avatar {
        width: 35px;
        height: 35px;
        font-size: 1rem;
    }
}
</style>

<script>
// Candidates Overview JavaScript
document.addEventListener('DOMContentLoaded', function() {
    
    // Filter functionality
    const electionFilter = document.getElementById('electionFilter');
    const genderFilter = document.getElementById('genderFilter');
    const table = document.getElementById('candidatesTable');
    
    function filterTable() {
        if (!table) return;
        
        const selectedElection = electionFilter.value;
        const selectedGender = genderFilter.value;
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            const electionId = row.dataset.electionId;
            const gender = row.dataset.gender;
            
            const electionMatch = !selectedElection || electionId === selectedElection;
            const genderMatch = !selectedGender || gender === selectedGender;
            
            if (electionMatch && genderMatch) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
    
    if (electionFilter && genderFilter) {
        electionFilter.addEventListener('change', filterTable);
        genderFilter.addEventListener('change', filterTable);
    }
    
    // Auto-dismiss alerts
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.remove();
                }
            }, 500);
        });
    }, 5000);
});
</script>

<?php include '../../includes/footer.php'; ?>