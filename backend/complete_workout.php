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
  echo json_encode(["ok"=>false,"message"=>"workout_id required"]);
  exit;
}

/* ================================
   ✅ PLAN GATING (MUST)
   basic  -> only basic
   premium-> basic + premium
   pro    -> all
================================ */

// A) fetch user plan
$stmt = $conn->prepare("SELECT plan FROM users WHERE id=? LIMIT 1");
if(!$stmt){
  http_response_code(500);
  echo json_encode(["ok"=>false,"message"=>"Prepare failed: ".$conn->error]);
  exit;
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
$stmt->close();

$user_plan = strtolower($u["plan"] ?? "basic");
if (!in_array($user_plan, ["basic","premium","pro"], true)) $user_plan = "basic";

// B) fetch workout plan_type
// ⚠️ If your workouts column is plan_code, change plan_type -> plan_code here
$stmt2 = $conn->prepare("SELECT plan_type FROM workouts WHERE id=? LIMIT 1");
if(!$stmt2){
  http_response_code(500);
  echo json_encode(["ok"=>false,"message"=>"Prepare failed: ".$conn->error]);
  exit;
}
$stmt2->bind_param("i", $workout_id);
$stmt2->execute();
$w = $stmt2->get_result()->fetch_assoc();
$stmt2->close();

if (!$w) {
  http_response_code(404);
  echo json_encode(["ok"=>false,"message"=>"Workout not found"]);
  exit;
}

$workout_plan = strtolower($w["plan_type"] ?? "basic");
if (!in_array($workout_plan, ["basic","premium","pro"], true)) $workout_plan = "basic";

// C) allowed list
$allowed = ["basic"];
if ($user_plan === "premium") $allowed = ["basic","premium"];
if ($user_plan === "pro") $allowed = ["basic","premium","pro"];

// D) block if not allowed
if (!in_array($workout_plan, $allowed, true)) {
  http_response_code(403);
  echo json_encode([
    "ok"=>false,
    "message"=>"Your plan ($user_plan) cannot complete this workout ($workout_plan). Upgrade required."
  ]);
  exit;
}

/* ================================
   ✅ EXISTING COMPLETE LOGIC
================================ */

// Find latest record for this user+workout
$check = $conn->prepare("
  SELECT id, status
  FROM user_workouts
  WHERE user_id=? AND workout_id=?
  ORDER BY id DESC
  LIMIT 1
");
if(!$check){
  http_response_code(500);
  echo json_encode(["ok"=>false,"message"=>"Prepare failed: ".$conn->error]);
  exit;
}

$check->bind_param("ii", $user_id, $workout_id);
$check->execute();
$res = $check->get_result();
$row = $res ? $res->fetch_assoc() : null;
$check->close();

if ($row) {
  $id = (int)$row["id"];

  $upd = $conn->prepare("
    UPDATE user_workouts
    SET status='completed',
        completed_at = NOW(),
        started_at = COALESCE(started_at, NOW())
    WHERE id=?
  ");
  if(!$upd){
    http_response_code(500);
    echo json_encode(["ok"=>false,"message"=>"Prepare failed: ".$conn->error]);
    exit;
  }

  $upd->bind_param("i", $id);
  $ok = $upd->execute();
  $upd->close();

  if(!$ok){
    http_response_code(500);
    echo json_encode(["ok"=>false,"message"=>"Update failed"]);
    exit;
  }

  echo json_encode([
    "ok"=>true,
    "message"=>"Completed (updated)",
    "workout_id"=>$workout_id,
    "user_plan"=>$user_plan
  ]);
  exit;
}

// If no row exists, insert completed record directly
$ins = $conn->prepare("
  INSERT INTO user_workouts (user_id, workout_id, status, started_at, completed_at, created_at)
  VALUES (?, ?, 'completed', NOW(), NOW(), NOW())
");
if(!$ins){
  http_response_code(500);
  echo json_encode(["ok"=>false,"message"=>"Prepare failed: ".$conn->error]);
  exit;
}

$ins->bind_param("ii", $user_id, $workout_id);
$ok = $ins->execute();
$ins->close();

if(!$ok){
  http_response_code(500);
  echo json_encode(["ok"=>false,"message"=>"Insert failed"]);
  exit;
}

echo json_encode([
  "ok"=>true,
  "message"=>"Completed (inserted)",
  "workout_id"=>$workout_id,
  "user_plan"=>$user_plan
]);
