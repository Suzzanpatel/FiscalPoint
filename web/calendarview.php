<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user_id = require_login();
$uid = $user_id;
$selected_month = valid_month($_POST['month'] ?? null) ? $_POST['month'] : date('Y-m');
$month = (int) date("m", strtotime($selected_month . "-01"));
$year = (int) date("Y", strtotime($selected_month . "-01"));

$sql_calendar = "SELECT DAY(Date) AS day, SUM(amount) AS total FROM Expense WHERE Uid = ? AND MONTH(Date) = ? AND YEAR(Date) = ? GROUP BY DAY(Date)";
$stmt = $conn->prepare($sql_calendar);
$stmt->bind_param("iii", $uid, $month, $year);
$stmt->execute();
$result_calendar = $stmt->get_result();

$daily_expenses = [];
while ($row = $result_calendar->fetch_assoc()) {
    $daily_expenses[$row['day']] = $row['total'];
}
$stmt->close();

// GROUP_CONCAT keeps the query valid under MySQL's ONLY_FULL_GROUP_BY mode.
$sql_details = "SELECT DAY(date) AS day, category AS Category, SUM(amount) AS category_total,
                       GROUP_CONCAT(description ORDER BY Eid SEPARATOR ', ') AS Description
                FROM Expense
                WHERE Uid = ? AND MONTH(date) = ? AND YEAR(date) = ?
                GROUP BY DAY(date), category";
$stmt = $conn->prepare($sql_details);
$stmt->bind_param("iii", $uid, $month, $year);
$stmt->execute();
$result_details = $stmt->get_result();

$expense_details = [];
while ($row = $result_details->fetch_assoc()) {
    if (!isset($expense_details[$row['day']])) {
        $expense_details[$row['day']] = [];
    }
    $expense_details[$row['day']][] = [
        'category' => $row['Category'],
        'amount' => $row['category_total'],
        'description' => $row['Description']
    ];
}
$stmt->close();

$first_day_of_month = date('N', strtotime($selected_month . "-01")); // 1 (Mon) to 7 (Sun)
$total_days = (int) date("t", strtotime($selected_month . "-01"));   // no dependency on ext-calendar
$today = date("j");
$current_month = date("n");
$current_year = date("Y");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Calendar Day-wise Report</title>
    <link rel="stylesheet" href="<?= e(asset('css/calendarview.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
    <style>
        /* Popup styling */
        .expense-popup {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color:rgb(0, 0, 2);
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0,0,0,0.2);
            z-index: 1000;
            min-width: 300px;
            max-width: 80%;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .expense-popup h3 {
            margin-top: 0;
            color: white;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        
        .expense-popup table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        
        .expense-popup th, .expense-popup td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .expense-popup th {
            background-color:rgb(0, 0, 2);
        }
        
        .close-popup {
            position: absolute;
            top: 10px;
            right: 10px;
            cursor: pointer;
            font-size: 20px;
            color: #999;
        }
        
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 999;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar_user.php'; ?>
    
<div class="calendar">
    <h2><?= e(date("F Y", strtotime($selected_month . "-01"))) ?></h2>
    <table>
        <tr>
            <th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th>
            <th>Fri</th><th>Sat</th><th>Sun</th>
        </tr>
        <tr>
            <?php
            $day_counter = 1;
            $cell_count = 1;

            for ($i = 1; $i < $first_day_of_month; $i++) {
                echo '<td class="empty"></td>';
                $cell_count++;
            }

            for ($day = 1; $day <= $total_days; $day++) {
                $classes = "";
                if ($day == $today && $month == $current_month && $year == $current_year) {
                    $classes .= "today ";
                }
                if (isset($daily_expenses[$day])) {
                    $classes .= "has-expense";
                }

                $amount = isset($daily_expenses[$day]) ? (float) $daily_expenses[$day] : null;
                echo "<td class='$classes' data-day='$day' data-amount='" . ($amount === null ? '' : $amount) . "'>$day";
                if ($amount !== null) {
                    echo "<br>₹" . number_format($amount, 2);
                }
                echo "</td>";

                if ($cell_count % 7 == 0) {
                    echo "</tr><tr>";
                }

                $cell_count++;
            }

            while (($cell_count - 1) % 7 != 0) {
                echo '<td class="empty"></td>';
                $cell_count++;
            }
            ?>
        </tr>
    </table>
</div>

<div class="month-nav">
    <form method="POST" id="monthForm">
        <input type="hidden" name="month" id="monthInput" value="<?= e($selected_month) ?>">
        <button type="button" onclick="changeMonth(-1)">← Prev</button>
        <button type="button" onclick="changeMonth(1)">Next →</button>
    </form>
</div>

<!-- Add the popup and overlay divs -->
<div class="overlay" id="overlay"></div>
<div class="expense-popup" id="expensePopup">
    <span class="close-popup" onclick="closePopup()">&times;</span>
    <h3 id="popupDate"></h3>
    <div id="popupContent"></div>
</div>

<script>
    function changeMonth(offset) {
        // Pure arithmetic on YYYY-MM (Date + toISOString breaks in negative UTC offsets).
        const [y, m] = document.getElementById('monthInput').value.split('-').map(Number);
        const index = y * 12 + (m - 1) + offset;
        const newMonth = `${Math.floor(index / 12)}-${String(index % 12 + 1).padStart(2, '0')}`;
        document.getElementById('monthInput').value = newMonth;
        document.getElementById('monthForm').submit();
    }

    // ← → arrow key support
    document.addEventListener('keydown', function(event) {
        if (event.key === 'ArrowLeft') {
            changeMonth(-1);
        } else if (event.key === 'ArrowRight') {
            changeMonth(1);
        }
    });

    // Click day cell to show popup with detailed expense breakdown
    document.querySelectorAll(".calendar td.has-expense").forEach(cell => {
        cell.addEventListener("click", () => {
            const day = cell.dataset.day.padStart(2, '0');
            const date = <?= js(sprintf('%04d-%02d-', $year, $month)) ?> + day + 'T00:00:00';
            
            // Get the expense details from PHP
            const expenseDetails = <?= js($expense_details) ?>;
            const dayDetails = expenseDetails[parseInt(cell.dataset.day)];
            
            // Format the date for display
            const formattedDate = new Date(date).toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            
            // Set the popup title
            document.getElementById('popupDate').textContent = formattedDate;
            
            // Build the content table
            // Built with DOM APIs + textContent so user-entered text can never inject HTML.
            const container = document.getElementById('popupContent');
            container.textContent = '';
            const table = document.createElement('table');
            table.innerHTML = '<thead><tr><th>Category</th><th>Amount</th></tr></thead>';
            const body = document.createElement('tbody');
            (dayDetails || []).forEach(detail => {
                const row = body.insertRow();
                row.insertCell().textContent = detail.category;
                row.insertCell().textContent = '₹' + parseFloat(detail.amount).toFixed(2);
            });
            table.appendChild(body);
            container.appendChild(table);
            const total = document.createElement('p');
            total.innerHTML = '<strong></strong>';
            total.firstChild.textContent = 'Total: ₹' + parseFloat(cell.dataset.amount).toFixed(2);
            container.appendChild(total);
            
            // Show the popup
            document.getElementById('overlay').style.display = 'block';
            document.getElementById('expensePopup').style.display = 'block';
        });
    });
    
    function closePopup() {
        document.getElementById('overlay').style.display = 'none';
        document.getElementById('expensePopup').style.display = 'none';
    }
    
    // Close popup when clicking overlay
    document.getElementById('overlay').addEventListener('click', closePopup);
</script>

</body>
</html>
