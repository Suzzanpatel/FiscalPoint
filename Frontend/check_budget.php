<?php
session_start();

if (!isset($_SESSION["Uid"])) {
    http_response_code(401);
    echo "unauthorized";
    exit();
}

$conn = new mysqli("localhost", "root", "", "FiscalPoint");
if ($conn->connect_error) {
    http_response_code(500);
    echo "error";
    exit();
}

$month = $_POST["month"] ?? "";
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo "missing";
    exit();
}

$monthName = date("F", strtotime($month . "-01"));
$stmt = $conn->prepare("SELECT Bid FROM Budget WHERE Uid = ? AND Month = ?");
$stmt->bind_param("is", $_SESSION["Uid"], $monthName);
$stmt->execute();
$result = $stmt->get_result();

echo $result->num_rows > 0 ? "exists" : "missing";
$stmt->close();
$conn->close();
