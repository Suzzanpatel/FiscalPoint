<?php
/**
 * Expense report export: PDF download or PDF by email, for one month (or all time).
 * Reached from the "All Expenses" report page.
 */
require_once __DIR__ . '/includes/bootstrap.php';

use Mpdf\Mpdf;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

$uid = require_login();
$userEmail = (string) ($_SESSION['email'] ?? '');

if (is_post()) {
    $month = valid_month($_POST['month'] ?? null) ? $_POST['month'] : null;

    if (isset($_POST['export_pdf'])) {
        $pdf = build_report_pdf($conn, $uid, $month);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="FiscalPoint_Report' . ($month ? "_$month" : '') . '.pdf"');
        echo $pdf;
        exit;
    }

    if (isset($_POST['email_report'])) {
        $sent = email_report(build_report_pdf($conn, $uid, $month), $userEmail);
        alert_redirect($sent ? 'Report emailed to ' . $userEmail . '.' : 'The report could not be emailed. Please try again later.', url('export_report.php'));
    }
}

/** Render the expense + budget report as a PDF string. */
function build_report_pdf(mysqli $conn, int $uid, ?string $month): string
{
    $where = 'Uid = ?';
    $types = 'i';
    $params = [$uid];
    if ($month !== null) {
        $where .= " AND DATE_FORMAT(date, '%Y-%m') = ?";
        $types .= 's';
        $params[] = $month;
    }

    $stmt = $conn->prepare("SELECT date, category, description, amount, Payment_Method FROM Expense WHERE $where ORDER BY date");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $title = 'FiscalPoint Expense Report' . ($month ? ' - ' . date('F Y', strtotime("$month-01")) : '');
    $html = '<h2>' . e($title) . '</h2>';
    $html .= '<table border="1" cellpadding="5" style="border-collapse:collapse; width:100%">'
        . '<tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Payment Mode</th></tr>';
    $total = 0.0;
    while ($row = $result->fetch_assoc()) {
        $total += (float) $row['amount'];
        $html .= '<tr><td>' . e(date('d-m-Y', strtotime($row['date']))) . '</td><td>' . e($row['category'])
            . '</td><td>' . e($row['description']) . '</td><td style="text-align:right">' . e(number_format((float) $row['amount'], 2))
            . '</td><td>' . e($row['Payment_Method']) . '</td></tr>';
    }
    $html .= '<tr><td colspan="3"><strong>Total</strong></td><td style="text-align:right"><strong>'
        . e(number_format($total, 2)) . '</strong></td><td></td></tr></table>';
    $stmt->close();

    if ($month !== null) {
        $stmt = $conn->prepare('SELECT Amount FROM Budget WHERE Uid = ? AND Month = ?');
        $stmt->bind_param('is', $uid, $month);
        $stmt->execute();
        $budget = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($budget) {
            $remaining = (float) $budget['Amount'] - $total;
            $html .= '<h3>Budget</h3><p>Budget: Rs. ' . e(number_format((float) $budget['Amount'], 2))
                . '<br>Remaining: Rs. ' . e(number_format($remaining, 2)) . '</p>';
        }
    }

    $mpdf = new Mpdf(['tempDir' => sys_get_temp_dir() . '/mpdf']);
    $mpdf->WriteHTML($html);
    return $mpdf->Output('', 'S');
}

/** Email the PDF to the logged-in user via the SMTP account configured in .env. */
function email_report(string $pdf, string $to): bool
{
    $username = env('EMAIL_USERNAME');
    $password = env('EMAIL_PASSWORD');
    if (!$username || !$password || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('FiscalPoint: email report not sent (SMTP not configured or invalid recipient).');
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = env('SMTP_HOST', 'smtp.gmail.com');
        $mail->Port = (int) env('SMTP_PORT', '587');
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->setFrom($username, 'FiscalPoint Reports');
        $mail->addAddress($to);
        $mail->Subject = 'Your FiscalPoint expense report';
        $mail->Body = 'Please find your expense report attached.';
        $mail->addStringAttachment($pdf, 'FiscalPoint_Report.pdf', 'base64', 'application/pdf');
        return $mail->send();
    } catch (MailException $e) {
        error_log('FiscalPoint mail error: ' . $mail->ErrorInfo);
        return false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Export Report - FiscalPoint</title>
    <link rel="stylesheet" href="<?= e(asset('css/tabularreport.css')) ?>">
</head>
<body style="padding: 2rem;">
    <h2>Export Your Report</h2>
    <form method="post">
        <?= csrf_field() ?>
        <label for="month">Month (leave empty for all time):</label>
        <input type="month" id="month" name="month" value="<?= e(date('Y-m')) ?>">
        <button type="submit" name="export_pdf">Download PDF</button>
        <button type="submit" name="email_report">Email PDF to <?= e($userEmail) ?></button>
    </form>
    <p><a href="<?= e(url('tabularreport.php')) ?>">Back to All Expenses</a></p>
</body>
</html>
