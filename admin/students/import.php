<?php
require_once '../../config/config.php';
require_once '../../auth/session.php';
require_once '../../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

$page_title = 'Import Students';
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index'],
    ['title' => 'Students', 'url' => './index'],
    ['title' => 'Import Students']
];

include '../../includes/header.php';
?>

<!-- Import Students Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="fas fa-upload me-2"></i>Import Students (CSV)
        </h4>
        <small class="text-muted">Bulk import students from CSV file</small>
    </div>
    <div class="d-flex gap-2">
        <a href="index" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Overview
        </a>
    </div>
</div>

<!-- Coming Soon -->
<div class="card">
    <div class="card-body text-center py-5">
        <i class="fas fa-upload text-muted" style="font-size: 4rem;"></i>
        <h4 class="text-muted mt-3">Import Feature Coming Soon</h4>
        <p class="text-muted">CSV import functionality will be available in a future update.</p>
        <a href="manage" class="btn btn-primary">
            <i class="fas fa-user-plus me-2"></i>Add Students Manually
        </a>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>