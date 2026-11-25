<?php
session_start();
require_once __DIR__ . '/db.php';

// Password reset may originate from a valid email token or an authenticated session.
$uid = $_SESSION['Uid'] ?? null;
$token = $_GET['token'] ?? '';
$error = "";
$success = "";

if (!$uid && $token === '') {
    header("Location: login.php");
    exit();
}

if ($token !== '') {
    $stmt = $conn->prepare("SELECT Uid FROM User WHERE reset_token = ? AND token_expires > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        die("Invalid or expired password reset link.");
    }
    $uid = (int) $row['Uid'];
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!isset($_SESSION['Uid']) && $token === '') {
        die("Invalid password reset request.");
    }
    if (isset($_SESSION['Uid']) && (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']))) {
        die("CSRF token mismatch!");
    }

    $newPassword = trim($_POST['new_password']);
    $confirmPassword = trim($_POST['confirm_password']);

    // Validate password strength
    if (strlen($newPassword) < 8 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
        $error = "Password must be at least 8 characters long, contain an uppercase letter and a number.";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "Passwords do not match!";
    } else {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE User SET Password = ? WHERE Uid = ?");
        $stmt->bind_param("si", $hashedPassword, $uid);

        if ($stmt->execute()) {
            $clear = $conn->prepare("UPDATE User SET reset_token = NULL, token_expires = NULL WHERE Uid = ?");
            $clear->bind_param("i", $uid);
            $clear->execute();
            $clear->close();

            if (isset($_SESSION['Uid'])) {
                session_regenerate_id(true);
                if (($_SESSION['Role'] ?? '') === 'admin') {
                echo "<script>
                        alert('Password reset successfully!');
                        window.location.href = 'admin_profile.php';
                      </script>";
                } else {
                session_destroy(); 
                echo "<script>
                        alert('Password reset successfully! Please log in.');
                        window.location.href = 'login.php';
                      </script>";
                }
            } else {
                echo "<script>
                        alert('Password reset successfully! Please log in.');
                        window.location.href = 'login.php';
                      </script>";
            }
            exit();
        } else {
            $error = "Failed to reset password.";
        }
        $stmt->close();
    }
}

// Generate CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link rel="stylesheet" href="css/reset_password.css">
    <script>
        function validateForm() {
            let password = document.getElementById("new_password").value;
            let confirmPassword = document.getElementById("confirm_password").value;
            let errorBox = document.getElementById("error-box");

            if (password.length < 8 || !/[A-Z]/.test(password) || !/[0-9]/.test(password)) {
                errorBox.innerText = "Password must be at least 8 characters, contain an uppercase letter and a number.";
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
            <img src="css/logo.png" alt="Logo" class="logo" onclick="location.href='landing.html'">
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
                <img src="css/profile.png" alt="User Icon">
            </div>
            <h2>Reset Password</h2>

            <p id="error-box" style="color:red;"><?php echo $error; ?></p>

            <form method="POST" onsubmit="return validateForm();">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                <label for="new_password">New Password:</label>
                <input type="password" id="new_password" name="new_password" placeholder="Enter new password" required>

                <label for="confirm_password">Confirm Password:</label>
                <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required>

                <button type="submit">Reset Password</button>
            </form>

            <p class="back-to-profile">
                <?php if(isset($_SESSION['Role']) && $_SESSION['Role'] === 'admin'): ?>
                    <a href="admin_profile.php">Back to Profile</a>
                <?php else: ?>
                    <a href="profile.php">Back to Profile</a>
                <?php endif; ?>
            </p>
        </div>
    </div>

</body>

</html>
