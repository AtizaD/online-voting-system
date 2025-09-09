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

requireAuth(['staff']);

$db = Database::getInstance()->getConnection();

// Get parameters
$format = isset($_GET['format']) ? sanitize($_GET['format']) : 'csv';

// Validate format
$allowed_formats = ['csv', 'excel', 'pdf'];
if (!in_array($format, $allowed_formats)) {
    $format = 'csv';
}

// Get verification data
$verification_data = getVerificationData($db);

// Handle different export formats
switch ($format) {
    case 'csv':
        exportCSV($verification_data);
        break;
    case 'excel':
        exportExcel($verification_data);
        break;
    case 'pdf':
        exportPDF($verification_data);
        break;
    default:
        $_SESSION['error'] = "Invalid export format.";
        redirectTo('staff/reports/verification.php');
}

function getVerificationData($db) {
    // Get overall statistics
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN is_verified = 0 THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) as verified
        FROM students WHERE is_active = 1
    ");
    $stmt->execute();
    $overall_stats = $stmt->fetch();

    // Get students with details
    $stmt = $db->prepare("
        SELECT s.*, prog.program_name, cl.class_name,
               CASE 
                   WHEN s.is_verified = 1 THEN 'Verified'
                   ELSE 'Pending'
               END as verification_status,
               CASE 
                   WHEN s.is_verified = 1 THEN s.verified_at
                   ELSE s.created_at 
               END as status_date
        FROM students s
        LEFT JOIN programs prog ON s.program_id = prog.program_id
        LEFT JOIN classes cl ON s.class_id = cl.class_id
        WHERE s.is_active = 1
        ORDER BY prog.program_name, cl.class_name, s.last_name, s.first_name
    ");
    $stmt->execute();
    $students = $stmt->fetchAll();

    // Get class-wise statistics
    $stmt = $db->prepare("
        SELECT 
            prog.program_name,
            cl.class_name,
            COUNT(*) as total_students,
            SUM(CASE WHEN s.is_verified = 1 THEN 1 ELSE 0 END) as verified_count,
            SUM(CASE WHEN s.is_verified = 0 THEN 1 ELSE 0 END) as pending_count
        FROM students s
        LEFT JOIN programs prog ON s.program_id = prog.program_id
        LEFT JOIN classes cl ON s.class_id = cl.class_id
        WHERE s.is_active = 1
        GROUP BY prog.program_id, cl.class_id
        ORDER BY prog.program_name, cl.class_name
    ");
    $stmt->execute();
    $class_stats = $stmt->fetchAll();

    return [
        'overall_stats' => $overall_stats,
        'students' => $students,
        'class_stats' => $class_stats
    ];
}

function exportCSV($verification_data) {
    $filename = 'student_verification_report_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

    $output = fopen('php://output', 'w');
    
    // Report header
    fputcsv($output, ['Student Verification Report']);
    fputcsv($output, ['Generated on', date('Y-m-d H:i:s')]);
    fputcsv($output, []);

    // Overall statistics
    fputcsv($output, ['Overall Statistics']);
    fputcsv($output, ['Total Students', $verification_data['overall_stats']['total']]);
    fputcsv($output, ['Verified Students', $verification_data['overall_stats']['verified']]);
    fputcsv($output, ['Pending Students', $verification_data['overall_stats']['pending']]);
    $completion_rate = $verification_data['overall_stats']['total'] > 0 ? 
        round(($verification_data['overall_stats']['verified'] / $verification_data['overall_stats']['total']) * 100, 1) : 0;
    fputcsv($output, ['Completion Rate', $completion_rate . '%']);
    fputcsv($output, []);

    // Class-wise statistics
    fputcsv($output, ['Class-wise Statistics']);
    fputcsv($output, ['Program', 'Class', 'Total Students', 'Verified', 'Pending', 'Completion Rate']);
    
    foreach ($verification_data['class_stats'] as $class_stat) {
        $class_completion = $class_stat['total_students'] > 0 ? 
            round(($class_stat['verified_count'] / $class_stat['total_students']) * 100, 1) : 0;
        fputcsv($output, [
            $class_stat['program_name'],
            $class_stat['class_name'],
            $class_stat['total_students'],
            $class_stat['verified_count'],
            $class_stat['pending_count'],
            $class_completion . '%'
        ]);
    }
    fputcsv($output, []);

    // Individual students
    fputcsv($output, ['Student Details']);
    fputcsv($output, ['First Name', 'Last Name', 'Student Number', 'Program', 'Class', 'Status', 'Status Date']);
    
    foreach ($verification_data['students'] as $student) {
        fputcsv($output, [
            $student['first_name'],
            $student['last_name'],
            $student['student_number'],
            $student['program_name'],
            $student['class_name'],
            $student['verification_status'],
            date('Y-m-d H:i:s', strtotime($student['status_date']))
        ]);
    }
    
    fclose($output);
    exit;
}

function exportExcel($verification_data) {
    // Check if PhpSpreadsheet is available
    if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        // Fallback to CSV format
        exportCSVFallback($verification_data);
        return;
    }
    
    $filename = 'student_verification_report_' . date('Y-m-d') . '.xlsx';
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Verification Report');
    
    // Set default font for entire sheet
    $sheet->getParent()->getDefaultStyle()->getFont()->setName('Times New Roman')->setSize(12);
    
    $row = 1;
    
    // Report header - Font size 16
    $sheet->setCellValue('A' . $row, 'Student Verification Report');
    $sheet->getStyle('A' . $row)->getFont()
        ->setBold(true)
        ->setSize(16)
        ->setName('Times New Roman')
        ->getColor()->setRGB('1F4E79'); // Dark blue
    $sheet->getStyle('A' . $row)->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setRGB('E7F3FF'); // Light blue background
    $row += 2;
    
    // Generation date
    $sheet->setCellValue('A' . $row, 'Generated on:');
    $sheet->setCellValue('B' . $row, date('Y-m-d H:i:s'));
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $row += 2;
    
    // Overall statistics - Font size 14
    $sheet->setCellValue('A' . $row, 'Overall Statistics');
    $sheet->getStyle('A' . $row)->getFont()
        ->setBold(true)
        ->setSize(14)
        ->setName('Times New Roman')
        ->getColor()->setRGB('FFFFFF'); // White text
    $sheet->getStyle('A' . $row . ':B' . $row)->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setRGB('2F5597'); // Dark blue background
    $row++;
    
    $sheet->setCellValue('A' . $row, 'Total Students:');
    $sheet->setCellValue('B' . $row, $verification_data['overall_stats']['total']);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $row++;
    
    $sheet->setCellValue('A' . $row, 'Verified Students:');
    $sheet->setCellValue('B' . $row, $verification_data['overall_stats']['verified']);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $row++;
    
    $sheet->setCellValue('A' . $row, 'Pending Students:');
    $sheet->setCellValue('B' . $row, $verification_data['overall_stats']['pending']);
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $row++;
    
    $completion_rate = $verification_data['overall_stats']['total'] > 0 ? 
        ($verification_data['overall_stats']['verified'] / $verification_data['overall_stats']['total']) : 0;
    $sheet->setCellValue('A' . $row, 'Completion Rate:');
    $sheet->setCellValue('B' . $row, $completion_rate);
    $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('0.00%');
    $sheet->getStyle('A' . $row)->getFont()->setBold(true);
    $row += 2;
    
    // Class-wise statistics
    $sheet->setCellValue('A' . $row, 'Class-wise Statistics');
    $sheet->getStyle('A' . $row)->getFont()
        ->setBold(true)
        ->setSize(14)
        ->setName('Times New Roman')
        ->getColor()->setRGB('FFFFFF'); // White text
    $sheet->getStyle('A' . $row . ':F' . $row)->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setRGB('2F5597'); // Dark blue background
    $row++;
    
    // Headers
    $headers = ['Program', 'Class', 'Total Students', 'Verified', 'Pending', 'Completion Rate'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $row, $header);
        $sheet->getStyle($col . $row)->getFont()
            ->setBold(true)
            ->setSize(12)
            ->setName('Times New Roman');
        $sheet->getStyle($col . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('D9E2F3'); // Light blue background
        $col++;
    }
    $row++;
    
    // Class data
    foreach ($verification_data['class_stats'] as $class_stat) {
        $class_completion = $class_stat['total_students'] > 0 ? 
            ($class_stat['verified_count'] / $class_stat['total_students']) : 0;
        
        $sheet->setCellValue('A' . $row, $class_stat['program_name']);
        $sheet->setCellValue('B' . $row, $class_stat['class_name']);
        $sheet->setCellValue('C' . $row, $class_stat['total_students']);
        $sheet->setCellValue('D' . $row, $class_stat['verified_count']);
        $sheet->setCellValue('E' . $row, $class_stat['pending_count']);
        $sheet->setCellValue('F' . $row, $class_completion);
        $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('0.00%');
        
        // Set font
        $range = 'A' . $row . ':F' . $row;
        $sheet->getStyle($range)->getFont()->setSize(12)->setName('Times New Roman');
        
        // Alternate row colors
        $bgColor = ($row % 2 == 0) ? 'F2F2F2' : 'FFFFFF';
        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB($bgColor);
        
        $row++;
    }
    $row += 2;
    
    // Student details
    $sheet->setCellValue('A' . $row, 'Student Details');
    $sheet->getStyle('A' . $row)->getFont()
        ->setBold(true)
        ->setSize(14)
        ->setName('Times New Roman')
        ->getColor()->setRGB('FFFFFF'); // White text
    $sheet->getStyle('A' . $row . ':G' . $row)->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setRGB('2F5597'); // Dark blue background
    $row++;
    
    // Student headers
    $student_headers = ['First Name', 'Last Name', 'Student Number', 'Program', 'Class', 'Status', 'Status Date'];
    $col = 'A';
    foreach ($student_headers as $header) {
        $sheet->setCellValue($col . $row, $header);
        $sheet->getStyle($col . $row)->getFont()
            ->setBold(true)
            ->setSize(12)
            ->setName('Times New Roman');
        $sheet->getStyle($col . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('D9E2F3'); // Light blue background
        $col++;
    }
    $row++;
    
    // Student data
    foreach ($verification_data['students'] as $student) {
        $sheet->setCellValue('A' . $row, $student['first_name']);
        $sheet->setCellValue('B' . $row, $student['last_name']);
        $sheet->setCellValue('C' . $row, $student['student_number']);
        $sheet->setCellValue('D' . $row, $student['program_name']);
        $sheet->setCellValue('E' . $row, $student['class_name']);
        $sheet->setCellValue('F' . $row, $student['verification_status']);
        $sheet->setCellValue('G' . $row, date('Y-m-d H:i:s', strtotime($student['status_date'])));
        
        // Set font
        $range = 'A' . $row . ':G' . $row;
        $sheet->getStyle($range)->getFont()->setSize(12)->setName('Times New Roman');
        
        // Highlight verified students
        if ($student['verification_status'] === 'Verified') {
            $sheet->getStyle($range)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('C6EFCE'); // Light green
            $sheet->getStyle($range)->getFont()->getColor()->setRGB('006100'); // Dark green text
        } else {
            // Alternate row colors for pending
            $bgColor = ($row % 2 == 0) ? 'FFF2CC' : 'FFFFFF'; // Light yellow/white
            $sheet->getStyle($range)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB($bgColor);
        }
        
        $row++;
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

function exportCSVFallback($verification_data) {
    exportCSV($verification_data); // Use the CSV function as fallback
}

function exportPDF($verification_data) {
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
        exportPDFHtml($verification_data);
        return;
    }
    
    $filename = 'student_verification_report_' . date('Y-m-d_H-i-s') . '.pdf';
    
    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator(SITE_NAME);
    $pdf->SetTitle('Student Verification Report');
    $pdf->SetSubject('Student Verification Report');
    $pdf->SetKeywords('student, verification, report');
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(15, 10, 15);
    $pdf->SetAutoPageBreak(TRUE, 20);
    
    // Add a page
    $pdf->AddPage();
    
    // Header Section
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->SetTextColor(0, 123, 255);
    $pdf->Cell(0, 15, 'Student Verification Report', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 6, 'Report Generated: ' . date('M j, Y H:i:s'), 0, 1, 'C');
    $pdf->Ln(5);
    
    // Overall Statistics
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(0, 123, 255);
    $pdf->Cell(0, 8, 'Overall Statistics', 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);
    
    $pdf->SetFont('helvetica', '', 10);
    $completion_rate = $verification_data['overall_stats']['total'] > 0 ? 
        round(($verification_data['overall_stats']['verified'] / $verification_data['overall_stats']['total']) * 100, 1) : 0;
    
    $pdf->Cell(90, 6, 'Total Students: ' . number_format($verification_data['overall_stats']['total']), 0, 0);
    $pdf->Cell(90, 6, 'Completion Rate: ' . $completion_rate . '%', 0, 1);
    $pdf->Cell(90, 6, 'Verified: ' . number_format($verification_data['overall_stats']['verified']), 0, 0);
    $pdf->Cell(90, 6, 'Pending: ' . number_format($verification_data['overall_stats']['pending']), 0, 1);
    $pdf->Ln(8);
    
    // Class-wise Statistics
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(0, 123, 255);
    $pdf->Cell(0, 8, 'Class-wise Statistics', 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);
    
    // Table headers
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(0, 123, 255);
    $pdf->SetTextColor(255, 255, 255);
    
    $pdf->Cell(40, 8, 'Program', 1, 0, 'L', true);
    $pdf->Cell(30, 8, 'Class', 1, 0, 'L', true);
    $pdf->Cell(25, 8, 'Total', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Verified', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Pending', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Rate %', 1, 1, 'C', true);
    
    // Table data
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    
    foreach ($verification_data['class_stats'] as $class_stat) {
        $class_completion = $class_stat['total_students'] > 0 ? 
            round(($class_stat['verified_count'] / $class_stat['total_students']) * 100, 1) : 0;
        
        if ($class_completion >= 80) {
            $pdf->SetFillColor(212, 237, 218); // Light green
        } elseif ($class_completion >= 50) {
            $pdf->SetFillColor(255, 243, 205); // Light yellow
        } else {
            $pdf->SetFillColor(248, 215, 218); // Light red
        }
        
        $pdf->Cell(40, 6, $class_stat['program_name'], 1, 0, 'L', true);
        $pdf->Cell(30, 6, $class_stat['class_name'], 1, 0, 'L', true);
        $pdf->Cell(25, 6, $class_stat['total_students'], 1, 0, 'C', true);
        $pdf->Cell(25, 6, $class_stat['verified_count'], 1, 0, 'C', true);
        $pdf->Cell(25, 6, $class_stat['pending_count'], 1, 0, 'C', true);
        $pdf->Cell(25, 6, $class_completion . '%', 1, 1, 'C', true);
    }
    
    // Footer
    $pdf->SetY(-15);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(108, 117, 125);
    $pdf->Cell(0, 6, 'Generated by: ' . SITE_NAME . ' | Report Generated: ' . date('M j, Y \a\t H:i:s'), 0, 1, 'L');
    
    // Output PDF
    $pdf->Output($filename, 'D'); // D = download
    exit;
}

function exportPDFHtml($verification_data) {
    // Fallback HTML export when TCPDF is not available
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Student Verification Report</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
            .section { margin-bottom: 30px; }
            .section h3 { background: #f8f9fa; padding: 10px; border-left: 5px solid #007bff; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { padding: 8px; border: 1px solid #ddd; text-align: left; }
            th { background: #f8f9fa; }
            .verified { background: #d4edda; }
            .pending { background: #fff3cd; }
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
            <h1>Student Verification Report</h1>
            <p>Generated on <?= date('F j, Y g:i A') ?></p>
        </div>

        <div class="section">
            <h3>Overall Statistics</h3>
            <table>
                <tr><td><strong>Total Students:</strong></td><td><?= number_format($verification_data['overall_stats']['total']) ?></td></tr>
                <tr><td><strong>Verified Students:</strong></td><td><?= number_format($verification_data['overall_stats']['verified']) ?></td></tr>
                <tr><td><strong>Pending Students:</strong></td><td><?= number_format($verification_data['overall_stats']['pending']) ?></td></tr>
                <tr><td><strong>Completion Rate:</strong></td><td>
                    <?php 
                    $completion_rate = $verification_data['overall_stats']['total'] > 0 ? 
                        round(($verification_data['overall_stats']['verified'] / $verification_data['overall_stats']['total']) * 100, 1) : 0;
                    echo $completion_rate . '%';
                    ?>
                </td></tr>
            </table>
        </div>

        <div class="section">
            <h3>Class-wise Statistics</h3>
            <table>
                <thead>
                    <tr>
                        <th>Program</th>
                        <th>Class</th>
                        <th>Total Students</th>
                        <th>Verified</th>
                        <th>Pending</th>
                        <th>Completion Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($verification_data['class_stats'] as $class_stat): ?>
                        <?php 
                        $class_completion = $class_stat['total_students'] > 0 ? 
                            round(($class_stat['verified_count'] / $class_stat['total_students']) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($class_stat['program_name']) ?></td>
                            <td><?= htmlspecialchars($class_stat['class_name']) ?></td>
                            <td><?= $class_stat['total_students'] ?></td>
                            <td><?= $class_stat['verified_count'] ?></td>
                            <td><?= $class_stat['pending_count'] ?></td>
                            <td><?= $class_completion ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top: 30px; border-top: 1px solid #ddd; padding-top: 20px; text-align: center; color: #666;">
            <p>Report generated by <?= SITE_NAME ?> on <?= date('F j, Y \a\t g:i A') ?></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Log export activity
logActivity('export_verification_report', "Exported verification report - Format: $format");
?>