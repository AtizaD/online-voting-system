<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['election_officer']);

$page_title = 'Voting Monitor';
$breadcrumbs = [
    ['title' => 'Election Officer', 'url' => SITE_URL . 'election-officer/'],
    ['title' => 'Voting Monitor']
];

$db = Database::getInstance()->getConnection();

// Get active elections
$stmt = $db->prepare("
    SELECT election_id, name as title, start_date, end_date, status 
    FROM elections 
    WHERE status IN ('active', 'draft') 
    ORDER BY start_date ASC
");
$stmt->execute();
$active_elections = $stmt->fetchAll();

// Get selected election or default to first active
$selected_election = $_GET['election_id'] ?? ($active_elections[0]['election_id'] ?? null);

if ($selected_election) {
    // Get election details
    $stmt = $db->prepare("SELECT * FROM elections WHERE election_id = ?");
    $stmt->execute([$selected_election]);
    $election = $stmt->fetch();
    
    // Get voting statistics
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT vs.student_id) as total_voters,
            COUNT(DISTINCT v.vote_id) as total_votes,
            COUNT(DISTINCT CASE WHEN v.vote_timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN v.vote_id END) as votes_last_hour,
            COUNT(DISTINCT CASE WHEN v.vote_timestamp >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN v.vote_id END) as votes_last_5min
        FROM votes v
        JOIN voting_sessions vs ON v.session_id = vs.session_id
        WHERE v.election_id = ?
    ");
    $stmt->execute([$selected_election]);
    $vote_stats = $stmt->fetch();
    
    // Get eligible voters count
    $stmt = $db->prepare("
        SELECT COUNT(*) as eligible_voters
        FROM students 
        WHERE is_active = 1 AND is_verified = 1
    ");
    $stmt->execute();
    $eligible_stats = $stmt->fetch();
    
    // Get voting activity by hour (last 24 hours)
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(vote_timestamp, '%H:00') as hour,
            COUNT(*) as vote_count
        FROM votes 
        WHERE election_id = ? AND vote_timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY DATE_FORMAT(vote_timestamp, '%H:00')
        ORDER BY DATE_FORMAT(vote_timestamp, '%H:00')
    ");
    $stmt->execute([$selected_election]);
    $hourly_activity = $stmt->fetchAll();
    
    // Get recent votes
    $stmt = $db->prepare("
        SELECT 
            v.vote_timestamp as created_at,
            CONCAT(s.first_name, ' ', s.last_name) as voter_name,
            s.student_id as student_number,
            p.title as position_name,
            'Vote Cast' as action_type
        FROM votes v
        JOIN voting_sessions vs ON v.session_id = vs.session_id
        JOIN students s ON vs.student_id = s.student_id
        JOIN positions p ON v.position_id = p.position_id
        WHERE v.election_id = ?
        ORDER BY v.vote_timestamp DESC
        LIMIT 50
    ");
    $stmt->execute([$selected_election]);
    $recent_activity = $stmt->fetchAll();
    
    // Get position-wise statistics
    $stmt = $db->prepare("
        SELECT 
            p.title as position_name,
            COUNT(DISTINCT vs.student_id) as voters_count,
            COUNT(v.vote_id) as votes_count,
            COUNT(DISTINCT c.candidate_id) as candidates_count
        FROM positions p
        LEFT JOIN candidates c ON p.position_id = c.position_id
        LEFT JOIN votes v ON p.position_id = v.position_id
        LEFT JOIN voting_sessions vs ON v.session_id = vs.session_id
        WHERE p.election_id = ? AND p.is_active = 1
        GROUP BY p.position_id
        ORDER BY p.title
    ");
    $stmt->execute([$selected_election]);
    $position_stats = $stmt->fetchAll();
    
    // Calculate turnout percentage
    $turnout_percentage = $eligible_stats['eligible_voters'] > 0 ? 
        round(($vote_stats['total_voters'] / $eligible_stats['eligible_voters']) * 100, 1) : 0;
}

include __DIR__ . '/../../includes/header.php';
?>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    border-radius: 0.5rem;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    text-align: center;
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 4px;
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
}

.stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}

.stat-label {
    color: #64748b;
    font-size: 0.875rem;
    margin: 0.5rem 0 0;
}

.stat-change {
    font-size: 0.75rem;
    margin-top: 0.25rem;
}

.stat-change.positive { color: #10b981; }
.stat-change.neutral { color: #6b7280; }

.monitor-card {
    background: white;
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
}

.monitor-header {
    padding: 1.5rem;
    border-bottom: 1px solid #f1f5f9;
    background: #f8fafc;
}

.monitor-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.monitor-body {
    padding: 1.5rem;
}

.activity-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f1f5f9;
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-info {
    flex: 1;
}

.activity-time {
    color: #64748b;
    font-size: 0.875rem;
}

.position-stat-item {
    display: flex;
    align-items: center;
    justify-content: between;
    padding: 1rem;
    background: #f8fafc;
    border-radius: 0.375rem;
    margin-bottom: 0.5rem;
}

.position-stat-name {
    font-weight: 600;
    color: #1e293b;
    flex: 1;
}

.position-stat-numbers {
    display: flex;
    gap: 1rem;
    font-size: 0.875rem;
}

.position-stat-number {
    text-align: center;
}

.position-stat-number .number {
    font-weight: 600;
    color: #1e293b;
    display: block;
}

.position-stat-number .label {
    color: #64748b;
    font-size: 0.75rem;
}

.live-indicator {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: #10b981;
    font-size: 0.875rem;
    font-weight: 500;
}

.live-dot {
    width: 8px;
    height: 8px;
    background: #10b981;
    border-radius: 50%;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}

.election-selector {
    background: white;
    border-radius: 0.5rem;
    padding: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
}

.no-data {
    text-align: center;
    padding: 3rem;
    color: #64748b;
}

.chart-container {
    height: 300px;
    position: relative;
}
</style>

<?php if (empty($active_elections)): ?>
    <div class="no-data">
        <i class="fas fa-poll fa-4x text-muted mb-3"></i>
        <h4>No Active Elections</h4>
        <p>There are no active or scheduled elections to monitor.</p>
        <a href="<?= SITE_URL ?>election-officer/elections/manage.php" class="btn btn-primary">
            <i class="fas fa-plus me-2"></i>Create Election
        </a>
    </div>
<?php else: ?>
    
    <!-- Election Selector -->
    <div class="election-selector">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4>Election Monitor</h4>
                <div class="live-indicator">
                    <div class="live-dot"></div>
                    Live monitoring
                </div>
            </div>
            
            <div>
                <label for="election_select" class="form-label">Select Election:</label>
                <select class="form-select" id="election_select" onchange="changeElection()">
                    <?php foreach ($active_elections as $election): ?>
                        <option value="<?= $election['election_id'] ?>" 
                                <?= $election['election_id'] == $selected_election ? 'selected' : '' ?>>
                            <?= sanitize($election['title']) ?> 
                            (<?= ucfirst($election['status']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <?php if ($selected_election && $election): ?>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3 class="stat-number"><?= number_format($vote_stats['total_voters']) ?></h3>
                <p class="stat-label">Total Voters</p>
                <div class="stat-change neutral">
                    <?= $turnout_percentage ?>% turnout
                </div>
            </div>
            
            <div class="stat-card">
                <h3 class="stat-number"><?= number_format($vote_stats['total_votes']) ?></h3>
                <p class="stat-label">Total Votes</p>
                <div class="stat-change positive">
                    +<?= $vote_stats['votes_last_hour'] ?> last hour
                </div>
            </div>
            
            <div class="stat-card">
                <h3 class="stat-number"><?= number_format($eligible_stats['eligible_voters']) ?></h3>
                <p class="stat-label">Eligible Voters</p>
                <div class="stat-change neutral">
                    <?= number_format($eligible_stats['eligible_voters'] - $vote_stats['total_voters']) ?> remaining
                </div>
            </div>
            
            <div class="stat-card">
                <h3 class="stat-number"><?= number_format($vote_stats['votes_last_5min']) ?></h3>
                <p class="stat-label">Votes (5 min)</p>
                <div class="stat-change positive">
                    Real-time activity
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Recent Activity -->
            <div class="col-lg-8 mb-4">
                <div class="monitor-card">
                    <div class="monitor-header">
                        <h3 class="monitor-title">
                            <i class="fas fa-stream text-primary"></i>
                            Recent Voting Activity
                        </h3>
                    </div>
                    <div class="monitor-body">
                        <?php if (empty($recent_activity)): ?>
                            <div class="no-data">
                                <i class="fas fa-inbox text-muted fa-2x mb-2"></i>
                                <p>No voting activity yet.</p>
                            </div>
                        <?php else: ?>
                            <div style="max-height: 400px; overflow-y: auto;">
                                <?php foreach ($recent_activity as $activity): ?>
                                    <div class="activity-item">
                                        <div class="activity-info">
                                            <strong><?= sanitize($activity['voter_name']) ?></strong>
                                            <span class="text-muted">(<?= sanitize($activity['student_number']) ?>)</span>
                                            <div class="small text-muted">
                                                Voted for <?= sanitize($activity['position_name']) ?>
                                            </div>
                                        </div>
                                        <div class="activity-time">
                                            <?= date('g:i A', strtotime($activity['created_at'])) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Position Statistics -->
            <div class="col-lg-4 mb-4">
                <div class="monitor-card">
                    <div class="monitor-header">
                        <h3 class="monitor-title">
                            <i class="fas fa-chart-pie text-primary"></i>
                            Position Statistics
                        </h3>
                    </div>
                    <div class="monitor-body">
                        <?php if (empty($position_stats)): ?>
                            <div class="no-data">
                                <p>No positions found.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($position_stats as $position): ?>
                                <div class="position-stat-item">
                                    <div class="position-stat-name">
                                        <?= sanitize($position['position_name']) ?>
                                    </div>
                                    <div class="position-stat-numbers">
                                        <div class="position-stat-number">
                                            <span class="number"><?= $position['voters_count'] ?></span>
                                            <span class="label">Voters</span>
                                        </div>
                                        <div class="position-stat-number">
                                            <span class="number"><?= $position['votes_count'] ?></span>
                                            <span class="label">Votes</span>
                                        </div>
                                        <div class="position-stat-number">
                                            <span class="number"><?= $position['candidates_count'] ?></span>
                                            <span class="label">Candidates</span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Voting Timeline -->
        <div class="monitor-card">
            <div class="monitor-header">
                <h3 class="monitor-title">
                    <i class="fas fa-chart-line text-primary"></i>
                    Voting Activity (Last 24 Hours)
                </h3>
            </div>
            <div class="monitor-body">
                <div class="chart-container">
                    <?php if (empty($hourly_activity)): ?>
                        <div class="no-data">
                            <p>No voting activity data available for the last 24 hours.</p>
                        </div>
                    <?php else: ?>
                        <canvas id="votingChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
    <?php endif; ?>
<?php endif; ?>

<script src="<?= SITE_URL ?>/assets/js/chart.min.js"></script>
<script>
function changeElection() {
    const select = document.getElementById('election_select');
    const electionId = select.value;
    if (electionId) {
        window.location.href = `?election_id=${electionId}`;
    }
}

// Auto-refresh every 30 seconds
let refreshInterval;
function startAutoRefresh() {
    refreshInterval = setInterval(() => {
        location.reload();
    }, 30000);
}

// Stop auto-refresh when user interacts
function stopAutoRefresh() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
}

// Start auto-refresh on load
document.addEventListener('DOMContentLoaded', function() {
    startAutoRefresh();
    
    // Stop refresh on user interaction
    document.addEventListener('click', stopAutoRefresh);
    document.addEventListener('keydown', stopAutoRefresh);
    
    // Create voting activity chart
    <?php if (!empty($hourly_activity)): ?>
    const ctx = document.getElementById('votingChart');
    if (ctx) {
        const chartData = {
            labels: [<?php foreach ($hourly_activity as $activity): ?>'<?= $activity['hour'] ?>',<?php endforeach; ?>],
            datasets: [{
                label: 'Votes per Hour',
                data: [<?php foreach ($hourly_activity as $activity): ?><?= $activity['vote_count'] ?>,<?php endforeach; ?>],
                borderColor: 'rgb(102, 126, 234)',
                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                tension: 0.4,
                fill: true
            }]
        };
        
        new Chart(ctx, {
            type: 'line',
            data: chartData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    }
    <?php endif; ?>
});

// Add visibility change handler to restart refresh when tab becomes active
document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
        startAutoRefresh();
    } else {
        stopAutoRefresh();
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>