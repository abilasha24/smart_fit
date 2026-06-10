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
if ($user_id <= 0) {
  http_response_code(400);
  echo json_encode(["ok"=>false,"message"=>"Invalid user_id in session"]);
  exit;
}

/* ---------------------------
   Helpers
----------------------------*/
function firstExistingColumn(mysqli $conn, string $table, array $candidates): ?string {
  $cols = [];
  $res = $conn->query("DESCRIBE `$table`");
  if(!$res) return null;
  while($r = $res->fetch_assoc()){
    $cols[strtolower($r["Field"])] = $r["Field"];
  }
  foreach($candidates as $c){
    $lc = strtolower($c);
    if(isset($cols[$lc])) return $cols[$lc];
  }
  return null;
}

function safeSelectOne(mysqli $conn, string $sql, string $types, array $params): ?array {
  $stmt = $conn->prepare($sql);
  if(!$stmt) return null;
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();
  return $res ? $res->fetch_assoc() : null;
}

function tableExists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $res = $conn->query("SHOW TABLES LIKE '$t'");
  return ($res && $res->num_rows > 0);
}

/* ---------------------------
   PLAN from users table
----------------------------*/
$plan = "basic";
if (tableExists($conn, "users")) {
  $planCol = firstExistingColumn($conn, "users", ["plan","plan_type","plan_code","subscription_plan"]);
  if($planCol){
    $row = safeSelectOne($conn, "SELECT `$planCol` AS p FROM users WHERE id=? LIMIT 1", "i", [$user_id]);
    if($row && !empty($row["p"])) $plan = $row["p"];
  }
}

