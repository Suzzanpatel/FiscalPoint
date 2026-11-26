<?php
require_once __DIR__ . '/includes/bootstrap.php';
$uid = require_login();

/** Budget, spending and remaining amount for a given YYYY-MM month. */
function month_budget_status(mysqli $conn, int $uid, string $month): array
{
    $stmt = $conn->prepare('SELECT Amount FROM Budget WHERE Uid = ? AND Month = ?');
    $stmt->bind_param('is', $uid, $month);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $budget = $row ? (float) $row['Amount'] : null;

    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM Expense WHERE Uid = ? AND DATE_FORMAT(date, '%Y-%m') = ?");
    $stmt->bind_param('is', $uid, $month);
    $stmt->execute();
    $spent = (float) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    return ['budget' => $budget, 'spent' => $spent, 'remaining' => $budget === null ? null : $budget - $spent];
}

$currentMonth = date('Y-m');
$currentMonthLabel = date('F Y');
$status = month_budget_status($conn, $uid, $currentMonth);
$monthly_budget = $status['budget'];
$total_expenses = $status['spent'];
$remaining_budget = $status['remaining'];

if (is_post()) {
    $category = trim((string) ($_POST['category'] ?? ''));
    if ($category === 'Other') {
        $category = trim(strip_tags((string) ($_POST['other_category'] ?? '')));
    }
    $paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
    if ($paymentMethod === 'Other') {
        $paymentMethod = trim(strip_tags((string) ($_POST['other_payment'] ?? '')));
    }
    $description = trim(strip_tags((string) ($_POST['item'] ?? '')));
    $amount = filter_var($_POST['cost'] ?? '', FILTER_VALIDATE_FLOAT);
    $date = (string) ($_POST['date'] ?? '');
    $parsedDate = DateTime::createFromFormat('!Y-m-d', $date);

    if ($amount === false || $amount <= 0 || $amount > 99999999) {
        alert_redirect('Invalid amount. Enter a positive number.');
    }
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
        alert_redirect('Invalid date. Please enter a valid date.');
    }
    if ($parsedDate > new DateTime('today')) {
        alert_redirect('You cannot add an expense for a future date.');
    }
    if ($category === '' || mb_strlen($category) > 60) {
        alert_redirect('Please choose a category (custom categories can be up to 60 characters).');
    }
    if ($paymentMethod === '' || mb_strlen($paymentMethod) > 60) {
        alert_redirect('Please choose a payment method.');
    }
    if ($description === '' || mb_strlen($description) > 255) {
        alert_redirect('Please enter a short description (up to 255 characters).');
    }

    $stmt = $conn->prepare('INSERT INTO Expense (Uid, category, amount, date, description, Payment_Method) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('isdsss', $uid, $category, $amount, $date, $description, $paymentMethod);
    $stmt->execute();
    $stmt->close();

    // The expense is always recorded (blocking it would hide real spending from reports
    // and from the ML models). Over-budget spending is reported as a warning instead.
    $expenseMonth = $parsedDate->format('Y-m');
    $after = month_budget_status($conn, $uid, $expenseMonth);
    $message = 'Expense added successfully!';
    if ($after['budget'] !== null && $after['remaining'] < 0) {
        $message .= ' Warning: you are now ' . money(-$after['remaining']) . ' over your budget for '
            . $parsedDate->format('F Y') . '.';
    }
    alert_redirect($message, url('addexpense.php'));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Expense</title>
    <link rel="stylesheet" href="<?= e(asset('css/addexpense.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
    <style>
        .category-suggestion { margin: 4px 0 10px; font-size: 14px; color: #fff; }
        .suggestion-chip { margin: 2px 4px; padding: 3px 10px; border-radius: 12px; border: 1px solid #b9b3d6; background: #6B6487; color: #fff; cursor: pointer; }
        .suggestion-chip:hover { background: #847ca8; }
    </style>
</head>
<body>
    <!-- Header Section -->
    <header>
        <!-- Logo with click functionality to redirect to landing page -->
        <img src="<?= e(asset('img/logo.png')) ?>" alt="Logo" class="logo" onclick="location.href='<?= e(url('landing.html')) ?>'"> 
    </header>

    <?php include __DIR__ . '/includes/sidebar_user.php'; ?>
     <!-- MAIN CONTENT -->
    <div class="main-content">
   

        <div class="form-container">
            <h1>Add Expense:</h1>
            <div>
                <h3 class="budget-text">Your Budget for <?php echo $currentMonthLabel; ?>:</h3>
                <div class="Budget">
                    <p><?= $monthly_budget === null ? 'No budget set' : e(number_format($monthly_budget, 2, '.', '')) ?></p>
                </div>
            </div>
            <br>
            <form action="addexpense.php" method="POST" id="expenseForm">
                <?= csrf_field() ?>
                <label for="date">Date:</label>
                <input type="date" id="date" name="date" required>
                
                <label for="category">Category:</label>
                <select id="category" name="category" class="category" required>
                    <option value="Housing">Housing</option>
                    <option value="Food">Food</option>
                    <option value="Transportation">Transportation</option>
                    <option value="Healthcare">Healthcare</option>
                    <option value="Education">Education</option>
                    <option value="Entertainment">Entertainment</option>
                    <option value="Personal Care">Personal Care</option>
                    <option value="Debt Repayment">Debt Repayment</option>
                    <option value="Savings & Investments">Savings & Investments</option>
                    <option value="Insurance">Insurance</option>
                    <option value="Childcare/Dependents">Childcare/Dependents</option>
                    <option value="Gifts & Donations">Gifts & Donations</option>
                    <option value="Miscellaneous">Miscellaneous</option>
                    <option value="Other">Other</option>
                </select>
                
                <div id="otherCategoryDiv" style="display: none;">
                    <label for="other_category">Specify Category:</label>
                    <input type="text" id="other_category" name="other_category">
                </div>
                
                <label for="item">Description:</label>
                <input type="text" id="item" name="item" required maxlength="255" autocomplete="off">
                <div id="category-suggestion" class="category-suggestion" data-endpoint="<?= e(url('api/ml_categorize.php')) ?>" style="display:none;"></div>
                
                <label for="cost">Amount Spent:</label>
                <input type="number" id="cost" name="cost" step="0.01" required>

                <!-- Display Total Expenses (Hidden) -->
                <input type="hidden" id="total_expenses" value="<?= e($total_expenses) ?>">
                <input type="hidden" id="monthly_budget" value="<?= e($monthly_budget ?? '') ?>">

                <label for="remaining_budget">Remaining Budget for <?php echo $currentMonthLabel; ?>:</label>
                <input type="text" id="remaining_budget" name="remaining_budget" value="<?= $remaining_budget === null ? 'No budget set' : e(number_format($remaining_budget, 2, '.', '')) ?>" readonly>
                <p id="budget-warning" style="color:#ffb347; display:none;">This expense will take you over your budget for this month.</p>
                
                <label for="payment_method">Payment Method:</label>
                <select id="payment_method" name="payment_method" required class="payment-method">
                    <option value="Cash">Cash</option>
                    <option value="Credit / Debit card">Credit/Debit card</option>
                    <option value="UPI Payment">UPI Payment</option>

                    <option value="Other">Other</option>
                </select>
                
                <div id="otherPaymentDiv" style="display: none;">
                    <label for="other_payment">Specify Payment Method:</label>
                    <input type="text" id="other_payment" name="other_payment">
                </div>
                
                <button type="submit" class="add-expense-btn">Add Expense</button>
            </form>
        </div>
    </div>
    
     <!-- Linking External JavaScript -->
     <script src="<?= e(asset('js/addexpense.js')) ?>"></script>
</body>
</html>
