<?php
header("Content-Type: application/json; charset=UTF-8");
session_start();

if (empty($_SESSION["logged_in"]) || (strtolower($_SESSION["role"] ?? "") !== "trainer")) {
  http_response_code(403);
  echo json_encode(["ok"=>false,"message"=>"Forbidden"]);
  exit;
}

require_once __DIR__ . "/db.php";
if (!isset($conn) || !($conn instanceof mysqli)) {
  http_response_code(500);
  echo json_encode(["ok"=>false,"message"=>"DB connection missing"]);
  exit;
}

$member_id = (int)($_GET["member_id"] ?? 0);
if ($member_id <= 0) {
  http_response_code(400);
  echo json_encode(["ok"=>false,"message"=>"member_id required"]);
  exit;
}

function normPlan(string $p): string {
  $p = strtolower(trim($p));
  return in_array($p, ["basic","premium","pro"], true) ? $p : "basic";
}
function allowedPlanTypes(string $plan): array {
  if ($plan === "pro") return ["basic","premium","pro"];
  if ($plan === "premium") return ["basic","premium"];
  return ["basic"];
}
function normGoal(?string $g): string {
  $g = strtolower(trim((string)$g));
  $allowed = ["muscle_gain","weight_loss","endurance","flexibility","general_fitness"];
  return in_array($g, $allowed, true) ? $g : "general_fitness";
}

function prepFail(mysqli $conn, string $where) {
  http_response_code(500);
  echo json_encode(["ok"=>false,"message"=>"Prepare failed: $where","err"=>$conn->error]);
  exit;
}
function execFail(mysqli_stmt $stmt, string $where) {
  http_response_code(500);
  echo json_encode(["ok"=>false,"message"=>"Execute failed: $where","err"=>$stmt->error]);
  exit;
}

/* member plan */
$stmt = $conn->prepare("SELECT plan FROM users WHERE id=? AND role='member' LIMIT 1");
if(!$stmt) prepFail($conn, "users.plan");
$stmt->bind_param("i", $member_id);
if(!$stmt->execute()) execFail($stmt, "users.plan");
$m = $stmt->get_result()->fetch_assoc();
$stmt->close();
if(!$m) { http_response_code(404); echo json_encode(["ok"=>false,"message"=>"Member not found"]); exit; }

$plan = normPlan($m["plan"] ?? "basic");
$allowed = allowedPlanTypes($plan);

/* member goal */
$goal = "general_fitness";
$stmt = $conn->prepare("SELECT goal FROM member_profiles WHERE user_id=? LIMIT 1");
if(!$stmt) prepFail($conn, "member_profiles.goal");
$stmt->bind_param("i", $member_id);
if(!$stmt->execute()) execFail($stmt, "member_profiles.goal");
$r = $stmt->get_result()->fetch_assoc();
$stmt->close();
$goal = normGoal($r["goal"] ?? "general_fitness");

/* latest bmi */
$bmi = null;
$stmt = $conn->prepare("SELECT bmi_value FROM bmi_records WHERE user_id=? ORDER BY created_at DESC LIMIT 1");
if(!$stmt) prepFail($conn, "bmi_records.latest");
$stmt->bind_param("i", $member_id);
if(!$stmt->execute()) execFail($stmt, "bmi_records.latest");
$r = $stmt->get_result()->fetch_assoc();
$stmt->close();
if($r && is_numeric($r["bmi_value"])) $bmi = (float)$r["bmi_value"];

/* completed count */
$completed = 0;
$stmt = $conn->prepare("SELECT COUNT(*) total FROM user_workouts WHERE user_id=? AND status='completed'");
if(!$stmt) prepFail($conn, "user_workouts.completed");
$stmt->bind_param("i", $member_id);
if(!$stmt->execute()) execFail($stmt, "user_workouts.completed");
$r = $stmt->get_result()->fetch_assoc();
$stmt->close();
$completed = (int)($r["total"] ?? 0);

/* suggested levels by progress */
$levels = ["beginner"];
if ($completed >= 3)  $levels = ["beginner","intermediate"];
if ($completed >= 10) $levels = ["beginner","intermediate","advanced"];

/* keyword map */
$keywords = [
  "weight_loss" => ["hiit","cardio","fat","burn"],
  "muscle_gain" => ["strength","power","bulk","upper","leg"],
  "endurance"   => ["endurance","circuit","cardio"],
  "flexibility" => ["yoga","stretch","mobility"],
  "general_fitness" => ["full","fitness","balance"]
];
$kw = $keywords[$goal] ?? ["fitness"];

/* candidates restricted by plan_type + levels */
$levelPlace = implode(",", array_fill(0, count($levels), "?"));
$planPlace  = implode(",", array_fill(0, count($allowed), "?"));

$sql = "
  SELECT id, title, level, duration_min, calories, youtube_url, plan_type
  FROM workouts
  WHERE level IN ($levelPlace)
    AND plan_type IN ($planPlace)
  ORDER BY created_at DESC
  LIMIT 50
";
$stmt = $conn->prepare($sql);
if(!$stmt) prepFail($conn, "workouts.candidates");

$types = str_repeat("s", count($levels)) . str_repeat("s", count($allowed));
$params = array_merge($levels, $allowed);

$stmt->bind_param($types, ...$params);
if(!$stmt->execute()) execFail($stmt, "workouts.candidates");
$res = $stmt->get_result();

$cands = [];
while($res && ($w=$res->fetch_assoc())) $cands[] = $w;
$stmt->close();

/* score */
function score(array $w, array $kw, ?float $bmi): array {
  $t = strtolower($w["title"] ?? "");
  $s = 0; $reasons = [];

  foreach($kw as $k){
    if(str_contains($t,$k)){ $s+=25; $reasons[]="Goal match ($k)"; break; }
  }

  $lvl = strtolower($w["level"] ?? "beginner");
  if($lvl==="beginner") $s+=10;
  if($lvl==="intermediate") $s+=12;
  if($lvl==="advanced") $s+=14;

  if($bmi !== null && $bmi >= 25){
    if(str_contains($t,"cardio") || str_contains($t,"hiit") || str_contains($t,"fat")){
      $s+=10; $reasons[]="BMI supports cardio";
    }
  }
  if($bmi !== null && $bmi < 18.5){
    if(str_contains($t,"strength") || str_contains($t,"power") || str_contains($t,"bulk")){
      $s+=10; $reasons[]="BMI supports strength gain";
    }
  }

  $conf = max(45, min(95, 50 + (int)($s/2)));
  return [$s,$conf,array_slice(array_unique($reasons),0,2)];
}

$sc = [];
foreach($cands as $w){
  [$s,$conf,$reasons] = score($w,$kw,$bmi);
  $w["score"]=$s;
  $w["confidence"]=$conf;
  $w["reasons"]=$reasons;
  $sc[]=$w;
}
usort($sc, fn($a,$b)=>($b["score"]<=>$a["score"]));

echo json_encode([
  "ok"=>true,
  "member_id"=>$member_id,
  "member_plan"=>$plan,
  "goal"=>$goal,
  "bmi"=>$bmi,
  "completed_workouts"=>$completed,
  "levels_used"=>$levels,
  "suggestions"=>array_slice($sc,0,3)
]);
exit;