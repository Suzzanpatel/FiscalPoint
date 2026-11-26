<?php
/**
 * AJAX proxy: category suggestions for the add-expense form.
 * Request:  POST JSON {description, amount?, payment_method?} with X-CSRF-Token header
 * Response: {"suggestions": [{"category": "Food", "probability": 0.93}, ...]}
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ml_client.php';

header('Content-Type: application/json; charset=utf-8');

if (current_user_id() === null) {
    http_response_code(401);
    exit(json_encode(['suggestions' => [], 'error' => 'Not logged in.']));
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit(json_encode(['suggestions' => []]));
}
csrf_verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

$input = json_decode((string) file_get_contents('php://input'), true) ?: [];
$description = trim((string) ($input['description'] ?? ''));
if (mb_strlen($description) < 2) {
    exit(json_encode(['suggestions' => []]));
}
$amount = isset($input['amount']) && is_numeric($input['amount']) ? (float) $input['amount'] : null;

$result = ml_request('/v1/categorize', [
    'description' => mb_substr($description, 0, 255),
    'amount' => $amount,
    'payment_method' => (string) ($input['payment_method'] ?? ''),
    'top_k' => 3,
], 3);

// The form works without suggestions, so failures degrade silently to "no suggestions".
echo json_encode(['suggestions' => $result['ok'] ? ($result['data']['suggestions'] ?? []) : []]);
