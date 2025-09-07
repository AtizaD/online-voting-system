<?php
require_once '../../config/config.php';
require_once '../../auth/session.php';
require_once '../../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

// Get student ID if editing
$editing_student = null;
$student_id = intval($_GET['student_id'] ?? 0);

$page_title = $editing_student ? 'Edit Student' : 'Add Student';
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index'],
    ['title' => 'Students', 'url' => './index'],
    ['title' => 'Manage', 'url' => './manage'],
    ['title' => $editing_student ? 'Edit Student' : 'Add Student']
];

// Handle success/error messages from session
$success = '';
$error = '';

if (isset($_SESSION['students_success'])) {
    $success = $_SESSION['students_success'];
    unset($_SESSION['students_success']);
}

if (isset($_SESSION['students_error'])) {
    $error = $_SESSION['students_error'];
    unset($_SESSION['students_error']);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // AJAX duplicate checking
        if ($action === 'check_duplicate') {
            $first_name = sanitize($_POST['first_name'] ?? '');
            $last_name = sanitize($_POST['last_name'] ?? '');
            $class_id = intval($_POST['class_id'] ?? 0);
            
            $stmt = $db->prepare(
                "SELECT student_id, student_number 
                 FROM students 
                 WHERE (
                    (LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?))
                    OR 
                    (LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?))
                 )
                 AND class_id = ?"
            );
            $stmt->execute([
                $first_name,
                $last_name,
                $last_name,  // Swapped order check
                $first_name, // Swapped order check
                $class_id
            ]);
            
            $existingStudent = $stmt->fetch();
            
            header('Content-Type: application/json');
            if ($existingStudent) {
                echo json_encode([
                    'duplicate' => true,
                    'existing_student_id' => $existingStudent['student_number'],
                    'debug' => [
                        'search_name' => "$first_name $last_name",
                        'class_id' => $class_id,
                        'found' => $existingStudent['student_number']
                    ]
                ]);
            } else {
                echo json_encode([
                    'duplicate' => false,
                    'debug' => [
                        'search_name' => "$first_name $last_name", 
                        'class_id' => $class_id
                    ]
                ]);
            }
            exit;
        }
        
        if ($action === 'add_student') {
            $first_name = sanitize($_POST['first_name'] ?? '');
            $last_name = sanitize($_POST['last_name'] ?? '');
            $program_id = intval($_POST['program_id'] ?? 0);
            $class_id = intval($_POST['class_id'] ?? 0);
            $gender = sanitize($_POST['gender'] ?? '');
            $is_verified = 0; // New students are unverified by default
            
            if (empty($first_name) || empty($last_name) || !$program_id || !$class_id || empty($gender)) {
                throw new Exception('All required fields must be filled');
            }
            
            if (!in_array($gender, ['Male', 'Female'])) {
                throw new Exception('Gender must be Male or Female');
            }
            
            // Verify program and class combination and get class info
            $stmt = $db->prepare("SELECT c.class_name FROM classes c WHERE c.class_id = ? AND c.program_id = ?");
            $stmt->execute([$class_id, $program_id]);
            $classInfo = $stmt->fetch();
            if (!$classInfo) {
                throw new Exception('Invalid program and class combination');
            }
            
            // Check if a student with the same name already exists in this class
            $stmt = $db->prepare(
                "SELECT student_id, student_number 
                 FROM students 
                 WHERE (
                    (LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?))
                    OR 
                    (LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?))
                 )
                 AND class_id = ?"
            );
            $stmt->execute([
                strtolower($first_name),
                strtolower($last_name),
                strtolower($last_name),  // Swapped order check
                strtolower($first_name), // Swapped order check
                $class_id
            ]);
            
            $existingStudent = $stmt->fetch();
            if ($existingStudent) {
                throw new Exception("A student with this name is already registered in this class. Student ID: {$existingStudent['student_number']}. Please verify if this is a duplicate.");
            }
            
            // Generate student number automatically
            // Generate base student number from class name and initials
            $nameParts = array_merge(
                explode(' ', $first_name),
                explode(' ', $last_name)
            );
            array_walk($nameParts, function(&$part) {
                $part = trim($part);
            });
            $nameParts = array_filter($nameParts);
            sort($nameParts, SORT_STRING | SORT_FLAG_CASE);

            // Generate initials from sorted names
            $initials = '';
            foreach ($nameParts as $part) {
                $initials .= substr($part, 0, 1);
            }
            $initials = strtoupper($initials);
            $baseStudentNumber = trim($classInfo['class_name']) . '-' . $initials;

            // Find the highest existing suffix for this base student number
            $stmt = $db->prepare(
                "SELECT student_number FROM students 
                 WHERE student_number LIKE ? 
                 ORDER BY CAST(SUBSTRING(student_number, -3) AS UNSIGNED) DESC 
                 LIMIT 1"
            );
            $stmt->execute([$baseStudentNumber . '%']);
            $lastStudentNumber = $stmt->fetch();

            // Determine the next suffix to use
            if ($lastStudentNumber) {
                // Extract the numeric suffix and increment it
                preg_match('/(\d+)$/', $lastStudentNumber['student_number'], $matches);
                $suffix = isset($matches[1]) ? (intval($matches[1]) + 1) : 1;
            } else {
                $suffix = 1;
            }

            $student_number = $baseStudentNumber . str_pad($suffix, 3, '0', STR_PAD_LEFT);

            // Double check that this student number doesn't already exist (extra safety check)
            $stmt = $db->prepare("SELECT student_number FROM students WHERE student_number = ?");
            $stmt->execute([$student_number]);
            if ($stmt->fetch()) {
                // This should rarely happen if our suffix calculation is correct,
                // but as a fallback, we'll keep incrementing until we find an unused student number
                $counter = $suffix + 1;
                $isUnique = false;
                
                while (!$isUnique && $counter < 1000) { // Set a reasonable limit
                    $candidateStudentNumber = $baseStudentNumber . str_pad($counter, 3, '0', STR_PAD_LEFT);
                    $stmt = $db->prepare("SELECT student_number FROM students WHERE student_number = ?");
                    $stmt->execute([$candidateStudentNumber]);
                    if (!$stmt->fetch()) {
                        $student_number = $candidateStudentNumber;
                        $isUnique = true;
                    }
                    $counter++;
                }
                
                if (!$isUnique) {
                    throw new Exception('Unable to generate a unique student number. Please contact support.');
                }
            }
            
            $stmt = $db->prepare("
                INSERT INTO students (student_number, first_name, last_name, program_id, class_id, gender, is_verified, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$student_number, $first_name, $last_name, $program_id, $class_id, $gender, $is_verified, $current_user['id']]);
            
            logActivity('student_add', "Added student: {$first_name} {$last_name} ({$student_number})", $current_user['id']);
            $_SESSION['students_success'] = 'Student added successfully';
            header('Location: manage');
            exit;
            
        } elseif ($action === 'update_student') {
            $student_id = intval($_POST['student_id'] ?? 0);
            $student_number = sanitize($_POST['student_number'] ?? '');
            $first_name = sanitize($_POST['first_name'] ?? '');
            $last_name = sanitize($_POST['last_name'] ?? '');
            $program_id = intval($_POST['program_id'] ?? 0);
            $class_id = intval($_POST['class_id'] ?? 0);
            $gender = sanitize($_POST['gender'] ?? '');
            
            // Get current student data to preserve status fields
            $stmt = $db->prepare("SELECT is_verified, is_active FROM students WHERE student_id = ?");
            $stmt->execute([$student_id]);
            $currentStudent = $stmt->fetch();
            $is_verified = $currentStudent['is_verified'] ?? 0;
            $is_active = $currentStudent['is_active'] ?? 1;
            
            if (!$student_id || empty($student_number) || empty($first_name) || empty($last_name) || !$program_id || !$class_id || empty($gender)) {
                throw new Exception('All required fields must be filled');
            }
            
            // Check if student number exists for another student
            $stmt = $db->prepare("SELECT student_id FROM students WHERE student_number = ? AND student_id != ?");
            $stmt->execute([$student_number, $student_id]);
            if ($stmt->fetch()) {
                throw new Exception('Student number already exists');
            }
            
            // Verify program and class combination
            $stmt = $db->prepare("SELECT class_id FROM classes WHERE class_id = ? AND program_id = ?");
            $stmt->execute([$class_id, $program_id]);
            if (!$stmt->fetch()) {
                throw new Exception('Invalid program and class combination');
            }
            
            $stmt = $db->prepare("
                UPDATE students 
                SET student_number = ?, first_name = ?, last_name = ?, program_id = ?, class_id = ?, 
                    gender = ?, is_verified = ?, is_active = ?, updated_at = NOW()
                WHERE student_id = ?
            ");
            $stmt->execute([$student_number, $first_name, $last_name, $program_id, $class_id, $gender, $is_verified, $is_active, $student_id]);
            
            logActivity('student_update', "Updated student: {$first_name} {$last_name} ({$student_number})", $current_user['id']);
            $_SESSION['students_success'] = 'Student updated successfully';
            header('Location: manage');
            exit;
            
        } elseif ($action === 'delete_student') {
            $student_id = intval($_POST['student_id'] ?? 0);
            
            if (!$student_id) {
                throw new Exception('Invalid student ID');
            }
            
            // Check if student is a candidate in any election
            $stmt = $db->prepare("SELECT COUNT(*) as candidate_count FROM candidates WHERE student_id = ?");
            $stmt->execute([$student_id]);
            $candidate_count = $stmt->fetch();
            
            if ($candidate_count['candidate_count'] > 0) {
                throw new Exception('Cannot delete student who is a candidate in elections');
            }
            
            // Get student info for logging
            $stmt = $db->prepare("SELECT first_name, last_name, student_number FROM students WHERE student_id = ?");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch();
            
            if (!$student) {
                throw new Exception('Student not found');
            }
            
            $stmt = $db->prepare("DELETE FROM students WHERE student_id = ?");
            $stmt->execute([$student_id]);
            
            logActivity('student_delete', "Deleted student: {$student['first_name']} {$student['last_name']} ({$student['student_number']})", $current_user['id']);
            $_SESSION['students_success'] = 'Student deleted successfully';
            header('Location: manage');
            exit;
        }
        
    } catch (Exception $e) {
        $_SESSION['students_error'] = $e->getMessage();
        logError("Students management error: " . $e->getMessage());
        header('Location: ' . $_SERVER['PHP_SELF'] . ($student_id ? "?student_id={$student_id}" : ''));
        exit;
    }
}

