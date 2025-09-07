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
$stmt = $db->prepare("SELECT * FROM elections WHERE election_id = ?");
$stmt->execute([$election_id]);
$election = $stmt->fetch();

if (!$election) {
    $_SESSION['error'] = 'Election not found.';
    redirectTo('election-officer/elections/');
}

$page_title = 'Election Results - ' . $election['name'];
$breadcrumbs = [
    ['title' => 'Election Officer', 'url' => SITE_URL . 'election-officer/'],
    ['title' => 'Results']
];

// Get election statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT v.student_id) as total_voters,
        COUNT(DISTINCT v.vote_id) as total_votes,
        COUNT(DISTINCT s.student_id) as eligible_voters
    FROM students s
    LEFT JOIN votes v ON s.student_id = v.student_id AND v.election_id = ?
    WHERE s.is_active = 1 AND s.is_verified = 1
");
$stmt->execute([$election_id]);
$vote_stats = $stmt->fetch();

$turnout_percentage = $vote_stats['eligible_voters'] > 0 ? 
    round(($vote_stats['total_voters'] / $vote_stats['eligible_voters']) * 100, 1) : 0;

// Get results by position
$stmt = $db->prepare("
    SELECT 
        p.position_id,
        p.position_name,
        p.max_votes_per_voter,
        COUNT(DISTINCT v.vote_id) as total_position_votes
    FROM positions p
    LEFT JOIN votes v ON p.position_id = v.position_id
    WHERE p.election_id = ? AND p.is_active = 1
    GROUP BY p.position_id
    ORDER BY p.title
");
$stmt->execute([$election_id]);
$positions = $stmt->fetchAll();

$results_data = [];
foreach ($positions as $position) {
    // Get candidates and their vote counts for this position
    $stmt = $db->prepare("
        SELECT 
            c.candidate_id,
            CONCAT(s.first_name, ' ', s.last_name) as candidate_name,
            s.student_id as student_number,
            s.program,
            s.class,
            c.platform_statement,
            c.campaign_slogan,
            COUNT(v.vote_id) as vote_count,
            CASE 
                WHEN ? > 0 THEN ROUND((COUNT(v.vote_id) / ?) * 100, 1)
                ELSE 0 
            END as vote_percentage
        FROM candidates c
        JOIN students s ON c.student_id = s.student_id
        LEFT JOIN votes v ON c.candidate_id = v.candidate_id
        WHERE c.position_id = ?
        GROUP BY c.candidate_id
        ORDER BY vote_count DESC, candidate_name ASC
    ");
    $stmt->execute([$position['total_position_votes'], $position['total_position_votes'], $position['position_id']]);
    $candidates = $stmt->fetchAll();
    
    $results_data[$position['position_id']] = [
        'position' => $position,
        'candidates' => $candidates
    ];
}

// Handle results publication
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'publish_results') {
        try {
            $stmt = $db->prepare("
                UPDATE elections 
                SET results_published = 1, results_published_at = NOW(), updated_at = NOW()
                WHERE election_id = ?
            ");
            $stmt->execute([$election_id]);
            
            logActivity('results_publish', "Results published for election: {$election['name']}");
            $_SESSION['success'] = 'Election results have been published successfully!';
            redirectTo("election-officer/results/view.php?election_id=$election_id");
            
        } catch (Exception $e) {
            $error = 'Failed to publish results. Please try again.';
            error_log("Results publish error: " . $e->getMessage());
        }
    }
    
    elseif ($action === 'unpublish_results') {
        try {
            $stmt = $db->prepare("
                UPDATE elections 
                SET results_published = 0, results_published_at = NULL, updated_at = NOW()
                WHERE election_id = ?
            ");
            $stmt->execute([$election_id]);
            
            logActivity('results_unpublish', "Results unpublished for election: {$election['name']}");
            $_SESSION['success'] = 'Election results have been unpublished successfully!';
            redirectTo("election-officer/results/view.php?election_id=$election_id");
            
        } catch (Exception $e) {
            $error = 'Failed to unpublish results. Please try again.';
            error_log("Results unpublish error: " . $e->getMessage());
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<style>
.election-info-card {
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    color: white;
    border-radius: 0.5rem;
    padding: 2rem;
    margin-bottom: 2rem;
}

.election-title {
    font-size: 1.75rem;
    font-weight: 600;
    margin: 0 0 1rem;
}

.election-meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    opacity: 0.9;
}

.meta-item {
    text-align: center;
}

.meta-number {
    font-size: 1.5rem;
    font-weight: 600;
    display: block;
}

.meta-label {
    font-size: 0.875rem;
    opacity: 0.8;
}

.position-results {
    background: white;
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
    overflow: hidden;
}

.position-header {
    background: #f8fafc;
    padding: 1.5rem;
    border-bottom: 1px solid #e2e8f0;
}

.position-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
}

.position-stats {
    font-size: 0.875rem;
    color: #64748b;
    margin: 0.5rem 0 0;
}

.candidate-result {
    padding: 1.5rem;
    border-bottom: 1px solid #f1f5f9;
    position: relative;
}

.candidate-result:last-child {
    border-bottom: none;
}

.candidate-result.winner {
    background: linear-gradient(90deg, rgba(16, 185, 129, 0.05) 0%, transparent 100%);
    border-left: 4px solid #10b981;
}

.candidate-rank {
    position: absolute;
    top: 1rem;
    right: 1.5rem;
    background: #f3f4f6;
    color: #374151;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
}

.candidate-rank.first {
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
    color: white;
}

.candidate-rank.second {
    background: linear-gradient(135deg, #9ca3af, #6b7280);
    color: white;
}

.candidate-rank.third {
    background: linear-gradient(135deg, #cd7c2f, #92400e);
    color: white;
}

.candidate-info {
    margin-bottom: 1rem;
}

.candidate-name {
    font-size: 1.125rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
}

.candidate-details {
    color: #64748b;
    font-size: 0.875rem;
    margin: 0.25rem 0;
}

.vote-bar-container {
    position: relative;
    height: 2rem;
    background: #f1f5f9;
    border-radius: 1rem;
    overflow: hidden;
    margin-bottom: 0.5rem;
}

.vote-bar {
    height: 100%;
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    border-radius: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    font-size: 0.875rem;
    transition: width 0.5s ease;
}

.vote-stats {
    display: flex;
    justify-content: space-between;
    font-size: 0.875rem;
    color: #64748b;
}

.actions-card {
    background: white;
    border-radius: 0.5rem;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
}

.status-published {
    color: #10b981;
    background: #ecfdf5;
    border: 1px solid #d1fae5;
    padding: 0.5rem 1rem;
    border-radius: 0.375rem;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.status-unpublished {
    color: #f59e0b;
    background: #fffbeb;
    border: 1px solid #fed7aa;
    padding: 0.5rem 1rem;
    border-radius: 0.375rem;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}
</style>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= $_SESSION['success'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= $error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Election Info -->
<div class="election-info-card">
    <h2 class="election-title"><?= sanitize($election['name']) ?></h2>
    <div class="election-meta">
        <div class="meta-item">
            <span class="meta-number"><?= number_format($vote_stats['total_voters']) ?></span>
            <span class="meta-label">Total Voters</span>
        </div>
        <div class="meta-item">
            <span class="meta-number"><?= number_format($vote_stats['total_votes']) ?></span>
            <span class="meta-label">Total Votes</span>
        </div>
        <div class="meta-item">
            <span class="meta-number"><?= $turnout_percentage ?>%</span>
            <span class="meta-label">Turnout</span>
        </div>
        <div class="meta-item">
            <span class="meta-number"><?= date('M j, Y', strtotime($election['start_date'])) ?></span>
            <span class="meta-label">Election Date</span>
        </div>
    </div>
</div>

<!-- Actions -->
<div class="actions-card">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <?php if ($election['results_published']): ?>
                <span class="status-published">
                    <i class="fas fa-eye"></i>
                    Results Published
                </span>
                <div class="small text-muted mt-1">
                    Published on <?= date('M j, Y g:i A', strtotime($election['results_published_at'])) ?>
                </div>
            <?php else: ?>
                <span class="status-unpublished">
                    <i class="fas fa-eye-slash"></i>
                    Results Not Published
                </span>
            <?php endif; ?>
        </div>
        
        <div class="d-flex gap-2">
            <?php if ($election['results_published']): ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="unpublish_results">
                    <button type="submit" class="btn btn-warning" onclick="return confirm('Are you sure you want to unpublish the results? Students will no longer be able to view them.')">
                        <i class="fas fa-eye-slash me-2"></i>Unpublish Results
                    </button>
                </form>
            <?php else: ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="publish_results">
                    <button type="submit" class="btn btn-success" onclick="return confirm('Are you sure you want to publish the results? Students will be able to view them.')">
                        <i class="fas fa-eye me-2"></i>Publish Results
                    </button>
                </form>
            <?php endif; ?>
            
            <a href="<?= SITE_URL ?>election-officer/results/export.php?election_id=<?= $election_id ?>" class="btn btn-outline-primary">
                <i class="fas fa-download me-2"></i>Export Results
            </a>
            
            <a href="<?= SITE_URL ?>election-officer/elections/" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Elections
            </a>
        </div>
    </div>
</div>

<!-- Results by Position -->
<?php if (empty($results_data)): ?>
    <div class="text-center py-5">
        <i class="fas fa-chart-bar fa-4x text-muted mb-3"></i>
        <h4>No Results Available</h4>
        <p class="text-muted">No positions or candidates found for this election.</p>
    </div>
<?php else: ?>
    <?php foreach ($results_data as $position_id => $data): ?>
        <div class="position-results">
            <div class="position-header">
                <h3 class="position-title"><?= sanitize($data['position']['position_name']) ?></h3>
                <p class="position-stats">
                    <?= number_format($data['position']['total_position_votes']) ?> total votes • 
                    <?= count($data['candidates']) ?> candidates
                </p>
            </div>
            
            <?php if (empty($data['candidates'])): ?>
                <div class="text-center py-4">
                    <p class="text-muted">No approved candidates for this position.</p>
                </div>
            <?php else: ?>
                <?php 
                $rank = 1;
                $prev_votes = null;
                $actual_rank = 1;
                ?>
                <?php foreach ($data['candidates'] as $index => $candidate): ?>
                    <?php 
                    // Handle tied votes - same votes = same rank
                    if ($prev_votes !== null && $candidate['vote_count'] !== $prev_votes) {
                        $actual_rank = $index + 1;
                    }
                    $prev_votes = $candidate['vote_count'];
                    
                    $is_winner = $actual_rank === 1 && $candidate['vote_count'] > 0;
                    ?>
                    <div class="candidate-result <?= $is_winner ? 'winner' : '' ?>">
                        <div class="candidate-rank <?= $actual_rank === 1 ? 'first' : ($actual_rank === 2 ? 'second' : ($actual_rank === 3 ? 'third' : '')) ?>">
                            <?php if ($actual_rank === 1): ?>
                                <i class="fas fa-crown me-1"></i>
                            <?php endif; ?>
                            #<?= $actual_rank ?>
                        </div>
                        
                        <div class="candidate-info">
                            <h4 class="candidate-name"><?= sanitize($candidate['candidate_name']) ?></h4>
                            <div class="candidate-details">
                                <?= sanitize($candidate['student_number']) ?> • 
                                <?= sanitize($candidate['program']) ?> • 
                                <?= sanitize($candidate['class']) ?>
                            </div>
                            <?php if ($candidate['campaign_slogan']): ?>
                                <div class="candidate-details">
                                    <em>"<?= sanitize($candidate['campaign_slogan']) ?>"</em>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="vote-bar-container">
                            <div class="vote-bar" style="width: <?= $candidate['vote_percentage'] ?>%">
                                <?php if ($candidate['vote_percentage'] >= 20): ?>
                                    <?= number_format($candidate['vote_count']) ?> votes
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="vote-stats">
                            <span><?= number_format($candidate['vote_count']) ?> votes</span>
                            <span><?= $candidate['vote_percentage'] ?>%</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<script>
// Add animation to vote bars
document.addEventListener('DOMContentLoaded', function() {
    const voteBars = document.querySelectorAll('.vote-bar');
    voteBars.forEach((bar, index) => {
        const width = bar.style.width;
        bar.style.width = '0%';
        setTimeout(() => {
            bar.style.width = width;
        }, index * 100);
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>