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

$days = 30;

// MySQL: DAYOFWEEK() => 1=Sun ... 7=Sat
$sqlStarted = "
SELECT DAYOFWEEK(started_at) as d, COUNT(*) as c
FROM user_workouts
WHERE user_id=? AND started_at IS NOT NULL
  AND started_at >= (NOW() - INTERVAL ? DAY)
GROUP BY DAYOFWEEK(started_at)
";

$sqlCompleted = "
SELECT DAYOFWEEK(completed_at) as d, COUNT(*) as c
FROM user_workouts
WHERE user_id=? AND completed_at IS NOT NULL
  AND completed_at >= (NOW() - INTERVAL ? DAY)
GROUP BY DAYOFWEEK(completed_at)
";

function fillWeekArray($rows){
  // index 1..7 → map to 0..6
  $arr = [0,0,0,0,0,0,0];
  foreach($rows as $r){
    $d = (int)$r["d"]; // 1..7
    $c = (int)$r["c"];
    $arr[$d-1] = $c;
  }
  return $arr;
}

$stmt = $conn->prepare($sqlStarted);
$stmt->bind_param("ii", $user_id, $days);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while($row=$res->fetch_assoc()) $rows[]=$row;
$started = fillWeekArray($rows);

$stmt = $conn->prepare($sqlCompleted);
$stmt->bind_param("ii", $user_id, $days);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while($row=$res->fetch_assoc()) $rows[]=$row;
$completed = fillWeekArray($rows);

echo json_encode([
  "ok"=>true,
  "labels"=>["Sun","Mon","Tue","Wed","Thu","Fri","Sat"],
  "started"=>$started,
  "completed"=>$completed
]);