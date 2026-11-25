<?php
session_start();

if (!isset($_SESSION["Uid"])) {
    http_response_code(401);
    echo "unauthorized";
    exit();
}

require_once __DIR__ . '/db.php';

$month = $_POST["month"] ?? "";
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    echo "missing";
    exit();
}

$stmt = $conn->prepare("SELECT Bid FROM Budget WHERE Uid = ? AND Month = ?");
$stmt->bind_param("is", $_SESSION["Uid"], $month);
$stmt->execute();
$result = $stmt->get_result();

echo $result->num_rows > 0 ? "exists" : "missing";
$stmt->close();
$conn->close();
