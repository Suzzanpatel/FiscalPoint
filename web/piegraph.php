<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user_id = require_login();
$uid = $user_id;
// Fetch User Details
$sql_user = "SELECT Uname FROM User WHERE Uid = ?";
$stmt_user = $conn->prepare($sql_user);
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$result_user = $stmt_user->get_result();
$user_name = ($result_user->num_rows > 0) ? $result_user->fetch_assoc()["Uname"] : "Unknown User";
$stmt_user->close();

// Get selected month
$selected_month = valid_month($_POST["month"] ?? null) ? $_POST["month"] : date("Y-m");

// Fetch Expenses (Grouping by Category for the selected month)
$sql_expense = "SELECT category, SUM(amount) AS total_cost 
                FROM Expense 
                WHERE Uid = ? AND DATE_FORMAT(date, '%Y-%m') = ?
                GROUP BY category";

$stmt_expense = $conn->prepare($sql_expense);
$stmt_expense->bind_param("is", $user_id, $selected_month);
$stmt_expense->execute();
$result_expense = $stmt_expense->get_result();

$categories = [];
$costs = [];

// Fetch data for Pie Chart
while ($row = $result_expense->fetch_assoc()) {
    $categories[] = $row["category"];
    $costs[] = $row["total_cost"];
}

$stmt_expense->close();
$conn->close();

// Check if there are expenses
$hasExpenses = count($categories) > 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Pie Chart</title>
    <link rel="stylesheet" href="<?= e(asset('css/piegraph.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
    <script src="<?= e(asset('vendor/chartjs/chart.umd.min.js')) ?>"></script> <!-- Include Chart.js -->
</head>
<body>
    <header>
        <img src="<?= e(asset('img/logo.png')) ?>" alt="Logo" class="logo" onclick="location.href='<?= e(url('landing.html')) ?>'">
    </header>
    
    <?php include __DIR__ . '/includes/sidebar_user.php'; ?>

    <!-- MAIN CONTENT -->
    <div class="chart-container" style="width: 50%; margin: auto; height: 70%;">
        <h2>Category-Wise Expense Distribution</h2>

        <!-- Month Selection Form -->
        <form method="POST" action="" class="filter-form">
            <label for="month">Select Month:</label>
            <input type="month" id="month" name="month" value="<?= e($selected_month) ?>">
            <button type="submit">Filter</button>
        </form>

        <?php if ($hasExpenses): ?>
            <canvas id="expensePieGraph"></canvas>
        <?php else: ?>
            <p style="text-align: center; font-size: 18px; color: red;">No expenses were made in the selected month.</p>
        <?php endif; ?>
    </div>
        
    <?php if ($hasExpenses): ?>
        <script src="<?= e(asset('vendor/chartjs/chart.umd.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/chartjs-plugin-datalabels/chartjs-plugin-datalabels.min.js')) ?>"></script> <!-- Add this line -->

<script>
    // Pie Chart Data
    const ctx = document.getElementById('expensePieGraph').getContext('2d');
    Chart.register(ChartDataLabels); // Register the plugin

    const expenseChart = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: <?= js($categories) ?>,
            datasets: [{
                label: 'Expenses',
                data: <?= js($costs) ?>,
                backgroundColor: [
                    "#fd7f6f", "#7eb0d5", "#b2e061",
                    "#bd7ebe", "#ffb55a", "#ffee65", 
                    "#beb9db", "#fdcce5", "#8bd3c7",
                    "#ff677d", "#56c1ff", "#a0e65d", "#d39cd3"
                ],
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'top',
                },
                datalabels: {
                    color: 'black', // Text color
                    anchor: 'end', // Positioning
                    align: 'start',
                    font: {
                        weight: 'bold',
                        size: 14
                    },
                    formatter: (value) => `₹${value}` // Format the values with currency
                }
            }
        }
    });
</script>

    <?php endif; ?>
    
</body>
</html>
