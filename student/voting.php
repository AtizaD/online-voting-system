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
$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'];

// Get election ID from URL
$election_id = intval($_GET['election_id'] ?? 0);
if (!$election_id) {
    header('Location: dashboard.php?error=Invalid election');
    exit;
}

// Verify election exists and is active - respects both status and timing
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
    WHERE e.election_id = ? AND e.status IN ('active', 'scheduled')
");
$stmt->execute([$election_id]);
$election = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$election) {
    header('Location: dashboard.php?error=Election not found');
    exit;
}

if ($election['election_status'] !== 'active') {
    header('Location: dashboard.php?error=Election not active');
    exit;
}

// Check if student has already voted
$stmt = $db->prepare("
    SELECT session_id 
    FROM voting_sessions 
    WHERE student_id = ? AND election_id = ? AND status = 'completed'
");
$stmt->execute([$student_id, $election_id]);
if ($stmt->fetch()) {
    header('Location: dashboard.php?error=Already voted in this election');
    exit;
}

// Get positions and candidates for this election
$stmt = $db->prepare("
    SELECT 
        p.position_id,
        p.title as position_title,
        p.description as position_description,
        1 as max_votes,
        p.display_order
    FROM positions p
    WHERE p.election_id = ? AND p.is_active = 1
    ORDER BY p.display_order ASC
");
$stmt->execute([$election_id]);
$positions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($positions)) {
    header('Location: dashboard.php?error=No positions available for voting');
    exit;
}

// Get candidates for each position
$candidates = [];
foreach ($positions as $position) {
    $stmt = $db->prepare("
        SELECT 
            c.candidate_id,
            c.student_id,
            c.photo_url,
            s.first_name,
            s.last_name,
            s.student_number,
            cl.class_name,
            p.program_name
        FROM candidates c
        JOIN students s ON c.student_id = s.student_id
        LEFT JOIN classes cl ON s.class_id = cl.class_id
        LEFT JOIN programs p ON cl.program_id = p.program_id
        WHERE c.position_id = ?
        ORDER BY s.first_name, s.last_name
    ");
    $stmt->execute([$position['position_id']]);
    $candidates[$position['position_id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vote - <?= htmlspecialchars($election['title']) ?> - <?= SITE_NAME ?></title>
    <link href="<?= SITE_URL ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= SITE_URL ?>/assets/css/font-awesome.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4f46e5;
            --success-color: #059669;
            --warning-color: #d97706;
            --danger-color: #dc2626;
        }
        
        .voting-header {
            background: linear-gradient(135deg, var(--primary-color), #7c3aed);
            color: white;
            padding: 0.75rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .voting-header .row {
            align-items: center;
        }
        
        .voting-header h1 {
            font-size: 1.5rem;
            margin-bottom: 0;
            font-weight: 700;
        }
        
        .voting-header .election-dates {
            font-size: 0.85rem;
            opacity: 0.9;
            line-height: 1.2;
        }
        
        .position-container {
            min-height: 70vh;
            display: flex;
            flex-direction: column;
        }
        
        .position-header {
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            text-align: center;
        }
        
        .candidates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
            flex-grow: 1;
        }
        
        /* Mobile responsiveness for voting */
        @media (max-width: 768px) {
            .candidates-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .candidate-card {
                max-width: none;
                width: 100%;
            }
            
            .position-header {
                padding: 0.75rem;
                margin-bottom: 1rem;
            }
            
            .position-header h1 {
                font-size: 1.4rem;
            }
        }
        
        .candidate-card {
            background: white;
            border: 3px solid #e5e7eb;
            border-radius: 16px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .candidate-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-8px);
            box-shadow: 0 12px 30px rgba(79, 70, 229, 0.2);
        }
        
        /* Touch-friendly candidate cards */
        @media (max-width: 768px) {
            .candidate-card:hover {
                transform: none; /* Disable hover animations on mobile */
            }
            
            .candidate-card:active {
                transform: scale(0.98);
                background: #f8fafc;
            }
            
            .candidate-photo {
                width: 100px;
                height: 100px;
            }
            
            .candidate-name {
                font-size: 1.2rem;
            }
            
            .candidate-details {
                font-size: 0.9rem;
            }
            
            .vote-checkbox {
                transform: scale(2.5); /* Larger checkboxes for touch */
                top: 0.75rem;
                right: 0.75rem;
            }
        }
        
        .candidate-card.selected {
            border-color: var(--success-color);
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
        }
        
        .candidate-photo {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid #e5e7eb;
            margin-bottom: 1.5rem;
        }
        
        .candidate-card.selected .candidate-photo {
            border-color: var(--success-color);
        }
        
        .candidate-info {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .candidate-name {
            font-size: 1.4rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 1rem;
            line-height: 1.2;
        }
        
        .candidate-details {
            color: #6b7280;
            font-size: 0.95rem;
            line-height: 1.4;
        }
        
        .candidate-detail-item {
            background: #f8fafc;
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            margin-bottom: 0.3rem;
            display: inline-block;
            font-weight: 500;
        }
        
        .vote-checkbox {
            position: absolute;
            top: 1rem;
            right: 1rem;
            transform: scale(2);
            accent-color: var(--success-color);
        }
        
        .position-nav {
            display: flex;
            justify-content: between;
            align-items: center;
            padding: 1.5rem 0;
            border-top: 2px solid #e5e7eb;
            margin-top: 2rem;
        }
        
        .position-progress {
            text-align: center;
            color: #6b7280;
            font-weight: 500;
        }
        
        .btn-nav {
            padding: 12px 24px;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
            min-height: 44px; /* Touch-friendly minimum */
        }
        
        /* Mobile navigation improvements */
        @media (max-width: 768px) {
            .btn-nav {
                padding: 15px 20px;
                font-size: 0.9rem;
                width: 100%;
                margin-bottom: 0.5rem;
            }
            
            .position-nav {
                padding: 1rem 0;
            }
            
            .position-nav .d-flex {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .position-progress {
                order: -1;
                margin-bottom: 0.5rem;
                font-weight: 600;
            }
            
            .voting-header .row {
                text-align: center;
            }
            
            .voting-header .election-dates {
                margin-top: 0.5rem;
                font-size: 0.8rem;
            }
        }
        
        .btn-prev {
            background: #f3f4f6;
            border: 2px solid #d1d5db;
            color: #374151;
        }
        
        .btn-prev:hover {
            background: #e5e7eb;
            border-color: #9ca3af;
        }
        
        .btn-next, .btn-review {
            background: var(--primary-color);
            border: none;
            color: white;
        }
        
        .btn-next:hover, .btn-review:hover {
            background: #3730a3;
            color: white;
        }
        
        .confirmation-screen {
            display: none;
        }
        
        .selection-summary {
            background: #f8fafc;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .positions-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        /* Mobile responsiveness for confirmation screen */
        @media (max-width: 768px) {
            .positions-row {
                grid-template-columns: 1fr;
                gap: 1rem;
                margin-bottom: 1rem;
            }
            
            .position-selection-group {
                padding: 1rem;
            }
            
            .position-selection-title {
                font-size: 1.1rem;
            }
            
            .selections-grid {
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
                gap: 0.75rem;
            }
            
            .selection-card {
                padding: 0.75rem;
                max-width: none;
            }
            
            .selection-photo {
                width: 50px;
                height: 50px;
                margin-bottom: 0.5rem;
            }
            
            .selection-candidate-name {
                font-size: 0.9rem;
            }
            
            .selection-candidate-details {
                font-size: 0.75rem;
            }
        }
        
        .position-selection-group {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            height: fit-content;
        }
        
        .position-selection-header {
            text-align: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .position-selection-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
        }
        
        .position-candidate-count {
            font-size: 0.8rem;
            color: #6b7280;
        }
        
        .selections-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            justify-items: center;
        }
        
        .selection-card {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            border: 2px solid var(--success-color);
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
            width: 100%;
            max-width: 220px;
            transition: transform 0.3s ease;
        }
        
        .selection-card:hover {
            transform: translateY(-2px);
        }
        
        .selection-photo {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid var(--success-color);
            margin-bottom: 0.75rem;
        }
        
        .selection-candidate-name {
            font-size: 0.95rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.25rem;
            line-height: 1.2;
        }
        
        .selection-candidate-details {
            color: #6b7280;
            font-size: 0.8rem;
            line-height: 1.3;
        }
        
        .btn-submit-vote {
            background: var(--success-color);
            border: none;
            color: white;
            padding: 15px 50px;
            font-size: 1.2rem;
            font-weight: 600;
            border-radius: 8px;
        }
        
        .btn-submit-vote:hover {
            background: #047857;
            color: white;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-arrow-left me-2"></i><?= SITE_NAME ?>
            </a>
            
            <div class="navbar-nav ms-auto">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i><?= $student_name ?>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                        </a></li>
                        <li><a class="dropdown-item" href="logout.php">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- Voting Header -->
    <div class="voting-header">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h1><?= htmlspecialchars($election['title']) ?></h1>
                    <div class="fw-semibold"><?= htmlspecialchars($election['description']) ?></div>
                </div>
                <div class="col-md-6 text-md-end">
                    <div class="election-dates">
                        <div><i class="fas fa-calendar me-1"></i><?= date('M d, Y g:i A', strtotime($election['start_date'])) ?></div>
                        <div><i class="fas fa-calendar-times me-1"></i><?= date('M d, Y g:i A', strtotime($election['end_date'])) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Voting Interface -->
    <div class="container my-5">
        <form id="votingForm" method="POST" action="../api/votes/submit.php">
            <input type="hidden" name="election_id" value="<?= $election_id ?>">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            
            <!-- Position Voting Pages -->
            <div id="votingPages">
                <?php foreach ($positions as $index => $position): ?>
                    <div class="position-page" data-position="<?= $index ?>" style="<?= $index > 0 ? 'display: none;' : '' ?>">
                        <div class="position-container">
                            <!-- Position Header -->
                            <div class="position-header">
                                <h1 class="mb-2 fw-bold text-primary">
                                    <?= htmlspecialchars($position['position_title']) ?>
                                </h1>
                                <div class="alert alert-info d-inline-block mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <?php if ($position['max_votes'] == 1): ?>
                                        Select one candidate
                                    <?php else: ?>
                                        Select up to <?= $position['max_votes'] ?> candidates
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Candidates Grid -->
                            <?php if (empty($candidates[$position['position_id']])): ?>
                                <div class="alert alert-warning text-center">
                                    <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                                    <h4>No Candidates Available</h4>
                                    <p>There are no candidates registered for this position.</p>
                                </div>
                            <?php else: ?>
                                <div class="candidates-grid">
                                    <?php foreach ($candidates[$position['position_id']] as $candidate): ?>
                                        <div class="candidate-card" onclick="selectCandidate(this, <?= $position['position_id'] ?>, <?= $position['max_votes'] ?>)">
                                            <input type="<?= $position['max_votes'] == 1 ? 'radio' : 'checkbox' ?>" 
                                                   name="votes[<?= $position['position_id'] ?>]<?= $position['max_votes'] == 1 ? '' : '[]' ?>" 
                                                   value="<?= $candidate['candidate_id'] ?>" 
                                                   class="vote-checkbox" 
                                                   id="candidate_<?= $candidate['candidate_id'] ?>"
                                                   data-position="<?= $position['position_id'] ?>"
                                                   data-max-votes="<?= $position['max_votes'] ?>">
                                            
                                            <div class="candidate-info">
                                                <?php if ($candidate['photo_url']): ?>
                                                    <img src="<?= $candidate['photo_url'] ?>" 
                                                         alt="<?= htmlspecialchars($candidate['first_name'] . ' ' . $candidate['last_name']) ?>"
                                                         class="candidate-photo">
                                                <?php else: ?>
                                                    <div class="candidate-photo bg-light d-flex align-items-center justify-content-center">
                                                        <i class="fas fa-user fa-3x text-muted"></i>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <div class="candidate-name">
                                                    <?= htmlspecialchars($candidate['first_name'] . ' ' . $candidate['last_name']) ?>
                                                </div>
                                                
                                                <div class="candidate-details">
                                                    <?php if ($candidate['class_name']): ?>
                                                        <div class="candidate-detail-item">
                                                            <i class="fas fa-users me-1"></i>
                                                            <?= htmlspecialchars($candidate['class_name']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($candidate['program_name']): ?>
                                                        <div class="candidate-detail-item">
                                                            <i class="fas fa-graduation-cap me-1"></i>
                                                            <?= htmlspecialchars($candidate['program_name']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Navigation -->
                            <div class="position-nav">
                                <div class="d-flex justify-content-between align-items-center w-100">
                                    <div>
                                        <?php if ($index > 0): ?>
                                            <button type="button" class="btn btn-prev btn-nav" onclick="previousPosition()">
                                                <i class="fas fa-arrow-left me-2"></i>Previous
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-outline-secondary btn-nav" onclick="window.location.href='dashboard.php'">
                                                <i class="fas fa-times me-2"></i>Cancel
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="position-progress">
                                        Position <?= $index + 1 ?> of <?= count($positions) ?>
                                    </div>
                                    
                                    <div>
                                        <?php if ($index < count($positions) - 1): ?>
                                            <button type="button" class="btn btn-next btn-nav" onclick="nextPosition()">
                                                Next<i class="fas fa-arrow-right ms-2"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-review btn-nav" onclick="showConfirmation()">
                                                Review & Submit<i class="fas fa-check ms-2"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Confirmation Screen -->
            <div id="confirmationScreen" class="confirmation-screen">
                <div class="position-container">
                    <div class="position-header">
                        <h2 class="mb-3">
                            <i class="fas fa-clipboard-check me-3 text-success"></i>
                            Review Your Selections
                        </h2>
                        <p class="lead">Please review your choices carefully. Once submitted, your vote cannot be changed.</p>
                    </div>
                    
                    <div class="selection-summary" id="selectionSummary">
                        <!-- Selections will be populated here -->
                    </div>
                    
                    <div class="position-nav">
                        <div class="d-flex justify-content-between align-items-center w-100">
                            <button type="button" class="btn btn-prev btn-nav" onclick="backToVoting()">
                                <i class="fas fa-arrow-left me-2"></i>Back to Voting
                            </button>
                            
                            <div class="text-center">
                                <button type="submit" class="btn btn-submit-vote" id="finalSubmitBtn">
                                    <i class="fas fa-paper-plane me-2"></i>Submit My Vote
                                </button>
                                <div class="mt-2">
                                    <small class="text-muted">
                                        <i class="fas fa-lock me-1"></i>Your vote is secure and anonymous
                                    </small>
                                </div>
                            </div>
                            
                            <div></div> <!-- Spacer for flex layout -->
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script src="<?= SITE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentPosition = 0;
        const totalPositions = <?= count($positions) ?>;
        const positionData = <?= json_encode($positions) ?>;
        const electionId = <?= $election_id ?>;
        const studentId = <?= $student_id ?>;
        
        // Storage key for this specific election and student (to avoid conflicts on shared computers)
        const storageKey = `voting_selections_${electionId}_${studentId}`;
        const positionKey = `voting_position_${electionId}_${studentId}`;
        
        // Load selections and current position from session storage
        function loadSelections() {
            try {
                // Load selections
                const stored = sessionStorage.getItem(storageKey);
                if (stored) {
                    const selections = JSON.parse(stored);
                    
                    // Restore selections
                    Object.keys(selections).forEach(candidateId => {
                        const checkbox = document.getElementById(`candidate_${candidateId}`);
                        if (checkbox) {
                            checkbox.checked = true;
                            const card = checkbox.closest('.candidate-card');
                            if (card) {
                                card.classList.add('selected');
                            }
                        }
                    });
                }
                
                // Load and restore current position
                const storedPosition = sessionStorage.getItem(positionKey);
                if (storedPosition !== null) {
                    const savedPosition = parseInt(storedPosition);
                    if (savedPosition >= 0 && savedPosition < totalPositions) {
                        // Hide all positions
                        document.querySelectorAll('.position-page').forEach(page => {
                            page.style.display = 'none';
                        });
                        
                        // Show the saved position
                        currentPosition = savedPosition;
                        document.querySelector(`[data-position="${currentPosition}"]`).style.display = 'block';
                    }
                }
            } catch (error) {
                console.error('Error loading selections:', error);
            }
        }
        
        // Save current position to session storage
        function saveCurrentPosition() {
            try {
                sessionStorage.setItem(positionKey, currentPosition.toString());
            } catch (error) {
                console.error('Error saving position:', error);
            }
        }
        
        // Save selections to session storage
        function saveSelections() {
            try {
                const selections = {};
                const selectedInputs = document.querySelectorAll('input[type="checkbox"]:checked, input[type="radio"]:checked');
                
                selectedInputs.forEach(input => {
                    selections[input.value] = {
                        positionId: input.dataset.position,
                        candidateId: input.value
                    };
                });
                
                sessionStorage.setItem(storageKey, JSON.stringify(selections));
            } catch (error) {
                console.error('Error saving selections:', error);
            }
        }
        
        function selectCandidate(cardElement, positionId, maxVotes) {
            const checkbox = cardElement.querySelector('input[type="checkbox"], input[type="radio"]');
            const isRadio = checkbox.type === 'radio';
            
            if (isRadio) {
                // For radio buttons, unselect all other candidates in this position
                document.querySelectorAll(`input[data-position="${positionId}"]`).forEach(input => {
                    input.closest('.candidate-card').classList.remove('selected');
                    input.checked = false;
                });
                
                // Select this candidate
                checkbox.checked = true;
                cardElement.classList.add('selected');
            } else {
                // For checkboxes, check if we can select more candidates
                const selectedInPosition = document.querySelectorAll(`input[data-position="${positionId}"]:checked`).length;
                
                if (checkbox.checked) {
                    // Unselect this candidate
                    checkbox.checked = false;
                    cardElement.classList.remove('selected');
                } else if (selectedInPosition < maxVotes) {
                    // Select this candidate
                    checkbox.checked = true;
                    cardElement.classList.add('selected');
                } else {
                    // Show alert for max votes reached
                    alert(`You can only select up to ${maxVotes} candidates for this position.`);
                    return;
                }
            }
            
            // Save selections after any change
            saveSelections();
        }
        
        function nextPosition() {
            if (currentPosition < totalPositions - 1) {
                // Hide current position
                document.querySelector(`[data-position="${currentPosition}"]`).style.display = 'none';
                
                // Show next position
                currentPosition++;
                document.querySelector(`[data-position="${currentPosition}"]`).style.display = 'block';
                
                // Save current position
                saveCurrentPosition();
                
                // Scroll to top
                window.scrollTo(0, 0);
            }
        }
        
        function previousPosition() {
            if (currentPosition > 0) {
                // Hide current position
                document.querySelector(`[data-position="${currentPosition}"]`).style.display = 'none';
                
                // Show previous position
                currentPosition--;
                document.querySelector(`[data-position="${currentPosition}"]`).style.display = 'block';
                
                // Save current position
                saveCurrentPosition();
                
                // Scroll to top
                window.scrollTo(0, 0);
            }
        }
        
        function showConfirmation() {
            // Collect all selections grouped by position
            const selectionsByPosition = {};
            const selectedInputs = document.querySelectorAll('input[type="checkbox"]:checked, input[type="radio"]:checked');
            
            selectedInputs.forEach(input => {
                const candidateCard = input.closest('.candidate-card');
                const candidateName = candidateCard.querySelector('.candidate-name').textContent.trim();
                const candidateDetails = candidateCard.querySelector('.candidate-details').textContent.trim();
                const candidatePhoto = candidateCard.querySelector('img')?.src || null;
                const positionId = input.dataset.position;
                const positionTitle = positionData.find(p => p.position_id == positionId)?.position_title || 'Unknown Position';
                
                if (!selectionsByPosition[positionId]) {
                    selectionsByPosition[positionId] = {
                        positionTitle,
                        candidates: []
                    };
                }
                
                selectionsByPosition[positionId].candidates.push({
                    candidateName,
                    candidateDetails,
                    candidatePhoto
                });
            });
            
            if (Object.keys(selectionsByPosition).length === 0) {
                alert('Please make at least one selection before proceeding to review.');
                return;
            }
            
            // Hide voting pages
            document.getElementById('votingPages').style.display = 'none';
            
            // Show confirmation screen
            document.getElementById('confirmationScreen').style.display = 'block';
            
            // Populate selection summary with improved horizontal layout
            const summaryDiv = document.getElementById('selectionSummary');
            let summaryHTML = '<h3 class="mb-4 text-center text-primary"><i class="fas fa-clipboard-check me-2"></i>Your Selections</h3>';
            
            // Sort positions by their order in positionData
            const sortedPositions = Object.keys(selectionsByPosition).sort((a, b) => {
                const indexA = positionData.findIndex(p => p.position_id == a);
                const indexB = positionData.findIndex(p => p.position_id == b);
                return indexA - indexB;
            });
            
            // Group positions into rows (responsive based on screen size)
            const isMobile = window.innerWidth <= 768;
            const positionsPerRow = isMobile ? 1 : 2; // 1 per row on mobile, 2 on desktop
            let currentRow = [];
            
            sortedPositions.forEach((positionId, index) => {
                const position = selectionsByPosition[positionId];
                
                // Start a new row if needed
                if (currentRow.length === 0) {
                    summaryHTML += '<div class="positions-row">';
                }
                
                currentRow.push(positionId);
                
                // Build position card
                summaryHTML += `
                    <div class="position-selection-group">
                        <div class="position-selection-header">
                            <div class="position-selection-title">
                                <i class="fas fa-award me-2"></i>${position.positionTitle}
                            </div>
                            <div class="position-candidate-count">
                                ${position.candidates.length} candidate${position.candidates.length > 1 ? 's' : ''} selected
                            </div>
                        </div>
                        
                        <div class="selections-grid">
                `;
                
                position.candidates.forEach(candidate => {
                    summaryHTML += `
                        <div class="selection-card">
                            ${candidate.candidatePhoto ? 
                                `<img src="${candidate.candidatePhoto}" alt="${candidate.candidateName}" class="selection-photo">` : 
                                '<div class="selection-photo bg-light d-flex align-items-center justify-content-center mx-auto"><i class="fas fa-user text-muted"></i></div>'
                            }
                            <div class="selection-candidate-name">${candidate.candidateName}</div>
                            <div class="selection-candidate-details">${candidate.candidateDetails}</div>
                        </div>
                    `;
                });
                
                summaryHTML += `
                        </div>
                    </div>
                `;
                
                // Close row if it's full or if it's the last position
                if (currentRow.length === positionsPerRow || index === sortedPositions.length - 1) {
                    summaryHTML += '</div>'; // Close positions-row
                    currentRow = [];
                }
            });
            
            // Show warning if not all positions have selections
            const totalSelected = Object.keys(selectionsByPosition).length;
            if (totalSelected < totalPositions) {
                summaryHTML += `
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Notice:</strong> You have made selections for ${totalSelected} of ${totalPositions} positions. 
                        You can still submit your vote, but consider going back to vote for the remaining positions.
                    </div>
                `;
            }
            
            // Add summary stats
            const totalCandidates = Object.values(selectionsByPosition).reduce((sum, pos) => sum + pos.candidates.length, 0);
            summaryHTML += `
                <div class="alert alert-success text-center">
                    <i class="fas fa-check-circle me-2"></i>
                    <strong>Summary:</strong> ${totalCandidates} candidate${totalCandidates > 1 ? 's' : ''} selected across ${totalSelected} position${totalSelected > 1 ? 's' : ''}
                </div>
            `;
            
            summaryDiv.innerHTML = summaryHTML;
            
            // Scroll to top
            window.scrollTo(0, 0);
        }
        
        function backToVoting() {
            // Hide confirmation screen
            document.getElementById('confirmationScreen').style.display = 'none';
            
            // Show voting pages
            document.getElementById('votingPages').style.display = 'block';
            
            // Go to last position
            currentPosition = totalPositions - 1;
            document.querySelectorAll('.position-page').forEach((page, index) => {
                page.style.display = index === currentPosition ? 'block' : 'none';
            });
            
            // Scroll to top
            window.scrollTo(0, 0);
        }
        
        // Form submission
        document.getElementById('votingForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const selectedCandidates = document.querySelectorAll('input[type="checkbox"]:checked, input[type="radio"]:checked').length;
            
            if (selectedCandidates === 0) {
                alert('Please select at least one candidate before submitting your vote.');
                return;
            }
            
            const finalSubmitBtn = document.getElementById('finalSubmitBtn');
            finalSubmitBtn.disabled = true;
            finalSubmitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting Vote...';
            
            try {
                // Submit the form via AJAX
                const formData = new FormData(this);
                const response = await fetch(this.action, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Clear stored selections and position since vote was successful
                    sessionStorage.removeItem(storageKey);
                    sessionStorage.removeItem(positionKey);
                    
                    // Show success message with countdown
                    document.body.innerHTML = `
                        <div class="container mt-5 pt-5">
                            <div class="row justify-content-center">
                                <div class="col-md-8 text-center">
                                    <div class="card border-success shadow-lg">
                                        <div class="card-body p-5">
                                            <i class="fas fa-check-circle fa-6x text-success mb-4"></i>
                                            <h1 class="text-success mb-3">Vote Submitted Successfully!</h1>
                                            <p class="lead mb-4">
                                                Thank you for participating in <strong><?= htmlspecialchars($election['title']) ?></strong>. 
                                                Your vote has been recorded securely and anonymously.
                                            </p>
                                            <div class="alert alert-info mb-4">
                                                <i class="fas fa-info-circle me-2"></i>
                                                You selected candidates for <strong>${selectedCandidates}</strong> position(s).
                                            </div>
                                            <div class="alert alert-warning mb-4">
                                                <i class="fas fa-sign-out-alt me-2"></i>
                                                For security, you will be automatically logged out in <span id="countdown" class="fw-bold">5</span> seconds.
                                            </div>
                                            <button class="btn btn-primary btn-lg px-5 me-3" onclick="logoutNow()">
                                                <i class="fas fa-sign-out-alt me-2"></i>Logout Now
                                            </button>
                                            <button class="btn btn-outline-secondary btn-lg px-5" onclick="cancelLogout()">
                                                <i class="fas fa-home me-2"></i>Return to Dashboard
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    // Start logout countdown
                    let countdown = 5;
                    const countdownElement = document.getElementById('countdown');
                    const logoutTimer = setInterval(() => {
                        countdown--;
                        if (countdownElement) {
                            countdownElement.textContent = countdown;
                        }
                        
                        if (countdown <= 0) {
                            clearInterval(logoutTimer);
                            logoutStudent();
                        }
                    }, 1000);
                    
                    // Store timer ID for cancellation
                    window.logoutTimer = logoutTimer;
                } else {
                    alert('Error: ' + result.message);
                    finalSubmitBtn.disabled = false;
                    finalSubmitBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Submit My Vote';
                }
            } catch (error) {
                alert('An error occurred while submitting your vote. Please try again.');
                finalSubmitBtn.disabled = false;
                finalSubmitBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Submit My Vote';
            }
        });
        
        // Initialize selections when page loads
        document.addEventListener('DOMContentLoaded', function() {
            loadSelections();
        });
        
        // Logout functions
        async function logoutStudent() {
            try {
                // Call student logout endpoint
                await fetch('logout.php', { method: 'POST' });
                // Redirect to landing page after logout
                window.location.href = '<?= SITE_URL ?>';
            } catch (error) {
                // Fallback - just redirect to landing page
                window.location.href = '<?= SITE_URL ?>';
            }
        }
        
        function logoutNow() {
            // Clear the timer and logout immediately
            if (window.logoutTimer) {
                clearInterval(window.logoutTimer);
            }
            logoutStudent();
        }
        
        function cancelLogout() {
            // Clear the timer and return to dashboard
            if (window.logoutTimer) {
                clearInterval(window.logoutTimer);
            }
            window.location.href = 'dashboard.php';
        }
    </script>
</body>
</html>