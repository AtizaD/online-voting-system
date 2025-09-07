<?php
require_once '../../config/config.php';
require_once '../../auth/session.php';
require_once '../../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

$page_title = 'Class Generator';
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index'],
    ['title' => 'Class Management', 'url' => './index'],
    ['title' => 'Class Generator']
];

// Handle success/error messages from session
$success = '';
$error = '';

if (isset($_SESSION['classes_success'])) {
    $success = $_SESSION['classes_success'];
    unset($_SESSION['classes_success']);
}

if (isset($_SESSION['classes_error'])) {
    $error = $_SESSION['classes_error'];
    unset($_SESSION['classes_error']);
}

// Get URL parameters for pre-selection
$selected_level_id = intval($_GET['level_id'] ?? 0);
$selected_program_id = intval($_GET['program_id'] ?? 0);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $db = Database::getInstance()->getConnection();
        
        if ($action === 'generate_classes') {
            $level_id = intval($_POST['level_id'] ?? 0);
            $program_id = intval($_POST['program_id'] ?? 0);
            $level_suffix = sanitize($_POST['level_suffix'] ?? '');
            $program_suffix = sanitize($_POST['program_suffix'] ?? '');
            $class_count = intval($_POST['class_count'] ?? 1);
            $start_number = intval($_POST['start_number'] ?? 1);
            $naming_pattern = sanitize($_POST['naming_pattern'] ?? 'suffix_number');
            
            if (!$level_id || !$program_id) {
                throw new Exception('Level and program are required');
            }
            
            if ($class_count < 1 || $class_count > 50) {
                throw new Exception('Class count must be between 1 and 50');
            }
            
            // Get level and program info
            $stmt = $db->prepare("SELECT level_name FROM levels WHERE level_id = ? AND is_active = 1");
            $stmt->execute([$level_id]);
            $level = $stmt->fetch();
            
            $stmt = $db->prepare("SELECT program_name FROM programs WHERE program_id = ? AND is_active = 1");
            $stmt->execute([$program_id]);
            $program = $stmt->fetch();
            
            if (!$level || !$program) {
                throw new Exception('Selected level or program is not available');
            }
            
            // Generate class names based on pattern
            $generated_classes = [];
            $created_count = 0;
            $skipped_count = 0;
            
            for ($i = 0; $i < $class_count; $i++) {
                $class_number = $start_number + $i;
                
                // Generate class name based on pattern
                switch ($naming_pattern) {
                    case 'suffix_number':
                        $class_name = $level_suffix . $program_suffix . $class_number;
                        break;
                    case 'number_suffix':
                        $class_name = $class_number . $level_suffix . $program_suffix;
                        break;
                    case 'level_program_number':
                        $class_name = $level_suffix . '-' . $program_suffix . '-' . $class_number;
                        break;
                    case 'custom':
                        $custom_pattern = sanitize($_POST['custom_pattern'] ?? '');
                        $class_name = str_replace(
                            ['{L}', '{P}', '{N}', '{LC}', '{PC}'],
                            [$level_suffix, $program_suffix, $class_number, strtolower($level_suffix), strtolower($program_suffix)],
                            $custom_pattern
                        );
                        break;
                    default:
                        $class_name = $level_suffix . $program_suffix . $class_number;
                }
                
                // Check if class already exists
                $stmt = $db->prepare("SELECT class_id FROM classes WHERE class_name = ? AND level_id = ? AND program_id = ?");
                $stmt->execute([$class_name, $level_id, $program_id]);
                if ($stmt->fetch()) {
                    $generated_classes[] = [
                        'name' => $class_name,
                        'status' => 'exists',
                        'message' => 'Already exists'
                    ];
                    $skipped_count++;
                    continue;
                }
                
                // Create the class
                $stmt = $db->prepare("
                    INSERT INTO classes (class_name, level_id, program_id, is_active, created_at) 
                    VALUES (?, ?, ?, 1, NOW())
                ");
                $stmt->execute([$class_name, $level_id, $program_id]);
                
                $generated_classes[] = [
                    'name' => $class_name,
                    'status' => 'created',
                    'message' => 'Created successfully'
                ];
                $created_count++;
            }
            
            logActivity('classes_generate', "Generated {$created_count} classes for {$level['level_name']} - {$program['program_name']}", $current_user['id']);
            
            $_SESSION['generation_results'] = $generated_classes;
            $_SESSION['classes_success'] = "Generation completed: {$created_count} created, {$skipped_count} skipped";
            header('Location: ' . $_SERVER['PHP_SELF'] . "?level_id={$level_id}&program_id={$program_id}");
            exit;
        }
        
    } catch (Exception $e) {
        $_SESSION['classes_error'] = $e->getMessage();
        logError("Class generation error: " . $e->getMessage());
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Get generation results from session
$generation_results = $_SESSION['generation_results'] ?? [];
if ($generation_results) {
    unset($_SESSION['generation_results']);
}

// Get data for the page
try {
    $db = Database::getInstance()->getConnection();
    
    // Get levels
    $stmt = $db->prepare("SELECT * FROM levels WHERE is_active = 1 ORDER BY level_order ASC, level_name ASC");
    $stmt->execute();
    $levels = $stmt->fetchAll();
    
    // Get programs (removed program_code from query since it doesn't exist)
    $stmt = $db->prepare("SELECT * FROM programs WHERE is_active = 1 ORDER BY program_name ASC");
    $stmt->execute();
    $programs = $stmt->fetchAll();
    
    // Get existing classes for preview
    if ($selected_level_id && $selected_program_id) {
        $stmt = $db->prepare("
            SELECT c.*, l.level_name, p.program_name
            FROM classes c
            JOIN levels l ON c.level_id = l.level_id
            JOIN programs p ON c.program_id = p.program_id
            WHERE c.level_id = ? AND c.program_id = ?
            ORDER BY c.class_name ASC
        ");
        $stmt->execute([$selected_level_id, $selected_program_id]);
        $existing_classes = $stmt->fetchAll();
    } else {
        $existing_classes = [];
    }
    
} catch (Exception $e) {
    logError("Class generator data fetch error: " . $e->getMessage());
    $_SESSION['classes_error'] = "Unable to load generator data";
    header('Location: index');
    exit;
}

include '../../includes/header.php';
?>

<!-- Class Generator Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="fas fa-magic me-2"></i>Class Generator
        </h4>
        <small class="text-muted">Generate multiple classes with custom naming patterns</small>
    </div>
    <div class="d-flex gap-2">
        <a href="index" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Overview
        </a>
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

<!-- Generation Results -->
<?php if (!empty($generation_results)): ?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="fas fa-list-check me-2"></i>Generation Results
        </h5>
    </div>
    <div class="card-body">
        <div class="row">
            <?php foreach ($generation_results as $result): ?>
                <div class="col-lg-3 col-md-4 col-sm-6 mb-2">
                    <div class="d-flex align-items-center">
                        <span class="badge bg-<?= $result['status'] === 'created' ? 'success' : 'warning' ?> me-2">
                            <i class="fas fa-<?= $result['status'] === 'created' ? 'check' : 'exclamation' ?>"></i>
                        </span>
                        <strong><?= sanitize($result['name']) ?></strong>
                    </div>
                    <small class="text-muted"><?= $result['message'] ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row">
    <!-- Generator Form -->
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-cogs me-2"></i>Class Generation Settings
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" id="generatorForm">
                    <input type="hidden" name="action" value="generate_classes">
                    
                    <!-- Level and Program Selection -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="level_id" class="form-label">Select Level <span class="text-danger">*</span></label>
                            <select class="form-select" id="level_id" name="level_id" required onchange="updatePreview()">
                                <option value="">Choose level...</option>
                                <?php foreach ($levels as $level): ?>
                                    <option value="<?= $level['level_id'] ?>" 
                                            <?= $level['level_id'] == $selected_level_id ? 'selected' : '' ?>>
                                        <?= sanitize($level['level_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="program_id" class="form-label">Select Program <span class="text-danger">*</span></label>
                            <select class="form-select" id="program_id" name="program_id" required onchange="updatePreview()">
                                <option value="">Choose program...</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?= $program['program_id'] ?>" 
                                            data-name="<?= sanitize($program['program_name']) ?>"
                                            <?= $program['program_id'] == $selected_program_id ? 'selected' : '' ?>>
                                        <?= sanitize($program['program_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Naming Configuration -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="level_suffix" class="form-label">Level Suffix <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="level_suffix" name="level_suffix" required 
                                   placeholder="e.g., 1 (for SHS 1)" onchange="updatePreview()">
                            <div class="form-text">Short identifier for the level</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="program_suffix" class="form-label">Program Suffix <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="program_suffix" name="program_suffix" required 
                                   placeholder="e.g., B (for Business)" onchange="updatePreview()">
                            <div class="form-text">Short identifier for the program</div>
                        </div>
                    </div>
                    
                    <!-- Generation Settings -->
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="class_count" class="form-label">Number of Classes <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="class_count" name="class_count" 
                                   value="3" min="1" max="50" required onchange="updatePreview()">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="start_number" class="form-label">Starting Number</label>
                            <input type="number" class="form-control" id="start_number" name="start_number" 
                                   value="1" min="1" onchange="updatePreview()">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="naming_pattern" class="form-label">Naming Pattern</label>
                            <select class="form-select" id="naming_pattern" name="naming_pattern" onchange="toggleCustomPattern(); updatePreview();">
                                <option value="suffix_number">Suffix + Number (1B1)</option>
                                <option value="number_suffix">Number + Suffix (11B)</option>
                                <option value="level_program_number">Level-Program-Number (1-B-1)</option>
                                <option value="custom">Custom Pattern</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Custom Pattern Input (hidden by default) -->
                    <div class="mb-3" id="customPatternDiv" style="display: none;">
                        <label for="custom_pattern" class="form-label">Custom Pattern</label>
                        <input type="text" class="form-control" id="custom_pattern" name="custom_pattern" 
                               placeholder="e.g., {L}{P}{N} or Class-{L}-{P}-{N}" onchange="updatePreview()">
                        <div class="form-text">
                            Use placeholders: {L} = Level suffix, {P} = Program suffix, {N} = Number, {LC} = Level lowercase, {PC} = Program lowercase
                        </div>
                    </div>
                    
                    <!-- Preview -->
                    <div class="mb-3">
                        <label class="form-label">Preview:</label>
                        <div class="p-3 bg-light rounded">
                            <div id="classPreview" class="text-muted">Configure settings above to see preview</div>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-secondary" onclick="resetForm()">Reset</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-magic me-2"></i>Generate Classes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>    <div class="col-lg-4">
        <!-- Quick Examples -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-lightbulb me-2"></i>Examples
                </h5>
            </div>
            <div class="card-body">
                <div class="small">
                    <div class="mb-3">
                        <h6 class="fw-bold">Example 1: SHS 1 Business</h6>
                        <ul class="mb-2">
                            <li>Level Suffix: <code>1</code></li>
                            <li>Program Suffix: <code>B</code></li>
                            <li>Count: 3</li>
                        </ul>
                        <p class="text-success"><strong>Result:</strong> 1B1, 1B2, 1B3</p>
                    </div>
                    
                    <div class="mb-3">
                        <h6 class="fw-bold">Example 2: JHS 2 Science</h6>
                        <ul class="mb-2">
                            <li>Level Suffix: <code>2</code></li>
                            <li>Program Suffix: <code>SCI</code></li>
                            <li>Pattern: Level-Program-Number</li>
                        </ul>
                        <p class="text-success"><strong>Result:</strong> 2-SCI-1, 2-SCI-2, 2-SCI-3</p>
                    </div>
                    
                    <div>
                        <h6 class="fw-bold">Custom Pattern Examples:</h6>
                        <ul class="mb-0">
                            <li><code>{L}{P}{N}</code> → 1B1</li>
                            <li><code>Class-{L}{P}{N}</code> → Class-1B1</li>
                            <li><code>{L}_{PC}_{N}</code> → 1_business_1</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Existing Classes -->
        <?php if (!empty($existing_classes)): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-list me-2"></i>Existing Classes
                </h5>
            </div>
            <div class="card-body">
                <div class="small">
                    <p class="text-muted mb-2">
                        <?= sanitize($existing_classes[0]['level_name']) ?> - <?= sanitize($existing_classes[0]['program_name']) ?>
                    </p>
                    <?php foreach ($existing_classes as $class): ?>
                        <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                            <span><?= sanitize($class['class_name']) ?></span>
                            <span class="badge bg-<?= $class['is_active'] ? 'success' : 'secondary' ?> badge-sm">
                                <?= $class['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* Class Generator Styles */

.badge-sm {
    font-size: 0.7rem;
}

#classPreview {
    min-height: 60px;
    font-family: 'Courier New', monospace;
    font-size: 1.1rem;
}

.preview-class {
    display: inline-block;
    background: #007bff;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
    margin: 0.125rem;
    font-weight: 600;
}

.form-text {
    font-size: 0.8rem;
}
</style>

<script>
// Class Generator JavaScript
document.addEventListener('DOMContentLoaded', function() {
    
    // Initialize preview
    updatePreview();
    
    // Toggle custom pattern input
    window.toggleCustomPattern = function() {
        const pattern = document.getElementById('naming_pattern').value;
        const customDiv = document.getElementById('customPatternDiv');
        
        if (pattern === 'custom') {
            customDiv.style.display = 'block';
            document.getElementById('custom_pattern').required = true;
        } else {
            customDiv.style.display = 'none';
            document.getElementById('custom_pattern').required = false;
        }
    };
    
    // Update preview
    window.updatePreview = function() {
        const levelSuffix = document.getElementById('level_suffix').value || 'L';
        const programSuffix = document.getElementById('program_suffix').value || 'P';
        const classCount = parseInt(document.getElementById('class_count').value) || 3;
        const startNumber = parseInt(document.getElementById('start_number').value) || 1;
        const namingPattern = document.getElementById('naming_pattern').value;
        const customPattern = document.getElementById('custom_pattern').value;
        
        const previewDiv = document.getElementById('classPreview');
        
        if (!levelSuffix || !programSuffix) {
            previewDiv.innerHTML = '<span class="text-muted">Enter level and program suffixes to see preview</span>';
            return;
        }
        
        let classNames = [];
        
        for (let i = 0; i < Math.min(classCount, 10); i++) { // Limit preview to 10
            const classNumber = startNumber + i;
            let className;
            
            switch (namingPattern) {
                case 'suffix_number':
                    className = levelSuffix + programSuffix + classNumber;
                    break;
                case 'number_suffix':
                    className = classNumber + levelSuffix + programSuffix;
                    break;
                case 'level_program_number':
                    className = levelSuffix + '-' + programSuffix + '-' + classNumber;
                    break;
                case 'custom':
                    if (customPattern) {
                        className = customPattern
                            .replace(/{L}/g, levelSuffix)
                            .replace(/{P}/g, programSuffix)
                            .replace(/{N}/g, classNumber)
                            .replace(/{LC}/g, levelSuffix.toLowerCase())
                            .replace(/{PC}/g, programSuffix.toLowerCase());
                    } else {
                        className = levelSuffix + programSuffix + classNumber;
                    }
                    break;
                default:
                    className = levelSuffix + programSuffix + classNumber;
            }
            
            classNames.push(className);
        }
        
        const previewHtml = classNames.map(name => `<span class="preview-class">${name}</span>`).join(' ');
        const moreText = classCount > 10 ? ` <span class="text-muted">... and ${classCount - 10} more</span>` : '';
        
        previewDiv.innerHTML = previewHtml + moreText;
    };
    
    // Reset form
    window.resetForm = function() {
        document.getElementById('generatorForm').reset();
        document.getElementById('customPatternDiv').style.display = 'none';
        updatePreview();
    };
    
    // Auto-populate program suffix when program is selected
    document.getElementById('program_id').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const programName = selectedOption.dataset.name;
        if (programName && !document.getElementById('program_suffix').value) {
            // Generate a suffix from the program name
            let suffix = '';
            if (programName.includes('Business')) suffix = 'B';
            else if (programName.includes('Science')) suffix = 'S';
            else if (programName.includes('Arts')) suffix = 'A';
            else if (programName.includes('Home Economics')) suffix = 'HE';
            else if (programName.includes('Agriculture')) suffix = 'AG';
            else suffix = programName.charAt(0).toUpperCase();
            
            document.getElementById('program_suffix').value = suffix;
            updatePreview();
        }
    });
    
    // Auto-dismiss alerts
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.remove();
                }
            }, 500);
        });
    }, 8000);
});
</script>

<?php include '../../includes/footer.php'; ?>