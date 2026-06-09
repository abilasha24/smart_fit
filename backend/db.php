<?php
$host = getenv('MYSQLHOST')     ?: 'localhost';
$user = getenv('MYSQLUSER')     ?: 'root';
$pass = getenv('MYSQLPASSWORD') ?: '';
$db   = getenv('MYSQLDATABASE') ?: 'smart_fit';
$port = (int)(getenv('MYSQLPORT') ?: 3306);

$conn = new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_error) {
  http_response_code(500);
  die(json_encode([
    "status" => "error",
    "message" => "DB connection failed: " . $conn->connect_error
  ]));
}

$conn->set_charset("utf8mb4");
?>