<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$adminId = require_admin();
// Handle delete (CSRF-protected; an admin cannot delete themselves or the last admin)
if (is_post() && isset($_POST["delete_id"])) {
    $delete_uid = (int) $_POST["delete_id"];
    if ($delete_uid === $adminId) {
        alert_redirect('You cannot delete your own account from here.', 'admin_registered_users.php');
    }
    $stmt = $conn->prepare("SELECT Role FROM User WHERE Uid = ?");
    $stmt->bind_param("i", $delete_uid);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($target && $target['Role'] === 'admin') {
        $admins = (int) $conn->query("SELECT COUNT(*) AS n FROM User WHERE Role = 'admin'")->fetch_assoc()['n'];
        if ($admins <= 1) {
            alert_redirect('The last administrator cannot be deleted.', 'admin_registered_users.php');
        }
    }
    $stmt = $conn->prepare("DELETE FROM User WHERE Uid = ?");
    $stmt->bind_param("i", $delete_uid);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    alert_redirect($ok ? 'User deleted successfully.' : 'Failed to delete user.', 'admin_registered_users.php');
}

// Fetch all users with correct column names
$sql = "SELECT Uid, Uname, email, Phone_no, Created_At FROM User WHERE Role = 'user' ORDER BY Created_At DESC";
$result_users = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Registered Users</title>
    <link rel="stylesheet" href="<?= e(asset('css/admin_registered_users.css')) ?>"> <!-- Use the same CSS -->
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
</head>
<body>
<header>
    <img src="<?= e(asset('img/logo.png')) ?>" alt="Logo" class="logo" onclick="location.href='<?= e(url('landing.html')) ?>'">
</header>

<?php include __DIR__ . '/../includes/sidebar_admin.php'; ?>

<div>
 
    <h2 style="text-align:center;">Registered Users</h2>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Sr No.</th>
                    <th>User Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Register Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
<?php
if ($result_users->num_rows > 0) {
    $sr_no = 1;
    while ($row = $result_users->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$sr_no}</td>";
        echo "<td>" . htmlspecialchars($row["Uname"]) . "</td>";
        echo "<td>" . htmlspecialchars($row["email"]) . "</td>";
        echo "<td>" . htmlspecialchars($row["Phone_no"]) . "</td>";
        echo "<td>" . e(date("d-m-Y", strtotime($row["Created_At"]))) . "</td>";
        echo "<td class='action-buttons'>
                <a href='edit_user.php?uid=" . (int) $row["Uid"] . "&amp;from=admin_registered_users' class='edit-btn'>Edit</a>
                <form method='POST' style='display:inline;' onsubmit='return confirm(\"Are you sure you want to delete this user?\");'>
                    " . csrf_field() . "<input type='hidden' name='delete_id' value='" . (int) $row["Uid"] . "'>
                    <button type='submit' class='delete-btn'>Delete</button>
                </form>
                <a href='expense_details.php?uid=" . (int) $row["Uid"] . "' class='edit-btn'>Expense Details</a>
              </td>";
        echo "</tr>";
        $sr_no++;
    }
} else {
    echo "<tr><td colspan='6'>No registered users found.</td></tr>";
}
?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>

<?php $conn->close(); ?>
