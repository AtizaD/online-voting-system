<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['staff']);

$page_title = 'Student Management';
$breadcrumbs = [
    ['title' => 'Staff Dashboard', 'url' => SITE_URL . 'staff/'],
    ['title' => 'Student Management']
];

$current_user = getCurrentUser();
$db = Database::getInstance()->getConnection();

include __DIR__ . '/../../includes/header.php';
?>

<style>
.student-management {
    min-height: 80vh;
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

.students-table {
    margin-bottom: 0;
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

.table th {
    background: #f8fafc;
    border-bottom: 2px solid #e2e8f0;
    color: #1e293b;
    font-weight: 600;
}

.pagination-info {
    color: #64748b;
    font-size: 0.875rem;
}

.btn-group-actions {
    display: flex;
    gap: 0.5rem;
}

.stats-bar {
    background: #f8fafc;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: between;
    align-items: center;
}

.stats-item {
    margin-right: 2rem;
}

.stats-number {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e293b;
}

.stats-label {
    font-size: 0.75rem;
    color: #64748b;
    text-transform: uppercase;
}
</style>

<div class="container-fluid py-4 student-management">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">Student Management</h2>
                    <p class="text-muted mb-0">Manage student records, create new students, and update information</p>
                </div>
                <div>
                    <button class="btn btn-primary" onclick="showAddStudentModal()">
                        <i class="fas fa-plus me-2"></i>Add Student
                    </button>
                </div>
            </div>
        </div>
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
                            <option value="pending">Pending</option>
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
                                <i class="fas fa-search"></i> Filter
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

    <!-- Students Table -->
    <div class="row">
        <div class="col-12">
            <div class="students-card">
                <!-- Statistics Bar -->
                <div class="stats-bar" id="statsBar">
                    <div class="d-flex flex-wrap">
                        <div class="stats-item">
                            <div class="stats-number" id="totalCount">0</div>
                            <div class="stats-label">Total</div>
                        </div>
                        <div class="stats-item">
                            <div class="stats-number" id="pendingCount">0</div>
                            <div class="stats-label">Pending</div>
                        </div>
                        <div class="stats-item">
                            <div class="stats-number" id="verifiedCount">0</div>
                            <div class="stats-label">Verified</div>
                        </div>
                    </div>
                    <div class="pagination-info" id="paginationInfo"></div>
                </div>

                <!-- Table Content -->
                <div id="studentsContent">
                    <div class="loading">
                        <i class="fas fa-spinner fa-spin fa-2x mb-3"></i>
                        <div>Loading students...</div>
                    </div>
                </div>

                <!-- Pagination -->
                <div class="p-3" id="paginationContainer"></div>
            </div>
        </div>
    </div>
</div>

<!-- Add Student Modal -->
<div class="modal fade" id="addStudentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Student</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addStudentForm">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Student Number*</label>
                            <input type="text" name="student_number" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Gender*</label>
                            <select name="gender" class="form-select" required>
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">First Name*</label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name*</label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Program*</label>
                            <select name="program_id" id="addProgramSelect" class="form-select" required>
                                <option value="">Select Program</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Class*</label>
                            <select name="class_id" id="addClassSelect" class="form-select" required>
                                <option value="">Select Class</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Photo URL</label>
                            <input type="url" name="photo_url" class="form-control" placeholder="https://...">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Add Student
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Student Modal -->
<div class="modal fade" id="editStudentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Student</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editStudentForm">
                <input type="hidden" name="student_id" id="editStudentId">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Student Number*</label>
                            <input type="text" name="student_number" id="editStudentNumber" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Gender*</label>
                            <select name="gender" id="editGender" class="form-select" required>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">First Name*</label>
                            <input type="text" name="first_name" id="editFirstName" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name*</label>
                            <input type="text" name="last_name" id="editLastName" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Program*</label>
                            <select name="program_id" id="editProgramSelect" class="form-select" required>
                                <option value="">Select Program</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Class*</label>
                            <select name="class_id" id="editClassSelect" class="form-select" required>
                                <option value="">Select Class</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" id="editPhone" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Photo URL</label>
                            <input type="url" name="photo_url" id="editPhotoUrl" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Update Student
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let currentPage = 1;
let currentFilters = {};
let allPrograms = [];
let allClasses = [];

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
        const response = await fetch('/online_voting/api/students/list.php?per_page=1');
        const data = await response.json();
        
        if (data.success) {
            // Get unique programs from student data
            const studentsResponse = await fetch('/online_voting/api/students/list.php?per_page=1000');
            const studentsData = await studentsResponse.json();
            
            if (studentsData.success) {
                const programs = [...new Set(studentsData.data.map(s => s.program_name).filter(Boolean))];
                const classes = [...new Set(studentsData.data.map(s => s.class_name).filter(Boolean))];
                
                allPrograms = programs;
                allClasses = classes;
                
                // Populate program filters
                const programSelects = [
                    document.getElementById('programFilter'),
                    document.getElementById('addProgramSelect'),
                    document.getElementById('editProgramSelect')
                ];
                
                programSelects.forEach(select => {
                    if (select) {
                        select.innerHTML = '<option value="">Select Program</option>';
                        programs.forEach(program => {
                            select.innerHTML += `<option value="${program}">${program}</option>`;
                        });
                    }
                });
                
                // Populate class filters
                const classSelects = [
                    document.getElementById('classFilter'),
                    document.getElementById('addClassSelect'),
                    document.getElementById('editClassSelect')
                ];
                
                classSelects.forEach(select => {
                    if (select) {
                        select.innerHTML = '<option value="">Select Class</option>';
                        classes.forEach(className => {
                            select.innerHTML += `<option value="${className}">${className}</option>`;
                        });
                    }
                });
            }
        }
    } catch (error) {
        console.error('Error loading programs:', error);
    }
}

