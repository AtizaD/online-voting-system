<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../auth/session.php';
require_once '../../vendor/autoload.php';

// Require admin or election officer permissions
requireAuth(['admin', 'election_officer']);

// Get election ID
$election_id = intval($_GET['election_id'] ?? 0);

if (!$election_id) {
    die('Invalid election ID');
}

$db = Database::getInstance()->getConnection();
$current_user = getCurrentUser();

// Get election data
$stmt = $db->prepare("
    SELECT e.*, et.name as type_name, u.first_name as created_by_fname, u.last_name as created_by_lname
    FROM elections e
    LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
    LEFT JOIN users u ON e.created_by = u.user_id
    WHERE e.election_id = ?
");
$stmt->execute([$election_id]);
$election_data = $stmt->fetch();

if (!$election_data) {
    die('Election not found');
}

// Get election statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT vs.student_id) as total_voters,
        COUNT(v.vote_id) as total_votes,
        COUNT(DISTINCT p.position_id) as total_positions,
        (SELECT COUNT(*) FROM students s 
         JOIN classes c ON s.class_id = c.class_id 
         WHERE c.is_active = 1 AND s.is_active = 1) as total_students,
        (SELECT COUNT(*) FROM students s 
         JOIN classes c ON s.class_id = c.class_id 
         WHERE c.is_active = 1 AND s.is_active = 1 AND s.is_verified = 1) as total_verified
    FROM elections e
    LEFT JOIN voting_sessions vs ON e.election_id = vs.election_id AND vs.status = 'completed'
    LEFT JOIN votes v ON vs.session_id = v.session_id
    LEFT JOIN positions p ON e.election_id = p.election_id AND p.is_active = 1
    WHERE e.election_id = ?
");
$stmt->execute([$election_id]);
$statistics = $stmt->fetch();

// First, get ALL positions for this election to ensure none are missed
$positions_stmt = $db->prepare("
    SELECT position_id, title, description, display_order
    FROM positions 
    WHERE election_id = ? AND is_active = 1 
    ORDER BY display_order, position_id
");
$positions_stmt->execute([$election_id]);
$all_positions = $positions_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total voters count (reusable)
$total_voters_stmt = $db->prepare("
    SELECT COUNT(DISTINCT vs.student_id) as total_voters
    FROM voting_sessions vs 
    WHERE vs.election_id = ? AND vs.status = 'completed'
");
$total_voters_stmt->execute([$election_id]);
$total_voters_result = $total_voters_stmt->fetch();
$total_voters = $total_voters_result['total_voters'] ?? 0;

// Initialize results array with all positions
$results = [];
foreach ($all_positions as $position) {
    $results[$position['position_id']] = [
        'position_title' => $position['title'],
        'position_description' => $position['description'],
        'display_order' => $position['display_order'],
        'total_voters' => $total_voters,
        'candidates' => []
    ];
}

// Now get candidates and their vote counts for each position
$candidates_stmt = $db->prepare("
    SELECT 
        p.position_id,
        c.candidate_id,
        c.student_id,
        c.photo_url,
        s.first_name,
        s.last_name,
        s.student_number,
        prog.program_name,
        cl.class_name,
        COALESCE(vote_counts.vote_count, 0) as vote_count
    FROM positions p
    LEFT JOIN candidates c ON p.position_id = c.position_id
    LEFT JOIN students s ON c.student_id = s.student_id
    LEFT JOIN programs prog ON s.program_id = prog.program_id
    LEFT JOIN classes cl ON s.class_id = cl.class_id
    LEFT JOIN (
        SELECT 
            v.candidate_id, 
            COUNT(*) as vote_count
        FROM votes v
        JOIN voting_sessions vs ON v.session_id = vs.session_id
        WHERE vs.election_id = ? AND vs.status = 'completed'
        GROUP BY v.candidate_id
    ) vote_counts ON c.candidate_id = vote_counts.candidate_id
    WHERE p.election_id = ? AND p.is_active = 1
    ORDER BY p.display_order, p.position_id, vote_count DESC, s.first_name, s.last_name
");
$candidates_stmt->execute([$election_id, $election_id]);
$candidates_results = $candidates_stmt->fetchAll();

// Add candidates to their respective positions
foreach ($candidates_results as $candidate) {
    $position_id = $candidate['position_id'];
    
    // Only add if candidate exists (candidate_id is not null)
    if ($candidate['candidate_id']) {
        $results[$position_id]['candidates'][] = [
            'candidate_id' => $candidate['candidate_id'],
            'student_id' => $candidate['student_id'],
            'name' => trim($candidate['first_name'] . ' ' . $candidate['last_name']),
            'student_number' => $candidate['student_number'],
            'photo_url' => $candidate['photo_url'],
            'program_name' => $candidate['program_name'] ?? 'N/A',
            'class_name' => $candidate['class_name'] ?? 'N/A',
            'vote_count' => (int)$candidate['vote_count']
        ];
    }
}


// Calculate percentages and determine winners for each position
foreach ($results as $position_id => &$position) {
    $total_votes = array_sum(array_column($position['candidates'], 'vote_count'));
    $position['total_votes'] = $total_votes;
    
    $highest_votes = 0;
    foreach ($position['candidates'] as &$candidate) {
        $candidate['percentage'] = $total_votes > 0 
            ? round(($candidate['vote_count'] / $total_votes) * 100, 2) 
            : 0;
        if ($candidate['vote_count'] > $highest_votes) {
            $highest_votes = $candidate['vote_count'];
        }
    }
    
    // Mark winners (only if they have votes)
    foreach ($position['candidates'] as &$candidate) {
        $candidate['is_winner'] = ($candidate['vote_count'] > 0 && $candidate['vote_count'] == $highest_votes);
    }
    unset($candidate); // Break reference
}
unset($position); // Break reference

// Calculate overall voter turnout
$turnout = $statistics['total_verified'] > 0 
    ? round(($statistics['total_voters'] / $statistics['total_verified']) * 100, 1) 
    : 0;

// Generate PDF using TCPDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('Online Voting System');
$pdf->SetAuthor($current_user['first_name'] . ' ' . $current_user['last_name']);
$pdf->SetTitle('Election Results - ' . $election_data['name']);
$pdf->SetSubject('Official Election Results Report');

// Set default header data
$pdf->SetHeaderData('', 0, 'Election Results Report', $election_data['name'] . "\nGenerated on " . date('F j, Y \a\t g:i A'));

// Set header and footer fonts
$pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
$pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

// Set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// Set margins
$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
$pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

// Set image scale factor
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// Add a page
$pdf->AddPage();

// Set font
$pdf->SetFont('helvetica', 'B', 16);

// Title
$pdf->Cell(0, 10, 'Official Election Results', 0, 1, 'C');
$pdf->Ln(5);

// Election Information
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'Election Information', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);

$info_data = [
    'Election Type' => $election_data['type_name'] ?? 'General Election',
    'Status' => ucfirst($election_data['status']),
    'Duration' => date('M j, Y g:i A', strtotime($election_data['start_date'])) . ' - ' . date('M j, Y g:i A', strtotime($election_data['end_date'])),
];

if ($election_data['results_published_at']) {
    $info_data['Results Published'] = date('M j, Y g:i A', strtotime($election_data['results_published_at']));
}

$info_data['Created by'] = $election_data['created_by_fname'] . ' ' . $election_data['created_by_lname'];

foreach ($info_data as $label => $value) {
    $pdf->Cell(40, 6, $label . ':', 0, 0, 'L');
    $pdf->Cell(0, 6, $value, 0, 1, 'L');
}

$pdf->Ln(5);

// Statistics
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'Election Statistics', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 10);

$stats_data = [
    'Total Students' => number_format($statistics['total_students']),
    'Total Verified' => number_format($statistics['total_verified']),
    'Students Voted' => number_format($statistics['total_voters']),
    'Total Votes Cast' => number_format($statistics['total_votes']),
    'Voter Turnout' => $turnout . '%',
    'Positions Contested' => number_format($statistics['total_positions']),
];

foreach ($stats_data as $label => $value) {
    $pdf->Cell(40, 6, $label . ':', 0, 0, 'L');
    $pdf->Cell(0, 6, $value, 0, 1, 'L');
}

$pdf->Ln(10);

// Results by Position (sorted by display_order)
uasort($results, function($a, $b) {
    return $a['display_order'] <=> $b['display_order'];
});

foreach ($results as $position_id => $position) {
    // Check if we need a new page
    if ($pdf->GetY() > 250) {
        $pdf->AddPage();
    }
    
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, $position['position_title'], 0, 1, 'L');
    
    if ($position['position_description']) {
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->Cell(0, 6, $position['position_description'], 0, 1, 'L');
    }
    
    $pdf->Ln(3);
    
    if (empty($position['candidates'])) {
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->Cell(0, 6, 'No candidates registered for this position', 0, 1, 'C');
    } else {
        // Table headers
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(15, 8, 'Rank', 1, 0, 'C');
        $pdf->Cell(60, 8, 'Candidate Name', 1, 0, 'L');
        $pdf->Cell(30, 8, 'Student ID', 1, 0, 'C');
        $pdf->Cell(50, 8, 'Program/Class', 1, 0, 'L');
        $pdf->Cell(20, 8, 'Votes', 1, 0, 'C');
        $pdf->Cell(15, 8, '%', 1, 1, 'C');
        
        // Candidates data
        $pdf->SetFont('helvetica', '', 9);
        foreach ($position['candidates'] as $index => $candidate) {
            $pdf->Cell(15, 6, '#' . ($index + 1), 1, 0, 'C');
            
            $name = $candidate['name'];
            if ($candidate['is_winner']) {
                $name .= ' ★';
                $pdf->SetFont('helvetica', 'B', 9);
            }
            
            $pdf->Cell(60, 6, $name, 1, 0, 'L');
            
            if ($candidate['is_winner']) {
                $pdf->SetFont('helvetica', '', 9);
            }
            
            $pdf->Cell(30, 6, $candidate['student_number'], 1, 0, 'C');
            $pdf->Cell(50, 6, $candidate['program_name'] . ' - ' . $candidate['class_name'], 1, 0, 'L');
            $pdf->Cell(20, 6, number_format($candidate['vote_count']), 1, 0, 'C');
            $pdf->Cell(15, 6, $candidate['percentage'] . '%', 1, 1, 'C');
        }
    }
    
    $pdf->Ln(5);
}

// Footer
$pdf->Ln(10);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->Cell(0, 4, 'This is an official election results report generated from the Online Voting System.', 0, 1, 'C');
$pdf->Cell(0, 4, 'Report generated by: ' . $current_user['first_name'] . ' ' . $current_user['last_name'] . ' on ' . date('F j, Y \a\t g:i A'), 0, 1, 'C');

// Output PDF
$filename = 'Election_Results_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $election_data['name']) . '_' . date('Y-m-d') . '.pdf';
$pdf->Output($filename, 'D');

// Log the export action
$stmt = $db->prepare("
    INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, ip_address, user_agent)
    VALUES (?, 'export_pdf', 'elections', ?, ?, ?, ?)
");
$stmt->execute([
    $current_user['id'],
    $election_id,
    json_encode([
        'election_name' => $election_data['name'],
        'exported_by' => $current_user['first_name'] . ' ' . $current_user['last_name'],
        'total_voters' => $statistics['total_voters'],
        'total_votes' => $statistics['total_votes'],
        'positions_included' => count($results)
    ]),
    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
]);
?>