// Get data for the page
try {
    $db = Database::getInstance()->getConnection();
    
    // Get programs
    $stmt = $db->prepare("SELECT * FROM programs WHERE is_active = 1 ORDER BY program_name");
    $stmt->execute();
    $programs = $stmt->fetchAll();
    
    // Get classes with program info
    $stmt = $db->prepare("
        SELECT c.*, p.program_name, l.level_name 
        FROM classes c 
        JOIN programs p ON c.program_id = p.program_id 
        JOIN levels l ON c.level_id = l.level_id 
        WHERE c.is_active = 1 
        ORDER BY p.program_name, l.level_name, c.class_name
    ");
    $stmt->execute();
    $classes = $stmt->fetchAll();
    
    // If editing, get student details
    if ($student_id) {
        $stmt = $db->prepare("
            SELECT s.*, p.program_name, c.class_name, l.level_name
            FROM students s
            JOIN programs p ON s.program_id = p.program_id
            JOIN classes c ON s.class_id = c.class_id
            JOIN levels l ON c.level_id = l.level_id
            WHERE s.student_id = ?
        ");
        $stmt->execute([$student_id]);
        $editing_student = $stmt->fetch();
        
        if (!$editing_student) {
            $_SESSION['students_error'] = 'Student not found';
            header('Location: manage');
            exit;
        }
    }
    
} catch (Exception $e) {
    logError("Students add data fetch error: " . $e->getMessage());
    $_SESSION['students_error'] = "Unable to load form data";
    header('Location: manage');
    exit;
}

include '../../includes/header.php';
?>

<!-- Add/Edit Student Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="fas fa-user-<?= $editing_student ? 'edit' : 'plus' ?> me-2"></i><?= $editing_student ? 'Edit Student' : 'Add New Student' ?>
        </h4>
        <small class="text-muted"><?= $editing_student ? 'Update student information' : 'Add a new student to the system' ?></small>
    </div>
    <div class="d-flex gap-2">
        <a href="manage" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Management
        </a>
        <?php if ($editing_student): ?>
            <a href="add" class="btn btn-success">
                <i class="fas fa-plus me-2"></i>Add New
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Alert Messages -->
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <?= sanitize($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?= sanitize($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Main Form -->
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-<?= $editing_student ? 'edit' : 'user-plus' ?> me-2"></i>
                    <?= $editing_student ? 'Edit Student' : 'Add New Student' ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="<?= $editing_student ? 'update_student' : 'add_student' ?>">
                    <?php if ($editing_student): ?>
                        <input type="hidden" name="student_id" value="<?= $editing_student['student_id'] ?>">
                    <?php endif; ?>
                    
                    <!-- Student ID Section (for editing only) -->
                    <?php if ($editing_student): ?>
                        <div class="row mb-3">
                            <div class="col-12">
                                <div class="alert alert-secondary d-flex align-items-center">
                                    <i class="fas fa-id-card me-3"></i>
                                    <div>
                                        <strong>Student ID:</strong> <?= sanitize($editing_student['student_number']) ?>
                                        <small class="d-block text-muted">Student number can be modified when editing</small>
                                        <input type="hidden" name="student_number" value="<?= sanitize($editing_student['student_number']) ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Personal Information -->
                    <div class="row">
                        <div class="col-12">
                            <h6 class="text-primary mb-3"><i class="fas fa-user me-2"></i>Personal Information</h6>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="first_name" name="first_name" 
                                       value="<?= $editing_student ? sanitize($editing_student['first_name']) : '' ?>" 
                                       required placeholder="Enter first name">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="last_name" name="last_name" 
                                       value="<?= $editing_student ? sanitize($editing_student['last_name']) : '' ?>" 
                                       required placeholder="Enter last name">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-4">
                                <label class="form-label">Gender <span class="text-danger">*</span></label>
                                <div class="d-flex gap-4 mt-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="gender" id="gender_male" value="Male" 
                                               <?= ($editing_student && $editing_student['gender'] === 'Male') ? 'checked' : '' ?> required>
                                        <label class="form-check-label" for="gender_male">
                                            <i class="fas fa-male me-1"></i> Male
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="gender" id="gender_female" value="Female" 
                                               <?= ($editing_student && $editing_student['gender'] === 'Female') ? 'checked' : '' ?> required>
                                        <label class="form-check-label" for="gender_female">
                                            <i class="fas fa-female me-1"></i> Female
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Academic Information -->
                    <div class="row">
                        <div class="col-12">
                            <h6 class="text-primary mb-3"><i class="fas fa-graduation-cap me-2"></i>Academic Information</h6>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="program_id" class="form-label">Program <span class="text-danger">*</span></label>
                                <select class="form-select" id="program_id" name="program_id" required onchange="loadClasses()">
                                    <option value="">Choose program...</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?= $program['program_id'] ?>" 
                                                <?= ($editing_student && $editing_student['program_id'] == $program['program_id']) ? 'selected' : '' ?>>
                                            <?= sanitize($program['program_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="level_id" class="form-label">Level <span class="text-danger">*</span></label>
                                <select class="form-select" id="level_id" name="level_id" required onchange="loadClasses()">
                                    <option value="">Choose level...</option>
                                    <?php 
                                    // Get levels from database
                                    $stmt = $db->prepare("SELECT * FROM levels WHERE is_active = 1 ORDER BY level_order");
                                    $stmt->execute();
                                    $levels = $stmt->fetchAll();
                                    ?>
                                    <?php foreach ($levels as $level): ?>
                                        <option value="<?= $level['level_id'] ?>" 
                                                <?php if ($editing_student): ?>
                                                    <?php 
                                                    $stmt = $db->prepare("SELECT level_id FROM classes WHERE class_id = ?");
                                                    $stmt->execute([$editing_student['class_id']]);
                                                    $student_level = $stmt->fetch();
                                                    ?>
                                                    <?= ($student_level && $student_level['level_id'] == $level['level_id']) ? 'selected' : '' ?>
                                                <?php endif; ?>>
                                            <?= sanitize($level['level_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="class_id" class="form-label">Class <span class="text-danger">*</span></label>
                                <select class="form-select" id="class_id" name="class_id" required>
                                    <option value="">Choose class...</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Student ID Preview (for new students only) -->
                    <?php if (!$editing_student): ?>
                        <div class="row" id="studentIdPreview" style="display: none;">
                            <div class="col-12">
                                <h6 class="text-primary mb-3"><i class="fas fa-preview me-2"></i>Generated Student ID Preview</h6>
                            </div>
                            <div class="col-12">
                                <div class="alert alert-info d-flex align-items-center" id="previewAlert">
                                    <i class="fas fa-id-badge me-3 fa-2x"></i>
                                    <div class="w-100">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <strong>Student ID: <span id="previewStudentId">-</span></strong>
                                                <small class="d-block text-muted">Auto-generated student number</small>
                                            </div>
                                            <div class="col-md-6">
                                                <strong>Login Username: <span id="previewUsername">-</span></strong>
                                                <small class="d-block text-muted">Same as Student ID</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Duplicate Warning -->
                                <div class="alert alert-warning d-flex align-items-center" id="duplicateWarning" style="display: none;">
                                    <i class="fas fa-exclamation-triangle me-3 fa-2x"></i>
                                    <div>
                                        <strong>Duplicate Student Detected!</strong>
                                        <p class="mb-0" id="duplicateMessage"></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="d-flex gap-2 justify-content-end">
                        <a href="manage" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-<?= $editing_student ? 'save' : 'plus' ?> me-2"></i>
                            <?= $editing_student ? 'Update Student' : 'Add Student' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>    <div class="col-lg-4">
        <!-- Quick Actions -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-bolt me-2"></i>Quick Actions
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="manage" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-users-cog me-2"></i>Manage Students
                    </a>
                    <a href="verify" class="btn btn-outline-success btn-sm">
                        <i class="fas fa-user-check me-2"></i>Verify Students
                    </a>
                    <a href="import" class="btn btn-outline-warning btn-sm">
                        <i class="fas fa-upload me-2"></i>Import CSV
                    </a>
                    <a href="export" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-download me-2"></i>Export Data
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Guidelines -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-info-circle me-2"></i>Guidelines
                </h5>
            </div>
            <div class="card-body">
                <div class="small">
                    <div class="mb-3">
                        <h6 class="fw-bold">Student Information:</h6>
                        <ul class="mb-0">
                            <li>Student ID is automatically generated</li>
                            <li>ID format: {CLASS}-{INITIALS}{NUMBER}</li>
                            <li>Program, level, and class must be selected</li>
                            <li>All fields are required for creation</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <h6 class="fw-bold">ID Generation:</h6>
                        <ul class="mb-0">
                            <li>Real-time preview shows generated ID</li>
                            <li>Login username matches student ID</li>
                            <li>Duplicate names are automatically detected</li>
                        </ul>
                    </div>
                    
                    <div>
                        <h6 class="fw-bold">Important Notes:</h6>
                        <ul class="mb-0">
                            <li>New students are unverified by default</li>
                            <li>Use verification page to approve students</li>
                            <li>Changes are logged for audit trail</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Student Section (for editing mode) -->
<?php if ($editing_student): ?>
<div class="card mt-4">
    <div class="card-header bg-danger text-white">
        <h5 class="card-title mb-0">
            <i class="fas fa-exclamation-triangle me-2"></i>Danger Zone
        </h5>
    </div>
    <div class="card-body">
        <p class="text-muted">Remove this student from the system. This action cannot be undone.</p>
        <button type="button" class="btn btn-danger" onclick="deleteStudent(<?= $editing_student['student_id'] ?>, '<?= addslashes($editing_student['first_name'] . ' ' . $editing_student['last_name']) ?>')">
            <i class="fas fa-trash me-2"></i>Delete Student
        </button>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteStudentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>Delete Student
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete "<strong id="deleteStudentName"></strong>"?</p>
                <p class="text-danger"><small><i class="fas fa-warning me-1"></i>This action cannot be undone.</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteStudentForm">
                    <input type="hidden" name="action" value="delete_student">
                    <input type="hidden" name="student_id" id="delete_student_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Student</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Add/Edit Students JavaScript
document.addEventListener('DOMContentLoaded', function() {
    
    // Classes data for dynamic loading
    const classesData = <?= json_encode($classes) ?>;
    
    // Load classes based on selected program and level
    window.loadClasses = function() {
        const programId = document.getElementById('program_id').value;
        const levelId = document.getElementById('level_id').value;
        const classSelect = document.getElementById('class_id');
        const currentClassId = <?= $editing_student ? $editing_student['class_id'] : '0' ?>;
        
        classSelect.innerHTML = '<option value="">Choose class...</option>';
        
        if (!programId || !levelId) {
            return;
        }
        
        const filteredClasses = classesData.filter(c => 
            c.program_id == programId && c.level_id == levelId
        );
        
        if (filteredClasses.length === 0) {
            classSelect.innerHTML = '<option value="">No classes available</option>';
            return;
        }
        
        filteredClasses.forEach(cls => {
            const option = document.createElement('option');
            option.value = cls.class_id;
            option.textContent = cls.class_name;
            if (cls.class_id == currentClassId) {
                option.selected = true;
            }
            classSelect.appendChild(option);
        });
        
        // Trigger preview update for new students
        <?php if (!$editing_student): ?>
            setTimeout(checkDuplicateAndGeneratePreview, 100);
        <?php endif; ?>
    };
    
    // Initialize classes if editing
    <?php if ($editing_student): ?>
        loadClasses();
    <?php endif; ?>
    
    // Student ID Preview and Duplicate Detection (for new students only)
    <?php if (!$editing_student): ?>
        let isDuplicate = false;
        let debounceTimer = null;
        
        function checkDuplicateAndGeneratePreview() {
            const firstNameInput = document.getElementById('first_name');
            const lastNameInput = document.getElementById('last_name');
            const classSelect = document.getElementById('class_id');
            const submitBtn = document.querySelector('button[type="submit"]');
            const studentIdPreview = document.getElementById('studentIdPreview');
            const duplicateWarning = document.getElementById('duplicateWarning');
            
            if (!firstNameInput || !lastNameInput || !classSelect) {
                return;
            }
            
            const firstName = firstNameInput.value.trim();
            const lastName = lastNameInput.value.trim();
            
            // Reset state
            if (studentIdPreview) studentIdPreview.style.display = 'none';
            if (duplicateWarning) duplicateWarning.style.display = 'none';
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.classList.remove('btn-danger');
                submitBtn.classList.add('btn-primary');
                submitBtn.innerHTML = '<i class="fas fa-plus me-2"></i>Add Student';
            }
            isDuplicate = false;
            
            // Check if all required fields are filled
            if (!firstName || !lastName || !classSelect.value) {
                return;
            }
            
            // Get class name from the selected option text
            const selectedClassOption = classSelect.options[classSelect.selectedIndex];
            const className = selectedClassOption.textContent;
            
            if (!className || className === 'Choose class...') {
                return;
            }
            
            // Generate initials using the same logic as PHP
            const nameParts = [
                ...firstName.split(' '),
                ...lastName.split(' ')
            ].filter(part => part.trim().length > 0)
             .map(part => part.trim())
             .sort((a, b) => a.toLowerCase().localeCompare(b.toLowerCase()));
            
            const initials = nameParts.map(part => part.charAt(0).toUpperCase()).join('');
            const baseStudentId = className + '-' + initials;
            const studentId = baseStudentId + '001'; // Assume 001 for preview
            
            // Show preview
            const previewStudentId = document.getElementById('previewStudentId');
            const previewUsername = document.getElementById('previewUsername');
            
            if (previewStudentId && previewUsername && studentIdPreview) {
                previewStudentId.textContent = studentId;
                previewUsername.textContent = studentId;
                studentIdPreview.style.display = 'block';
            }
        }
        
        // Debounced function for input events
        function debouncedCheck() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(checkDuplicateAndGeneratePreview, 500);
        }
        
        // Setup event listeners
        function setupEventListeners() {
            const firstNameInput = document.getElementById('first_name');
            const lastNameInput = document.getElementById('last_name');
            const classIdSelect = document.getElementById('class_id');
            
            if (firstNameInput) {
                firstNameInput.addEventListener('input', debouncedCheck);
            }
            if (lastNameInput) {
                lastNameInput.addEventListener('input', debouncedCheck);
            }
            if (classIdSelect) {
                classIdSelect.addEventListener('change', checkDuplicateAndGeneratePreview);
            }
        }
        
        setTimeout(setupEventListeners, 100);
    <?php endif; ?>
    
    // Delete student function
    window.deleteStudent = function(studentId, studentName) {
        document.getElementById('delete_student_id').value = studentId;
        document.getElementById('deleteStudentName').textContent = studentName;
        
        const deleteModal = new bootstrap.Modal(document.getElementById('deleteStudentModal'));
        deleteModal.show();
    };
});
</script>

<?php include '../../includes/footer.php'; ?>