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

function fail($msg, $detail = ""){
  http_response_code(500);
  echo json_encode(["ok"=>false,"message"=>$msg,"detail"=>$detail]);
  exit;
}

function plan_rank($p){
  $p = strtolower(trim((string)$p));
  if ($p === "pro") return 3;
  if ($p === "premium") return 2;
  return 1;
}

try {
  // ---------------------------
  // 1) PLAN (safe)
  // ---------------------------
  $plan = "basic";

  // Try users.plan if exists
  $col = $conn->query("SHOW COLUMNS FROM users LIKE 'plan'");
  if ($col && $col->num_rows > 0) {
    $stmt = $conn->prepare("SELECT plan FROM users WHERE id=? LIMIT 1");
    if (!$stmt) fail("Server error", $conn->error);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!empty($row["plan"])) $plan = strtolower($row["plan"]);
  }

  // If still basic, try user_plans join plans ONLY if those columns exist
  // (avoid prepare fail)
  $hasUP = $conn->query("SHOW TABLES LIKE 'user_plans'");
  $hasP  = $conn->query("SHOW TABLES LIKE 'plans'");
  if ($hasUP && $hasUP->num_rows>0 && $hasP && $hasP->num_rows>0) {

    $hasUP_user = $conn->query("SHOW COLUMNS FROM user_plans LIKE 'user_id'");
    $hasUP_plan = $conn->query("SHOW COLUMNS FROM user_plans LIKE 'plan_id'");
    $hasP_id    = $conn->query("SHOW COLUMNS FROM plans LIKE 'id'");
    $hasP_code  = $conn->query("SHOW COLUMNS FROM plans LIKE 'code'");

    if ($hasUP_user && $hasUP_user->num_rows>0 && $hasUP_plan && $hasUP_plan->num_rows>0 && $hasP_id && $hasP_id->num_rows>0 && $hasP_code && $hasP_code->num_rows>0) {
      $sql = "SELECT p.code AS plan_code
              FROM user_plans up
              JOIN plans p ON p.id = up.plan_id
              WHERE up.user_id=?
              ORDER BY up.id DESC
              LIMIT 1";
      $stmt = $conn->prepare($sql);
      if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!empty($row["plan_code"])) $plan = strtolower($row["plan_code"]);
      }
      // if prepare fails, ignore (fallback stays)
    }
  }

  // ---------------------------
  // 2) BMI (bmi_records)
  // ---------------------------
  $height = null; $weight = null; $bmi = null; $bmi_category = null;

  $stmt = $conn->prepare("SELECT height_cm, weight_kg, bmi_value, category
                          FROM bmi_records
                          WHERE user_id=?
                          ORDER BY id DESC LIMIT 1");
  if (!$stmt) fail("Server error", $conn->error);
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $br = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($br) {
    $height = $br["height_cm"];
    $weight = $br["weight_kg"];
    $bmi = $br["bmi_value"];
    $bmi_category = $br["category"];
  }

  // ---------------------------
  // 3) Gender (users.gender if exists)
  // ---------------------------
  $gender = null;
  $col = $conn->query("SHOW COLUMNS FROM users LIKE 'gender'");
  if ($col && $col->num_rows>0) {
    $stmt = $conn->prepare("SELECT gender FROM users WHERE id=? LIMIT 1");
    if (!$stmt) fail("Server error", $conn->error);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $gr = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $gender = $gr["gender"] ?? null;
  }

  // ---------------------------
  // 4) Unread notifications (SAFE)
  // ---------------------------
  $unread = 0;
  $hasN = $conn->query("SHOW TABLES LIKE 'notifications'");
  if ($hasN && $hasN->num_rows>0) {
    $hasIsRead = $conn->query("SHOW COLUMNS FROM notifications LIKE 'is_read'");
    if ($hasIsRead && $hasIsRead->num_rows>0) {
      $stmt = $conn->prepare("SELECT COUNT(*) c FROM notifications WHERE user_id=? AND is_read=0");
      if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $unread = (int)($stmt->get_result()->fetch_assoc()["c"] ?? 0);
        $stmt->close();
      }
    }
  }

  // ---------------------------
  // 5) Stats from user_workouts
  // ---------------------------
  $total = 0; $completed = 0; $started = 0;

  $stmt = $conn->prepare("SELECT COUNT(*) total,
                                 SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) completed,
                                 SUM(CASE WHEN status='started' THEN 1 ELSE 0 END) started
                          FROM user_workouts WHERE user_id=?");
  if (!$stmt) fail("Server error", $conn->error);
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $wr = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($wr) {
    $total = (int)$wr["total"];
    $completed = (int)$wr["completed"];
    $started = (int)$wr["started"];
  }

  // ---------------------------
  // 6) Chart: counts per day last 7 days
  // ---------------------------
  $tmpOn = array_fill(0,7,0);
  $tmpOff = array_fill(0,7,0);

  $stmt = $conn->prepare("
    SELECT DAYOFWEEK(created_at) dow,
           SUM(CASE WHEN source='self' THEN 1 ELSE 0 END) online_cnt,
           SUM(CASE WHEN source<>'self' OR source IS NULL THEN 1 ELSE 0 END) offline_cnt
    FROM user_workouts
    WHERE user_id=? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY dow
  ");
  if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while($r = $res->fetch_assoc()){
      $idx = ((int)$r["dow"]) - 1;
      if ($idx>=0 && $idx<7){
        $tmpOn[$idx] = (int)$r["online_cnt"];
        $tmpOff[$idx] = (int)$r["offline_cnt"];
      }
    }
    $stmt->close();
  }

  // ---------------------------
  // 7) Output (top cards dummy)
  // ---------------------------
  echo json_encode([
    "ok"=>true,
    "plan"=>$plan,
    "unread"=>$unread,

    "height"=>$height,
    "weight"=>$weight,
    "gender"=>$gender,
    "bmi"=>$bmi,
    "bmi_category"=>$bmi_category,

    "water_liters"=>1.2,
    "kilo_calories"=>2.54,
    "bpm"=>124,
    "sleep_hours"=>7,

    "weekly_distance"=>56,
    "total_distance"=>236,
    "cardio_offline_hours"=>10,

    "stats"=>[
      "total_workouts"=>$total,
      "completed_workouts"=>$completed,
      "started_workouts"=>$started
    ],
    "chart"=>[
      "online"=>$tmpOn,
      "offline"=>$tmpOff
    ]
  ]);

} catch(Throwable $e){
  fail("Server error", $e->getMessage());
}