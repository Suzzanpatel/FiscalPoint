<?php
/** Excel export of the "All Expenses" report, honouring the same filters as the page. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/expense_filters.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$user_id = require_login();
if (!is_post()) {
    redirect(url('tabularreport.php'));
}

$filters = expense_filters($_POST);
[$sql, $types, $params] = expense_filter_query($user_id, $filters);
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Expenses ' . $filters['month']);
$sheet->fromArray(['Category', 'Item', 'Cost', 'Date', 'Payment Method'], null, 'A1');
$sheet->getStyle('A1:E1')->getFont()->setBold(true);

$rowNum = 2;
while ($row = $result->fetch_assoc()) {
    $sheet->setCellValue("A{$rowNum}", $row['category']);
    $sheet->setCellValue("B{$rowNum}", $row['Item']);
    $sheet->setCellValue("C{$rowNum}", (float) $row['Cost']);        // numeric, so totals work in Excel
    $sheet->setCellValue("D{$rowNum}", date('d-m-Y', strtotime($row['Date'])));
    $sheet->setCellValue("E{$rowNum}", $row['Payment_Method']);
    $rowNum++;
}
if ($rowNum > 2) {
    $sheet->setCellValue("B{$rowNum}", 'Total');
    $sheet->setCellValue("C{$rowNum}", '=SUM(C2:C' . ($rowNum - 1) . ')');
    $sheet->getStyle("B{$rowNum}:C{$rowNum}")->getFont()->setBold(true);
}
$sheet->getStyle('C2:C' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');
foreach (range('A', 'E') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="expenses_' . $filters['month'] . '.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($spreadsheet))->save('php://output');
exit;
