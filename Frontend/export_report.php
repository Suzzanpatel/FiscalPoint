<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';  // Ensure the correct path

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Mpdf\Mpdf;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

error_reporting(E_ALL);
require_once __DIR__ . '/db.php';

// **Fix: Check if session variables exist**
$uid = isset($_SESSION['Uid']) ? $_SESSION['Uid'] : 0;
$user_email = isset($_SESSION['UserEmail']) ? $_SESSION['UserEmail'] : '';

if ($uid == 0) {
    die("Error: User session not found. Please log in again.");
}

// **Fix: Ensure vendor dependencies exist**
if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    die("Error: Missing dependencies. Run `composer install` in the project root.");
}

$tempDir = sys_get_temp_dir();

// **Handle Export Requests**
if (isset($_POST['export_excel'])) {
    exportToExcel($conn, $uid);
} elseif (isset($_POST['export_pdf'])) {
    exportToPDF($conn, $uid);
} elseif (isset($_POST['email_report'])) {
    emailReport($conn, $uid, $user_email);
}

// **📌 Function: Export to Excel**
function exportToExcel($conn, $uid) {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // **Fix: Corrected column name (`Payment_Mode` instead of `PaymentType`)**
    $sql = "SELECT Date, Category, Amount, Payment_Method FROM Expense WHERE Uid = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $result = $stmt->get_result();

    // **Set column headers**
    $sheet->setCellValue('A1', 'Date');
    $sheet->setCellValue('B1', 'Category');
    $sheet->setCellValue('C1', 'Amount');
    $sheet->setCellValue('D1', 'Payment Mode');

    $row = 2;
    while ($data = $result->fetch_assoc()) {
        $sheet->setCellValue("A$row", $data['Date']);
        $sheet->setCellValue("B$row", $data['Category']);
        $sheet->setCellValue("C$row", $data['Amount']);
        $sheet->setCellValue("D$row", $data['Payment_Method']);
        $row++;
    }

    // **Export as Excel file**
    $writer = new Xlsx($spreadsheet);
    $fileName = "User_Report.xlsx";

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    $writer->save('php://output');
    exit;
}

// **📌 Function: Export to PDF**
function exportToPDF($conn, $uid, $returnFilePath = false) {
    global $tempDir;

    $mpdf = new Mpdf(['tempDir' => $tempDir]);
    $mpdf->WriteHTML("<h2>User Expense & Budget Report</h2>");

    // **Fetch user expenses**
    $html = '<h3>Expenses</h3><table border="1" cellpadding="5"><tr><th>Date</th><th>Category</th><th>Amount</th><th>Payment Mode</th></tr>';
    $sql = "SELECT Date, Category, Amount, Payment_Method FROM Expense WHERE Uid = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($data = $result->fetch_assoc()) {
        $html .= "<tr><td>{$data['Date']}</td><td>{$data['Category']}</td><td>{$data['Amount']}</td><td>{$data['Payment_Method']}</td></tr>";
    }
    $html .= '</table>';

    $mpdf->WriteHTML($html);
    
    $tempFile = tempnam($tempDir, 'fiscalpoint_report_');
    if ($tempFile === false) {
        throw new \RuntimeException('Unable to create temporary report file.');
    }
    @unlink($tempFile);
    $pdfFilePath = $tempFile . '.pdf';
    $mpdf->Output($pdfFilePath, "F");

    if ($returnFilePath) {
        return $pdfFilePath;
    } else {
        header("Content-type: application/pdf");
        header("Content-Disposition: attachment; filename=User_Report.pdf");
        readfile($pdfFilePath);
        @unlink($pdfFilePath);
        exit;
    }
}

// **📌 Function: Send Report via Email**
function emailReport($conn, $uid, $user_email) {
    $pdfPath = exportToPDF($conn, $uid, true);  // Generate PDF first

    $mail = new PHPMailer(true);
    try {
        // **SMTP Configuration**
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';  // Change as needed
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['EMAIL_USERNAME'] ?? '';
        $mail->Password = $_ENV['EMAIL_PASSWORD'] ?? '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // **Email Details**
        $mail->setFrom($mail->Username, 'FiscalPoint Reports');
        $mail->addAddress($user_email);
        $mail->Subject = 'Your Expense Report';
        $mail->Body = 'Please find your expense report attached.';
        $mail->addAttachment($pdfPath);

        // **Send Email**
        if ($mail->send()) {
            echo "Email sent successfully!";
        } else {
            echo "Error sending email.";
        }
        @unlink($pdfPath);
    } catch (Exception $e) {
        echo "Email Error: " . $mail->ErrorInfo;
    }
}
?>

<!DOCTYPE html>
<html>
<body>
    <h2>Export Your Report</h2>
    <form method="post">
        <button type="submit" name="export_excel">Export as Excel</button>
        <button type="submit" name="export_pdf">Export as PDF</button>
        <button type="submit" name="email_report">Email Report</button>
    </form>
</body>
</html>
