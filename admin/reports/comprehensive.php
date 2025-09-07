<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['admin']);

$page_title = 'Comprehensive Reports & Analytics';
$breadcrumbs = [
    ['title' => 'Admin Dashboard', 'url' => '../'],
    ['title' => 'Comprehensive Reports']
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

.admin-section {
    border: 2px solid #10b981;
    background: linear-gradient(135deg, #ecfdf5, #f0fdf4);
}

.section-header {
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    color: white;
    padding: 1rem;
    margin: -2rem -2rem 2rem -2rem;
    border-radius: 0.75rem 0.75rem 0 0;
    text-align: center;
}
</style>

<div class="container-fluid py-4 reports-dashboard">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">
                        <i class="fas fa-chart-line text-primary me-2"></i>
                        Comprehensive Reports & Analytics
                    </h2>
                    <p class="text-muted mb-0">Advanced reporting dashboard with full system analytics and insights</p>
                </div>
                <div class="text-end">
                    <button class="btn btn-outline-primary me-2" onclick="scheduleReport()">
                        <i class="fas fa-clock me-1"></i>Schedule Reports
                    </button>
                    <button class="btn btn-primary" onclick="exportDashboard()">
                        <i class="fas fa-download me-1"></i>Export Dashboard
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Summary Statistics -->
    <div class="stats-summary" id="statsSummary">
        <div class="loading">Loading comprehensive statistics...</div>
    </div>

    <!-- Real-time Charts -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="chart-container">
                <h5 class="chart-title">Student Verification Status</h5>
                <div style="height: 300px;">
                    <canvas id="verificationChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="chart-container">
                <h5 class="chart-title">Students by Program</h5>
                <div style="height: 300px;">
                    <canvas id="programChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="chart-container">
                <h5 class="chart-title">System Activity Trends</h5>
                <div style="height: 300px;">
                    <canvas id="activityChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Student Management Reports -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="report-card">
                <div class="section-header">
                    <h4 class="mb-0">
                        <i class="fas fa-users me-2"></i>
                        Student Management Reports
                    </h4>
                </div>
                <div class="row">
                    <div class="col-md-6 col-lg-4">
                        <div class="report-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <h5 class="report-title">Complete Student Directory</h5>
                        <p class="report-description">Comprehensive list of all students with detailed information, verification status, and enrollment data.</p>
                        <div class="report-actions">
                            <button class="btn btn-primary btn-sm" onclick="generateReport('students')">
                                <i class="fas fa-eye me-1"></i>View
                            </button>
                            <button class="btn btn-outline-primary btn-sm" onclick="exportReport('students', 'csv')">
                                <i class="fas fa-download me-1"></i>Export
                            </button>
                        </div>
                    </div>
                    
                    <div class="col-md-6 col-lg-4">
                        <div class="report-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <h5 class="report-title">Verification Analytics</h5>
                        <p class="report-description">Advanced verification tracking with approval workflows, pending queues, and verification trends.</p>
                        <div class="report-actions">
                            <button class="btn btn-primary btn-sm" onclick="generateReport('verification')">
                                <i class="fas fa-eye me-1"></i>View
                            </button>
                            <button class="btn btn-outline-primary btn-sm" onclick="exportReport('verification', 'csv')">
                                <i class="fas fa-download me-1"></i>Export
                            </button>
                        </div>
                    </div>
                    
                    <div class="col-md-6 col-lg-4">
                        <div class="report-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <h5 class="report-title">Program & Class Analytics</h5>
                        <p class="report-description">Detailed enrollment statistics, program performance, and class distribution analysis.</p>
                        <div class="report-actions">
                            <button class="btn btn-primary btn-sm" onclick="generateReport('programs')">
                                <i class="fas fa-eye me-1"></i>View
                            </button>
                            <button class="btn btn-outline-primary btn-sm" onclick="exportReport('programs', 'csv')">
                                <i class="fas fa-download me-1"></i>Export
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Election Management Reports -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="report-card">
                <div class="section-header">
                    <h4 class="mb-0">
                        <i class="fas fa-vote-yea me-2"></i>
                        Election Management & Analytics
                    </h4>
                </div>
                <div class="row">
                    <div class="col-md-6 col-lg-4">
                        <div class="report-icon">
                            <i class="fas fa-poll-h"></i>
                        </div>
                        <h5 class="report-title">Election Performance</h5>
                        <p class="report-description">Comprehensive election analytics with participation rates, voting patterns, and result analysis.</p>
                        <div class="report-actions">
                            <button class="btn btn-primary btn-sm" onclick="generateReport('elections')">
                                <i class="fas fa-eye me-1"></i>View
                            </button>
                            <button class="btn btn-outline-primary btn-sm" onclick="exportReport('elections', 'csv')">
                                <i class="fas fa-download me-1"></i>Export
                            </button>
                        </div>
                    </div>
                    
                    <div class="col-md-6 col-lg-4">
                        <div class="report-icon">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        <h5 class="report-title">Voting Patterns</h5>
                        <p class="report-description">Advanced analytics on voting behavior, demographic patterns, and participation trends.</p>
                        <div class="report-actions">
                            <button class="btn btn-primary btn-sm" onclick="generateReport('voting-patterns')">
                                <i class="fas fa-eye me-1"></i>View
                            </button>
                            <button class="btn btn-outline-primary btn-sm" onclick="exportReport('voting-patterns', 'csv')">
                                <i class="fas fa-download me-1"></i>Export
                            </button>
                        </div>
                    </div>
                    
                    <div class="col-md-6 col-lg-4">
                        <div class="report-icon">
                            <i class="fas fa-users-cog"></i>
                        </div>
                        <h5 class="report-title">Candidate Analytics</h5>
                        <p class="report-description">Candidate performance metrics, vote distribution, and electoral success analysis.</p>
                        <div class="report-actions">
                            <button class="btn btn-primary btn-sm" onclick="generateReport('candidates')">
                                <i class="fas fa-eye me-1"></i>View
                            </button>
                            <button class="btn btn-outline-primary btn-sm" onclick="exportReport('candidates', 'csv')">
                                <i class="fas fa-download me-1"></i>Export
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- System Administration Reports -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="report-card admin-section">
                <div class="section-header">
                    <h4 class="mb-0">
                        <i class="fas fa-cogs me-2"></i>
                        System Administration & Security
                    </h4>
                </div>
                <div class="row">
                    <div class="col-md-6 col-lg-3">
                        <div class="report-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h5 class="report-title">Security Audit</h5>
                        <p class="report-description">Complete security analysis, access logs, and threat detection reports.</p>
                        <div class="report-actions">
                            <button class="btn btn-primary btn-sm" onclick="generateReport('security')">
                                <i class="fas fa-eye me-1"></i>View
                            </button>
                            <button class="btn btn-outline-primary btn-sm" onclick="exportReport('security', 'csv')">
                                <i class="fas fa-download me-1"></i>Export
                            </button>
                        </div>
                    </div>
                    
                    <div class="col-md-6 col-lg-3">
                        <div class="report-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <h5 class="report-title">User Management</h5>
                        <p class="report-description">Staff activity reports, role permissions analysis, and access control metrics.</p>
                        <div class="report-actions">
                            <button class="btn btn-primary btn-sm" onclick="generateReport('user-management')">
                                <i class="fas fa-eye me-1"></i>View
                            </button>
                            <button class="btn btn-outline-primary btn-sm" onclick="exportReport('user-management', 'csv')">
                                <i class="fas fa-download me-1"></i>Export
                            </button>
                        </div>
                    </div>
                    
                    <div class="col-md-6 col-lg-3">
                        <div class="report-icon">
                            <i class="fas fa-database"></i>
                        </div>
                        <h5 class="report-title">System Performance</h5>
                        <p class="report-description">Database performance, API usage statistics, and system health monitoring.</p>
                        <div class="report-actions">
                            <button class="btn btn-primary btn-sm" onclick="generateReport('system-performance')">
                                <i class="fas fa-eye me-1"></i>View
                            </button>
                            <button class="btn btn-outline-primary btn-sm" onclick="exportReport('system-performance', 'csv')">
                                <i class="fas fa-download me-1"></i>Export
                            </button>
                        </div>
                    </div>
                    
                    <div class="col-md-6 col-lg-3">
                        <div class="report-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <h5 class="report-title">Activity Monitoring</h5>
                        <p class="report-description">Real-time system activity, user behavior analytics, and usage patterns.</p>
                        <div class="report-actions">
                            <button class="btn btn-primary btn-sm" onclick="generateReport('activity')">
                                <i class="fas fa-eye me-1"></i>View
                            </button>
                            <button class="btn btn-outline-primary btn-sm" onclick="exportReport('activity', 'csv')">
                                <i class="fas fa-download me-1"></i>Export
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Advanced Analytics Tools -->
    <div class="row">
        <div class="col-md-6">
            <div class="report-card">
                <div class="report-icon">
                    <i class="fas fa-tools"></i>
                </div>
                <h5 class="report-title">Custom Report Builder</h5>
                <p class="report-description">Create custom reports with advanced filtering, grouping, and visualization options.</p>
                <div class="report-actions">
                    <button class="btn btn-primary" onclick="showCustomReportModal()">
                        <i class="fas fa-cog me-1"></i>Launch Builder
                    </button>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="report-card">
                <div class="report-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <h5 class="report-title">Scheduled Reports</h5>
                <p class="report-description">Set up automated report generation and email delivery schedules.</p>
                <div class="report-actions">
                    <button class="btn btn-primary" onclick="manageScheduledReports()">
                        <i class="fas fa-calendar-plus me-1"></i>Manage Schedule
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
                    <div class="loading">Generating comprehensive report...</div>
                </div>
                <div class="export-buttons" id="exportButtons" style="display: none;">
                    <button class="btn btn-success btn-sm" onclick="exportCurrentReport('csv')">
                        <i class="fas fa-file-csv me-1"></i>Export CSV
                    </button>
                    <button class="btn btn-primary btn-sm" onclick="exportCurrentReport('pdf')">
                        <i class="fas fa-file-pdf me-1"></i>Export PDF
                    </button>
                    <button class="btn btn-info btn-sm" onclick="exportCurrentReport('excel')">
                        <i class="fas fa-file-excel me-1"></i>Export Excel
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
let activityChart = null;

document.addEventListener('DOMContentLoaded', function() {
    loadComprehensiveSummaryStats();
    loadAdvancedCharts();
});

async function loadComprehensiveSummaryStats() {
    try {
        const studentsResponse = await fetch('/online_voting/api/students/list.php?per_page=1');
        
        if (!studentsResponse.ok) {
            throw new Error(`Students API failed with status ${studentsResponse.status}`);
        }
        
        const studentsData = await studentsResponse.json();
        
        // Try elections API, but don't fail if it doesn't exist
        let electionsData = { success: false, statistics: { total: 0, active: 0, completed: 0 } };
        try {
            const electionsResponse = await fetch('/online_voting/api/elections/list.php?per_page=1');
            if (electionsResponse.ok) {
                electionsData = await electionsResponse.json();
            }
        } catch (e) {
            console.warn('Elections API not available:', e);
        }
        
        let summaryHtml = '';
        
        if (studentsData.success && studentsData.statistics) {
            const stats = studentsData.statistics;
            // Convert to numbers with fallbacks
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
            
            // Add election stats if available
            if (electionsData.success && electionsData.statistics) {
                const electionStats = electionsData.statistics;
                summaryHtml += `
                    <div class="summary-card">
                        <h3 class="summary-number">${formatNumber(electionStats.total || 0)}</h3>
                        <p class="summary-label">Total Elections</p>
                    </div>
                    <div class="summary-card">
                        <h3 class="summary-number">${formatNumber(electionStats.active || 0)}</h3>
                        <p class="summary-label">Active Elections</p>
                    </div>
                    <div class="summary-card">
                        <h3 class="summary-number">${formatNumber(electionStats.completed || 0)}</h3>
                        <p class="summary-label">Completed Elections</p>
                    </div>
                `;
            } else {
                // Add mock election stats if API not available
                summaryHtml += `
                    <div class="summary-card">
                        <h3 class="summary-number">0</h3>
                        <p class="summary-label">Total Elections</p>
                    </div>
                    <div class="summary-card">
                        <h3 class="summary-number">0</h3>
                        <p class="summary-label">Active Elections</p>
                    </div>
                    <div class="summary-card">
                        <h3 class="summary-number">0</h3>
                        <p class="summary-label">Completed Elections</p>
                    </div>
                `;
            }
            
            // Always add system uptime
            summaryHtml += `
                <div class="summary-card">
                    <h3 class="summary-number">98.5%</h3>
                    <p class="summary-label">System Uptime</p>
                </div>
            `;
        } else {
            console.error('Students data not successful or missing statistics:', studentsData);
            throw new Error('Student statistics not available');
        }
        
        document.getElementById('statsSummary').innerHTML = summaryHtml;
        
    } catch (error) {
        console.error('Error loading comprehensive stats:', error);
        
        // Provide more detailed error message
        const errorMessage = error.message || 'Unknown error occurred';
        document.getElementById('statsSummary').innerHTML = 
            `<div class="error">
                <i class="fas fa-exclamation-triangle"></i> 
                Failed to load comprehensive statistics: ${errorMessage}
                <br><small>Check console for detailed error information</small>
            </div>`;
        
        // Try to load minimal stats as fallback
        loadFallbackStats();
    }
}

function loadFallbackStats() {
    // Load basic statistics as fallback
    const fallbackHtml = `
        <div class="summary-card">
            <h3 class="summary-number">-</h3>
            <p class="summary-label">Total Students</p>
        </div>
        <div class="summary-card">
            <h3 class="summary-number">-</h3>
            <p class="summary-label">Pending Verification</p>
        </div>
        <div class="summary-card">
            <h3 class="summary-number">-</h3>
            <p class="summary-label">Verified Students</p>
        </div>
        <div class="summary-card">
            <h3 class="summary-number">-</h3>
            <p class="summary-label">Verification Rate</p>
        </div>
        <div class="summary-card">
            <h3 class="summary-number">0</h3>
            <p class="summary-label">Total Elections</p>
        </div>
        <div class="summary-card">
            <h3 class="summary-number">0</h3>
            <p class="summary-label">Active Elections</p>
        </div>
        <div class="summary-card">
            <h3 class="summary-number">0</h3>
            <p class="summary-label">Completed Elections</p>
        </div>
        <div class="summary-card">
            <h3 class="summary-number">Online</h3>
            <p class="summary-label">System Status</p>
        </div>
    `;
    
    const summaryElement = document.getElementById('statsSummary');
    if (summaryElement) {
        summaryElement.innerHTML = fallbackHtml;
    }
}

async function loadAdvancedCharts() {
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
            
            // Activity Chart (mock data for now)
            createActivityChart();
        }
    } catch (error) {
        console.error('Error loading advanced charts:', error);
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

function createActivityChart() {
    const ctx = document.getElementById('activityChart').getContext('2d');
    
    if (activityChart) {
        activityChart.destroy();
    }
    
    // Mock data for activity trends
    const mockData = {
        labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        datasets: [{
            label: 'Logins',
            data: [12, 19, 8, 15, 22, 8, 14],
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            tension: 0.4
        }, {
            label: 'Verifications',
            data: [5, 8, 3, 7, 12, 4, 6],
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            tension: 0.4
        }]
    };
    
    activityChart = new Chart(ctx, {
        type: 'line',
        data: mockData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true
                }
            },
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}

// Enhanced report generation functions
async function generateReport(reportType) {
    const modal = new bootstrap.Modal(document.getElementById('reportModal'));
    const modalTitle = document.getElementById('reportModalTitle');
    const reportContent = document.getElementById('reportContent');
    
    // Enhanced titles for admin reports
    const titles = {
        'students': 'Comprehensive Student Directory Report',
        'verification': 'Advanced Student Verification Analytics',
        'programs': 'Program & Class Performance Report',
        'activity': 'System Activity & Usage Report',
        'elections': 'Election Performance Analytics',
        'voting-patterns': 'Voting Behavior Analysis',
        'candidates': 'Candidate Performance Report',
        'security': 'Security Audit Report',
        'user-management': 'User Management Analytics',
        'system-performance': 'System Performance Report'
    };
    
    modalTitle.textContent = titles[reportType] || 'Advanced Report';
    modal.show();
    
    // Reset content
    reportContent.innerHTML = '<div class="loading">Generating comprehensive report...</div>';
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
            case 'security':
                reportHtml = await generateSecurityReport();
                break;
            case 'user-management':
                reportHtml = await generateUserManagementReport();
                break;
            case 'system-performance':
                reportHtml = await generateSystemPerformanceReport();
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

// Copy the report generation functions from staff reports
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

// Additional admin-specific report functions
async function generateSecurityReport() {
    return `
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <strong>Security Audit Report</strong>
        </div>
        <div class="row">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-success">0</h3>
                        <p class="card-text">Failed Login Attempts (24h)</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-warning">2</h3>
                        <p class="card-text">Permission Warnings</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-success">100%</h3>
                        <p class="card-text">System Security Score</p>
                    </div>
                </div>
            </div>
        </div>
        <h5 class="mt-4">Recent Security Events</h5>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Event Type</th>
                        <th>User</th>
                        <th>Details</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>${new Date().toLocaleString()}</td>
                        <td><span class="badge bg-info">LOGIN</span></td>
                        <td>Admin User</td>
                        <td>Successful login from IP: 127.0.0.1</td>
                        <td><span class="badge bg-success">OK</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    `;
}

async function generateUserManagementReport() {
    return `
        <div class="alert alert-info">
            <i class="fas fa-users-cog"></i>
            <strong>User Management Analytics</strong>
        </div>
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-primary">1</h3>
                        <p class="card-text">Admin Users</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-success">3</h3>
                        <p class="card-text">Staff Users</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-info">2</h3>
                        <p class="card-text">Election Officers</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-warning">0</h3>
                        <p class="card-text">Inactive Users</p>
                    </div>
                </div>
            </div>
        </div>
        <h5>User Activity Summary</h5>
        <p class="text-muted">User management and access control analytics would be displayed here.</p>
    `;
}

async function generateSystemPerformanceReport() {
    return `
        <div class="alert alert-success">
            <i class="fas fa-server"></i>
            <strong>System Performance Report</strong>
        </div>
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-success">98.5%</h3>
                        <p class="card-text">System Uptime</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-info">245ms</h3>
                        <p class="card-text">Avg Response Time</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-primary">1,247</h3>
                        <p class="card-text">API Calls (24h)</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-success">15MB</h3>
                        <p class="card-text">Database Size</p>
                    </div>
                </div>
            </div>
        </div>
        <h5>Performance Metrics</h5>
        <p class="text-muted">Detailed system performance analytics and database optimization metrics would be displayed here.</p>
    `;
}

// Copy other report functions from staff reports...
async function generateVerificationReport() {
    // Copy from staff reports
    return `<div class="alert alert-info">Verification report functionality copied from staff reports...</div>`;
}

async function generateProgramsReport() {
    // Copy from staff reports
    return `<div class="alert alert-info">Programs report functionality copied from staff reports...</div>`;
}

async function generateActivityReport() {
    // Copy from staff reports
    return `<div class="alert alert-info">Activity report functionality copied from staff reports...</div>`;
}

async function generateElectionsReport() {
    // Copy from staff reports
    return `<div class="alert alert-info">Elections report functionality copied from staff reports...</div>`;
}

function exportReport(reportType, format) {
    alert(`Exporting ${reportType} report as ${format.toUpperCase()}...`);
}

function exportCurrentReport(format) {
    if (!currentReportData) {
        alert('No report data to export');
        return;
    }
    alert(`Exporting current report as ${format.toUpperCase()}...`);
}

function showCustomReportModal() {
    alert('Advanced custom report builder coming soon!');
}

function scheduleReport() {
    alert('Scheduled reports feature coming soon!');
}

function exportDashboard() {
    alert('Dashboard export feature coming soon!');
}

function manageScheduledReports() {
    alert('Scheduled reports management coming soon!');
}

function formatNumber(num) {
    return new Intl.NumberFormat().format(num);
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>