<?php
session_start();
require_once __DIR__ . "/backend/db.php";          // ✅ your db file is inside backend
require_once __DIR__ . "/vendor/autoload.php";
require_once __DIR__ . "/stripe_config.php";

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$sid   = trim($_GET["sid"] ?? "");
$plan  = strtolower(trim($_GET["plan"] ?? ""));
$cycle = strtolower(trim($_GET["cycle"] ?? "monthly"));
$amount = (float)($_GET["lkr"] ?? 0);

if ($sid === "" || !in_array($plan, ["premium","pro"], true)) {
  die("Invalid request");
}

$session = \Stripe\Checkout\Session::retrieve($sid);
if (($session->payment_status ?? "") !== "paid") {
  die("Payment not completed");
}

$user_id = (int)($_SESSION["user_id"] ?? 0);
$user_email = $_SESSION["email"] ?? "";

if ($user_id <= 0) { die("Login session missing"); }

// ✅ Insert into payments table
$stmt = $conn->prepare("INSERT INTO payments (user_id, user_email, plan, billing_cycle, payment_method, amount, created_at)
                        VALUES (?, ?, ?, ?, 'stripe', ?, NOW())");
$stmt->bind_param("isssd", $user_id, $user_email, $plan, $cycle, $amount);
$stmt->execute();

// ✅ Insert into user_plans table (activate)
$stmt2 = $conn->prepare("INSERT INTO user_plans (user_id, plan_code, status, starts_at, expires_at, created_at)
                         VALUES (?, ?, 'active', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())");
$stmt2->bind_param("is", $user_id, $plan);
$stmt2->execute();

// ✅ Go success page
header("Location: success.html?plan=$plan&amount=$amount&cycle=$cycle&payment=stripe");
exit;