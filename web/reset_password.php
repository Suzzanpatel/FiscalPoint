<?php
require_once __DIR__ . '/includes/bootstrap.php';

// A reset is allowed either with a valid emailed token or from a logged-in session.
$sessionUid = current_user_id();
$token = (string) ($_GET['token'] ?? '');
$error = '';
$uid = $sessionUid;

if ($sessionUid === null && $token === '') {
    redirect(url('login.php'));
}

if ($token !== '') {
    // Only the SHA-256 hash of the token is stored in the database.
    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare('SELECT Uid FROM User WHERE reset_token = ? AND token_expires > NOW()');
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        http_response_code(400);
        exit('Invalid or expired password reset link.');
    }
    $uid = (int) $row['Uid'];
}

if (is_post()) {
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($problem = password_problem($newPassword)) {
        $error = $problem;
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match!';
    } else {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE User SET Password = ?, reset_token = NULL, token_expires = NULL WHERE Uid = ?');
        $stmt->bind_param('si', $hashedPassword, $uid);
        $stmt->execute();
        $stmt->close();

        if ($sessionUid !== null && is_admin()) {
            session_regenerate_id(true);
            alert_redirect('Password reset successfully!', url('admin/admin_profile.php'));
        }
        // Users (and anyone using an emailed link) must log in again with the new password.
        $_SESSION = [];
        session_destroy();
        alert_redirect('Password reset successfully! Please log in.', url('login.php'));
    }
}

$backLink = is_admin() ? url('admin/admin_profile.php') : ($sessionUid !== null ? url('profile.php') : url('login.php'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link rel="stylesheet" href="<?= e(asset('css/reset_password.css')) ?>">
    <script>
        function validateForm() {
            let password = document.getElementById("new_password").value;
            let confirmPassword = document.getElementById("confirm_password").value;
            let errorBox = document.getElementById("error-box");

            if (password.length < 8 || !/[A-Z]/.test(password) || !/[a-z]/.test(password) || !/[0-9]/.test(password)) {
                errorBox.innerText = "Password must be at least 8 characters and contain an uppercase letter, a lowercase letter and a number.";
                return false;
            }
            if (password !== confirmPassword) {
                errorBox.innerText = "Passwords do not match!";
                return false;
            }
            return true;
        }
    </script>
</head>
<body>

    <div class="container">
        <header>
            <img src="<?= e(asset('img/logo.png')) ?>" alt="Logo" class="logo" onclick="location.href='<?= e(url('landing.html')) ?>'">
            <nav class="navbar">
                <ul>
                    <li><a href="landing.html">Home</a></li>
                    <li><a href="login.php">Expense Tracker</a></li>
                    <li><a href="landing.html#aboutus">About Us</a></li> 
                </ul>
            </nav>
        </header>

        <div class="reset-password-box">
            <div class="avatar">
                <img src="<?= e(asset('img/profile.png')) ?>" alt="User Icon">
            </div>
            <h2>Reset Password</h2>

            <p id="error-box" style="color:red;"><?= e($error) ?></p>

            <form method="POST" onsubmit="return validateForm();">
                <?= csrf_field() ?>

                <label for="new_password">New Password:</label>
                <input type="password" id="new_password" name="new_password" placeholder="Enter new password" required>

                <label for="confirm_password">Confirm Password:</label>
                <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required>

                <button type="submit">Reset Password</button>
            </form>

            <p class="back-to-profile"><a href="<?= e($backLink) ?>">Back</a></p>
        </div>
    </div>

</body>

</html>
