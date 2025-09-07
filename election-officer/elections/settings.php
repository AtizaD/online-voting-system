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

$page_title = 'Election Settings - ' . $election['title'];
$breadcrumbs = [
    ['title' => 'Election Officer', 'url' => SITE_URL . 'election-officer/'],
    ['title' => 'Elections', 'url' => SITE_URL . 'election-officer/elections/'],
    ['title' => 'Settings']
];

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_settings') {
        // Basic settings
        $voting_method = sanitize($_POST['voting_method'] ?? 'online');
        $voter_eligibility = sanitize($_POST['voter_eligibility'] ?? 'all');
        $results_visibility = sanitize($_POST['results_visibility'] ?? 'public');
        $allow_multiple_votes = isset($_POST['allow_multiple_votes']) ? 1 : 0;
        $require_verification = isset($_POST['require_verification']) ? 1 : 0;
        $enable_vote_receipt = isset($_POST['enable_vote_receipt']) ? 1 : 0;
        
        // Advanced settings
        $vote_verification_method = sanitize($_POST['vote_verification_method'] ?? 'none');
        $max_vote_attempts = intval($_POST['max_vote_attempts'] ?? 3);
        $voting_session_timeout = intval($_POST['voting_session_timeout'] ?? 300);
        $enable_candidate_photos = isset($_POST['enable_candidate_photos']) ? 1 : 0;
        $enable_candidate_statements = isset($_POST['enable_candidate_statements']) ? 1 : 0;
        $randomize_candidate_order = isset($_POST['randomize_candidate_order']) ? 1 : 0;
        
        // Notification settings
        $send_start_notifications = isset($_POST['send_start_notifications']) ? 1 : 0;
        $send_end_notifications = isset($_POST['send_end_notifications']) ? 1 : 0;
        $send_result_notifications = isset($_POST['send_result_notifications']) ? 1 : 0;
        
        // Security settings
        $ip_restriction_enabled = isset($_POST['ip_restriction_enabled']) ? 1 : 0;
        $allowed_ip_ranges = sanitize($_POST['allowed_ip_ranges'] ?? '');
        $device_restriction_enabled = isset($_POST['device_restriction_enabled']) ? 1 : 0;
        $max_devices_per_voter = intval($_POST['max_devices_per_voter'] ?? 1);
        
        try {
            $db->beginTransaction();
            
            // Update main election settings
            $stmt = $db->prepare("
                UPDATE elections 
                SET voting_method = ?, voter_eligibility = ?, results_visibility = ?,
                    allow_multiple_votes = ?, require_verification = ?, updated_at = NOW()
                WHERE election_id = ?
            ");
            $stmt->execute([
                $voting_method, $voter_eligibility, $results_visibility,
                $allow_multiple_votes, $require_verification, $election_id
            ]);
            
            // Check if election settings record exists
            $stmt = $db->prepare("SELECT election_id FROM election_settings WHERE election_id = ?");
            $stmt->execute([$election_id]);
            $settings_exist = $stmt->fetch();
            
            if ($settings_exist) {
                // Update existing settings
                $stmt = $db->prepare("
                    UPDATE election_settings 
                    SET enable_vote_receipt = ?, vote_verification_method = ?, max_vote_attempts = ?,
                        voting_session_timeout = ?, enable_candidate_photos = ?, enable_candidate_statements = ?,
                        randomize_candidate_order = ?, send_start_notifications = ?, send_end_notifications = ?,
                        send_result_notifications = ?, ip_restriction_enabled = ?, allowed_ip_ranges = ?,
                        device_restriction_enabled = ?, max_devices_per_voter = ?, updated_at = NOW()
                    WHERE election_id = ?
                ");
                $stmt->execute([
                    $enable_vote_receipt, $vote_verification_method, $max_vote_attempts,
                    $voting_session_timeout, $enable_candidate_photos, $enable_candidate_statements,
                    $randomize_candidate_order, $send_start_notifications, $send_end_notifications,
                    $send_result_notifications, $ip_restriction_enabled, $allowed_ip_ranges,
                    $device_restriction_enabled, $max_devices_per_voter, $election_id
                ]);
            } else {
                // Insert new settings
                $stmt = $db->prepare("
                    INSERT INTO election_settings (
                        election_id, enable_vote_receipt, vote_verification_method, max_vote_attempts,
                        voting_session_timeout, enable_candidate_photos, enable_candidate_statements,
                        randomize_candidate_order, send_start_notifications, send_end_notifications,
                        send_result_notifications, ip_restriction_enabled, allowed_ip_ranges,
                        device_restriction_enabled, max_devices_per_voter, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $election_id, $enable_vote_receipt, $vote_verification_method, $max_vote_attempts,
                    $voting_session_timeout, $enable_candidate_photos, $enable_candidate_statements,
                    $randomize_candidate_order, $send_start_notifications, $send_end_notifications,
                    $send_result_notifications, $ip_restriction_enabled, $allowed_ip_ranges,
                    $device_restriction_enabled, $max_devices_per_voter
                ]);
            }
            
            $db->commit();
            
            logActivity('election_settings_update', "Settings updated for election: {$election['title']}");
            $message = 'Election settings updated successfully!';
            
            // Refresh election data
            $stmt = $db->prepare("SELECT * FROM elections WHERE election_id = ? AND is_active = 1");
            $stmt->execute([$election_id]);
            $election = $stmt->fetch();
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Failed to update settings. Please try again.';
            error_log("Election settings update error: " . $e->getMessage());
        }
    }
}

