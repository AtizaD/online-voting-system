<?php
require_once '../../config/config.php';
require_once '../../config/database.php';

if (!isLoggedIn() || (!hasPermission('view_results') && !hasPermission('manage_voting'))) {
    redirectTo('auth/index.php');
}

$db = Database::getInstance()->getConnection();
$current_user = getCurrentUser();

// Get elections with results
$stmt = $db->query("
    SELECT e.*, et.name as election_type_name,
           COUNT(DISTINCT vs.student_id) as total_voters,
           COUNT(v.vote_id) as total_votes,
           (SELECT COUNT(*) FROM students WHERE is_active = 1 AND is_verified = 1) as total_eligible,
           (SELECT COUNT(*) FROM positions WHERE election_id = e.election_id) as total_positions,
           MIN(vs.started_at) as voting_started,
           MAX(vs.completed_at) as voting_ended
    FROM elections e 
    LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
    LEFT JOIN voting_sessions vs ON e.election_id = vs.election_id AND vs.status = 'completed'
    LEFT JOIN votes v ON vs.session_id = v.session_id
    GROUP BY e.election_id
    ORDER BY e.start_date DESC
");
$elections = $stmt->fetchAll();

// Get recent result publications
$stmt = $db->query("
    SELECT e.election_id, e.name, e.results_published_at, u.first_name, u.last_name
    FROM elections e
    LEFT JOIN users u ON e.created_by = u.user_id
    WHERE e.results_published_at IS NOT NULL
    ORDER BY e.results_published_at DESC
    LIMIT 10
");
$recent_publications = $stmt->fetchAll();

// Get election statistics summary
$stmt = $db->query("
    SELECT 
        COUNT(*) as total_elections,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_elections,
        SUM(CASE WHEN results_published_at IS NOT NULL THEN 1 ELSE 0 END) as published_results,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_elections
    FROM elections
");
$summary_stats = $stmt->fetch();

$page_title = "Election Results Management";
include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-poll"></i> Election Results Management</h2>
                <div class="btn-group">
                    <a href="../" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Admin
                    </a>
                    <a href="compare.php" class="btn btn-info">
                        <i class="fas fa-balance-scale"></i> Compare Elections
                    </a>
                    <div class="dropdown">
                        <button class="btn btn-success dropdown-toggle" type="button" id="exportDropdown" data-toggle="dropdown">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="export.php?format=pdf">
                                <i class="fas fa-file-pdf"></i> PDF Report
                            </a>
                            <a class="dropdown-item" href="export.php?format=excel">
                                <i class="fas fa-file-excel"></i> Excel Report
                            </a>
                            <a class="dropdown-item" href="export.php?format=csv">
                                <i class="fas fa-file-csv"></i> CSV Data
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Summary Statistics -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body text-center">
                            <h3><?= $summary_stats['total_elections'] ?></h3>
                            <p class="mb-0">Total Elections</p>
                            <small>All time</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <h3><?= $summary_stats['completed_elections'] ?></h3>
                            <p class="mb-0">Completed</p>
                            <small>With voting data</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <h3><?= $summary_stats['published_results'] ?></h3>
                            <p class="mb-0">Published</p>
                            <small>Public results</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body text-center">
                            <h3><?= $summary_stats['active_elections'] ?></h3>
                            <p class="mb-0">Active</p>
                            <small>Currently running</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Elections Results Overview -->
            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Election Results Overview</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($elections)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <h5>No Elections Found</h5>
                                    <p class="text-muted">No elections have been created yet.</p>
                                    <a href="../elections/" class="btn btn-primary">Create Election</a>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Election</th>
                                                <th>Type</th>
                                                <th>Status</th>
                                                <th>Participation</th>
                                                <th>Results</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($elections as $election): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= sanitize($election['name']) ?></strong>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?= date('M j, Y', strtotime($election['start_date'])) ?>
                                                            <?php if ($election['voting_started']): ?>
                                                                <br>Voting: <?= date('g:i A', strtotime($election['voting_started'])) ?>
                                                                <?php if ($election['voting_ended']): ?>
                                                                    - <?= date('g:i A', strtotime($election['voting_ended'])) ?>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-secondary">
                                                            <?= sanitize($election['election_type_name']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $status_class = [
                                                            'draft' => 'secondary',
                                                            'scheduled' => 'info',
                                                            'active' => 'success',
                                                            'completed' => 'primary',
                                                            'cancelled' => 'danger'
                                                        ];
                                                        ?>
                                                        <span class="badge badge-<?= $status_class[$election['status']] ?? 'secondary' ?>">
                                                            <?= ucfirst($election['status']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $turnout = $election['total_eligible'] > 0 
                                                            ? round(($election['total_voters'] / $election['total_eligible']) * 100, 1) 
                                                            : 0;
                                                        ?>
                                                        <div class="progress mb-1" style="height: 15px;">
                                                            <div class="progress-bar" 
                                                                 style="width: <?= $turnout ?>%"
                                                                 title="<?= $turnout ?>% turnout">
                                                            </div>
                                                        </div>
                                                        <small class="text-muted">
                                                            <?= $election['total_voters'] ?>/<?= $election['total_eligible'] ?> 
                                                            (<?= $turnout ?>%)
                                                        </small>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($election['results_published_at']): ?>
                                                            <span class="badge badge-success" title="Published <?= date('M j, Y g:i A', strtotime($election['results_published_at'])) ?>">
                                                                <i class="fas fa-eye"></i> Published
                                                            </span>
                                                        <?php elseif ($election['total_votes'] > 0): ?>
                                                            <span class="badge badge-warning">
                                                                <i class="fas fa-eye-slash"></i> Unpublished
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge badge-light text-muted">
                                                                <i class="fas fa-minus"></i> No Data
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <a href="view.php?id=<?= $election['election_id'] ?>" 
                                                               class="btn btn-outline-primary" title="View Results">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <?php if ($election['total_votes'] > 0): ?>
                                                                <a href="export.php?election_id=<?= $election['election_id'] ?>&format=pdf" 
                                                                   class="btn btn-outline-success" title="Export PDF">
                                                                    <i class="fas fa-file-pdf"></i>
                                                                </a>
                                                                <?php if (hasPermission('publish_results') && !$election['results_published_at']): ?>
                                                                    <button onclick="publishResults(<?= $election['election_id'] ?>)" 
                                                                            class="btn btn-outline-info" title="Publish Results">
                                                                        <i class="fas fa-share"></i>
                                                                    </button>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </div>
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

                <!-- Recent Publications & Quick Stats -->
                <div class="col-md-4">
                    <!-- Recent Publications -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-history"></i> Recent Publications</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_publications)): ?>
                                <p class="text-center text-muted">No results published yet</p>
                            <?php else: ?>
                                <div class="timeline">
                                    <?php foreach ($recent_publications as $pub): ?>
                                        <div class="timeline-item mb-3">
                                            <div class="timeline-marker bg-success"></div>
                                            <div class="timeline-content">
                                                <h6 class="mb-1"><?= sanitize($pub['name']) ?></h6>
                                                <p class="mb-1 text-muted small">
                                                    Published by <?= sanitize($pub['first_name'] . ' ' . $pub['last_name']) ?>
                                                </p>
                                                <small class="text-muted">
                                                    <?= date('M j, g:i A', strtotime($pub['results_published_at'])) ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-bolt"></i> Quick Actions</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="../voting/results.php" class="btn btn-primary btn-block">
                                    <i class="fas fa-chart-line"></i> Live Results Monitor
                                </a>
                                <a href="../elections/" class="btn btn-secondary btn-block">
                                    <i class="fas fa-plus"></i> Create New Election
                                </a>
                                <a href="compare.php" class="btn btn-info btn-block">
                                    <i class="fas fa-balance-scale"></i> Compare Elections
                                </a>
                                <button onclick="generateReport()" class="btn btn-success btn-block">
                                    <i class="fas fa-file-alt"></i> Generate Report
                                </button>
                            </div>
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
                <p>Are you sure you want to publish the results for this election?</p>
                <p class="text-warning"><strong>Warning:</strong> Published results will be visible to all users and cannot be unpublished.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="confirmPublish">Publish Results</button>
            </div>
        </div>
    </div>
</div>

<script>
let selectedElectionId = null;

function publishResults(electionId) {
    selectedElectionId = electionId;
    $('#publishModal').modal('show');
}

$('#confirmPublish').click(function() {
    if (selectedElectionId) {
        // Create form and submit
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'publish.php';
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'election_id';
        input.value = selectedElectionId;
        
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    }
});

function generateReport() {
    // Open report generator in new window
    window.open('export.php?format=summary', '_blank');
}

// Auto-refresh data every 5 minutes for active elections
setInterval(function() {
    const activeElements = document.querySelectorAll('.badge-success');
    if (activeElements.length > 0) {
        location.reload();
    }
}, 300000); // 5 minutes
</script>

<style>
.timeline-item {
    position: relative;
    padding-left: 30px;
}

.timeline-marker {
    position: absolute;
    left: 0;
    top: 5px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
}

.timeline-content {
    padding-left: 10px;
}

.card {
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border: none;
}

.progress {
    background-color: #e9ecef;
}

.btn-group-sm > .btn {
    padding: 0.25rem 0.5rem;
}

.d-grid {
    display: grid;
    gap: 0.5rem;
}

.btn-block {
    width: 100%;
    margin-bottom: 0.5rem;
}

@media (max-width: 768px) {
    .btn-group {
        flex-direction: column;
    }
    
    .btn-group .btn {
        margin-bottom: 0.25rem;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>