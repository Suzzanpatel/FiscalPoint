<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user_id() !== null) {
    redirect(is_admin() ? url('admin/admin_registered_users.php') : url('dashboard.php'));
}

$error = '';

if (is_post()) {
    // Passwords are never HTML-escaped: they go straight to password_verify().
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // Simple brute-force throttle: 5 failed attempts lock the form for 5 minutes.
    $attempts = $_SESSION['login_attempts'] ?? ['count' => 0, 'since' => time()];
    if (time() - $attempts['since'] > 300) {
        $attempts = ['count' => 0, 'since' => time()];
    }

    if ($attempts['count'] >= 5) {
        $error = 'Too many failed attempts. Please wait a few minutes and try again.';
    } else {
        $stmt = $conn->prepare('SELECT Uid, Uname, email, Password, Role FROM User WHERE email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $valid = false;
        if ($user) {
            if (password_verify($password, $user['Password'])) {
                $valid = true;
            } elseif (password_verify(htmlspecialchars(trim($password)), $user['Password'])) {
                // Legacy accounts: older versions HTML-escaped passwords before hashing.
                $valid = true;
            }
        }

        if ($valid) {
            if (password_needs_rehash($user['Password'], PASSWORD_DEFAULT) || !password_verify($password, $user['Password'])) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $upd = $conn->prepare('UPDATE User SET Password = ? WHERE Uid = ?');
                $upd->bind_param('si', $hash, $user['Uid']);
                $upd->execute();
                $upd->close();
            }
            unset($_SESSION['login_attempts']);
            login_user($user);
            redirect($user['Role'] === 'admin' ? url('admin/admin_registered_users.php') : url('dashboard.php'));
        }

        $attempts['count']++;
        $_SESSION['login_attempts'] = $attempts;
        // Same message for unknown email and wrong password (no account enumeration).
        $error = 'Invalid email or password.';
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Page</title>
    <!-- Linking CSS File -->
    <link rel="stylesheet" href="<?= e(asset('css/login.css')) ?>"> 
</head>
<body>

    <div class="container">
        <header>
            <!-- LOGO-->
            <img src="<?= e(asset('img/logo.png')) ?>" alt="Logo" class="logo" onclick="location.href='<?= e(url('landing.html')) ?>'">
            <!-- NAVIGATION BAR -->  
            <nav class="navbar">
                <ul>
                    <li><a href="landing.html">Home</a></li>
                    <li><a href="login.php">Expense Tracker</a></li>
                    <li><a href="landing.html#aboutus">About Us</a></li> 
                </ul>
            </nav>
        </header>
        <div class="login-box">
            <div class="avatar">
                <img src="<?= e(asset('img/profile.png')) ?>" alt="User Icon">
            </div>
            <!-- LOGIN FORM -->
            <form method="post" action="login.php">
                <?= csrf_field() ?>
                <?php if ($error): ?><p class="error-message" style="color:#ff6b6b;"><?= e($error) ?></p><?php endif; ?>
                <label for="email">Enter your email:</label>
                <input type="email" id="email" name="email" placeholder="Email" required>
                
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" placeholder="Password" required>
                
                <button type="submit">Login</button>
            </form>
            
            <!-- Signup Link -->
            <p class="signup-text">New user? <a href="signup.php">Sign up instead</a></p>

             <!-- Forgot Password Link -->
             <p class="forgotpassword-text"><a href="forgot_password.php">Forgot Password?</a></p>

        </div>
    </div>
   
</body>
</html>
