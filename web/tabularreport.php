<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/expense_filters.php';
$user_id = require_login();

$isPost = is_post();

// Handle expense deletion (only the owner's rows can be deleted)
if ($isPost && isset($_POST['delete_id'])) {
    $delete_id = (int) $_POST['delete_id'];
    $stmt_delete = $conn->prepare('DELETE FROM Expense WHERE Uid = ? AND Eid = ?');
    $stmt_delete->bind_param('ii', $user_id, $delete_id);
    $stmt_delete->execute();
    $deleted = $stmt_delete->affected_rows > 0;
    $stmt_delete->close();
    alert_redirect($deleted ? 'Expense deleted successfully.' : 'Expense not found.', url('tabularreport.php'));
}

$filters = expense_filters($_POST);
$selected_month = $filters['month'];
[$sql_expense, $types, $params] = expense_filter_query($user_id, $filters);

$stmt_expense = $conn->prepare($sql_expense);
$stmt_expense->bind_param($types, ...$params);
$stmt_expense->execute();
$result_expense = $stmt_expense->get_result();
$conn->close();
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tabular Report</title>
    <link rel="stylesheet" href="<?= e(asset('css/tabularreport.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
</head>
<body>
    <header>
        <img src="<?= e(asset('img/logo.png')) ?>" alt="Logo" class="logo" onclick="location.href='<?= e(url('landing.html')) ?>'">
    </header>

    <?php include __DIR__ . '/includes/sidebar_user.php'; ?>

    <div>
        <h2>All Expenses</h2>
        <form method="POST" action="tabularreport.php">
            <?= csrf_field() ?>
            <input type="hidden" name="submit" value="1">
            <label for="month">Select Month:</label>
            <input type="month" id="month" name="month" value="<?= e($selected_month) ?>">

            <select name="date_order">
                <option value="">Date</option>
                <option value="ASC" <?php if (($_POST['date_order'] ?? '') === 'ASC') echo 'selected'; ?>>Oldest First</option>
                <option value="DESC" <?php if (($_POST['date_order'] ?? '') === 'DESC') echo 'selected'; ?>>Newest First</option>
            </select>

            <select name="amount_order">
                <option value="">Amount</option>
                <option value="ASC" <?php if (($_POST['amount_order'] ?? '') === 'ASC') echo 'selected'; ?>>Low to High</option>
                <option value="DESC" <?php if (($_POST['amount_order'] ?? '') === 'DESC') echo 'selected'; ?>>High to Low</option>
            </select>

            <select name="payment_type">
                <option value="">Payment Type</option>
                <option value="Cash" <?php if (($_POST['payment_type'] ?? '') === 'Cash') echo 'selected'; ?>>Cash</option>
                <option value="UPI Payment" <?php if (($_POST['payment_type'] ?? '') === 'UPI Payment') echo 'selected'; ?>>UPI</option>
                <option value="Credit / Debit card" <?php if (($_POST['payment_type'] ?? '') === 'Credit / Debit card') echo 'selected'; ?>>Credit/Debit Card</option>
                <option value="Other" <?php if (($_POST['payment_type'] ?? '') === 'Other') echo 'selected'; ?>>Other</option>
            </select>

            <button type="submit">Filter</button>
        </form>

        <!-- Export / navigation actions -->
        <style>
            .report-actions { display: flex; gap: 10px; justify-content: center; align-items: center; margin: 10px auto; }
            .report-actions form { margin: 0; padding: 0; background: none; box-shadow: none; }
        </style>
        <div class="report-actions">
            <form method="POST" action="download_excel.php" target="_blank">
                <?= csrf_field() ?>
                <input type="hidden" name="month" value="<?= e($filters['month']) ?>">
                <input type="hidden" name="date_order" value="<?= e($filters['date_order']) ?>">
                <input type="hidden" name="amount_order" value="<?= e($filters['amount_order']) ?>">
                <input type="hidden" name="payment_type" value="<?= e($filters['payment_type']) ?>">
                <button type="submit" style="background-color:#4CAF50; color:white; border:none; padding:8px 16px; border-radius:5px; cursor:pointer;">Download Excel</button>
            </form>
            <form method="POST" action="export_report.php" target="_blank">
                <?= csrf_field() ?>
                <input type="hidden" name="month" value="<?= e($filters['month']) ?>">
                <button type="submit" name="export_pdf" style="background-color:#4CAF50; color:white; border:none; padding:8px 16px; border-radius:5px; cursor:pointer;">Download PDF</button>
            </form>
            <a href="calendarview.php" style="background-color:#4CAF50; color:white; padding:8px 16px; border-radius:5px; text-decoration:none;">Calendar wise expense</a>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Sr No.</th>
                        <th>Category</th>
                        <th>Item</th>
                        <th>Cost</th>
                        <th>Date</th>
                        <th>Payment Method</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total_cost = 0;
                    if ($result_expense->num_rows > 0) {
                        $sr_no = 1;
                        while ($row = $result_expense->fetch_assoc()) {
                            echo "<tr>";
                            echo "<td>" . $sr_no . "</td>";
                            echo "<td>" . htmlspecialchars($row["category"]) . "</td>";
                            echo "<td>" . htmlspecialchars($row["Item"]) . "</td>";
                            echo "<td>₹" . number_format($row["Cost"], 2) . "</td>";
                            echo "<td>" . date("d-m-Y", strtotime($row["Date"])) . "</td>";
                            echo "<td>" . htmlspecialchars($row["Payment_Method"]) . "</td>";
                            echo "<td>
                                    <form method='POST' onsubmit='return confirm(\"Are you sure you want to delete this expense?\")' style='display:inline;'>
                                        " . csrf_field() . "
                                        <input type='hidden' name='delete_id' value='" . (int) $row["Eid"] . "'>
                                        <button type='submit' style='background-color:red; color:white; padding:5px 10px; border:none; border-radius:5px; cursor:pointer;'>Delete</button>
                                    </form>
                                  </td>";
                            echo "</tr>";
                            $total_cost += floatval($row["Cost"]);
                            $sr_no++;
                        }
                    } else {
                        echo "<tr><td colspan='7'>No expenses found.</td></tr>";
                    }
                    echo "<tr style='background-color:#86a69c; font-weight:bold;'>";
                    echo "<td><strong>Total:</strong></td>";
                    echo "<td colspan='2'></td>";
                    echo "<td><strong>₹" . number_format($total_cost, 2) . "</strong></td>";
                    echo "<td colspan='3'></td>";
                    echo "</tr>";
                    ?>
                </tbody>
            </table>
        </div>
    </div>
   
</body>
</html>
