<?php
require_once '../../config/config.php';
require_once '../../config/database.php';

if (!isLoggedIn() || (!hasPermission('monitor_voting') && !hasPermission('manage_voting'))) {
    redirectTo('auth/index.php');
}

$db = Database::getInstance()->getConnection();
$current_user = getCurrentUser();

// Get active elections (including draft for testing)
$stmt = $db->query("
    SELECT e.*, et.name as election_type_name,
           COUNT(DISTINCT vs.student_id) as voters_count,
           (SELECT COUNT(*) FROM students WHERE is_active = 1 AND is_verified = 1) as total_eligible
    FROM elections e 
    LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
    LEFT JOIN voting_sessions vs ON e.election_id = vs.election_id AND vs.status = 'completed'
    WHERE e.status IN ('active', 'scheduled', 'draft')
    GROUP BY e.election_id
    ORDER BY e.start_date DESC
");
$active_elections = $stmt->fetchAll();

// Get voting statistics for today
$today = date('Y-m-d');
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT vs.student_id) as today_voters,
        COUNT(v.vote_id) as today_votes,
        AVG(vs.votes_cast) as avg_votes_per_session
    FROM voting_sessions vs
    LEFT JOIN votes v ON vs.session_id = v.session_id
    WHERE DATE(vs.started_at) = ?
");
$stmt->execute([$today]);
$today_stats = $stmt->fetch();

// Get recent voting activities
$stmt = $db->prepare("
    SELECT vs.*, s.student_number, s.first_name, s.last_name, e.name as election_name
    FROM voting_sessions vs
    JOIN students s ON vs.student_id = s.student_id
    JOIN elections e ON vs.election_id = e.election_id
    WHERE vs.status = 'completed'
    ORDER BY vs.completed_at DESC
    LIMIT 10
");
$stmt->execute();
$recent_activities = $stmt->fetchAll();

$page_title = "Voting Management Dashboard";
include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-vote-yea"></i> Voting Management</h2>
                <div class="btn-group" role="group">
                    <a href="monitor.php" class="btn btn-primary">
                        <i class="fas fa-eye"></i> Live Monitor
                    </a>
                    <a href="results.php" class="btn btn-success">
                        <i class="fas fa-chart-bar"></i> Results
                    </a>
                    <a href="statistics.php" class="btn btn-info">
                        <i class="fas fa-analytics"></i> Statistics
                    </a>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h4><?= count($active_elections) ?></h4>
                                    <p class="mb-0">Active Elections</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-vote-yea fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h4><?= $today_stats['today_voters'] ?? 0 ?></h4>
                                    <p class="mb-0">Voters Today</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-users fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h4><?= $today_stats['today_votes'] ?? 0 ?></h4>
                                    <p class="mb-0">Votes Cast Today</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-check-circle fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h4><?= number_format($today_stats['avg_votes_per_session'] ?? 0, 1) ?></h4>
                                    <p class="mb-0">Avg Votes/Session</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-chart-line fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Active Elections -->
            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-calendar-alt"></i> Active Elections</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($active_elections)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No active elections at this time</p>
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
                                                <th>Turnout</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($active_elections as $election): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= sanitize($election['name']) ?></strong>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?= date('M j, Y g:i A', strtotime($election['start_date'])) ?> - 
                                                            <?= date('M j, Y g:i A', strtotime($election['end_date'])) ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-secondary">
                                                            <?= sanitize($election['election_type_name']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $now = new DateTime();
                                                        $start = new DateTime($election['start_date']);
                                                        $end = new DateTime($election['end_date']);
                                                        
                                                        if ($now < $start) {
                                                            echo '<span class="badge badge-info">Scheduled</span>';
                                                        } elseif ($now >= $start && $now <= $end) {
                                                            echo '<span class="badge badge-success">Active</span>';
                                                        } else {
                                                            echo '<span class="badge badge-secondary">Ended</span>';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $turnout = $election['total_eligible'] > 0 
                                                            ? round(($election['voters_count'] / $election['total_eligible']) * 100, 1) 
                                                            : 0;
                                                        ?>
                                                        <div class="progress" style="height: 20px;">
                                                            <div class="progress-bar" role="progressbar" 
                                                                 style="width: <?= $turnout ?>%"
                                                                 aria-valuenow="<?= $turnout ?>" 
                                                                 aria-valuemin="0" aria-valuemax="100">
                                                                <?= $turnout ?>%
                                                            </div>
                                                        </div>
                                                        <small class="text-muted">
                                                            <?= $election['voters_count'] ?> / <?= $election['total_eligible'] ?> voters
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <a href="monitor.php?election_id=<?= $election['election_id'] ?>" 
                                                               class="btn btn-outline-primary" title="Monitor">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            <a href="results.php?election_id=<?= $election['election_id'] ?>" 
                                                               class="btn btn-outline-success" title="Results">
                                                                <i class="fas fa-chart-bar"></i>
                                                            </a>
                                                            <a href="reset-votes.php?election_id=<?= $election['election_id'] ?>" 
                                                               class="btn btn-outline-danger" title="Reset Votes">
                                                                <i class="fas fa-undo"></i>
                                                            </a>
                                                            <a href="../elections/" 
                                                               class="btn btn-outline-info" title="Manage Elections">
                                                                <i class="fas fa-cog"></i>
                                                            </a>
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

                <!-- Recent Voting Activity -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-history"></i> Recent Voting Activity</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_activities)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-clock fa-2x text-muted mb-3"></i>
                                    <p class="text-muted">No recent voting activity</p>
                                </div>
                            <?php else: ?>
                                <div class="timeline">
                                    <?php foreach ($recent_activities as $activity): ?>
                                        <div class="timeline-item mb-3">
                                            <div class="timeline-marker bg-success"></div>
                                            <div class="timeline-content">
                                                <h6 class="mb-1">
                                                    <?= sanitize($activity['first_name'] . ' ' . $activity['last_name']) ?>
                                                </h6>
                                                <p class="mb-1 text-muted small">
                                                    Voted in <?= sanitize($activity['election_name']) ?>
                                                </p>
                                                <small class="text-muted">
                                                    <?= date('g:i A', strtotime($activity['completed_at'])) ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

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
    box-shadow: 0 0 10px rgba(0,0,0,0.1);
    border: none;
}

.progress {
    border-radius: 10px;
}

.btn-group-sm > .btn {
    padding: 0.25rem 0.5rem;
}
</style>

<?php include '../../includes/footer.php'; ?>