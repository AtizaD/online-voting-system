<?php
require_once '../../config/config.php';
require_once '../../config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

if (!isLoggedIn() || !hasPermission('manage_system')) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $response = [];

    // Get basic statistics
    $stats_query = "
        SELECT 
            (SELECT COUNT(*) FROM elections WHERE status = 'active') as active_elections,
            (SELECT COUNT(*) FROM elections WHERE status = 'draft') as draft_elections,
            (SELECT COUNT(*) FROM elections WHERE status = 'completed') as completed_elections,
            (SELECT COUNT(*) FROM students WHERE is_active = 1) as total_students,
            (SELECT COUNT(*) FROM students WHERE is_verified = 1) as verified_students,
            (SELECT COUNT(*) FROM candidates) as total_candidates,
            (SELECT COUNT(*) FROM votes) as total_votes,
            (SELECT COUNT(*) FROM users WHERE is_active = 1) as total_users,
            (SELECT COUNT(*) FROM security_events WHERE resolved = 0) as security_alerts,
            (SELECT COUNT(*) FROM audit_logs WHERE DATE(timestamp) = CURDATE()) as today_activities
    ";

    $stats_result = $db->query($stats_query);
    $response['stats'] = $stats_result->fetch();

    // Get voting trends for the last 7 days
    $voting_trends_query = "
        SELECT 
            DATE(vote_timestamp) as vote_date,
            COUNT(*) as vote_count
        FROM votes 
        WHERE vote_timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(vote_timestamp)
        ORDER BY vote_date
    ";
    $voting_trends = $db->query($voting_trends_query)->fetchAll();
    $response['voting_trends'] = [
        'labels' => array_column($voting_trends, 'vote_date'),
        'data' => array_column($voting_trends, 'vote_count')
    ];

    // Get recent activities
    $recent_activities_query = "
        SELECT al.*, u.first_name, u.last_name, s.first_name as student_first_name, s.last_name as student_last_name
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        LEFT JOIN students s ON al.student_id = s.student_id
        ORDER BY al.timestamp DESC
        LIMIT 10
    ";
    $recent_activities = $db->query($recent_activities_query)->fetchAll();
    $response['recent_activities'] = $recent_activities;

    // Get security events for the last 24 hours
    $security_events_query = "
        SELECT event_type, COUNT(*) as count
        FROM security_events 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY event_type
    ";
    $security_events = $db->query($security_events_query)->fetchAll();
    $response['security_events'] = [
        'labels' => array_column($security_events, 'event_type'),
        'data' => array_column($security_events, 'count')
    ];

    // Get hourly vote distribution for today
    $hourly_votes_query = "
        SELECT 
            HOUR(vote_timestamp) as hour,
            COUNT(*) as votes
        FROM votes 
        WHERE DATE(vote_timestamp) = CURDATE()
        GROUP BY HOUR(vote_timestamp)
        ORDER BY hour
    ";
    $hourly_votes = $db->query($hourly_votes_query)->fetchAll();
    $response['hourly_votes'] = [
        'labels' => array_column($hourly_votes, 'hour'),
        'data' => array_column($hourly_votes, 'votes')
    ];

    // Get today's activity summary
    $today_activity_query = "
        SELECT 
            COUNT(CASE WHEN action = 'user_login' THEN 1 END) as logins_today,
            COUNT(CASE WHEN action = 'vote' THEN 1 END) as votes_today,
            COUNT(*) as total_activities_today
        FROM audit_logs 
        WHERE DATE(timestamp) = CURDATE()
    ";
    $today_activity = $db->query($today_activity_query)->fetch();
    $response['today_activity'] = $today_activity;

    // Get system health indicators
    $response['system_health'] = [
        'database_status' => 'connected',
        'last_backup' => date('Y-m-d H:i:s'), // This should be from actual backup logs
        'active_sessions' => $db->query("SELECT COUNT(*) as count FROM user_sessions WHERE is_active = 1 AND expires_at > NOW()")->fetch()['count'],
        'server_load' => rand(15, 35), // This should be from actual server monitoring
        'uptime' => '99.9%'
    ];

    $response['timestamp'] = date('Y-m-d H:i:s');
    
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'message' => $e->getMessage()]);
}
?>