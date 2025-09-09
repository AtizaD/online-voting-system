<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../includes/functions.php';


// Check if user is logged in and is election officer
requireAuth(['election_officer']);

// Get current user
$current_user = getCurrentUser();

$page_title = 'Manage Candidates';
$breadcrumbs = [
    ['title' => 'Election Officer', 'url' => SITE_URL . '/election-officer/'],
    ['title' => 'Candidates', 'url' => SITE_URL . '/election-officer/candidates/'],
    ['title' => 'Manage Candidates']
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

// Get candidate ID if editing
$editing_candidate = null;
$candidate_id = intval($_GET['candidate_id'] ?? 0);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $db = Database::getInstance()->getConnection();
        
        if ($action === 'add_candidate') {
            $election_id = intval($_POST['election_id'] ?? 0);
            $position_id = intval($_POST['position_id'] ?? 0);
            $student_number = sanitize($_POST['student_number'] ?? '');
            
            if (!$election_id || !$position_id || empty($student_number)) {
                throw new Exception('Election, position, and student number are required');
            }
            
            // Handle image upload
            $photo_url = '';
            if (isset($_FILES['candidate_image']) && $_FILES['candidate_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = __DIR__ . '/../../assets/uploads/candidates/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file = $_FILES['candidate_image'];
                $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                $max_size = 2 * 1024 * 1024; // 2MB
                
                if (!in_array($file['type'], $allowed_types)) {
                    throw new Exception('Only JPEG, PNG, and GIF images are allowed');
                }
                
                if ($file['size'] > $max_size) {
                    throw new Exception('Image size must be less than 2MB');
                }
                
                $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $new_filename = 'candidate_' . time() . '_' . rand(1000, 9999) . '.' . $file_extension;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    $photo_url = SITE_URL . 'assets/uploads/candidates/' . $new_filename;
                } else {
                    throw new Exception('Failed to upload image');
                }
            }
            
            // Check if student exists
            $stmt = $db->prepare("SELECT student_id, first_name, last_name FROM students WHERE student_number = ?");
            $stmt->execute([$student_number]);
            $student = $stmt->fetch();
            
            if (!$student) {
                throw new Exception('Student not found with number: ' . $student_number);
            }
            
            // Check if student is already a candidate for this position
            $stmt = $db->prepare("SELECT candidate_id FROM candidates WHERE position_id = ? AND student_id = ?");
            $stmt->execute([$position_id, $student['student_id']]);
            if ($stmt->fetch()) {
                throw new Exception('Student is already a candidate for this position');
            }
            
            // Check max candidates limit
            $stmt = $db->prepare("
                SELECT p.max_candidates, COUNT(c.candidate_id) as current_count, p.title
                FROM positions p
                LEFT JOIN candidates c ON p.position_id = c.position_id
                WHERE p.position_id = ?
                GROUP BY p.position_id, p.max_candidates, p.title
            ");
            $stmt->execute([$position_id]);
            $position_info = $stmt->fetch();
            
            if ($position_info && $position_info['current_count'] >= $position_info['max_candidates']) {
                throw new Exception('Maximum candidates reached for ' . $position_info['title'] . ' position');
            }
            
            // Add candidate
            $stmt = $db->prepare("
                INSERT INTO candidates (student_id, position_id, election_id, photo_url, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$student['student_id'], $position_id, $election_id, $photo_url, $current_user['user_id']]);
            
            $candidate_name = $student['first_name'] . ' ' . $student['last_name'];
            logActivity('candidate_add', "Added candidate: {$candidate_name} for position ID {$position_id}");
            $_SESSION['candidates_success'] = 'Candidate added successfully';
            redirectTo('election-officer/candidates/');
            
        } elseif ($action === 'edit_candidate') {
            $candidate_id = intval($_POST['candidate_id'] ?? 0);
            $photo_url = sanitize($_POST['photo_url'] ?? '');
            
            if (!$candidate_id) {
                throw new Exception('Invalid candidate ID');
            }
            
            // Handle image upload for editing
            if (isset($_FILES['candidate_image']) && $_FILES['candidate_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = __DIR__ . '/../../assets/uploads/candidates/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file = $_FILES['candidate_image'];
                $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                $max_size = 2 * 1024 * 1024; // 2MB
                
                if (!in_array($file['type'], $allowed_types)) {
                    throw new Exception('Only JPEG, PNG, and GIF images are allowed');
                }
                
                if ($file['size'] > $max_size) {
                    throw new Exception('Image size must be less than 2MB');
                }
                
                $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $new_filename = 'candidate_' . time() . '_' . rand(1000, 9999) . '.' . $file_extension;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    $photo_url = SITE_URL . 'assets/uploads/candidates/' . $new_filename;
                } else {
                    throw new Exception('Failed to upload image');
                }
            }
            
            // Get candidate info
            $stmt = $db->prepare("
                SELECT s.first_name, s.last_name, p.title, e.name as election_name
                FROM candidates c
                JOIN students s ON c.student_id = s.student_id
                JOIN positions p ON c.position_id = p.position_id
                JOIN elections e ON c.election_id = e.election_id
                WHERE c.candidate_id = ?
            ");
            $stmt->execute([$candidate_id]);
            $candidate = $stmt->fetch();
            
            if (!$candidate) {
                throw new Exception('Candidate not found');
            }
            
            // Update candidate
            $stmt = $db->prepare("UPDATE candidates SET photo_url = ?, updated_at = NOW() WHERE candidate_id = ?");
            $stmt->execute([$photo_url, $candidate_id]);
            
            $candidate_name = $candidate['first_name'] . ' ' . $candidate['last_name'];
            logActivity('candidate_edit', "Updated candidate: {$candidate_name}");
            $_SESSION['candidates_success'] = 'Candidate updated successfully';
            redirectTo('election-officer/candidates/');
            
        } elseif ($action === 'remove_candidate') {
            $candidate_id = intval($_POST['candidate_id'] ?? 0);
            
            if (!$candidate_id) {
                throw new Exception('Invalid candidate ID');
            }
            
            // Get candidate info
            $stmt = $db->prepare("
                SELECT s.first_name, s.last_name, p.title, e.name as election_name
                FROM candidates c
                JOIN students s ON c.student_id = s.student_id
                JOIN positions p ON c.position_id = p.position_id
                JOIN elections e ON c.election_id = e.election_id
                WHERE c.candidate_id = ?
            ");
            $stmt->execute([$candidate_id]);
            $candidate = $stmt->fetch();
            
            if (!$candidate) {
                throw new Exception('Candidate not found');
            }
            
            // Check if candidate has received votes
            $stmt = $db->prepare("SELECT COUNT(*) as vote_count FROM votes WHERE candidate_id = ?");
            $stmt->execute([$candidate_id]);
            $votes = $stmt->fetch();
            
            if ($votes['vote_count'] > 0) {
                throw new Exception('Cannot remove candidate who has received votes');
            }
            
            // Remove candidate
            $stmt = $db->prepare("DELETE FROM candidates WHERE candidate_id = ?");
            $stmt->execute([$candidate_id]);
            
            $candidate_name = $candidate['first_name'] . ' ' . $candidate['last_name'];
            logActivity('candidate_remove', "Removed candidate: {$candidate_name}");
            $_SESSION['candidates_success'] = 'Candidate removed successfully';
            redirectTo('election-officer/candidates/');
        }
        
    } catch (Exception $e) {
        $_SESSION['candidates_error'] = $e->getMessage();
        error_log("Candidates management error: " . $e->getMessage());
        redirectTo('election-officer/candidates/manage.php');
    }
}

// Get data for the page
try {
    $db = Database::getInstance()->getConnection();
    
    // Get all elections for dropdown
    $stmt = $db->prepare("SELECT election_id, name, status FROM elections WHERE status != 'completed' ORDER BY created_at DESC");
    $stmt->execute();
    $elections = $stmt->fetchAll();
    
    // Get positions grouped by election for add candidate form
    $stmt = $db->prepare("
        SELECT e.election_id, e.name as election_name, e.status,
               p.position_id, p.title as position_title, p.max_candidates,
               COUNT(c.candidate_id) as current_candidates
        FROM elections e
        LEFT JOIN positions p ON e.election_id = p.election_id
        LEFT JOIN candidates c ON p.position_id = c.position_id
        WHERE e.status != 'completed'
        GROUP BY e.election_id, e.name, e.status, e.created_at, p.position_id, p.title, p.max_candidates
        ORDER BY e.created_at DESC, p.title ASC
    ");
    $stmt->execute();
    $election_positions = $stmt->fetchAll();
    
    // If editing, get candidate details
    if ($candidate_id) {
        $stmt = $db->prepare("
            SELECT c.candidate_id, c.student_id, c.position_id, c.election_id, c.photo_url,
                   s.student_number, s.first_name, s.last_name, s.gender,
                   p.title as position_title,
                   e.name as election_name
            FROM candidates c
            JOIN students s ON c.student_id = s.student_id
            JOIN positions p ON c.position_id = p.position_id
            JOIN elections e ON c.election_id = e.election_id
            WHERE c.candidate_id = ?
        ");
        $stmt->execute([$candidate_id]);
        $editing_candidate = $stmt->fetch();
        
    }
    
} catch (Exception $e) {
    error_log("Candidates manage data fetch error: " . $e->getMessage());
    $_SESSION['candidates_error'] = "Unable to load management data";
    redirectTo('election-officer/candidates/');
}

include __DIR__ . '/../../includes/header.php';
?>

<!-- Manage Candidates Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="fas fa-user-cog me-2"></i><?= $editing_candidate ? 'Edit Candidate' : 'Manage Candidates' ?>
        </h4>
        <small class="text-muted"><?= $editing_candidate ? 'Update candidate information' : 'Add, edit, or remove election candidates' ?></small>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= SITE_URL ?>/election-officer/candidates/" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Overview
        </a>
        <?php if ($editing_candidate): ?>
            <a href="<?= SITE_URL ?>/election-officer/candidates/manage.php" class="btn btn-success">
                <i class="fas fa-plus me-2"></i>Add New
            </a>
        <?php endif; ?>
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

<div class="row">
    <!-- Main Form -->
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-<?= $editing_candidate ? 'edit' : 'user-plus' ?> me-2"></i>
                    <?= $editing_candidate ? 'Edit Candidate' : 'Add New Candidate' ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="<?= $editing_candidate ? 'edit_candidate' : 'add_candidate' ?>">
                    <?php if ($editing_candidate): ?>
                        <input type="hidden" name="candidate_id" value="<?= $editing_candidate['candidate_id'] ?>">
                    <?php endif; ?>
                    
                    <?php if ($editing_candidate): ?>
                        <!-- Editing Mode - Show Current Info -->
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <h6 class="fw-bold">Current Candidate Information</h6>
                                <div class="bg-light p-3 rounded">
                                    <div class="d-flex align-items-center mb-2">
                                        <div class="candidate-avatar me-3">
                                            <?php if (!empty($editing_candidate['photo_url'])): ?>
                                                <img src="<?= sanitize($editing_candidate['photo_url']) ?>" alt="<?= sanitize($editing_candidate['first_name']) ?>" class="candidate-photo">
                                            <?php else: ?>
                                                <i class="fas fa-<?= $editing_candidate['gender'] === 'Male' ? 'male' : 'female' ?>"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <strong><?= sanitize($editing_candidate['first_name']) ?> <?= sanitize($editing_candidate['last_name']) ?></strong>
                                            <br><small class="text-muted">ID: <?= sanitize($editing_candidate['student_number']) ?></small>
                                        </div>
                                    </div>
                                    <p class="mb-1"><strong>Position:</strong> <?= sanitize($editing_candidate['position_title']) ?></p>
                                    <p class="mb-0"><strong>Election:</strong> <?= sanitize($editing_candidate['election_name']) ?></p>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Adding Mode - Selection Form -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="election_id" class="form-label">Select Election <span class="text-danger">*</span></label>
                                <select class="form-select" id="election_id" name="election_id" required onchange="loadPositions(this.value)">
                                    <option value="">Choose an election...</option>
                                    <?php foreach ($elections as $election): ?>
                                        <option value="<?= $election['election_id'] ?>"><?= sanitize($election['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="position_id" class="form-label">Select Position <span class="text-danger">*</span></label>
                                <select class="form-select" id="position_id" name="position_id" required disabled>
                                    <option value="">First select an election...</option>
                                </select>
                                <div class="form-text" id="position_info"></div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="student_number" class="form-label">Student Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="student_number" name="student_number" required placeholder="e.g., SRC001">
                            <div class="form-text">Enter the student's unique number/ID</div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Photo Management Section (for both modes) -->
                    <div class="mb-3">
                        <label class="form-label">Candidate Photo</label>
                        
                        <?php if ($editing_candidate): ?>
                            <div class="mb-2">
                                <label for="photo_url" class="form-label">Photo URL (Optional)</label>
                                <input type="url" class="form-control" id="photo_url" name="photo_url" 
                                       value="<?= sanitize($editing_candidate['photo_url']) ?>" 
                                       placeholder="https://example.com/photo.jpg">
                                <div class="form-text">Enter a URL to the candidate's photo, or upload a new one below</div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="mb-2">
                            <label for="candidate_image" class="form-label"><?= $editing_candidate ? 'Upload New Photo' : 'Upload Photo (Optional)' ?></label>
                            <input type="file" class="form-control" id="candidate_image" name="candidate_image" accept="image/*">
                            <div class="form-text">Max size: 2MB. Formats: JPEG, PNG, GIF</div>
                        </div>
                        
                        <div class="mt-2">
                            <?php if ($editing_candidate && !empty($editing_candidate['photo_url'])): ?>
                                <div class="mb-2">
                                    <small class="text-muted">Current Photo:</small><br>
                                    <img id="current_photo" src="<?= sanitize($editing_candidate['photo_url']) ?>" 
                                         alt="Current Photo" class="img-thumbnail" style="max-width: 150px; max-height: 150px;">
                                </div>
                            <?php endif; ?>
                            <img id="image_preview" src="#" alt="Preview" class="img-thumbnail" 
                                 style="display: none; max-width: 150px; max-height: 150px;">
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2 justify-content-end">
                        <a href="<?= SITE_URL ?>/election-officer/candidates/" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-<?= $editing_candidate ? 'save' : 'plus' ?> me-2"></i>
                            <?= $editing_candidate ? 'Update Candidate' : 'Add Candidate' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Quick Actions -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-bolt me-2"></i>Quick Actions
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="<?= SITE_URL ?>/election-officer/candidates/" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-list me-2"></i>View All Candidates
                    </a>
                    <a href="<?= SITE_URL ?>/election-officer/students/verify.php" class="btn btn-outline-success btn-sm">
                        <i class="fas fa-user-check me-2"></i>Verify Students
                    </a>
                    <a href="<?= SITE_URL ?>/election-officer/elections/" class="btn btn-outline-warning btn-sm">
                        <i class="fas fa-poll me-2"></i>Manage Elections
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Guidelines -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-info-circle me-2"></i>Guidelines
                </h5>
            </div>
            <div class="card-body">
                <div class="small">
                    <div class="mb-3">
                        <h6 class="fw-bold">Adding Candidates:</h6>
                        <ul class="mb-0">
                            <li>Student must be verified in the system</li>
                            <li>Cannot exceed position candidate limits</li>
                            <li>One student per position only</li>
                            <li>Photo upload is optional but recommended</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <h6 class="fw-bold">Photo Requirements:</h6>
                        <ul class="mb-0">
                            <li>Maximum file size: 2MB</li>
                            <li>Supported formats: JPEG, PNG, GIF</li>
                            <li>Recommended size: 300x300 pixels</li>
                            <li>Use high-quality, professional photos</li>
                        </ul>
                    </div>
                    
                    <div>
                        <h6 class="fw-bold">Important Notes:</h6>
                        <ul class="mb-0">
                            <li>Cannot remove candidates with votes</li>
                            <li>Changes are logged for audit trail</li>
                            <li>Active elections may have restrictions</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Remove Candidate Modal (for individual candidate management) -->
<?php if ($editing_candidate): ?>
<div class="card mt-4">
    <div class="card-header bg-danger text-white">
        <h5 class="card-title mb-0">
            <i class="fas fa-exclamation-triangle me-2"></i>Danger Zone
        </h5>
    </div>
    <div class="card-body">
        <p class="text-muted">Remove this candidate from the election. This action cannot be undone.</p>
        <button type="button" class="btn btn-danger" onclick="removeCandidate(<?= $editing_candidate['candidate_id'] ?>, '<?= addslashes($editing_candidate['first_name'] . ' ' . $editing_candidate['last_name']) ?>')">
            <i class="fas fa-trash me-2"></i>Remove Candidate
        </button>
    </div>
</div>

<!-- Remove Confirmation Modal -->
<div class="modal fade" id="removeCandidateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>Remove Candidate
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to remove "<strong id="removeCandidateName"></strong>" as a candidate?</p>
                <p class="text-warning"><small><i class="fas fa-info-circle me-1"></i>This action cannot be undone.</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="removeCandidateForm">
                    <input type="hidden" name="action" value="remove_candidate">
                    <input type="hidden" name="candidate_id" id="remove_candidate_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Remove Candidate</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
/* Manage Candidates Styles */

.candidate-avatar {
    width: 60px;
    height: 60px;
    background: #f8f9fa;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: #6c757d;
    overflow: hidden;
}

.candidate-photo {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}

.form-label span.text-danger {
    font-size: 0.875rem;
}

.card-header.bg-danger {
    border-bottom: 1px solid rgba(255,255,255,0.2);
}
</style>

<script>
// Manage Candidates JavaScript
document.addEventListener('DOMContentLoaded', function() {
    
    // Position data for dynamic loading
    const positionData = <?= json_encode($election_positions) ?>;
    
    // Load positions based on selected election
    window.loadPositions = function(electionId) {
        const positionSelect = document.getElementById('position_id');
        const positionInfo = document.getElementById('position_info');
        
        if (!positionSelect) return; // Not in add mode
        
        positionSelect.innerHTML = '<option value="">Choose a position...</option>';
        positionInfo.textContent = '';
        
        if (!electionId) {
            positionSelect.disabled = true;
            positionSelect.innerHTML = '<option value="">First select an election...</option>';
            return;
        }
        
        const positions = positionData.filter(p => p.election_id == electionId && p.position_id);
        
        if (positions.length === 0) {
            positionSelect.innerHTML = '<option value="">No positions available</option>';
            positionSelect.disabled = true;
            return;
        }
        
        positionSelect.disabled = false;
        positions.forEach(position => {
            const option = document.createElement('option');
            option.value = position.position_id;
            option.textContent = `${position.position_title} (${position.current_candidates}/${position.max_candidates})`;
            if (position.current_candidates >= position.max_candidates) {
                option.disabled = true;
                option.textContent += ' - FULL';
            }
            positionSelect.appendChild(option);
        });
    };
    
    // Update position info when position is selected
    const positionSelect = document.getElementById('position_id');
    if (positionSelect) {
        positionSelect.addEventListener('change', function() {
            const positionInfo = document.getElementById('position_info');
            const selectedPosition = positionData.find(p => p.position_id == this.value);
            
            if (selectedPosition) {
                const remaining = selectedPosition.max_candidates - selectedPosition.current_candidates;
                if (remaining > 0) {
                    positionInfo.textContent = `${remaining} candidate spots remaining`;
                    positionInfo.className = 'form-text text-success';
                } else {
                    positionInfo.textContent = 'Position is full';
                    positionInfo.className = 'form-text text-danger';
                }
            } else {
                positionInfo.textContent = '';
            }
        });
    }
    
    // Image preview functionality
    const input = document.getElementById('candidate_image');
    const preview = document.getElementById('image_preview');
    
    if (input && preview) {
        input.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            } else {
                preview.style.display = 'none';
            }
        });
    }
    
    // Remove candidate function
    window.removeCandidate = function(candidateId, candidateName) {
        document.getElementById('remove_candidate_id').value = candidateId;
        document.getElementById('removeCandidateName').textContent = candidateName;
        
        const removeModal = new bootstrap.Modal(document.getElementById('removeCandidateModal'));
        removeModal.show();
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>