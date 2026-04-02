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
  SELECT
    SUM(CASE WHEN status = 'assigned' OR status IS NULL OR status = '' THEN 1 ELSE 0 END) AS assigned,
    SUM(CASE WHEN status = 'started' THEN 1 ELSE 0 END) AS started,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed
  FROM user_workouts
";

$result = $conn->query($sql);

if (!$result) {
  respond(["ok" => false, "message" => "Query failed", "error" => $conn->error], 500);
}

$row = $result->fetch_assoc();

$summary = [
  "assigned"  => (int)($row["assigned"] ?? 0),
  "started"   => (int)($row["started"] ?? 0),
  "completed" => (int)($row["completed"] ?? 0),
];

respond(["ok" => true, "summary" => $summary]);