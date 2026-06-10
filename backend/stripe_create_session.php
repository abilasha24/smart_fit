<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/stripe_config.php";
require_once __DIR__ . "/../vendor/autoload.php";

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok"=>false, "message"=>"Method not allowed"]);
  exit;
}

$user_id = (int)($_POST["user_id"] ?? 0);
$email   = trim($_POST["email"] ?? "");
$plan    = strtolower(trim($_POST["plan"] ?? ""));
$cycle   = strtolower(trim($_POST["cycle"] ?? "monthly"));

$planMap = [
  "premium" => ["name"=>"Premium Plan", "lkr"=>2500],
  "pro"     => ["name"=>"Pro Plan",     "lkr"=>5000],
];

if ($user_id <= 0 || $email === "" || !isset($planMap[$plan])) {
  http_response_code(400);
  echo json_encode(["ok"=>false, "message"=>"Invalid fields"]);
  exit;
}

$amountLkr = (float)$planMap[$plan]["lkr"];
if ($cycle === "yearly") $amountLkr *= 12;

// ✅ Stripe supports LKR, so use LKR directly (in cents)
$unitAmount = (int) round($amountLkr * 100); // LKR -> cents

// Create order id
$order_id = "SF-" . date("Ymd") . "-" . strtoupper(substr(bin2hex(random_bytes(4)),0,8));

// ✅ OPTIONAL: transactions table insert (if your table exists)
$payment_method = "stripe";
try {
  $stmt = $conn->prepare("INSERT INTO transactions (order_id, user_id, plan_code, billing_cycle, payment_method, total)
                          VALUES (?, ?, ?, ?, ?, ?)");
  if ($stmt) {
    $stmt->bind_param("sisssd", $order_id, $user_id, $plan, $cycle, $payment_method, $amountLkr);
    $stmt->execute();
  }
} catch(Exception $e) {
  // if table doesn't exist, ignore (demo)
}

try {
  $session = \Stripe\Checkout\Session::create([
    "mode" => "payment",
    "customer_email" => $email,
    "line_items" => [[
      "quantity" => 1,
      "price_data" => [
        "currency" => "lkr",
        "unit_amount" => $unitAmount,
        "product_data" => [
          "name" => "Smart Fit - " . $planMap[$plan]["name"] . " (" . $cycle . ")"
        ],
      ],
    ]],
    "metadata" => [
      "order_id" => $order_id,
      "user_id"  => (string)$user_id,
      "plan"     => $plan,
      "cycle"    => $cycle,
      "lkr"      => (string)$amountLkr
    ],
    "success_url" => BASE_URL . "/backend/stripe_success.php?sid={CHECKOUT_SESSION_ID}",
    "cancel_url"  => BASE_URL . "/register.html?plan=" . urlencode($plan) . "&canceled=1",
  ]);

  echo json_encode(["ok"=>true, "checkout_url"=>$session->url, "order_id"=>$order_id]);
  exit;

} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(["ok"=>false, "message"=>"Stripe error: ".$e->getMessage()]);
  exit;
}
