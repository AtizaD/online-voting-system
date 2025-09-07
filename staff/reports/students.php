<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['staff']);

$page_title = 'Reports & Analytics';
$breadcrumbs = [
    ['title' => 'Staff Dashboard', 'url' => SITE_URL . 'staff/'],
    ['title' => 'Reports']
];

$current_user = getCurrentUser();

include __DIR__ . '/../../includes/header.php';
?>

<style>
.reports-dashboard {
    min-height: 80vh;
}

.report-card {
    background: white;
    border-radius: 0.75rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    padding: 2rem;
    margin-bottom: 1.5rem;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.report-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 15px -3px rgba(0, 0, 0, 0.1);
    border-color: var(--primary-color);
}

.report-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
    margin-bottom: 1rem;
}

.report-title {
    font-size: 1.25rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.report-description {
    color: #64748b;
    font-size: 0.875rem;
    margin-bottom: 1.5rem;
}

.report-actions {
    display: flex;
    gap: 0.5rem;
}

.chart-container {
    background: white;
    border-radius: 0.75rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    height: auto;
}

.chart-title {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 1rem;
    color: #1e293b;
}

.loading {
    text-align: center;
    padding: 3rem;
    color: #64748b;
}

.error {
    background: #fee2e2;
    color: #dc2626;
    padding: 1rem;
    border-radius: 0.5rem;
    margin: 1rem 0;
}

.stats-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.summary-card {
    background: white;
    border-radius: 0.75rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    padding: 1.5rem;
    text-align: center;
}

.summary-number {
    font-size: 2rem;
    font-weight: 700;
    margin: 0;
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.summary-label {
    color: #64748b;
    font-size: 0.875rem;
    margin: 0.5rem 0 0;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

#reportModal .modal-dialog {
    max-width: 90%;
}

.report-content {
    max-height: 70vh;
    overflow-y: auto;
}

.export-buttons {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
}
</style>

<div class="container-fluid py-4 reports-dashboard">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Reports & Analytics</h2>
                    <p class="text-muted mb-0">Generate reports and view analytics for student management</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Statistics -->
    <div class="stats-summary" id="statsSummary">
        <div class="loading">Loading summary statistics...</div>
    </div>

    <!-- Quick Charts -->
    <div class="row">
        <div class="col-md-6">
            <div class="chart-container">
                <h5 class="chart-title">Student Verification Status</h5>
                <div style="height: 300px;">
                    <canvas id="verificationChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="chart-container">
                <h5 class="chart-title">Students by Program</h5>
                <div style="height: 300px;">
                    <canvas id="programChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Available Reports -->
    <div class="row">
        <div class="col-12">
            <h4 class="mb-3">Available Reports</h4>
        </div>
        
        <div class="col-md-6 col-lg-4">
            <div class="report-card">
                <div class="report-icon">
                    <i class="fas fa-users"></i>
                </div>
                <h5 class="report-title">Student Directory</h5>
                <p class="report-description">Complete list of all students with their details, verification status, and contact information.</p>
                <div class="report-actions">
                    <button class="btn btn-primary btn-sm" onclick="generateReport('students')">
                        <i class="fas fa-eye me-1"></i>View
                    </button>
                    <button class="btn btn-outline-primary btn-sm" onclick="exportReport('students', 'csv')">
                        <i class="fas fa-download me-1"></i>CSV
                    </button>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 col-lg-4">
            <div class="report-card">
                <div class="report-icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <h5 class="report-title">Verification Report</h5>
                <p class="report-description">Summary of student verification status with pending approvals and recent verifications.</p>
                <div class="report-actions">
                    <button class="btn btn-primary btn-sm" onclick="generateReport('verification')">
                        <i class="fas fa-eye me-1"></i>View
                    </button>
                    <button class="btn btn-outline-primary btn-sm" onclick="exportReport('verification', 'csv')">
                        <i class="fas fa-download me-1"></i>CSV
                    </button>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 col-lg-4">
            <div class="report-card">
                <div class="report-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <h5 class="report-title">Program Summary</h5>
                <p class="report-description">Student distribution across programs and classes with enrollment statistics.</p>
                <div class="report-actions">
                    <button class="btn btn-primary btn-sm" onclick="generateReport('programs')">
                        <i class="fas fa-eye me-1"></i>View
                    </button>
                    <button class="btn btn-outline-primary btn-sm" onclick="exportReport('programs', 'csv')">
                        <i class="fas fa-download me-1"></i>CSV
                    </button>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 col-lg-4">
            <div class="report-card">
                <div class="report-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <h5 class="report-title">Activity Report</h5>
                <p class="report-description">Recent student additions, verifications, and other activities in the system.</p>
                <div class="report-actions">
                    <button class="btn btn-primary btn-sm" onclick="generateReport('activity')">
                        <i class="fas fa-eye me-1"></i>View
                    </button>
                    <button class="btn btn-outline-primary btn-sm" onclick="exportReport('activity', 'csv')">
                        <i class="fas fa-download me-1"></i>CSV
                    </button>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 col-lg-4">
            <div class="report-card">
                <div class="report-icon">
                    <i class="fas fa-vote-yea"></i>
                </div>
                <h5 class="report-title">Election Participation</h5>
                <p class="report-description">Student eligibility and participation rates in elections by program and class.</p>
                <div class="report-actions">
                    <button class="btn btn-primary btn-sm" onclick="generateReport('elections')">
                        <i class="fas fa-eye me-1"></i>View
                    </button>
                    <button class="btn btn-outline-primary btn-sm" onclick="exportReport('elections', 'csv')">
                        <i class="fas fa-download me-1"></i>CSV
                    </button>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 col-lg-4">
            <div class="report-card">
                <div class="report-icon">
                    <i class="fas fa-file-alt"></i>
                </div>
                <h5 class="report-title">Custom Report</h5>
                <p class="report-description">Create custom reports with specific filters and data points.</p>
                <div class="report-actions">
                    <button class="btn btn-primary btn-sm" onclick="showCustomReportModal()">
                        <i class="fas fa-cog me-1"></i>Configure
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Report Modal -->
<div class="modal fade" id="reportModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="reportModalTitle">Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="report-content" id="reportContent">
                    <div class="loading">Generating report...</div>
                </div>
                <div class="export-buttons" id="exportButtons" style="display: none;">
                    <button class="btn btn-success btn-sm" onclick="exportCurrentReport('csv')">
                        <i class="fas fa-file-csv me-1"></i>Export CSV
                    </button>
                    <button class="btn btn-primary btn-sm" onclick="exportCurrentReport('pdf')">
                        <i class="fas fa-file-pdf me-1"></i>Export PDF
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= SITE_URL ?>/assets/js/chart.min.js"></script>

<script>
let currentReportData = null;
let verificationChart = null;
let programChart = null;

document.addEventListener('DOMContentLoaded', function() {
    loadSummaryStats();
    loadCharts();
});

async function loadSummaryStats() {
    try {
        const [studentsResponse, electionsResponse] = await Promise.all([
            fetch('/online_voting/api/students/list.php?per_page=1'),
            fetch('/online_voting/api/elections/list.php?per_page=1')
        ]);
        
        const [studentsData, electionsData] = await Promise.all([
            studentsResponse.json(),
            electionsResponse.json()
        ]);
        
        let summaryHtml = '';
        
        if (studentsData.success) {
            const stats = studentsData.statistics;
            // Convert to numbers to ensure proper calculation
            const total = parseInt(stats.total) || 0;
            const verified = parseInt(stats.verified) || 0;
            const pending = parseInt(stats.pending) || 0;
            const verificationRate = total > 0 ? Math.round((verified / total) * 100) : 0;
            
            summaryHtml = `
                <div class="summary-card">
                    <h3 class="summary-number">${formatNumber(total)}</h3>
                    <p class="summary-label">Total Students</p>
                </div>
                <div class="summary-card">
                    <h3 class="summary-number">${formatNumber(pending)}</h3>
                    <p class="summary-label">Pending Verification</p>
                </div>
                <div class="summary-card">
                    <h3 class="summary-number">${formatNumber(verified)}</h3>
                    <p class="summary-label">Verified Students</p>
                </div>
                <div class="summary-card">
                    <h3 class="summary-number">${verificationRate}%</h3>
                    <p class="summary-label">Verification Rate</p>
                </div>
            `;
            
            if (electionsData.success) {
                const electionStats = electionsData.statistics;
                summaryHtml += `
                    <div class="summary-card">
                        <h3 class="summary-number">${formatNumber(electionStats.active || 0)}</h3>
                        <p class="summary-label">Active Elections</p>
                    </div>
                    <div class="summary-card">
                        <h3 class="summary-number">${formatNumber(electionStats.completed || 0)}</h3>
                        <p class="summary-label">Completed Elections</p>
                    </div>
                `;
            }
        }
        
        document.getElementById('statsSummary').innerHTML = summaryHtml;
        
    } catch (error) {
        document.getElementById('statsSummary').innerHTML = 
            '<div class="error">Failed to load summary statistics</div>';
        console.error('Error loading summary stats:', error);
    }
}

async function loadCharts() {
    try {
        const response = await fetch('/online_voting/api/students/list.php?per_page=10000');
        const data = await response.json();
        
        if (data.success) {
            const students = data.data;
            
            // Verification Status Chart
            const verificationData = {
                pending: students.filter(s => s.verification_status === 'pending').length,
                verified: students.filter(s => s.verification_status === 'verified').length
            };
            
            createVerificationChart(verificationData);
            
            // Program Distribution Chart
            const programData = {};
            students.forEach(student => {
                const program = student.program_name || 'Unknown';
                programData[program] = (programData[program] || 0) + 1;
            });
            
            createProgramChart(programData);
        }
    } catch (error) {
        console.error('Error loading chart data:', error);
    }
}

function createVerificationChart(data) {
    const ctx = document.getElementById('verificationChart').getContext('2d');
    
    if (verificationChart) {
        verificationChart.destroy();
    }
    
    verificationChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Pending', 'Verified'],
            datasets: [{
                data: [data.pending, data.verified],
                backgroundColor: ['#fbbf24', '#10b981'],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}

function createProgramChart(data) {
    const ctx = document.getElementById('programChart').getContext('2d');
    
    if (programChart) {
        programChart.destroy();
    }
    
    const labels = Object.keys(data);
    const values = Object.values(data);
    
    programChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Students',
                data: values,
                backgroundColor: '#3b82f6',
                borderColor: '#1d4ed8',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
}

async function generateReport(reportType) {
    const modal = new bootstrap.Modal(document.getElementById('reportModal'));
    const modalTitle = document.getElementById('reportModalTitle');
    const reportContent = document.getElementById('reportContent');
    
    // Set title and show modal
    const titles = {
        'students': 'Student Directory Report',
        'verification': 'Student Verification Report',
        'programs': 'Program Summary Report',
        'activity': 'Recent Activity Report',
        'elections': 'Election Participation Report'
    };
    
    modalTitle.textContent = titles[reportType] || 'Report';
    modal.show();
    
    // Reset content
    reportContent.innerHTML = '<div class="loading">Generating report...</div>';
    document.getElementById('exportButtons').style.display = 'none';
    
    try {
        let reportHtml = '';
        
        switch (reportType) {
            case 'students':
                reportHtml = await generateStudentsReport();
                break;
            case 'verification':
                reportHtml = await generateVerificationReport();
                break;
            case 'programs':
                reportHtml = await generateProgramsReport();
                break;
            case 'activity':
                reportHtml = await generateActivityReport();
                break;
            case 'elections':
                reportHtml = await generateElectionsReport();
                break;
            default:
                throw new Error('Unknown report type');
        }
        
        reportContent.innerHTML = reportHtml;
        document.getElementById('exportButtons').style.display = 'flex';
        
    } catch (error) {
        reportContent.innerHTML = `<div class="error">Failed to generate report: ${error.message}</div>`;
        console.error('Error generating report:', error);
    }
}

async function generateStudentsReport() {
    const response = await fetch('/online_voting/api/students/list.php?per_page=10000');
    const data = await response.json();
    
    if (!data.success) {
        throw new Error(data.message);
    }
    
    currentReportData = data.data;
    
    let html = `
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Student Number</th>
                        <th>Full Name</th>
                        <th>Gender</th>
                        <th>Program</th>
                        <th>Class</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>Created Date</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    data.data.forEach(student => {
        html += `
            <tr>
                <td>${student.student_number}</td>
                <td>${student.first_name} ${student.last_name}</td>
                <td>${student.gender}</td>
                <td>${student.program_name || 'N/A'}</td>
                <td>${student.class_name || 'N/A'}</td>
                <td>${student.phone || 'N/A'}</td>
                <td>
                    <span class="badge bg-${student.verification_status === 'verified' ? 'success' : 'warning'}">
                        ${student.verification_status.toUpperCase()}
                    </span>
                </td>
                <td>${new Date(student.created_at).toLocaleDateString()}</td>
            </tr>
        `;
    });
    
    html += `
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            <p class="text-muted">Total: ${data.data.length} students</p>
        </div>
    `;
    
    return html;
}

async function generateVerificationReport() {
    const response = await fetch('/online_voting/api/students/list.php?per_page=10000');
    const data = await response.json();
    
    if (!data.success) {
        throw new Error(data.message);
    }
    
    const stats = data.statistics;
    const students = data.data;
    
    // Group by verification status
    const pendingStudents = students.filter(s => s.verification_status === 'pending');
    const verifiedStudents = students.filter(s => s.verification_status === 'verified');
    
    let html = `
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-warning">${stats.pending}</h3>
                        <p class="card-text">Pending Verification</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-success">${stats.verified}</h3>
                        <p class="card-text">Verified Students</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-primary">${Math.round((stats.verified / stats.total) * 100)}%</h3>
                        <p class="card-text">Verification Rate</p>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    if (pendingStudents.length > 0) {
        html += `
            <h5 class="mb-3">Pending Verifications (${pendingStudents.length})</h5>
            <div class="table-responsive mb-4">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Student Number</th>
                            <th>Name</th>
                            <th>Program</th>
                            <th>Class</th>
                            <th>Created Date</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        
        pendingStudents.forEach(student => {
            html += `
                <tr>
                    <td>${student.student_number}</td>
                    <td>${student.first_name} ${student.last_name}</td>
                    <td>${student.program_name || 'N/A'}</td>
                    <td>${student.class_name || 'N/A'}</td>
                    <td>${new Date(student.created_at).toLocaleDateString()}</td>
                </tr>
            `;
        });
        
        html += `
                    </tbody>
                </table>
            </div>
        `;
    }
    
    // Recent verifications (last 30 days)
    const recentVerifications = verifiedStudents.filter(s => {
        if (!s.verified_at) return false;
        const verifiedDate = new Date(s.verified_at);
        const thirtyDaysAgo = new Date();
        thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
        return verifiedDate >= thirtyDaysAgo;
    });
    
    if (recentVerifications.length > 0) {
        html += `
            <h5 class="mb-3">Recent Verifications (${recentVerifications.length})</h5>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Student Number</th>
                            <th>Name</th>
                            <th>Program</th>
                            <th>Verified Date</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        
        recentVerifications.forEach(student => {
            html += `
                <tr>
                    <td>${student.student_number}</td>
                    <td>${student.first_name} ${student.last_name}</td>
                    <td>${student.program_name || 'N/A'}</td>
                    <td>${new Date(student.verified_at).toLocaleDateString()}</td>
                </tr>
            `;
        });
        
        html += `
                    </tbody>
                </table>
            </div>
        `;
    }
    
    currentReportData = {
        summary: stats,
        pending: pendingStudents,
        recent: recentVerifications
    };
    
    return html;
}

async function generateProgramsReport() {
    const response = await fetch('/online_voting/api/students/list.php?per_page=10000');
    const data = await response.json();
    
    if (!data.success) {
        throw new Error(data.message);
    }
    
    const students = data.data;
    
    // Group by program
    const programStats = {};
    students.forEach(student => {
        const program = student.program_name || 'Unknown';
        if (!programStats[program]) {
            programStats[program] = {
                total: 0,
                verified: 0,
                pending: 0,
                classes: new Set()
            };
        }
        programStats[program].total++;
        if (student.verification_status === 'verified') {
            programStats[program].verified++;
        } else {
            programStats[program].pending++;
        }
        if (student.class_name) {
            programStats[program].classes.add(student.class_name);
        }
    });
    
    let html = `
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Program</th>
                        <th>Total Students</th>
                        <th>Verified</th>
                        <th>Pending</th>
                        <th>Verification Rate</th>
                        <th>Classes</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    Object.entries(programStats).forEach(([program, stats]) => {
        const verificationRate = stats.total > 0 ? Math.round((stats.verified / stats.total) * 100) : 0;
        html += `
            <tr>
                <td><strong>${program}</strong></td>
                <td>${stats.total}</td>
                <td><span class="badge bg-success">${stats.verified}</span></td>
                <td><span class="badge bg-warning">${stats.pending}</span></td>
                <td>
                    <div class="progress" style="height: 20px;">
                        <div class="progress-bar" role="progressbar" style="width: ${verificationRate}%">
                            ${verificationRate}%
                        </div>
                    </div>
                </td>
                <td>${Array.from(stats.classes).join(', ')}</td>
            </tr>
        `;
    });
    
    html += `
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            <p class="text-muted">Total Programs: ${Object.keys(programStats).length}</p>
        </div>
    `;
    
    currentReportData = programStats;
    return html;
}

async function generateActivityReport() {
    // This would ideally use an activity log API
    // For now, we'll show recent students and verifications
    const response = await fetch('/online_voting/api/students/list.php?per_page=100');
    const data = await response.json();
    
    if (!data.success) {
        throw new Error(data.message);
    }
    
    const students = data.data;
    
    // Sort by created date (recent first)
    const recentStudents = students
        .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
        .slice(0, 20);
    
    let html = `
        <h5 class="mb-3">Recent Student Activities</h5>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Activity</th>
                        <th>Student</th>
                        <th>Program</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    recentStudents.forEach(student => {
        const createdDate = new Date(student.created_at);
        const verifiedDate = student.verified_at ? new Date(student.verified_at) : null;
        
        // Add creation activity
        html += `
            <tr>
                <td>${createdDate.toLocaleDateString()} ${createdDate.toLocaleTimeString()}</td>
                <td><i class="fas fa-user-plus text-primary"></i> Student Added</td>
                <td>${student.first_name} ${student.last_name}</td>
                <td>${student.program_name || 'N/A'}</td>
                <td><span class="badge bg-info">New</span></td>
            </tr>
        `;
        
        // Add verification activity if exists
        if (verifiedDate && verifiedDate > createdDate) {
            html += `
                <tr>
                    <td>${verifiedDate.toLocaleDateString()} ${verifiedDate.toLocaleTimeString()}</td>
                    <td><i class="fas fa-user-check text-success"></i> Student Verified</td>
                    <td>${student.first_name} ${student.last_name}</td>
                    <td>${student.program_name || 'N/A'}</td>
                    <td><span class="badge bg-success">Verified</span></td>
                </tr>
            `;
        }
    });
    
    html += `
                </tbody>
            </table>
        </div>
    `;
    
    currentReportData = recentStudents;
    return html;
}

async function generateElectionsReport() {
    const [studentsResponse, electionsResponse] = await Promise.all([
        fetch('/online_voting/api/students/list.php?per_page=10000'),
        fetch('/online_voting/api/elections/list.php?per_page=100')
    ]);
    
    const [studentsData, electionsData] = await Promise.all([
        studentsResponse.json(),
        electionsResponse.json()
    ]);
    
    if (!studentsData.success || !electionsData.success) {
        throw new Error('Failed to load election data');
    }
    
    const students = studentsData.data;
    const elections = electionsData.data;
    
    // Calculate eligibility (verified students only)
    const eligibleStudents = students.filter(s => s.verification_status === 'verified');
    
    let html = `
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-primary">${elections.length}</h3>
                        <p class="card-text">Total Elections</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-success">${elections.filter(e => e.status === 'active').length}</h3>
                        <p class="card-text">Active Elections</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-info">${eligibleStudents.length}</h3>
                        <p class="card-text">Eligible Voters</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-warning">${students.filter(s => s.verification_status === 'pending').length}</h3>
                        <p class="card-text">Pending Verification</p>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    if (elections.length > 0) {
        html += `
            <h5 class="mb-3">Elections Overview</h5>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Election</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Candidates</th>
                            <th>Votes Cast</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        
        elections.forEach(election => {
            html += `
                <tr>
                    <td><strong>${election.name}</strong></td>
                    <td>${election.election_type_name || 'N/A'}</td>
                    <td>
                        <span class="badge bg-${getStatusColor(election.status)}">${election.status.toUpperCase()}</span>
                    </td>
                    <td>${new Date(election.start_date).toLocaleDateString()}</td>
                    <td>${new Date(election.end_date).toLocaleDateString()}</td>
                    <td>${election.total_candidates || 0}</td>
                    <td>${election.total_votes || 0}</td>
                </tr>
            `;
        });
        
        html += `
                    </tbody>
                </table>
            </div>
        `;
    }
    
    // Eligibility by program
    const programEligibility = {};
    students.forEach(student => {
        const program = student.program_name || 'Unknown';
        if (!programEligibility[program]) {
            programEligibility[program] = { total: 0, eligible: 0 };
        }
        programEligibility[program].total++;
        if (student.verification_status === 'verified') {
            programEligibility[program].eligible++;
        }
    });
    
    html += `
        <h5 class="mb-3 mt-4">Voter Eligibility by Program</h5>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Program</th>
                        <th>Total Students</th>
                        <th>Eligible Voters</th>
                        <th>Eligibility Rate</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    Object.entries(programEligibility).forEach(([program, stats]) => {
        const eligibilityRate = stats.total > 0 ? Math.round((stats.eligible / stats.total) * 100) : 0;
        html += `
            <tr>
                <td>${program}</td>
                <td>${stats.total}</td>
                <td>${stats.eligible}</td>
                <td>
                    <div class="progress" style="height: 20px;">
                        <div class="progress-bar" role="progressbar" style="width: ${eligibilityRate}%">
                            ${eligibilityRate}%
                        </div>
                    </div>
                </td>
            </tr>
        `;
    });
    
    html += `
                </tbody>
            </table>
        </div>
    `;
    
    currentReportData = {
        elections: elections,
        students: students,
        eligibility: programEligibility
    };
    
    return html;
}

function getStatusColor(status) {
    const colors = {
        'draft': 'secondary',
        'active': 'success',
        'completed': 'primary',
        'cancelled': 'danger'
    };
    return colors[status] || 'secondary';
}

function exportReport(reportType, format) {
    // This would generate and download the report
    alert(`Exporting ${reportType} report as ${format.toUpperCase()}...`);
    // In a real implementation, you would call an export API endpoint
}

function exportCurrentReport(format) {
    if (!currentReportData) {
        alert('No report data to export');
        return;
    }
    
    // This would export the currently displayed report
    alert(`Exporting current report as ${format.toUpperCase()}...`);
    // In a real implementation, you would process currentReportData and generate the file
}

function showCustomReportModal() {
    alert('Custom report builder coming soon!');
}

function formatNumber(num) {
    return new Intl.NumberFormat().format(num);
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>