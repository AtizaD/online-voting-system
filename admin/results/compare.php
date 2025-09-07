<?php
require_once '../../config/config.php';
require_once '../../config/database.php';

if (!isLoggedIn() || (!hasPermission('view_results') && !hasPermission('manage_voting'))) {
    redirectTo('auth/index.php');
}

$db = Database::getInstance()->getConnection();
$current_user = getCurrentUser();

// Get selected elections for comparison
$selected_elections = [];
if (isset($_GET['elections']) && is_array($_GET['elections'])) {
    $selected_elections = array_map('intval', $_GET['elections']);
    $selected_elections = array_filter($selected_elections);
}

// Get all elections for selection
$stmt = $db->query("
    SELECT e.election_id, e.name, e.status, e.start_date, e.end_date,
           et.name as election_type_name,
           COUNT(DISTINCT vs.student_id) as total_voters,
           COUNT(v.vote_id) as total_votes,
           (SELECT COUNT(*) FROM positions WHERE election_id = e.election_id) as total_positions
    FROM elections e
    LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
    LEFT JOIN voting_sessions vs ON e.election_id = vs.election_id AND vs.status = 'completed'
    LEFT JOIN votes v ON vs.session_id = v.session_id
    GROUP BY e.election_id
    ORDER BY e.start_date DESC
");
$all_elections = $stmt->fetchAll();

// Get comparison data if elections are selected
$comparison_data = [];
if (!empty($selected_elections)) {
    foreach ($selected_elections as $election_id) {
        $stmt = $db->prepare("
            SELECT e.*, et.name as election_type_name,
                   COUNT(DISTINCT vs.student_id) as total_voters,
                   COUNT(v.vote_id) as total_votes,
                   (SELECT COUNT(*) FROM students WHERE is_active = 1 AND is_verified = 1) as total_eligible,
                   (SELECT COUNT(*) FROM positions WHERE election_id = e.election_id) as total_positions,
                   (SELECT COUNT(*) FROM candidates WHERE election_id = e.election_id) as total_candidates,
                   MIN(vs.started_at) as first_vote,
                   MAX(vs.completed_at) as last_vote,
                   AVG(vs.votes_cast) as avg_votes_per_session
            FROM elections e
            LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
            LEFT JOIN voting_sessions vs ON e.election_id = vs.election_id AND vs.status = 'completed'
            LEFT JOIN votes v ON vs.session_id = v.session_id
            WHERE e.election_id = ?
            GROUP BY e.election_id
        ");
        $stmt->execute([$election_id]);
        $election_data = $stmt->fetch();
        
        if ($election_data) {
            // Get turnout by program for this election
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
                ORDER BY p.program_name
            ");
            $stmt->execute([$election_id]);
            $program_turnout = $stmt->fetchAll();
            
            // Get position-wise statistics
            $stmt = $db->prepare("
                SELECT p.title as position_title,
                       COUNT(DISTINCT c.candidate_id) as candidate_count,
                       COUNT(v.vote_id) as total_votes,
                       COUNT(av.abstain_id) as abstain_votes
                FROM positions p
                LEFT JOIN candidates c ON p.position_id = c.position_id
                LEFT JOIN votes v ON c.candidate_id = v.candidate_id
                LEFT JOIN abstain_votes av ON p.position_id = av.position_id
                WHERE p.election_id = ?
                GROUP BY p.position_id
                ORDER BY p.display_order
            ");
            $stmt->execute([$election_id]);
            $position_stats = $stmt->fetchAll();
            
            $comparison_data[$election_id] = [
                'election' => $election_data,
                'program_turnout' => $program_turnout,
                'position_stats' => $position_stats
            ];
        }
    }
}

$page_title = "Compare Election Results";
include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-balance-scale"></i> Compare Election Results</h2>
                <div class="btn-group">
                    <a href="./" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Results
                    </a>
                    <?php if (!empty($comparison_data)): ?>
                        <button onclick="exportComparison()" class="btn btn-success">
                            <i class="fas fa-download"></i> Export Comparison
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Election Selection -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-check-square"></i> Select Elections to Compare</h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" id="comparisonForm">
                                <div class="row">
                                    <?php foreach ($all_elections as $election): ?>
                                        <div class="col-md-4 mb-3">
                                            <div class="card border-<?= in_array($election['election_id'], $selected_elections) ? 'primary' : 'light' ?>">
                                                <div class="card-body">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" 
                                                               name="elections[]" value="<?= $election['election_id'] ?>"
                                                               id="election_<?= $election['election_id'] ?>"
                                                               <?= in_array($election['election_id'], $selected_elections) ? 'checked' : '' ?>
                                                               onchange="document.getElementById('comparisonForm').submit()">
                                                        <label class="form-check-label" for="election_<?= $election['election_id'] ?>">
                                                            <strong><?= sanitize($election['name']) ?></strong>
                                                        </label>
                                                    </div>
                                                    <small class="text-muted d-block mt-2">
                                                        <?= sanitize($election['election_type_name']) ?><br>
                                                        <?= date('M j, Y', strtotime($election['start_date'])) ?><br>
                                                        Status: <?= ucfirst($election['status']) ?><br>
                                                        Voters: <?= $election['total_voters'] ?> | Positions: <?= $election['total_positions'] ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <?php if (count($selected_elections) > 1): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i>
                                        Comparing <?= count($selected_elections) ?> elections. Select/deselect elections above to update the comparison.
                                    </div>
                                <?php elseif (count($selected_elections) === 1): ?>
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        Please select at least 2 elections to compare.
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (count($selected_elections) >= 2): ?>
                <!-- Comparison Overview -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Overview Comparison</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Metric</th>
                                                <?php foreach ($comparison_data as $data): ?>
                                                    <th class="text-center">
                                                        <?= sanitize($data['election']['name']) ?>
                                                        <br>
                                                        <small class="text-muted"><?= date('M j, Y', strtotime($data['election']['start_date'])) ?></small>
                                                    </th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td><strong>Election Type</strong></td>
                                                <?php foreach ($comparison_data as $data): ?>
                                                    <td class="text-center"><?= sanitize($data['election']['election_type_name']) ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <tr>
                                                <td><strong>Status</strong></td>
                                                <?php foreach ($comparison_data as $data): ?>
                                                    <td class="text-center">
                                                        <span class="badge badge-<?= $data['election']['status'] == 'completed' ? 'success' : 'info' ?>">
                                                            <?= ucfirst($data['election']['status']) ?>
                                                        </span>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <tr>
                                                <td><strong>Total Eligible Voters</strong></td>
                                                <?php foreach ($comparison_data as $data): ?>
                                                    <td class="text-center"><?= number_format($data['election']['total_eligible']) ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <tr>
                                                <td><strong>Voters Participated</strong></td>
                                                <?php foreach ($comparison_data as $data): ?>
                                                    <td class="text-center">
                                                        <?= number_format($data['election']['total_voters']) ?>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?= $data['election']['total_eligible'] > 0 
                                                                ? round(($data['election']['total_voters'] / $data['election']['total_eligible']) * 100, 1) 
                                                                : 0 ?>% turnout
                                                        </small>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <tr>
                                                <td><strong>Total Votes Cast</strong></td>
                                                <?php foreach ($comparison_data as $data): ?>
                                                    <td class="text-center"><?= number_format($data['election']['total_votes']) ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <tr>
                                                <td><strong>Positions</strong></td>
                                                <?php foreach ($comparison_data as $data): ?>
                                                    <td class="text-center"><?= $data['election']['total_positions'] ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <tr>
                                                <td><strong>Total Candidates</strong></td>
                                                <?php foreach ($comparison_data as $data): ?>
                                                    <td class="text-center"><?= $data['election']['total_candidates'] ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <tr>
                                                <td><strong>Avg Votes per Session</strong></td>
                                                <?php foreach ($comparison_data as $data): ?>
                                                    <td class="text-center"><?= round($data['election']['avg_votes_per_session'], 1) ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <tr>
                                                <td><strong>Voting Duration</strong></td>
                                                <?php foreach ($comparison_data as $data): ?>
                                                    <td class="text-center">
                                                        <?php if ($data['election']['first_vote'] && $data['election']['last_vote']): ?>
                                                            <?php
                                                            $duration = strtotime($data['election']['last_vote']) - strtotime($data['election']['first_vote']);
                                                            $hours = floor($duration / 3600);
                                                            $minutes = floor(($duration % 3600) / 60);
                                                            ?>
                                                            <?= $hours ?>h <?= $minutes ?>m
                                                        <?php else: ?>
                                                            <span class="text-muted">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Turnout by Program Comparison -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-graduation-cap"></i> Program Turnout Comparison</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="turnoutComparisonChart" height="100"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Position Statistics Comparison -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-users"></i> Position Statistics</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php foreach ($comparison_data as $election_id => $data): ?>
                                        <div class="col-md-6 mb-4">
                                            <h6><?= sanitize($data['election']['name']) ?></h6>
                                            <?php if (!empty($data['position_stats'])): ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm">
                                                        <thead>
                                                            <tr>
                                                                <th>Position</th>
                                                                <th>Candidates</th>
                                                                <th>Votes</th>
                                                                <th>Abstains</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($data['position_stats'] as $position): ?>
                                                                <tr>
                                                                    <td><?= sanitize($position['position_title']) ?></td>
                                                                    <td><span class="badge badge-info"><?= $position['candidate_count'] ?></span></td>
                                                                    <td><span class="badge badge-success"><?= $position['total_votes'] ?></span></td>
                                                                    <td><span class="badge badge-warning"><?= $position['abstain_votes'] ?></span></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php else: ?>
                                                <p class="text-muted">No position data available</p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Insights -->
                <div class="row">
                    <div class="col-md-12">
                        <div class="card bg-light">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-lightbulb"></i> Comparison Insights</h5>
                            </div>
                            <div class="card-body">
                                <?php
                                // Calculate insights
                                $turnouts = array_map(function($data) {
                                    return $data['election']['total_eligible'] > 0 
                                        ? round(($data['election']['total_voters'] / $data['election']['total_eligible']) * 100, 1)
                                        : 0;
                                }, $comparison_data);
                                
                                $highest_turnout = max($turnouts);
                                $lowest_turnout = min($turnouts);
                                $avg_turnout = round(array_sum($turnouts) / count($turnouts), 1);
                                
                                $total_votes = array_sum(array_column(array_column($comparison_data, 'election'), 'total_votes'));
                                $total_voters = array_sum(array_column(array_column($comparison_data, 'election'), 'total_voters'));
                                ?>
                                
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <h4 class="text-success"><?= $highest_turnout ?>%</h4>
                                            <p class="mb-0"><small>Highest Turnout</small></p>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <h4 class="text-warning"><?= $lowest_turnout ?>%</h4>
                                            <p class="mb-0"><small>Lowest Turnout</small></p>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <h4 class="text-info"><?= $avg_turnout ?>%</h4>
                                            <p class="mb-0"><small>Average Turnout</small></p>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <h4 class="text-primary"><?= number_format($total_voters) ?></h4>
                                            <p class="mb-0"><small>Total Unique Voters</small></p>
                                        </div>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <div class="alert alert-info">
                                    <h6><i class="fas fa-chart-line"></i> Key Observations:</h6>
                                    <ul class="mb-0">
                                        <li>Turnout variation: <?= $highest_turnout - $lowest_turnout ?>% difference between highest and lowest</li>
                                        <li>Total votes cast across all elections: <?= number_format($total_votes) ?></li>
                                        <li>Elections compared: <?= count($comparison_data) ?></li>
                                        <li>Date range: 
                                            <?php 
                                            $dates = array_column(array_column($comparison_data, 'election'), 'start_date');
                                            echo date('M j, Y', strtotime(min($dates))) . ' to ' . date('M j, Y', strtotime(max($dates)));
                                            ?>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif (empty($selected_elections)): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-2x mb-3"></i>
                    <h5>Select Elections to Compare</h5>
                    <p>Choose 2 or more elections from the list above to start comparing their results.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="<?= SITE_URL ?>/assets/js/chart.min.js"></script>
<script>
<?php if (count($selected_elections) >= 2): ?>
// Prepare data for program turnout comparison chart
const programData = {};
const electionNames = [];
const colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40'];

<?php foreach ($comparison_data as $election_id => $data): ?>
electionNames.push('<?= addslashes($data['election']['name']) ?>');
<?php foreach ($data['program_turnout'] as $program): ?>
if (!programData['<?= addslashes($program['program_name']) ?>']) {
    programData['<?= addslashes($program['program_name']) ?>'] = [];
}
programData['<?= addslashes($program['program_name']) ?>'].push(<?= $program['turnout_rate'] ?>);
<?php endforeach; ?>
<?php endforeach; ?>

// Create turnout comparison chart
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('turnoutComparisonChart').getContext('2d');
    
    const datasets = [];
    let colorIndex = 0;
    
    for (const program in programData) {
        datasets.push({
            label: program,
            data: programData[program],
            backgroundColor: colors[colorIndex % colors.length],
            borderColor: colors[colorIndex % colors.length],
            borderWidth: 1
        });
        colorIndex++;
    }
    
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: electionNames,
            datasets: datasets
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Program Turnout Rates Comparison'
                },
                legend: {
                    position: 'top'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    title: {
                        display: true,
                        text: 'Turnout Rate (%)'
                    }
                }
            }
        }
    });
});
<?php endif; ?>

function exportComparison() {
    const elections = <?= json_encode($selected_elections) ?>;
    const params = elections.map(id => 'elections[]=' + id).join('&');
    window.open('export_comparison.php?' + params, '_blank');
}
</script>

<style>
.card {
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border: none;
}

.form-check-input:checked + .form-check-label {
    font-weight: bold;
    color: #007bff;
}

.table th {
    border-top: none;
}

#turnoutComparisonChart {
    max-height: 400px;
}

@media print {
    .btn, .btn-group, .form-check {
        display: none !important;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>