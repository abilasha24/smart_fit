<?php
// backend/member_add_self_workout.php
header("Content-Type: application/json; charset=UTF-8");
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/db.php";

function respond($arr, $code=200){
  http_response_code($code);
  echo json_encode($arr);
  exit;
}

$user_id = (int)($_SESSION["user_id"] ?? 0);
$role = strtolower(trim($_SESSION["role"] ?? ""));

if ($user_id <= 0 || $role !== "member") {
  respond(["ok"=>false,"message"=>"Login required"], 401);
}

$input = json_decode(file_get_contents("php://input"), true);
$workout_id = (int)($input["workout_id"] ?? 0);
$status     = strtolower(trim($input["status"] ?? "assigned"));
if (!in_array($status, ["assigned","started","completed"], true)) $status = "assigned";

if ($workout_id <= 0) {
  respond(["ok"=>false,"message"=>"Missing workout_id"], 400);
}

// self workout-க்கு trainer_id NULL
$trainer_id = null;

// status-க்கு match ஆக started_at / completed_at set
$started_at   = null;
$completed_at = null;
if ($status === "started")   $started_at = date("Y-m-d H:i:s");
if ($status === "completed") { $started_at = date("Y-m-d H:i:s"); $completed_at = date("Y-m-d H:i:s"); }

$stmt = $conn->prepare("
  INSERT INTO user_workouts
    (user_id, trainer_id, workout_id, status, assigned_at, started_at, completed_at, source, created_at)
  VALUES
    (?, ?, ?, ?, NULL, ?, ?, 'self', NOW())
");

if(!$stmt) respond(["ok"=>false,"message"=>"Prepare failed","err"=>$conn->error], 500);

// trainer_id NULL bind பண்ண "i" use பண்ண முடியாது; workaround: set to NULL with bind_param + mysqli_stmt::bind_param doesn't allow null int cleanly
// so we use set to NULL via SQL using NULL literal by separate query:
$stmt->close();

$stmt = $conn->prepare("
  INSERT INTO user_workouts
    (user_id, trainer_id, workout_id, status, assigned_at, started_at, completed_at, source, created_at)
  VALUES
    (?, NULL, ?, ?, NULL, ?, ?, 'self', NOW())
");
if(!$stmt) respond(["ok"=>false,"message"=>"Prepare failed","err"=>$conn->error], 500);

$stmt->bind_param("issss", $user_id, $workout_id, $status, $started_at, $completed_at);

if(!$stmt->execute()){
  respond(["ok"=>false,"message"=>"Execute failed","err"=>$stmt->error], 500);
}

respond(["ok"=>true, "id"=>(int)$conn->insert_id]);