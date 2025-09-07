<?php
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth(['staff']);

$page_title = 'Staff Dashboard';
$breadcrumbs = [
    ['title' => 'Staff Dashboard']
];

// Get current user info
$current_user = getCurrentUser();
$db = Database::getInstance()->getConnection();

include __DIR__ . '/../includes/header.php';
?>

<style>
.dashboard-card {
    background: white;
    border-radius: 0.75rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    transition: all 0.3s ease;
}

.dashboard-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 15px -3px rgba(0, 0, 0, 0.1);
}

.stat-card {
    text-align: center;
    padding: 2rem 1.5rem;
}

.stat-number {
    font-size: 2.5rem;
    font-weight: 700;
    margin: 0;
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.stat-label {
    color: #64748b;
    font-size: 0.875rem;
    margin: 0.5rem 0 0;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.quick-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.action-card {
    background: white;
    border-radius: 0.5rem;
    padding: 1.5rem;
    text-align: center;
    border: 2px solid #e2e8f0;
    transition: all 0.3s ease;
    text-decoration: none;
    color: inherit;
}

.action-card:hover {
    border-color: var(--primary-color);
    transform: translateY(-2px);
    text-decoration: none;
    color: inherit;
}

.action-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1rem;
    color: white;
    font-size: 1.5rem;
}

.action-title {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.action-description {
    color: #64748b;
    font-size: 0.875rem;
}

.recent-activity {
    max-height: 400px;
    overflow-y: auto;
}

.activity-item {
    display: flex;
    align-items: center;
    padding: 1rem 0;
    border-bottom: 1px solid #f1f5f9;
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 1rem;
    font-size: 0.875rem;
}

.activity-create { background: #dcfce7; color: #166534; }
.activity-update { background: #dbeafe; color: #1d4ed8; }
.activity-verify { background: #fef3c7; color: #d97706; }

.activity-details h6 {
    margin: 0;
    font-size: 0.875rem;
    font-weight: 600;
}

.activity-details small {
    color: #64748b;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.loading {
    text-align: center;
    padding: 2rem;
    color: #64748b;
}

.error {
    background: #fee2e2;
    color: #dc2626;
    padding: 1rem;
    border-radius: 0.5rem;
    margin-bottom: 1rem;
}
</style>

<div class="container-fluid py-4">
    <!-- Welcome Section -->
    <div class="row">
        <div class="col-12">
            <div class="dashboard-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">Welcome back, <?= sanitize($current_user['first_name']) ?>!</h2>
                        <p class="text-muted mb-0">Staff Dashboard - Manage students and assist with elections</p>
                    </div>
                    <div class="text-end">
                        <div class="small text-muted">Last login</div>
                        <div class="fw-bold"><?= $current_user['last_login'] ? date('M j, Y g:i A', strtotime($current_user['last_login'])) : 'First time' ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid" id="statsGrid">
        <div class="loading">Loading statistics...</div>
    </div>

    <!-- Quick Actions -->
    <div class="row">
        <div class="col-12">
            <h4 class="mb-3">Quick Actions</h4>
            <div class="quick-actions">
                <a href="students/" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="action-title">Manage Students</div>
                    <div class="action-description">Add, edit, and verify student records</div>
                </a>
                
                <a href="students/verify.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="action-title">Verify Students</div>
                    <div class="action-description">Review and approve student verification</div>
                </a>
                
                <a href="../elections/" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-vote-yea"></i>
                    </div>
                    <div class="action-title">View Elections</div>
                    <div class="action-description">Monitor active and completed elections</div>
                </a>
                
                <a href="reports/" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="action-title">Generate Reports</div>
                    <div class="action-description">Create student and voting reports</div>
                </a>
            </div>
        </div>
    </div>

    <!-- Recent Activity and Pending Tasks -->
    <div class="row">
        <div class="col-md-8">
            <div class="dashboard-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Recent Student Activity</h5>
                    <a href="students/" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="recent-activity" id="recentActivity">
                    <div class="loading">Loading recent activity...</div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="dashboard-card">
                <h5 class="mb-3">Pending Tasks</h5>
                <div id="pendingTasks">
                    <div class="loading">Loading tasks...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Current Elections -->
    <div class="row">
        <div class="col-12">
            <div class="dashboard-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Current Elections</h5>
                    <a href="../elections/" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div id="currentElections">
                    <div class="loading">Loading elections...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Load dashboard data using APIs
document.addEventListener('DOMContentLoaded', function() {
    loadStatistics();
    loadRecentActivity();
    loadPendingTasks();
    loadCurrentElections();
});

async function loadStatistics() {
    try {
        const response = await fetch('/online_voting/api/students/list.php?per_page=1');
        const data = await response.json();
        
        if (data.success) {
            const stats = data.statistics;
            const statsHtml = `
                <div class="dashboard-card stat-card">
                    <h3 class="stat-number">${formatNumber(stats.total)}</h3>
                    <p class="stat-label">Total Students</p>
                </div>
                <div class="dashboard-card stat-card">
                    <h3 class="stat-number">${formatNumber(stats.pending)}</h3>
                    <p class="stat-label">Pending Verification</p>
                </div>
                <div class="dashboard-card stat-card">
                    <h3 class="stat-number">${formatNumber(stats.verified)}</h3>
                    <p class="stat-label">Verified Students</p>
                </div>
                <div class="dashboard-card stat-card">
                    <h3 class="stat-number">${Math.round((stats.verified / stats.total) * 100)}%</h3>
                    <p class="stat-label">Verification Rate</p>
                </div>
            `;
            document.getElementById('statsGrid').innerHTML = statsHtml;
        } else {
            throw new Error(data.message);
        }
    } catch (error) {
        document.getElementById('statsGrid').innerHTML = '<div class="error">Failed to load statistics</div>';
        console.error('Error loading statistics:', error);
    }
}

async function loadRecentActivity() {
    try {
        const response = await fetch('/online_voting/api/students/list.php?per_page=10');
        const data = await response.json();
        
        if (data.success) {
            const students = data.data;
            let activityHtml = '';
            
            students.slice(0, 8).forEach(student => {
                const statusDate = new Date(student.status_date);
                const isRecent = (Date.now() - statusDate.getTime()) < (7 * 24 * 60 * 60 * 1000); // 7 days
                
                if (isRecent) {
                    const iconClass = student.verification_status === 'verified' ? 'activity-verify' : 'activity-create';
                    const iconSymbol = student.verification_status === 'verified' ? 'fa-check' : 'fa-user-plus';
                    const actionText = student.verification_status === 'verified' ? 'verified' : 'added';
                    
                    activityHtml += `
                        <div class="activity-item">
                            <div class="activity-icon ${iconClass}">
                                <i class="fas ${iconSymbol}"></i>
                            </div>
                            <div class="activity-details flex-grow-1">
                                <h6>Student ${actionText}: ${student.first_name} ${student.last_name}</h6>
                                <small>${student.program_name} - ${student.class_name} • ${formatDate(statusDate)}</small>
                            </div>
                        </div>
                    `;
                }
            });
            
            if (!activityHtml) {
                activityHtml = '<div class="text-center py-3 text-muted">No recent activity</div>';
            }
            
            document.getElementById('recentActivity').innerHTML = activityHtml;
        } else {
            throw new Error(data.message);
        }
    } catch (error) {
        document.getElementById('recentActivity').innerHTML = '<div class="error">Failed to load recent activity</div>';
        console.error('Error loading recent activity:', error);
    }
}

async function loadPendingTasks() {
    try {
        const response = await fetch('/online_voting/api/students/list.php?status=pending&per_page=5');
        const data = await response.json();
        
        if (data.success) {
            const pendingCount = data.statistics.pending;
            let tasksHtml = '';
            
            if (pendingCount > 0) {
                tasksHtml += `
                    <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded mb-2">
                        <div>
                            <i class="fas fa-user-clock text-warning me-2"></i>
                            <span class="fw-bold">${pendingCount}</span> students need verification
                        </div>
                        <a href="students/verify.php" class="btn btn-sm btn-primary">Review</a>
                    </div>
                `;
            }
            
            // Check for elections needing attention
            const electionsResponse = await fetch('/online_voting/api/elections/list.php?status=active&per_page=1');
            const electionsData = await electionsResponse.json();
            
            if (electionsData.success && electionsData.statistics.active > 0) {
                tasksHtml += `
                    <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded mb-2">
                        <div>
                            <i class="fas fa-vote-yea text-success me-2"></i>
                            <span class="fw-bold">${electionsData.statistics.active}</span> active election(s)
                        </div>
                        <a href="../elections/" class="btn btn-sm btn-success">Monitor</a>
                    </div>
                `;
            }
            
            if (!tasksHtml) {
                tasksHtml = '<div class="text-center py-3 text-muted">All caught up! 🎉</div>';
            }
            
            document.getElementById('pendingTasks').innerHTML = tasksHtml;
        } else {
            throw new Error(data.message);
        }
    } catch (error) {
        document.getElementById('pendingTasks').innerHTML = '<div class="error">Failed to load tasks</div>';
        console.error('Error loading pending tasks:', error);
    }
}

async function loadCurrentElections() {
    try {
        const response = await fetch('/online_voting/api/elections/list.php?per_page=3');
        const data = await response.json();
        
        if (data.success) {
            const elections = data.data;
            let electionsHtml = '';
            
            if (elections.length > 0) {
                elections.forEach(election => {
                    const startDate = new Date(election.start_date);
                    const endDate = new Date(election.end_date);
                    const statusClass = {
                        'draft': 'secondary',
                        'active': 'success',
                        'completed': 'primary',
                        'cancelled': 'danger'
                    }[election.status] || 'secondary';
                    
                    electionsHtml += `
                        <div class="row border-bottom py-3">
                            <div class="col-md-6">
                                <h6 class="mb-1">${election.name}</h6>
                                <small class="text-muted">${election.election_type_name}</small>
                            </div>
                            <div class="col-md-3">
                                <span class="badge bg-${statusClass}">${election.status.toUpperCase()}</span>
                                <div class="small text-muted mt-1">
                                    ${election.total_candidates} candidates
                                </div>
                            </div>
                            <div class="col-md-3 text-end">
                                <div class="small text-muted">
                                    ${formatDate(startDate)} - ${formatDate(endDate)}
                                </div>
                                <div class="small">
                                    ${election.total_voters || 0} voters
                                </div>
                            </div>
                        </div>
                    `;
                });
            } else {
                electionsHtml = '<div class="text-center py-3 text-muted">No elections found</div>';
            }
            
            document.getElementById('currentElections').innerHTML = electionsHtml;
        } else {
            throw new Error(data.message);
        }
    } catch (error) {
        document.getElementById('currentElections').innerHTML = '<div class="error">Failed to load elections</div>';
        console.error('Error loading elections:', error);
    }
}

function formatNumber(num) {
    return new Intl.NumberFormat().format(num);
}

function formatDate(date) {
    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    }).format(date);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>