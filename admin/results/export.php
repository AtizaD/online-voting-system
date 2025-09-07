<?php
require_once '../../config/config.php';
require_once '../../config/database.php';

if (!isLoggedIn() || (!hasPermission('view_results') && !hasPermission('manage_voting'))) {
    redirectTo('auth/index.php');
}

$db = Database::getInstance()->getConnection();
$current_user = getCurrentUser();

// Get parameters
$election_id = isset($_GET['election_id']) ? (int)$_GET['election_id'] : null;
$format = isset($_GET['format']) ? sanitize($_GET['format']) : 'csv';

// Validate format
$allowed_formats = ['csv', 'excel', 'pdf', 'summary'];
if (!in_array($format, $allowed_formats)) {
    $format = 'csv';
}

// Export all elections summary if no specific election
if (!$election_id && $format === 'summary') {
    exportElectionsSummary($db, $format);
    exit;
}

// Get election data if specific election requested
$election_data = null;
$results = [];

if ($election_id) {
    // Get election details
    $stmt = $db->prepare("
        SELECT e.*, et.name as election_type_name
        FROM elections e 
        LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
        WHERE e.election_id = ?
    ");
    $stmt->execute([$election_id]);
    $election_data = $stmt->fetch();

    if (!$election_data) {
        $_SESSION['error_message'] = "Election not found.";
        redirectTo('admin/results/');
    }

    // Get comprehensive results
    $results = getElectionResults($db, $election_id);
}

// Handle different export formats
switch ($format) {
    case 'csv':
        exportCSV($election_data, $results);
        break;
    case 'excel':
        exportExcel($election_data, $results);
        break;
    case 'pdf':
        exportPDF($election_data, $results);
        break;
    default:
        $_SESSION['error_message'] = "Invalid export format.";
        redirectTo('admin/results/');
}

function getElectionResults($db, $election_id) {
    // Get positions with results
    $stmt = $db->prepare("
        SELECT p.position_id, p.title, p.description, p.display_order,
               COUNT(DISTINCT c.candidate_id) as candidate_count,
               COUNT(v.vote_id) as total_votes,
               COUNT(av.abstain_id) as abstain_votes
        FROM positions p
        LEFT JOIN candidates c ON p.position_id = c.position_id
        LEFT JOIN votes v ON c.candidate_id = v.candidate_id
        LEFT JOIN abstain_votes av ON p.position_id = av.position_id
        WHERE p.election_id = ?
        GROUP BY p.position_id
        ORDER BY p.display_order
    ");
    $stmt->execute([$election_id]);
    $positions = $stmt->fetchAll();

    $results = [];
    foreach ($positions as $position) {
        // Get candidates with vote counts
        $stmt = $db->prepare("
            SELECT c.candidate_id, c.student_id,
                   s.first_name, s.last_name, s.student_number, s.program_id,
                   p.program_name,
                   COUNT(v.vote_id) as vote_count
            FROM candidates c
            JOIN students s ON c.student_id = s.student_id
            JOIN programs p ON s.program_id = p.program_id
            LEFT JOIN votes v ON c.candidate_id = v.candidate_id
            WHERE c.position_id = ?
            GROUP BY c.candidate_id
            ORDER BY vote_count DESC, s.last_name ASC
        ");
        $stmt->execute([$position['position_id']]);
        $candidates = $stmt->fetchAll();

        // Calculate percentages
        $total_votes = array_sum(array_column($candidates, 'vote_count'));
        foreach ($candidates as &$candidate) {
            $candidate['percentage'] = $total_votes > 0 ? round(($candidate['vote_count'] / $total_votes) * 100, 2) : 0;
            $candidate['is_winner'] = $candidate === reset($candidates) && $candidate['vote_count'] > 0;
        }

        $results[$position['position_id']] = [
            'position' => $position,
            'candidates' => $candidates,
            'total_votes' => $total_votes
        ];
    }

    return $results;
}

function exportCSV($election_data, $results) {
    if (!$election_data) {
        $_SESSION['error_message'] = "Election data not found.";
        redirectTo('admin/results/');
    }

    $filename = 'election_results_' . $election_data['election_id'] . '_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

    $output = fopen('php://output', 'w');
    
    // Election header
    fputcsv($output, ['Election Results Export']);
    fputcsv($output, ['Election Name', $election_data['name']]);
    fputcsv($output, ['Election Type', $election_data['election_type_name']]);
    fputcsv($output, ['Status', ucfirst($election_data['status'])]);
    fputcsv($output, ['Start Date', date('Y-m-d H:i:s', strtotime($election_data['start_date']))]);
    fputcsv($output, ['End Date', date('Y-m-d H:i:s', strtotime($election_data['end_date']))]);
    fputcsv($output, ['Export Date', date('Y-m-d H:i:s')]);
    fputcsv($output, []);

    // Results data
    foreach ($results as $result) {
        fputcsv($output, ['POSITION: ' . $result['position']['title']]);
        fputcsv($output, ['Candidate Name', 'Student Number', 'Program', 'Votes', 'Percentage', 'Winner']);
        
        foreach ($result['candidates'] as $candidate) {
            fputcsv($output, [
                $candidate['first_name'] . ' ' . $candidate['last_name'],
                $candidate['student_number'],
                $candidate['program_name'],
                $candidate['vote_count'],
                $candidate['percentage'] . '%',
                $candidate['is_winner'] ? 'YES' : 'NO'
            ]);
        }
        
        fputcsv($output, ['Total Votes', $result['total_votes']]);
        fputcsv($output, ['Abstain Votes', $result['position']['abstain_votes']]);
        fputcsv($output, []);
    }
    
    fclose($output);
    exit;
}

function exportExcel($election_data, $results) {
    // For Excel export, we'll use HTML format that Excel can read
    if (!$election_data) {
        $_SESSION['error_message'] = "Election data not found.";
        redirectTo('admin/results/');
    }

    $filename = 'election_results_' . $election_data['election_id'] . '_' . date('Y-m-d') . '.xls';
    
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>';
    echo '<body>';

    // Election information
    echo '<table border="1">';
    echo '<tr><td colspan="6"><h2>Election Results: ' . htmlspecialchars($election_data['name']) . '</h2></td></tr>';
    echo '<tr><td><strong>Election Type:</strong></td><td>' . htmlspecialchars($election_data['election_type_name']) . '</td></tr>';
    echo '<tr><td><strong>Status:</strong></td><td>' . ucfirst($election_data['status']) . '</td></tr>';
    echo '<tr><td><strong>Start Date:</strong></td><td>' . date('Y-m-d H:i:s', strtotime($election_data['start_date'])) . '</td></tr>';
    echo '<tr><td><strong>End Date:</strong></td><td>' . date('Y-m-d H:i:s', strtotime($election_data['end_date'])) . '</td></tr>';
    echo '<tr><td><strong>Export Date:</strong></td><td>' . date('Y-m-d H:i:s') . '</td></tr>';
    echo '<tr><td colspan="6"></td></tr>';

    // Results
    foreach ($results as $result) {
        echo '<tr><td colspan="6"><h3>' . htmlspecialchars($result['position']['title']) . '</h3></td></tr>';
        echo '<tr>';
        echo '<td><strong>Candidate Name</strong></td>';
        echo '<td><strong>Student Number</strong></td>';
        echo '<td><strong>Program</strong></td>';
        echo '<td><strong>Votes</strong></td>';
        echo '<td><strong>Percentage</strong></td>';
        echo '<td><strong>Winner</strong></td>';
        echo '</tr>';
        
        foreach ($result['candidates'] as $candidate) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($candidate['first_name'] . ' ' . $candidate['last_name']) . '</td>';
            echo '<td>' . htmlspecialchars($candidate['student_number']) . '</td>';
            echo '<td>' . htmlspecialchars($candidate['program_name']) . '</td>';
            echo '<td>' . $candidate['vote_count'] . '</td>';
            echo '<td>' . $candidate['percentage'] . '%</td>';
            echo '<td>' . ($candidate['is_winner'] ? 'YES' : 'NO') . '</td>';
            echo '</tr>';
        }
        
        echo '<tr><td><strong>Total Votes:</strong></td><td>' . $result['total_votes'] . '</td></tr>';
        echo '<tr><td><strong>Abstain Votes:</strong></td><td>' . $result['position']['abstain_votes'] . '</td></tr>';
        echo '<tr><td colspan="6"></td></tr>';
    }
    
    echo '</table>';
    echo '</body></html>';
    exit;
}

function exportPDF($election_data, $results) {
    // Simple HTML to PDF using browser print
    if (!$election_data) {
        $_SESSION['error_message'] = "Election data not found.";
        redirectTo('admin/results/');
    }

    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Election Results - <?= htmlspecialchars($election_data['name']) ?></title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
            .election-info { margin-bottom: 30px; }
            .election-info table { width: 100%; border-collapse: collapse; }
            .election-info td { padding: 8px; border: 1px solid #ddd; }
            .position { margin-bottom: 30px; page-break-inside: avoid; }
            .position-title { background: #f8f9fa; padding: 15px; border-left: 5px solid #007bff; margin-bottom: 15px; }
            .candidates-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            .candidates-table th, .candidates-table td { padding: 10px; border: 1px solid #ddd; text-align: left; }
            .candidates-table th { background: #f8f9fa; }
            .winner { background: #d4edda; font-weight: bold; }
            .stats { background: #e9ecef; padding: 10px; }
            @media print {
                body { margin: 0; }
                .no-print { display: none; }
            }
        </style>
        <script>
            window.onload = function() {
                window.print();
            }
        </script>
    </head>
    <body>
        <div class="no-print" style="text-align: center; margin-bottom: 20px;">
            <p>This page will automatically print. <a href="javascript:window.close()">Close window</a> after printing.</p>
        </div>
        
        <div class="header">
            <h1>Election Results Report</h1>
            <h2><?= htmlspecialchars($election_data['name']) ?></h2>
        </div>

        <div class="election-info">
            <h3>Election Information</h3>
            <table>
                <tr><td><strong>Election Type:</strong></td><td><?= htmlspecialchars($election_data['election_type_name']) ?></td></tr>
                <tr><td><strong>Status:</strong></td><td><?= ucfirst($election_data['status']) ?></td></tr>
                <tr><td><strong>Start Date:</strong></td><td><?= date('F j, Y g:i A', strtotime($election_data['start_date'])) ?></td></tr>
                <tr><td><strong>End Date:</strong></td><td><?= date('F j, Y g:i A', strtotime($election_data['end_date'])) ?></td></tr>
                <tr><td><strong>Report Generated:</strong></td><td><?= date('F j, Y g:i A') ?></td></tr>
            </table>
        </div>

        <?php foreach ($results as $result): ?>
            <div class="position">
                <div class="position-title">
                    <h3><?= htmlspecialchars($result['position']['title']) ?></h3>
                    <?php if ($result['position']['description']): ?>
                        <p><?= htmlspecialchars($result['position']['description']) ?></p>
                    <?php endif; ?>
                </div>

                <?php if (!empty($result['candidates'])): ?>
                    <table class="candidates-table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Candidate Name</th>
                                <th>Student Number</th>
                                <th>Program</th>
                                <th>Votes</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['candidates'] as $index => $candidate): ?>
                                <tr class="<?= $candidate['is_winner'] ? 'winner' : '' ?>">
                                    <td><?= $index + 1 ?></td>
                                    <td>
                                        <?= htmlspecialchars($candidate['first_name'] . ' ' . $candidate['last_name']) ?>
                                        <?php if ($candidate['is_winner']): ?> 🏆<?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($candidate['student_number']) ?></td>
                                    <td><?= htmlspecialchars($candidate['program_name']) ?></td>
                                    <td><?= $candidate['vote_count'] ?></td>
                                    <td><?= $candidate['percentage'] ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><em>No candidates for this position</em></p>
                <?php endif; ?>

                <div class="stats">
                    <strong>Statistics:</strong> 
                    Total Votes: <?= $result['total_votes'] ?> | 
                    Abstain Votes: <?= $result['position']['abstain_votes'] ?> | 
                    Candidates: <?= count($result['candidates']) ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div style="margin-top: 30px; border-top: 1px solid #ddd; padding-top: 20px; text-align: center; color: #666;">
            <p>Report generated by <?= SITE_NAME ?> on <?= date('F j, Y \a\t g:i A') ?></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function exportElectionsSummary($db, $format) {
    // Get all elections summary
    $stmt = $db->query("
        SELECT e.election_id, e.name, e.status, e.start_date, e.end_date,
               et.name as election_type_name,
               COUNT(DISTINCT vs.student_id) as total_voters,
               COUNT(v.vote_id) as total_votes,
               (SELECT COUNT(*) FROM positions WHERE election_id = e.election_id) as total_positions
        FROM elections e
        LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
        LEFT JOIN voting_sessions vs ON e.election_id = vs.election_id AND vs.status = 'completed'
        LEFT JOIN votes v ON vs.session_id = v.session_id
        GROUP BY e.election_id
        ORDER BY e.start_date DESC
    ");
    $elections = $stmt->fetchAll();

    $filename = 'elections_summary_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['Elections Summary Report']);
    fputcsv($output, ['Generated on', date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    
    fputcsv($output, [
        'Election ID',
        'Election Name', 
        'Type',
        'Status',
        'Start Date',
        'End Date',
        'Total Positions',
        'Total Voters',
        'Total Votes'
    ]);
    
    foreach ($elections as $election) {
        fputcsv($output, [
            $election['election_id'],
            $election['name'],
            $election['election_type_name'],
            ucfirst($election['status']),
            date('Y-m-d', strtotime($election['start_date'])),
            date('Y-m-d', strtotime($election['end_date'])),
            $election['total_positions'],
            $election['total_voters'],
            $election['total_votes']
        ]);
    }
    
    fclose($output);
    exit;
}

// Log export activity
logActivity('export_results', "Exported results - Election ID: $election_id, Format: $format");
?>