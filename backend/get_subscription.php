<?php
// backend/get_subscription.php
session_start();
require_once __DIR__ . "/db.php";
header("Content-Type: application/json; charset=UTF-8");

// member auth
$role = strtolower(trim($_SESSION["role"] ?? ""));
if (!isset($_SESSION["user_id"]) || $role !== "member") {
  http_response_code(401);
  echo json_encode(["ok"=>false, "message"=>"Login required"]);
  exit;
}

$user_id = (int)$_SESSION["user_id"];

$sql = "SELECT id, plan, billing_cycle, amount, payment_method, created_at
        FROM payments
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 1";

$stmt = $conn->prepare($sql);
if(!$stmt){
  echo json_encode(["ok"=>false, "message"=>"SQL prepare failed", "error"=>$conn->error]);
  exit;
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

if($res->num_rows === 0){
  // no payment => default basic
  $conn->query("UPDATE users SET plan='basic' WHERE id={$user_id}");
  echo json_encode(["ok"=>true, "subscription"=>null, "expired"=>true, "valid_until"=>null]);
  exit;
}

$row = $res->fetch_assoc();

/* ===== Expiry Calculation (keep time too) ===== */
$cycle = strtolower(trim($row["billing_cycle"] ?? "monthly"));

$paidDT = new DateTime($row["created_at"]);     // full datetime
$validDT = clone $paidDT;

if ($cycle === "yearly") $validDT->modify("+365 days");
else $validDT->modify("+30 days");

$now = new DateTime();
$expired = ($now > $validDT);

// values to return
$valid_until = $validDT->format("Y-m-d");           // for UI display
$valid_until_full = $validDT->format("Y-m-d H:i:s"); // debug if needed

$row["valid_until"] = $valid_until;
$row["expired"] = $expired;

/* ===== Auto downgrade / sync users.plan ===== */
if ($expired) {
  $stmtU = $conn->prepare("UPDATE users SET plan='basic' WHERE id=?");
  if($stmtU){
    $stmtU->bind_param("i", $user_id);
    $stmtU->execute();
  }
} else {
  // sync users.plan to current paid plan (optional but good)
  $p = $row["plan"] ?? "basic";
  $stmtU = $conn->prepare("UPDATE users SET plan=? WHERE id=?");
  if($stmtU){
    $stmtU->bind_param("si", $p, $user_id);
    $stmtU->execute();
  }
}

echo json_encode([
  "ok" => true,
  "expired" => $expired,
  "valid_until" => $valid_until,
  "valid_until_full" => $valid_until_full, // (optional) remove later if you want
  "subscription" => $row
]);
