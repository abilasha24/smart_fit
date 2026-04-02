<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/db.php";

function respond($arr, $code = 200){
  http_response_code($code);
  echo json_encode($arr);
  exit;
}

$user_id = (int)($_SESSION["user_id"] ?? 0);
$role    = strtolower(trim($_SESSION["role"] ?? ""));

if ($user_id <= 0 || $role !== "admin") {
  respond(["ok" => false, "message" => "Unauthorized"], 401);
}

$sql = "
  SELECT f.id, f.user_id, f.subject, f.message, f.rating, f.created_at, f.is_read,
         u.first_name, u.last_name, u.email
  FROM feedback f
  LEFT JOIN users u ON u.id = f.user_id
  ORDER BY f.created_at DESC, f.id DESC
  LIMIT 5
";

$result = $conn->query($sql);

if (!$result) {
  respond(["ok" => false, "message" => "Query failed", "error" => $conn->error], 500);
}

$items = [];
while ($row = $result->fetch_assoc()) {
  $items[] = $row;
}

respond(["ok" => true, "items" => $items]);