<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user_id = require_login();

if (is_post()) {
    $month = (string) ($_POST['month'] ?? '');
    if (!valid_month($month)) {
        http_response_code(400);
        exit(isset($_POST['resetIncome']) ? 'error' : 'Invalid month.');
    }

    // Reset (AJAX): delete the income record for the month.
    if (($_POST['resetIncome'] ?? '') === 'true') {
        $stmt = $conn->prepare('DELETE FROM Income WHERE Uid = ? AND Month = ?');
        $stmt->bind_param('is', $user_id, $month);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();
        exit($deleted > 0 ? 'success' : 'not_found');
    }

    if (isset($_POST['addincome'])) {
        $income = filter_var($_POST['budget'] ?? '', FILTER_VALIDATE_FLOAT);
        if ($income === false || $income <= 0) {
            alert_redirect('Invalid income amount.');
        }
        // Past months are allowed: income history is used by Insights and the ML models.
        if ($month > date('Y-m', strtotime('+12 months'))) {
            alert_redirect('Income can only be recorded up to 12 months ahead.');
        }

        $stmt = $conn->prepare('SELECT 1 FROM Income WHERE Uid = ? AND Month = ?');
        $stmt->bind_param('is', $user_id, $month);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($exists) {
            alert_redirect('Income for this month already exists! Reset it before adding a new entry.', url('addincome.php'));
        }

        $stmt = $conn->prepare('INSERT INTO Income (Uid, Month, Income) VALUES (?, ?, ?)');
        $stmt->bind_param('isd', $user_id, $month, $income);
        $stmt->execute();
        $stmt->close();
        alert_redirect('Income added successfully!', url('addincome.php'));
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Income</title>
    <link rel="stylesheet" href="<?= e(asset('css/addincome.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
    <script src="<?= e(asset('vendor/jquery/jquery.min.js')) ?>"></script>
</head>
<body>
    <!-- Header Section -->
    <header>
        <img src="<?= e(asset('img/logo.png')) ?>" alt="Logo" class="logo" onclick="location.href='<?= e(url('landing.html')) ?>'">
    </header>

    <?php include __DIR__ . '/includes/sidebar_user.php'; ?>

    <div class="main-content">
        <div class="form-container">
            <h1>Add Income:</h1>
            <form id="IncomeForm" method="POST">
                <?= csrf_field() ?>
                <label for="month">Select Month:</label>
                <input type="month" id="month" name="month" required>
                <br>
                <label for="budget">Enter Income for the month:</label>
                <input type="number" id="budget" name="budget" step="0.01" required>
                
                <button type="submit" name="addincome" id="add-income-Btn" class="addincome-btn">Add Income</button>
                <button type="button" id="resetBtn" class="reset-income-btn" onclick="resetIncome()">Reset Income</button>
            </form>
        </div>
    </div>

    <script>

    function resetIncome() {
        var selectedMonth = document.getElementById("month").value;
        if (selectedMonth === "") {
            alert("Please select a month first.");
            return;
        }

        if (confirm("Are you sure you want to reset the Income for the selected month?")) {
            $.ajax({
                type: "POST",
                url: "addincome.php",
                data: { resetIncome: "true", month: selectedMonth, csrf_token: document.querySelector('input[name="csrf_token"]').value },
                success: function(response) {
                    if (response.trim() === "success") {
                        alert("Income reset successfully!");
                        location.reload();
                    } else {
                        alert("No income was recorded for the selected month.");
                    }
                },
                error: function() {
                    alert("Error resetting income. Please reload the page and try again.");
                }
            });
        }
    }
    </script>
   
</body>
</html>
