<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../auth/session.php';

// Check authentication and authorization
requireAuth(['admin', 'election_officer']);

$db = Database::getInstance()->getConnection();

// Get parameters
$election_id = isset($_GET['election_id']) ? (int)$_GET['election_id'] : null;
$time_range = isset($_GET['range']) ? $_GET['range'] : 'all';
$program_filter = isset($_GET['program_id']) ? (int)$_GET['program_id'] : null;
$export_format = isset($_GET['format']) ? $_GET['format'] : 'pdf';

if (!$election_id) {
    die('Election ID is required');
}

try {
    // Get election details
    $stmt = $db->prepare("
        SELECT e.*, et.name as election_type_name,
               u.first_name as created_by_name, u.last_name as created_by_lastname
        FROM elections e 
        LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
        LEFT JOIN users u ON e.created_by = u.user_id
        WHERE e.election_id = ?
    ");
    $stmt->execute([$election_id]);
    $election_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$election_data) {
        die('Election not found');
    }

    // Build filters
    $date_filter = "";
    $program_join = "";
    $params = [$election_id];
    
    switch ($time_range) {
        case 'today':
            $date_filter = "AND DATE(vs.started_at) = CURDATE()";
            break;
        case 'yesterday':
            $date_filter = "AND DATE(vs.started_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'week':
            $date_filter = "AND vs.started_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
            break;
        case 'month':
            $date_filter = "AND vs.started_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
            break;
    }

    if ($program_filter) {
        $program_join = "JOIN students s_filter ON vs.student_id = s_filter.student_id";
        $date_filter .= " AND s_filter.program_id = ?";
        $params[] = $program_filter;
    }

    // Get program name if filtering
    $program_name = '';
    if ($program_filter) {
        $stmt = $db->prepare("SELECT program_name FROM programs WHERE program_id = ?");
        $stmt->execute([$program_filter]);
        $program_result = $stmt->fetch(PDO::FETCH_ASSOC);
        $program_name = $program_result['program_name'] ?? 'Unknown Program';
    }

    // Collect all statistics (same queries as statistics.php)
    $statistics = [];

    // 1. Overall Statistics
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT vs.student_id) as total_voters,
            COUNT(v.vote_id) as total_votes,
            COUNT(DISTINCT p.position_id) as total_positions,
            COUNT(DISTINCT c.candidate_id) as total_candidates,
            (SELECT COUNT(*) FROM students s JOIN classes cl ON s.class_id = cl.class_id 
             WHERE s.is_active = TRUE AND s.is_verified = TRUE 
             " . ($program_filter ? "AND s.program_id = $program_filter" : "") . ") as eligible_voters,
            AVG(vs.votes_cast) as avg_votes_per_session,
            MIN(vs.started_at) as first_vote_time,
            MAX(vs.completed_at) as last_vote_time
        FROM voting_sessions vs
        $program_join
        LEFT JOIN votes v ON vs.session_id = v.session_id
        LEFT JOIN positions p ON v.position_id = p.position_id AND p.election_id = ?
        LEFT JOIN candidates c ON v.candidate_id = c.candidate_id AND c.election_id = ?
        WHERE vs.election_id = ? AND vs.status = 'completed' $date_filter
    ");
    $stmt->execute([...$params, $election_id, $election_id]);
    $statistics['overall'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $statistics['overall']['turnout_percentage'] = $statistics['overall']['eligible_voters'] > 0 
        ? round(($statistics['overall']['total_voters'] / $statistics['overall']['eligible_voters']) * 100, 2) 
        : 0;

    // 2. Program-wise Statistics (Fixed query)
    $stmt = $db->prepare("
        SELECT 
            p.program_name,
            p.program_id,
            COALESCE(student_counts.total_students, 0) as total_students,
            COALESCE(voter_counts.voted_students, 0) as voted_students,
            COALESCE(voter_counts.total_votes, 0) as total_votes,
            COALESCE(voter_counts.avg_votes_per_voter, 0) as avg_votes_per_voter,
            CASE 
                WHEN COALESCE(student_counts.total_students, 0) > 0 
                THEN ROUND(COALESCE(voter_counts.voted_students, 0) / student_counts.total_students * 100, 2) 
                ELSE 0 
            END as turnout_rate
        FROM programs p
        LEFT JOIN (
            SELECT 
                s.program_id,
                COUNT(DISTINCT s.student_id) as total_students
            FROM students s 
            WHERE s.is_active = TRUE AND s.is_verified = TRUE
            GROUP BY s.program_id
        ) student_counts ON p.program_id = student_counts.program_id
        LEFT JOIN (
            SELECT 
                s.program_id,
                COUNT(DISTINCT vs.student_id) as voted_students,
                COUNT(v.vote_id) as total_votes,
                AVG(vs.votes_cast) as avg_votes_per_voter
            FROM students s
            JOIN voting_sessions vs ON s.student_id = vs.student_id 
                AND vs.election_id = ? AND vs.status = 'completed' $date_filter
            LEFT JOIN votes v ON vs.session_id = v.session_id
            WHERE s.is_active = TRUE AND s.is_verified = TRUE
            GROUP BY s.program_id
        ) voter_counts ON p.program_id = voter_counts.program_id
        WHERE p.is_active = TRUE 
        " . ($program_filter ? "AND p.program_id = $program_filter" : "") . "
          AND COALESCE(student_counts.total_students, 0) > 0
        ORDER BY turnout_rate DESC, p.program_name
    ");
    $stmt->execute($params);
    $statistics['program_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Position Statistics
    $stmt = $db->prepare("
        SELECT 
            pos.title as position_title,
            pos.display_order,
            COUNT(DISTINCT c.candidate_id) as candidate_count,
            COUNT(v.vote_id) as total_votes,
            COUNT(av.abstain_id) as abstain_votes,
            COUNT(DISTINCT vs.student_id) as total_responses,
            ROUND(COUNT(v.vote_id) / (COUNT(v.vote_id) + COUNT(av.abstain_id)) * 100, 2) as vote_rate
        FROM positions pos
        LEFT JOIN candidates c ON pos.position_id = c.position_id
        LEFT JOIN votes v ON c.candidate_id = v.candidate_id
        LEFT JOIN abstain_votes av ON pos.position_id = av.position_id
        LEFT JOIN voting_sessions vs ON (v.session_id = vs.session_id OR av.session_id = vs.session_id) AND vs.status = 'completed'
        WHERE pos.election_id = ?
        GROUP BY pos.position_id, pos.title, pos.display_order
        ORDER BY pos.display_order
    ");
    $stmt->execute([$election_id]);
    $statistics['position_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Daily Trends
    $stmt = $db->prepare("
        SELECT 
            DATE(vs.started_at) as vote_date,
            COUNT(DISTINCT vs.student_id) as voters_count,
            COUNT(v.vote_id) as votes_count,
            AVG(vs.votes_cast) as avg_votes_per_session,
            AVG(TIMESTAMPDIFF(MINUTE, vs.started_at, vs.completed_at)) as avg_session_duration
        FROM voting_sessions vs
        $program_join
        LEFT JOIN votes v ON vs.session_id = v.session_id
        WHERE vs.election_id = ? AND vs.status = 'completed' $date_filter
        GROUP BY DATE(vs.started_at)
        ORDER BY vote_date
    ");
    $stmt->execute($params);
    $statistics['daily_trends'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Device Statistics
    $stmt = $db->prepare("
        SELECT 
            CASE 
                WHEN vs.user_agent LIKE '%Mobile%' OR vs.user_agent LIKE '%Android%' OR vs.user_agent LIKE '%iPhone%' THEN 'Mobile'
                WHEN vs.user_agent LIKE '%Chrome%' THEN 'Chrome Desktop'
                WHEN vs.user_agent LIKE '%Firefox%' THEN 'Firefox Desktop'
                WHEN vs.user_agent LIKE '%Safari%' AND vs.user_agent NOT LIKE '%Chrome%' THEN 'Safari Desktop'
                WHEN vs.user_agent LIKE '%Edge%' THEN 'Edge Desktop'
                ELSE 'Other'
            END as device_type,
            COUNT(*) as session_count,
            COUNT(v.vote_id) as vote_count,
            ROUND(COUNT(*) / (SELECT COUNT(*) FROM voting_sessions WHERE election_id = ? AND status = 'completed') * 100, 2) as percentage
        FROM voting_sessions vs
        $program_join
        LEFT JOIN votes v ON vs.session_id = v.session_id
        WHERE vs.election_id = ? AND vs.status = 'completed' $date_filter
        GROUP BY device_type
        ORDER BY session_count DESC
    ");
    $stmt->execute([...$params, $election_id]);
    $statistics['device_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Current user info
    $current_user = getCurrentUser();

} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}

// Export based on format
if ($export_format === 'csv') {
    exportCSV($election_data, $statistics, $time_range, $program_name);
} else {
    exportPDF($election_data, $statistics, $time_range, $program_name, $current_user);
}

function exportCSV($election_data, $statistics, $time_range, $program_name) {
    $filename = 'voting_statistics_' . $election_data['election_id'] . '_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    
    $output = fopen('php://output', 'w');
    
    // Election Info
    fputcsv($output, ['Election Statistics Report']);
    fputcsv($output, ['Election Name', $election_data['name']]);
    fputcsv($output, ['Election Type', $election_data['election_type_name']]);
    fputcsv($output, ['Status', ucfirst($election_data['status'])]);
    fputcsv($output, ['Time Range', ucfirst(str_replace('_', ' ', $time_range))]);
    if ($program_name) {
        fputcsv($output, ['Program Filter', $program_name]);
    }
    fputcsv($output, ['Generated On', date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    
    // Overall Statistics
    fputcsv($output, ['Overall Statistics']);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Voters', number_format($statistics['overall']['total_voters'])]);
    fputcsv($output, ['Total Votes', number_format($statistics['overall']['total_votes'])]);
    fputcsv($output, ['Eligible Voters', number_format($statistics['overall']['eligible_voters'])]);
    fputcsv($output, ['Turnout Percentage', $statistics['overall']['turnout_percentage'] . '%']);
    fputcsv($output, ['Avg Votes per Session', number_format($statistics['overall']['avg_votes_per_session'], 2)]);
    fputcsv($output, []);
    
    // Program Statistics
    fputcsv($output, ['Program-wise Statistics']);
    fputcsv($output, ['Program', 'Total Students', 'Voted Students', 'Total Votes', 'Turnout Rate']);
    foreach ($statistics['program_stats'] as $program) {
        fputcsv($output, [
            $program['program_name'],
            $program['total_students'],
            $program['voted_students'],
            $program['total_votes'],
            $program['turnout_rate'] . '%'
        ]);
    }
    fputcsv($output, []);
    
    // Position Statistics
    fputcsv($output, ['Position Statistics']);
    fputcsv($output, ['Position', 'Candidates', 'Total Votes', 'Abstain Votes', 'Vote Rate']);
    foreach ($statistics['position_stats'] as $position) {
        fputcsv($output, [
            $position['position_title'],
            $position['candidate_count'],
            $position['total_votes'],
            $position['abstain_votes'],
            $position['vote_rate'] . '%'
        ]);
    }
    fputcsv($output, []);
    
    // Daily Trends
    if (!empty($statistics['daily_trends'])) {
        fputcsv($output, ['Daily Voting Trends']);
        fputcsv($output, ['Date', 'Voters', 'Votes', 'Avg Votes/Session', 'Avg Duration (min)']);
        foreach ($statistics['daily_trends'] as $day) {
            fputcsv($output, [
                $day['vote_date'],
                $day['voters_count'],
                $day['votes_count'],
                number_format($day['avg_votes_per_session'], 2),
                number_format($day['avg_session_duration'], 1)
            ]);
        }
        fputcsv($output, []);
    }
    
    // Device Statistics
    if (!empty($statistics['device_stats'])) {
        fputcsv($output, ['Device Usage Statistics']);
        fputcsv($output, ['Device Type', 'Sessions', 'Votes', 'Percentage']);
        foreach ($statistics['device_stats'] as $device) {
            fputcsv($output, [
                $device['device_type'],
                $device['session_count'],
                $device['vote_count'],
                $device['percentage'] . '%'
            ]);
        }
    }
    
    fclose($output);
    exit;
}

function exportPDF($election_data, $statistics, $time_range, $program_name, $current_user) {
    // Check if TCPDF is available
    $tcpdf_path = __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php';
    if (!file_exists($tcpdf_path)) {
        // Try alternative path
        $tcpdf_path = __DIR__ . '/../../tcpdf/tcpdf.php';
    }
    
    if (!file_exists($tcpdf_path)) {
        // Fallback to HTML export if TCPDF not found
        exportPDFHtml($election_data, $statistics, $time_range, $program_name, $current_user);
        return;
    }
    
    require_once($tcpdf_path);
    
    $filename = 'voting_statistics_' . $election_data['election_id'] . '_' . date('Y-m-d_H-i-s') . '.pdf';
    
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator(SITE_NAME);
    $pdf->SetAuthor($current_user['first_name'] . ' ' . $current_user['last_name']);
    $pdf->SetTitle('Voting Statistics Report - ' . $election_data['name']);
    $pdf->SetSubject('Election Statistics Report');
    $pdf->SetKeywords('voting, statistics, election, report');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins - reduced for more content per page
    $pdf->SetMargins(15, 10, 15);
    $pdf->SetAutoPageBreak(TRUE, 10);
    
    // Set image scale factor
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', '', 12);
    
    // Header Section
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->SetTextColor(0, 123, 255); // Bootstrap primary blue
    $pdf->Cell(0, 15, 'Voting Statistics Report', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(108, 117, 125); // Bootstrap text-muted
    $pdf->Cell(0, 10, htmlspecialchars($election_data['name']), 0, 1, 'C');
    
    $pdf->Ln(3);
    
    // Election Information Box
    $pdf->SetFillColor(248, 249, 250); // Light gray background
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'Election Details', 0, 1, 'L', true);
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Ln(2);
    
    // Two column layout for election info
    $pdf->Cell(90, 6, 'Election Type: ' . htmlspecialchars($election_data['election_type_name'] ?? 'N/A'), 0, 0);
    $pdf->Cell(90, 6, 'Status: ' . ucfirst($election_data['status']), 0, 1);
    
    $pdf->Cell(90, 6, 'Start Date: ' . date('M j, Y H:i', strtotime($election_data['start_date'])), 0, 0);
    $pdf->Cell(90, 6, 'End Date: ' . date('M j, Y H:i', strtotime($election_data['end_date'])), 0, 1);
    
    $pdf->Cell(90, 6, 'Time Range: ' . ucfirst(str_replace('_', ' ', $time_range)), 0, 0);
    $pdf->Cell(90, 6, 'Generated: ' . date('M j, Y H:i:s'), 0, 1);
    
    if ($program_name) {
        $pdf->Cell(0, 6, 'Program Filter: ' . htmlspecialchars($program_name), 0, 1);
    }
    
    $pdf->Ln(6);
    
    // Overall Statistics
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(0, 123, 255);
    $pdf->Cell(0, 8, 'Overall Statistics', 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(3);
    
    // Statistics in full-width table format
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(0, 123, 255);
    $pdf->SetTextColor(255, 255, 255);
    
    $pdf->Cell(45, 8, 'Total Voters', 1, 0, 'C', true);
    $pdf->Cell(45, 8, 'Total Votes', 1, 0, 'C', true);
    $pdf->Cell(45, 8, 'Avg Votes/Session', 1, 0, 'C', true);
    $pdf->Cell(45, 8, 'Active Days', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(248, 249, 250);
    
    $first_vote = $statistics['overall']['first_vote_time'] ? date('M j', strtotime($statistics['overall']['first_vote_time'])) : 'N/A';
    $pdf->Cell(45, 6, number_format($statistics['overall']['total_voters']) . ' (' . $statistics['overall']['turnout_percentage'] . '%)', 1, 0, 'C', true);
    $pdf->Cell(45, 6, number_format($statistics['overall']['total_votes']), 1, 0, 'C', true);
    $pdf->Cell(45, 6, number_format($statistics['overall']['avg_votes_per_session'], 1), 1, 0, 'C', true);
    $pdf->Cell(45, 6, count($statistics['daily_trends']) . ' (from ' . $first_vote . ')', 1, 1, 'C', true);
    
    $pdf->Ln(6);
    
    // Program Statistics
    if (!empty($statistics['program_stats'])) {
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColor(0, 123, 255);
        $pdf->Cell(0, 8, 'Program-wise Turnout', 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);
        
        // Table headers
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(0, 123, 255);
        $pdf->SetTextColor(255, 255, 255);
        
        $pdf->Cell(70, 8, 'Program', 1, 0, 'L', true);
        $pdf->Cell(25, 8, 'Students', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Voted', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Votes', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'Turnout Rate', 1, 1, 'C', true);
        
        // Table data
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(0, 0, 0);
        
        foreach ($statistics['program_stats'] as $i => $program) {
            $fill = ($i % 2 == 0);
            $pdf->SetFillColor(248, 249, 250);
            
            $pdf->Cell(70, 6, htmlspecialchars($program['program_name']), 1, 0, 'L', $fill);
            $pdf->Cell(25, 6, number_format($program['total_students']), 1, 0, 'C', $fill);
            $pdf->Cell(25, 6, number_format($program['voted_students']), 1, 0, 'C', $fill);
            $pdf->Cell(25, 6, number_format($program['total_votes']), 1, 0, 'C', $fill);
            $pdf->Cell(35, 6, $program['turnout_rate'] . '%', 1, 1, 'C', $fill);
        }
        $pdf->Ln(6);
    }
    
    // Position Statistics
    if (!empty($statistics['position_stats'])) {
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColor(0, 123, 255);
        $pdf->Cell(0, 8, 'Position Statistics', 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);
        
        // Table headers
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(0, 123, 255);
        $pdf->SetTextColor(255, 255, 255);
        
        $pdf->Cell(80, 8, 'Position', 1, 0, 'L', true);
        $pdf->Cell(25, 8, 'Candidates', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Votes', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Abstains', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Vote Rate', 1, 1, 'C', true);
        
        // Table data
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(0, 0, 0);
        
        foreach ($statistics['position_stats'] as $i => $position) {
            $fill = ($i % 2 == 0);
            $pdf->SetFillColor(248, 249, 250);
            
            $pdf->Cell(80, 6, htmlspecialchars($position['position_title']), 1, 0, 'L', $fill);
            $pdf->Cell(25, 6, $position['candidate_count'], 1, 0, 'C', $fill);
            $pdf->Cell(25, 6, number_format($position['total_votes']), 1, 0, 'C', $fill);
            $pdf->Cell(25, 6, number_format($position['abstain_votes']), 1, 0, 'C', $fill);
            $pdf->Cell(25, 6, $position['vote_rate'] . '%', 1, 1, 'C', $fill);
        }
        $pdf->Ln(6);
    }
    
    // Daily Trends (if space allows)
    if (!empty($statistics['daily_trends']) && count($statistics['daily_trends']) <= 10) {
        // Add new page if needed
        if ($pdf->GetY() > 200) {
            $pdf->AddPage();
        }
        
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColor(0, 123, 255);
        $pdf->Cell(0, 8, 'Daily Voting Trends', 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);
        
        // Table headers
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(0, 123, 255);
        $pdf->SetTextColor(255, 255, 255);
        
        $pdf->Cell(40, 8, 'Date', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Voters', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Votes', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'Avg Votes/Session', 1, 0, 'C', true);
        $pdf->Cell(45, 8, 'Avg Duration (min)', 1, 1, 'C', true);
        
        // Table data
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(0, 0, 0);
        
        foreach ($statistics['daily_trends'] as $i => $day) {
            $fill = ($i % 2 == 0);
            $pdf->SetFillColor(248, 249, 250);
            
            $pdf->Cell(40, 6, date('M j, Y', strtotime($day['vote_date'])), 1, 0, 'C', $fill);
            $pdf->Cell(30, 6, $day['voters_count'], 1, 0, 'C', $fill);
            $pdf->Cell(30, 6, $day['votes_count'], 1, 0, 'C', $fill);
            $pdf->Cell(35, 6, number_format($day['avg_votes_per_session'], 1), 1, 0, 'C', $fill);
            $pdf->Cell(45, 6, number_format($day['avg_session_duration'], 1), 1, 1, 'C', $fill);
        }
    }
    
    // Device Statistics (if available and space allows)
    if (!empty($statistics['device_stats'])) {
        // Add new page if needed
        if ($pdf->GetY() > 180) {
            $pdf->AddPage();
        }
        
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColor(0, 123, 255);
        $pdf->Cell(0, 8, 'Device Usage Statistics', 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);
        
        // Table headers
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(0, 123, 255);
        $pdf->SetTextColor(255, 255, 255);
        
        $pdf->Cell(60, 8, 'Device Type', 1, 0, 'L', true);
        $pdf->Cell(40, 8, 'Sessions', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Votes', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Percentage', 1, 1, 'C', true);
        
        // Table data
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(0, 0, 0);
        
        foreach ($statistics['device_stats'] as $i => $device) {
            $fill = ($i % 2 == 0);
            $pdf->SetFillColor(248, 249, 250);
            
            $pdf->Cell(60, 6, htmlspecialchars($device['device_type']), 1, 0, 'L', $fill);
            $pdf->Cell(40, 6, number_format($device['session_count']), 1, 0, 'C', $fill);
            $pdf->Cell(40, 6, number_format($device['vote_count']), 1, 0, 'C', $fill);
            $pdf->Cell(40, 6, $device['percentage'] . '%', 1, 1, 'C', $fill);
        }
        $pdf->Ln(5);
    }
    
    // Footer
    $pdf->SetY(-30);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(108, 117, 125);
    $pdf->Cell(0, 6, 'Generated by: ' . SITE_NAME . ' | Report Generated: ' . date('M j, Y \a\t H:i:s'), 0, 1, 'L');
    $pdf->Cell(0, 6, 'Generated by: ' . htmlspecialchars($current_user['first_name'] . ' ' . $current_user['last_name']), 0, 1, 'L');
    $pdf->Cell(0, 6, '🤖 Generated with Claude Code - Advanced AI-powered voting system analytics', 0, 1, 'L');
    
    // Output PDF
    $pdf->Output($filename, 'D'); // D = download
    exit;
}

function exportPDFHtml($election_data, $statistics, $time_range, $program_name, $current_user) {
    // Fallback HTML export (original implementation)
    ob_start();
    
    $filename = 'voting_statistics_' . $election_data['election_id'] . '_' . date('Y-m-d_H-i-s') . '.pdf';
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Voting Statistics Report</title>
        <style>
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                line-height: 1.4;
                color: #333;
                margin: 0;
                padding: 20px;
                font-size: 12px;
            }
            
            .header {
                text-align: center;
                margin-bottom: 30px;
                padding-bottom: 20px;
                border-bottom: 3px solid #007bff;
            }
            
            .header h1 {
                color: #007bff;
                margin: 0;
                font-size: 24px;
                font-weight: bold;
            }
            
            .header h2 {
                color: #6c757d;
                margin: 5px 0;
                font-size: 18px;
                font-weight: normal;
            }
            
            .election-info {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 8px;
                margin-bottom: 25px;
                border-left: 4px solid #007bff;
            }
            
            .election-info h3 {
                margin: 0 0 10px 0;
                color: #007bff;
                font-size: 16px;
            }
            
            .info-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            
            .info-item {
                margin: 5px 0;
            }
            
            .info-label {
                font-weight: bold;
                color: #495057;
            }
            
            .section {
                margin-bottom: 30px;
                page-break-inside: avoid;
            }
            
            .section-title {
                background: #007bff;
                color: white;
                padding: 10px 15px;
                margin: 0 0 15px 0;
                font-size: 16px;
                font-weight: bold;
                border-radius: 5px;
            }
            
            .metrics-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 15px;
                margin-bottom: 20px;
            }
            
            .metric-card {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 8px;
                text-align: center;
                border: 1px solid #e9ecef;
            }
            
            .metric-value {
                font-size: 24px;
                font-weight: bold;
                color: #007bff;
                margin: 0;
            }
            
            .metric-label {
                font-size: 12px;
                color: #6c757d;
                margin: 5px 0 0 0;
            }
            
            .metric-subtext {
                font-size: 10px;
                color: #868e96;
                margin-top: 3px;
            }
            
            table {
                width: 100%;
                border-collapse: collapse;
                margin: 15px 0;
                font-size: 11px;
            }
            
            th {
                background: #007bff;
                color: white;
                padding: 10px 8px;
                text-align: left;
                font-weight: bold;
                border: 1px solid #0056b3;
            }
            
            td {
                padding: 8px;
                border: 1px solid #dee2e6;
                background: #fff;
            }
            
            tr:nth-child(even) td {
                background: #f8f9fa;
            }
            
            .progress-bar {
                background: #e9ecef;
                height: 15px;
                border-radius: 8px;
                overflow: hidden;
                position: relative;
            }
            
            .progress-fill {
                height: 100%;
                border-radius: 8px;
                transition: width 0.3s ease;
            }
            
            .progress-success { background: #28a745; }
            .progress-warning { background: #ffc107; }
            .progress-danger { background: #dc3545; }
            
            .progress-text {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                font-size: 10px;
                font-weight: bold;
                color: #333;
            }
            
            .badge {
                display: inline-block;
                padding: 3px 8px;
                font-size: 10px;
                font-weight: bold;
                color: white;
                border-radius: 12px;
                text-align: center;
            }
            
            .badge-primary { background: #007bff; }
            .badge-success { background: #28a745; }
            .badge-warning { background: #ffc107; color: #333; }
            .badge-danger { background: #dc3545; }
            .badge-info { background: #17a2b8; }
            
            .two-column {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
            }
            
            .chart-placeholder {
                background: #f8f9fa;
                border: 2px dashed #dee2e6;
                padding: 40px;
                text-align: center;
                color: #6c757d;
                border-radius: 8px;
                margin: 15px 0;
            }
            
            .footer {
                margin-top: 40px;
                padding-top: 20px;
                border-top: 1px solid #dee2e6;
                font-size: 10px;
                color: #6c757d;
                text-align: center;
            }
            
            @media print {
                body { margin: 0; }
                .section { page-break-inside: avoid; }
                .metrics-grid { grid-template-columns: repeat(2, 1fr); }
                .two-column { grid-template-columns: 1fr; }
            }
        </style>
    </head>
    <body>
        <!-- Header -->
        <div class="header">
            <h1>Voting Statistics Report</h1>
            <h2><?= htmlspecialchars($election_data['name']) ?></h2>
        </div>
        
        <!-- Election Information -->
        <div class="election-info">
            <h3>Election Details</h3>
            <div class="info-grid">
                <div>
                    <div class="info-item">
                        <span class="info-label">Election Type:</span> 
                        <?= htmlspecialchars($election_data['election_type_name']) ?>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Status:</span> 
                        <span class="badge badge-<?= $election_data['status'] === 'active' ? 'success' : 'primary' ?>">
                            <?= ucfirst($election_data['status']) ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Time Range:</span> 
                        <?= ucfirst(str_replace('_', ' ', $time_range)) ?>
                    </div>
                </div>
                <div>
                    <div class="info-item">
                        <span class="info-label">Start Date:</span> 
                        <?= date('M j, Y H:i', strtotime($election_data['start_date'])) ?>
                    </div>
                    <div class="info-item">
                        <span class="info-label">End Date:</span> 
                        <?= date('M j, Y H:i', strtotime($election_data['end_date'])) ?>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Generated:</span> 
                        <?= date('M j, Y H:i:s') ?>
                    </div>
                </div>
            </div>
            <?php if ($program_name): ?>
                <div class="info-item" style="margin-top: 10px;">
                    <span class="info-label">Program Filter:</span> 
                    <span class="badge badge-info"><?= htmlspecialchars($program_name) ?></span>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Overall Statistics -->
        <div class="section">
            <h3 class="section-title">📊 Overall Statistics</h3>
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-value"><?= number_format($statistics['overall']['total_voters']) ?></div>
                    <div class="metric-label">Total Voters</div>
                    <div class="metric-subtext">
                        <?= $statistics['overall']['turnout_percentage'] ?>% turnout<br>
                        (<?= number_format($statistics['overall']['eligible_voters']) ?> eligible)
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-value"><?= number_format($statistics['overall']['total_votes']) ?></div>
                    <div class="metric-label">Total Votes</div>
                    <div class="metric-subtext">
                        Across <?= $statistics['overall']['total_positions'] ?> positions
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-value"><?= number_format($statistics['overall']['avg_votes_per_session'], 1) ?></div>
                    <div class="metric-label">Avg Votes/Session</div>
                    <div class="metric-subtext">
                        <?= $statistics['overall']['total_candidates'] ?> candidates total
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-value"><?= count($statistics['daily_trends']) ?></div>
                    <div class="metric-label">Active Days</div>
                    <div class="metric-subtext">
                        <?php if ($statistics['overall']['first_vote_time']): ?>
                            From <?= date('M j', strtotime($statistics['overall']['first_vote_time'])) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Program Statistics -->
        <?php if (!empty($statistics['program_stats'])): ?>
        <div class="section">
            <h3 class="section-title">🎓 Program-wise Turnout</h3>
            <table>
                <thead>
                    <tr>
                        <th style="width: 35%;">Program</th>
                        <th style="width: 15%;">Students</th>
                        <th style="width: 15%;">Voted</th>
                        <th style="width: 15%;">Votes</th>
                        <th style="width: 20%;">Turnout Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($statistics['program_stats'] as $program): ?>
                    <tr>
                        <td><?= htmlspecialchars($program['program_name']) ?></td>
                        <td><?= number_format($program['total_students']) ?></td>
                        <td><?= number_format($program['voted_students']) ?></td>
                        <td><?= number_format($program['total_votes']) ?></td>
                        <td>
                            <div class="progress-bar">
                                <div class="progress-fill progress-<?= $program['turnout_rate'] >= 70 ? 'success' : ($program['turnout_rate'] >= 40 ? 'warning' : 'danger') ?>" 
                                     style="width: <?= min(100, $program['turnout_rate']) ?>%;"></div>
                                <div class="progress-text"><?= $program['turnout_rate'] ?>%</div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Position Statistics -->
        <?php if (!empty($statistics['position_stats'])): ?>
        <div class="section">
            <h3 class="section-title">📋 Position Statistics</h3>
            <table>
                <thead>
                    <tr>
                        <th style="width: 40%;">Position</th>
                        <th style="width: 15%;">Candidates</th>
                        <th style="width: 15%;">Votes</th>
                        <th style="width: 15%;">Abstains</th>
                        <th style="width: 15%;">Vote Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($statistics['position_stats'] as $position): ?>
                    <tr>
                        <td><?= htmlspecialchars($position['position_title']) ?></td>
                        <td>
                            <span class="badge badge-info"><?= $position['candidate_count'] ?></span>
                        </td>
                        <td>
                            <span class="badge badge-success"><?= number_format($position['total_votes']) ?></span>
                        </td>
                        <td>
                            <span class="badge badge-warning"><?= number_format($position['abstain_votes']) ?></span>
                        </td>
                        <td><?= $position['vote_rate'] ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Daily Trends -->
        <?php if (!empty($statistics['daily_trends'])): ?>
        <div class="section">
            <h3 class="section-title">📈 Daily Voting Trends</h3>
            <div class="chart-placeholder">
                <strong>Daily Voting Activity Chart</strong><br>
                <small>Interactive charts available in web version</small>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Voters</th>
                        <th>Votes</th>
                        <th>Avg Votes/Session</th>
                        <th>Avg Duration (min)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($statistics['daily_trends'] as $day): ?>
                    <tr>
                        <td><?= date('M j, Y', strtotime($day['vote_date'])) ?></td>
                        <td><?= $day['voters_count'] ?></td>
                        <td><?= $day['votes_count'] ?></td>
                        <td><?= number_format($day['avg_votes_per_session'], 1) ?></td>
                        <td><?= number_format($day['avg_session_duration'], 1) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Device Statistics -->
        <?php if (!empty($statistics['device_stats'])): ?>
        <div class="section">
            <h3 class="section-title">📱 Device Usage Statistics</h3>
            <div class="two-column">
                <div>
                    <div class="chart-placeholder">
                        <strong>Device Usage Distribution</strong><br>
                        <small>Pie chart available in web version</small>
                    </div>
                </div>
                <div>
                    <table>
                        <thead>
                            <tr>
                                <th>Device Type</th>
                                <th>Sessions</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($statistics['device_stats'] as $device): ?>
                            <tr>
                                <td><?= htmlspecialchars($device['device_type']) ?></td>
                                <td>
                                    <span class="badge badge-primary"><?= $device['session_count'] ?></span>
                                </td>
                                <td><?= $device['percentage'] ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Footer -->
        <div class="footer">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div style="text-align: left;">
                    <strong>Generated by:</strong> <?= SITE_NAME ?><br>
                    <strong>Report Generated:</strong> <?= date('M j, Y \a\t H:i:s') ?><br>
                    <strong>Generated by:</strong> <?= htmlspecialchars($current_user['first_name'] . ' ' . $current_user['last_name']) ?>
                </div>
                <div style="text-align: right;">
                    <strong>🤖 Generated with Claude Code</strong><br>
                    <em>Advanced AI-powered voting system analytics</em><br>
                    <small>This is an automated report based on real-time data</small>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    
    $html = ob_get_clean();
    
    // Use wkhtmltopdf if available, otherwise use basic PDF library
    if (function_exists('shell_exec') && shell_exec('which wkhtmltopdf')) {
        // Use wkhtmltopdf for better PDF generation
        $temp_html = tempnam(sys_get_temp_dir(), 'voting_stats_') . '.html';
        file_put_contents($temp_html, $html);
        
        $temp_pdf = tempnam(sys_get_temp_dir(), 'voting_stats_') . '.pdf';
        
        $command = "wkhtmltopdf --page-size A4 --orientation Portrait --margin-top 0.75in --margin-right 0.75in --margin-bottom 0.75in --margin-left 0.75in --encoding UTF-8 --print-media-type '$temp_html' '$temp_pdf'";
        shell_exec($command);
        
        if (file_exists($temp_pdf) && filesize($temp_pdf) > 0) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($temp_pdf));
            header('Cache-Control: no-cache, must-revalidate');
            
            readfile($temp_pdf);
            
            unlink($temp_html);
            unlink($temp_pdf);
        } else {
            // Fall back to HTML output if PDF generation fails
            header('Content-Type: text/html; charset=UTF-8');
            header('Content-Disposition: inline; filename="' . str_replace('.pdf', '.html', $filename) . '"');
            echo $html;
        }
    } else {
        // Fallback: serve as HTML if no PDF library available
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: inline; filename="' . str_replace('.pdf', '.html', $filename) . '"');
        echo $html;
    }
    
    exit;
}
?>