// Get election settings
$stmt = $db->prepare("SELECT * FROM election_settings WHERE election_id = ?");
$stmt->execute([$election_id]);
$settings = $stmt->fetch();

// Default settings if none exist
if (!$settings) {
    $settings = [
        'enable_vote_receipt' => 1,
        'vote_verification_method' => 'none',
        'max_vote_attempts' => 3,
        'voting_session_timeout' => 300,
        'enable_candidate_photos' => 1,
        'enable_candidate_statements' => 1,
        'randomize_candidate_order' => 0,
        'send_start_notifications' => 1,
        'send_end_notifications' => 1,
        'send_result_notifications' => 1,
        'ip_restriction_enabled' => 0,
        'allowed_ip_ranges' => '',
        'device_restriction_enabled' => 0,
        'max_devices_per_voter' => 1
    ];
}

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

.settings-section {
    background: white;
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
    overflow: hidden;
}

.settings-header {
    background: #f8fafc;
    padding: 1.5rem;
    border-bottom: 1px solid #e2e8f0;
}

.settings-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.settings-title i {
    color: var(--primary-color);
}

.settings-body {
    padding: 1.5rem;
}

.form-group-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
}

.setting-item {
    padding: 1rem;
    background: #f8fafc;
    border-radius: 0.375rem;
    border-left: 3px solid var(--primary-color);
}

.setting-label {
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 0.5rem;
}

.setting-description {
    font-size: 0.875rem;
    color: #64748b;
    margin-bottom: 1rem;
}

.form-check-custom {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 0.375rem;
    padding: 1rem;
    margin-bottom: 1rem;
}

.form-check-custom:hover {
    background: #f8fafc;
}

