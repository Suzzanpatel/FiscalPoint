<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user_id = require_login();
$currentMonth = date('Y-m');

// Budget for this month
$sqlBudget = "SELECT Amount FROM Budget WHERE Uid = ? AND Month = ?";
$stmt = $conn->prepare($sqlBudget);
$stmt->bind_param("is", $user_id, $currentMonth);
$stmt->execute();
$resultBudget = $stmt->get_result();
$budgetAmount = ($resultBudget->num_rows > 0) ? $resultBudget->fetch_assoc()['Amount'] : 0;

// Income for this month
$sqlIncome = "SELECT SUM(Income) AS Income FROM Income WHERE Uid = ? AND Month = ?";
$stmtIncome = $conn->prepare($sqlIncome);
$stmtIncome->bind_param("is", $user_id, $currentMonth);
$stmtIncome->execute();
$resultIncome = $stmtIncome->get_result();
$totalIncome = ($resultIncome->num_rows > 0) ? $resultIncome->fetch_assoc()['Income'] : 0;

// Expenses by category this month
$sqlExpenses = "SELECT category, SUM(amount) AS totalExpense 
                FROM Expense WHERE Uid = ? AND DATE_FORMAT(Date, '%Y-%m') = ? GROUP BY category";
$stmt = $conn->prepare($sqlExpenses);
$stmt->bind_param("is", $user_id, $currentMonth);
$stmt->execute();
$resultExpenses = $stmt->get_result();

$totalExpense = 0;
$expensesByCategory = [];
$categoryLabels = [];
$categoryValues = [];

while ($row = $resultExpenses->fetch_assoc()) {
    $totalExpense += $row['totalExpense'];
    $expensesByCategory[$row['category']] = $row['totalExpense'];
    $categoryLabels[] = $row['category'];
    $categoryValues[] = $row['totalExpense'];
}

// Spending percentage (only meaningful when a budget exists)
$hasBudget = $budgetAmount > 0;
$percentageSpent = $hasBudget ? ($totalExpense / $budgetAmount) * 100 : 0;

// Spending status
if (!$hasBudget) {
    $status = "No Budget Set";
    $statusMessage = "Set a budget for this month to track how well you are doing against it.";
    $statusColor = "blue";
} elseif ($totalExpense < ($budgetAmount * 0.7)) {
    $status = "Excellent Budget Management";
    $statusMessage = "Great job! You have spent wisely and saved a good amount.";
    $statusColor = "green";
} elseif ($totalExpense <= $budgetAmount) {
    $status = "On-Track Spending";
    $statusMessage = "You are managing your budget well.";
    $statusColor = "blue";
} else {
    $status = "Over Budget";
    $statusMessage = "You have exceeded your budget.";
    $statusColor = "red";
}

// Top category
$topCategory = !empty($expensesByCategory) ? array_keys($expensesByCategory, max($expensesByCategory))[0] : null;
$topCategoryAmount = $topCategory !== null ? $expensesByCategory[$topCategory] : 0;

// Breakdown insights (escaped: category names can be user-defined)
$insights = "";
foreach ($expensesByCategory as $category => $amount) {
    $categoryPercentage = $totalExpense > 0 ? ($amount / $totalExpense) * 100 : 0;
    $insights .= "<li><strong>" . e($category) . ":</strong> " . number_format($categoryPercentage, 2) . "%</li>";
}

// Recommendations keyed by the category names actually used by the app
$tips = [
    'Food' => '🍽️ Try planning meals and cooking at home more often.',
    'Entertainment' => '🎬 Review subscriptions and cancel the ones you rarely use.',
    'Transportation' => '🚗 Try carpooling or public transport.',
    'Personal Care' => '🧴 Compare prices and buy essentials in bulk.',
    'Housing' => '🏠 Check utility usage and renegotiate recurring bills.',
    'Gifts & Donations' => '🎁 Set a fixed gifting budget for festivals and occasions.',
    'Miscellaneous' => '🧾 Miscellaneous spending is high: try to categorise it to see where it goes.',
];
$recommendation = "";
if ($topCategory !== null) {
    $recommendation .= "<p><strong>Top Spending Category:</strong> " . e($topCategory) . " (" . money($topCategoryAmount) . ")</p>";
    if (isset($tips[$topCategory])) {
        $recommendation .= "<p>" . e($tips[$topCategory]) . "</p>";
    }
}

