<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['staff']);

$page_title = 'Student Overview';
$breadcrumbs = [
    ['title' => 'Staff Dashboard', 'url' => '../'],
    ['title' => 'Student Overview']
];

$current_user = getCurrentUser();
$db = Database::getInstance()->getConnection();

include __DIR__ . '/../../includes/header.php';
?>

<style>
.overview-card {
    background: white;
    border-radius: 0.75rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    transition: all 0.3s ease;
}

.overview-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 15px -3px rgba(0, 0, 0, 0.1);
}

.stat-card {
    text-align: center;
    padding: 2rem;
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
    <!-- Page Header -->
    <div class="row">
        <div class="col-12">
            <div class="overview-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">Student Overview</h2>
                        <p class="text-muted mb-0">Manage and monitor student records and verification status</p>
                    </div>
                    <div class="text-end">
                        <div class="small text-muted">Last updated</div>
                        <div class="fw-bold" id="lastUpdated">Loading...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid" id="statsGrid">
        <div class="loading">Loading student statistics...</div>
    </div>

    <!-- Quick Actions -->
    <div class="row">
        <div class="col-12">
            <h4 class="mb-3">Student Management Actions</h4>
            <div class="quick-actions">
                <a href="manage.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-users-cog"></i>
                    </div>
                    <div class="action-title">Manage Students</div>
                    <div class="action-description">Add, edit, and update student records</div>
                </a>
                
                <a href="verify.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="action-title">Verify Students</div>
                    <div class="action-description">Review and approve student verification</div>
                </a>
                
                <a href="../reports/students.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="action-title">Student Reports</div>
                    <div class="action-description">Generate detailed student reports</div>
                </a>
                
                <a href="../reports/verification.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div class="action-title">Verification Reports</div>
                    <div class="action-description">Monitor verification progress</div>
                </a>
            </div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="row">
        <div class="col-12">
            <div class="overview-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Recent Student Activity</h5>
                    <a href="manage.php" class="btn btn-sm btn-outline-primary">View All Students</a>
                </div>
                <div id="recentActivity">
                    <div class="loading">Loading recent activity...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    loadStatistics();
    loadRecentActivity();
    updateLastUpdated();
});

async function loadStatistics() {
    try {
        const response = await fetch('/online_voting/api/students/list.php?per_page=1');
        const data = await response.json();
        
        if (data.success) {
            const stats = data.statistics;
            const statsHtml = `
                <div class="overview-card stat-card">
                    <h3 class="stat-number">${formatNumber(stats.total)}</h3>
                    <p class="stat-label">Total Students</p>
                </div>
                <div class="overview-card stat-card">
                    <h3 class="stat-number">${formatNumber(stats.pending)}</h3>
                    <p class="stat-label">Pending Verification</p>
                </div>
                <div class="overview-card stat-card">
                    <h3 class="stat-number">${formatNumber(stats.verified)}</h3>
                    <p class="stat-label">Verified Students</p>
                </div>
                <div class="overview-card stat-card">
                    <h3 class="stat-number">${stats.total > 0 ? Math.round((stats.verified / stats.total) * 100) : 0}%</h3>
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
        const response = await fetch('/online_voting/api/students/list.php?per_page=5');
        const data = await response.json();
        
        if (data.success) {
            const students = data.data;
            let activityHtml = '';
            
            students.forEach(student => {
                const statusDate = new Date(student.status_date);
                const statusClass = student.verification_status === 'verified' ? 'text-success' : 'text-warning';
                const statusIcon = student.verification_status === 'verified' ? 'fa-check-circle' : 'fa-clock';
                
                activityHtml += `
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <div class="d-flex align-items-center">
                            <i class="fas ${statusIcon} ${statusClass} me-2"></i>
                            <div>
                                <div class="fw-bold">${student.first_name} ${student.last_name}</div>
                                <small class="text-muted">${student.program_name} - ${student.class_name}</small>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-${student.verification_status === 'verified' ? 'success' : 'warning'}">${student.verification_status}</span>
                            <div class="small text-muted">${formatDate(statusDate)}</div>
                        </div>
                    </div>
                `;
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

function updateLastUpdated() {
    const now = new Date();
    document.getElementById('lastUpdated').textContent = now.toLocaleTimeString();
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>