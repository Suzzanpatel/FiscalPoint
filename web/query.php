<?php
require_once __DIR__ . '/includes/bootstrap.php';
$uid = require_login();

if (is_post()) {
    // The reply address is always the account's own email (not a free-text field).
    $email = (string) ($_SESSION['email'] ?? '');
    $queryType = trim((string) ($_POST['category'] ?? ''));
    $description = trim(strip_tags((string) ($_POST['description'] ?? '')));

    if ($description === '' || mb_strlen($description) > 2000) {
        alert_redirect('Please describe your query (up to 2000 characters).');
    }
    if ($queryType === '' || mb_strlen($queryType) > 255) {
        alert_redirect('Please choose a query type.');
    }

    $stmt = $conn->prepare('INSERT INTO Query (Uid, Email, Query_type, Description) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $uid, $email, $queryType, $description);
    $stmt->execute();
    $stmt->close();
    alert_redirect('Query submitted successfully!', url('query.php'));
}

// Show the user their previous queries and any admin responses.
$stmt = $conn->prepare('SELECT Query_type, Description, Created_At, Status, Admin_Response FROM Query WHERE Uid = ? ORDER BY Created_At DESC');
$stmt->bind_param('i', $uid);
$stmt->execute();
$previousQueries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Query - FiscalPoint</title>
    <link rel="stylesheet" href="<?= e(asset('css/addexpense.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
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
            <h1>Query:</h1>
            <div>
                <h3 class="budget-text">Enter your query :</h3>
               
            </div>
            <br>
            <form action="query.php" method="POST" id="queryForm">
            <?= csrf_field() ?>

<label for="email">Replies will be sent to:</label>
<input type="email" id="email" value="<?= e($_SESSION['email'] ?? '') ?>" readonly>

<label for="category">Query Type :</label>
<select id="category" name="category" class="category" required>
    <option value="Dashboard">Dashboard</option>
    <option value="Add Income">Add Income</option>
    <option value="Add Budget">Add Budget</option>
    <option value="Add Expense">Add Expense</option>
    <option value="Graph Report">Graph Report</option>
    <option value="Tabular report">Tabular report</option>
    <option value="Insights">Insights</option>
    <option value="Predictions">Predictions</option>
    <option value="Profile">Profile</option>
</select>

<label for="description">Description:</label>
<textarea id="description" name="description" required maxlength="2000" rows="4" style="width:100%;"></textarea>

<p class="signup-text">Responses from the admin team appear below and in your mailbox.</p>

<button type="submit" class="add-expense-btn">Submit Query</button>
</form>

<?php if ($previousQueries): ?>
    <h3 class="budget-text" style="margin-top:20px;">Your previous queries</h3>
    <table style="width:100%; color:#fff; border-collapse:collapse;">
        <tr><th style="text-align:left;">Date</th><th style="text-align:left;">Type</th><th style="text-align:left;">Query</th><th style="text-align:left;">Status</th><th style="text-align:left;">Response</th></tr>
        <?php foreach ($previousQueries as $q): ?>
            <tr>
                <td><?= e(date('d-m-Y', strtotime($q['Created_At']))) ?></td>
                <td><?= e($q['Query_type']) ?></td>
                <td><?= e($q['Description']) ?></td>
                <td><?= e($q['Status']) ?></td>
                <td><?= e($q['Admin_Response'] ?? '-') ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
        </div>
    </div>

   
    
     <!-- Linking External JavaScript -->
   

</body>
</html>