/* ---------------------------
   UNREAD NOTIFICATIONS (REAL DB)
----------------------------*/
$unread = 0;
if (tableExists($conn, "notifications")) {
  $uidCol  = firstExistingColumn($conn, "notifications", ["user_id","member_id","userid"]);
  $readCol = firstExistingColumn($conn, "notifications", ["is_read","read_status","seen","is_seen"]);
  if($uidCol){
    if($readCol){
      $rowN = safeSelectOne($conn, "
        SELECT COUNT(*) AS c
        FROM notifications
        WHERE `$uidCol`=? AND (`$readCol`=0 OR `$readCol` IS NULL)
      ", "i", [$user_id]);
      if($rowN) $unread = (int)($rowN["c"] ?? 0);
    } else {
      $rowN = safeSelectOne($conn, "
        SELECT COUNT(*) AS c
        FROM notifications
        WHERE `$uidCol`=?
      ", "i", [$user_id]);
      if($rowN) $unread = (int)($rowN["c"] ?? 0);
    }
  }
}

/* ---------------------------
   MEMBER PROFILE (register data)
   Table: member_profiles
   Columns in your DB screenshot:
   user_id, gender, height_cm, weight_kg, updated_at ...
----------------------------*/
$height_cm = null; $weight_kg = null; $gender = null;

if (tableExists($conn, "member_profiles")) {
  $mpTable   = "member_profiles";
  $uidCol    = firstExistingColumn($conn, $mpTable, ["user_id","member_id","userid"]);
  $heightCol = firstExistingColumn($conn, $mpTable, ["height_cm","height","user_height","heightcm"]);
  $weightCol = firstExistingColumn($conn, $mpTable, ["weight_kg","weight","user_weight","weightkg"]);
  $genderCol = firstExistingColumn($conn, $mpTable, ["gender","sex"]);

  // IMPORTANT: pick latest by updated_at if exists, else by id
  $orderCol = firstExistingColumn($conn, $mpTable, ["updated_at","id"]);

  if($uidCol){
    $selectParts = [];
    if($heightCol) $selectParts[] = "`$heightCol` AS height";
    if($weightCol) $selectParts[] = "`$weightCol` AS weight";
    if($genderCol) $selectParts[] = "`$genderCol` AS gender";

    if(!empty($selectParts)){
      $orderSql = $orderCol ? " ORDER BY `$orderCol` DESC " : " ORDER BY id DESC ";
      $sql = "SELECT ".implode(",", $selectParts)." FROM `$mpTable` WHERE `$uidCol`=? $orderSql LIMIT 1";
      $row = safeSelectOne($conn, $sql, "i", [$user_id]);
      if($row){
        $height_cm = $row["height"] ?? null;
        $weight_kg = $row["weight"] ?? null;
        $gender    = $row["gender"] ?? null;
      }
    }
  }
}

/* ---------------------------
   BMI (CURRENT) = latest from bmi_records
   If exists, OVERRIDE height/weight + bmi
----------------------------*/
$bmi_value = null;

if (tableExists($conn, "bmi_records")) {
  $bTable   = "bmi_records";
  $uidCol   = firstExistingColumn($conn, $bTable, ["user_id","member_id","userid"]);
  $hCol     = firstExistingColumn($conn, $bTable, ["height_cm","height","heightcm"]);
  $wCol     = firstExistingColumn($conn, $bTable, ["weight_kg","weight","weightkg"]);
  $bmiCol   = firstExistingColumn($conn, $bTable, ["bmi_value","bmi"]);
  $dateCol  = firstExistingColumn($conn, $bTable, ["created_at","date","updated_at","id"]);

  if($uidCol){
    $selectParts = [];
    if($hCol)   $selectParts[] = "`$hCol` AS height";
    if($wCol)   $selectParts[] = "`$wCol` AS weight";
    if($bmiCol) $selectParts[] = "`$bmiCol` AS bmi";

    if(!empty($selectParts)){
      $order = $dateCol ? "`$dateCol`" : "id";
      $sql = "SELECT ".implode(",", $selectParts)." FROM `$bTable` WHERE `$uidCol`=? ORDER BY $order DESC, id DESC LIMIT 1";
      $row = safeSelectOne($conn, $sql, "i", [$user_id]);

      // If BMI record exists -> this is the CURRENT body metrics
      if($row){
        if(isset($row["height"]) && $row["height"] !== null && $row["height"] !== "") $height_cm = $row["height"];
        if(isset($row["weight"]) && $row["weight"] !== null && $row["weight"] !== "") $weight_kg = $row["weight"];
        if(isset($row["bmi"])    && $row["bmi"]    !== null && $row["bmi"]    !== "") $bmi_value = $row["bmi"];
      }
    }
  }
}

/* If BMI still missing -> compute from the current height/weight */
if (($bmi_value === null || $bmi_value === "" || (float)$bmi_value <= 0) && $height_cm && $weight_kg) {
  $h_m = ((float)$height_cm) / 100.0;
  if ($h_m > 0) $bmi_value = round(((float)$weight_kg) / ($h_m * $h_m), 2);
}

/* ---------------------------
   COUNTS from user_workouts
   assigned = trainer rows not completed
----------------------------*/
$counts = ["assigned"=>0,"started"=>0,"completed"=>0,"total"=>0,"remaining"=>0,"completion_rate"=>0];

if (tableExists($conn, "user_workouts")) {
  $row = safeSelectOne($conn, "
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN source='trainer' AND (status IS NULL OR status <> 'completed') THEN 1 ELSE 0 END) AS assigned,
      SUM(CASE WHEN status='started' THEN 1 ELSE 0 END) AS started,
      SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed
    FROM user_workouts
    WHERE user_id=?
  ", "i", [$user_id]);

  if($row){
    $counts["total"] = (int)($row["total"] ?? 0);
    $counts["assigned"] = (int)($row["assigned"] ?? 0);
    $counts["started"] = (int)($row["started"] ?? 0);
    $counts["completed"] = (int)($row["completed"] ?? 0);
    $counts["remaining"] = max($counts["total"] - $counts["completed"], 0);
    $counts["completion_rate"] = ($counts["total"]>0) ? (int)round(($counts["completed"]/$counts["total"])*100) : 0;
  }
}

/* ---------------------------
   Level
----------------------------*/
$member_level = "Beginner";
if ($counts["completed"] >= 10) $member_level = "Intermediate";
if ($counts["completed"] >= 25) $member_level = "Advanced";

/* ---------------------------
   Weekly goal (last 7 days)
----------------------------*/
$weekly_goal_target = 3;
$weekly_done = 0;

if (tableExists($conn, "user_workouts")) {
  $row = safeSelectOne($conn, "
    SELECT COUNT(*) AS done
    FROM user_workouts
    WHERE user_id=?
      AND status='completed'
      AND completed_at >= (NOW() - INTERVAL 7 DAY)
  ", "i", [$user_id]);

  if($row) $weekly_done = (int)($row["done"] ?? 0);
}

$weekly_goal = [
  "goal"=>$weekly_goal_target,
  "completed"=>$weekly_done,
  "remaining"=>max($weekly_goal_target-$weekly_done,0)
];

/* ---------------------------
   Latest payment
----------------------------*/
$latest_payment = ["plan"=>$plan,"method"=>null,"amount"=>null,"date"=>null];

if (tableExists($conn, "payments")) {
  $payTable    = "payments";
  $uidPayCol   = firstExistingColumn($conn, $payTable, ["user_id","member_id","userid"]);
  $planPayCol  = firstExistingColumn($conn, $payTable, ["plan","plan_code","plan_type"]);
  $methodCol   = firstExistingColumn($conn, $payTable, ["method","payment_method"]);
  $amountCol   = firstExistingColumn($conn, $payTable, ["amount","paid_amount","total_amount"]);
  $dateCol     = firstExistingColumn($conn, $payTable, ["created_at","paid_at","payment_date","date"]);

  if($uidPayCol){
    $selectParts = [];
    if($planPayCol) $selectParts[] = "`$planPayCol` AS plan";
    if($methodCol)  $selectParts[] = "`$methodCol` AS method";
    if($amountCol)  $selectParts[] = "`$amountCol` AS amount";
    if($dateCol)    $selectParts[] = "`$dateCol` AS dt";

    if(!empty($selectParts)){
      $sql = "SELECT ".implode(",", $selectParts)." FROM `$payTable` WHERE `$uidPayCol`=? ORDER BY id DESC LIMIT 1";
      $row = safeSelectOne($conn, $sql, "i", [$user_id]);

      if($row){
        $latest_payment["plan"]   = $row["plan"] ?? $plan;
        $latest_payment["method"] = $row["method"] ?? null;
        $latest_payment["amount"] = $row["amount"] ?? null;
        $latest_payment["date"]   = $row["dt"] ?? null;
      }
    }
  }
}

echo json_encode([
  "ok"=>true,
  "user_id"=>$user_id,
  "plan"=>$plan,
  "unread"=>$unread,

  // current body metrics (bmi_records latest if exists, else member_profiles)
  "height_cm"=>$height_cm,
  "weight_kg"=>$weight_kg,
  "gender"=>$gender,
  "bmi_value"=>$bmi_value,

  "counts"=>$counts,
  "member_level"=>$member_level,
  "weekly_goal"=>$weekly_goal,
  "latest_payment"=>$latest_payment
]);
