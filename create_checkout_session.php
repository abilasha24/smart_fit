<?php
session_start();
require_once __DIR__ . "/vendor/autoload.php";
require_once __DIR__ . "/stripe_config.php";

$role = strtolower(trim($_SESSION["role"] ?? ""));
$user_id = (int)($_SESSION["user_id"] ?? 0);

if ($user_id <= 0 || $role !== "member") { http_response_code(401); exit("Login required"); }

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$plan = strtolower(trim($_POST["plan"] ?? ""));
$cycle = strtolower(trim($_POST["cycle"] ?? "monthly"));

$plans = [
  "premium" => ["name"=>"Premium Plan", "lkr"=>2500],
  "pro"     => ["name"=>"Pro Plan",     "lkr"=>5000],
];

if (!isset($plans[$plan])) { http_response_code(400); exit("Invalid plan"); }

$amountLkr = $plans[$plan]["lkr"];
if ($cycle === "yearly") $amountLkr *= 12;

// Demo conversion to USD cents (test mode)
$usdCents = (int) round(($amountLkr / 400) * 100);

$session = \Stripe\Checkout\Session::create([
  "mode" => "payment",
  "line_items" => [[
    "quantity" => 1,
    "price_data" => [
      "currency" => "usd",
      "unit_amount" => $usdCents,
      "product_data" => ["name" => $plans[$plan]["name"]." ($cycle)"],
    ],
  ]],
  "success_url" => BASE_URL . "/payment_success.php?sid={CHECKOUT_SESSION_ID}&plan=$plan&cycle=$cycle&lkr=$amountLkr",
  "cancel_url"  => BASE_URL . "/payment_cancel.html",
]);

header("Location: " . $session->url);
exit;