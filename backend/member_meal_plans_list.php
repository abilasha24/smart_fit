<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/db.php";

$user_id = (int)($_SESSION["user_id"] ?? 0);
$role = strtolower(trim($_SESSION["role"] ?? ""));

if ($user_id <= 0 || $role !== "member") {
  http_response_code(401);
  echo json_encode(["ok"=>false,"message"=>"Login required"]);
  exit;
}

function plan_rank($p){
  $p = strtolower(trim((string)$p));
  if ($p === "pro") return 3;
  if ($p === "premium") return 2;
  return 1;
}
function fail($msg, $detail=""){
  http_response_code(500);
  echo json_encode(["ok"=>false,"message"=>$msg,"detail"=>$detail]);
  exit;
}

try {
  // plan from users.plan if exists
  $plan = "basic";
  $col = $conn->query("SHOW COLUMNS FROM users LIKE 'plan'");
  if ($col && $col->num_rows>0) {
    $stmt = $conn->prepare("SELECT plan FROM users WHERE id=? LIMIT 1");
    if (!$stmt) fail("Server error", $conn->error);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!empty($r["plan"])) $plan = strtolower($r["plan"]);
  }

  $rank = plan_rank($plan);
  $allowed = ["basic"];
  if ($rank >= 2) $allowed[] = "premium";
  if ($rank >= 3) $allowed[] = "pro";

  // IN list
  $ph = implode(",", array_fill(0, count($allowed), "?"));
  $types = str_repeat("s", count($allowed));

  $sql = "SELECT id, trainer_id, title, content, created_at, plan_type
          FROM meal_plans
          WHERE LOWER(plan_type) IN ($ph)
          ORDER BY id DESC
          LIMIT 12";

  $stmt = $conn->prepare($sql);
  if (!$stmt) fail("Server error", $conn->error);

  $stmt->bind_param($types, ...$allowed);
  $stmt->execute();
  $res = $stmt->get_result();

  $plans = [];
  while($row = $res->fetch_assoc()){
    $plans[] = $row;
  }
  $stmt->close();

  echo json_encode(["ok"=>true, "plan"=>$plan, "plans"=>$plans]);

} catch(Throwable $e){
  fail("Server error", $e->getMessage());
}
