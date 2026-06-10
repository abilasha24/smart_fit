<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");

if (empty($_SESSION["logged_in"]) || ($_SESSION["role"] ?? "") !== "member") {
  http_response_code(403);
  echo json_encode(["ok"=>false,"message"=>"Forbidden"]);
  exit;
}

require_once __DIR__ . "/db.php";

$user_id = (int)($_SESSION["user_id"] ?? 0);
$workout_id = (int)($_POST["workout_id"] ?? 0);

if ($user_id <= 0 || $workout_id <= 0) {
  http_response_code(400);
  echo json_encode(["ok"=>false,"message"=>"Missing user_id/workout_id"]);
  exit;
}

/* STEP A: user plan எடு */
$stmt = $conn->prepare("SELECT plan FROM users WHERE id=? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
$user_plan = strtolower($u["plan"] ?? "basic");

/* STEP B: workout plan_type எடு */
$stmt2 = $conn->prepare("SELECT plan_type FROM workouts WHERE id=? LIMIT 1");
$stmt2->bind_param("i", $workout_id);
$stmt2->execute();
$w = $stmt2->get_result()->fetch_assoc();

if (!$w) {
  http_response_code(404);
  echo json_encode(["ok"=>false,"message"=>"Workout not found"]);
  exit;
}
$workout_plan = strtolower($w["plan_type"] ?? "basic");

/* STEP C: allowed rules */
$allowed = ["basic"];
if ($user_plan === "premium") $allowed = ["basic","premium"];
if ($user_plan === "pro") $allowed = ["basic","premium","pro"];

/* STEP D: block if not allowed */
if (!in_array($workout_plan, $allowed, true)) {
  http_response_code(403);
  echo json_encode([
    "ok"=>false,
    "message"=>"Your plan ($user_plan) cannot start this workout ($workout_plan). Upgrade required."
  ]);
  exit;
}

/* STEP E: Start save */
$sql = "
  INSERT INTO user_workouts (user_id, workout_id, status, started_at, created_at)
  VALUES (?, ?, 'started', NOW(), NOW())
  ON DUPLICATE KEY UPDATE
    status = 'started',
    started_at = COALESCE(started_at, NOW())
";
$stmt3 = $conn->prepare($sql);
$stmt3->bind_param("ii", $user_id, $workout_id);

if(!$stmt3->execute()){
  http_response_code(500);
  echo json_encode(["ok"=>false,"message"=>"Execute failed: ".$stmt3->error]);
  exit;
}

echo json_encode(["ok"=>true,"message"=>"Started", "workout_id"=>$workout_id, "user_plan"=>$user_plan]);
