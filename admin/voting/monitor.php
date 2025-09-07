<?php
require_once '../../config/config.php';
require_once '../../config/database.php';

if (!isLoggedIn() || (!hasPermission('monitor_voting') && !hasPermission('manage_voting'))) {
    redirectTo('auth/index.php');
}

$db = Database::getInstance()->getConnection();
$current_user = getCurrentUser();

// Debug information (remove in production)
if (isset($_GET['debug'])) {
    echo "<pre>";
    echo "Current User: " . print_r($current_user, true);
    echo "Is Logged In: " . (isLoggedIn() ? 'Yes' : 'No') . "\n";
    echo "Has monitor_voting permission: " . (hasPermission('monitor_voting') ? 'Yes' : 'No') . "\n";
    echo "Has manage_voting permission: " . (hasPermission('manage_voting') ? 'Yes' : 'No') . "\n";
    echo "</pre>";
}

// Get election ID from URL parameter
$election_id = isset($_GET['election_id']) ? (int)$_GET['election_id'] : null;

// Get elections for selection (including draft for testing)
$stmt = $db->query("
    SELECT election_id, name, status, start_date, end_date
    FROM elections 
    WHERE status IN ('active', 'scheduled', 'completed', 'draft')
    ORDER BY start_date DESC
");
$elections = $stmt->fetchAll();

// If no election selected, use the first active one
if (!$election_id && !empty($elections)) {
    $election_id = $elections[0]['election_id'];
}

$election_data = null;
$voting_stats = [];
$recent_votes = [];
$position_stats = [];

if ($election_id) {
    // Get election details
    $stmt = $db->prepare("
        SELECT e.*, et.name as election_type_name
        FROM elections e 
        LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
        WHERE e.election_id = ?
    ");
    $stmt->execute([$election_id]);
    $election_data = $stmt->fetch();

    if ($election_data) {
        // Get voting statistics
        $stmt = $db->prepare("
            SELECT 
                COUNT(DISTINCT vs.student_id) as total_voters,
                COUNT(v.vote_id) as total_votes,
                AVG(vs.votes_cast) as avg_votes_per_session,
                MIN(vs.started_at) as first_vote_time,
                MAX(vs.completed_at) as last_vote_time,
                (SELECT COUNT(*) FROM students WHERE is_active = 1 AND is_verified = 1) as total_eligible
            FROM voting_sessions vs
            LEFT JOIN votes v ON vs.session_id = v.session_id
            WHERE vs.election_id = ? AND vs.status = 'completed'
        ");
        $stmt->execute([$election_id]);
        $voting_stats = $stmt->fetch();

        // Get recent voting activity (last 20 votes)
        $stmt = $db->prepare("
            SELECT vs.*, s.student_number, s.first_name, s.last_name, vs.votes_cast,
                   vs.completed_at, vs.ip_address
            FROM voting_sessions vs
            JOIN students s ON vs.student_id = s.student_id
            WHERE vs.election_id = ? AND vs.status = 'completed'
            ORDER BY vs.completed_at DESC
            LIMIT 20
        ");
        $stmt->execute([$election_id]);
        $recent_votes = $stmt->fetchAll();

        // Get voting statistics by position
        $stmt = $db->prepare("
            SELECT p.title, p.position_id,
                   COUNT(v.vote_id) as vote_count,
                   COUNT(DISTINCT v.session_id) as voters_count,
                   COUNT(av.abstain_id) as abstain_count
            FROM positions p
            LEFT JOIN votes v ON p.position_id = v.position_id
            LEFT JOIN abstain_votes av ON p.position_id = av.position_id
            WHERE p.election_id = ?
            GROUP BY p.position_id, p.title
            ORDER BY p.display_order
        ");
        $stmt->execute([$election_id]);
        $position_stats = $stmt->fetchAll();

        // Get hourly voting pattern for today
        $stmt = $db->prepare("
            SELECT HOUR(vs.completed_at) as hour, COUNT(*) as vote_count
            FROM voting_sessions vs
            WHERE vs.election_id = ? AND vs.status = 'completed' 
                  AND DATE(vs.completed_at) = CURDATE()
            GROUP BY HOUR(vs.completed_at)
            ORDER BY hour
        ");
        $stmt->execute([$election_id]);
        $hourly_votes = $stmt->fetchAll();
    }
}

$page_title = "Live Voting Monitor";
include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-eye"></i> Live Voting Monitor</h2>
                <div class="btn-group">
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <button id="autoRefresh" class="btn btn-info" onclick="toggleAutoRefresh()">
                        <i class="fas fa-sync"></i> Auto Refresh: OFF
                    </button>
                </div>
            </div>

            <!-- Election Selection -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-body">
                            <form method="GET" class="form-inline">
                                <label for="election_select" class="mr-2"><strong>Select Election:</strong></label>
                                <select name="election_id" id="election_select" class="form-control mr-3" onchange="this.form.submit()">
                                    <option value="">Choose an election...</option>
                                    <?php foreach ($elections as $election): ?>
                                        <option value="<?= $election['election_id'] ?>" 
                                                <?= $election['election_id'] == $election_id ? 'selected' : '' ?>>
                                            <?= sanitize($election['name']) ?> 
                                            (<?= ucfirst($election['status']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <?php if ($election_data): ?>
                                    <div class="ml-auto">
                                        <span class="badge badge-<?= $election_data['status'] == 'active' ? 'success' : 'secondary' ?> p-2">
                                            <i class="fas fa-circle"></i> <?= ucfirst($election_data['status']) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$election_data): ?>
                <div class="alert alert-warning text-center">
                    <i class="fas fa-exclamation-triangle fa-2x mb-3"></i>
                    <h5>No Election Selected</h5>
                    <p>Please select an election to monitor from the dropdown above.</p>
                    <?php if (empty($elections)): ?>
                        <p class="mt-2"><small>No elections found in the database.</small></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                
                <!-- Election Info Debug (remove in production) -->
                <div class="alert alert-info">
                    <strong>Debug Info:</strong> 
                    Election: <?= $election_data['name'] ?> | 
                    Status: <?= $election_data['status'] ?> | 
                    Positions: <?= count($position_stats) ?> | 
                    Total Eligible: <?= $voting_stats['total_eligible'] ?? 0 ?>
                </div>
                
                <!-- Key Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body text-center">
                                <h3><?= $voting_stats['total_voters'] ?? 0 ?></h3>
                                <p class="mb-0">Total Voters</p>
                                <small>
                                    <?php 
                                    $turnout = $voting_stats['total_eligible'] > 0 
                                        ? round(($voting_stats['total_voters'] / $voting_stats['total_eligible']) * 100, 1) 
                                        : 0;
                                    echo $turnout . '% turnout';
                                    ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body text-center">
                                <h3><?= $voting_stats['total_votes'] ?? 0 ?></h3>
                                <p class="mb-0">Total Votes</p>
                                <small>Across all positions</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card bg-info text-white">
                            <div class="card-body text-center">
                                <h3><?= number_format($voting_stats['avg_votes_per_session'] ?? 0, 1) ?></h3>
                                <p class="mb-0">Avg Votes/Session</p>
                                <small>Per voting session</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card bg-warning text-white">
                            <div class="card-body text-center">
                                <h3 id="currentTime"><?= date('H:i:s') ?></h3>
                                <p class="mb-0">Current Time</p>
                                <small>Last updated: <span id="lastUpdate">Now</span></small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Voting by Position -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Voting Progress by Position</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($position_stats)): ?>
                                    <div class="text-center text-muted py-4">
                                        <i class="fas fa-info-circle fa-2x mb-3"></i>
                                        <p>No positions found for this election</p>
                                        <small>Positions need to be created before monitoring voting progress</small>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($position_stats as $position): ?>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <h6 class="mb-0"><?= sanitize($position['title']) ?></h6>
                                                <span class="text-muted">
                                                    <?= $position['vote_count'] ?> votes, 
                                                    <?= $position['abstain_count'] ?> abstains
                                                </span>
                                            </div>
                                            <?php 
                                            $total_responses = $position['voters_count'] + $position['abstain_count'];
                                            $participation = $voting_stats['total_eligible'] > 0 
                                                ? round(($total_responses / $voting_stats['total_eligible']) * 100, 1) 
                                                : 0;
                                            ?>
                                            <div class="progress" style="height: 25px;">
                                                <div class="progress-bar bg-success" role="progressbar" 
                                                     style="width: <?= $participation ?>%"
                                                     aria-valuenow="<?= $participation ?>" 
                                                     aria-valuemin="0" aria-valuemax="100">
                                                    <?= $participation ?>%
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Hourly Voting Pattern -->
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-clock"></i> Today's Voting Pattern</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($hourly_votes)): ?>
                                    <p class="text-center text-muted">No votes today</p>
                                <?php else: ?>
                                    <canvas id="hourlyChart" width="400" height="300"></canvas>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Voting Activity -->
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="fas fa-history"></i> Recent Voting Activity
                                    <span class="badge badge-info ml-2" id="activityCount">
                                        <?= count($recent_votes) ?>
                                    </span>
                                </h5>
                            </div>
                            <div class="card-body">
                                <div id="recentActivity">
                                    <?php if (empty($recent_votes)): ?>
                                        <div class="text-center py-4">
                                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                            <p class="text-muted">No voting activity yet</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Student</th>
                                                        <th>Student Number</th>
                                                        <th>Votes Cast</th>
                                                        <th>Time</th>
                                                        <th>IP Address</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="activityTable">
                                                    <?php foreach ($recent_votes as $vote): ?>
                                                        <tr>
                                                            <td>
                                                                <?= sanitize($vote['first_name'] . ' ' . $vote['last_name']) ?>
                                                            </td>
                                                            <td>
                                                                <code><?= sanitize($vote['student_number']) ?></code>
                                                            </td>
                                                            <td>
                                                                <span class="badge badge-success">
                                                                    <?= $vote['votes_cast'] ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <?= date('M j, g:i:s A', strtotime($vote['completed_at'])) ?>
                                                            </td>
                                                            <td>
                                                                <small class="text-muted">
                                                                    <?= sanitize($vote['ip_address']) ?>
                                                                </small>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="<?= SITE_URL ?>/assets/js/chart.min.js"></script>
<script>
let autoRefreshInterval = null;
let isAutoRefreshOn = false;

function toggleAutoRefresh() {
    const btn = document.getElementById('autoRefresh');
    
    if (isAutoRefreshOn) {
        clearInterval(autoRefreshInterval);
        btn.innerHTML = '<i class="fas fa-sync"></i> Auto Refresh: OFF';
        btn.classList.remove('btn-success');
        btn.classList.add('btn-info');
        isAutoRefreshOn = false;
    } else {
        autoRefreshInterval = setInterval(refreshData, 30000); // 30 seconds
        btn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Auto Refresh: ON';
        btn.classList.remove('btn-info');
        btn.classList.add('btn-success');
        isAutoRefreshOn = true;
    }
}

function refreshData() {
    // Update current time
    document.getElementById('currentTime').textContent = new Date().toLocaleTimeString();
    document.getElementById('lastUpdate').textContent = new Date().toLocaleTimeString();
    
    // In a real implementation, you would fetch new data via AJAX here
    // For now, we'll just reload the page
    if (isAutoRefreshOn) {
        location.reload();
    }
}

// Update time every second
setInterval(() => {
    document.getElementById('currentTime').textContent = new Date().toLocaleTimeString();
}, 1000);

// Initialize hourly chart if data exists
<?php if (!empty($hourly_votes)): ?>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('hourlyChart').getContext('2d');
    
    const hourlyData = {
        labels: <?= json_encode(array_column($hourly_votes, 'hour')) ?>,
        datasets: [{
            label: 'Votes per Hour',
            data: <?= json_encode(array_column($hourly_votes, 'vote_count')) ?>,
            backgroundColor: 'rgba(54, 162, 235, 0.2)',
            borderColor: 'rgba(54, 162, 235, 1)',
            borderWidth: 2,
            fill: true
        }]
    };
    
    new Chart(ctx, {
        type: 'line',
        data: hourlyData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Hour of Day'
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
});
<?php endif; ?>
</script>

<style>
.card {
    box-shadow: 0 0 10px rgba(0,0,0,0.1);
    border: none;
}

.progress {
    border-radius: 10px;
}

#hourlyChart {
    max-height: 250px;
}

.table-hover tbody tr:hover {
    background-color: rgba(0,123,255,0.1);
}

.badge {
    font-size: 0.75em;
}
</style>

<?php include '../../includes/footer.php'; ?>