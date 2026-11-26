<?php
/** AJAX: permanently delete the logged-in user's account (expenses, budgets etc. cascade). */
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$uid = current_user_id();
if ($uid === null) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'User not logged in.']));
}

$input = json_decode((string) file_get_contents('php://input'), true) ?: [];
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($input['delete'])) {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Invalid request.']));
}
csrf_verify((string) ($input['csrf_token'] ?? ''));

if (is_admin()) {
    // Never let the system end up without an administrator.
    $count = (int) $conn->query("SELECT COUNT(*) AS n FROM User WHERE Role = 'admin'")->fetch_assoc()['n'];
    if ($count <= 1) {
        exit(json_encode(['success' => false, 'message' => 'You are the last administrator and cannot delete this account.']));
    }
}

$stmt = $conn->prepare('DELETE FROM User WHERE Uid = ?');
$stmt->bind_param('i', $uid);
$stmt->execute();
$stmt->close();

$_SESSION = [];
session_destroy();
echo json_encode(['success' => true]);
