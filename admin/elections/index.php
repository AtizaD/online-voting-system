<?php
require_once '../../config/config.php';
require_once '../../auth/session.php';
require_once '../../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

$page_title = 'Elections Management';
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index'],
    ['title' => 'Elections Management']
];

// Handle success/error messages from session
$success = '';
$error = '';

if (isset($_SESSION['elections_success'])) {
    $success = $_SESSION['elections_success'];
    unset($_SESSION['elections_success']);
}

if (isset($_SESSION['elections_error'])) {
    $error = $_SESSION['elections_error'];
    unset($_SESSION['elections_error']);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $db = Database::getInstance()->getConnection();
        
        if ($action === 'create_election') {
            $name = sanitize($_POST['name'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $election_type_id = intval($_POST['election_type_id'] ?? 1);
            $start_date = $_POST['start_date'] ?? '';
            $end_date = $_POST['end_date'] ?? '';
            $status = sanitize($_POST['status'] ?? 'draft');
            
            if (empty($name) || empty($start_date) || empty($end_date)) {
                throw new Exception('Name, start date, and end date are required');
            }
            
            if (strtotime($start_date) >= strtotime($end_date)) {
                throw new Exception('End date must be after start date');
            }
            
            $stmt = $db->prepare("
                INSERT INTO elections (name, description, election_type_id, start_date, end_date, status, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$name, $description, $election_type_id, $start_date, $end_date, $status, $current_user['id']]);
            
            logActivity('election_create', "Created election: {$name}", $current_user['id']);
            $_SESSION['elections_success'] = 'Election created successfully';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
            
        } elseif ($action === 'update_election') {
            $election_id = intval($_POST['election_id'] ?? 0);
            $name = sanitize($_POST['name'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $election_type_id = intval($_POST['election_type_id'] ?? 1);
            $start_date = $_POST['start_date'] ?? '';
            $end_date = $_POST['end_date'] ?? '';
            $status = sanitize($_POST['status'] ?? 'draft');
            
            if (empty($name) || empty($start_date) || empty($end_date) || !$election_id) {
                throw new Exception('All fields are required');
            }
            
            if (strtotime($start_date) >= strtotime($end_date)) {
                throw new Exception('End date must be after start date');
            }
            
            $stmt = $db->prepare("
                UPDATE elections 
                SET name = ?, description = ?, election_type_id = ?, start_date = ?, end_date = ?, status = ?, updated_at = NOW() 
                WHERE election_id = ?
            ");
            $stmt->execute([$name, $description, $election_type_id, $start_date, $end_date, $status, $election_id]);
            
            logActivity('election_update', "Updated election: {$name}", $current_user['id']);
            $_SESSION['elections_success'] = 'Election updated successfully';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
            
        } elseif ($action === 'delete_election') {
            $election_id = intval($_POST['election_id'] ?? 0);
            
            if (!$election_id) {
                throw new Exception('Invalid election ID');
            }
            
            // Get election name for logging
            $stmt = $db->prepare("SELECT name FROM elections WHERE election_id = ?");
            $stmt->execute([$election_id]);
            $election = $stmt->fetch();
            
            if (!$election) {
                throw new Exception('Election not found');
            }
            
            // Delete election (this will cascade to delete positions and candidates if properly set up)
            $stmt = $db->prepare("DELETE FROM elections WHERE election_id = ?");
            $stmt->execute([$election_id]);
            
            logActivity('election_delete', "Deleted election: {$election['name']}", $current_user['id']);
            $_SESSION['elections_success'] = 'Election deleted successfully';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        
    } catch (Exception $e) {
        $_SESSION['elections_error'] = $e->getMessage();
        logError("Elections management error: " . $e->getMessage());
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Get elections data
try {
    $db = Database::getInstance()->getConnection();
    
    // Get election types for forms
    $stmt = $db->prepare("SELECT * FROM election_types WHERE is_active = 1 ORDER BY name");
    $stmt->execute();
    $election_types = $stmt->fetchAll();
    
    // Get all elections with counts
    $stmt = $db->prepare("
        SELECT e.*, 
               et.name as election_type_name,
               COUNT(DISTINCT p.position_id) as position_count,
               COUNT(DISTINCT c.candidate_id) as candidate_count,
               COUNT(DISTINCT v.vote_id) as vote_count,
               u.first_name, u.last_name
        FROM elections e
        LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
        LEFT JOIN positions p ON e.election_id = p.election_id
        LEFT JOIN candidates c ON p.position_id = c.position_id
        LEFT JOIN votes v ON c.candidate_id = v.candidate_id
        LEFT JOIN users u ON e.created_by = u.user_id
        GROUP BY e.election_id
        ORDER BY e.created_at DESC
    ");
    $stmt->execute();
    $elections = $stmt->fetchAll();
    
} catch (Exception $e) {
    logError("Elections fetch error: " . $e->getMessage());
    $_SESSION['elections_error'] = "Unable to load elections data";
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

include '../../includes/header.php';
?>

<!-- Elections Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="fas fa-vote-yea me-2"></i>Elections Management
        </h4>
        <small class="text-muted">Create, manage, and monitor elections</small>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createElectionModal">
        <i class="fas fa-plus me-2"></i>Create New Election
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

<!-- Elections Overview Cards -->
<div class="row mb-4">
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-primary">
                <i class="fas fa-vote-yea"></i>
            </div>
            <div class="stats-info">
                <h3><?= count($elections) ?></h3>
                <p>Total Elections</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-success">
                <i class="fas fa-play-circle"></i>
            </div>
            <div class="stats-info">
                <h3><?= count(array_filter($elections, fn($e) => $e['status'] === 'active')) ?></h3>
                <p>Active Elections</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-warning">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stats-info">
                <h3><?= count(array_filter($elections, fn($e) => $e['status'] === 'draft')) ?></h3>
                <p>Draft Elections</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-info">
                <i class="fas fa-archive"></i>
            </div>
            <div class="stats-info">
                <h3><?= count(array_filter($elections, fn($e) => $e['status'] === 'completed')) ?></h3>
                <p>Completed Elections</p>
            </div>
        </div>
    </div>
</div>

<!-- Elections List -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="fas fa-list me-2"></i>All Elections
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($elections)): ?>
            <div class="text-center py-5">
                <i class="fas fa-vote-yea text-muted" style="font-size: 4rem;"></i>
                <h4 class="text-muted mt-3">No Elections Found</h4>
                <p class="text-muted">Create your first election to get started.</p>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createElectionModal">
                    <i class="fas fa-plus me-2"></i>Create Election
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Election</th>
                            <th>Duration</th>
                            <th>Status</th>
                            <th>Positions</th>
                            <th>Candidates</th>
                            <th>Votes</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($elections as $election): ?>
                            <tr>
                                <td>
                                    <div>
                                        <strong><?= sanitize($election['name']) ?></strong>
                                        <br><span class="badge bg-secondary"><?= sanitize($election['election_type_name']) ?></span>
                                        <?php if ($election['description']): ?>
                                            <br><small class="text-muted"><?= sanitize(substr($election['description'], 0, 60)) ?><?= strlen($election['description']) > 60 ? '...' : '' ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <small>
                                        <strong>Start:</strong> <?= date('M j, Y g:i A', strtotime($election['start_date'])) ?><br>
                                        <strong>End:</strong> <?= date('M j, Y g:i A', strtotime($election['end_date'])) ?>
                                    </small>
                                </td>
                                <td>
                                    <?php
                                    $status_class = match($election['status']) {
                                        'active' => 'success',
                                        'draft' => 'warning',
                                        'completed' => 'info',
                                        'cancelled' => 'danger',
                                        default => 'secondary'
                                    };
                                    ?>
                                    <span class="badge bg-<?= $status_class ?>"><?= ucfirst($election['status']) ?></span>
                                </td>
                                <td class="text-center"><?= $election['position_count'] ?></td>
                                <td class="text-center"><?= $election['candidate_count'] ?></td>
                                <td class="text-center"><?= $election['vote_count'] ?></td>
                                <td>
                                    <small><?= sanitize($election['first_name']) ?> <?= sanitize($election['last_name']) ?></small>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="positions?election_id=<?= $election['election_id'] ?>" class="btn btn-outline-primary" title="Manage Positions">
                                            <i class="fas fa-sitemap"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-secondary" onclick="editElection(<?= htmlspecialchars(json_encode($election)) ?>)" title="Edit Election">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($election['status'] === 'draft'): ?>
                                            <button type="button" class="btn btn-outline-danger" onclick="deleteElection(<?= $election['election_id'] ?>, '<?= addslashes($election['name']) ?>')" title="Delete Election">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
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

<!-- Create Election Modal -->
<div class="modal fade" id="createElectionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_election">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>Create New Election
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Election Name</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="election_type_id" class="form-label">Election Type</label>
                        <select class="form-select" id="election_type_id" name="election_type_id" required>
                            <?php foreach ($election_types as $type): ?>
                                <option value="<?= $type['election_type_id'] ?>"><?= sanitize($type['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="start_date" class="form-label">Start Date & Time</label>
                                <input type="datetime-local" class="form-control" id="start_date" name="start_date" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="end_date" class="form-label">End Date & Time</label>
                                <input type="datetime-local" class="form-control" id="end_date" name="end_date" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Election</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Election Modal -->
<div class="modal fade" id="editElectionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editElectionForm">
                <input type="hidden" name="action" value="update_election">
                <input type="hidden" name="election_id" id="edit_election_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Election
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">Election Name</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_election_type_id" class="form-label">Election Type</label>
                        <select class="form-select" id="edit_election_type_id" name="election_type_id" required>
                            <?php foreach ($election_types as $type): ?>
                                <option value="<?= $type['election_type_id'] ?>"><?= sanitize($type['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_start_date" class="form-label">Start Date & Time</label>
                                <input type="datetime-local" class="form-control" id="edit_start_date" name="start_date" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_end_date" class="form-label">End Date & Time</label>
                                <input type="datetime-local" class="form-control" id="edit_end_date" name="end_date" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_status" class="form-label">Status</label>
                        <select class="form-select" id="edit_status" name="status">
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Election</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteElectionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the election "<strong id="deleteElectionTitle"></strong>"?</p>
                <p class="text-danger"><small><i class="fas fa-warning me-1"></i>This action cannot be undone and will also delete all positions, candidates, and votes associated with this election.</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteElectionForm">
                    <input type="hidden" name="action" value="delete_election">
                    <input type="hidden" name="election_id" id="delete_election_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Election</button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
/* Elections Page Styles */

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
}
</style>

<script>
// Elections Management JavaScript
document.addEventListener('DOMContentLoaded', function() {
    
    // Edit election function
    window.editElection = function(election) {
        document.getElementById('edit_election_id').value = election.election_id;
        document.getElementById('edit_name').value = election.name;
        document.getElementById('edit_description').value = election.description || '';
        document.getElementById('edit_election_type_id').value = election.election_type_id;
        document.getElementById('edit_status').value = election.status;
        
        // Format dates for datetime-local input
        if (election.start_date) {
            const startDate = new Date(election.start_date);
            document.getElementById('edit_start_date').value = startDate.toISOString().slice(0, 16);
        }
        
        if (election.end_date) {
            const endDate = new Date(election.end_date);
            document.getElementById('edit_end_date').value = endDate.toISOString().slice(0, 16);
        }
        
        const editModal = new bootstrap.Modal(document.getElementById('editElectionModal'));
        editModal.show();
    };
    
    // Delete election function
    window.deleteElection = function(electionId, electionTitle) {
        document.getElementById('delete_election_id').value = electionId;
        document.getElementById('deleteElectionTitle').textContent = electionTitle;
        
        const deleteModal = new bootstrap.Modal(document.getElementById('deleteElectionModal'));
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