<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['staff']);

$page_title = 'Verification Reports';
$breadcrumbs = [
    ['title' => 'Staff Dashboard', 'url' => '../'],
    ['title' => 'Reports Dashboard', 'url' => './'],
    ['title' => 'Verification Reports']
];

$current_user = getCurrentUser();

include __DIR__ . '/../../includes/header.php';
?>

<!-- Chart.js removed since charts were removed -->

<style>
.report-card {
    background: white;
    border-radius: 0.75rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    transition: all 0.3s ease;
}

.chart-container {
    position: relative;
    height: 400px;
    margin: 1rem 0;
}

.metric-card {
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    color: white;
    border-radius: 0.5rem;
    padding: 1.5rem;
    text-align: center;
}

.metric-number {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.metric-label {
    font-size: 0.875rem;
    opacity: 0.9;
}

.table-responsive {
    border-radius: 0.5rem;
    overflow: hidden;
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
            <div class="report-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">Verification Reports</h2>
                        <p class="text-muted mb-0">Monitor student verification progress and analytics</p>
                    </div>
                    <div class="text-end">
                        <button class="btn btn-primary" onclick="exportReport()">
                            <i class="fas fa-download me-2"></i>Export Report
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Key Metrics -->
    <div class="row">
        <div class="col-md-3">
            <div class="metric-card">
                <div class="metric-number" id="totalStudents">-</div>
                <div class="metric-label">Total Students</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="metric-card">
                <div class="metric-number" id="verifiedCount">-</div>
                <div class="metric-label">Verified</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="metric-card">
                <div class="metric-number" id="pendingCount">-</div>
                <div class="metric-label">Pending</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="metric-card">
                <div class="metric-number" id="verificationRate">-%</div>
                <div class="metric-label">Completion Rate</div>
            </div>
        </div>
    </div>

    <!-- Charts removed due to display issues -->

    <!-- Verification Progress by Class -->
    <div class="row">
        <div class="col-12">
            <div class="report-card">
                <h5 class="mb-3">Verification Progress by Class</h5>
                <div class="table-responsive">
                    <table class="table table-striped" id="classProgressTable">
                        <thead class="table-dark">
                            <tr>
                                <th>Program</th>
                                <th>Class</th>
                                <th>Total Students</th>
                                <th>Verified</th>
                                <th>Pending</th>
                                <th>Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="6" class="loading">Loading class data...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Verification Activity -->
    <div class="row">
        <div class="col-12">
            <div class="report-card">
                <h5 class="mb-3">Recent Verification Activity</h5>
                <div id="recentActivity">
                    <div class="loading">Loading recent activity...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let statusChart, programChart;

document.addEventListener('DOMContentLoaded', function() {
    loadMetrics();
    loadClassProgress();
    loadRecentActivity();
});

async function loadMetrics() {
    try {
        const response = await fetch('/online_voting/api/students/list.php?per_page=10000');
        const data = await response.json();
        
        if (data.success) {
            const stats = data.statistics;
            
            // Convert strings to numbers for proper calculation
            const total = parseInt(stats.total) || 0;
            const verified = parseInt(stats.verified) || 0;
            const pending = parseInt(stats.pending) || 0;
            
            document.getElementById('totalStudents').textContent = formatNumber(total);
            document.getElementById('verifiedCount').textContent = formatNumber(verified);
            document.getElementById('pendingCount').textContent = formatNumber(pending);
            
            const rate = total > 0 ? Math.round((verified / total) * 100) : 0;
            document.getElementById('verificationRate').textContent = rate + '%';
            
            // Charts removed - just display the statistics
        } else {
            console.error('API response not successful:', data);
        }
    } catch (error) {
        console.error('Error loading metrics:', error);
    }
}

// Chart functions removed

async function loadClassProgress() {
    try {
        const response = await fetch('/online_voting/api/students/list.php?per_page=10000');
        const data = await response.json();
        
        if (data.success) {
            
            const classStats = {};
            
            data.data.forEach(student => {
                // Handle null values and clean data
                const programName = String(student.program_name || 'No Program').trim();
                const className = String(student.class_name || 'No Class').trim();
                const key = `${programName}|${className}`;
                
                if (!classStats[key]) {
                    classStats[key] = {
                        program: programName,
                        class: className,
                        total: 0,
                        verified: 0,
                        pending: 0
                    };
                }
                
                classStats[key].total++;
                if (student.verification_status === 'verified') {
                    classStats[key].verified++;
                } else {
                    classStats[key].pending++;
                }
            });
            
            displayClassProgress(classStats);
        }
    } catch (error) {
        console.error('Error loading class progress:', error);
        document.querySelector('#classProgressTable tbody').innerHTML = 
            '<tr><td colspan="6" class="error">Failed to load class data</td></tr>';
    }
}

function displayClassProgress(classStats) {
    const tbody = document.querySelector('#classProgressTable tbody');
    
    // Clear existing content
    tbody.innerHTML = '';
    
    // Convert to array and sort for consistent display
    const sortedStats = Object.values(classStats).sort((a, b) => {
        if (a.program !== b.program) return a.program.localeCompare(b.program);
        return a.class.localeCompare(b.class);
    });
    
    sortedStats.forEach(stat => {
        // Sanitize data and handle potential undefined values
        const program = String(stat.program || 'Unknown').trim();
        const className = String(stat.class || 'Unknown').trim();
        const total = parseInt(stat.total) || 0;
        const verified = parseInt(stat.verified) || 0;
        const pending = parseInt(stat.pending) || 0;
        
        const progressPercent = total > 0 ? Math.round((verified / total) * 100) : 0;
        const progressClass = progressPercent >= 80 ? 'success' : progressPercent >= 50 ? 'warning' : 'danger';
        
        // Create row element to avoid HTML string issues
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${escapeHtml(program)}</td>
            <td>${escapeHtml(className)}</td>
            <td>${total}</td>
            <td><span class="badge bg-success">${verified}</span></td>
            <td><span class="badge bg-warning">${pending}</span></td>
            <td>
                <div class="progress" style="height: 20px;">
                    <div class="progress-bar bg-${progressClass}" role="progressbar" 
                         style="width: ${progressPercent}%" aria-valuenow="${progressPercent}" 
                         aria-valuemin="0" aria-valuemax="100">
                        ${progressPercent}%
                    </div>
                </div>
            </td>
        `;
        tbody.appendChild(row);
    });
}

// Helper function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

async function loadRecentActivity() {
    try {
        const response = await fetch('/online_voting/api/students/list.php?per_page=10');
        const data = await response.json();
        
        if (data.success) {
            const students = data.data.filter(student => {
                const statusDate = new Date(student.status_date);
                const isRecent = (Date.now() - statusDate.getTime()) < (7 * 24 * 60 * 60 * 1000);
                return isRecent && student.verification_status === 'verified';
            });
            
            let activityHtml = '';
            
            students.slice(0, 5).forEach(student => {
                const statusDate = new Date(student.status_date);
                activityHtml += `
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-check-circle text-success me-2"></i>
                            <div>
                                <div class="fw-bold">${student.first_name} ${student.last_name}</div>
                                <small class="text-muted">${student.program_name} - ${student.class_name}</small>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-success">Verified</span>
                            <div class="small text-muted">${formatDate(statusDate)}</div>
                        </div>
                    </div>
                `;
            });
            
            if (!activityHtml) {
                activityHtml = '<div class="text-center py-3 text-muted">No recent verification activity</div>';
            }
            
            document.getElementById('recentActivity').innerHTML = activityHtml;
        }
    } catch (error) {
        document.getElementById('recentActivity').innerHTML = '<div class="error">Failed to load recent activity</div>';
        console.error('Error loading recent activity:', error);
    }
}

function exportReport() {
    // This would typically generate a CSV or PDF export
    alert('Export functionality would be implemented here. This would generate a detailed verification report in CSV or PDF format.');
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