<?php
require_once '../../config/config.php';
require_once '../../auth/session.php';
require_once '../../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

$page_title = 'Export Students';
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index'],
    ['title' => 'Students', 'url' => './index'],
    ['title' => 'Export Students']
];

include '../../includes/header.php';
?>

<!-- Export Students Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="fas fa-download me-2"></i>Export Student Data
        </h4>
        <small class="text-muted">Export student records in various formats</small>
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
        <i class="fas fa-download text-muted" style="font-size: 4rem;"></i>
        <h4 class="text-muted mt-3">Export Feature Coming Soon</h4>
        <p class="text-muted">Student data export functionality will be available in a future update.</p>
        <a href="index" class="btn btn-primary">
            <i class="fas fa-list me-2"></i>View All Students
        </a>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>