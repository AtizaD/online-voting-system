<?php
require_once '../../config/config.php';
require_once '../../config/database.php';

if (!isLoggedIn() || (!hasPermission('view_reports') && !hasPermission('manage_system'))) {
    redirectTo('auth/index.php');
}

$db = Database::getInstance()->getConnection();
$current_user = getCurrentUser();

// Get selected election ID
$election_id = isset($_GET['election_id']) ? (int)$_GET['election_id'] : null;

// Get all elections for dropdown
$stmt = $db->query("
    SELECT e.election_id, e.name, e.status, e.start_date, e.end_date,
           et.name as election_type_name
    FROM elections e
    LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
    ORDER BY e.start_date DESC
");
$all_elections = $stmt->fetchAll();

$election_data = null;
$voting_stats = [];
$turnout_by_program = [];
$position_results = [];
$voting_timeline = [];

if ($election_id) {
    // Get election details
    $stmt = $db->prepare("
        SELECT e.*, et.name as election_type_name,
               u.first_name as created_by_first_name, u.last_name as created_by_last_name
        FROM elections e
        LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
        LEFT JOIN users u ON e.created_by = u.user_id
        WHERE e.election_id = ?
    ");
    $stmt->execute([$election_id]);
    $election_data = $stmt->fetch();

    if ($election_data) {
        // Get comprehensive voting statistics
        $stmt = $db->prepare("
            SELECT 
                COUNT(DISTINCT vs.student_id) as total_voters,
                COUNT(v.vote_id) as total_votes,
                COUNT(DISTINCT p.position_id) as total_positions,
                COUNT(DISTINCT c.candidate_id) as total_candidates,
                (SELECT COUNT(*) FROM students WHERE is_active = 1 AND is_verified = 1) as eligible_voters,
                MIN(vs.started_at) as first_vote_time,
                MAX(vs.completed_at) as last_vote_time,
                AVG(vs.votes_cast) as avg_votes_per_session,
                COUNT(av.abstain_id) as total_abstains
            FROM elections e
            LEFT JOIN positions p ON e.election_id = p.election_id
            LEFT JOIN candidates c ON p.position_id = c.position_id
            LEFT JOIN voting_sessions vs ON e.election_id = vs.election_id AND vs.status = 'completed'
            LEFT JOIN votes v ON vs.session_id = v.session_id
            LEFT JOIN abstain_votes av ON p.position_id = av.position_id
            WHERE e.election_id = ?
            GROUP BY e.election_id
        ");
        $stmt->execute([$election_id]);
        $voting_stats = $stmt->fetch();

        // Get turnout by program
        $stmt = $db->prepare("
            SELECT p.program_name,
                   COUNT(DISTINCT s.student_id) as total_students,
                   COUNT(DISTINCT vs.student_id) as voted_students,
                   ROUND(COUNT(DISTINCT vs.student_id) / COUNT(DISTINCT s.student_id) * 100, 2) as turnout_rate
            FROM programs p
            LEFT JOIN students s ON p.program_id = s.program_id AND s.is_active = 1 AND s.is_verified = 1
            LEFT JOIN voting_sessions vs ON s.student_id = vs.student_id AND vs.election_id = ? AND vs.status = 'completed'
            WHERE p.is_active = 1
            GROUP BY p.program_id
            HAVING total_students > 0
            ORDER BY turnout_rate DESC
        ");
        $stmt->execute([$election_id]);
        $turnout_by_program = $stmt->fetchAll();

        // Get detailed position results
        $stmt = $db->prepare("
            SELECT p.position_id, p.title, p.description,
                   COUNT(DISTINCT c.candidate_id) as candidate_count,
                   COUNT(v.vote_id) as vote_count,
                   COUNT(av.abstain_id) as abstain_count,
                   ROUND(COUNT(v.vote_id) / (COUNT(v.vote_id) + COUNT(av.abstain_id)) * 100, 2) as vote_percentage
            FROM positions p
            LEFT JOIN candidates c ON p.position_id = c.position_id
            LEFT JOIN votes v ON c.candidate_id = v.candidate_id
            LEFT JOIN abstain_votes av ON p.position_id = av.position_id
            WHERE p.election_id = ?
            GROUP BY p.position_id
            ORDER BY p.display_order
        ");
        $stmt->execute([$election_id]);
        $position_results = $stmt->fetchAll();

        // Get voting timeline (hourly breakdown)
        $stmt = $db->prepare("
            SELECT DATE(vs.started_at) as vote_date,
                   HOUR(vs.started_at) as vote_hour,
                   COUNT(*) as vote_count
            FROM voting_sessions vs
            WHERE vs.election_id = ? AND vs.status = 'completed'
            GROUP BY DATE(vs.started_at), HOUR(vs.started_at)
            ORDER BY vote_date, vote_hour
        ");
        $stmt->execute([$election_id]);
        $voting_timeline = $stmt->fetchAll();

        // Get top candidates for each position
        foreach ($position_results as &$position) {
            $stmt = $db->prepare("
                SELECT c.candidate_id, 
                       s.first_name, s.last_name, s.student_number, s.photo_url,
                       pr.program_name,
                       COUNT(v.vote_id) as vote_count,
                       ROUND(COUNT(v.vote_id) / ? * 100, 2) as vote_percentage
                FROM candidates c
                JOIN students s ON c.student_id = s.student_id
                JOIN programs pr ON s.program_id = pr.program_id
                LEFT JOIN votes v ON c.candidate_id = v.candidate_id
                WHERE c.position_id = ?
                GROUP BY c.candidate_id
                ORDER BY vote_count DESC
                LIMIT 5
            ");
            $stmt->execute([$position['vote_count'] ?: 1, $position['position_id']]);
            $position['candidates'] = $stmt->fetchAll();
        }
    }
}

$page_title = "Election Reports";
include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><i class="fas fa-vote-yea"></i> Election Reports</h2>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../">Admin</a></li>
                            <li class="breadcrumb-item"><a href="./">Reports</a></li>
                            <li class="breadcrumb-item active">Elections</li>
                        </ol>
                    </nav>
                </div>
                <div class="btn-group">
                    <a href="./" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Reports
                    </a>
                    <?php if ($election_data): ?>
                        <button onclick="window.print()" class="btn btn-info">
                            <i class="fas fa-print"></i> Print Report
                        </button>
                        <div class="dropdown">
                            <button class="btn btn-success dropdown-toggle" type="button" data-toggle="dropdown">
                                <i class="fas fa-download"></i> Export
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="export.php?type=election&election_id=<?= $election_id ?>&format=pdf">
                                    <i class="fas fa-file-pdf"></i> PDF Report
                                </a>
                                <a class="dropdown-item" href="export.php?type=election&election_id=<?= $election_id ?>&format=excel">
                                    <i class="fas fa-file-excel"></i> Excel Report
                                </a>
                                <a class="dropdown-item" href="export.php?type=election&election_id=<?= $election_id ?>&format=csv">
                                    <i class="fas fa-file-csv"></i> CSV Data
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Election Selection -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-body">
                            <form method="GET" class="form-inline">
                                <label for="election_select" class="mr-2"><strong>Select Election:</strong></label>
                                <select name="election_id" id="election_select" class="form-control mr-3" onchange="this.form.submit()" style="min-width: 300px;">
                                    <option value="">Choose an election to analyze...</option>
                                    <?php foreach ($all_elections as $election): ?>
                                        <option value="<?= $election['election_id'] ?>" 
                                                <?= $election['election_id'] == $election_id ? 'selected' : '' ?>>
                                            <?= sanitize($election['name']) ?> 
                                            (<?= sanitize($election['election_type_name']) ?> - <?= ucfirst($election['status']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <?php if ($election_data): ?>
                                    <div class="ml-auto">
                                        <span class="badge badge-<?= $election_data['status'] == 'completed' ? 'success' : 'info' ?> p-2">
                                            <i class="fas fa-info-circle"></i> <?= ucfirst($election_data['status']) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$election_data): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-chart-bar fa-3x mb-3"></i>
                    <h5>Select an Election to View Reports</h5>
                    <p>Choose an election from the dropdown above to view comprehensive reports and analytics.</p>
                </div>
            <?php else: ?>

                <!-- Election Overview -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card border-primary">
                            <div class="card-header bg-primary text-white">
                                <h4 class="mb-0">
                                    <i class="fas fa-info-circle"></i> 
                                    <?= sanitize($election_data['name']) ?> - Report Overview
                                </h4>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-borderless">
                                            <tr>
                                                <th width="35%">Election Type:</th>
                                                <td><?= sanitize($election_data['election_type_name']) ?></td>
                                            </tr>
                                            <tr>
                                                <th>Status:</th>
                                                <td>
                                                    <span class="badge badge-<?= $election_data['status'] == 'completed' ? 'success' : 'info' ?> p-2">
                                                        <?= ucfirst($election_data['status']) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Duration:</th>
                                                <td>
                                                    <?= date('M j, Y g:i A', strtotime($election_data['start_date'])) ?><br>
                                                    <small class="text-muted">to</small><br>
                                                    <?= date('M j, Y g:i A', strtotime($election_data['end_date'])) ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Created by:</th>
                                                <td><?= sanitize($election_data['created_by_first_name'] . ' ' . $election_data['created_by_last_name']) ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-center">
                                            <h6>Voter Participation</h6>
                                            <?php 
                                            $turnout_rate = $voting_stats['eligible_voters'] > 0 
                                                ? round(($voting_stats['total_voters'] / $voting_stats['eligible_voters']) * 100, 1) 
                                                : 0;
                                            ?>
                                            <div class="progress mb-3" style="height: 40px;">
                                                <div class="progress-bar bg-success progress-bar-animated" 
                                                     style="width: <?= $turnout_rate ?>%">
                                                    <strong><?= $turnout_rate ?>%</strong>
                                                </div>
                                            </div>
                                            <p><strong><?= $voting_stats['total_voters'] ?></strong> of <strong><?= $voting_stats['eligible_voters'] ?></strong> eligible voters participated</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Key Metrics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body text-center">
                                <i class="fas fa-users fa-2x mb-2"></i>
                                <h3><?= $voting_stats['total_voters'] ?></h3>
                                <p class="mb-0">Total Voters</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body text-center">
                                <i class="fas fa-vote-yea fa-2x mb-2"></i>
                                <h3><?= $voting_stats['total_votes'] ?></h3>
                                <p class="mb-0">Total Votes</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-info text-white">
                            <div class="card-body text-center">
                                <i class="fas fa-list fa-2x mb-2"></i>
                                <h3><?= $voting_stats['total_positions'] ?></h3>
                                <p class="mb-0">Positions</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-warning text-white">
                            <div class="card-body text-center">
                                <i class="fas fa-user-tie fa-2x mb-2"></i>
                                <h3><?= $voting_stats['total_candidates'] ?></h3>
                                <p class="mb-0">Candidates</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Turnout by Program & Voting Timeline -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-graduation-cap"></i> Turnout by Program</h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($turnout_by_program)): ?>
                                    <?php foreach ($turnout_by_program as $program): ?>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <strong><?= sanitize($program['program_name']) ?></strong>
                                                <span class="text-muted"><?= $program['turnout_rate'] ?>%</span>
                                            </div>
                                            <div class="progress">
                                                <div class="progress-bar" 
                                                     style="width: <?= $program['turnout_rate'] ?>%">
                                                </div>
                                            </div>
                                            <small class="text-muted">
                                                <?= $program['voted_students'] ?> of <?= $program['total_students'] ?> students
                                            </small>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted">No program data available</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-clock"></i> Voting Timeline</h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($voting_timeline)): ?>
                                    <canvas id="votingTimelineChart" height="200"></canvas>
                                <?php else: ?>
                                    <p class="text-muted text-center">No voting timeline data available</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Position Results -->
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-trophy"></i> Position Results Summary</h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($position_results)): ?>
                                    <?php foreach ($position_results as $position): ?>
                                        <div class="card mb-3">
                                            <div class="card-header bg-light">
                                                <div class="d-flex justify-content-between">
                                                    <h6 class="mb-0"><?= sanitize($position['title']) ?></h6>
                                                    <div>
                                                        <span class="badge badge-primary"><?= $position['candidate_count'] ?> candidates</span>
                                                        <span class="badge badge-success"><?= $position['vote_count'] ?> votes</span>
                                                        <span class="badge badge-warning"><?= $position['abstain_count'] ?> abstains</span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="card-body">
                                                <?php if (!empty($position['candidates'])): ?>
                                                    <div class="row">
                                                        <?php foreach ($position['candidates'] as $index => $candidate): ?>
                                                            <div class="col-md-4 mb-2">
                                                                <div class="d-flex align-items-center">
                                                                    <div class="candidate-rank mr-2">
                                                                        <span class="badge badge-<?= $index === 0 ? 'success' : 'secondary' ?>">
                                                                            #<?= $index + 1 ?>
                                                                        </span>
                                                                    </div>
                                                                    <div class="candidate-info">
                                                                        <div class="candidate-name">
                                                                            <strong><?= sanitize($candidate['first_name'] . ' ' . $candidate['last_name']) ?></strong>
                                                                        </div>
                                                                        <div class="candidate-details text-muted small">
                                                                            <?= sanitize($candidate['student_number']) ?> | <?= sanitize($candidate['program_name']) ?>
                                                                        </div>
                                                                        <div class="candidate-votes">
                                                                            <span class="text-primary"><?= $candidate['vote_count'] ?> votes</span>
                                                                            <span class="text-muted">(<?= $candidate['vote_percentage'] ?>%)</span>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <p class="text-muted">No candidates for this position</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-info-circle fa-2x text-muted mb-3"></i>
                                        <p class="text-muted">No position results available</p>
                                    </div>
                                <?php endif; ?>
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
<?php if (!empty($voting_timeline)): ?>
// Prepare timeline data
const timelineLabels = [];
const timelineData = [];

<?php 
$timeline_by_hour = [];
foreach ($voting_timeline as $entry) {
    $hour_key = $entry['vote_hour'];
    if (!isset($timeline_by_hour[$hour_key])) {
        $timeline_by_hour[$hour_key] = 0;
    }
    $timeline_by_hour[$hour_key] += $entry['vote_count'];
}
?>

<?php foreach ($timeline_by_hour as $hour => $count): ?>
timelineLabels.push('<?= sprintf("%02d:00", $hour) ?>');
timelineData.push(<?= $count ?>);
<?php endforeach; ?>

// Create voting timeline chart
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('votingTimelineChart').getContext('2d');
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: timelineLabels,
            datasets: [{
                label: 'Votes per Hour',
                data: timelineData,
                borderColor: 'rgb(54, 162, 235)',
                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                tension: 0.3,
                fill: true
            }]
        },
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
                        stepSize: 1
                    }
                }
            }
        }
    });
});
<?php endif; ?>
</script>

<style>
.card {
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border: none;
}

.progress {
    height: 20px;
    border-radius: 10px;
}

.candidate-rank {
    min-width: 40px;
}

.badge {
    font-size: 0.75em;
}

@media print {
    .btn, .btn-group {
        display: none !important;
    }
    
    .card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>