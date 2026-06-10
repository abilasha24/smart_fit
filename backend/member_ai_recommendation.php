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
  $p = strtolower(trim($p));
  if ($p === "pro") return 3;
  if ($p === "premium") return 2;
  return 1;
}

try {

  /* ================= USER PLAN ================= */
  $plan = "basic";
  $stmt = $conn->prepare("SELECT plan FROM users WHERE id=? LIMIT 1");
  $stmt->bind_param("i",$user_id);
  $stmt->execute();
  $u = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if(!empty($u["plan"])) $plan = strtolower($u["plan"]);

  $rank = plan_rank($plan);
  $allowedPlans = ["basic"];
  if ($rank >= 2) $allowedPlans[] = "premium";
  if ($rank >= 3) $allowedPlans[] = "pro";

  /* ================= BMI ================= */
  $bmiCat = "Normal";
  $stmt = $conn->prepare("SELECT category FROM bmi_records WHERE user_id=? ORDER BY id DESC LIMIT 1");
  $stmt->bind_param("i",$user_id);
  $stmt->execute();
  $b = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if(!empty($b["category"])) $bmiCat = strtolower($b["category"]);

  /* ================= WORKOUT CATEGORY DECISION ================= */
  $keyword = "";
  $category = "Balanced Fitness";
  $tip = "Mix strength + cardio weekly.";

  if(str_contains($bmiCat,"under")){
    $keyword = "muscle";
    $category = "Muscle Gain";
    $tip = "Strength training + high protein diet.";
  }
  else if(str_contains($bmiCat,"over") || str_contains($bmiCat,"obese")){
    $keyword = "loss";
    $category = "Fat Loss";
    $tip = "Cardio + calorie deficit meals.";
  }

  /* ================= WORKOUT FETCH ================= */
  $ph = implode(",", array_fill(0,count($allowedPlans),"?"));
  $types = str_repeat("s", count($allowedPlans));
  
  $sql = "SELECT id,title,level,duration_min,calories,youtube_url
          FROM workouts
          WHERE LOWER(plan_type) IN ($ph)
          ORDER BY id DESC
          LIMIT 4";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$allowedPlans);
  $stmt->execute();
  $res = $stmt->get_result();

  $workouts=[];
  while($w=$res->fetch_assoc()){
    $workouts[]=$w;
  }
  $stmt->close();

  /* ================= MEAL PLAN FETCH ================= */
  // Because no goal column → use title keyword search
  $mealPlans=[];
  
  if($keyword!=""){
      $sql="SELECT * FROM meal_plans
            WHERE LOWER(plan_type) IN ($ph)
            AND LOWER(title) LIKE ?
            ORDER BY id DESC
            LIMIT 3";
      
      $types2 = str_repeat("s", count($allowedPlans)) . "s";
      $params2 = array_merge($allowedPlans, ["%".$keyword."%"]);

      $stmt=$conn->prepare($sql);
      $stmt->bind_param($types2, ...$params2);
  } else {
      $sql="SELECT * FROM meal_plans
            WHERE LOWER(plan_type) IN ($ph)
            ORDER BY id DESC
            LIMIT 3";
      $stmt=$conn->prepare($sql);
      $stmt->bind_param($types, ...$allowedPlans);
  }

  $stmt->execute();
  $res=$stmt->get_result();
  while($m=$res->fetch_assoc()){
    $mealPlans[]=$m;
  }
  $stmt->close();

  echo json_encode([
    "ok"=>true,
    "plan"=>$plan,
    "bmi_category"=>$bmiCat,
    "category"=>$category,
    "tip"=>$tip,
    "workouts"=>$workouts,
    "meal_plans"=>$mealPlans
  ]);

}catch(Throwable $e){
  http_response_code(500);
  echo json_encode(["ok"=>false,"message"=>$e->getMessage()]);
}
