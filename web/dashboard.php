<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/ml_client.php';
$uid = require_login();

// ML summary (short timeout: the dashboard must stay fast when the ML service is down)
$mlForecast = ml_request('/v1/forecast', ['user_id' => $uid, 'horizon' => 1], 4);
$mlRisk = $mlForecast['status'] !== 0 ? ml_request('/v1/budget-risk', ['user_id' => $uid], 4) : null;
$mlMonth = $mlForecast['ok'] ? ($mlForecast['data']['months'][0] ?? null) : null;

// Fetch today's expense
$sql_today = "SELECT SUM(amount) AS total_today FROM Expense WHERE Uid = ? AND DATE(Date) = CURDATE()";
$stmt = $conn->prepare($sql_today);
$stmt->bind_param("i", $uid);
$stmt->execute();
$result_today = $stmt->get_result();
$row_today = $result_today->fetch_assoc();
$today_expense = isset($row_today['total_today']) ? $row_today['total_today'] : 0;

// Fetch yesterday's expense
$sql_yesterday = "SELECT SUM(amount) AS total_yesterday FROM Expense WHERE Uid = ? AND DATE(Date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
$stmt = $conn->prepare($sql_yesterday);
$stmt->bind_param("i", $uid);
$stmt->execute();
$result_yesterday = $stmt->get_result();
$row_yesterday = $result_yesterday->fetch_assoc();
$yesterday_expense = isset($row_yesterday['total_yesterday']) ? $row_yesterday['total_yesterday'] : 0;

// Fetch monthly expense
$sql_monthly = "SELECT SUM(amount) AS total_monthly FROM Expense WHERE Uid = ? AND MONTH(Date) = MONTH(CURDATE()) AND YEAR(Date) = YEAR(CURDATE())";
$stmt = $conn->prepare($sql_monthly);
$stmt->bind_param("i", $uid);
$stmt->execute();
$result_monthly = $stmt->get_result();
$row_monthly = $result_monthly->fetch_assoc();
$monthly_expense = isset($row_monthly['total_monthly']) ? $row_monthly['total_monthly'] : 0;

// Fetch yearly expense
$sql_yearly = "SELECT SUM(amount) AS total_yearly FROM Expense WHERE Uid = ? AND YEAR(Date) = YEAR(CURDATE())";
$stmt = $conn->prepare($sql_yearly);
$stmt->bind_param("i", $uid);
$stmt->execute();
$result_yearly = $stmt->get_result();
$row_yearly = $result_yearly->fetch_assoc();
$yearly_expense = isset($row_yearly['total_yearly']) ? $row_yearly['total_yearly'] : 0;

// Fetch user's budget for the current month
$currentMonth = date("Y-m");
$currentMonthLabel = date("F Y");
$sql_budget = "SELECT Amount FROM Budget WHERE Uid = ? AND Month = ?";
$stmt = $conn->prepare($sql_budget);
$stmt->bind_param("is", $uid, $currentMonth);
$stmt->execute();
$result_budget = $stmt->get_result();
$row_budget = $result_budget->fetch_assoc();
$monthly_budget = isset($row_budget['Amount']) ? $row_budget['Amount'] : "No budget set";

// Fetch user's Income for the current month
$sql_budget = "SELECT Income FROM Income WHERE Uid = ? AND Month = ?";
$stmt = $conn->prepare($sql_budget);
$stmt->bind_param("is", $uid, $currentMonth);
$stmt->execute();
$result_income = $stmt->get_result();
$row_income = $result_income->fetch_assoc();
$monthly_income = isset($row_income['Income']) ? $row_income['Income'] : "No Income set";

// Determine text color for monthly expense
$expense_color = 'white';
if ($monthly_budget !== "No budget set") {
    $expense_color = ((float) $monthly_expense > (float) $monthly_budget) ? 'red' : 'green';
}

// Query: Get expense totals per category
$sql = "SELECT Category, SUM(amount) AS total FROM Expense WHERE Uid = ? GROUP BY Category";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $uid);
$stmt->execute();
$result = $stmt->get_result();

$categories = [];
$amounts = [];

while ($row = $result->fetch_assoc()) {
    $categories[] = $row['Category'];
    $amounts[] = $row['total'];
}

