<?php
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Load PhpSpreadsheet if available
$autoload_paths = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../autoload.php'
];

foreach ($autoload_paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        break;
    }
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

requireAuth(['election_officer']);

$db = Database::getInstance()->getConnection();

// Get parameters
$election_id = isset($_GET['election_id']) ? (int)$_GET['election_id'] : null;
$format = isset($_GET['format']) ? sanitize($_GET['format']) : 'csv';

// Validate format
$allowed_formats = ['csv', 'excel', 'pdf'];
if (!in_array($format, $allowed_formats)) {
    $format = 'csv';
}

if (!$election_id) {
    $_SESSION['error'] = "Election ID is required.";
    redirectTo('election-officer/results/');
}

// Get election details
$stmt = $db->prepare("SELECT * FROM elections WHERE election_id = ?");
$stmt->execute([$election_id]);
$election_data = $stmt->fetch();

if (!$election_data) {
    $_SESSION['error'] = "Election not found.";
    redirectTo('election-officer/results/');
}

// Get comprehensive results
$results = getElectionResults($db, $election_id);

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
        $_SESSION['error'] = "Invalid export format.";
        redirectTo('election-officer/results/');
}

function getElectionResults($db, $election_id) {
    // Get positions with results - ordered by display_order if available, then by title
    $stmt = $db->prepare("
        SELECT p.position_id, p.title, p.description,
               COALESCE(p.display_order, 999) as display_order,
               COUNT(DISTINCT c.candidate_id) as candidate_count,
               COUNT(v.vote_id) as total_votes
        FROM positions p
        LEFT JOIN candidates c ON p.position_id = c.position_id
        LEFT JOIN votes v ON c.candidate_id = v.candidate_id
        WHERE p.election_id = ?
        GROUP BY p.position_id
        ORDER BY display_order ASC, p.title ASC
    ");
    $stmt->execute([$election_id]);
    $positions = $stmt->fetchAll();

    $results = [];
    foreach ($positions as $position) {
        // Get candidates with vote counts
        $stmt = $db->prepare("
            SELECT c.candidate_id, c.student_id,
                   CONCAT(s.first_name, ' ', s.last_name) as candidate_name,
                   s.student_number,
                   prog.program_name,
                   cl.class_name,
                   COUNT(v.vote_id) as vote_count
            FROM candidates c
            JOIN students s ON c.student_id = s.student_id
            JOIN programs prog ON s.program_id = prog.program_id
            JOIN classes cl ON s.class_id = cl.class_id
            LEFT JOIN votes v ON c.candidate_id = v.candidate_id
            WHERE c.position_id = ?
            GROUP BY c.candidate_id
            ORDER BY vote_count DESC, candidate_name ASC
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
    $filename = 'election_results_' . $election_data['election_id'] . '_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

    $output = fopen('php://output', 'w');
    
    // Election header
    fputcsv($output, ['Election Results Export']);
    fputcsv($output, ['Election Name', $election_data['name']]);
    fputcsv($output, ['Status', ucfirst($election_data['status'])]);
    fputcsv($output, ['Start Date', date('Y-m-d H:i:s', strtotime($election_data['start_date']))]);
    fputcsv($output, ['End Date', date('Y-m-d H:i:s', strtotime($election_data['end_date']))]);
    fputcsv($output, ['Export Date', date('Y-m-d H:i:s')]);
    fputcsv($output, []);

    // Results data
    foreach ($results as $result) {
        fputcsv($output, ['POSITION: ' . $result['position']['title']]);
        fputcsv($output, ['Candidate Name', 'Student Number', 'Program', 'Class', 'Votes', 'Percentage', 'Winner']);
        
        foreach ($result['candidates'] as $candidate) {
            fputcsv($output, [
                $candidate['candidate_name'],
                $candidate['student_number'],
                $candidate['program_name'],
                $candidate['class_name'],
                $candidate['vote_count'],
                $candidate['percentage'] . '%',
                $candidate['is_winner'] ? 'YES' : 'NO'
            ]);
        }
        
        fputcsv($output, ['Total Votes', $result['total_votes']]);
        fputcsv($output, []);
    }
    
    fclose($output);
    exit;
}

function exportExcel($election_data, $results) {
    // Check if PhpSpreadsheet is available
    if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        // Fallback to tab-separated format
        exportExcelFallback($election_data, $results);
        return;
    }
    
    $filename = 'election_results_' . $election_data['election_id'] . '_' . date('Y-m-d') . '.xlsx';
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Election Results');
    
    // Set default font for entire sheet
    $sheet->getParent()->getDefaultStyle()->getFont()->setName('Times New Roman')->setSize(12);
    
    $row = 1;
    
    // Election header - Font size 16
    $sheet->setCellValue('A' . $row, 'Election Results Export');
    $sheet->getStyle('A' . $row)->getFont()
        ->setBold(true)
        ->setSize(16)
        ->setName('Times New Roman')
        ->getColor()->setRGB('1F4E79'); // Dark blue
    $sheet->getStyle('A' . $row)->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setRGB('E7F3FF'); // Light blue background
    $row += 2;
    
    // Election information - Font size 12
    $sheet->setCellValue('A' . $row, 'Election Name:');
    $sheet->setCellValue('B' . $row, $election_data['name']);
    $sheet->getStyle('A' . $row)->getFont()
        ->setBold(true)
        ->setSize(12)
        ->setName('Times New Roman');
    $sheet->getStyle('B' . $row)->getFont()
        ->setSize(12)
        ->setName('Times New Roman');
    $row++;
    
    $sheet->setCellValue('A' . $row, 'Status:');
    $sheet->setCellValue('B' . $row, ucfirst($election_data['status']));
    $sheet->getStyle('A' . $row)->getFont()
        ->setBold(true)
        ->setSize(12)
        ->setName('Times New Roman');
    $sheet->getStyle('B' . $row)->getFont()
        ->setSize(12)
        ->setName('Times New Roman');
    $row++;
    
    $sheet->setCellValue('A' . $row, 'Start Date:');
    $sheet->setCellValue('B' . $row, date('Y-m-d H:i:s', strtotime($election_data['start_date'])));
    $sheet->getStyle('A' . $row)->getFont()
        ->setBold(true)
        ->setSize(12)
        ->setName('Times New Roman');
    $sheet->getStyle('B' . $row)->getFont()
        ->setSize(12)
        ->setName('Times New Roman');
    $row++;
    
    $sheet->setCellValue('A' . $row, 'End Date:');
    $sheet->setCellValue('B' . $row, date('Y-m-d H:i:s', strtotime($election_data['end_date'])));
    $sheet->getStyle('A' . $row)->getFont()
        ->setBold(true)
        ->setSize(12)
        ->setName('Times New Roman');
    $sheet->getStyle('B' . $row)->getFont()
        ->setSize(12)
        ->setName('Times New Roman');
    $row++;
    
    $sheet->setCellValue('A' . $row, 'Export Date:');
    $sheet->setCellValue('B' . $row, date('Y-m-d H:i:s'));
    $sheet->getStyle('A' . $row)->getFont()
        ->setBold(true)
        ->setSize(12)
        ->setName('Times New Roman');
    $sheet->getStyle('B' . $row)->getFont()
        ->setSize(12)
        ->setName('Times New Roman');
    $row += 2;
    
    // Results data
    foreach ($results as $result) {
        // Position title - Font size 14
        $sheet->setCellValue('A' . $row, 'POSITION: ' . $result['position']['title']);
        $sheet->getStyle('A' . $row)->getFont()
            ->setBold(true)
            ->setSize(14)
            ->setName('Times New Roman')
            ->getColor()->setRGB('FFFFFF'); // White text
        $sheet->getStyle('A' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('2F5597'); // Dark blue background
        $sheet->getStyle('A' . $row . ':G' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('2F5597'); // Extend background across all columns
        $row++;
        
        // Headers - Font size 12
        $headers = ['Candidate Name', 'Student Number', 'Program', 'Class', 'Votes', 'Percentage', 'Winner'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $row, $header);
            $sheet->getStyle($col . $row)->getFont()
                ->setBold(true)
                ->setSize(12)
                ->setName('Times New Roman')
                ->getColor()->setRGB('000000'); // Black text
            $sheet->getStyle($col . $row)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('D9E2F3'); // Light blue background
            $col++;
        }
        $row++;
        
        // Candidate data - Font size 12
        foreach ($result['candidates'] as $candidate) {
            $sheet->setCellValue('A' . $row, $candidate['candidate_name']);
            $sheet->setCellValue('B' . $row, $candidate['student_number']);
            $sheet->setCellValue('C' . $row, $candidate['program_name']);
            $sheet->setCellValue('D' . $row, $candidate['class_name']);
            $sheet->setCellValue('E' . $row, $candidate['vote_count']);
            $sheet->setCellValue('F' . $row, $candidate['percentage'] / 100); // Store as decimal
            $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('0.00%'); // Format as percentage
            $sheet->setCellValue('G' . $row, $candidate['is_winner'] ? 'YES' : 'NO');
            
            // Set font for all candidate data
            $range = 'A' . $row . ':G' . $row;
            $sheet->getStyle($range)->getFont()
                ->setSize(12)
                ->setName('Times New Roman');
            
            // Highlight winner
            if ($candidate['is_winner']) {
                $sheet->getStyle($range)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('C6EFCE'); // Light green
                $sheet->getStyle($range)->getFont()
                    ->setBold(true)
                    ->getColor()->setRGB('006100'); // Dark green text
            } else {
                // Alternate row colors for non-winners
                $bgColor = ($row % 2 == 0) ? 'F2F2F2' : 'FFFFFF'; // Light gray/white alternating
                $sheet->getStyle($range)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB($bgColor);
            }
            
            $row++;
        }
        
        // Total votes - Font size 12
        $sheet->setCellValue('A' . $row, 'Total Votes:');
        $sheet->setCellValue('B' . $row, $result['total_votes']);
        $sheet->getStyle('A' . $row . ':B' . $row)->getFont()
            ->setBold(true)
            ->setSize(12)
            ->setName('Times New Roman')
            ->getColor()->setRGB('FFFFFF'); // White text
        $sheet->getStyle('A' . $row . ':G' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('70AD47'); // Green background
        $row += 2;
    }
    
    // Auto-size columns
    foreach (range('A', 'G') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    // Output
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

function exportExcelFallback($election_data, $results) {
    $filename = 'election_results_' . $election_data['election_id'] . '_' . date('Y-m-d') . '.xls';
    
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

    $output = fopen('php://output', 'w');
    
    // Election header
    fputcsv($output, ['Election Results Export'], "\t");
    fputcsv($output, ['Election Name', $election_data['name']], "\t");
    fputcsv($output, ['Status', ucfirst($election_data['status'])], "\t");
    fputcsv($output, ['Start Date', date('Y-m-d H:i:s', strtotime($election_data['start_date']))], "\t");
    fputcsv($output, ['End Date', date('Y-m-d H:i:s', strtotime($election_data['end_date']))], "\t");
    fputcsv($output, ['Export Date', date('Y-m-d H:i:s')], "\t");
    fputcsv($output, [], "\t");

    // Results data
    foreach ($results as $result) {
        fputcsv($output, ['POSITION: ' . $result['position']['title']], "\t");
        fputcsv($output, ['Candidate Name', 'Student Number', 'Program', 'Class', 'Votes', 'Percentage', 'Winner'], "\t");
        
        foreach ($result['candidates'] as $candidate) {
            fputcsv($output, [
                $candidate['candidate_name'],
                $candidate['student_number'],
                $candidate['program_name'],
                $candidate['class_name'],
                $candidate['vote_count'],
                $candidate['percentage'] . '%',
                $candidate['is_winner'] ? 'YES' : 'NO'
            ], "\t");
        }
        
        fputcsv($output, ['Total Votes', $result['total_votes']], "\t");
        fputcsv($output, [], "\t");
    }
    
    fclose($output);
    exit;
}

function exportPDF($election_data, $results) {
    // Check if TCPDF is available
    $tcpdf_paths = [
        __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php',
        __DIR__ . '/../../tcpdf/tcpdf.php',
        __DIR__ . '/../../vendor/tcpdf/tcpdf.php'
    ];
    
    $tcpdf_found = false;
    foreach ($tcpdf_paths as $path) {
        if (file_exists($path)) {
            require_once($path);
            $tcpdf_found = true;
            break;
        }
    }
    
    if (!$tcpdf_found) {
        // Fallback to HTML export if TCPDF not found
        exportPDFHtml($election_data, $results);
        return;
    }
    
    $filename = 'election_results_' . $election_data['election_id'] . '_' . date('Y-m-d_H-i-s') . '.pdf';
    
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator(SITE_NAME);
    $pdf->SetTitle('Election Results - ' . $election_data['name']);
    $pdf->SetSubject('Election Results Report');
    $pdf->SetKeywords('voting, results, election, report');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins - reduced bottom margin
    $pdf->SetMargins(15, 10, 15);
    $pdf->SetAutoPageBreak(TRUE, 20);
    
    // Set image scale factor
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', '', 12);
    
    // Header Section
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->SetTextColor(0, 123, 255);
    $pdf->Cell(0, 15, 'Election Results Report', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(108, 117, 125);
    $pdf->Cell(0, 10, $election_data['name'], 0, 1, 'C');
    
    $pdf->Ln(3);
    
    // Election Information Box
    $pdf->SetFillColor(248, 249, 250);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'Election Information', 0, 1, 'L', true);
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Ln(2);
    
    // Election info in two columns
    $pdf->Cell(90, 6, 'Status: ' . ucfirst($election_data['status']), 0, 0);
    $pdf->Cell(90, 6, 'Report Generated: ' . date('M j, Y H:i:s'), 0, 1);
    
    $pdf->Cell(90, 6, 'Start Date: ' . date('M j, Y H:i', strtotime($election_data['start_date'])), 0, 0);
    $pdf->Cell(90, 6, 'End Date: ' . date('M j, Y H:i', strtotime($election_data['end_date'])), 0, 1);
    
    $pdf->Ln(8);
    
    // Results for each position
    foreach ($results as $result) {
        // Check if we need a new page - increased threshold
        if ($pdf->GetY() > 240) {
            $pdf->AddPage();
        }
        
        // Position title
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColor(0, 123, 255);
        $pdf->Cell(0, 8, $result['position']['title'], 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        
        if ($result['position']['description']) {
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell(0, 6, $result['position']['description'], 0, 1);
        }
        
        $pdf->Ln(2);
        
        if (!empty($result['candidates'])) {
            // Table headers
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(0, 123, 255);
            $pdf->SetTextColor(255, 255, 255);
            
            $pdf->Cell(15, 8, 'Rank', 1, 0, 'C', true);
            $pdf->Cell(40, 8, 'Candidate Name', 1, 0, 'L', true);
            $pdf->Cell(25, 8, 'Student No.', 1, 0, 'C', true);
            $pdf->Cell(35, 8, 'Program', 1, 0, 'L', true);
            $pdf->Cell(25, 8, 'Class', 1, 0, 'L', true);
            $pdf->Cell(20, 8, 'Votes', 1, 0, 'C', true);
            $pdf->Cell(20, 8, '%', 1, 1, 'C', true);
            
            // Table data
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(0, 0, 0);
            
            foreach ($result['candidates'] as $index => $candidate) {
                $fill = $candidate['is_winner'];
                if ($fill) {
                    $pdf->SetFillColor(212, 237, 218);
                } else {
                    $pdf->SetFillColor(248, 249, 250);
                }
                
                $pdf->Cell(15, 6, ($index + 1), 1, 0, 'C', true);
                
                $name = $candidate['candidate_name'];
                if ($candidate['is_winner']) {
                    $name .= ' *';
                }
                $pdf->Cell(40, 6, $name, 1, 0, 'L', true);
                $pdf->Cell(25, 6, $candidate['student_number'], 1, 0, 'C', true);
                $pdf->Cell(35, 6, $candidate['program_name'], 1, 0, 'L', true);
                $pdf->Cell(25, 6, $candidate['class_name'], 1, 0, 'L', true);
                $pdf->Cell(20, 6, $candidate['vote_count'], 1, 0, 'C', true);
                $pdf->Cell(20, 6, $candidate['percentage'] . '%', 1, 1, 'C', true);
            }
            
            // Statistics row
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(233, 236, 239);
            $pdf->Cell(180, 6, 'Total Votes: ' . $result['total_votes'] . ' | Candidates: ' . count($result['candidates']) . ' | * = Winner', 1, 1, 'L', true);
            
        } else {
            $pdf->SetFont('helvetica', 'I', 10);
            $pdf->Cell(0, 6, 'No candidates for this position', 0, 1);
        }
        
        $pdf->Ln(6);
    }
    
    // Footer - moved closer to bottom
    $pdf->SetY(-15);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(108, 117, 125);
    $pdf->Cell(0, 6, 'Generated by: ' . SITE_NAME . ' | Report Generated: ' . date('M j, Y \a\t H:i:s'), 0, 1, 'L');
    
    // Output PDF
    $pdf->Output($filename, 'D'); // D = download
    exit;
}

function exportPDFHtml($election_data, $results) {
    // Fallback HTML export when TCPDF is not available
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
                                <th>Class</th>
                                <th>Votes</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['candidates'] as $index => $candidate): ?>
                                <tr class="<?= $candidate['is_winner'] ? 'winner' : '' ?>">
                                    <td><?= $index + 1 ?></td>
                                    <td>
                                        <?= htmlspecialchars($candidate['candidate_name']) ?>
                                        <?php if ($candidate['is_winner']): ?> (WINNER)<?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($candidate['student_number']) ?></td>
                                    <td><?= htmlspecialchars($candidate['program_name']) ?></td>
                                    <td><?= htmlspecialchars($candidate['class_name']) ?></td>
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

// Log export activity
logActivity('export_results', "Exported results - Election ID: $election_id, Format: $format");
?>