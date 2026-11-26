<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$adminId = require_admin();
if (is_post()) {
    // Rename a category across all users' expenses.
    if (isset($_POST['update_category'])) {
        $updated_category = trim(strip_tags((string) ($_POST['updated_category'] ?? '')));
        $original_category = (string) ($_POST['original_category'] ?? '');

        if ($updated_category === '' || mb_strlen($updated_category) > 60) {
            alert_redirect('Category names must be 1-60 characters.', 'admin_category.php');
        }

        $stmt_check = $conn->prepare('SELECT COUNT(*) AS n FROM Expense WHERE category = ? AND category <> ?');
        $stmt_check->bind_param('ss', $updated_category, $original_category);
        $stmt_check->execute();
        $count = (int) $stmt_check->get_result()->fetch_assoc()['n'];
        $stmt_check->close();

        if ($count > 0) {
            alert_redirect('Category name already exists. Please choose a different name.', 'admin_category.php');
        }
        $stmt_update = $conn->prepare('UPDATE Expense SET category = ? WHERE category = ?');
        $stmt_update->bind_param('ss', $updated_category, $original_category);
        $stmt_update->execute();
        $stmt_update->close();
        alert_redirect('Category updated successfully.', 'admin_category.php');
    }

    // "Delete" a category: its expenses are moved to Miscellaneous (no spending data is lost).
    if (isset($_POST['delete_id'])) {
        $delete_category = (string) $_POST['delete_id'];
        if ($delete_category === 'Miscellaneous') {
            alert_redirect('Miscellaneous is the fallback category and cannot be deleted.', 'admin_category.php');
        }
        $stmt_delete = $conn->prepare("UPDATE Expense SET category = 'Miscellaneous' WHERE category = ?");
        $stmt_delete->bind_param('s', $delete_category);
        $stmt_delete->execute();
        $stmt_delete->close();
        alert_redirect('Category deleted; its expenses were moved to Miscellaneous.', 'admin_category.php');
    }
}

// Fetch unique categories
$sql = "SELECT category, MIN(date) AS CreatedDate FROM Expense WHERE category IS NOT NULL GROUP BY category ORDER BY CreatedDate DESC";
$result_expense = $conn->query($sql);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Category</title>
    <link rel="stylesheet" href="<?= e(asset('css/admin_category.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
    <style>
        .form-inline input[type="text"] {
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .form-inline input[type="text"]:focus {
            border-color: #4CAF50;
            box-shadow: 0 0 5px rgba(76,175,80,0.5);
            outline: none;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .action-buttons .edit-btn,
        .action-buttons .delete-btn,
        .form-inline .edit-btn,
        .form-inline .delete-btn {
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            transition: background-color 0.3s ease;
            display: inline-block;
            font-weight: 500;
        }
        .action-buttons .edit-btn,
        .form-inline .edit-btn {
            background-color: #4CAF50;
        }
        .action-buttons .edit-btn:hover,
        .form-inline .edit-btn:hover {
            background-color: #45a049;
        }
        .action-buttons .delete-btn,
        .form-inline .delete-btn {
            background-color: #f44336;
        }
        .action-buttons .delete-btn:hover,
        .form-inline .delete-btn:hover {
            background-color: #d32f2f;
        }
        .action-buttons form {
            display: inline;
            margin: 0;
        }
    </style>
</head>
<body>
<header>
    <img src="<?= e(asset('img/logo.png')) ?>" alt="Logo" class="logo" onclick="location.href='<?= e(url('landing.html')) ?>'">
</header>

<?php include __DIR__ . '/../includes/sidebar_admin.php'; ?>

<div>
    <h2 style="text-align:center;">Manage Category</h2>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Sr No.</th>
                    <th>Category Name</th>
                    <th>Created Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
<?php
$editCategory = isset($_GET['edit']) ? (string) $_GET['edit'] : null;
if ($result_expense->num_rows > 0) {
    $sr_no = 1;
    while ($row = $result_expense->fetch_assoc()) {
        $name = (string) $row["category"];
        echo "<tr>";
        echo "<td>{$sr_no}</td>";

        if ($editCategory !== null && $editCategory === $name) {
            echo "<td colspan='3'>
                    <form method='POST' action='admin_category.php' class='form-inline' style='display: flex; align-items: center; gap: 10px; width: 100%;'>
                        " . csrf_field() . "
                        <input type='text' name='updated_category' value='" . e($name) . "' maxlength='60'
                               style='width: 60%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;' required>
                        <input type='hidden' name='original_category' value='" . e($name) . "'>
                        <button type='submit' name='update_category' class='edit-btn'>Save</button>
                        <a href='admin_category.php' class='delete-btn'>Cancel</a>
                    </form>
                  </td>";
        } else {
            echo "<td>" . e($name) . "</td>";
            echo "<td>" . e(date("d-m-Y", strtotime($row["CreatedDate"]))) . "</td>";
            echo "<td class='action-buttons'>
                    <a href='admin_category.php?edit=" . e(urlencode($name)) . "' class='edit-btn'>Edit</a>
                    <form method='POST' action='admin_category.php' style='display:inline;' onsubmit='return confirm(\"Delete this category? Its expenses will be moved to Miscellaneous.\");'>
                        " . csrf_field() . "
                        <input type='hidden' name='delete_id' value='" . e($name) . "'>
                        <button type='submit' class='delete-btn'>Delete</button>
                    </form>
                  </td>";
        }

        echo "</tr>";
        $sr_no++;
    }
} else {
    echo "<tr><td colspan='4'>No categories found.</td></tr>";
}
?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
