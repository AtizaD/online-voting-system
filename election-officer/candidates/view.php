<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['election_officer']);

$page_title = 'View Candidate';
$candidate_id = intval($_GET['id'] ?? 0);


if (!$candidate_id) {
    $_SESSION['candidates_error'] = 'Invalid candidate ID';
    redirectTo('/election-officer/candidates/');
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Get candidate details with all related information
    $stmt = $db->prepare("
        SELECT 
            c.*,
            CONCAT(s.first_name, ' ', s.last_name) as candidate_name,
            s.student_number,
            s.gender,
            s.phone,
            prog.program_name as program,
            cl.class_name as class,
            l.level_name as level,
            e.name as election_name,
            e.start_date,
            e.end_date,
            e.status as election_status,
            p.title as position_name,
            p.description as position_description,
            COUNT(v.vote_id) as vote_count
        FROM candidates c
        JOIN students s ON c.student_id = s.student_id
        JOIN programs prog ON s.program_id = prog.program_id
        JOIN classes cl ON s.class_id = cl.class_id
        JOIN levels l ON cl.level_id = l.level_id
        JOIN elections e ON c.election_id = e.election_id
        JOIN positions p ON c.position_id = p.position_id
        LEFT JOIN votes v ON c.candidate_id = v.candidate_id
        WHERE c.candidate_id = ?
        GROUP BY c.candidate_id
    ");
    
    $stmt->execute([$candidate_id]);
    $candidate = $stmt->fetch();
    
    if (!$candidate) {
        $_SESSION['candidates_error'] = 'Candidate not found';
        redirectTo('/election-officer/candidates/');
    }
    
} catch (Exception $e) {
    error_log("Candidate view error: " . $e->getMessage());
    $_SESSION['candidates_error'] = 'Unable to load candidate details';
    redirectTo('/election-officer/candidates/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($candidate['candidate_name']) ?> - Candidate Details</title>
    
    <!-- Bootstrap CSS -->
    <link href="<?= SITE_URL ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="<?= SITE_URL ?>/assets/css/font-awesome.min.css" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        
        .candidate-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 800px;
            margin: 0 auto;
            animation: fadeInUp 0.6s ease-out;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .candidate-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 2rem;
            text-align: center;
            position: relative;
        }
        
        .candidate-avatar {
            width: 120px;
            height: 120px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0 auto 1rem;
            border: 4px solid rgba(255,255,255,0.3);
        }
        
        .candidate-name {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .candidate-position {
            font-size: 1.2rem;
            opacity: 0.9;
            margin-bottom: 0.25rem;
        }
        
        .candidate-election {
            font-size: 1rem;
            opacity: 0.8;
        }
        
        .candidate-body {
            padding: 2rem;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .info-section {
            background: #f8fafc;
            border-radius: 12px;
            padding: 1.5rem;
            border-left: 4px solid #667eea;
        }
        
        .info-section h5 {
            color: #1e293b;
            font-weight: 600;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            margin-bottom: 0.75rem;
            font-size: 0.95rem;
        }
        
        .info-item:last-child {
            margin-bottom: 0;
        }
        
        .info-item i {
            width: 20px;
            color: #667eea;
            margin-right: 0.75rem;
        }
        
        .info-label {
            font-weight: 500;
            color: #64748b;
            min-width: 80px;
        }
        
        .info-value {
            color: #1e293b;
            font-weight: 500;
        }
        
        .stats-section {
            background: linear-gradient(135deg, #f1f5f9, #ffffff);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            border: 1px solid #e2e8f0;
        }
        
        .vote-count {
            font-size: 3rem;
            font-weight: 800;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.5rem;
        }
        
        .vote-label {
            color: #64748b;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.875rem;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-active { 
            background: linear-gradient(135deg, #dcfce7, #bbf7d0); 
            color: #166534; 
        }
        
        .status-completed { 
            background: linear-gradient(135deg, #f3e8ff, #e9d5ff); 
            color: #7c3aed; 
        }
        
        .status-scheduled { 
            background: linear-gradient(135deg, #dbeafe, #bfdbfe); 
            color: #1d4ed8; 
        }
        
        .status-draft { 
            background: linear-gradient(135deg, #f3f4f6, #e5e7eb); 
            color: #374151; 
        }
        
        .actions-section {
            border-top: 1px solid #e2e8f0;
            padding: 1.5rem 2rem;
            background: #f8fafc;
            display: flex;
            gap: 1rem;
            justify-content: center;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
            border-radius: 10px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }
        
        .btn-secondary {
            background: #64748b;
            border: none;
            border-radius: 10px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            background: #475569;
            transform: translateY(-2px);
        }
        
        @media (max-width: 768px) {
            .candidate-card {
                margin: 0 1rem;
            }
            
            .candidate-header {
                padding: 1.5rem;
            }
            
            .candidate-avatar {
                width: 100px;
                height: 100px;
                font-size: 2rem;
            }
            
            .candidate-name {
                font-size: 1.5rem;
            }
            
            .candidate-body {
                padding: 1.5rem;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
            
            .actions-section {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="candidate-card">
            <div class="candidate-header">
                <div class="candidate-avatar">
                    <?php
                    $names = explode(' ', $candidate['candidate_name']);
                    echo substr($names[0], 0, 1) . (isset($names[1]) ? substr($names[1], 0, 1) : '');
                    ?>
                </div>
                <h1 class="candidate-name"><?= sanitize($candidate['candidate_name']) ?></h1>
                <div class="candidate-position"><?= sanitize($candidate['position_name']) ?></div>
                <div class="candidate-election"><?= sanitize($candidate['election_name']) ?></div>
            </div>
            
            <div class="candidate-body">
                <div class="info-grid">
                    <!-- Personal Information -->
                    <div class="info-section">
                        <h5><i class="fas fa-user"></i> Personal Information</h5>
                        <div class="info-item">
                            <i class="fas fa-id-card"></i>
                            <span class="info-label">Student ID:</span>
                            <span class="info-value"><?= sanitize($candidate['student_number']) ?></span>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-venus-mars"></i>
                            <span class="info-label">Gender:</span>
                            <span class="info-value"><?= sanitize($candidate['gender']) ?></span>
                        </div>
                        <?php if ($candidate['phone']): ?>
                        <div class="info-item">
                            <i class="fas fa-phone"></i>
                            <span class="info-label">Phone:</span>
                            <span class="info-value"><?= sanitize($candidate['phone']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Academic Information -->
                    <div class="info-section">
                        <h5><i class="fas fa-graduation-cap"></i> Academic Information</h5>
                        <div class="info-item">
                            <i class="fas fa-layer-group"></i>
                            <span class="info-label">Level:</span>
                            <span class="info-value"><?= sanitize($candidate['level']) ?></span>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-book"></i>
                            <span class="info-label">Program:</span>
                            <span class="info-value"><?= sanitize($candidate['program']) ?></span>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-users"></i>
                            <span class="info-label">Class:</span>
                            <span class="info-value"><?= sanitize($candidate['class']) ?></span>
                        </div>
                    </div>
                    
                    <!-- Election Information -->
                    <div class="info-section">
                        <h5><i class="fas fa-poll"></i> Election Information</h5>
                        <div class="info-item">
                            <i class="fas fa-calendar-alt"></i>
                            <span class="info-label">Start Date:</span>
                            <span class="info-value"><?= date('M j, Y g:i A', strtotime($candidate['start_date'])) ?></span>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-calendar-check"></i>
                            <span class="info-label">End Date:</span>
                            <span class="info-value"><?= date('M j, Y g:i A', strtotime($candidate['end_date'])) ?></span>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-flag"></i>
                            <span class="info-label">Status:</span>
                            <span class="status-badge status-<?= $candidate['election_status'] ?>">
                                <?= ucfirst($candidate['election_status']) ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-clock"></i>
                            <span class="info-label">Registered:</span>
                            <span class="info-value"><?= date('M j, Y g:i A', strtotime($candidate['created_at'])) ?></span>
                        </div>
                    </div>
                    
                    <!-- Vote Statistics -->
                    <div class="stats-section">
                        <h5><i class="fas fa-chart-bar"></i> Vote Statistics</h5>
                        <div class="vote-count"><?= number_format($candidate['vote_count']) ?></div>
                        <div class="vote-label">Total Votes</div>
                    </div>
                </div>
                
                <?php if ($candidate['position_description']): ?>
                <div class="info-section">
                    <h5><i class="fas fa-info-circle"></i> Position Description</h5>
                    <p style="color: #64748b; margin: 0; line-height: 1.6;">
                        <?= sanitize($candidate['position_description']) ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="actions-section">
                <a href="<?= SITE_URL ?>/election-officer/candidates/manage.php?candidate_id=<?= $candidate['candidate_id'] ?>" 
                   class="btn btn-primary">
                    <i class="fas fa-edit me-2"></i>Edit Candidate
                </a>
                <button onclick="window.close()" class="btn btn-secondary">
                    <i class="fas fa-times me-2"></i>Close
                </button>
                <a href="<?= SITE_URL ?>/election-officer/candidates/" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to List
                </a>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="<?= SITE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>