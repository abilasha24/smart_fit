<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");

if (empty($_SESSION["logged_in"]) || (($_SESSION["role"] ?? "") !== "member")) {
  http_response_code(401);
  echo json_encode(["ok"=>false,"message"=>"Login required"]);
  exit;
}

require_once __DIR__ . "/db.php";
$user_id = (int)($_SESSION["user_id"] ?? 0);

$sql = "
SELECT
  uw.id,
  uw.workout_id,
  uw.status,
  uw.assigned_at,
  uw.started_at,
  uw.completed_at,
  w.title,
  w.level,
  w.duration_min,
  w.calories,
  w.youtube_url
FROM user_workouts uw
JOIN workouts w ON w.id = uw.workout_id
WHERE uw.user_id = ?
  AND uw.source = 'self'
ORDER BY uw.id DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

$list = [];
while($row = $res->fetch_assoc()){
  $list[] = $row;
}

echo json_encode(["ok"=>true, "workouts"=>$list]);