.advanced-settings {
    border: 1px solid #e2e8f0;
    border-radius: 0.375rem;
    padding: 1rem;
    background: #fefefe;
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
    <h2><?= sanitize($election['title']) ?></h2>
    <div class="d-flex gap-3 mt-2">
        <span><i class="fas fa-calendar me-1"></i><?= date('M j, Y', strtotime($election['start_date'])) ?> - <?= date('M j, Y', strtotime($election['end_date'])) ?></span>
        <span><i class="fas fa-flag me-1"></i>Status: <?= ucfirst($election['status']) ?></span>
    </div>
</div>

<form method="POST">
    <input type="hidden" name="action" value="update_settings">
    
    <!-- Basic Settings -->
    <div class="settings-section">
        <div class="settings-header">
            <h3 class="settings-title">
                <i class="fas fa-cog"></i>
                Basic Voting Settings
            </h3>
        </div>
        <div class="settings-body">
            <div class="form-group-grid">
                <div class="mb-3">
                    <label for="voting_method" class="form-label">Voting Method</label>
                    <select class="form-select" id="voting_method" name="voting_method">
                        <option value="online" <?= ($election['voting_method'] ?? 'online') === 'online' ? 'selected' : '' ?>>Online Only</option>
                        <option value="offline" <?= ($election['voting_method'] ?? '') === 'offline' ? 'selected' : '' ?>>Offline Only</option>
                        <option value="hybrid" <?= ($election['voting_method'] ?? '') === 'hybrid' ? 'selected' : '' ?>>Online & Offline</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label for="voter_eligibility" class="form-label">Voter Eligibility</label>
                    <select class="form-select" id="voter_eligibility" name="voter_eligibility">
                        <option value="all" <?= ($election['voter_eligibility'] ?? 'all') === 'all' ? 'selected' : '' ?>>All Students</option>
                        <option value="verified" <?= ($election['voter_eligibility'] ?? '') === 'verified' ? 'selected' : '' ?>>Verified Students Only</option>
                        <option value="custom" <?= ($election['voter_eligibility'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom Criteria</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label for="results_visibility" class="form-label">Results Visibility</label>
                    <select class="form-select" id="results_visibility" name="results_visibility">
                        <option value="public" <?= ($election['results_visibility'] ?? 'public') === 'public' ? 'selected' : '' ?>>Public</option>
                        <option value="private" <?= ($election['results_visibility'] ?? '') === 'private' ? 'selected' : '' ?>>Private</option>
                        <option value="delayed" <?= ($election['results_visibility'] ?? '') === 'delayed' ? 'selected' : '' ?>>Delayed Release</option>
                    </select>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="form-check-custom">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="allow_multiple_votes" name="allow_multiple_votes" 
                                   <?= ($election['allow_multiple_votes'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="allow_multiple_votes">
                                <strong>Allow Multiple Votes</strong>
                            </label>
                        </div>
                        <div class="form-text">Allow voters to change their vote before election ends</div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="form-check-custom">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="require_verification" name="require_verification" 
                                   <?= ($election['require_verification'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="require_verification">
                                <strong>Require Student Verification</strong>
                            </label>
                        </div>
                        <div class="form-text">Only verified students can vote</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Advanced Settings -->
    <div class="settings-section">
        <div class="settings-header">
            <h3 class="settings-title">
                <i class="fas fa-sliders-h"></i>
                Advanced Settings
            </h3>
        </div>
        <div class="settings-body">
            <div class="form-group-grid mb-3">
                <div class="mb-3">
                    <label for="vote_verification_method" class="form-label">Vote Verification Method</label>
                    <select class="form-select" id="vote_verification_method" name="vote_verification_method">
                        <option value="none" <?= ($settings['vote_verification_method'] ?? 'none') === 'none' ? 'selected' : '' ?>>None</option>
                        <option value="receipt" <?= ($settings['vote_verification_method'] ?? '') === 'receipt' ? 'selected' : '' ?>>Receipt Based</option>
                        <option value="blockchain" <?= ($settings['vote_verification_method'] ?? '') === 'blockchain' ? 'selected' : '' ?>>Blockchain</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label for="max_vote_attempts" class="form-label">Max Vote Attempts</label>
                    <input type="number" class="form-control" id="max_vote_attempts" name="max_vote_attempts" 
                           min="1" max="10" value="<?= $settings['max_vote_attempts'] ?? 3 ?>">
                    <div class="form-text">Maximum failed voting attempts before lockout</div>
                </div>
                
                <div class="mb-3">
                    <label for="voting_session_timeout" class="form-label">Session Timeout (seconds)</label>
                    <input type="number" class="form-control" id="voting_session_timeout" name="voting_session_timeout" 
                           min="60" max="3600" value="<?= $settings['voting_session_timeout'] ?? 300 ?>">
                    <div class="form-text">How long voting sessions remain active</div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-lg-4 col-md-6">
                    <div class="form-check-custom">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="enable_vote_receipt" name="enable_vote_receipt" 
                                   <?= ($settings['enable_vote_receipt'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="enable_vote_receipt">
                                <strong>Enable Vote Receipt</strong>
                            </label>
                        </div>
                        <div class="form-text">Provide voting confirmation to voters</div>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6">
                    <div class="form-check-custom">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="enable_candidate_photos" name="enable_candidate_photos" 
                                   <?= ($settings['enable_candidate_photos'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="enable_candidate_photos">
                                <strong>Enable Candidate Photos</strong>
                            </label>
                        </div>
                        <div class="form-text">Show candidate photos on ballot</div>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6">
                    <div class="form-check-custom">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="enable_candidate_statements" name="enable_candidate_statements" 
                                   <?= ($settings['enable_candidate_statements'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="enable_candidate_statements">
                                <strong>Enable Candidate Statements</strong>
                            </label>
                        </div>
                        <div class="form-text">Allow candidates to add statements</div>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6">
                    <div class="form-check-custom">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="randomize_candidate_order" name="randomize_candidate_order" 
                                   <?= ($settings['randomize_candidate_order'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="randomize_candidate_order">
                                <strong>Randomize Candidate Order</strong>
                            </label>
                        </div>
                        <div class="form-text">Randomize candidate order on ballot</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Notification Settings -->
    <div class="settings-section">
        <div class="settings-header">
            <h3 class="settings-title">
                <i class="fas fa-bell"></i>
                Notification Settings
            </h3>
        </div>
        <div class="settings-body">
            <div class="row">
                <div class="col-lg-4 col-md-6">
                    <div class="form-check-custom">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="send_start_notifications" name="send_start_notifications" 
                                   <?= ($settings['send_start_notifications'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="send_start_notifications">
                                <strong>Election Start Notifications</strong>
                            </label>
                        </div>
                        <div class="form-text">Notify voters when election starts</div>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6">
                    <div class="form-check-custom">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="send_end_notifications" name="send_end_notifications" 
                                   <?= ($settings['send_end_notifications'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="send_end_notifications">
                                <strong>Election End Notifications</strong>
                            </label>
                        </div>
                        <div class="form-text">Notify voters before election ends</div>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6">
                    <div class="form-check-custom">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="send_result_notifications" name="send_result_notifications" 
                                   <?= ($settings['send_result_notifications'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="send_result_notifications">
                                <strong>Result Notifications</strong>
                            </label>
                        </div>
                        <div class="form-text">Notify voters when results are published</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Security Settings -->
    <div class="settings-section">
        <div class="settings-header">
            <h3 class="settings-title">
                <i class="fas fa-shield-alt"></i>
                Security Settings
            </h3>
        </div>
        <div class="settings-body">
            <div class="row mb-3">
                <div class="col-md-6">
                    <div class="form-check-custom">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="ip_restriction_enabled" name="ip_restriction_enabled" 
                                   <?= ($settings['ip_restriction_enabled'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ip_restriction_enabled">
                                <strong>IP Restriction</strong>
                            </label>
                        </div>
                        <div class="form-text">Restrict voting to specific IP ranges</div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="form-check-custom">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="device_restriction_enabled" name="device_restriction_enabled" 
                                   <?= ($settings['device_restriction_enabled'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="device_restriction_enabled">
                                <strong>Device Restriction</strong>
                            </label>
                        </div>
                        <div class="form-text">Limit number of devices per voter</div>
                    </div>
                </div>
            </div>
            
            <div class="advanced-settings">
                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label for="allowed_ip_ranges" class="form-label">Allowed IP Ranges</label>
                        <textarea class="form-control" id="allowed_ip_ranges" name="allowed_ip_ranges" rows="3" 
                                  placeholder="192.168.1.0/24&#10;10.0.0.0/16"><?= sanitize($settings['allowed_ip_ranges'] ?? '') ?></textarea>
                        <div class="form-text">One IP range per line (CIDR notation)</div>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="max_devices_per_voter" class="form-label">Max Devices per Voter</label>
                        <input type="number" class="form-control" id="max_devices_per_voter" name="max_devices_per_voter" 
                               min="1" max="10" value="<?= $settings['max_devices_per_voter'] ?? 1 ?>">
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Form Actions -->
    <div class="d-flex gap-3 mb-4">
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="fas fa-save me-2"></i>Save Settings
        </button>
        
        <a href="<?= SITE_URL ?>election-officer/elections/" class="btn btn-secondary btn-lg">
            <i class="fas fa-arrow-left me-2"></i>Back to Elections
        </a>
        
        <a href="<?= SITE_URL ?>election-officer/elections/positions.php?election_id=<?= $election_id ?>" class="btn btn-outline-primary btn-lg">
            <i class="fas fa-list me-2"></i>Manage Positions
        </a>
    </div>
</form>

<script>
// Toggle advanced settings based on checkboxes
document.getElementById('ip_restriction_enabled').addEventListener('change', function() {
    document.getElementById('allowed_ip_ranges').disabled = !this.checked;
});

document.getElementById('device_restriction_enabled').addEventListener('change', function() {
    document.getElementById('max_devices_per_voter').disabled = !this.checked;
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('allowed_ip_ranges').disabled = !document.getElementById('ip_restriction_enabled').checked;
    document.getElementById('max_devices_per_voter').disabled = !document.getElementById('device_restriction_enabled').checked;
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>