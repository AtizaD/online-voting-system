<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['staff']);

$page_title = 'Student Verification';
$breadcrumbs = [
    ['title' => 'Staff Dashboard', 'url' => SITE_URL . 'staff/'],
    ['title' => 'Student Verification']
];

$current_user = getCurrentUser();

include __DIR__ . '/../../includes/header.php';
?>

<style>
.verification-dashboard {
    min-height: 80vh;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    border-radius: 0.75rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    padding: 2rem 1.5rem;
    text-align: center;
    transition: transform 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-2px);
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

.filters-card {
    background: white;
    border-radius: 0.75rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.students-card {
    background: white;
    border-radius: 0.75rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    overflow: hidden;
}

.bulk-actions {
    background: #f8fafc;
    padding: 1rem;
    border: 1px solid #e2e8f0;
    border-bottom: none;
    border-radius: 0.5rem 0.5rem 0 0;
    display: none;
}

.bulk-actions.show {
    display: block;
}

.bulk-actions.show + .table {
    border-radius: 0 0 0.5rem 0.5rem;
}

.student-avatar {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
    margin-right: 0.75rem;
}

.student-info {
    display: flex;
    align-items: center;
}

.student-details h6 {
    margin: 0;
    font-weight: 600;
    color: #1e293b;
}

.student-details small {
    color: #64748b;
}

.status-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 500;
}

.status-pending { 
    background: #fef3c7; 
    color: #d97706; 
}

.status-verified { 
    background: #dcfce7; 
    color: #166534; 
}

.table th {
    background: #f8fafc;
    border-bottom: 2px solid #e2e8f0;
    color: #1e293b;
    font-weight: 600;
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
    margin: 1rem;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: #64748b;
}

.pagination-info {
    background: #f8fafc;
    padding: 1rem 1.5rem;
    border-top: 1px solid #e2e8f0;
    color: #64748b;
    font-size: 0.875rem;
}
</style>

<div class="container-fluid py-4 verification-dashboard">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Student Verification</h2>
                    <p class="text-muted mb-0">Review and approve student verification requests</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics -->
    <div class="stats-grid" id="statsGrid">
        <div class="loading">Loading statistics...</div>
    </div>

    <!-- Filters -->
    <div class="row">
        <div class="col-12">
            <div class="filters-card">
                <form id="filtersForm" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" id="searchInput" class="form-control" 
                               placeholder="Name or student number...">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" id="statusFilter" class="form-select">
                            <option value="">All Status</option>
                            <option value="pending" selected>Pending</option>
                            <option value="verified">Verified</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Program</label>
                        <select name="program" id="programFilter" class="form-select">
                            <option value="">All Programs</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Class</label>
                        <select name="class" id="classFilter" class="form-select">
                            <option value="">All Classes</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Show</label>
                        <select name="per_page" id="perPageFilter" class="form-select">
                            <option value="15">15</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-primary" onclick="loadStudents()">
                                <i class="fas fa-search"></i>
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="clearFilters()">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Results Info -->
    <div class="d-flex justify-content-between align-items-center mb-3" id="resultsInfo" style="display: none;">
        <div class="text-muted" id="paginationInfo"></div>
        <div class="text-muted" id="pageInfo"></div>
    </div>

    <!-- Students Verification Table -->
    <div class="row">
        <div class="col-12">
            <div class="students-card">
                <form id="bulkVerificationForm">
                    <!-- Bulk Actions -->
                    <div class="bulk-actions" id="bulkActions">
                        <div class="d-flex justify-content-between align-items-center">
                            <span id="selectedCount">0 students selected</span>
                            <div>
                                <button type="button" class="btn btn-success btn-sm me-2" onclick="bulkVerify('verified')">
                                    <i class="fas fa-check me-1"></i>Verify Selected
                                </button>
                                <button type="button" class="btn btn-warning btn-sm me-2" onclick="bulkVerify('pending')">
                                    <i class="fas fa-undo me-1"></i>Unverify Selected
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearSelection()">
                                    Clear Selection
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Table Content -->
                    <div id="studentsContent">
                        <div class="loading">
                            <i class="fas fa-spinner fa-spin fa-2x mb-3"></i>
                            <div>Loading students...</div>
                        </div>
                    </div>
                </form>

                <!-- Pagination -->
                <div class="pagination-info" id="paginationContainer"></div>
            </div>
        </div>
    </div>
