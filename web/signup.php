<?php
require_once __DIR__ . '/includes/bootstrap.php';

$error = '';

if (is_post()) {
    $uname = trim((string) ($_POST['Uname'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['Phone_no'] ?? ''));
    $password = (string) ($_POST['Password'] ?? '');            // never HTML-escaped
    $confirmPassword = (string) ($_POST['ConfirmPassword'] ?? '');
    $role = 'user';                                              // public sign-up can only create users

    if ($uname === '' || mb_strlen($uname) > 255) {
        $error = 'Please enter your full name.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^\d{10}$/', $phone)) {
        $error = 'Phone number must be exactly 10 digits.';
    } elseif ($problem = password_problem($password)) {
        $error = $problem;
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $conn->prepare('SELECT 1 FROM User WHERE email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($exists) {
            $error = 'An account with this email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('INSERT INTO User (Uname, email, Phone_no, Password, Role) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('sssss', $uname, $email, $phone, $hash, $role);
            $stmt->execute();
            $userId = $conn->insert_id;
            $stmt->close();

            login_user(['Uid' => $userId, 'Uname' => $uname, 'email' => $email, 'Role' => $role]);
            redirect(url('dashboard.php'));
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
    <link rel="stylesheet" href="<?= e(asset('css/signup.css')) ?>">  
</head>
<body>
 <!-- Main Container -->
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

    <!--  Signup Form Section -->
    <div class="signup-container">
        <h2>Welcome to Fiscal Point!</h2>

        <!-- Signup Form -->
        <form class="signup-form" action="signup.php" method="POST">
    <?= csrf_field() ?>
    <?php if ($error): ?><p class="error-message"><?= e($error) ?></p><?php endif; ?>
    <!-- Email Input -->
    <label for="email">Enter your email:</label>
    <input type="email" id="email" name="email" placeholder="Email" required value="<?= e($_POST['email'] ?? '') ?>">
    <span id="email-error" class="error-message"></span>

    <!-- Full Name Input -->
    <label for="Uname">Full Name:</label>
    <input type="text" id="Uname" name="Uname" placeholder="Full Name" required value="<?= e($_POST['Uname'] ?? '') ?>">

    <!-- Phone Number Input --> 
    <label for="Phone_no">Phone Number:</label>
    <input type="tel" id="Phone_no" name="Phone_no" placeholder="Enter Phone Number" required pattern="[0-9]{10}" maxlength="10" value="<?= e($_POST['Phone_no'] ?? '') ?>">

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
        <p class="login-text">Already a user? <a href="login.php">Login instead</a></p>
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
