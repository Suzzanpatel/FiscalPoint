<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once __DIR__ . '/includes/bootstrap.php';

$notice = '';

if (is_post()) {
    $email = trim((string) ($_POST['email'] ?? ''));

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $conn->prepare('SELECT Uid FROM User WHERE email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);          // store only the hash
            // Expiry computed by the database so it is compared on the same clock in reset_password.php.
            $stmt = $conn->prepare('UPDATE User SET reset_token = ?, token_expires = NOW() + INTERVAL 1 HOUR WHERE Uid = ?');
            $stmt->bind_param('si', $tokenHash, $user['Uid']);
            $stmt->execute();
            $stmt->close();

            $appUrl = rtrim(env('APP_URL', 'http://localhost/FiscalPoint/web'), '/');
            $resetLink = $appUrl . '/reset_password.php?token=' . urlencode($token);
            $body = "Hello,\n\nA password reset was requested for your FiscalPoint account.\n\n"
                . "Open this link to choose a new password (valid for 1 hour):\n$resetLink\n\n"
                . "If you did not request this, you can ignore this email.\n\nFiscalPoint Support";

            if (!sendMail($email, 'FiscalPoint password reset', $body)) {
                error_log('FiscalPoint: password reset email could not be sent.');
            }
        }
    }
    // Identical response whether or not the account exists (prevents account enumeration).
    $notice = 'If an account exists for that email, a password reset link has been sent.';
}

/** Send a plain-text email through the SMTP server configured in .env. */
function sendMail(string $to, string $subject, string $body): bool
{
    $username = env('EMAIL_USERNAME');
    $password = env('EMAIL_PASSWORD');
    if (!$username || !$password) {
        error_log('FiscalPoint: EMAIL_USERNAME / EMAIL_PASSWORD are not configured.');
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = env('SMTP_HOST', 'smtp.gmail.com');
        $mail->Port = (int) env('SMTP_PORT', '587');
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->setFrom($username, 'FiscalPoint Support');
        $mail->addAddress($to);
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body = $body;
        return $mail->send();
    } catch (MailException $e) {
        error_log('FiscalPoint mail error: ' . $mail->ErrorInfo);
        return false;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="<?= e(asset('css/forgot_password.css')) ?>">
</head>
<body>
<div class="container">
    <header>
        <!-- LOGO -->
        <img src="<?= e(asset('img/logo.png')) ?>" alt="Logo" class="logo" onclick="location.href='<?= e(url('landing.html')) ?>'">
        <!-- NAVIGATION BAR -->  
        <nav class="navbar">
            <ul>
                <?php if (isset($_SESSION['Role']) && $_SESSION['Role'] === 'admin'): ?>
                    <li><a href="<?= e(url('admin/admin_profile.php')) ?>">Back to Profile</a></li>
                    <li><a href="<?= e(url('admin/admin_category.php')) ?>">Dashboard</a></li>
                    <li><a href="logout.php">Logout</a></li>
                <?php else: ?>
                    <li><a href="landing.html">Home</a></li>
                    <li><a href="login.php">Expense Tracker</a></li>
                    <li><a href="landing.html#aboutus">About Us</a></li> 
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <div class="forgot-password-box">
        <h2>Forgot Password</h2>
        <p>Enter your email address and we will send you a link to reset your password.</p>

        <?php if ($notice): ?><p class="notice"><?= e($notice) ?></p><?php endif; ?>
        <form action="forgot_password.php" method="POST">
            <?= csrf_field() ?>
            <label for="email">Email Address:</label>
            <input type="email" name="email" id="email" required>
            <button type="submit">Send Reset Link</button>
        </form>

        <?php if (isset($_SESSION['Role']) && $_SESSION['Role'] === 'admin'): ?>
            <p><a href="<?= e(url('admin/admin_profile.php')) ?>">Back to Profile</a></p>
        <?php else: ?>
            <p><a href="login.php">Back to Login</a></p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