// Additional Insights
$monthlyExpense = [];
$scatterData = [];

// Expense summary
$sqlMonthlyExpense = "SELECT DATE_FORMAT(Date, '%Y-%m') AS period, SUM(amount) AS total FROM Expense WHERE Uid = ? GROUP BY period";
$stmt = $conn->prepare($sqlMonthlyExpense);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$resAvg = $stmt->get_result();

$monthCount = $resAvg->num_rows;
$totalForAvg = 0;

while ($row = $resAvg->fetch_assoc()) {
    $monthlyExpense[$row['period']] = $row['total'];
    $totalForAvg += $row['total'];
}
$avgExpense = ($monthCount > 0) ? $totalForAvg / $monthCount : 0;

// Saving = Income - Expense per month
$sqlIncomeMonths = "SELECT Month AS period, SUM(Income) AS total FROM Income WHERE Uid = ? GROUP BY Month";
$stmt = $conn->prepare($sqlIncomeMonths);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$resIncomes = $stmt->get_result();

$savings = [];
$highestIncome = null;
$highestIncomeMonth = 'N/A';

while ($row = $resIncomes->fetch_assoc()) {
    $period = $row['period'];
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
        continue;
    }

    $income = $row['total'];
    $expense = $monthlyExpense[$period] ?? 0;
    $savings[$period] = $income - $expense;
    $scatterData[] = ["x" => $expense, "y" => $income];

    if ($highestIncome === null || $income > $highestIncome) {
        $highestIncome = $income;
        $highestIncomeMonth = date('F Y', strtotime($period . '-01'));
    }
}

arsort($savings);
$bestSavingMonth = !empty($savings)
    ? date('F Y', strtotime(array_key_first($savings) . '-01'))
    : 'N/A';

$conn->close();
?>

<!-- HTML + CHARTS -->
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Insights</title>
    <link rel="stylesheet" href="<?= e(asset('css/insights.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
    <script src="<?= e(asset('vendor/chartjs/chart.umd.min.js')) ?>"></script>
</head>
<body>
<header>
    <img src="<?= e(asset('img/logo.png')) ?>" alt="Logo" class="logo" onclick="location.href='<?= e(url('landing.html')) ?>'">
</header>

<?php include __DIR__ . '/includes/sidebar_user.php'; ?>

<div class="report-container">
    <h2>📈 Monthly Financial Insights</h2>

    <div class="status <?= e($statusColor) ?>"><?= e($status) ?></div>

    <p><strong>Total Budget:</strong> ₹<?php echo number_format($budgetAmount, 2); ?></p>
    <p><strong>Total Spent:</strong> ₹<?php echo number_format($totalExpense, 2); ?> <?php if ($hasBudget): ?>(<?php echo number_format($percentageSpent, 2); ?>%)<?php endif; ?></p>
    <p><strong>Total Income (This Month):</strong> ₹<?php echo number_format($totalIncome, 2); ?></p>

    <h3>Spending Breakdown</h3>
    <ul><?php echo $insights; ?></ul>

    <div class="recommendation">
        <h4>Recommendations</h4>
        <p><?= e($statusMessage) ?></p>
        <?php echo $recommendation; ?>
    </div>

    <div class="extra-insights">
        <h4>📊 Additional Insights</h4>
        <p><strong>Average Monthly Expense:</strong> ₹<?php echo number_format($avgExpense, 2); ?></p>
        <p><strong>Best Saving Month:</strong> <?= e($bestSavingMonth) ?></p>
        <p><strong>Highest Income Month:</strong> <?= e($highestIncomeMonth) ?></p>
    </div>

    <div class="chart-section">
        <h4>📊 Bar Chart - Expenses by Category</h4>
        <canvas id="barChart" height="120"></canvas>
    </div>

</div>

<script>
    new Chart(document.getElementById('barChart'), {
        type: 'bar',
        data: {
            labels: <?= js($categoryLabels) ?>,
            datasets: [{
                label: 'Expenses (₹)',
                data: <?= js(array_map('floatval', $categoryValues)) ?>,
                backgroundColor: '#007bff'
            }]
        },
        options: {
            scales: {
                y: { beginAtZero: true }
            }
        }
    });

    
</script>

</body>
</html>
