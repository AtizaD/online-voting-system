<?php
require_once '../../config/config.php';
require_once '../../config/database.php';

if (!isLoggedIn() || !hasPermission('publish_results')) {
    redirectTo('auth/index.php');
}

$db = Database::getInstance()->getConnection();
$current_user = getCurrentUser();

if ($_POST && isset($_POST['election_id'])) {
    $election_id = (int)$_POST['election_id'];
    
    try {
        // Check if election exists and is not already published
        $stmt = $db->prepare("
            SELECT election_id, name, status, results_published_at 
            FROM elections 
            WHERE election_id = ?
        ");
        $stmt->execute([$election_id]);
        $election = $stmt->fetch();
        
        if (!$election) {
            $_SESSION['error_message'] = "Election not found.";
            redirectTo('admin/results/');
        }
        
        if ($election['results_published_at']) {
            $_SESSION['error_message'] = "Results for this election have already been published.";
            redirectTo('admin/results/view.php?id=' . $election_id);
        }
        
        // Publish the results
        $stmt = $db->prepare("
            UPDATE elections 
            SET results_published_at = CURRENT_TIMESTAMP 
            WHERE election_id = ?
        ");
        $stmt->execute([$election_id]);
        
        // Log the activity
        logActivity('publish_results', "Published results for election: {$election['name']} (ID: $election_id)");
        
        // Create audit log entry
        $stmt = $db->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, ip_address, user_agent)
            VALUES (?, 'publish_results', 'elections', ?, ?, ?, ?)
        ");
        $stmt->execute([
            $current_user['id'],
            $election_id,
            json_encode(['results_published_at' => date('Y-m-d H:i:s')]),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
        
        $_SESSION['success_message'] = "Results for '{$election['name']}' have been published successfully!";
        redirectTo('admin/results/view.php?id=' . $election_id);
        
    } catch (Exception $e) {
        error_log("Error publishing results: " . $e->getMessage());
        $_SESSION['error_message'] = "An error occurred while publishing the results. Please try again.";
        redirectTo('admin/results/view.php?id=' . $election_id);
    }
    
} else {
    $_SESSION['error_message'] = "Invalid request.";
    redirectTo('admin/results/');
}
?>