async function loadClassesByProgram() {
    // This would need a separate API endpoint or logic to get classes by program
    // For now, we'll show all classes
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
        <table class="table table-hover students-table">
            <thead>
                <tr>
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
        
        html += `
            <tr>
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
                </td>
                <td>
                    <div class="btn-group-actions">
                        <button class="btn btn-sm btn-outline-primary" onclick="editStudent(${student.student_id})" title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        ${student.verification_status === 'pending' ? 
                            `<button class="btn btn-sm btn-success" onclick="verifyStudent(${student.student_id}, 'verified')" title="Verify">
                                <i class="fas fa-check"></i>
                            </button>` :
                            `<button class="btn btn-sm btn-warning" onclick="verifyStudent(${student.student_id}, 'pending')" title="Unverify">
                                <i class="fas fa-undo"></i>
                            </button>`
                        }
                    </div>
                </td>
            </tr>
        `;
    });
    
    html += `
            </tbody>
        </table>
    `;
    
    container.innerHTML = html;
}

function updateStatistics(stats) {
    document.getElementById('totalCount').textContent = stats.total || 0;
    document.getElementById('pendingCount').textContent = stats.pending || 0;
    document.getElementById('verifiedCount').textContent = stats.verified || 0;
}

function updatePagination(pagination) {
    document.getElementById('paginationInfo').textContent = 
        `Showing ${pagination.showing_from} to ${pagination.showing_to} of ${pagination.total_records} students`;
    
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
    document.getElementById('perPageFilter').value = '25';
    currentPage = 1;
    loadStudents();
}

function showAddStudentModal() {
    const modal = new bootstrap.Modal(document.getElementById('addStudentModal'));
    modal.show();
}

// Add Student Form Handler
document.getElementById('addStudentForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = Object.fromEntries(formData);
    
    // Find program and class IDs
    const programName = data.program_id;
    const className = data.class_id;
    
    // For now, we'll use placeholder IDs - in a real system, you'd have proper program/class endpoints
    data.program_id = allPrograms.indexOf(programName) + 1;
    data.class_id = allClasses.indexOf(className) + 1;
    
    try {
        const response = await fetch('/online_voting/api/students/create.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            bootstrap.Modal.getInstance(document.getElementById('addStudentModal')).hide();
            this.reset();
            loadStudents();
            alert('Student added successfully!');
        } else {
            alert('Error: ' + result.message);
        }
    } catch (error) {
        alert('Failed to add student. Please try again.');
        console.error('Error adding student:', error);
    }
});

async function editStudent(studentId) {
    try {
        // For now, we'll get the student from the current table data
        // In a real system, you'd have a GET endpoint for individual students
        const response = await fetch(`/online_voting/api/students/list.php?search=${studentId}&per_page=1`);
        const data = await response.json();
        
        if (data.success && data.data.length > 0) {
            const student = data.data[0];
            
            // Populate edit form
            document.getElementById('editStudentId').value = student.student_id;
            document.getElementById('editStudentNumber').value = student.student_number;
            document.getElementById('editFirstName').value = student.first_name;
            document.getElementById('editLastName').value = student.last_name;
            document.getElementById('editGender').value = student.gender;
            document.getElementById('editPhone').value = student.phone || '';
            document.getElementById('editPhotoUrl').value = student.photo_url || '';
            
            // Set program and class
            document.getElementById('editProgramSelect').value = student.program_name || '';
            document.getElementById('editClassSelect').value = student.class_name || '';
            
            const modal = new bootstrap.Modal(document.getElementById('editStudentModal'));
            modal.show();
        }
    } catch (error) {
        alert('Failed to load student data');
        console.error('Error loading student:', error);
    }
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
                alert(result.message);
            } else {
                alert('Error: ' + result.message);
            }
        } catch (error) {
            alert('Failed to update student status');
            console.error('Error updating student:', error);
        }
    }
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