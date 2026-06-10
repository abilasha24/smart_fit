<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/db.php";   // ✅ correct (same folder)

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok"=>false, "message"=>"Method not allowed"]);
  exit;
}

$user_id = (int)($_POST["user_id"] ?? 0);
$plan_code = trim($_POST["plan_code"] ?? "");
$billing_cycle = trim($_POST["billing_cycle"] ?? "monthly");
$payment_method = trim($_POST["payment_method"] ?? "");
$total = (float)($_POST["total"] ?? 0);

if ($user_id <= 0 || $plan_code === "" || $payment_method === "" || $total < 0) {
  http_response_code(400);
  echo json_encode(["ok"=>false, "message"=>"Missing/invalid fields"]);
  exit;
}

$order_id = "SF-" . date("Ymd") . "-" . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

$stmt = $conn->prepare("INSERT INTO transactions (order_id, user_id, plan_code, billing_cycle, payment_method, total)
                        VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("sisssd", $order_id, $user_id, $plan_code, $billing_cycle, $payment_method, $total);

if ($stmt->execute()) {
  echo json_encode(["ok"=>true, "order_id"=>$order_id]);
  exit;
}

http_response_code(500);
echo json_encode(["ok"=>false, "message"=>"DB insert failed", "error"=>$conn->error]);
exit;
