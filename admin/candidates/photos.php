<?php
require_once '../../config/config.php';
require_once '../../auth/session.php';
require_once '../../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

$page_title = 'Manage Candidate Photos';
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index'],
    ['title' => 'Candidates', 'url' => './index'],
    ['title' => 'Manage Photos']
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

// Get candidate ID if managing specific candidate
$candidate_id = intval($_GET['candidate_id'] ?? 0);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $db = Database::getInstance()->getConnection();
        
        if ($action === 'upload_photo') {
            $candidate_id = intval($_POST['candidate_id'] ?? 0);
            
            if (!$candidate_id) {
                throw new Exception('Invalid candidate ID');
            }
            
            if (!isset($_FILES['candidate_photo']) || $_FILES['candidate_photo']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Please select a photo to upload');
            }
            
            $upload_dir = '../../assets/uploads/candidates/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file = $_FILES['candidate_photo'];
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
                $photo_url = '/online_voting/assets/uploads/candidates/' . $new_filename;
                
                // Update candidate photo
                $stmt = $db->prepare("UPDATE candidates SET photo_url = ?, updated_at = NOW() WHERE candidate_id = ?");
                $stmt->execute([$photo_url, $candidate_id]);
                
                // Get candidate name for logging
                $stmt = $db->prepare("
                    SELECT s.first_name, s.last_name
                    FROM candidates c
                    JOIN students s ON c.student_id = s.student_id
                    WHERE c.candidate_id = ?
                ");
                $stmt->execute([$candidate_id]);
                $candidate = $stmt->fetch();
                
                if ($candidate) {
                    $candidate_name = $candidate['first_name'] . ' ' . $candidate['last_name'];
                    logActivity('candidate_photo_upload', "Uploaded photo for candidate: {$candidate_name}", $current_user['id']);
                }
                
                $_SESSION['candidates_success'] = 'Photo uploaded successfully';
            } else {
                throw new Exception('Failed to upload photo');
            }
            
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
            
        } elseif ($action === 'update_photo_url') {
            $candidate_id = intval($_POST['candidate_id'] ?? 0);
            $photo_url = sanitize($_POST['photo_url'] ?? '');
            
            if (!$candidate_id) {
                throw new Exception('Invalid candidate ID');
            }
            
            // Validate URL format if provided
            if (!empty($photo_url) && !filter_var($photo_url, FILTER_VALIDATE_URL)) {
                throw new Exception('Invalid photo URL format');
            }
            
            // Update candidate photo URL
            $stmt = $db->prepare("UPDATE candidates SET photo_url = ?, updated_at = NOW() WHERE candidate_id = ?");
            $stmt->execute([$photo_url, $candidate_id]);
            
            // Get candidate name for logging
            $stmt = $db->prepare("
                SELECT s.first_name, s.last_name
                FROM candidates c
                JOIN students s ON c.student_id = s.student_id
                WHERE c.candidate_id = ?
            ");
            $stmt->execute([$candidate_id]);
            $candidate = $stmt->fetch();
            
            if ($candidate) {
                $candidate_name = $candidate['first_name'] . ' ' . $candidate['last_name'];
                $action_desc = empty($photo_url) ? 'Removed photo URL' : 'Updated photo URL';
                logActivity('candidate_photo_update', "{$action_desc} for candidate: {$candidate_name}", $current_user['id']);
            }
            
            $_SESSION['candidates_success'] = empty($photo_url) ? 'Photo URL removed successfully' : 'Photo URL updated successfully';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
            
        } elseif ($action === 'remove_photo') {
            $candidate_id = intval($_POST['candidate_id'] ?? 0);
            
            if (!$candidate_id) {
                throw new Exception('Invalid candidate ID');
            }
            
            // Get current photo to potentially delete file
            $stmt = $db->prepare("SELECT photo_url FROM candidates WHERE candidate_id = ?");
            $stmt->execute([$candidate_id]);
            $current = $stmt->fetch();
            
            // Remove photo URL from database
            $stmt = $db->prepare("UPDATE candidates SET photo_url = NULL, updated_at = NOW() WHERE candidate_id = ?");
            $stmt->execute([$candidate_id]);
            
            // If it's an uploaded file, try to delete it
            if ($current && !empty($current['photo_url']) && strpos($current['photo_url'], '/online_voting/assets/uploads/') === 0) {
                $file_path = '../../assets/uploads/' . basename(dirname($current['photo_url'])) . '/' . basename($current['photo_url']);
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }
            
            // Get candidate name for logging
            $stmt = $db->prepare("
                SELECT s.first_name, s.last_name
                FROM candidates c
                JOIN students s ON c.student_id = s.student_id
                WHERE c.candidate_id = ?
            ");
            $stmt->execute([$candidate_id]);
            $candidate = $stmt->fetch();
            
            if ($candidate) {
                $candidate_name = $candidate['first_name'] . ' ' . $candidate['last_name'];
                logActivity('candidate_photo_remove', "Removed photo for candidate: {$candidate_name}", $current_user['id']);
            }
            
            $_SESSION['candidates_success'] = 'Photo removed successfully';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        
    } catch (Exception $e) {
        $_SESSION['candidates_error'] = $e->getMessage();
        logError("Candidate photo management error: " . $e->getMessage());
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Get data for the page
try {
    $db = Database::getInstance()->getConnection();
    
    // Get all candidates with photo info
    $where_clause = $candidate_id ? "WHERE c.candidate_id = ?" : "";
    $stmt = $db->prepare("
        SELECT c.candidate_id, c.student_id, c.position_id, c.election_id, c.photo_url, c.updated_at,
               s.student_number, s.first_name, s.last_name, s.gender,
               p.title as position_title,
               e.name as election_name, e.status as election_status
        FROM candidates c
        JOIN students s ON c.student_id = s.student_id
        JOIN positions p ON c.position_id = p.position_id
        JOIN elections e ON c.election_id = e.election_id
        {$where_clause}
        ORDER BY e.created_at DESC, p.display_order ASC, s.first_name ASC
    ");
    
    if ($candidate_id) {
        $stmt->execute([$candidate_id]);
    } else {
        $stmt->execute();
    }
    
    $candidates = $stmt->fetchAll();
    
    // Get photo statistics
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_candidates,
            COUNT(CASE WHEN photo_url IS NOT NULL AND photo_url != '' THEN 1 END) as with_photos,
            COUNT(CASE WHEN photo_url IS NULL OR photo_url = '' THEN 1 END) as without_photos
        FROM candidates
    ");
    $stmt->execute();
    $stats = $stmt->fetch();
    
} catch (Exception $e) {
    logError("Candidate photos data fetch error: " . $e->getMessage());
    $_SESSION['candidates_error'] = "Unable to load photo management data";
    header('Location: index');
    exit;
}

include '../../includes/header.php';
?>

<!-- Photos Management Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="fas fa-images me-2"></i>Manage Candidate Photos
        </h4>
        <small class="text-muted"><?= $candidate_id ? 'Managing photo for specific candidate' : 'Upload and manage photos for all candidates' ?></small>
    </div>
    <div class="d-flex gap-2">
        <a href="index" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Overview
        </a>
        <a href="manage" class="btn btn-success">
            <i class="fas fa-user-plus me-2"></i>Add Candidate
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

<!-- Photo Statistics -->
<?php if (!$candidate_id): ?>
<div class="row mb-4">
    <div class="col-md-4">
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
    <div class="col-md-4">
        <div class="stats-card">
            <div class="stats-icon bg-success">
                <i class="fas fa-image"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['with_photos'] ?></h3>
                <p>With Photos</p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stats-card">
            <div class="stats-icon bg-warning">
                <i class="fas fa-image text-white"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['without_photos'] ?></h3>
                <p>Without Photos</p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Candidates Photo Management -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="fas fa-camera me-2"></i>Candidate Photos
        </h5>
    </div>
    <div class="card-body">
        <?php if (empty($candidates)): ?>
            <div class="text-center py-5">
                <i class="fas fa-users text-muted" style="font-size: 4rem;"></i>
                <h4 class="text-muted mt-3">No Candidates Found</h4>
                <p class="text-muted">Add candidates first to manage their photos.</p>
                <a href="manage" class="btn btn-primary">
                    <i class="fas fa-user-plus me-2"></i>Add Candidates
                </a>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($candidates as $candidate): ?>
                    <div class="col-lg-6 col-xl-4 mb-4">
                        <div class="card h-100">
                            <div class="card-body text-center">
                                <!-- Candidate Photo Display -->
                                <div class="candidate-photo-large mx-auto mb-3">
                                    <?php if (!empty($candidate['photo_url'])): ?>
                                        <img src="<?= sanitize($candidate['photo_url']) ?>" 
                                             alt="<?= sanitize($candidate['first_name']) ?>" 
                                             class="candidate-photo-img"
                                             onclick="viewPhoto('<?= sanitize($candidate['photo_url']) ?>', '<?= sanitize($candidate['first_name'] . ' ' . $candidate['last_name']) ?>')">
                                    <?php else: ?>
                                        <div class="no-photo">
                                            <i class="fas fa-<?= $candidate['gender'] === 'Male' ? 'male' : 'female' ?>"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Candidate Info -->
                                <h6 class="fw-bold mb-1"><?= sanitize($candidate['first_name']) ?> <?= sanitize($candidate['last_name']) ?></h6>
                                <small class="text-muted d-block mb-1">ID: <?= sanitize($candidate['student_number']) ?></small>
                                <small class="text-muted d-block mb-2"><?= sanitize($candidate['position_title']) ?></small>
                                <span class="badge bg-<?= $candidate['election_status'] === 'active' ? 'success' : 'secondary' ?> mb-3">
                                    <?= sanitize($candidate['election_name']) ?>
                                </span>
                                
                                <!-- Photo Status -->
                                <div class="mb-3">
                                    <?php if (!empty($candidate['photo_url'])): ?>
                                        <span class="badge bg-success">
                                            <i class="fas fa-check me-1"></i>Has Photo
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">
                                            <i class="fas fa-exclamation me-1"></i>No Photo
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Action Buttons -->
                                <div class="d-flex gap-1 justify-content-center">
                                    <button type="button" class="btn btn-primary btn-sm" 
                                            onclick="uploadPhoto(<?= $candidate['candidate_id'] ?>, '<?= addslashes($candidate['first_name'] . ' ' . $candidate['last_name']) ?>')">
                                        <i class="fas fa-upload"></i>
                                    </button>
                                    <button type="button" class="btn btn-info btn-sm" 
                                            onclick="editPhotoUrl(<?= $candidate['candidate_id'] ?>, '<?= addslashes($candidate['first_name'] . ' ' . $candidate['last_name']) ?>', '<?= addslashes($candidate['photo_url'] ?? '') ?>')">
                                        <i class="fas fa-link"></i>
                                    </button>
                                    <?php if (!empty($candidate['photo_url'])): ?>
                                        <button type="button" class="btn btn-danger btn-sm" 
                                                onclick="removePhoto(<?= $candidate['candidate_id'] ?>, '<?= addslashes($candidate['first_name'] . ' ' . $candidate['last_name']) ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Upload Photo Modal -->
<div class="modal fade" id="uploadPhotoModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_photo">
                <input type="hidden" name="candidate_id" id="upload_candidate_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-upload me-2"></i>Upload Photo
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Upload photo for <strong id="upload_candidate_name"></strong></p>
                    
                    <div class="mb-3">
                        <label for="candidate_photo" class="form-label">Select Photo</label>
                        <input type="file" class="form-control" id="candidate_photo" name="candidate_photo" accept="image/*" required>
                        <div class="form-text">Max size: 2MB. Formats: JPEG, PNG, GIF</div>
                    </div>
                    
                    <div class="mb-3">
                        <img id="upload_preview" src="#" alt="Preview" class="img-thumbnail" 
                             style="display: none; max-width: 200px; max-height: 200px;">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Upload Photo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Photo URL Modal -->
<div class="modal fade" id="editPhotoUrlModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_photo_url">
                <input type="hidden" name="candidate_id" id="url_candidate_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-link me-2"></i>Edit Photo URL
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Edit photo URL for <strong id="url_candidate_name"></strong></p>
                    
                    <div class="mb-3">
                        <label for="photo_url" class="form-label">Photo URL</label>
                        <input type="url" class="form-control" id="photo_url" name="photo_url" placeholder="https://example.com/photo.jpg">
                        <div class="form-text">Enter a valid URL to an image, or leave blank to remove</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update URL</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Remove Photo Modal -->
<div class="modal fade" id="removePhotoModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>Remove Photo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to remove the photo for "<strong id="remove_candidate_name"></strong>"?</p>
                <p class="text-warning"><small><i class="fas fa-info-circle me-1"></i>This action cannot be undone.</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="removePhotoForm">
                    <input type="hidden" name="action" value="remove_photo">
                    <input type="hidden" name="candidate_id" id="remove_candidate_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Remove Photo</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- View Photo Modal -->
<div class="modal fade" id="viewPhotoModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="view_photo_title">
                    <i class="fas fa-eye me-2"></i>View Photo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="view_photo_img" src="#" alt="Candidate Photo" class="img-fluid">
            </div>
        </div>
    </div>
</div>

<style>
/* Photo Management Styles */

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

.candidate-photo-large {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 3px solid #e9ecef;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8f9fa;
}

.candidate-photo-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    cursor: pointer;
    transition: opacity 0.2s;
}

.candidate-photo-img:hover {
    opacity: 0.8;
}

.no-photo {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    color: #6c757d;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .stats-card {
        flex-direction: column;
        text-align: center;
    }
    
    .candidate-photo-large {
        width: 100px;
        height: 100px;
    }
    
    .no-photo {
        font-size: 2.5rem;
    }
}
</style>

<script>
// Photo Management JavaScript
document.addEventListener('DOMContentLoaded', function() {
    
    // Upload photo function
    window.uploadPhoto = function(candidateId, candidateName) {
        document.getElementById('upload_candidate_id').value = candidateId;
        document.getElementById('upload_candidate_name').textContent = candidateName;
        
        // Reset form
        document.getElementById('candidate_photo').value = '';
        document.getElementById('upload_preview').style.display = 'none';
        
        const uploadModal = new bootstrap.Modal(document.getElementById('uploadPhotoModal'));
        uploadModal.show();
    };
    
    // Edit photo URL function
    window.editPhotoUrl = function(candidateId, candidateName, currentUrl) {
        document.getElementById('url_candidate_id').value = candidateId;
        document.getElementById('url_candidate_name').textContent = candidateName;
        document.getElementById('photo_url').value = currentUrl || '';
        
        const urlModal = new bootstrap.Modal(document.getElementById('editPhotoUrlModal'));
        urlModal.show();
    };
    
    // Remove photo function
    window.removePhoto = function(candidateId, candidateName) {
        document.getElementById('remove_candidate_id').value = candidateId;
        document.getElementById('remove_candidate_name').textContent = candidateName;
        
        const removeModal = new bootstrap.Modal(document.getElementById('removePhotoModal'));
        removeModal.show();
    };
    
    // View photo function
    window.viewPhoto = function(photoUrl, candidateName) {
        document.getElementById('view_photo_title').innerHTML = '<i class="fas fa-eye me-2"></i>' + candidateName;
        document.getElementById('view_photo_img').src = photoUrl;
        
        const viewModal = new bootstrap.Modal(document.getElementById('viewPhotoModal'));
        viewModal.show();
    };
    
    // Image preview for upload
    const uploadInput = document.getElementById('candidate_photo');
    const uploadPreview = document.getElementById('upload_preview');
    
    if (uploadInput && uploadPreview) {
        uploadInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    uploadPreview.src = e.target.result;
                    uploadPreview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            } else {
                uploadPreview.style.display = 'none';
            }
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