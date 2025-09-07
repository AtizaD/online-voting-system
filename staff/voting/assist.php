<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth(['staff']);

$page_title = 'Assist Students with Voting';
$breadcrumbs = [
    ['title' => 'Staff Dashboard', 'url' => '../'],
    ['title' => 'Assist Students with Voting']
];

$current_user = getCurrentUser();

include __DIR__ . '/../../includes/header.php';
?>

<style>
.assist-card {
    background: white;
    border-radius: 0.75rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    transition: all 0.3s ease;
}

.search-card {
    border: 2px solid #e2e8f0;
    border-radius: 0.5rem;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.student-card {
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    padding: 1rem;
    margin-bottom: 1rem;
    background: #f8fafc;
}

.election-item {
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    padding: 1rem;
    margin-bottom: 0.5rem;
    background: white;
}

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
    font-size: 0.75rem;
    font-weight: 600;
}

.status-eligible { background: #dcfce7; color: #166534; }
.status-voted { background: #dbeafe; color: #1d4ed8; }
.status-ineligible { background: #fee2e2; color: #dc2626; }

.help-section {
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 0.5rem;
    padding: 1rem;
    margin-bottom: 1rem;
}
</style>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="row">
        <div class="col-12">
            <div class="assist-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">Assist Students with Voting</h2>
                        <p class="text-muted mb-0">Help students check their voting status and provide guidance</p>
                    </div>
                    <div class="text-end">
                        <button class="btn btn-outline-primary" onclick="showHelpGuide()">
                            <i class="fas fa-question-circle me-2"></i>Help Guide
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Student Search -->
    <div class="row">
        <div class="col-12">
            <div class="search-card">
                <h5 class="mb-3">Find Student</h5>
                <form id="studentSearchForm" onsubmit="searchStudent(event)">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="studentNumber" class="form-label">Student Number</label>
                                <input type="text" class="form-control" id="studentNumber" placeholder="Enter student number">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="studentName" class="form-label">Student Name</label>
                                <input type="text" class="form-control" id="studentName" placeholder="Enter first or last name">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>&nbsp;</label>
                                <div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search me-2"></i>Search
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary ms-2" onclick="clearSearch()">
                                        <i class="fas fa-times me-2"></i>Clear
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Search Results -->
    <div class="row" id="searchResults" style="display: none;">
        <div class="col-12">
            <div class="assist-card">
                <h5 class="mb-3">Search Results</h5>
                <div id="searchResultsContent"></div>
            </div>
        </div>
    </div>

    <!-- Student Details -->
    <div class="row" id="studentDetails" style="display: none;">
        <div class="col-md-4">
            <div class="assist-card">
                <h5 class="mb-3">Student Information</h5>
                <div id="studentInfo"></div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="assist-card">
                <h5 class="mb-3">Voting Status & Elections</h5>
                <div id="votingStatus"></div>
            </div>
        </div>
    </div>

    <!-- Help Guide Modal -->
    <div class="modal fade" id="helpModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Student Voting Assistance Guide</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="help-section">
                        <h6><i class="fas fa-search text-primary"></i> Finding Students</h6>
                        <ul class="mb-0">
                            <li>Search by student number for exact matches</li>
                            <li>Search by name (first or last name) for broader results</li>
                            <li>Ensure student is verified before they can vote</li>
                        </ul>
                    </div>
                    
                    <div class="help-section">
                        <h6><i class="fas fa-vote-yea text-success"></i> Voting Status</h6>
                        <ul class="mb-0">
                            <li><span class="status-badge status-eligible">Eligible</span> - Student can vote in this election</li>
                            <li><span class="status-badge status-voted">Voted</span> - Student has already cast their vote</li>
                            <li><span class="status-badge status-ineligible">Ineligible</span> - Student cannot vote (not verified or other restrictions)</li>
                        </ul>
                    </div>
                    
                    <div class="help-section">
                        <h6><i class="fas fa-life-ring text-info"></i> Common Issues & Solutions</h6>
                        <ul class="mb-0">
                            <li><strong>Student not found:</strong> Check spelling, verify student number</li>
                            <li><strong>Not eligible to vote:</strong> Ensure student is verified, check program/class restrictions</li>
                            <li><strong>Forgot student number:</strong> Search by name, verify identity with ID</li>
                            <li><strong>Voting problems:</strong> Check internet connection, try different browser</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentStudent = null;

async function searchStudent(event) {
    event.preventDefault();
    
    const studentNumber = document.getElementById('studentNumber').value.trim();
    const studentName = document.getElementById('studentName').value.trim();
    
    if (!studentNumber && !studentName) {
        alert('Please enter either student number or name to search');
        return;
    }
    
    try {
        let searchParams = new URLSearchParams();
        if (studentNumber) searchParams.append('student_number', studentNumber);
        if (studentName) searchParams.append('search', studentName);
        searchParams.append('per_page', '10');
        
        const response = await fetch(`/online_voting/api/students/list.php?${searchParams.toString()}`);
        const data = await response.json();
        
        if (data.success) {
            displaySearchResults(data.data);
        } else {
            throw new Error(data.message);
        }
    } catch (error) {
        alert('Error searching for student: ' + error.message);
        console.error('Search error:', error);
    }
}

function displaySearchResults(students) {
    const resultsDiv = document.getElementById('searchResults');
    const contentDiv = document.getElementById('searchResultsContent');
    
    if (students.length === 0) {
        contentDiv.innerHTML = '<div class="alert alert-warning">No students found matching your search criteria.</div>';
    } else if (students.length === 1) {
        // Show student details directly if only one result
        showStudentDetails(students[0]);
        resultsDiv.style.display = 'none';
        return;
    } else {
        let html = '';
        students.forEach(student => {
            const statusClass = student.verification_status === 'verified' ? 'success' : 'warning';
            html += `
                <div class="student-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">${student.first_name} ${student.last_name}</h6>
                            <small class="text-muted">${student.student_number} • ${student.program_name} - ${student.class_name}</small>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-${statusClass}">${student.verification_status}</span>
                            <button class="btn btn-sm btn-primary ms-2" onclick="showStudentDetails(${JSON.stringify(student).replace(/"/g, '&quot;')})">
                                Select
                            </button>
                        </div>
                    </div>
                </div>
            `;
        });
        contentDiv.innerHTML = html;
    }
    
    resultsDiv.style.display = 'block';
    document.getElementById('studentDetails').style.display = 'none';
}

async function showStudentDetails(student) {
    currentStudent = student;
    
    // Hide search results
    document.getElementById('searchResults').style.display = 'none';
    
    // Show student info
    const studentInfo = `
        <div class="text-center mb-3">
            <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                <i class="fas fa-user fa-2x text-muted"></i>
            </div>
        </div>
        <table class="table table-borderless">
            <tr><td><strong>Name:</strong></td><td>${student.first_name} ${student.last_name}</td></tr>
            <tr><td><strong>Student #:</strong></td><td>${student.student_number}</td></tr>
            <tr><td><strong>Program:</strong></td><td>${student.program_name}</td></tr>
            <tr><td><strong>Class:</strong></td><td>${student.class_name}</td></tr>
            <tr><td><strong>Status:</strong></td><td><span class="badge bg-${student.verification_status === 'verified' ? 'success' : 'warning'}">${student.verification_status}</span></td></tr>
        </table>
        <div class="text-center">
            <button class="btn btn-outline-primary" onclick="clearSearch()">Search Another Student</button>
        </div>
    `;
    
    document.getElementById('studentInfo').innerHTML = studentInfo;
    
    // Load voting status
    await loadVotingStatus(student.student_id);
    
    document.getElementById('studentDetails').style.display = 'block';
}

async function loadVotingStatus(studentId) {
    try {
        const response = await fetch('/online_voting/api/elections/list.php?status=active');
        const data = await response.json();
        
        if (data.success) {
            let statusHtml = '';
            
            if (data.data.length === 0) {
                statusHtml = '<div class="alert alert-info">No active elections at this time.</div>';
            } else {
                data.data.forEach(election => {
                    const canVote = currentStudent.verification_status === 'verified';
                    const statusClass = canVote ? 'eligible' : 'ineligible';
                    const statusText = canVote ? 'Eligible' : 'Not Eligible';
                    
                    statusHtml += `
                        <div class="election-item">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1">${election.name}</h6>
                                    <small class="text-muted">${election.election_type_name} • ${formatDateRange(election.start_date, election.end_date)}</small>
                                </div>
                                <div class="text-end">
                                    <span class="status-badge status-${statusClass}">${statusText}</span>
                                    ${canVote ? `<button class="btn btn-sm btn-success ms-2" onclick="assistVoting(${election.election_id})">Assist Voting</button>` : ''}
                                </div>
                            </div>
                            ${!canVote ? '<div class="small text-danger mt-2"><i class="fas fa-exclamation-triangle"></i> Student must be verified before voting</div>' : ''}
                        </div>
                    `;
                });
            }
            
            document.getElementById('votingStatus').innerHTML = statusHtml;
        }
    } catch (error) {
        document.getElementById('votingStatus').innerHTML = '<div class="alert alert-danger">Failed to load voting status</div>';
        console.error('Error loading voting status:', error);
    }
}

function assistVoting(electionId) {
    // This would redirect to the voting interface with assistance mode
    const assistUrl = `/online_voting/student/vote.php?election_id=${electionId}&assist_mode=1&student_id=${currentStudent.student_id}`;
    if (confirm(`Help ${currentStudent.first_name} ${currentStudent.last_name} vote in this election?\n\nThis will open the voting interface in assistance mode.`)) {
        window.open(assistUrl, '_blank');
    }
}

function clearSearch() {
    document.getElementById('studentNumber').value = '';
    document.getElementById('studentName').value = '';
    document.getElementById('searchResults').style.display = 'none';
    document.getElementById('studentDetails').style.display = 'none';
    currentStudent = null;
}

function showHelpGuide() {
    const modal = new bootstrap.Modal(document.getElementById('helpModal'));
    modal.show();
}

function formatDateRange(startDate, endDate) {
    const start = new Date(startDate);
    const end = new Date(endDate);
    return `${start.toLocaleDateString()} - ${end.toLocaleDateString()}`;
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>