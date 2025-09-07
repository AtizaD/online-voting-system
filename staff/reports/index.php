<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['staff']);

$page_title = 'Reports Dashboard';
$breadcrumbs = [
    ['title' => 'Staff Dashboard', 'url' => '../'],
    ['title' => 'Reports Dashboard']
];

$current_user = getCurrentUser();

include __DIR__ . '/../../includes/header.php';
?>

<style>
.report-card {
    background: white;
    border-radius: 0.75rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    transition: all 0.3s ease;
}

.report-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 15px -3px rgba(0, 0, 0, 0.1);
}

.report-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.report-item {
    background: white;
    border-radius: 0.5rem;
    padding: 1.5rem;
    text-align: center;
    border: 2px solid #e2e8f0;
    transition: all 0.3s ease;
    text-decoration: none;
    color: inherit;
}

.report-item:hover {
    border-color: var(--primary-color);
    transform: translateY(-2px);
    text-decoration: none;
    color: inherit;
}

.report-icon {
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

.report-title {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.report-description {
    color: #64748b;
    font-size: 0.875rem;
}
</style>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="row">
        <div class="col-12">
            <div class="report-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">Reports Dashboard</h2>
                        <p class="text-muted mb-0">Generate and view various system reports</p>
                    </div>
                    <div class="text-end">
                        <div class="small text-muted">Available Reports</div>
                        <div class="fw-bold">2 Report Types</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Report Types -->
    <div class="row">
        <div class="col-12">
            <h4 class="mb-3">Available Reports</h4>
            <div class="report-grid">
                <a href="students.php" class="report-item">
                    <div class="report-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="report-title">Student Reports</div>
                    <div class="report-description">Comprehensive reports on student enrollment, demographics, and academic information</div>
                </a>
                
                <a href="verification.php" class="report-item">
                    <div class="report-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div class="report-title">Verification Reports</div>
                    <div class="report-description">Track student verification progress and status analytics</div>
                </a>
            </div>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="row">
        <div class="col-12">
            <div class="report-card">
                <h5 class="mb-3">Quick Statistics</h5>
                <div class="row text-center">
                    <div class="col-md-3">
                        <h3 class="text-primary mb-1" id="totalStudents">-</h3>
                        <small class="text-muted">Total Students</small>
                    </div>
                    <div class="col-md-3">
                        <h3 class="text-success mb-1" id="verifiedStudents">-</h3>
                        <small class="text-muted">Verified</small>
                    </div>
                    <div class="col-md-3">
                        <h3 class="text-warning mb-1" id="pendingStudents">-</h3>
                        <small class="text-muted">Pending</small>
                    </div>
                    <div class="col-md-3">
                        <h3 class="text-info mb-1" id="verificationRate">-</h3>
                        <small class="text-muted">Verification Rate</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Report Activity -->
    <div class="row">
        <div class="col-12">
            <div class="report-card">
                <h5 class="mb-3">Report Generation History</h5>
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-chart-bar fa-3x mb-3 opacity-50"></i>
                    <p>No recent report generation activity</p>
                    <small>Reports will appear here once generated</small>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    loadQuickStats();
});

async function loadQuickStats() {
    try {
        const response = await fetch('/online_voting/api/students/list.php?per_page=1');
        const data = await response.json();
        
        if (data.success) {
            const stats = data.statistics;
            document.getElementById('totalStudents').textContent = formatNumber(stats.total);
            document.getElementById('verifiedStudents').textContent = formatNumber(stats.verified);
            document.getElementById('pendingStudents').textContent = formatNumber(stats.pending);
            document.getElementById('verificationRate').textContent = stats.total > 0 ? Math.round((stats.verified / stats.total) * 100) + '%' : '0%';
        }
    } catch (error) {
        console.error('Error loading quick stats:', error);
    }
}

function formatNumber(num) {
    return new Intl.NumberFormat().format(num);
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>