<?php
require_once '../../config/config.php';
require_once '../../auth/session.php';
require_once '../../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

// Get election ID
$election_id = intval($_GET['election_id'] ?? 0);

if (!$election_id) {
    $_SESSION['elections_error'] = 'Invalid election ID';
    header('Location: index.php');
    exit;
}

// Handle success/error messages from session
$success = '';
$error = '';

if (isset($_SESSION['positions_success'])) {
    $success = $_SESSION['positions_success'];
    unset($_SESSION['positions_success']);
}

if (isset($_SESSION['positions_error'])) {
    $error = $_SESSION['positions_error'];
    unset($_SESSION['positions_error']);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $db = Database::getInstance()->getConnection();
        
        if ($action === 'create_position') {
            $title = sanitize($_POST['title'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $max_candidates = intval($_POST['max_candidates'] ?? 1);
            
            if (empty($title)) {
                throw new Exception('Position title is required');
            }
            
            if ($max_candidates < 1) {
                throw new Exception('Maximum candidates must be at least 1');
            }
            
            $stmt = $db->prepare("
                INSERT INTO positions (election_id, title, description, max_candidates, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$election_id, $title, $description, $max_candidates]);
            
            logActivity('position_create', "Created position: {$title}", $current_user['id']);
            $_SESSION['positions_success'] = 'Position created successfully';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?election_id=' . $election_id);
            exit;
            
        } elseif ($action === 'update_position') {
            $position_id = intval($_POST['position_id'] ?? 0);
            $title = sanitize($_POST['title'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $max_candidates = intval($_POST['max_candidates'] ?? 1);
            
            if (empty($title) || !$position_id) {
                throw new Exception('Position title and ID are required');
            }
            
            if ($max_candidates < 1) {
                throw new Exception('Maximum candidates must be at least 1');
            }
            
            $stmt = $db->prepare("
                UPDATE positions 
                SET title = ?, description = ?, max_candidates = ?, updated_at = NOW() 
                WHERE position_id = ? AND election_id = ?
            ");
            $stmt->execute([$title, $description, $max_candidates, $position_id, $election_id]);
            
            logActivity('position_update', "Updated position: {$title}", $current_user['id']);
            $_SESSION['positions_success'] = 'Position updated successfully';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?election_id=' . $election_id);
            exit;
            
        } elseif ($action === 'delete_position') {
            $position_id = intval($_POST['position_id'] ?? 0);
            
            if (!$position_id) {
                throw new Exception('Invalid position ID');
            }
            
            // Get position title for logging
            $stmt = $db->prepare("SELECT title FROM positions WHERE position_id = ? AND election_id = ?");
            $stmt->execute([$position_id, $election_id]);
            $position = $stmt->fetch();
            
            if (!$position) {
                throw new Exception('Position not found');
            }
            
            // Delete position (this will cascade to delete candidates if properly set up)
            $stmt = $db->prepare("DELETE FROM positions WHERE position_id = ? AND election_id = ?");
            $stmt->execute([$position_id, $election_id]);
            
            logActivity('position_delete', "Deleted position: {$position['title']}", $current_user['id']);
            $_SESSION['positions_success'] = 'Position deleted successfully';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?election_id=' . $election_id);
            exit;
            
        } elseif ($action === 'create_candidate') {
            $position_id = intval($_POST['position_id'] ?? 0);
            $student_id = sanitize($_POST['student_id'] ?? '');
            
            if (!$position_id || empty($student_id)) {
                throw new Exception('Position and student ID are required');
            }
            
            // Check if student exists and get student info
            $stmt = $db->prepare("
                SELECT s.student_id, s.first_name, s.last_name
                FROM students s 
                WHERE s.student_number = ?
            ");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch();
            
            if (!$student) {
                throw new Exception('Student not found');
            }
            
            // Check if student is already a candidate in this position
            $stmt = $db->prepare("SELECT candidate_id FROM candidates WHERE position_id = ? AND student_id = ?");
            $stmt->execute([$position_id, $student['student_id']]);
            if ($stmt->fetch()) {
                throw new Exception('Student is already a candidate for this position');
            }
            
            // Check max candidates limit
            $stmt = $db->prepare("
                SELECT p.max_candidates, COUNT(c.candidate_id) as current_count
                FROM positions p
                LEFT JOIN candidates c ON p.position_id = c.position_id
                WHERE p.position_id = ?
                GROUP BY p.position_id
            ");
            $stmt->execute([$position_id]);
            $position_info = $stmt->fetch();
            
            if ($position_info && $position_info['current_count'] >= $position_info['max_candidates']) {
                throw new Exception('Maximum number of candidates reached for this position');
            }
            
            $stmt = $db->prepare("
                INSERT INTO candidates (student_id, position_id, election_id, created_by, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$student['student_id'], $position_id, $election_id, $current_user['id']]);
            
            $candidate_name = $student['first_name'] . ' ' . $student['last_name'];
            logActivity('candidate_create', "Added candidate: {$candidate_name}", $current_user['id']);
            $_SESSION['positions_success'] = 'Candidate added successfully';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?election_id=' . $election_id);
            exit;
            
        } elseif ($action === 'delete_candidate') {
            $candidate_id = intval($_POST['candidate_id'] ?? 0);
            
            if (!$candidate_id) {
                throw new Exception('Invalid candidate ID');
            }
            
            // Get candidate info for logging
            $stmt = $db->prepare("
                SELECT s.first_name, s.last_name, p.title 
                FROM candidates c 
                JOIN students s ON c.student_id = s.student_id
                JOIN positions p ON c.position_id = p.position_id 
                WHERE c.candidate_id = ? AND p.election_id = ?
            ");
            $stmt->execute([$candidate_id, $election_id]);
            $candidate = $stmt->fetch();
            
            if (!$candidate) {
                throw new Exception('Candidate not found');
            }
            
            // Delete candidate
            $stmt = $db->prepare("
                DELETE c FROM candidates c 
                JOIN positions p ON c.position_id = p.position_id 
                WHERE c.candidate_id = ? AND p.election_id = ?
            ");
            $stmt->execute([$candidate_id, $election_id]);
            
            $candidate_name = $candidate['first_name'] . ' ' . $candidate['last_name'];
            logActivity('candidate_delete', "Deleted candidate: {$candidate_name}", $current_user['id']);
            $_SESSION['positions_success'] = 'Candidate deleted successfully';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?election_id=' . $election_id);
            exit;
        }
        
    } catch (Exception $e) {
        $_SESSION['positions_error'] = $e->getMessage();
        logError("Positions management error: " . $e->getMessage());
        header('Location: ' . $_SERVER['PHP_SELF'] . '?election_id=' . $election_id);
        exit;
    }
}

// Get election and positions data
try {
    $db = Database::getInstance()->getConnection();
    
    // Get election info
    $stmt = $db->prepare("SELECT * FROM elections WHERE election_id = ?");
    $stmt->execute([$election_id]);
    $election = $stmt->fetch();
    
    if (!$election) {
        $_SESSION['elections_error'] = 'Election not found';
        header('Location: index.php');
        exit;
    }
    
    // Get positions with candidate counts
    $stmt = $db->prepare("
        SELECT p.*, COUNT(c.candidate_id) as candidate_count
        FROM positions p
        LEFT JOIN candidates c ON p.position_id = c.position_id
        WHERE p.election_id = ?
        GROUP BY p.position_id
        ORDER BY p.created_at ASC
    ");
    $stmt->execute([$election_id]);
    $positions = $stmt->fetchAll();
    
    // Get all candidates for this election
    $stmt = $db->prepare("
        SELECT c.*, s.first_name, s.last_name, s.student_number, p.title as position_title, COUNT(v.vote_id) as vote_count
        FROM candidates c
        JOIN students s ON c.student_id = s.student_id
        JOIN positions p ON c.position_id = p.position_id
        LEFT JOIN votes v ON c.candidate_id = v.candidate_id
        WHERE p.election_id = ?
        GROUP BY c.candidate_id
        ORDER BY p.created_at ASC, c.created_at ASC
    ");
    $stmt->execute([$election_id]);
    $candidates = $stmt->fetchAll();
    
} catch (Exception $e) {
    logError("Positions fetch error: " . $e->getMessage());
    $_SESSION['positions_error'] = "Unable to load positions data";
    header('Location: ' . $_SERVER['PHP_SELF'] . '?election_id=' . $election_id);
    exit;
}

$page_title = 'Manage Positions - ' . $election['name'];
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index'],
    ['title' => 'Elections', 'url' => 'index'],
    ['title' => $election['name']]
];

include '../../includes/header.php';
?>

<!-- Positions Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <div class="d-flex align-items-center mb-2">
            <a href="index.php" class="btn btn-outline-secondary btn-sm me-3">
                <i class="fas fa-arrow-left me-1"></i>Back to Elections
            </a>
            <span class="badge bg-<?= match($election['status']) {
                'active' => 'success',
                'draft' => 'warning', 
                'completed' => 'info',
                'cancelled' => 'danger',
                default => 'secondary'
            } ?>"><?= ucfirst($election['status']) ?></span>
        </div>
        <h4 class="mb-1">
            <i class="fas fa-sitemap me-2"></i><?= sanitize($election['name']) ?>
        </h4>
        <small class="text-muted">Manage positions and candidates</small>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createPositionModal">
        <i class="fas fa-plus me-2"></i>Add Position
    </button>
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

<!-- Election Info Card -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row">
            <div class="col-md-8">
                <?php if ($election['description']): ?>
                    <p class="text-muted mb-2"><?= sanitize($election['description']) ?></p>
                <?php endif; ?>
                <div class="row">
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Start Date</small>
                        <strong><?= date('M j, Y g:i A', strtotime($election['start_date'])) ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">End Date</small>
                        <strong><?= date('M j, Y g:i A', strtotime($election['end_date'])) ?></strong>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-md-end">
                    <div class="d-flex justify-content-md-end gap-3">
                        <div class="text-center">
                            <h4 class="mb-0"><?= count($positions) ?></h4>
                            <small class="text-muted">Positions</small>
                        </div>
                        <div class="text-center">
                            <h4 class="mb-0"><?= count($candidates) ?></h4>
                            <small class="text-muted">Candidates</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Positions and Candidates -->
<?php if (empty($positions)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="fas fa-sitemap text-muted" style="font-size: 4rem;"></i>
            <h4 class="text-muted mt-3">No Positions Found</h4>
            <p class="text-muted">Add positions to this election to get started.</p>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createPositionModal">
                <i class="fas fa-plus me-2"></i>Add First Position
            </button>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($positions as $position): ?>
        <div class="card mb-4">
            <div class="card-header">
                <div class="row align-items-center">
                    <div class="col">
                        <h5 class="mb-1">
                            <i class="fas fa-bookmark me-2 text-primary"></i>
                            <?= sanitize($position['title']) ?>
                        </h5>
                        <?php if ($position['description']): ?>
                            <p class="text-muted mb-0 small"><?= sanitize($position['description']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="col-auto">
                        <span class="badge bg-info me-2">
                            <?= $position['candidate_count'] ?>/<?= $position['max_candidates'] ?> candidates
                        </span>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-success" onclick="addCandidate(<?= $position['position_id'] ?>, '<?= addslashes($position['title']) ?>')" title="Add Candidate">
                                <i class="fas fa-user-plus"></i>
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="editPosition(<?= htmlspecialchars(json_encode($position)) ?>)" title="Edit Position">
                                <i class="fas fa-edit"></i>
                            </button>
                            <?php if ($position['candidate_count'] == 0): ?>
                                <button type="button" class="btn btn-outline-danger" onclick="deletePosition(<?= $position['position_id'] ?>, '<?= addslashes($position['title']) ?>')" title="Delete Position">
                                    <i class="fas fa-trash"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php
                $position_candidates = array_filter($candidates, fn($c) => $c['position_title'] === $position['title']);
                ?>
                
                <?php if (empty($position_candidates)): ?>
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-users mb-2" style="font-size: 2rem;"></i>
                        <p class="mb-2">No candidates yet</p>
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="addCandidate(<?= $position['position_id'] ?>, '<?= addslashes($position['title']) ?>')">
                            <i class="fas fa-user-plus me-1"></i>Add First Candidate
                        </button>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <?php foreach ($position_candidates as $candidate): ?>
                            <div class="col-lg-6 mb-3">
                                <div class="candidate-card">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1">
                                                <i class="fas fa-user me-1 text-primary"></i>
                                                <?= sanitize($candidate['first_name']) ?> <?= sanitize($candidate['last_name']) ?>
                                            </h6>
                                            <small class="text-muted">ID: <?= sanitize($candidate['student_number']) ?></small>
                                            <div class="small">
                                                <i class="fas fa-vote-yea me-1"></i>
                                                <strong><?= $candidate['vote_count'] ?></strong> votes
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteCandidate(<?= $candidate['candidate_id'] ?>, '<?= addslashes($candidate['first_name'] . ' ' . $candidate['last_name']) ?>')" title="Remove Candidate">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Create Position Modal -->
<div class="modal fade" id="createPositionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_position">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>Add New Position
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="title" class="form-label">Position Title</label>
                        <input type="text" class="form-control" id="title" name="title" required placeholder="e.g., Student President">
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" placeholder="Brief description of the position..."></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="max_candidates" class="form-label">Maximum Candidates</label>
                        <input type="number" class="form-control" id="max_candidates" name="max_candidates" min="1" value="10" required>
                        <div class="form-text">Maximum number of candidates allowed for this position</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Position</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Position Modal -->
<div class="modal fade" id="editPositionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_position">
                <input type="hidden" name="position_id" id="edit_position_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Position
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_title" class="form-label">Position Title</label>
                        <input type="text" class="form-control" id="edit_title" name="title" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_max_candidates" class="form-label">Maximum Candidates</label>
                        <input type="number" class="form-control" id="edit_max_candidates" name="max_candidates" min="1" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Position</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Candidate Modal -->
<div class="modal fade" id="addCandidateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_candidate">
                <input type="hidden" name="position_id" id="candidate_position_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus me-2"></i>Add Candidate to <span id="candidate_position_title"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="student_id" class="form-label">Student Number</label>
                        <input type="text" class="form-control" id="student_id" name="student_id" required placeholder="Enter student number">
                        <div class="form-text">The student must be registered in the system</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Add Candidate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Position Modal -->
<div class="modal fade" id="deletePositionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the position "<strong id="deletePositionTitle"></strong>"?</p>
                <p class="text-danger"><small><i class="fas fa-warning me-1"></i>This will also delete all candidates for this position.</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deletePositionForm">
                    <input type="hidden" name="action" value="delete_position">
                    <input type="hidden" name="position_id" id="delete_position_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Position</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Candidate Modal -->
<div class="modal fade" id="deleteCandidateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>Remove Candidate
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to remove "<strong id="deleteCandidateName"></strong>" as a candidate?</p>
                <p class="text-warning"><small><i class="fas fa-info-circle me-1"></i>This will also remove all votes for this candidate.</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteCandidateForm">
                    <input type="hidden" name="action" value="delete_candidate">
                    <input type="hidden" name="candidate_id" id="delete_candidate_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Remove Candidate</button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
/* Positions Page Styles */

.candidate-card {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 1rem;
    height: 100%;
}


/* Responsive adjustments */
@media (max-width: 768px) {
    .candidate-card {
        margin-bottom: 1rem;
    }
}
</style>

<script>
// Positions Management JavaScript
document.addEventListener('DOMContentLoaded', function() {
    
    // Edit position function
    window.editPosition = function(position) {
        document.getElementById('edit_position_id').value = position.position_id;
        document.getElementById('edit_title').value = position.title;
        document.getElementById('edit_description').value = position.description || '';
        document.getElementById('edit_max_candidates').value = position.max_candidates;
        
        const editModal = new bootstrap.Modal(document.getElementById('editPositionModal'));
        editModal.show();
    };
    
    // Add candidate function
    window.addCandidate = function(positionId, positionTitle) {
        document.getElementById('candidate_position_id').value = positionId;
        document.getElementById('candidate_position_title').textContent = positionTitle;
        
        // Clear form
        document.getElementById('student_id').value = '';
        
        const addModal = new bootstrap.Modal(document.getElementById('addCandidateModal'));
        addModal.show();
    };
    
    // Delete position function
    window.deletePosition = function(positionId, positionTitle) {
        document.getElementById('delete_position_id').value = positionId;
        document.getElementById('deletePositionTitle').textContent = positionTitle;
        
        const deleteModal = new bootstrap.Modal(document.getElementById('deletePositionModal'));
        deleteModal.show();
    };
    
    // Delete candidate function
    window.deleteCandidate = function(candidateId, candidateName) {
        document.getElementById('delete_candidate_id').value = candidateId;
        document.getElementById('deleteCandidateName').textContent = candidateName;
        
        const deleteModal = new bootstrap.Modal(document.getElementById('deleteCandidateModal'));
        deleteModal.show();
    };
    
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