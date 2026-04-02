<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");

function respond($arr, $code = 200){
  http_response_code($code);
  echo json_encode($arr);
  exit;
}

if (empty($_SESSION["logged_in"]) || (($_SESSION["role"] ?? "") !== "member")) {
  respond(["ok"=>false, "message"=>"Login required"], 401);
}

require_once __DIR__ . "/db.php";

$user_id = (int)($_SESSION["user_id"] ?? 0);
if ($user_id <= 0) {
  respond(["ok"=>false, "message"=>"Invalid user_id in session"], 400);
}

/**
 * Accept both JSON and form-data.
 */
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$height_cm = isset($data["height_cm"]) ? (float)$data["height_cm"] : (isset($data["height"]) ? (float)$data["height"] : 0);
$weight_kg = isset($data["weight_kg"]) ? (float)$data["weight_kg"] : (isset($data["weight"]) ? (float)$data["weight"] : 0);

if ($height_cm <= 0 || $weight_kg <= 0) {
  respond(["ok"=>false, "message"=>"Height/Weight required"], 422);
}

if ($height_cm < 80 || $height_cm > 260) {
  respond(["ok"=>false, "message"=>"Height seems invalid"], 422);
}
if ($weight_kg < 20 || $weight_kg > 350) {
  respond(["ok"=>false, "message"=>"Weight seems invalid"], 422);
}

$h_m = $height_cm / 100.0;
$bmi = round($weight_kg / ($h_m * $h_m), 2);

$category = "Normal";
if ($bmi < 18.5) $category = "Underweight";
else if ($bmi < 25) $category = "Normal";
else if ($bmi < 30) $category = "Overweight";
else $category = "Obese";

try {
  // Make sure bmi_records table exists
  $has = $conn->query("SHOW TABLES LIKE 'bmi_records'");
  if (!$has || $has->num_rows == 0) {
    respond(["ok"=>false, "message"=>"bmi_records table not found"], 500);
  }

  // ✅ 1) INSERT into bmi_records
  $stmt = $conn->prepare("
    INSERT INTO bmi_records (user_id, height_cm, weight_kg, bmi_value, category, created_at)
    VALUES (?, ?, ?, ?, ?, NOW())
  ");
  if (!$stmt) {
    respond(["ok"=>false, "message"=>"Prepare failed (bmi_records): ".$conn->error], 500);
  }

  $stmt->bind_param("iddds", $user_id, $height_cm, $weight_kg, $bmi, $category);
  if (!$stmt->execute()) {
    respond(["ok"=>false, "message"=>"Insert failed (bmi_records): ".$stmt->error], 500);
  }
  $insert_id = $conn->insert_id;
  $stmt->close();

  // ✅ 2) UPDATE member_profiles (so dashboard shows latest height/weight)
  $hasMP = $conn->query("SHOW TABLES LIKE 'member_profiles'");
  if ($hasMP && $hasMP->num_rows > 0) {

    // check if member profile row exists for this user
    $check = $conn->prepare("SELECT id FROM member_profiles WHERE user_id=? LIMIT 1");
    $check->bind_param("i", $user_id);
    $check->execute();
    $res = $check->get_result();
    $exists = $res && $res->num_rows > 0;
    $check->close();

    if ($exists) {
      $up = $conn->prepare("
        UPDATE member_profiles
        SET height_cm=?, weight_kg=?, updated_at=NOW()
        WHERE user_id=?
      ");
      if ($up) {
        $up->bind_param("ddi", $height_cm, $weight_kg, $user_id);
        $up->execute(); // even if this fails, bmi record is already saved
        $up->close();
      }
    } else {
      // optional insert (only if your table allows null for dob/goal etc.)
      // If your table requires dob/goal, don't insert here; keep only update logic.
      $insMP = $conn->prepare("
        INSERT INTO member_profiles (user_id, gender, height_cm, weight_kg, updated_at)
        VALUES (?, NULL, ?, ?, NOW())
      ");
      if ($insMP) {
        $insMP->bind_param("idd", $user_id, $height_cm, $weight_kg);
        @$insMP->execute();
        $insMP->close();
      }
    }
  }

  // ✅ Return success with insert_id (important for debugging)
  respond([
    "ok"=>true,
    "insert_id"=>$insert_id,
    "user_id"=>$user_id,
    "height_cm"=>$height_cm,
    "weight_kg"=>$weight_kg,
    "bmi"=>$bmi,
    "category"=>$category
  ]);

} catch (Throwable $e) {
  respond(["ok"=>false, "message"=>"Server error: ".$e->getMessage()], 500);
}