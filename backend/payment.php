<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/stripe_config.php";
require_once __DIR__ . "/../vendor/autoload.php";

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

define("BASE_URL", "https://smartfit-production-09cf.up.railway.app");

$user_id = (int)($_SESSION["user_id"] ?? 0);
$email   = $_SESSION["email"] ?? "";

$plan  = strtolower(trim($_POST["plan"] ?? ""));
$cycle = strtolower(trim($_POST["cycle"] ?? "monthly"));

if ($user_id <= 0 || $email === "" || !in_array($plan, ["premium","pro"])) {
    echo json_encode(["ok"=>false,"message"=>"Login required or invalid plan"]);
    exit;
}

$planMap = [
    "premium" => 2500,
    "pro"     => 5000
];

$amount = $planMap[$plan];
$unitAmount = $amount * 100;

try {

    $session = \Stripe\Checkout\Session::create([
        "mode" => "payment",
        "customer_email" => $email,
        "line_items" => [[
            "quantity" => 1,
            "price_data" => [
                "currency" => "usd",
                "unit_amount" => $unitAmount,
                "product_data" => [
                    "name" => "Smart Fit - $plan ($cycle)"
                ],
            ],
        ]],
        "metadata" => [
            "user_id" => $user_id,
            "plan" => $plan,
            "cycle" => $cycle
        ],
        "success_url" => BASE_URL . "/payment_success.php?sid={CHECKOUT_SESSION_ID}&plan=$plan&cycle=$cycle&lkr=$amount",
        "cancel_url" => BASE_URL . "/payment_cancel.php"
    ]);

    echo json_encode([
        "ok" => true,
        "checkout_url" => $session->url
    ]);
    exit;

} catch (Exception $e) {
    echo json_encode([
        "ok" => false,
        "message" => $e->getMessage()
    ]);
    exit;
}
