<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$adminId = require_admin();

$error = '';

if (is_post()) {
    $uname = trim((string) ($_POST['Uname'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['Phone_no'] ?? ''));
    $password = (string) ($_POST['Password'] ?? '');
    $confirm_password = (string) ($_POST['ConfirmPassword'] ?? '');
    $role = 'admin';   // this page only ever creates administrators

    if ($uname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{10}$/', $phone)) {
        $error = 'Please enter a name, a valid email and a 10-digit phone number.';
    } elseif ($problem = password_problem($password)) {
        $error = $problem;
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $conn->prepare('SELECT 1 FROM User WHERE email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($exists) {
            $error = 'Email already exists. Please use a different email.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('INSERT INTO User (Uname, email, Phone_no, Password, Role) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('sssss', $uname, $email, $phone, $hash, $role);
            $stmt->execute();
            $stmt->close();
            // The current admin stays logged in as themselves (the old code switched the
            // session to the newly created account).
            alert_redirect('Administrator account created for ' . $email . '.', 'manage_admin.php');
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - Fiscal Point</title>
    <link rel="stylesheet" href="<?= e(asset('css/add_admin.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
    <style>
        button[type="submit"] {
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            transition: background-color 0.3s ease;
            display: inline-block;
            font-weight: 500;
            background-color: #4CAF50;
        }
        
        button[type="submit"]:hover {
            background-color: #45a049;
        }
    </style>
</head>
<body>
 <!-- Main Container -->
 <div class="container">
    <header>
        <!-- LOGO-->
       <img src="<?= e(asset('img/logo.png')) ?>" alt="Logo" class="logo" onclick="location.href='<?= e(url('landing.html')) ?>'"> 
       
       <!-- NAVIGATION BAR -->
       <?php include __DIR__ . '/../includes/sidebar_admin.php'; ?>
    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    <!--  Signup Form Section -->
    
    <div class="signup-container">
        <h2>Welcome to Fiscal Point!</h2>

        <!-- Signup Form -->
        <form class="signup-form" method="post" action="add_admin.php">
            <?= csrf_field() ?>
            <?php if ($error): ?><p class="error-message"><?= e($error) ?></p><?php endif; ?>
    <!-- Email Input -->
    <input type="hidden" name="Role" value="admin">
    <label for="email">Enter admin email:</label>
    <input type="email" id="email" name="email" placeholder="Email" required>
    <span id="email-error" class="error-message"></span>

    <!-- Full Name Input -->
    <label for="Uname">Amin Name:</label>
    <input type="text" id="Uname" name="Uname" placeholder="Full Name" required>

    <!-- Phone Number Input --> 
    <label for="Phone_no">Phone Number:</label>
    <input type="tel" id="Phone_no" name="Phone_no" placeholder="Enter Phone Number" required pattern="[0-9]{10}" maxlength="10">

    <!-- Password Input -->
    <label for="Password">Create Password:</label>
    <input type="password" id="Password" name="Password" placeholder="Password" required>
    <span id="password-error" class="error-message"></span>

    <!-- Confirm Password Input -->
    <label for="ConfirmPassword">Confirm Password:</label>
    <input type="password" id="ConfirmPassword" name="ConfirmPassword" placeholder="Confirm Password" required>
    <span id="confirm-password-error" class="error-message"></span>
    
    

    <!-- Signup Button -->
    <button type="submit">Get Started</button>
    
</form>

        <!-- Login Redirect -->
        <p class="login-text">Already a login? <a href="login.php">Login instead</a></p>
    </div>
</div>

<style>
    .error-message {
        color: red;
        font-size: 0.9em;
    }
</style>

 <!--  JavaScript -->
 <script src="<?= e(asset('js/signup.js')) ?>"></script>

</body>
</html>
