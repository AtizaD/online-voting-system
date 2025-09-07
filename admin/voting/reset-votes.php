<?php
require_once '../../config/config.php';
require_once '../../config/database.php';

if (!isLoggedIn() || !hasPermission('manage_voting')) {
    redirectTo('auth/index.php');
}

$db = Database::getInstance()->getConnection();
$current_user = getCurrentUser();

// Get election ID from URL
$election_id = intval($_GET['election_id'] ?? 0);
if (!$election_id) {
    redirectTo('index.php?error=Invalid election');
}

// Get election info
$stmt = $db->prepare("
    SELECT e.*, 
           COUNT(DISTINCT vs.student_id) as total_voters,
           COUNT(v.vote_id) as total_votes
    FROM elections e
    LEFT JOIN voting_sessions vs ON e.election_id = vs.election_id AND vs.status = 'completed'
    LEFT JOIN votes v ON vs.session_id = v.session_id
    WHERE e.election_id = ?
    GROUP BY e.election_id
");
$stmt->execute([$election_id]);
$election = $stmt->fetch();

if (!$election) {
    redirectTo('index.php?error=Election not found');
}

// Handle form submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $db->beginTransaction();
        
        if ($action === 'reset_student') {
            $student_id = intval($_POST['student_id'] ?? 0);
            if (!$student_id) {
                throw new Exception('Invalid student ID');
            }
            
            // Get student info
            $stmt = $db->prepare("
                SELECT s.student_number, s.first_name, s.last_name,
                       vs.session_id, vs.votes_cast
                FROM students s
                LEFT JOIN voting_sessions vs ON s.student_id = vs.student_id 
                    AND vs.election_id = ? AND vs.status = 'completed'
                WHERE s.student_id = ?
            ");
            $stmt->execute([$election_id, $student_id]);
            $student = $stmt->fetch();
            
            if (!$student) {
                throw new Exception('Student not found');
            }
            
            if (!$student['session_id']) {
                throw new Exception('Student has not voted in this election');
            }
            
            // Delete votes
            $stmt = $db->prepare("DELETE FROM votes WHERE session_id = ?");
            $stmt->execute([$student['session_id']]);
            $votes_deleted = $stmt->rowCount();
            
            // Delete voting session
            $stmt = $db->prepare("DELETE FROM voting_sessions WHERE session_id = ?");
            $stmt->execute([$student['session_id']]);
            
            // Log the action
            $stmt = $db->prepare("
                INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, ip_address, user_agent)
                VALUES (?, 'vote_reset', 'voting_sessions', ?, ?, ?, ?)
            ");
            $stmt->execute([
                $current_user['id'],
                $student['session_id'],
                json_encode([
                    'student_id' => $student_id,
                    'student_number' => $student['student_number'],
                    'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                    'election_id' => $election_id,
                    'votes_deleted' => $votes_deleted,
                    'reset_by' => $current_user['first_name'] . ' ' . $current_user['last_name'],
                    'reason' => $_POST['reason'] ?? 'Admin vote reset'
                ]),
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            $db->commit();
            $message = "Successfully reset vote for {$student['first_name']} {$student['last_name']} ({$student['student_number']})";
            
        } elseif ($action === 'reset_all') {
            $confirmation = $_POST['confirmation'] ?? '';
            if ($confirmation !== 'RESET ALL VOTES') {
                throw new Exception('Please type "RESET ALL VOTES" to confirm');
            }
            
            // Get count of votes to be deleted
            $stmt = $db->prepare("
                SELECT COUNT(*) as vote_count, COUNT(DISTINCT vs.student_id) as student_count
                FROM voting_sessions vs
                LEFT JOIN votes v ON vs.session_id = v.session_id
                WHERE vs.election_id = ? AND vs.status = 'completed'
            ");
            $stmt->execute([$election_id]);
            $counts = $stmt->fetch();
            
            // Delete all votes for this election
            $stmt = $db->prepare("
                DELETE v FROM votes v
                INNER JOIN voting_sessions vs ON v.session_id = vs.session_id
                WHERE vs.election_id = ?
            ");
            $stmt->execute([$election_id]);
            $votes_deleted = $stmt->rowCount();
            
            // Delete all voting sessions for this election
            $stmt = $db->prepare("DELETE FROM voting_sessions WHERE election_id = ?");
            $stmt->execute([$election_id]);
            $sessions_deleted = $stmt->rowCount();
            
            // Log the action
            $stmt = $db->prepare("
                INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, ip_address, user_agent)
                VALUES (?, 'election_votes_reset', 'elections', ?, ?, ?, ?)
            ");
            $stmt->execute([
                $current_user['id'],
                $election_id,
                json_encode([
                    'election_name' => $election['name'],
                    'votes_deleted' => $votes_deleted,
                    'sessions_deleted' => $sessions_deleted,
                    'students_affected' => $counts['student_count'],
                    'reset_by' => $current_user['first_name'] . ' ' . $current_user['last_name'],
                    'reason' => $_POST['reason'] ?? 'Admin mass vote reset'
                ]),
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            $db->commit();
            $message = "Successfully reset all votes for this election. {$votes_deleted} votes from {$sessions_deleted} students deleted.";
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
    }
}

// Get students who have voted
$stmt = $db->prepare("
    SELECT s.student_id, s.student_number, s.first_name, s.last_name, s.program_id,
           prog.program_name, cl.class_name,
           vs.session_id, vs.started_at, vs.completed_at, vs.votes_cast,
           COUNT(v.vote_id) as actual_votes
    FROM students s
    INNER JOIN voting_sessions vs ON s.student_id = vs.student_id
    LEFT JOIN programs prog ON s.program_id = prog.program_id
    LEFT JOIN classes cl ON s.class_id = cl.class_id
    LEFT JOIN votes v ON vs.session_id = v.session_id
    WHERE vs.election_id = ? AND vs.status = 'completed'
    GROUP BY s.student_id, vs.session_id
    ORDER BY vs.completed_at DESC
");
$stmt->execute([$election_id]);
$voted_students = $stmt->fetchAll();

$page_title = "Reset Votes - " . $election['name'];
include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><i class="fas fa-undo"></i> Reset Votes</h2>
                    <p class="text-muted mb-0">Election: <strong><?= sanitize($election['name']) ?></strong></p>
                </div>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Voting Management
                </a>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle"></i> <?= sanitize($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle"></i> <?= sanitize($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Election Stats -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <h3><?= $election['total_voters'] ?></h3>
                            <p class="mb-0">Students Voted</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <h3><?= $election['total_votes'] ?></h3>
                            <p class="mb-0">Total Votes Cast</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card bg-warning text-dark">
                        <div class="card-body">
                            <h5><i class="fas fa-exclamation-triangle"></i> Warning</h5>
                            <p class="mb-0">Resetting votes is irreversible. Use only when necessary (e.g., someone voted using another student's ID).</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Individual Student Reset -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-user"></i> Students Who Have Voted</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($voted_students)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No votes have been cast for this election yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Student</th>
                                                <th>Program/Class</th>
                                                <th>Voted At</th>
                                                <th>Votes</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($voted_students as $student): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= sanitize($student['first_name'] . ' ' . $student['last_name']) ?></strong>
                                                        <br>
                                                        <small class="text-muted"><?= sanitize($student['student_number']) ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-secondary"><?= sanitize($student['program_name']) ?></span>
                                                        <?php if ($student['class_name']): ?>
                                                            <br><small class="text-muted"><?= sanitize($student['class_name']) ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?= date('M j, Y g:i A', strtotime($student['completed_at'])) ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-success"><?= $student['actual_votes'] ?> votes</span>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-danger" 
                                                                onclick="showResetModal(<?= $student['student_id'] ?>, '<?= sanitize($student['first_name'] . ' ' . $student['last_name']) ?>', '<?= sanitize($student['student_number']) ?>')">
                                                            <i class="fas fa-undo"></i> Reset Vote
                                                        </button>
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

                <!-- Reset All Votes -->
                <div class="col-md-4">
                    <div class="card border-danger">
                        <div class="card-header bg-danger text-white">
                            <h5><i class="fas fa-exclamation-triangle"></i> Danger Zone</h5>
                        </div>
                        <div class="card-body">
                            <h6>Reset All Votes</h6>
                            <p class="text-muted">This will permanently delete ALL votes for this election. This action cannot be undone.</p>
                            
                            <?php if (!empty($voted_students)): ?>
                                <button class="btn btn-danger w-100" onclick="showResetAllModal()">
                                    <i class="fas fa-trash"></i> Reset All Votes
                                </button>
                            <?php else: ?>
                                <button class="btn btn-secondary w-100" disabled>
                                    <i class="fas fa-trash"></i> No Votes to Reset
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Reset Individual Vote Modal -->
<div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Reset Student Vote</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="reset_student">
                    <input type="hidden" name="student_id" id="resetStudentId">
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        Are you sure you want to reset the vote for <strong id="resetStudentName"></strong> (<span id="resetStudentNumber"></span>)?
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Reason for Reset (Optional)</label>
                        <textarea name="reason" class="form-control" rows="3" placeholder="e.g., Student reported unauthorized voting using their ID"></textarea>
                    </div>
                    
                    <p class="text-muted small">This action will permanently delete all votes cast by this student in this election.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-undo"></i> Reset Vote
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset All Votes Modal -->
<div class="modal fade" id="resetAllModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Reset All Votes</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="reset_all">
                    
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>DANGER:</strong> This will permanently delete ALL <?= $election['total_votes'] ?> votes from <?= $election['total_voters'] ?> students in this election.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Type "RESET ALL VOTES" to confirm:</label>
                        <input type="text" name="confirmation" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Reason for Mass Reset</label>
                        <textarea name="reason" class="form-control" rows="3" required placeholder="Explain why all votes need to be reset"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Reset All Votes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showResetModal(studentId, studentName, studentNumber) {
    document.getElementById('resetStudentId').value = studentId;
    document.getElementById('resetStudentName').textContent = studentName;
    document.getElementById('resetStudentNumber').textContent = studentNumber;
    
    const modal = new bootstrap.Modal(document.getElementById('resetModal'));
    modal.show();
}

function showResetAllModal() {
    const modal = new bootstrap.Modal(document.getElementById('resetAllModal'));
    modal.show();
}
</script>

<?php include '../../includes/footer.php'; ?>