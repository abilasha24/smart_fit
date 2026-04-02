<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/db.php";

// Member login guard
$user_id = (int)($_SESSION["user_id"] ?? 0);
$role = strtolower(trim($_SESSION["role"] ?? ""));
if ($user_id <= 0 || $role !== "member") {
  http_response_code(401);
  echo json_encode(["ok"=>false, "message"=>"Login required"]);
  exit;
}

// Helper: allowed plan types based on user's plan
function allowedPlans(string $plan): array {
  $plan = strtolower(trim($plan));
  if ($plan === "pro") return ["basic", "premium", "pro"];
  if ($plan === "premium") return ["basic", "premium"];
  return ["basic"]; // default
}

try {
  // 1) Get user plan (உங்க users table-ல plan column இருக்கு)
  $stmt = $conn->prepare("SELECT plan FROM users WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $plan = $user["plan"] ?? "basic";

  $allowed = allowedPlans($plan);

  // 2) Dynamic IN (?, ?, ?)
  $placeholders = implode(",", array_fill(0, count($allowed), "?"));
  $sql = "SELECT id, title, level, duration_min, calories, youtube_url, plan_type
          FROM workouts
          WHERE plan_type IN ($placeholders)
          ORDER BY id DESC";

  $stmt2 = $conn->prepare($sql);

  // bind params dynamically
  // all are strings, count = n
  $types = str_repeat("s", count($allowed));
  $stmt2->bind_param($types, ...$allowed);

  $stmt2->execute();
  $res = $stmt2->get_result();

  $items = [];
  while ($row = $res->fetch_assoc()) $items[] = $row;

  echo json_encode([
    "ok" => true,
    "user_plan" => $plan,
    "allowed_plan_types" => $allowed,
    "workouts" => $items
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok"=>false, "message"=>"Server error"]);
}