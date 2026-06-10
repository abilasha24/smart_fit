<?php
header("Content-Type: application/json");
session_start();
require_once __DIR__ . "/db.php";

try {

  // 1️⃣ Logged-in user id
  $user_id = $_SESSION['user_id'] ?? 0;

  if ($user_id <= 0) {
    http_response_code(401);
    echo json_encode(["ok" => false, "message" => "Login required"]);
    exit;
  }

  // 2️⃣ Get member plan
  $stmt = $conn->prepare("SELECT plan FROM users WHERE id = ?");
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $result = $stmt->get_result();
  $user = $result->fetch_assoc();

  if (!$user) {
    echo json_encode(["ok" => false, "message" => "User not found"]);
    exit;
  }

  $plan = $user['plan'];

  // 3️⃣ Plan-based filtering
  if ($plan === 'basic') {
      $sql = "SELECT id, title, level, duration_min, calories, youtube_url
              FROM workouts
              WHERE plan_type = 'basic'
              ORDER BY id DESC";
  }
  elseif ($plan === 'premium') {
      $sql = "SELECT id, title, level, duration_min, calories, youtube_url
              FROM workouts
              WHERE plan_type IN ('basic','premium')
              ORDER BY id DESC";
  }
  else { // pro
      $sql = "SELECT id, title, level, duration_min, calories, youtube_url
              FROM workouts
              ORDER BY id DESC";
  }

  $res = $conn->query($sql);

  $items = [];
  while ($row = $res->fetch_assoc()) {
    $items[] = $row;
  }

  echo json_encode(["ok" => true, "workouts" => $items]);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["ok" => false, "message" => "Server error"]);
}
