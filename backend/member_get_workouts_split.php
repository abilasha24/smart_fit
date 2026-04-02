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
  respond(["ok"=>false,"message"=>"Login required"], 401);
}

$srcExpr = "CASE
  WHEN uw.source IS NULL OR TRIM(uw.source)='' THEN 'trainer'
  ELSE LOWER(TRIM(uw.source))
END";

$q = "
  SELECT
    uw.id,
    uw.workout_id,
    uw.status,
    uw.created_at,
    uw.started_at,
    uw.completed_at,
    uw.trainer_id,
    uw.assigned_at,
    $srcExpr AS source,
    w.title, w.level, w.duration_min, w.calories, w.youtube_url
  FROM user_workouts uw
  JOIN workouts w ON w.id = uw.workout_id
  WHERE uw.user_id=?
  ORDER BY uw.id DESC
";

$stmt = $conn->prepare($q);
if(!$stmt) respond(["ok"=>false,"message"=>"Prepare failed","err"=>$conn->error], 500);

$stmt->bind_param("i", $user_id);
if(!$stmt->execute()) respond(["ok"=>false,"message"=>"Execute failed","err"=>$stmt->error], 500);

$res = $stmt->get_result();

$self = [];
$trainer = [];

while($row = $res->fetch_assoc()){
  $src = strtolower(trim($row["source"] ?? "trainer"));
  if($src === "self") $self[] = $row;
  else $trainer[] = $row;
}
$stmt->close();

respond([
  "ok"=>true,
  "trainer_assigned"=>$trainer,
  "self_workouts"=>$self
]);