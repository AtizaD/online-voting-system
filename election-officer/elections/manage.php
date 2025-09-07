<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['election_officer']);

$db = Database::getInstance()->getConnection();
$election_id = $_GET['id'] ?? null;
$is_edit = !empty($election_id);

$page_title = $is_edit ? 'Edit Election' : 'Create Election';
$breadcrumbs = [
    ['title' => 'Election Officer', 'url' => SITE_URL . 'election-officer/'],
    ['title' => 'Elections', 'url' => SITE_URL . 'election-officer/elections/'],
    ['title' => $is_edit ? 'Edit Election' : 'Create Election']
];

$message = '';
$error = '';
$election = null;

// Get election data if editing
if ($is_edit) {
    $stmt = $db->prepare("SELECT * FROM elections WHERE election_id = ?");
    $stmt->execute([$election_id]);
    $election = $stmt->fetch();
    
    if (!$election) {
        $_SESSION['error'] = 'Election not found.';
        redirectTo('election-officer/elections/');
    }
}

// Get election types
$stmt = $db->prepare("SELECT * FROM election_types WHERE is_active = 1 ORDER BY name");
$stmt->execute();
$election_types = $stmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['title'] ?? ''); // Using 'name' field in DB
    $description = sanitize($_POST['description'] ?? '');
    $election_type_id = intval($_POST['type_id'] ?? 0);
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $voter_eligibility = sanitize($_POST['voter_eligibility'] ?? 'all');
    $voting_method = sanitize($_POST['voting_method'] ?? 'online');
    $results_visibility = sanitize($_POST['results_visibility'] ?? 'public');
    $allow_multiple_votes = isset($_POST['allow_multiple_votes']) ? 1 : 0;
    $require_verification = isset($_POST['require_verification']) ? 1 : 0;
    $status = sanitize($_POST['status'] ?? 'draft');
    
    // Validation
    $errors = [];
    if (empty($name)) $errors[] = 'Election title is required';
    if (empty($description)) $errors[] = 'Election description is required';
    if (!$election_type_id) $errors[] = 'Election type is required';
    if (empty($start_date)) $errors[] = 'Start date is required';
    if (empty($end_date)) $errors[] = 'End date is required';
    if (strtotime($start_date) >= strtotime($end_date)) {
        $errors[] = 'End date must be after start date';
    }
    if (strtotime($start_date) <= time() && !$is_edit) {
        $errors[] = 'Start date must be in the future';
    }
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            if ($is_edit) {
                // Update existing election
                $stmt = $db->prepare("
                    UPDATE elections 
                    SET name = ?, description = ?, election_type_id = ?, start_date = ?, end_date = ?,
                        voter_eligibility = ?, voting_method = ?, results_visibility = ?,
                        allow_multiple_votes = ?, require_verification = ?, status = ?, 
                        updated_at = NOW()
                    WHERE election_id = ?
                ");
                $stmt->execute([
                    $name, $description, $election_type_id, $start_date, $end_date,
                    $voter_eligibility, $voting_method, $results_visibility,
                    $allow_multiple_votes, $require_verification, $status,
                    $election_id
                ]);
                
                logActivity('election_update', "Election updated: $name", getCurrentUser()['id']);
                $message = 'Election updated successfully!';
            } else {
                // Create new election
                $stmt = $db->prepare("
                    INSERT INTO elections (name, description, election_type_id, start_date, end_date,
                                         voter_eligibility, voting_method, results_visibility,
                                         allow_multiple_votes, require_verification, status,
                                         created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $name, $description, $election_type_id, $start_date, $end_date,
                    $voter_eligibility, $voting_method, $results_visibility,
                    $allow_multiple_votes, $require_verification, $status,
                    getCurrentUser()['id']
                ]);
                
                $election_id = $db->lastInsertId();
                logActivity('election_create', "Election created: $name", getCurrentUser()['id']);
                $message = 'Election created successfully!';
            }
            
            $db->commit();
            
            // Redirect to elections list with success message
            $_SESSION['success'] = $message;
            redirectTo('election-officer/elections/');
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Failed to save election. Please try again.';
            error_log("Election save error: " . $e->getMessage());
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<style>
.form-card {
    background: white;
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    overflow: hidden;
}

.form-header {
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    color: white;
    padding: 1.5rem;
}

.form-title {
    font-size: 1.5rem;
    font-weight: 600;
    margin: 0;
}

.form-body {
    padding: 2rem;
}

.form-section {
    margin-bottom: 2rem;
    padding-bottom: 2rem;
    border-bottom: 1px solid #f1f5f9;
}

.form-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.form-section-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.form-section-title i {
    color: var(--primary-color);
}

.form-group-inline {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.datetime-group {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

@media (max-width: 768px) {
    .datetime-group {
        grid-template-columns: 1fr;
    }
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

<div class="form-card">
    <div class="form-header">
        <h2 class="form-title">
            <i class="fas fa-poll me-2"></i>
            <?= $is_edit ? 'Edit Election' : 'Create New Election' ?>
        </h2>
    </div>
    
    <div class="form-body">
        <form method="POST">
            <!-- Basic Information -->
            <div class="form-section">
                <h3 class="form-section-title">
                    <i class="fas fa-info-circle"></i>
                    Basic Information
                </h3>
                
                <div class="mb-3">
                    <label for="title" class="form-label">Election Title</label>
                    <input type="text" class="form-control" id="title" name="title" 
                           value="<?= sanitize($election['name'] ?? '') ?>" required>
                    <div class="form-text">Choose a clear, descriptive title for the election.</div>
                </div>
                
                <div class="mb-3">
                    <label for="description" class="form-label">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="4" required><?= sanitize($election['description'] ?? '') ?></textarea>
                    <div class="form-text">Provide details about the election purpose and procedures.</div>
                </div>
                
                <div class="mb-3">
                    <label for="type_id" class="form-label">Election Type</label>
                    <select class="form-select" id="type_id" name="type_id" required>
                        <option value="">Select Election Type</option>
                        <?php foreach ($election_types as $type): ?>
                            <option value="<?= $type['election_type_id'] ?>" 
                                    <?= ($election['election_type_id'] ?? 0) == $type['election_type_id'] ? 'selected' : '' ?>>
                                <?= sanitize($type['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- Schedule -->
            <div class="form-section">
                <h3 class="form-section-title">
                    <i class="fas fa-calendar"></i>
                    Schedule
                </h3>
                
                <div class="datetime-group">
                    <div class="mb-3">
                        <label for="start_date" class="form-label">Start Date & Time</label>
                        <input type="datetime-local" class="form-control" id="start_date" name="start_date" 
                               value="<?= $election ? date('Y-m-d\TH:i', strtotime($election['start_date'])) : '' ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="end_date" class="form-label">End Date & Time</label>
                        <input type="datetime-local" class="form-control" id="end_date" name="end_date" 
                               value="<?= $election ? date('Y-m-d\TH:i', strtotime($election['end_date'])) : '' ?>" required>
                    </div>
                </div>
            </div>
            
            <!-- Voting Configuration -->
            <div class="form-section">
                <h3 class="form-section-title">
                    <i class="fas fa-cog"></i>
                    Voting Configuration
                </h3>
                
                <div class="form-group-inline mb-3">
                    <div>
                        <label for="voter_eligibility" class="form-label">Voter Eligibility</label>
                        <select class="form-select" id="voter_eligibility" name="voter_eligibility">
                            <option value="all" <?= ($election['voter_eligibility'] ?? 'all') === 'all' ? 'selected' : '' ?>>All Students</option>
                            <option value="verified" <?= ($election['voter_eligibility'] ?? '') === 'verified' ? 'selected' : '' ?>>Verified Students Only</option>
                            <option value="custom" <?= ($election['voter_eligibility'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom Criteria</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="voting_method" class="form-label">Voting Method</label>
                        <select class="form-select" id="voting_method" name="voting_method">
                            <option value="online" <?= ($election['voting_method'] ?? 'online') === 'online' ? 'selected' : '' ?>>Online Only</option>
                            <option value="offline" <?= ($election['voting_method'] ?? '') === 'offline' ? 'selected' : '' ?>>Offline Only</option>
                            <option value="hybrid" <?= ($election['voting_method'] ?? '') === 'hybrid' ? 'selected' : '' ?>>Online & Offline</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="results_visibility" class="form-label">Results Visibility</label>
                        <select class="form-select" id="results_visibility" name="results_visibility">
                            <option value="public" <?= ($election['results_visibility'] ?? 'public') === 'public' ? 'selected' : '' ?>>Public</option>
                            <option value="private" <?= ($election['results_visibility'] ?? '') === 'private' ? 'selected' : '' ?>>Private</option>
                            <option value="delayed" <?= ($election['results_visibility'] ?? '') === 'delayed' ? 'selected' : '' ?>>Delayed Release</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group-inline">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="allow_multiple_votes" name="allow_multiple_votes" 
                               <?= ($election['allow_multiple_votes'] ?? 0) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="allow_multiple_votes">
                            Allow Multiple Votes
                        </label>
                        <div class="form-text">Allow voters to change their vote before election ends.</div>
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="require_verification" name="require_verification" 
                               <?= ($election['require_verification'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="require_verification">
                            Require Student Verification
                        </label>
                        <div class="form-text">Only verified students can vote.</div>
                    </div>
                </div>
            </div>
            
            <!-- Status -->
            <div class="form-section">
                <h3 class="form-section-title">
                    <i class="fas fa-flag"></i>
                    Status
                </h3>
                
                <div class="mb-3">
                    <label for="status" class="form-label">Election Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="draft" <?= ($election['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
                        <?php if ($is_edit): ?>
                            <option value="scheduled" <?= ($election['status'] ?? '') === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                            <?php if ($election['status'] === 'active'): ?>
                                <option value="active" selected>Active</option>
                            <?php endif; ?>
                            <?php if ($election['status'] === 'completed'): ?>
                                <option value="completed" selected>Completed</option>
                            <?php endif; ?>
                        <?php endif; ?>
                    </select>
                    <div class="form-text">
                        Draft elections can be edited. Scheduled elections will start automatically.
                    </div>
                </div>
            </div>
            
            <!-- Form Actions -->
            <div class="d-flex gap-3">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>
                    <?= $is_edit ? 'Update Election' : 'Create Election' ?>
                </button>
                
                <a href="<?= SITE_URL ?>election-officer/elections/" class="btn btn-secondary">
                    <i class="fas fa-times me-2"></i>Cancel
                </a>
                
                <?php if ($is_edit && $election['status'] === 'draft'): ?>
                    <button type="button" class="btn btn-success" onclick="scheduleElection()">
                        <i class="fas fa-clock me-2"></i>Save & Schedule
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
// Set minimum datetime to current time for new elections
document.addEventListener('DOMContentLoaded', function() {
    <?php if (!$is_edit): ?>
    const now = new Date();
    const currentDateTime = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    document.getElementById('start_date').min = currentDateTime;
    <?php endif; ?>
    
    // Validate end date is after start date
    document.getElementById('start_date').addEventListener('change', function() {
        document.getElementById('end_date').min = this.value;
        if (document.getElementById('end_date').value && document.getElementById('end_date').value <= this.value) {
            document.getElementById('end_date').value = '';
        }
    });
});

function scheduleElection() {
    document.getElementById('status').value = 'scheduled';
    document.querySelector('form').submit();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>