<?php
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/stripe_config.php";
require_once __DIR__ . "/../vendor/autoload.php";

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$sid = trim($_GET["sid"] ?? "");
if ($sid === "") die("Missing sid");

try {
  $session = \Stripe\Checkout\Session::retrieve($sid);

  if (($session->payment_status ?? "") !== "paid") {
    die("Payment not completed");
  }

  $order_id  = (string)($session->metadata->order_id ?? "");
  $user_id   = (int)($session->metadata->user_id ?? 0);
  $plan      = (string)($session->metadata->plan ?? "");
  $cycle     = (string)($session->metadata->cycle ?? "monthly");
  $amountLkr = (float)($session->metadata->lkr ?? 0);
  $email     = (string)($session->customer_email ?? "");

  // ✅ OPTIONAL: payments table insert (if your table exists)
  try {
    $stmt = $conn->prepare("INSERT INTO payments (user_id, user_email, plan, billing_cycle, payment_method, amount, created_at)
                            VALUES (?, ?, ?, ?, 'stripe', ?, NOW())");
    if ($stmt) {
      $stmt->bind_param("isssd", $user_id, $email, $plan, $cycle, $amountLkr);
      $stmt->execute();
    }
  } catch(Exception $e) {
    // ignore if table missing
  }

  // ✅ Redirect to your success.html (you already have it) :contentReference[oaicite:0]{index=0}
  header("Location: ../success.html?plan=" . urlencode($plan) .
         "&amount=" . urlencode($amountLkr) .
         "&cycle=" . urlencode($cycle) .
         "&payment=stripe" .
         "&order_id=" . urlencode($order_id));
  exit;

} catch (Exception $e) {
  die("Stripe verify failed: " . $e->getMessage());
}
