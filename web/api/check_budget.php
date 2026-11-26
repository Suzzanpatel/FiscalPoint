<?php
/** AJAX: does a budget already exist for the given month? Responds "exists" or "missing". */
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
if (current_user_id() === null) {
    http_response_code(401);
    exit('unauthorized');
}
if (!is_post()) {
    http_response_code(405);
    exit('method not allowed');
}

$month = (string) ($_POST['month'] ?? '');
if (!valid_month($month)) {
    exit('missing');
}

$uid = current_user_id();
$stmt = $conn->prepare('SELECT 1 FROM Budget WHERE Uid = ? AND Month = ?');
$stmt->bind_param('is', $uid, $month);
$stmt->execute();
echo $stmt->get_result()->num_rows > 0 ? 'exists' : 'missing';
$stmt->close();
