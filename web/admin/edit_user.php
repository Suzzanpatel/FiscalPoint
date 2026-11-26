<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$adminId = require_admin();
// Get user ID from URL
$uid = isset($_GET['uid']) ? intval($_GET['uid']) : 0;

if ($uid <= 0) {
    redirect("admin_registered_users.php");
}

// Fetch user details
$stmt = $conn->prepare("SELECT Uid, Uname, email, Phone_no FROM User WHERE Uid = ?");
$stmt->bind_param("i", $uid);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    redirect("admin_registered_users.php");
}

// Where to go back to: whitelisted, never taken verbatim from user input.
$redirect_page = (($_GET['from'] ?? $_POST['from'] ?? '') === 'manage_admin') ? 'manage_admin.php' : 'admin_registered_users.php';
$error = '';

if (is_post()) {
    $uname = trim((string) ($_POST['uname'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));

    if ($uname === '' || mb_strlen($uname) > 255) {
        $error = 'Please enter a name.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^\d{10}$/', $phone)) {
        $error = 'Phone number must be exactly 10 digits.';
    } else {
        $stmt = $conn->prepare('SELECT 1 FROM User WHERE email = ? AND Uid <> ?');
        $stmt->bind_param('si', $email, $uid);
        $stmt->execute();
        $taken = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($taken) {
            $error = 'Another account already uses this email address.';
        } else {
            $update_stmt = $conn->prepare('UPDATE User SET Uname = ?, email = ?, Phone_no = ? WHERE Uid = ?');
            $update_stmt->bind_param('sssi', $uname, $email, $phone, $uid);
            $update_stmt->execute();
            $update_stmt->close();
            alert_redirect('User updated successfully.', $redirect_page);
        }
    }
    $user = ['Uid' => $uid, 'Uname' => $uname, 'email' => $email, 'Phone_no' => $phone];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit User</title>
    <link rel="stylesheet" href="<?= e(asset('css/admin_registered_users.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
</head>
<body>
<header>
    <img src="<?= e(asset('img/logo.png')) ?>" alt="Logo" class="logo" onclick="location.href='<?= e(url('landing.html')) ?>'">
</header>

<?php include __DIR__ . '/../includes/sidebar_admin.php'; ?>

<div class="edit-form-container">
    <h2>Edit User Details</h2>
    <form method="POST" action="edit_user.php?uid=<?= (int) $uid ?>">
        <?= csrf_field() ?>
        <?php if ($error): ?><p style="color:#8b0000;"><?= e($error) ?></p><?php endif; ?>
        <div class="form-group">
            <label for="uname">Username:</label>
            <input type="text" id="uname" name="uname" value="<?= e($user['Uname']) ?>" required>
        </div>
        
        <div class="form-group">
            <label for="email">Email:</label>
            <input type="email" id="email" name="email" value="<?= e($user['email']) ?>" required>
        </div>
        
        <div class="form-group">
            <label for="phone">Phone Number:</label>
            <input type="tel" id="phone" name="phone" value="<?= e($user['Phone_no']) ?>" required>
        </div>
        
        <div class="form-actions">
            <input type="hidden" name="from" value="<?= $redirect_page === 'manage_admin.php' ? 'manage_admin' : '' ?>">
            <button type="submit" class="save-btn"><i class="fas fa-save"></i> Save Changes</button>
            <a href="<?= e($redirect_page) ?>" class="cancel-btn"><i class="fas fa-times"></i> Cancel</a>
        </div>
    </form>
</div>

<style>
.edit-form-container {
    margin-left: 20%;
    padding: 20px;
    background-color: #86a69c;
    border-radius: 20px;
    width: 60%;
}

.edit-form-container h2 {
    text-align: center;
    color: white;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    color: white;
    font-weight: bold;
}

.form-group input {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    box-sizing: border-box;
}

.form-actions {
    display: flex;
    justify-content: center;
    gap: 10px;
    margin-top: 20px;
}

.save-btn, .cancel-btn {
    color: white;
    border: none;
    padding: 8px 15px;
    border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
    transition: background-color 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-weight: 500;
}

.save-btn {
    background-color: #4CAF50;
}

.save-btn:hover {
    background-color: #45a049;
}

.cancel-btn {
    background-color: #f44336;
}

.cancel-btn:hover {
    background-color: #d32f2f;
}
</style>

</body>
</html>
<?php $conn->close(); ?>
