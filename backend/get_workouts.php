<?php
header("Content-Type: application/json; charset=UTF-8");
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . "/db.php";
error_reporting(0); ini_set('display_errors', 0);

/**
 * Accept both styles:
 * - $_SESSION['user_id'] + $_SESSION['role']
 * - $_SESSION['logged_in'] flag
 */
$uid  = (int)($_SESSION["user_id"] ?? 0);
$role = strtolower(trim($_SESSION["role"] ?? ""));

if ($uid <= 0 || $role !== "trainer") {
  http_response_code(401);
  echo json_encode(["ok"=>false, "message"=>"Login required"]);
  exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
  http_response_code(500);
  echo json_encode(["ok"=>false, "message"=>"DB connection missing"]);
  exit;
}

/**
 * If you want only ACTIVE workouts, add WHERE status='active' etc.
 * For now, return all workouts so dropdown always has data.
 */
$sql = "SELECT id, title, level, duration_min, calories, youtube_url, plan_type
        FROM workouts
        ORDER BY id DESC";

$res = $conn->query($sql);
if (!$res) {
  http_response_code(500);
  echo json_encode(["ok"=>false, "message"=>"Query failed"]);
  exit;
}

$workouts = [];
while ($row = $res->fetch_assoc()) $workouts[] = $row;

echo json_encode(["ok"=>true, "workouts"=>$workouts]);
exit;
