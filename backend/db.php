<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "smart_fit";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
  http_response_code(500);
  die(json_encode([
    "status" => "error",
    "message" => "DB connection failed"
  ]));
}

$conn->set_charset("utf8mb4");
?>