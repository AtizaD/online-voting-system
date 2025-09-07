<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Require student authentication
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'student') {
    header('Location: ' . SITE_URL . '/?error=Access denied');
    exit;
}

$db = Database::getInstance()->getConnection();

// Get student info
$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'];
$student_number = $_SESSION['student_number'];

// Get active elections - respects both status and timing
$stmt = $db->prepare("
    SELECT e.election_id, e.name as title, e.description, e.start_date, e.end_date,
           CASE 
               WHEN e.status = 'cancelled' THEN 'cancelled'
               WHEN e.status = 'draft' THEN 'draft'
               WHEN e.status = 'completed' THEN 'completed'
               WHEN e.status = 'active' AND NOW() < e.start_date THEN 'upcoming'
               WHEN e.status = 'active' AND NOW() > e.end_date THEN 'ended'
               WHEN e.status = 'active' AND NOW() >= e.start_date AND NOW() <= e.end_date THEN 'active'
               WHEN e.status = 'scheduled' AND NOW() < e.start_date THEN 'upcoming'
               WHEN e.status = 'scheduled' AND NOW() >= e.start_date AND NOW() <= e.end_date THEN 'active'
               WHEN e.status = 'scheduled' AND NOW() > e.end_date THEN 'ended'
               ELSE e.status
           END as election_status
    FROM elections e
    WHERE e.status IN ('active', 'scheduled', 'completed')
    ORDER BY e.start_date ASC
");
$stmt->execute();
$elections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if student has voted
$voted_elections = [];
if (!empty($elections)) {
    $election_ids = array_column($elections, 'election_id');
    $placeholders = str_repeat('?,', count($election_ids) - 1) . '?';
    
    $stmt = $db->prepare("
        SELECT vs.election_id 
        FROM voting_sessions vs
        WHERE vs.student_id = ? AND vs.election_id IN ($placeholders) AND vs.status = 'completed'
    ");
    $stmt->execute(array_merge([$student_id], $election_ids));
    $voted_elections = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'election_id');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - <?= SITE_NAME ?></title>
    <link href="<?= SITE_URL ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= SITE_URL ?>/assets/css/font-awesome.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4f46e5;
            --success-color: #059669;
            --warning-color: #d97706;
            --danger-color: #dc2626;
        }
        
        .hero-section {
            background: linear-gradient(135deg, var(--primary-color), #7c3aed);
            color: white;
            padding: 2rem 0;
        }
        
        .election-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .election-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .status-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            z-index: 2;
        }
        
        .btn-vote {
            background: var(--success-color);
            border: none;
            color: white;
        }
        
        .btn-vote:hover {
            background: #047857;
            color: white;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-vote-yea me-2"></i><?= SITE_NAME ?>
            </a>
            
            <div class="navbar-nav ms-auto">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i><?= $student_name ?>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= SITE_URL ?>/auth/logout">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="hero-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="display-5 fw-bold">Welcome, <?= $student_name ?>!</h1>
                    <p class="lead">Student Number: <?= $student_number ?></p>
                    <p class="mb-0">Participate in active elections and make your voice heard.</p>
                </div>
                <div class="col-md-4 text-center">
                    <i class="fas fa-vote-yea fa-5x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container my-5">
        <div class="row">
            <div class="col-12">
                <h2 class="mb-4">
                    <i class="fas fa-ballot me-2"></i>Elections
                </h2>
                
                <?php if (empty($elections)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        No elections are currently available.
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($elections as $election): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="card election-card h-100 position-relative">
                                    <!-- Status Badge -->
                                    <?php
                                    $badge_class = 'bg-secondary';
                                    $badge_text = $election['election_status'];
                                    if ($election['election_status'] === 'active') {
                                        $badge_class = 'bg-success';
                                        $badge_text = 'Active';
                                    } elseif ($election['election_status'] === 'upcoming') {
                                        $badge_class = 'bg-warning';
                                        $badge_text = 'Upcoming';
                                    } elseif ($election['election_status'] === 'ended') {
                                        $badge_class = 'bg-danger';
                                        $badge_text = 'Ended';
                                    }
                                    
                                    $has_voted = in_array($election['election_id'], $voted_elections);
                                    if ($has_voted) {
                                        $badge_class = 'bg-info';
                                        $badge_text = 'Voted';
                                    }
                                    ?>
                                    <span class="badge <?= $badge_class ?> status-badge"><?= $badge_text ?></span>
                                    
                                    <div class="card-header bg-light">
                                        <h5 class="card-title mb-0">
                                            <i class="fas fa-poll me-2"></i><?= htmlspecialchars($election['title']) ?>
                                        </h5>
                                    </div>
                                    
                                    <div class="card-body">
                                        <p class="card-text"><?= htmlspecialchars($election['description']) ?></p>
                                        
                                        <div class="mb-3">
                                            <small class="text-muted">
                                                <i class="fas fa-calendar me-1"></i>
                                                <?= date('M d, Y g:i A', strtotime($election['start_date'])) ?>
                                                <br>
                                                <i class="fas fa-calendar-times me-1"></i>
                                                <?= date('M d, Y g:i A', strtotime($election['end_date'])) ?>
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <div class="card-footer bg-transparent">
                                        <?php if ($election['election_status'] === 'active' && !$has_voted): ?>
                                            <a href="voting.php?election_id=<?= $election['election_id'] ?>" 
                                               class="btn btn-vote w-100">
                                                <i class="fas fa-vote-yea me-2"></i>Vote Now
                                            </a>
                                        <?php elseif ($has_voted): ?>
                                            <button class="btn btn-outline-success w-100" disabled>
                                                <i class="fas fa-check-circle me-2"></i>Already Voted
                                            </button>
                                        <?php elseif ($election['election_status'] === 'upcoming'): ?>
                                            <button class="btn btn-outline-warning w-100" disabled>
                                                <i class="fas fa-clock me-2"></i>Not Started Yet
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-outline-secondary w-100" disabled>
                                                <i class="fas fa-times-circle me-2"></i>Election Ended
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="<?= SITE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>