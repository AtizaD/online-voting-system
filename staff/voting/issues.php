<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['staff']);

$page_title = 'Report Voting Issues';
$breadcrumbs = [
    ['title' => 'Staff Dashboard', 'url' => '../'],
    ['title' => 'Report Voting Issues']
];

$current_user = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Handle issue submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_issue'])) {
    try {
        $student_number = sanitize($_POST['student_number'] ?? '');
        $issue_type = sanitize($_POST['issue_type'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $priority = sanitize($_POST['priority'] ?? 'medium');
        
        if (empty($student_number) || empty($issue_type) || empty($description)) {
            throw new Exception('All fields are required');
        }
        
        // Find student
        $stmt = $db->prepare("SELECT student_id, first_name, last_name FROM students WHERE student_number = ? AND is_active = 1");
        $stmt->execute([$student_number]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            throw new Exception('Student not found');
        }
        
        // Insert issue report (assuming there's an issues table)
        $stmt = $db->prepare("
            INSERT INTO voting_issues (student_id, reported_by, issue_type, description, priority, status, created_at) 
            VALUES (?, ?, ?, ?, ?, 'open', NOW())
        ");
        $stmt->execute([$student['student_id'], $current_user['user_id'], $issue_type, $description, $priority]);
        
        $success_message = "Issue reported successfully for {$student['first_name']} {$student['last_name']}";
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<style>
.issues-card {
    background: white;
    border-radius: 0.75rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.issue-form {
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    padding: 1.5rem;
    background: #f8fafc;
}

.issue-item {
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    padding: 1rem;
    margin-bottom: 1rem;
    background: white;
}

.priority-high { border-left: 4px solid #dc2626; }
.priority-medium { border-left: 4px solid #f59e0b; }
.priority-low { border-left: 4px solid #10b981; }

.status-open { background: #fee2e2; color: #dc2626; }
.status-resolved { background: #dcfce7; color: #166534; }
.status-pending { background: #fef3c7; color: #d97706; }

.common-issues {
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 0.5rem;
    padding: 1rem;
}
</style>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="row">
        <div class="col-12">
            <div class="issues-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">Report Voting Issues</h2>
                        <p class="text-muted mb-0">Document and track voting problems for resolution</p>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-primary" id="openIssuesCount">Loading...</span>
                        <div class="small text-muted">Open Issues</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?= $success_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-triangle me-2"></i><?= $error_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Issue Report Form -->
    <div class="row">
        <div class="col-md-8">
            <div class="issues-card">
                <h5 class="mb-3">Report New Issue</h5>
                <form method="POST" class="issue-form">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="student_number" class="form-label">Student Number *</label>
                                <input type="text" class="form-control" id="student_number" name="student_number" 
                                       placeholder="Enter student number" required>
                                <div class="form-text">Student experiencing the voting issue</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="issue_type" class="form-label">Issue Type *</label>
                                <select class="form-select" id="issue_type" name="issue_type" required>
                                    <option value="">Select issue type</option>
                                    <option value="login_problem">Login Problem</option>
                                    <option value="voting_error">Voting Error</option>
                                    <option value="system_slowness">System Slowness</option>
                                    <option value="display_issue">Display Issue</option>
                                    <option value="eligibility_error">Eligibility Error</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Issue Description *</label>
                        <textarea class="form-control" id="description" name="description" rows="4" 
                                  placeholder="Describe the issue in detail..." required></textarea>
                        <div class="form-text">Include steps to reproduce, error messages, and any other relevant details</div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="priority" class="form-label">Priority</label>
                                <select class="form-select" id="priority" name="priority">
                                    <option value="low">Low - Minor inconvenience</option>
                                    <option value="medium" selected>Medium - Affects voting ability</option>
                                    <option value="high">High - Prevents voting entirely</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>&nbsp;</label>
                                <div>
                                    <button type="submit" name="submit_issue" class="btn btn-primary">
                                        <i class="fas fa-exclamation-triangle me-2"></i>Report Issue
                                    </button>
                                    <button type="reset" class="btn btn-outline-secondary ms-2">
                                        <i class="fas fa-undo me-2"></i>Reset Form
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="issues-card">
                <h5 class="mb-3">Common Issues & Solutions</h5>
                <div class="common-issues">
                    <h6><i class="fas fa-sign-in-alt text-primary"></i> Login Problems</h6>
                    <ul class="small mb-3">
                        <li>Verify student number is correct</li>
                        <li>Check if account is verified</li>
                        <li>Try clearing browser cache</li>
                    </ul>
                    
                    <h6><i class="fas fa-vote-yea text-success"></i> Voting Errors</h6>
                    <ul class="small mb-3">
                        <li>Ensure election is currently active</li>
                        <li>Check if student already voted</li>
                        <li>Verify browser JavaScript is enabled</li>
                    </ul>
                    
                    <h6><i class="fas fa-tachometer-alt text-warning"></i> Performance Issues</h6>
                    <ul class="small mb-0">
                        <li>Check internet connection</li>
                        <li>Try different browser</li>
                        <li>Refresh page and try again</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Issues -->
    <div class="row">
        <div class="col-12">
            <div class="issues-card">
                <h5 class="mb-3">Recent Issues</h5>
                <div id="recentIssues">
                    <div class="text-center py-3 text-muted">
                        <i class="fas fa-clipboard-list fa-2x mb-2"></i>
                        <p>No recent issues reported</p>
                        <small>Issues will appear here once reported</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    loadIssuesCount();
    loadRecentIssues();
});

async function loadIssuesCount() {
    try {
        // This would call an API to get open issues count
        // For now, showing placeholder
        document.getElementById('openIssuesCount').textContent = '0';
    } catch (error) {
        console.error('Error loading issues count:', error);
    }
}

function loadRecentIssues() {
    // This would load recent issues from database
    // For demonstration, showing placeholder content
    const mockIssues = [
        {
            id: 1,
            student_name: 'John Doe',
            issue_type: 'Login Problem',
            priority: 'high',
            status: 'open',
            created_at: new Date().toISOString(),
            description: 'Student unable to access voting system with correct credentials'
        }
    ];
    
    if (mockIssues.length === 0) return;
    
    let html = '';
    mockIssues.forEach(issue => {
        const priorityClass = `priority-${issue.priority}`;
        const statusClass = `status-${issue.status}`;
        const createdDate = new Date(issue.created_at);
        
        html += `
            <div class="issue-item ${priorityClass}">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center mb-2">
                            <h6 class="mb-0 me-3">#${issue.id} - ${issue.issue_type}</h6>
                            <span class="badge ${statusClass}">${issue.status}</span>
                            <span class="badge bg-${issue.priority === 'high' ? 'danger' : issue.priority === 'medium' ? 'warning' : 'success'} ms-2">
                                ${issue.priority} priority
                            </span>
                        </div>
                        <p class="mb-2 text-muted">${issue.description}</p>
                        <small class="text-muted">
                            Student: ${issue.student_name} • 
                            Reported: ${createdDate.toLocaleDateString()} ${createdDate.toLocaleTimeString()}
                        </small>
                    </div>
                    <div class="text-end">
                        <button class="btn btn-sm btn-outline-primary" onclick="viewIssue(${issue.id})">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
    });
    
    if (html) {
        document.getElementById('recentIssues').innerHTML = html;
    }
}

function viewIssue(issueId) {
    alert(`View issue #${issueId} - This would open a detailed issue view`);
}

// Auto-populate student info when student number is entered
document.getElementById('student_number').addEventListener('blur', async function() {
    const studentNumber = this.value.trim();
    if (!studentNumber) return;
    
    try {
        const response = await fetch(`/online_voting/api/students/list.php?student_number=${studentNumber}`);
        const data = await response.json();
        
        if (data.success && data.data.length > 0) {
            const student = data.data[0];
            // Could show student name confirmation
        }
    } catch (error) {
        console.error('Error verifying student:', error);
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>