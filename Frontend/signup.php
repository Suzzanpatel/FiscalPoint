<?php
// Enable error reporting
error_reporting(E_ALL);

// Start session
session_start();

require_once __DIR__ . '/db.php';

// Sanitize input function
function sanitize_input($data) {
    if ($data === null) {
        return '';
    }
    return htmlspecialchars(trim($data));
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $uname = trim($_POST["Uname"] ?? "");
    $email = strtolower(trim($_POST["email"] ?? ""));
    $phone = trim($_POST["Phone_no"] ?? "");
    $password = $_POST["Password"] ?? "";
    $confirm_password = $_POST["ConfirmPassword"] ?? "";
    $role = "user";
    $created_at = date("Y-m-d H:i:s");

    // Validate required fields before storing account data.
    if ($uname === "" || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match("/^[0-9]{10}$/", $phone) || strlen($password) < 8) {
        echo "<script>alert('Please provide a valid name, email, 10-digit phone number and a password of at least 8 characters.'); window.history.back();</script>";
        exit();
    }

    // Check if passwords match
    if ($password !== $confirm_password) {
        echo "<script>alert('Passwords do not match. Please try again.'); window.history.back();</script>";
        exit();
    }

    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Check if email already exists
    $check_query = "SELECT * FROM User WHERE email = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        echo "<script>alert('Email already exists. Please use a different email.');</script>";
    } else {
        // Insert new user into database
        $insert_query = "INSERT INTO User (Uname, email, Phone_no, Password, Created_At, Role) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("ssssss", $uname, $email, $phone, $hashed_password, $created_at, $role);

        if ($stmt->execute()) {
            // Get the inserted user details (including role)
            $user_id = $conn->insert_id;
            session_regenerate_id(true);
            $_SESSION["Uid"] = $user_id;
            $_SESSION["Uname"] = $uname;
            $_SESSION["email"] = $email;
            $_SESSION["UserEmail"] = $email;
            $_SESSION["Role"] = $role;  // Store the role in the session

            header("Location: dashboard.php");
            exit();
        } else {
            echo "<script>alert('Registration failed. Please try again later.');</script>";
        }
    }

    // Close the statement
    $stmt->close();
}

// Close the connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - Fiscal Point</title>
    <link rel="stylesheet" href="css/signup.css">  
</head>
<body>
 <!-- Main Container -->
 <div class="container">
    <header>
        <!-- LOGO-->
       <img src="css/logo.png" alt="Logo" class="logo" onclick="location.href='landing.html'"> 
       
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
    <!-- Email Input -->
    <label for="email">Enter your email:</label>
    <input type="email" id="email" name="email" placeholder="Email" required>
    <span id="email-error" class="error-message"></span>

    <!-- Full Name Input -->
    <label for="Uname">Full Name:</label>
    <input type="text" id="Uname" name="Uname" placeholder="Full Name" required>

    <!-- Phone Number Input --> 
    <label for="Phone_no">Phone Number:</label>
    <input type="tel" id="Phone_no" name="Phone_no" placeholder="Enter Phone Number" required pattern="[0-9]{10}" maxlength="10">

    <!-- Public registrations are always assigned the user role by the server. -->
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
 <script src="javascript/signup.js"></script>

</body>
</html>
