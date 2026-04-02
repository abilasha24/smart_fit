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
  SELECT id, user_id, user_email, plan, billing_cycle, payment_method, amount, created_at
  FROM payments
  ORDER BY created_at DESC, id DESC
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