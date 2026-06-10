<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/db.php";

function respond($arr, $code=200){
  http_response_code($code);
  echo json_encode($arr);
  exit;
}

$role = strtolower(trim($_SESSION["role"] ?? ""));
$user_id = (int)($_SESSION["user_id"] ?? 0);

if ($user_id <= 0 || $role !== "member") {
  respond(["ok"=>false, "message"=>"Login required"], 401);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  respond(["ok"=>false, "message"=>"Method not allowed"], 405);
}

$id = (int)($_POST["id"] ?? 0);
$status = strtolower(trim($_POST["status"] ?? ""));

if ($id <= 0 || !in_array($status, ["started","completed"], true)) {
  respond(["ok"=>false, "message"=>"Invalid data"], 400);
}

// check ownership
$stmt = $conn->prepare("SELECT id FROM user_workouts WHERE id=? AND user_id=? LIMIT 1");
$stmt->bind_param("ii", $id, $user_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) respond(["ok"=>false, "message"=>"Workout not found"], 404);

if ($status === "started") {
  $stmt = $conn->prepare("
    UPDATE user_workouts
    SET status='started',
        started_at = COALESCE(started_at, NOW())
    WHERE id=? AND user_id=?
  ");
  $stmt->bind_param("ii", $id, $user_id);
  $ok = $stmt->execute();
  $stmt->close();
  respond(["ok"=>$ok]);
}

if ($status === "completed") {
  $stmt = $conn->prepare("
    UPDATE user_workouts
    SET status='completed',
        started_at = COALESCE(started_at, NOW()),
        completed_at = COALESCE(completed_at, NOW())
    WHERE id=? AND user_id=?
  ");
  $stmt->bind_param("ii", $id, $user_id);
  $ok = $stmt->execute();
  $stmt->close();
  respond(["ok"=>$ok]);
}
