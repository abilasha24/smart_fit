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
  SELECT category, COUNT(*) AS total
  FROM bmi_records
  GROUP BY category
";

$result = $conn->query($sql);

if (!$result) {
  respond(["ok" => false, "message" => "Query failed", "error" => $conn->error], 500);
}

$summary = [
  "normal"      => 0,
  "overweight"  => 0,
  "underweight" => 0,
  "obese"       => 0
];

while ($row = $result->fetch_assoc()) {
  $cat = strtolower(trim($row["category"] ?? ""));
  $count = (int)($row["total"] ?? 0);

  if ($cat === "normal") $summary["normal"] = $count;
  else if ($cat === "overweight") $summary["overweight"] = $count;
  else if ($cat === "underweight") $summary["underweight"] = $count;
  else if ($cat === "obese") $summary["obese"] = $count;
}

respond(["ok" => true, "summary" => $summary]);
