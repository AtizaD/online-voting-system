<?php
require_once '../../config/config.php';
require_once '../../auth/session.php';
require_once '../../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

$page_title = 'Election Positions Management';
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index'],
    ['title' => 'Election Positions']
];

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
        
        if ($action === 'add_position_to_election') {
            $election_id = intval($_POST['election_id'] ?? 0);
            $title = sanitize($_POST['title'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $max_candidates = intval($_POST['max_candidates'] ?? 10);
            $display_order = intval($_POST['display_order'] ?? 1);
            
            if (!$election_id || empty($title)) {
                throw new Exception('Election and position title are required');
            }
            
            if ($max_candidates < 1) {
                throw new Exception('Maximum candidates must be at least 1');
            }
            
            // Check if position already exists in this election
            $stmt = $db->prepare("SELECT position_id FROM positions WHERE election_id = ? AND title = ?");
            $stmt->execute([$election_id, $title]);
            if ($stmt->fetch()) {
                throw new Exception('This position already exists in the selected election');
            }
            
            $stmt = $db->prepare("
                INSERT INTO positions (election_id, title, description, max_candidates, display_order, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$election_id, $title, $description, $max_candidates, $display_order]);
            
            logActivity('position_add', "Added position '{$title}' to election ID {$election_id}", $current_user['id']);
            $_SESSION['positions_success'] = 'Position added to election successfully';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
            
        } elseif ($action === 'bulk_add_positions') {
            $election_id = intval($_POST['election_id'] ?? 0);
            $position_titles = $_POST['position_titles'] ?? [];
            
            // Debug logging
            logError("Bulk add debug - Election ID: {$election_id}, Positions: " . json_encode($position_titles));
            
            if (!$election_id || empty($position_titles)) {
                throw new Exception('Election and at least one position are required');
            }
            
            $added_count = 0;
            $skipped_positions = [];
            
            foreach ($position_titles as $title) {
                $title = sanitize(trim($title));
                if (empty($title)) continue;
                
                // Check if position already exists
                $stmt = $db->prepare("SELECT position_id FROM positions WHERE election_id = ? AND title = ?");
                $stmt->execute([$election_id, $title]);
                if ($stmt->fetch()) {
                    $skipped_positions[] = $title;
                    continue; // Skip existing positions
                }
                
                $stmt = $db->prepare("
                    INSERT INTO positions (election_id, title, max_candidates, display_order, created_at) 
                    VALUES (?, ?, 10, ?, NOW())
                ");
                $stmt->execute([$election_id, $title, $added_count + 1]);
                $added_count++;
            }
            
            if ($added_count > 0) {
                $message = "{$added_count} positions added successfully";
                if (!empty($skipped_positions)) {
                    $message .= " (" . count($skipped_positions) . " skipped - already exist)";
                }
                logActivity('positions_bulk_add', "Added {$added_count} positions to election ID {$election_id}", $current_user['id']);
                $_SESSION['positions_success'] = $message;
            } else {
                $_SESSION['positions_error'] = "No new positions were added (" . count($skipped_positions) . " positions already exist)";
            }
            
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
            
        } elseif ($action === 'update_position') {
            $position_id = intval($_POST['position_id'] ?? 0);
            $title = sanitize($_POST['title'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $max_candidates = intval($_POST['max_candidates'] ?? 10);
            $display_order = intval($_POST['display_order'] ?? 1);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (!$position_id || empty($title)) {
                throw new Exception('Position ID and title are required');
            }
            
            $stmt = $db->prepare("
                UPDATE positions 
                SET title = ?, description = ?, max_candidates = ?, display_order = ?, is_active = ?, updated_at = NOW()
                WHERE position_id = ?
            ");
            $stmt->execute([$title, $description, $max_candidates, $display_order, $is_active, $position_id]);
            
            logActivity('position_update', "Updated position: {$title}", $current_user['id']);
            $_SESSION['positions_success'] = 'Position updated successfully';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
            
        } elseif ($action === 'delete_position') {
            $position_id = intval($_POST['position_id'] ?? 0);
            
            if (!$position_id) {
                throw new Exception('Invalid position ID');
            }
            
            // Get position info
            $stmt = $db->prepare("SELECT title, election_id FROM positions WHERE position_id = ?");
            $stmt->execute([$position_id]);
            $position = $stmt->fetch();
            
            if (!$position) {
                throw new Exception('Position not found');
            }
            
            // Check if position has candidates
            $stmt = $db->prepare("SELECT COUNT(*) as candidate_count FROM candidates WHERE position_id = ?");
            $stmt->execute([$position_id]);
            $candidates = $stmt->fetch();
            
            if ($candidates['candidate_count'] > 0) {
                throw new Exception('Cannot delete position with candidates. Remove candidates first.');
            }
            
            $stmt = $db->prepare("DELETE FROM positions WHERE position_id = ?");
            $stmt->execute([$position_id]);
            
            logActivity('position_delete', "Deleted position: {$position['title']}", $current_user['id']);
            $_SESSION['positions_success'] = 'Position deleted successfully';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        
    } catch (Exception $e) {
        $_SESSION['positions_error'] = $e->getMessage();
        logError("Positions management error: " . $e->getMessage());
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Get data for the page
try {
    $db = Database::getInstance()->getConnection();
    
    // Get all elections for dropdown
    $stmt = $db->prepare("SELECT election_id, name, status FROM elections ORDER BY created_at DESC");
    $stmt->execute();
    $elections = $stmt->fetchAll();
    
    // Get all positions with election and candidate info
    $stmt = $db->prepare("
        SELECT p.*, e.name as election_name, e.status as election_status,
               COUNT(c.candidate_id) as candidate_count
        FROM positions p
        LEFT JOIN elections e ON p.election_id = e.election_id
        LEFT JOIN candidates c ON p.position_id = c.position_id
        GROUP BY p.position_id
        ORDER BY e.created_at DESC, p.display_order ASC, p.title ASC
    ");
    $stmt->execute();
    $positions = $stmt->fetchAll();
    
    // Get position title statistics
    $stmt = $db->prepare("
        SELECT title, COUNT(*) as usage_count
        FROM positions 
        GROUP BY title 
        ORDER BY usage_count DESC, title ASC
    ");
    $stmt->execute();
    $position_stats = $stmt->fetchAll();
    
    // Common position templates
    $common_positions = [
        'Student Council' => [
            'President', 'Vice President', 'Secretary', 'Treasurer', 
            'Public Relations Officer', 'Social Secretary'
        ],
        'Class Representative' => [
            'Class President', 'Class Secretary', 'Class Treasurer'
        ],
        'Prefect Positions' => [
            'Head Boy', 'Head Girl', 'Assistant Head Boy', 'Assistant Head Girl',
            'Dining Hall Prefect', 'Library Prefect', 'Sports Prefect'
        ],
        'Academic Positions' => [
            'Science Prefect', 'Mathematics Prefect', 'English Prefect',
            'Social Studies Prefect', 'Arts Prefect'
        ]
    ];
    
} catch (Exception $e) {
    logError("Positions data fetch error: " . $e->getMessage());
    $_SESSION['positions_error'] = "Unable to load positions data";
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

include '../../includes/header.php';
?>

<!-- Positions Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="fas fa-bookmark me-2"></i>Election Positions Management
        </h4>
        <small class="text-muted">Add and manage positions for elections</small>
    </div>
    <div class="btn-group">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPositionModal">
            <i class="fas fa-plus me-2"></i>Add Single Position
        </button>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#bulkAddModal">
            <i class="fas fa-layer-group me-2"></i>Bulk Add Positions
        </button>
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
                <i class="fas fa-bookmark"></i>
            </div>
            <div class="stats-info">
                <h3><?= count($positions) ?></h3>
                <p>Total Positions</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-success">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-info">
                <h3><?= array_sum(array_column($positions, 'candidate_count')) ?></h3>
                <p>Total Candidates</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-info">
                <i class="fas fa-layer-group"></i>
            </div>
            <div class="stats-info">
                <h3><?= count($position_stats) ?></h3>
                <p>Position Types</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-warning">
                <i class="fas fa-vote-yea"></i>
            </div>
            <div class="stats-info">
                <h3><?= count($elections) ?></h3>
                <p>Total Elections</p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Position Templates -->
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-templates me-2"></i>Common Position Templates
                </h5>
            </div>
            <div class="card-body">
                <?php foreach ($common_positions as $category => $positions_list): ?>
                    <div class="mb-3">
                        <h6 class="fw-bold text-primary"><?= $category ?></h6>
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($positions_list as $position): ?>
                                <span class="badge bg-light text-dark position-template" 
                                      style="cursor: pointer;" 
                                      onclick="addPositionTemplate('<?= addslashes($position) ?>')">
                                    <?= $position ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="text-muted small mt-3">
                    <i class="fas fa-info-circle me-1"></i>
                    Click on any template above to quickly add it to an election
                </div>
            </div>
        </div>
    </div>
    
    <!-- Most Used Positions -->
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-chart-bar me-2"></i>Most Used Position Titles
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($position_stats)): ?>
                    <p class="text-muted text-center py-3">No positions created yet</p>
                <?php else: ?>
                    <div class="row">
                        <?php foreach (array_slice($position_stats, 0, 12) as $stat): ?>
                            <div class="col-md-6 col-lg-4 mb-2">
                                <div class="d-flex justify-content-between align-items-center p-2 bg-light rounded">
                                    <span class="fw-medium"><?= sanitize($stat['title']) ?></span>
                                    <span class="badge bg-primary"><?= $stat['usage_count'] ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- All Positions List -->
<div class="card">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">
                <i class="fas fa-list me-2"></i>All Election Positions
            </h5>
            <div class="d-flex gap-2">
                <select class="form-select form-select-sm" id="electionFilter" style="width: auto;">
                    <option value="">All Elections</option>
                    <?php foreach ($elections as $election): ?>
                        <option value="<?= $election['election_id'] ?>"><?= sanitize($election['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($positions)): ?>
            <div class="text-center py-5">
                <i class="fas fa-bookmark text-muted" style="font-size: 4rem;"></i>
                <h4 class="text-muted mt-3">No Positions Found</h4>
                <p class="text-muted">Add positions to elections to get started.</p>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPositionModal">
                    <i class="fas fa-plus me-2"></i>Add First Position
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover" id="positionsTable">
                    <thead>
                        <tr>
                            <th>Position Title</th>
                            <th>Election</th>
                            <th>Status</th>
                            <th>Candidates</th>
                            <th>Max Candidates</th>
                            <th>Display Order</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($positions as $position): ?>
                            <tr data-election-id="<?= $position['election_id'] ?>">
                                <td>
                                    <div>
                                        <strong><?= sanitize($position['title']) ?></strong>
                                        <?php if ($position['description']): ?>
                                            <br><small class="text-muted"><?= sanitize(substr($position['description'], 0, 60)) ?><?= strlen($position['description']) > 60 ? '...' : '' ?></small>
                                        <?php endif; ?>
                                        <?php if (!$position['is_active']): ?>
                                            <br><span class="badge bg-warning">Inactive</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?= sanitize($position['election_name']) ?>
                                </td>
                                <td>
                                    <?php
                                    $status_class = match($position['election_status']) {
                                        'active' => 'success',
                                        'draft' => 'warning',
                                        'completed' => 'info',
                                        'cancelled' => 'danger',
                                        default => 'secondary'
                                    };
                                    ?>
                                    <span class="badge bg-<?= $status_class ?>"><?= ucfirst($position['election_status']) ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $position['candidate_count'] > 0 ? 'info' : 'secondary' ?>">
                                        <?= $position['candidate_count'] ?>
                                    </span>
                                </td>
                                <td class="text-center"><?= $position['max_candidates'] ?></td>
                                <td class="text-center"><?= $position['display_order'] ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="../elections/positions.php?election_id=<?= $position['election_id'] ?>" class="btn btn-outline-primary" title="Manage in Election">
                                            <i class="fas fa-cog"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-secondary" onclick="editPosition(<?= htmlspecialchars(json_encode($position)) ?>)" title="Edit Position">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($position['candidate_count'] == 0): ?>
                                            <button type="button" class="btn btn-outline-danger" onclick="deletePosition(<?= $position['position_id'] ?>, '<?= addslashes($position['title']) ?>')" title="Delete Position">
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

<!-- Add Single Position Modal -->
<div class="modal fade" id="addPositionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_position_to_election">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>Add Position to Election
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="election_id" class="form-label">Select Election</label>
                        <select class="form-select" id="election_id" name="election_id" required>
                            <option value="">Choose an election...</option>
                            <?php foreach ($elections as $election): ?>
                                <option value="<?= $election['election_id'] ?>" 
                                        <?= $election['status'] === 'completed' ? 'disabled' : '' ?>>
                                    <?= sanitize($election['name']) ?> 
                                    <?= $election['status'] === 'completed' ? '(Completed)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="title" class="form-label">Position Title</label>
                        <input type="text" class="form-control" id="title" name="title" required placeholder="e.g., President">
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description (Optional)</label>
                        <textarea class="form-control" id="description" name="description" rows="2" placeholder="Brief description of the position..."></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="max_candidates" class="form-label">Max Candidates</label>
                                <input type="number" class="form-control" id="max_candidates" name="max_candidates" min="1" value="10" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="display_order" class="form-label">Display Order</label>
                                <input type="number" class="form-control" id="display_order" name="display_order" min="1" value="1" required>
                            </div>
                        </div>
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

<!-- Bulk Add Positions Modal -->
<div class="modal fade" id="bulkAddModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="bulk_add_positions">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-layer-group me-2"></i>Bulk Add Positions
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="bulk_election_id" class="form-label">Select Election</label>
                        <select class="form-select" id="bulk_election_id" name="election_id" required>
                            <option value="">Choose an election...</option>
                            <?php foreach ($elections as $election): ?>
                                <option value="<?= $election['election_id'] ?>" 
                                        <?= $election['status'] === 'completed' ? 'disabled' : '' ?>>
                                    <?= sanitize($election['name']) ?> 
                                    <?= $election['status'] === 'completed' ? '(Completed)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Position Titles (one per line)</label>
                        <textarea class="form-control" id="bulk_positions" rows="8" 
                                  placeholder="President&#10;Vice President&#10;Secretary&#10;Treasurer&#10;Public Relations Officer&#10;Social Secretary" 
                                  style="white-space: pre-wrap;" required></textarea>
                        <div class="form-text">Enter each position title on a new line</div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Student Council Positions</h6>
                            <div class="d-grid gap-1">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="addBulkTemplate('President\nVice President\nSecretary\nTreasurer\nPublic Relations Officer\nSocial Secretary')">Add Student Council</button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6>Class Representative Positions</h6>
                            <div class="d-grid gap-1">
                                <button type="button" class="btn btn-outline-success btn-sm" onclick="addBulkTemplate('Class President\nClass Secretary\nClass Treasurer')">Add Class Rep</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Add All Positions</button>
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
                        <textarea class="form-control" id="edit_description" name="description" rows="2"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_max_candidates" class="form-label">Max Candidates</label>
                                <input type="number" class="form-control" id="edit_max_candidates" name="max_candidates" min="1" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_display_order" class="form-label">Display Order</label>
                                <input type="number" class="form-control" id="edit_display_order" name="display_order" min="1" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active">
                        <label class="form-check-label" for="edit_is_active">
                            Position is active
                        </label>
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
                <p class="text-danger"><small><i class="fas fa-warning me-1"></i>This action cannot be undone.</small></p>
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

<style>
/* Election Positions Management Styles */

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

.position-template:hover {
    background-color: #0d6efd !important;
    color: white !important;
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
// Election Positions Management JavaScript
document.addEventListener('DOMContentLoaded', function() {
    
    // Add position template to form
    window.addPositionTemplate = function(positionTitle) {
        document.getElementById('title').value = positionTitle;
        const addModal = new bootstrap.Modal(document.getElementById('addPositionModal'));
        addModal.show();
    };
    
    // Add bulk template
    window.addBulkTemplate = function(template) {
        const textarea = document.getElementById('bulk_positions');
        const currentValue = textarea.value.trim();
        const newValue = currentValue ? currentValue + '\n' + template : template;
        textarea.value = newValue;
    };
    
    // Edit position function
    window.editPosition = function(position) {
        document.getElementById('edit_position_id').value = position.position_id;
        document.getElementById('edit_title').value = position.title;
        document.getElementById('edit_description').value = position.description || '';
        document.getElementById('edit_max_candidates').value = position.max_candidates;
        document.getElementById('edit_display_order').value = position.display_order;
        document.getElementById('edit_is_active').checked = position.is_active == 1;
        
        const editModal = new bootstrap.Modal(document.getElementById('editPositionModal'));
        editModal.show();
    };
    
    // Delete position function
    window.deletePosition = function(positionId, positionTitle) {
        document.getElementById('delete_position_id').value = positionId;
        document.getElementById('deletePositionTitle').textContent = positionTitle;
        
        const deleteModal = new bootstrap.Modal(document.getElementById('deletePositionModal'));
        deleteModal.show();
    };
    
    // Election filter functionality
    const electionFilter = document.getElementById('electionFilter');
    const table = document.getElementById('positionsTable');
    
    if (electionFilter && table) {
        electionFilter.addEventListener('change', function() {
            const selectedElectionId = this.value;
            const rows = table.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const electionId = row.dataset.electionId;
                if (!selectedElectionId || electionId === selectedElectionId) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }
    
    // Handle bulk positions textarea - convert to array
    const bulkForm = document.querySelector('#bulkAddModal form');
    if (bulkForm) {
        bulkForm.addEventListener('submit', function(e) {
            const textarea = document.getElementById('bulk_positions');
            const positions = textarea.value.split('\n').filter(p => p.trim());
            
            if (positions.length === 0) {
                e.preventDefault();
                alert('Please enter at least one position title.');
                return;
            }
            
            // Remove the textarea name attribute to prevent it from being submitted
            textarea.removeAttribute('name');
            
            // Clear any existing hidden inputs
            this.querySelectorAll('input[name="position_titles[]"]').forEach(input => input.remove());
            
            // Add each position as a separate input
            positions.forEach(position => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'position_titles[]';
                input.value = position.trim();
                this.appendChild(input);
            });
        });
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