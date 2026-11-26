<?php
require_once __DIR__ . '/includes/bootstrap.php';

$uid = require_login();

// Fetch user details
$query = "SELECT Uname, email, Phone_no FROM User WHERE Uid = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $uid);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
} else {
    echo "User not found!";
    exit();
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Profile</title>
    <link rel="stylesheet" href="<?= e(asset('css/profile.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
</head>
<body>
    <img src="<?= e(asset('img/logo.png')) ?>" alt="Logo" class="logo" onclick="location.href='<?= e(url('landing.html')) ?>'">

    <?php include __DIR__ . '/includes/sidebar_user.php'; ?>

    <div class="profile-container">
        <div class="profile-card">
            <h1>User Details</h1>
            <h2>Name:</h2>
            <div class="input-field"><?= e($user['Uname']) ?></div>

            <h2>Email:</h2>
            <div class="input-field"><?= e($user['email']) ?></div>

            <h2>Phone Number:</h2>
            <div class="input-field"><?= e($user['Phone_no']) ?></div>
            <br>
            <div class="button-group">
                <input type="hidden" id="delete-csrf" value="<?= e(csrf_token()) ?>">
                <!-- Update reset password button to link to reset_password.php -->
                <a href="<?= e(url('reset_password.php')) ?>" class="btn reset-btn">Reset Password</a>
                <a href="<?= e(url('forgot_password.php')) ?>" class="btn forgot-btn">Forgot Password</a>
                <button class="btn delete-btn" onclick="confirmDelete()">Delete Account</button>
            </div>
        </div>
    </div>

<!-- JavaScript for Delete Account Confirmation -->
<script>
function confirmDelete() {
    let confirmation = confirm("Are you sure you want to delete your account?");
    if (confirmation) {
        fetch(<?= js(url('api/delete_account.php')) ?>, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ delete: true, csrf_token: document.getElementById("delete-csrf").value })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert("Account deleted successfully!");
                window.location.href = <?= js(url('landing.html')) ?>;
            } else {
                alert("Error: " + data.message);
            }
        })
        .catch(() => alert('Could not delete the account. Please try again.'));
    }
}
</script>

</body>
</html>