</div>

<script>
let currentPage = 1;
let selectedStudents = new Set();

document.addEventListener('DOMContentLoaded', function() {
    loadPrograms();
    loadStudents();
    
    // Auto-filter on input changes
    document.getElementById('searchInput').addEventListener('input', debounce(loadStudents, 500));
    document.getElementById('statusFilter').addEventListener('change', loadStudents);
    document.getElementById('programFilter').addEventListener('change', () => {
        loadClassesByProgram();
        loadStudents();
    });
    document.getElementById('classFilter').addEventListener('change', loadStudents);
    document.getElementById('perPageFilter').addEventListener('change', () => {
        currentPage = 1;
        loadStudents();
    });
});

async function loadPrograms() {
    try {
        const response = await fetch('/online_voting/api/students/list.php?per_page=1000');
        const data = await response.json();
        
        if (data.success) {
            const programs = [...new Set(data.data.map(s => s.program_name).filter(Boolean))];
            const classes = [...new Set(data.data.map(s => s.class_name).filter(Boolean))];
            
            // Populate program filter
            const programSelect = document.getElementById('programFilter');
            programSelect.innerHTML = '<option value="">All Programs</option>';
            programs.forEach(program => {
                programSelect.innerHTML += `<option value="${program}">${program}</option>`;
            });
            
            // Populate class filter
            const classSelect = document.getElementById('classFilter');
            classSelect.innerHTML = '<option value="">All Classes</option>';
            classes.forEach(className => {
                classSelect.innerHTML += `<option value="${className}">${className}</option>`;
            });
        }
    } catch (error) {
        console.error('Error loading programs:', error);
    }
}

async function loadClassesByProgram() {
    // For simplicity, this implementation shows all classes
    // In a full implementation, this would filter classes by program
}

async function loadStudents() {
    const formData = new FormData(document.getElementById('filtersForm'));
    const params = new URLSearchParams();
    
    // Add filters
    for (const [key, value] of formData.entries()) {
        if (value.trim()) {
            params.append(key, value);
        }
    }
    
    // Add pagination
    params.append('page', currentPage);
    
    try {
        const response = await fetch(`/online_voting/api/students/list.php?${params.toString()}`);
        const data = await response.json();
        
        if (data.success) {
            displayStudents(data.data);
            updateStatistics(data.statistics);
            updatePagination(data.pagination);
            clearSelection();
        } else {
            throw new Error(data.message);
        }
    } catch (error) {
        document.getElementById('studentsContent').innerHTML = 
            `<div class="error">Failed to load students: ${error.message}</div>`;
        console.error('Error loading students:', error);
    }
}

