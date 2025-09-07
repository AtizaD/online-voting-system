<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['election_officer']);

$db = Database::getInstance()->getConnection();
$election_id = $_GET['election_id'] ?? null;

if (!$election_id) {
    $_SESSION['error'] = 'Election ID is required.';
    redirectTo('election-officer/elections/');
}

// Get election details
$stmt = $db->prepare("SELECT * FROM elections WHERE election_id = ? AND is_active = 1");
$stmt->execute([$election_id]);
$election = $stmt->fetch();

if (!$election) {
    $_SESSION['error'] = 'Election not found.';
    redirectTo('election-officer/elections/');
}

$page_title = 'Manage Positions - ' . $election['title'];
$breadcrumbs = [
    ['title' => 'Election Officer', 'url' => SITE_URL . 'election-officer/'],
    ['title' => 'Elections', 'url' => SITE_URL . 'election-officer/elections/'],
    ['title' => 'Positions']
];

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_position') {
        $position_name = sanitize($_POST['position_name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $max_candidates = intval($_POST['max_candidates'] ?? 0);
        $max_votes_per_voter = intval($_POST['max_votes_per_voter'] ?? 1);
        $is_required = isset($_POST['is_required']) ? 1 : 0;
        
        // Validation
        $errors = [];
        if (empty($position_name)) $errors[] = 'Position name is required';
        if ($max_candidates <= 0) $errors[] = 'Maximum candidates must be greater than 0';
        if ($max_votes_per_voter <= 0) $errors[] = 'Maximum votes per voter must be greater than 0';
        if ($max_votes_per_voter > $max_candidates) {
            $errors[] = 'Maximum votes per voter cannot exceed maximum candidates';
        }
        
        // Check for duplicate position name
        $stmt = $db->prepare("
            SELECT position_id FROM positions 
            WHERE election_id = ? AND position_name = ? AND is_active = 1
        ");
        $stmt->execute([$election_id, $position_name]);
        if ($stmt->fetch()) {
            $errors[] = 'Position name already exists for this election';
        }
        
        if (empty($errors)) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO positions (election_id, position_name, description, max_candidates, 
                                         max_votes_per_voter, is_required, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $election_id, $position_name, $description, $max_candidates,
                    $max_votes_per_voter, $is_required
                ]);
                
                logActivity('position_create', "Position created: $position_name for election: {$election['title']}");
                $message = 'Position added successfully!';
                
            } catch (Exception $e) {
                $error = 'Failed to add position. Please try again.';
                error_log("Position add error: " . $e->getMessage());
            }
        } else {
            $error = implode('<br>', $errors);
        }
    }
    
    elseif ($action === 'update_position') {
        $position_id = intval($_POST['position_id'] ?? 0);
        $position_name = sanitize($_POST['position_name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $max_candidates = intval($_POST['max_candidates'] ?? 0);
        $max_votes_per_voter = intval($_POST['max_votes_per_voter'] ?? 1);
        $is_required = isset($_POST['is_required']) ? 1 : 0;
        
        // Validation
        $errors = [];
        if (!$position_id) $errors[] = 'Position ID is required';
        if (empty($position_name)) $errors[] = 'Position name is required';
        if ($max_candidates <= 0) $errors[] = 'Maximum candidates must be greater than 0';
        if ($max_votes_per_voter <= 0) $errors[] = 'Maximum votes per voter must be greater than 0';
        if ($max_votes_per_voter > $max_candidates) {
            $errors[] = 'Maximum votes per voter cannot exceed maximum candidates';
        }
        
        // Check for duplicate position name (excluding current)
        $stmt = $db->prepare("
            SELECT position_id FROM positions 
            WHERE election_id = ? AND position_name = ? AND position_id != ? AND is_active = 1
        ");
        $stmt->execute([$election_id, $position_name, $position_id]);
        if ($stmt->fetch()) {
            $errors[] = 'Position name already exists for this election';
        }
        
        if (empty($errors)) {
            try {
                $stmt = $db->prepare("
                    UPDATE positions 
                    SET position_name = ?, description = ?, max_candidates = ?, 
                        max_votes_per_voter = ?, is_required = ?, updated_at = NOW()
                    WHERE position_id = ? AND election_id = ?
                ");
                $stmt->execute([
                    $position_name, $description, $max_candidates,
                    $max_votes_per_voter, $is_required, $position_id, $election_id
                ]);
                
                logActivity('position_update', "Position updated: $position_name for election: {$election['title']}");
                $message = 'Position updated successfully!';
                
            } catch (Exception $e) {
                $error = 'Failed to update position. Please try again.';
                error_log("Position update error: " . $e->getMessage());
            }
        } else {
            $error = implode('<br>', $errors);
        }
    }
    
    elseif ($action === 'delete_position') {
        $position_id = intval($_POST['position_id'] ?? 0);
        
        if ($position_id) {
            try {
                // Check if position has candidates
                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM candidates 
                    WHERE position_id = ? AND is_active = 1
                ");
                $stmt->execute([$position_id]);
                $candidate_count = $stmt->fetchColumn();
                
                if ($candidate_count > 0) {
                    $error = 'Cannot delete position with existing candidates. Remove candidates first.';
                } else {
                    // Soft delete
                    $stmt = $db->prepare("
                        UPDATE positions 
                        SET is_active = 0, updated_at = NOW()
                        WHERE position_id = ? AND election_id = ?
                    ");
                    $stmt->execute([$position_id, $election_id]);
                    
                    logActivity('position_delete', "Position deleted for election: {$election['title']}");
                    $message = 'Position deleted successfully!';
                }
                
            } catch (Exception $e) {
                $error = 'Failed to delete position. Please try again.';
                error_log("Position delete error: " . $e->getMessage());
            }
        }
    }
}

// Get positions for this election
$stmt = $db->prepare("
    SELECT 
        p.*,
        COUNT(DISTINCT c.candidate_id) as candidate_count,
        COUNT(DISTINCT v.vote_id) as vote_count
    FROM positions p
    LEFT JOIN candidates c ON p.position_id = c.position_id
    LEFT JOIN votes v ON c.candidate_id = v.candidate_id
    WHERE p.election_id = ? AND p.is_active = 1
    GROUP BY p.position_id
    ORDER BY p.created_at ASC
");
$stmt->execute([$election_id]);
$positions = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<style>
.election-info-card {
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    color: white;
    border-radius: 0.5rem;
    padding: 1.5rem;
    margin-bottom: 2rem;
}

.election-title {
    font-size: 1.5rem;
    font-weight: 600;
    margin: 0 0 0.5rem;
}

.election-meta {
    display: flex;
    gap: 2rem;
    font-size: 0.875rem;
    opacity: 0.9;
}

.positions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 1.5rem;
}

.position-card {
    background: white;
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    overflow: hidden;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.position-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.position-header {
    padding: 1.5rem;
    border-bottom: 1px solid #f1f5f9;
}

.position-name {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 0.5rem;
}

.position-description {
    color: #64748b;
    margin: 0;
    font-size: 0.875rem;
}

.position-body {
    padding: 1.5rem;
}

.position-stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
    margin-bottom: 1rem;
}

.position-stat {
    text-align: center;
    padding: 0.75rem;
    background: #f8fafc;
    border-radius: 0.375rem;
}

.position-stat-number {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
}

.position-stat-label {
    font-size: 0.75rem;
    color: #64748b;
    margin: 0;
}

.position-details {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.5rem;
    margin-bottom: 1rem;
    font-size: 0.875rem;
}

.position-detail {
    display: flex;
    justify-content: space-between;
}

.add-position-card {
    background: white;
    border: 2px dashed #cbd5e1;
    border-radius: 0.5rem;
    padding: 2rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
}

.add-position-card:hover {
    border-color: var(--primary-color);
    background: #f8fafc;
}
</style>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= $message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= $error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Election Info -->
<div class="election-info-card">
    <h2 class="election-title"><?= sanitize($election['title']) ?></h2>
    <div class="election-meta">
        <span><i class="fas fa-calendar me-1"></i><?= date('M j, Y', strtotime($election['start_date'])) ?> - <?= date('M j, Y', strtotime($election['end_date'])) ?></span>
        <span><i class="fas fa-flag me-1"></i>Status: <?= ucfirst($election['status']) ?></span>
        <span><i class="fas fa-list me-1"></i><?= count($positions) ?> Positions</span>
    </div>
</div>

<!-- Positions Grid -->
<div class="positions-grid">
    <!-- Add Position Card -->
    <div class="add-position-card" onclick="showAddPositionModal()">
        <i class="fas fa-plus-circle fa-3x text-muted mb-3"></i>
        <h4>Add New Position</h4>
        <p class="text-muted">Click to add a new position to this election</p>
    </div>
    
    <!-- Position Cards -->
    <?php foreach ($positions as $position): ?>
        <div class="position-card">
            <div class="position-header">
                <h3 class="position-name"><?= sanitize($position['position_name']) ?></h3>
                <p class="position-description"><?= sanitize($position['description']) ?></p>
            </div>
            
            <div class="position-body">
                <div class="position-stats">
                    <div class="position-stat">
                        <h4 class="position-stat-number"><?= $position['candidate_count'] ?></h4>
                        <p class="position-stat-label">Candidates</p>
                    </div>
                    <div class="position-stat">
                        <h4 class="position-stat-number"><?= $position['vote_count'] ?></h4>
                        <p class="position-stat-label">Votes</p>
                    </div>
                </div>
                
                <div class="position-details">
                    <div class="position-detail">
                        <span>Max Candidates:</span>
                        <strong><?= $position['max_candidates'] ?></strong>
                    </div>
                    <div class="position-detail">
                        <span>Max Votes:</span>
                        <strong><?= $position['max_votes_per_voter'] ?></strong>
                    </div>
                    <div class="position-detail">
                        <span>Required:</span>
                        <strong><?= $position['is_required'] ? 'Yes' : 'No' ?></strong>
                    </div>
                </div>
                
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-primary btn-sm flex-fill" 
                            onclick="editPosition(<?= htmlspecialchars(json_encode($position)) ?>)">
                        <i class="fas fa-edit me-1"></i>Edit
                    </button>
                    <button class="btn btn-outline-danger btn-sm" 
                            onclick="deletePosition(<?= $position['position_id'] ?>, '<?= sanitize($position['position_name']) ?>')">
                        <i class="fas fa-trash me-1"></i>Delete
                    </button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Add/Edit Position Modal -->
<div class="modal fade" id="positionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="positionModalTitle">Add Position</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="positionForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="positionAction" value="add_position">
                    <input type="hidden" name="position_id" id="positionId">
                    
                    <div class="mb-3">
                        <label for="position_name" class="form-label">Position Name</label>
                        <input type="text" class="form-control" id="position_name" name="position_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="max_candidates" class="form-label">Maximum Candidates</label>
                            <input type="number" class="form-control" id="max_candidates" name="max_candidates" 
                                   min="1" value="10" required>
                            <div class="form-text">Maximum number of candidates for this position</div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="max_votes_per_voter" class="form-label">Max Votes per Voter</label>
                            <input type="number" class="form-control" id="max_votes_per_voter" name="max_votes_per_voter" 
                                   min="1" value="1" required>
                            <div class="form-text">How many candidates a voter can select</div>
                        </div>
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_required" name="is_required" checked>
                        <label class="form-check-label" for="is_required">
                            Required Position
                        </label>
                        <div class="form-text">Voters must vote for this position</div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="savePositionBtn">Add Position</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showAddPositionModal() {
    document.getElementById('positionModalTitle').textContent = 'Add Position';
    document.getElementById('positionAction').value = 'add_position';
    document.getElementById('savePositionBtn').textContent = 'Add Position';
    document.getElementById('positionForm').reset();
    document.getElementById('max_candidates').value = '10';
    document.getElementById('max_votes_per_voter').value = '1';
    document.getElementById('is_required').checked = true;
    new bootstrap.Modal(document.getElementById('positionModal')).show();
}

function editPosition(position) {
    document.getElementById('positionModalTitle').textContent = 'Edit Position';
    document.getElementById('positionAction').value = 'update_position';
    document.getElementById('savePositionBtn').textContent = 'Update Position';
    document.getElementById('positionId').value = position.position_id;
    document.getElementById('position_name').value = position.position_name;
    document.getElementById('description').value = position.description || '';
    document.getElementById('max_candidates').value = position.max_candidates;
    document.getElementById('max_votes_per_voter').value = position.max_votes_per_voter;
    document.getElementById('is_required').checked = position.is_required == 1;
    new bootstrap.Modal(document.getElementById('positionModal')).show();
}

function deletePosition(positionId, positionName) {
    if (confirm(`Are you sure you want to delete the position "${positionName}"?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_position">
            <input type="hidden" name="position_id" value="${positionId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Validate max votes doesn't exceed max candidates
document.getElementById('max_candidates').addEventListener('input', function() {
    const maxVotesInput = document.getElementById('max_votes_per_voter');
    const maxCandidates = parseInt(this.value) || 0;
    const maxVotes = parseInt(maxVotesInput.value) || 0;
    
    if (maxVotes > maxCandidates) {
        maxVotesInput.value = maxCandidates;
    }
    
    maxVotesInput.max = maxCandidates;
});

document.getElementById('max_votes_per_voter').addEventListener('input', function() {
    const maxCandidates = parseInt(document.getElementById('max_candidates').value) || 0;
    const maxVotes = parseInt(this.value) || 0;
    
    if (maxVotes > maxCandidates) {
        this.value = maxCandidates;
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>