$categories_json = js($categories);
$amounts_json = js(array_map('floatval', $amounts));

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Expense Dashboard</title>
    <link rel="stylesheet" href="<?= e(asset('css/dashboard.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
    <script src="<?= e(asset('vendor/chartjs/chart.umd.min.js')) ?>"></script>
  
       
</head>
<body>
<header>
    <img src="<?= e(asset('img/logo.png')) ?>" alt="Logo" class="logo" onclick="location.href='<?= e(url('landing.html')) ?>'">
</header>

<?php include __DIR__ . '/includes/sidebar_user.php'; ?>

<main class="dashboard">
    <div class="grid-container">
        <div class="box">
            <h3>Your Budget for <?php echo $currentMonthLabel; ?>:</h3>
            <div class="content-box">
                <p><?= is_numeric($monthly_budget) ? e(money($monthly_budget)) : e($monthly_budget) ?></p>
            </div>
        </div>
        <div class="box">
            <h3>Your Income for <?php echo $currentMonthLabel; ?>:</h3>
            <div class="content-box">
                <p><?= is_numeric($monthly_income) ? e(money($monthly_income)) : e($monthly_income) ?></p>
            </div>
        </div>
        <div class="box">
            <h3>Today's Expense:</h3>
            <div class="content-box">
                <p><?= is_numeric($today_expense) ? e(money($today_expense)) : e($today_expense) ?></p>
            </div>
        </div>
        <div class="box" onclick="location.href='calendarview.php'" style="cursor:pointer;" title="Open calendar view">
            <h3>Yesterday's Expense:</h3>
            <div class="content-box">
                <p><?= is_numeric($yesterday_expense) ? e(money($yesterday_expense)) : e($yesterday_expense) ?></p>
            </div>
        </div>
        <div class="box">
            <h3>Monthly Expense:</h3>
            <div class="content-box" style="color: <?= e($expense_color) ?>;">
                <p><?= is_numeric($monthly_expense) ? e(money($monthly_expense)) : e($monthly_expense) ?></p>
            </div>
        </div>
        <div class="box" onclick="location.href='predictions.php'" style="cursor:pointer;" title="Open ML predictions">
            <h3><i class="fas fa-brain"></i> ML forecast for <?= e($currentMonthLabel) ?>:</h3>
            <div class="content-box" style="flex-direction: column; text-align: center;">
                <?php if ($mlMonth): ?>
                    <p><?= e(money($mlMonth['total']['expected'])) ?></p>
                    <?php if ($mlRisk && $mlRisk['ok'] && isset($mlRisk['data']['probability_over_budget'])):
                        [$riskLabel, $riskColor] = risk_badge($mlRisk['data']['risk_level']); ?>
                        <small style="color: <?= e($riskColor) ?>;"><?= e($riskLabel) ?>: <?= (int) round($mlRisk['data']['probability_over_budget'] * 100) ?>% chance of going over budget</small>
                    <?php endif; ?>
                <?php elseif ($mlForecast['status'] === 0): ?>
                    <p style="font-size: 14px;">ML service offline</p>
                <?php else: ?>
                    <p style="font-size: 14px;">Not enough history yet</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="box">
            <h3>This Year Expense:</h3>
            <div class="content-box">
                <p><?= is_numeric($yearly_expense) ? e(money($yearly_expense)) : e($yearly_expense) ?></p>
            </div>
        </div>
    </div>

    <div class="chart-container">
        <canvas id="expenseChart"></canvas>
    </div>
</main>

<script>
   
    document.addEventListener("DOMContentLoaded", function () {
        let ctx = document.getElementById('expenseChart').getContext('2d');
        let categories = <?php echo $categories_json; ?>;
        let amounts = <?php echo $amounts_json; ?>;
        let colors = ["#fd7f6f", "#7eb0d5", "#b2e061", "#bd7ebe", "#ffb55a", "#ffee65", "#beb9db", "#fdcce5", "#8bd3c7", "#ff677d", "#56c1ff"];
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: categories,
                datasets: [{
                    data: amounts,
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#222',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            color: 'white',
                            font: { size: 14 }
                        }
                    }
                },
                cutout: '70%'
            }
        });
    });
</script>
</body>

</html>