function displayStudents(students) {
    const container = document.getElementById('studentsContent');
    
    if (students.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-user-graduate fa-4x mb-3"></i>
                <h4>No Students Found</h4>
                <p>No students match your current filters.</p>
            </div>
        `;
        return;
    }
    
    let html = `
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th style="width: 50px;">
                        <input type="checkbox" id="selectAll" class="form-check-input">
                    </th>
                    <th>Student</th>
                    <th>Student Number</th>
                    <th>Program</th>
                    <th>Class</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
    `;
    
    students.forEach(student => {
        const initials = student.first_name.charAt(0) + student.last_name.charAt(0);
        const statusClass = student.verification_status === 'verified' ? 'status-verified' : 'status-pending';
        const statusDate = new Date(student.status_date);
        
        html += `
            <tr>
                <td>
                    <input type="checkbox" name="student_ids[]" value="${student.student_id}" 
                           class="form-check-input student-checkbox">
                </td>
                <td>
                    <div class="student-info">
                        <div class="student-avatar">${initials}</div>
                        <div class="student-details">
                            <h6>${student.first_name} ${student.last_name}</h6>
                            <small>${student.gender}</small>
                        </div>
                    </div>
                </td>
                <td><strong>${student.student_number}</strong></td>
                <td>${student.program_name || 'N/A'}</td>
                <td>${student.class_name || 'N/A'}</td>
                <td>
                    <span class="status-badge ${statusClass}">
                        ${student.verification_status.charAt(0).toUpperCase() + student.verification_status.slice(1)}
                    </span>
                    <div class="small text-muted">
                        ${statusDate.toLocaleDateString()}
                    </div>
                </td>
                <td>
                    ${student.verification_status === 'pending' ? 
                        `<button type="button" class="btn btn-success btn-sm" 
                                onclick="verifyStudent(${student.student_id}, 'verified')">
                            <i class="fas fa-check"></i> Verify
                        </button>` :
                        `<button type="button" class="btn btn-warning btn-sm" 
                                onclick="verifyStudent(${student.student_id}, 'pending')">
                            <i class="fas fa-undo"></i> Unverify
                        </button>`
                    }
                </td>
            </tr>
        `;
    });
    
    html += `
            </tbody>
        </table>
    `;
    
    container.innerHTML = html;
    
    // Attach event listeners
    attachCheckboxListeners();
}

function attachCheckboxListeners() {
    // Select all functionality
    const selectAllCheckbox = document.getElementById('selectAll');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
                if (this.checked) {
                    selectedStudents.add(parseInt(checkbox.value));
                } else {
                    selectedStudents.delete(parseInt(checkbox.value));
                }
            });
            updateBulkActions();
        });
    }
    
    // Individual checkboxes
    document.querySelectorAll('.student-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            if (this.checked) {
                selectedStudents.add(parseInt(this.value));
            } else {
                selectedStudents.delete(parseInt(this.value));
            }
            updateBulkActions();
            updateSelectAllState();
        });
    });
}

function updateSelectAllState() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.student-checkbox');
    const checkedBoxes = document.querySelectorAll('.student-checkbox:checked');
    
    if (selectAllCheckbox && checkboxes.length > 0) {
        selectAllCheckbox.checked = checkedBoxes.length === checkboxes.length;
        selectAllCheckbox.indeterminate = checkedBoxes.length > 0 && checkedBoxes.length < checkboxes.length;
    }
}

function updateBulkActions() {
    const count = selectedStudents.size;
    const bulkActions = document.getElementById('bulkActions');
    const selectedCount = document.getElementById('selectedCount');
    
    if (count > 0) {
        bulkActions.classList.add('show');
        selectedCount.textContent = `${count} student${count === 1 ? '' : 's'} selected`;
    } else {
        bulkActions.classList.remove('show');
    }
}

function clearSelection() {
    selectedStudents.clear();
    document.querySelectorAll('.student-checkbox').forEach(checkbox => {
        checkbox.checked = false;
    });
    const selectAllCheckbox = document.getElementById('selectAll');
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;
    }
    updateBulkActions();
}

function updateStatistics(stats) {
    const statsHtml = `
        <div class="stat-card">
            <h3 class="stat-number">${formatNumber(stats.total)}</h3>
            <p class="stat-label">Total Students</p>
        </div>
        <div class="stat-card">
            <h3 class="stat-number">${formatNumber(stats.pending)}</h3>
            <p class="stat-label">Pending Verification</p>
        </div>
        <div class="stat-card">
            <h3 class="stat-number">${formatNumber(stats.verified)}</h3>
            <p class="stat-label">Verified Students</p>
        </div>
        <div class="stat-card">
            <h3 class="stat-number">${Math.round((stats.verified / stats.total) * 100)}%</h3>
            <p class="stat-label">Verification Rate</p>
        </div>
    `;
    document.getElementById('statsGrid').innerHTML = statsHtml;
}

function updatePagination(pagination) {
    document.getElementById('paginationInfo').textContent = 
        `Showing ${pagination.showing_from} to ${pagination.showing_to} of ${pagination.total_records} students`;
    
    document.getElementById('pageInfo').textContent = 
        `Page ${pagination.current_page} of ${pagination.total_pages}`;
    
    document.getElementById('resultsInfo').style.display = 'flex';
    
    let paginationHtml = '';
    
    if (pagination.total_pages > 1) {
        paginationHtml = '<nav><ul class="pagination justify-content-center mb-0">';
        
        // Previous button
        if (pagination.current_page > 1) {
            paginationHtml += `
                <li class="page-item">
                    <button class="page-link" onclick="changePage(${pagination.current_page - 1})">Previous</button>
                </li>
            `;
        }
        
        // Page numbers
        const start = Math.max(1, pagination.current_page - 2);
        const end = Math.min(pagination.total_pages, pagination.current_page + 2);
        
        for (let i = start; i <= end; i++) {
            paginationHtml += `
                <li class="page-item ${i === pagination.current_page ? 'active' : ''}">
                    <button class="page-link" onclick="changePage(${i})">${i}</button>
                </li>
            `;
        }
        
        // Next button
        if (pagination.current_page < pagination.total_pages) {
            paginationHtml += `
                <li class="page-item">
                    <button class="page-link" onclick="changePage(${pagination.current_page + 1})">Next</button>
                </li>
            `;
        }
        
        paginationHtml += '</ul></nav>';
    }
    
    document.getElementById('paginationContainer').innerHTML = paginationHtml;
}

function changePage(page) {
    currentPage = page;
    loadStudents();
}

function clearFilters() {
    document.getElementById('filtersForm').reset();
    document.getElementById('statusFilter').value = 'pending';
    document.getElementById('perPageFilter').value = '25';
    currentPage = 1;
    loadStudents();
}

async function verifyStudent(studentId, status) {
    const action = status === 'verified' ? 'verify' : 'unverify';
    
    if (confirm(`Are you sure you want to ${action} this student?`)) {
        try {
            const response = await fetch('/online_voting/api/students/verify.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    student_id: studentId,
                    status: status
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                loadStudents();
                showAlert(result.message, 'success');
            } else {
                showAlert('Error: ' + result.message, 'error');
            }
        } catch (error) {
            showAlert('Failed to update student status', 'error');
            console.error('Error updating student:', error);
        }
    }
}

async function bulkVerify(status) {
    if (selectedStudents.size === 0) {
        showAlert('Please select students first', 'warning');
        return;
    }
    
    const action = status === 'verified' ? 'verify' : 'unverify';
    const count = selectedStudents.size;
    
    if (confirm(`Are you sure you want to ${action} ${count} selected student${count > 1 ? 's' : ''}?`)) {
        try {
            const promises = Array.from(selectedStudents).map(studentId => 
                fetch('/online_voting/api/students/verify.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        student_id: studentId,
                        status: status
                    })
                })
            );
            
            const responses = await Promise.all(promises);
            const results = await Promise.all(responses.map(r => r.json()));
            
            const successful = results.filter(r => r.success).length;
            const failed = results.length - successful;
            
            if (successful > 0) {
                showAlert(`${successful} student${successful > 1 ? 's' : ''} ${action === 'verify' ? 'verified' : 'unverified'} successfully${failed > 0 ? `, ${failed} failed` : ''}`, successful === results.length ? 'success' : 'warning');
                loadStudents();
            } else {
                showAlert('All operations failed', 'error');
            }
        } catch (error) {
            showAlert('Failed to process bulk verification', 'error');
            console.error('Error in bulk verification:', error);
        }
    }
}

function showAlert(message, type = 'info') {
    // Create a simple alert - you could use a toast library here
    const alertClass = {
        'success': 'alert-success',
        'error': 'alert-danger',
        'warning': 'alert-warning',
        'info': 'alert-info'
    }[type] || 'alert-info';
    
    const alertHtml = `
        <div class="alert ${alertClass} alert-dismissible fade show position-fixed" 
             style="top: 20px; right: 20px; z-index: 1050; max-width: 400px;">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', alertHtml);
    
    // Auto-dismiss after 5 seconds
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        if (alerts.length > 0) {
            alerts[alerts.length - 1].remove();
        }
    }, 5000);
}

function formatNumber(num) {
    return new Intl.NumberFormat().format(num);
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>