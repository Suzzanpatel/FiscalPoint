<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user_id = require_login();

if (is_post()) {
    $month = (string) ($_POST['month'] ?? '');
    if (!valid_month($month)) {
        http_response_code(400);
        exit(isset($_POST['resetBudget']) ? 'error' : 'Invalid month.');
    }

    // Reset (AJAX): returns "success" or "not_found".
    if (isset($_POST['resetBudget'])) {
        $stmt = $conn->prepare('DELETE FROM Budget WHERE Uid = ? AND Month = ?');
        $stmt->bind_param('is', $user_id, $month);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();
        exit($deleted > 0 ? 'success' : 'not_found');
    }

    if (isset($_POST['setBudget'])) {
        $amount = filter_var($_POST['budget'] ?? '', FILTER_VALIDATE_FLOAT);
        $monthLabel = date('F Y', strtotime($month . '-01'));

        if ($month < date('Y-m')) {
            alert_redirect('Cannot set budget for past months!', url('setbudget.php'));
        }
        if ($amount === false || $amount <= 0) {
            alert_redirect('Invalid input. Please enter a valid budget amount.');
        }

        $stmt = $conn->prepare('SELECT 1 FROM Budget WHERE Uid = ? AND Month = ?');
        $stmt->bind_param('is', $user_id, $month);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($exists) {
            alert_redirect("Budget for $monthLabel already exists! Reset it first if you want to change it.", url('setbudget.php'));
        }

        $stmt = $conn->prepare('INSERT INTO Budget (Uid, Month, Amount) VALUES (?, ?, ?)');
        $stmt->bind_param('isd', $user_id, $month, $amount);
        $stmt->execute();
        $stmt->close();
        alert_redirect('Budget set successfully!', url('setbudget.php'));
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Budget</title>
    <link rel="stylesheet" href="<?= e(asset('css/setbudget.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
    <script src="<?= e(asset('vendor/jquery/jquery.min.js')) ?>"></script>
</head>
<body>
    <header>
        <img src="<?= e(asset('img/logo.png')) ?>" alt="Logo" class="logo" onclick="location.href='<?= e(url('landing.html')) ?>'">
    </header>
    
    <?php include __DIR__ . '/includes/sidebar_user.php'; ?>
     <!-- MAIN CONTENT -->
    <div class="main-content">
        <div class="form-container">
            <h1>Set Budget:</h1>
            <form id="budgetForm" method="POST">
                <?= csrf_field() ?>
                <label for="month">Select Month:</label>
                <input type="month" id="month" name="month" required onchange="checkExistingBudget()">
                <br>
                <label for="budget">Enter Budget Amount:</label>
                <input type="number" id="budget" name="budget" step="0.01" required>
                
                <button type="submit" name="setBudget" id="setBudgetBtn" class="set-budget-btn">Set Budget</button>
                <button type="button" id="resetBtn" class="reset-budget-btn" onclick="resetBudget()">Reset Budget</button>
            </form>
        </div>
    </div>

    <script>
        // Disable past months
        document.addEventListener("DOMContentLoaded", function () {
            let today = new Date();
            let year = today.getFullYear();
            let month = String(today.getMonth() + 1).padStart(2, '0'); 
            let minDate = `${year}-${month}`;
            document.getElementById("month").setAttribute("min", minDate);
        });

        function checkExistingBudget() {
            var selectedMonth = document.getElementById("month").value;
            if (selectedMonth === "") {
                return;
            }

            $.ajax({
                type: "POST",
                url: "api/check_budget.php",
                data: { month: selectedMonth, csrf_token: document.querySelector('input[name="csrf_token"]').value },
                success: function(response) {
                    if (response.trim() === "exists") {
                        alert("Budget for this month already exists! You must reset it first.");
                        document.getElementById("setBudgetBtn").disabled = true;
                    } else {
                        document.getElementById("setBudgetBtn").disabled = false;
                    }
                }
            });
        }

        function resetBudget() {
            var selectedMonth = document.getElementById("month").value;
            if (selectedMonth === "") {
                alert("Please select a month first.");
                return;
            }

            if (confirm("Are you sure you want to reset the budget for the selected month?")) {
                $.ajax({
                    type: "POST",
                    url: "setbudget.php",
                    data: { resetBudget: true, month: selectedMonth, csrf_token: document.querySelector('input[name="csrf_token"]').value },
                    success: function(response) {
                        if (response.trim() === "success") {
                            alert("Budget reset successfully!");
                            location.reload();
                        } else {
                            alert("No budget was set for the selected month.");
                        }
                    },
                    error: function() {
                        alert("Error in resetting budget.");
                    }
                });
            }
        }
    </script>
    
</body>
</html>
