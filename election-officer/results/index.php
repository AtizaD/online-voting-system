<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['election_officer']);

$page_title = 'Election Results';
$breadcrumbs = [
    ['title' => 'Election Officer', 'url' => SITE_URL . 'election-officer/'],
    ['title' => 'Results']
];

try {
    $db = Database::getInstance()->getConnection();
    
    // Get elections that have results available (completed elections)
    $stmt = $db->prepare("
        SELECT e.election_id, e.name as election_name, e.status, e.start_date, e.end_date,
               COUNT(DISTINCT c.candidate_id) as total_candidates,
               COUNT(DISTINCT v.vote_id) as total_votes
        FROM elections e
        LEFT JOIN candidates c ON e.election_id = c.election_id
        LEFT JOIN votes v ON e.election_id = v.election_id
        WHERE e.status IN ('active', 'completed')
        GROUP BY e.election_id, e.name, e.status, e.start_date, e.end_date
        ORDER BY e.end_date DESC, e.start_date DESC
    ");
    $stmt->execute();
    $elections = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Results page error: " . $e->getMessage());
    $elections = [];
    $error = "Unable to load election results";
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">
                <i class="fas fa-chart-bar me-2"></i>Election Results
            </h4>
            <small class="text-muted">View and manage election results</small>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?= sanitize($error) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($elections)): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fas fa-chart-bar fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No Election Results Available</h5>
                <p class="text-muted">No completed or active elections found.</p>
                <a href="<?= SITE_URL ?>election-officer/elections/" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>Manage Elections
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($elections as $election): ?>
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <h5 class="card-title mb-0"><?= sanitize($election['election_name']) ?></h5>
                                <span class="badge bg-<?= $election['status'] === 'completed' ? 'success' : ($election['status'] === 'active' ? 'primary' : 'secondary') ?>">
                                    <?= ucfirst($election['status']) ?>
                                </span>
                            </div>
                            
                            <div class="row text-center mb-3">
                                <div class="col-6">
                                    <div class="border-end">
                                        <h6 class="text-primary mb-0"><?= number_format($election['total_candidates']) ?></h6>
                                        <small class="text-muted">Candidates</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <h6 class="text-success mb-0"><?= number_format($election['total_votes']) ?></h6>
                                    <small class="text-muted">Votes Cast</small>
                                </div>
                            </div>
                            
                            <?php if ($election['start_date'] && $election['end_date']): ?>
                                <div class="mb-3">
                                    <small class="text-muted">
                                        <i class="fas fa-calendar me-1"></i>
                                        <?= date('M j, Y', strtotime($election['start_date'])) ?> - 
                                        <?= date('M j, Y', strtotime($election['end_date'])) ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                            
                            <div class="d-flex gap-2">
                                <a href="<?= SITE_URL ?>election-officer/results/view.php?election_id=<?= $election['election_id'] ?>" 
                                   class="btn btn-primary btn-sm flex-fill">
                                    <i class="fas fa-eye me-2"></i>View Results
                                </a>
                                
                                <?php if ($election['status'] === 'active'): ?>
                                    <a href="<?= SITE_URL ?>election-officer/voting/monitor.php?election_id=<?= $election['election_id'] ?>" 
                                       class="btn btn-outline-info btn-sm">
                                        <i class="fas fa-chart-line"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>