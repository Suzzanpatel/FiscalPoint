<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user_id = require_login();
$uid = $user_id;
// Fetch User Details
$sql_user = "SELECT Uname FROM User WHERE Uid = ?";
$stmt_user = $conn->prepare($sql_user);
if ($stmt_user) {
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    $user_name = ($result_user->num_rows > 0) ? $result_user->fetch_assoc()["Uname"] : "Unknown User";
    $stmt_user->close();
} else {
    http_response_code(500); exit("Could not load the report.");
}

// Fetch categories for dropdown
$sql_categories = "SELECT DISTINCT category FROM Expense WHERE Uid = ?";
$stmt_categories = $conn->prepare($sql_categories);
$categories = [];
if ($stmt_categories) {
    $stmt_categories->bind_param("i", $user_id);
    $stmt_categories->execute();
    $result_categories = $stmt_categories->get_result();
    while ($row = $result_categories->fetch_assoc()) {
        $categories[] = $row["category"];
    }
    $stmt_categories->close();
} else {
    http_response_code(500); exit("Could not load the report.");
}

// Default category selection
$selected_category = (string) ($_POST['category'] ?? '');

// Fetch Expenses based on selected category
$sql_expense = "SELECT category, description AS Item, amount AS Cost, date AS Date, Payment_Method FROM Expense WHERE Uid = ?";
if ($selected_category) {
    $sql_expense .= " AND category = ?";
}

$stmt_expense = $conn->prepare($sql_expense);
if ($stmt_expense) {
    if ($selected_category) {
        $stmt_expense->bind_param("is", $user_id, $selected_category);
    } else {
        $stmt_expense->bind_param("i", $user_id);
    }
    $stmt_expense->execute();
    $result_expense = $stmt_expense->get_result();
    $stmt_expense->close();
} else {
    http_response_code(500); exit("Could not load the report.");
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tabular Report</title>
    <link rel="stylesheet" href="<?= e(asset('css/categorywisereport.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
</head>
<body>
    <header>
        <img src="<?= e(asset('img/logo.png')) ?>" alt="Logo" class="logo" onclick="location.href='<?= e(url('landing.html')) ?>'">
    </header>
    
    <?php include __DIR__ . '/includes/sidebar_user.php'; ?>
    
    <div>
        <h2>View Expenses by Category</h2>
        <form method="POST" action="">
            <label for="category">Select Category:</label>
            <select name="category" id="category">
                <option value="">All Categories</option>
                <?php
                foreach ($categories as $category) {
                    echo "<option value='" . htmlspecialchars($category) . "' " . ($selected_category == $category ? "selected" : "") . ">" . htmlspecialchars($category) . "</option>";
                }
                ?>
            </select>
            <button type="submit">Filter</button>
        </form>
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
                        echo "<td>₹" . htmlspecialchars($row["Cost"]) . "</td>";
                        echo "<td>" . date("d-m-Y", strtotime($row["Date"])) . "</td>";
                        echo "<td>" . htmlspecialchars($row["Payment_Method"]) . "</td>";
                        echo "</tr>";
                        $total_cost += floatval($row["Cost"]);
                        $sr_no++;
                    }
                } else {
                    echo "<tr><td colspan='6'>No expenses found.</td></tr>";
                }
                echo "<tr>";
    echo "<tr>";
    echo "<td><strong>Total:</strong></td>"; 
    echo "<td colspan='2'></td>"; 
    echo "<td><strong>₹" . number_format($total_cost, 2) . "</strong></td>"; 
    echo "<td colspan='2'></td>"; 
    echo "</tr>";

                ?>
            </tbody>
        </table>
        </div>
    </div>
   
</body>
</html>
