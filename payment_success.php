<?php
session_start();
require_once __DIR__ . "/backend/db.php";
require_once __DIR__ . "/vendor/autoload.php";
require_once __DIR__ . "/stripe_config.php";

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$sid   = trim($_GET["sid"] ?? "");
$plan  = strtolower(trim($_GET["plan"] ?? ""));
$cycle = strtolower(trim($_GET["cycle"] ?? "monthly"));
$amount = (float)($_GET["lkr"] ?? 0);

/* -------------------------
   1. VALIDATION
--------------------------*/
if ($sid === "" || !in_array($plan, ["premium","pro"], true)) {
  header("Location: /error.html?msg=Invalid+Request");
  exit;
}

/* -------------------------
   2. STRIPE CHECK SAFELY
--------------------------*/
try {
  $session = \Stripe\Checkout\Session::retrieve($sid);
} catch (Exception $e) {
  header("Location: /error.html?msg=Stripe+Error");
  exit;
}

if (($session->payment_status ?? "") !== "paid") {
  header("Location: /error.html?msg=Payment+Not+Completed");
  exit;
}

/* -------------------------
   3. SESSION CHECK
--------------------------*/
$user_id = (int)($_SESSION["user_id"] ?? 0);
$user_email = $_SESSION["email"] ?? "";

if ($user_id <= 0) {
  header("Location: /login.html");
  exit;
}

/* -------------------------
   4. INSERT PAYMENT
--------------------------*/
try {
  $stmt = $conn->prepare("
    INSERT INTO payments 
    (user_id, user_email, plan, billing_cycle, payment_method, amount, created_at)
    VALUES (?, ?, ?, ?, 'stripe', ?, NOW())
  ");

  if (!$stmt) {
    throw new Exception($conn->error);
  }

  $stmt->bind_param("isssd", $user_id, $user_email, $plan, $cycle, $amount);

  if (!$stmt->execute()) {
    throw new Exception($stmt->error);
  }

} catch (Exception $e) {
  error_log("Payment insert failed: " . $e->getMessage());
}

/* -------------------------
   5. ACTIVATE PLAN
--------------------------*/
try {
  $stmt2 = $conn->prepare("
    INSERT INTO user_plans 
    (user_id, plan_code, status, starts_at, expires_at, created_at)
    VALUES (?, ?, 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())
  ");

  if ($stmt2) {
    $stmt2->bind_param("is", $user_id, $plan);
    $stmt2->execute();
  }

} catch (Exception $e) {
  error_log("Plan insert failed: " . $e->getMessage());
}

/* -------------------------
   6. SUCCESS REDIRECT
--------------------------*/
header("Location: success.html?plan=$plan&amount=$amount&cycle=$cycle&payment=stripe");
exit;