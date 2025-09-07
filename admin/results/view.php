<?php
require_once '../../config/config.php';
require_once '../../config/database.php';

if (!isLoggedIn() || (!hasPermission('view_results') && !hasPermission('manage_voting'))) {
    redirectTo('auth/index.php');
}

$db = Database::getInstance()->getConnection();
$current_user = getCurrentUser();

// Get election ID from URL parameter
$election_id = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$election_id) {
    $_SESSION['error_message'] = "Election ID is required.";
    redirectTo('admin/results/');
}

// Get election details with comprehensive data
$stmt = $db->prepare("
    SELECT e.*, et.name as election_type_name, 
           u.first_name as created_by_first_name, u.last_name as created_by_last_name,
           (SELECT COUNT(*) FROM students WHERE is_active = 1 AND is_verified = 1) as total_eligible_students,
           COUNT(DISTINCT vs.student_id) as total_participants,
           COUNT(v.vote_id) as total_votes_cast,
           MIN(vs.started_at) as first_vote_time,
           MAX(vs.completed_at) as last_vote_time
    FROM elections e
    LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
    LEFT JOIN users u ON e.created_by = u.user_id
    LEFT JOIN voting_sessions vs ON e.election_id = vs.election_id AND vs.status = 'completed'
    LEFT JOIN votes v ON vs.session_id = v.session_id
    WHERE e.election_id = ?
    GROUP BY e.election_id
");
$stmt->execute([$election_id]);
$election = $stmt->fetch();

if (!$election) {
    $_SESSION['error_message'] = "Election not found.";
    redirectTo('admin/results/');
}

// Get positions with detailed results
$stmt = $db->prepare("
    SELECT p.position_id, p.title, p.description, p.display_order,
           COUNT(DISTINCT c.candidate_id) as candidate_count,
           COUNT(v.vote_id) as total_votes,
           COUNT(av.abstain_id) as abstain_votes,
           COUNT(DISTINCT v.session_id) as voters_count
    FROM positions p
    LEFT JOIN candidates c ON p.position_id = c.position_id
    LEFT JOIN votes v ON c.candidate_id = v.candidate_id
    LEFT JOIN abstain_votes av ON p.position_id = av.position_id
    WHERE p.election_id = ?
    GROUP BY p.position_id
    ORDER BY p.display_order
");
$stmt->execute([$election_id]);
$positions = $stmt->fetchAll();

// Get detailed results for each position
$results = [];
foreach ($positions as $position) {
    $stmt = $db->prepare("
        SELECT c.candidate_id, c.student_id,
               s.first_name, s.last_name, s.student_number, s.photo_url, s.program_id,
               p.program_name,
               COUNT(v.vote_id) as vote_count
        FROM candidates c
        JOIN students s ON c.student_id = s.student_id
        JOIN programs p ON s.program_id = p.program_id
        LEFT JOIN votes v ON c.candidate_id = v.candidate_id
        WHERE c.position_id = ?
        GROUP BY c.candidate_id
        ORDER BY vote_count DESC, s.last_name ASC
    ");
    $stmt->execute([$position['position_id']]);
    $candidates = $stmt->fetchAll();
    
    // Calculate percentages and determine winners
    $total_votes = array_sum(array_column($candidates, 'vote_count'));
    $highest_votes = $candidates ? $candidates[0]['vote_count'] : 0;
    
    foreach ($candidates as &$candidate) {
        $candidate['percentage'] = $total_votes > 0 ? round(($candidate['vote_count'] / $total_votes) * 100, 2) : 0;
        $candidate['is_winner'] = ($candidate['vote_count'] == $highest_votes && $highest_votes > 0);
    }
    
    $results[$position['position_id']] = [
        'position' => $position,
        'candidates' => $candidates,
        'total_votes' => $total_votes,
        'abstain_votes' => $position['abstain_votes'],
        'participation_rate' => $election['total_eligible_students'] > 0 
            ? round((($total_votes + $position['abstain_votes']) / $election['total_eligible_students']) * 100, 2)
            : 0
    ];
}

// Get voting patterns and analytics
$stmt = $db->prepare("
    SELECT DATE(vs.started_at) as vote_date, 
           COUNT(DISTINCT vs.student_id) as daily_voters,
           COUNT(v.vote_id) as daily_votes
    FROM voting_sessions vs
    LEFT JOIN votes v ON vs.session_id = v.session_id
    WHERE vs.election_id = ? AND vs.status = 'completed'
    GROUP BY DATE(vs.started_at)
    ORDER BY vote_date
");
$stmt->execute([$election_id]);
$daily_patterns = $stmt->fetchAll();

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
$program_turnout = $stmt->fetchAll();

$page_title = "Election Results: " . $election['name'];
include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><i class="fas fa-poll"></i> <?= sanitize($election['name']) ?></h2>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../">Admin</a></li>
                            <li class="breadcrumb-item"><a href="./">Results</a></li>
                            <li class="breadcrumb-item active">View Results</li>
                        </ol>
                    </nav>
                </div>
                <div class="btn-group">
                    <a href="./" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Results
                    </a>
                    <button onclick="window.print()" class="btn btn-info">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <div class="dropdown">
                        <button class="btn btn-success dropdown-toggle" type="button" data-toggle="dropdown">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="export.php?election_id=<?= $election_id ?>&format=pdf">
                                <i class="fas fa-file-pdf"></i> PDF Report
                            </a>
                            <a class="dropdown-item" href="export.php?election_id=<?= $election_id ?>&format=excel">
                                <i class="fas fa-file-excel"></i> Excel Report
                            </a>
                            <a class="dropdown-item" href="export.php?election_id=<?= $election_id ?>&format=csv">
                                <i class="fas fa-file-csv"></i> CSV Data
                            </a>
                        </div>
                    </div>
                    <?php if (hasPermission('publish_results') && !$election['results_published_at']): ?>
                        <button onclick="publishResults()" class="btn btn-primary">
                            <i class="fas fa-share"></i> Publish Results
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Election Overview Card -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card border-primary">
                        <div class="card-header bg-primary text-white">
                            <h4 class="mb-0">Election Overview</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="30%">Election Type:</th>
                                            <td><?= sanitize($election['election_type_name']) ?></td>
                                        </tr>
                                        <tr>
                                            <th>Status:</th>
                                            <td>
                                                <span class="badge badge-<?= $election['status'] == 'completed' ? 'success' : 'info' ?> p-2">
                                                    <?= ucfirst($election['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Duration:</th>
                                            <td>
                                                <?= date('M j, Y g:i A', strtotime($election['start_date'])) ?><br>
                                                <small class="text-muted">to</small><br>
                                                <?= date('M j, Y g:i A', strtotime($election['end_date'])) ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Created by:</th>
                                            <td><?= sanitize($election['created_by_first_name'] . ' ' . $election['created_by_last_name']) ?></td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <div class="text-center">
                                        <h5>Voter Turnout</h5>
                                        <?php 
                                        $turnout_rate = $election['total_eligible_students'] > 0 
                                            ? round(($election['total_participants'] / $election['total_eligible_students']) * 100, 1) 
                                            : 0;
                                        ?>
                                        <div class="progress mb-3" style="height: 40px;">
                                            <div class="progress-bar bg-success progress-bar-striped" 
                                                 role="progressbar" 
                                                 style="width: <?= $turnout_rate ?>%"
                                                 aria-valuenow="<?= $turnout_rate ?>" 
                                                 aria-valuemin="0" aria-valuemax="100">
                                                <strong><?= $turnout_rate ?>%</strong>
                                            </div>
                                        </div>
                                        <p class="mb-2">
                                            <strong><?= $election['total_participants'] ?></strong> of 
                                            <strong><?= $election['total_eligible_students'] ?></strong> eligible voters participated
                                        </p>
                                        <p class="text-muted">
                                            <small>
                                                Total votes cast: <strong><?= $election['total_votes_cast'] ?></strong><br>
                                                Voting period: 
                                                <?php if ($election['first_vote_time']): ?>
                                                    <?= date('g:i A', strtotime($election['first_vote_time'])) ?> - 
                                                    <?= date('g:i A', strtotime($election['last_vote_time'])) ?>
                                                <?php else: ?>
                                                    No votes cast
                                                <?php endif; ?>
                                            </small>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($election['results_published_at']): ?>
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="alert alert-success mt-3">
                                            <i class="fas fa-eye"></i> 
                                            <strong>Results Published:</strong> 
                                            <?= date('M j, Y g:i A', strtotime($election['results_published_at'])) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Results by Position -->
            <?php if (empty($results)): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-2x mb-3"></i>
                    <h5>No Positions Found</h5>
                    <p>This election does not have any positions configured.</p>
                </div>
            <?php else: ?>
                <?php foreach ($results as $position_id => $result): ?>
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h5 class="mb-0">
                                                <i class="fas fa-trophy text-warning"></i>
                                                <?= sanitize($result['position']['title']) ?>
                                            </h5>
                                            <?php if ($result['position']['description']): ?>
                                                <small class="text-muted"><?= sanitize($result['position']['description']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-right">
                                            <span class="badge badge-primary">
                                                <?= count($result['candidates']) ?> Candidates
                                            </span>
                                            <span class="badge badge-success">
                                                <?= $result['total_votes'] ?> Votes
                                            </span>
                                            <span class="badge badge-warning">
                                                <?= $result['abstain_votes'] ?> Abstains
                                            </span>
                                            <br>
                                            <small class="text-muted">
                                                <?= $result['participation_rate'] ?>% participation
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($result['candidates'])): ?>
                                        <div class="text-center py-3 text-muted">
                                            <i class="fas fa-user-slash fa-2x mb-2"></i>
                                            <p>No candidates for this position</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="row">
                                            <?php foreach ($result['candidates'] as $index => $candidate): ?>
                                                <div class="col-md-4 mb-3">
                                                    <div class="card <?= $candidate['is_winner'] ? 'border-success' : 'border-light' ?> h-100">
                                                        <div class="card-body text-center">
                                                            <!-- Winner Crown -->
                                                            <?php if ($candidate['is_winner']): ?>
                                                                <div class="winner-crown mb-2">
                                                                    <i class="fas fa-crown text-warning fa-2x"></i>
                                                                    <br>
                                                                    <span class="badge badge-success mt-1">WINNER</span>
                                                                </div>
                                                            <?php endif; ?>
                                                            
                                                            <!-- Candidate Photo -->
                                                            <?php if ($candidate['photo_url']): ?>
                                                                <img src="../../<?= sanitize($candidate['photo_url']) ?>" 
                                                                     class="candidate-photo mb-3" 
                                                                     alt="<?= sanitize($candidate['first_name'] . ' ' . $candidate['last_name']) ?>">
                                                            <?php else: ?>
                                                                <div class="candidate-placeholder mb-3">
                                                                    <i class="fas fa-user fa-3x text-muted"></i>
                                                                </div>
                                                            <?php endif; ?>
                                                            
                                                            <!-- Candidate Info -->
                                                            <h6 class="card-title">
                                                                <?= sanitize($candidate['first_name'] . ' ' . $candidate['last_name']) ?>
                                                            </h6>
                                                            <p class="card-text">
                                                                <small class="text-muted">
                                                                    <?= sanitize($candidate['student_number']) ?><br>
                                                                    <?= sanitize($candidate['program_name']) ?>
                                                                </small>
                                                            </p>
                                                            
                                                            <!-- Vote Statistics -->
                                                            <div class="vote-stats">
                                                                <h4 class="text-primary mb-2">
                                                                    <?= $candidate['vote_count'] ?>
                                                                    <small class="text-muted">votes</small>
                                                                </h4>
                                                                <h5 class="text-secondary mb-2">
                                                                    <?= $candidate['percentage'] ?>%
                                                                </h5>
                                                                
                                                                <!-- Vote Bar -->
                                                                <div class="progress mb-2">
                                                                    <div class="progress-bar <?= $candidate['is_winner'] ? 'bg-success' : 'bg-primary' ?>" 
                                                                         role="progressbar" 
                                                                         style="width: <?= $candidate['percentage'] ?>%">
                                                                    </div>
                                                                </div>
                                                                
                                                                <!-- Position Badge -->
                                                                <span class="badge badge-<?= $index === 0 ? 'success' : 'secondary' ?>">
                                                                    #<?= $index + 1 ?> Position
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Analytics Section -->
            <div class="row mb-4">
                <!-- Voting Patterns -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-chart-line"></i> Voting Patterns</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($daily_patterns)): ?>
                                <canvas id="votingPatternsChart" height="100"></canvas>
                            <?php else: ?>
                                <p class="text-center text-muted">No voting data available</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Program Turnout -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-graduation-cap"></i> Program Turnout</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($program_turnout)): ?>
                                <?php foreach ($program_turnout as $program): ?>
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <small><strong><?= sanitize($program['program_name']) ?></strong></small>
                                            <small class="text-muted"><?= $program['turnout_rate'] ?>%</small>
                                        </div>
                                        <div class="progress" style="height: 15px;">
                                            <div class="progress-bar bg-info" 
                                                 style="width: <?= $program['turnout_rate'] ?>%">
                                            </div>
                                        </div>
                                        <small class="text-muted">
                                            <?= $program['voted_students'] ?>/<?= $program['total_students'] ?> students
                                        </small>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-center text-muted">No program data available</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Summary Footer -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card bg-light">
                        <div class="card-body text-center">
                            <p class="mb-0 text-muted">
                                <small>
                                    <i class="fas fa-info-circle"></i>
                                    Results generated on <?= date('M j, Y g:i A') ?> • 
                                    Election conducted from <?= date('M j, Y', strtotime($election['start_date'])) ?> 
                                    to <?= date('M j, Y', strtotime($election['end_date'])) ?> •
                                    Total positions: <?= count($results) ?> • 
                                    Total candidates: <?= array_sum(array_map(function($r) { return count($r['candidates']); }, $results)) ?>
                                </small>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Publish Results Modal -->
<div class="modal fade" id="publishModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Publish Election Results</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to publish the results for <strong><?= sanitize($election['name']) ?></strong>?</p>
                <p class="text-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Warning:</strong> Published results will be visible to all users and cannot be unpublished.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="confirmPublish">
                    <i class="fas fa-share"></i> Publish Results
                </button>
            </div>
        </div>
    </div>
</div>

<script src="<?= SITE_URL ?>/assets/js/chart.min.js"></script>
<script>
// Voting Patterns Chart
<?php if (!empty($daily_patterns)): ?>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('votingPatternsChart').getContext('2d');
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode(array_column($daily_patterns, 'vote_date')) ?>,
            datasets: [{
                label: 'Daily Voters',
                data: <?= json_encode(array_column($daily_patterns, 'daily_voters')) ?>,
                borderColor: 'rgb(54, 162, 235)',
                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                tension: 0.1,
                fill: true
            }, {
                label: 'Daily Votes',
                data: <?= json_encode(array_column($daily_patterns, 'daily_votes')) ?>,
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                tension: 0.1,
                fill: true
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Daily Voting Activity'
                },
                legend: {
                    position: 'top'
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
});
<?php endif; ?>

function publishResults() {
    $('#publishModal').modal('show');
}

$('#confirmPublish').click(function() {
    // Create form and submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'publish.php';
    
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'election_id';
    input.value = <?= $election_id ?>;
    
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
});
</script>

<style>
.candidate-photo {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #ddd;
}

.candidate-placeholder {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    border: 3px solid #ddd;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    background-color: #f8f9fa;
}

.winner-crown {
    animation: bounce 2s infinite;
}

@keyframes bounce {
    0%, 20%, 60%, 100% {
        transform: translateY(0);
    }
    40% {
        transform: translateY(-10px);
    }
    80% {
        transform: translateY(-5px);
    }
}

.card {
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border: none;
}

.border-success {
    border: 2px solid #28a745 !important;
}

.vote-stats h4, .vote-stats h5 {
    margin: 0;
}

.progress {
    height: 8px;
    border-radius: 4px;
}

@media print {
    .btn, .btn-group, .dropdown {
        display: none !important;
    }
    
    .card {
        box-shadow: none;
        border: 1px solid #ddd !important;
    }
    
    .winner-crown {
        animation: none;
    }
}

@media (max-width: 768px) {
    .btn-group {
        flex-direction: column;
    }
    
    .btn-group .btn {
        margin-bottom: 0.25rem;
    }
    
    .candidate-photo, .candidate-placeholder {
        width: 80px;
        height: 80px;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>