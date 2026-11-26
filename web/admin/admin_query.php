<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$adminId = require_admin();
if (is_post() && isset($_POST['qid'], $_POST['status'])) {
    $qid = (int) $_POST['qid'];
    $status = ($_POST['status'] === 'Solved') ? 'Solved' : 'Pending';
    $admin_response = trim((string) ($_POST['admin_response'] ?? ''));

    if ($admin_response !== '') {
        $update = $conn->prepare('UPDATE Query SET Status = ?, Admin_Response = ?, Response_Date = NOW() WHERE Qid = ?');
        $update->bind_param('ssi', $status, $admin_response, $qid);
    } else {
        // Status-only change: keep any existing response.
        $update = $conn->prepare('UPDATE Query SET Status = ? WHERE Qid = ?');
        $update->bind_param('si', $status, $qid);
    }
    $update->execute();
    $update->close();
    alert_redirect('Query updated successfully.', 'admin_query.php');
}

$sql = "SELECT Qid, Email, Query_type, Description, Created_At, Status, Admin_Response, Response_Date FROM Query ORDER BY Created_At DESC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Queries</title>
    <link rel="stylesheet" href="<?= e(asset('css/admin_registered_users.css')) ?>"> <!-- Use the same CSS -->
    <link rel="stylesheet" href="<?= e(asset('vendor/fontawesome/css/all.min.css')) ?>">
</head>
<body>
<header>
    <img src="<?= e(asset('img/logo.png')) ?>" alt="Logo" class="logo" onclick="location.href='<?= e(url('landing.html')) ?>'">
</header>

<?php include __DIR__ . '/../includes/sidebar_admin.php'; ?>
    <div>
    <h1 style="text-align:center;">User Queries</h1>
    <div class="table-container">
        <table class="query-table">
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Query Type</th>
                    <th>Description</th>
                    <th>Submitted At</th>
                    <th>Status</th>
                    <th>Admin Response</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        echo "<tr>
                            <td>" . htmlspecialchars($row['Email']) . "</td>
                            <td>" . htmlspecialchars($row['Query_type']) . "</td>
                            <td>" . htmlspecialchars($row['Description']) . "</td>
                            <td>" . e(date('d-m-Y H:i', strtotime($row['Created_At']))) . "</td>
                            <td>
                                <form method='POST' action='admin_query.php' class='status-form'>
                                    " . csrf_field() . "
                                    <input type='hidden' name='qid' value='" . (int) $row['Qid'] . "'>
                                    <select name='status' class='status-select' onchange='this.form.submit()'>
                                        <option value='Pending'" . ($row['Status'] == 'Pending' ? ' selected' : '') . ">Pending</option>
                                        <option value='Solved'" . ($row['Status'] == 'Solved' ? ' selected' : '') . ">Solved</option>
                                    </select>
                                </form>
                            </td>
                            <td>" . (!empty($row['Admin_Response']) ? 
                                htmlspecialchars($row['Admin_Response']) . 
                                "<br><small>Responded: " . e(date('d-m-Y H:i', strtotime((string) $row['Response_Date']))) . "</small>" 
                                : "-") . 
                            "</td>
                            <td class='action-buttons'>
                                <button onclick='openResponseModal(" . (int) $row['Qid'] . ")' class='edit-btn'>Respond</button>
                            </td>
                          </tr>";
                    }
                } else {
                    echo "<tr><td colspan='7'>No queries submitted yet.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>
    </div>
</div>

<!-- Add Modal for Response -->
<div id="responseModal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h2>Respond to Query</h2>
        <form method="POST" action="admin_query.php" id="responseForm">
            <?= csrf_field() ?>
            <input type="hidden" name="qid" id="modalQid">
            <div class="form-group">
                <label for="admin_response">Your Response:</label>
                <textarea name="admin_response" id="admin_response" required></textarea>
            </div>
            <div class="form-group">
                <label for="status">Status:</label>
                <select name="status" id="status">
                    <option value="Pending">Pending</option>
                    <option value="Solved">Solved</option>
                </select>
            </div>
            <button type="submit" class="edit-btn">Submit Response</button>
        </form>
    </div>
</div>

<style>
/* Existing styles remain... */

.modal {
    display: none;
    position: fixed;
    z-index: 1;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.4);
}

.modal-content {
    background-color: #86a69c;
    margin: 15% auto;
    padding: 20px;
    border-radius: 8px;
    width: 50%;
    color: white;
}

.close {
    color: white;
    float: right;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
}

.form-group textarea {
    width: 100%;
    padding: 8px;
    border-radius: 4px;
    border: 1px solid #ddd;
    min-height: 100px;
}

.status-select {
    padding: 5px;
    border-radius: 4px;
    border: 1px solid #ddd;
}

.action-buttons {
    display: flex;
    gap: 10px;
    justify-content: center;
}

.edit-btn, .delete-btn, button[type="submit"] {
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

.edit-btn, button[type="submit"] {
    background-color: #4CAF50;
}

.edit-btn:hover, button[type="submit"]:hover {
    background-color: #45a049;
}

.delete-btn {
    background-color: #f44336;
}

.delete-btn:hover {
    background-color: #d32f2f;
}
</style>

<script>
const modal = document.getElementById('responseModal');
const span = document.getElementsByClassName('close')[0];
const responseForm = document.getElementById('responseForm');
const modalQid = document.getElementById('modalQid');

function openResponseModal(qid) {
    modalQid.value = qid;
    modal.style.display = "block";
}

span.onclick = function() {
    modal.style.display = "none";
}

window.onclick = function(event) {
    if (event.target == modal) {
        modal.style.display = "none";
    }
}

// Auto-submit status changes
document.querySelectorAll('.status-select').forEach(select => {
    select.addEventListener('change', function() {
        this.closest('form').submit();
    });
});
</script>

</body>
</html>