<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");

ini_set('display_errors', 0);
error_reporting(0);

require_once "db.php";

function respond($arr, $code = 200) {
  http_response_code($code);
  echo json_encode($arr);
  exit;
}

try {

  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond(["status"=>"error","message"=>"Invalid request"],405);
  }

  // ---------------- INPUT ----------------
  $firstName = trim($_POST['firstName'] ?? '');
  $lastName  = trim($_POST['lastName'] ?? '');
  $email     = trim($_POST['email'] ?? '');
  $phone     = trim($_POST['phone'] ?? '');
  $password  = $_POST['password'] ?? '';
  $role      = strtolower($_POST['role'] ?? 'member');
  $plan      = strtolower($_POST['plan'] ?? 'premium');

  // ---------------- VALIDATION ----------------
  if ($firstName=='' || $lastName=='' || $email=='' || $phone=='' || $password=='') {
    respond(["status"=>"error","message"=>"Missing fields"],400);
  }

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(["status"=>"error","message"=>"Invalid email"],400);
  }

  if (!in_array($role,["member","trainer","admin"])) $role="member";
  if (!in_array($plan,["basic","premium","pro"])) $plan="premium";

  // ---------------- DB CHECK ----------------
  $check = $conn->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
  if (!$check) respond(["status"=>"error","message"=>"DB error"],500);

  $check->bind_param("s",$email);
  $check->execute();
  $res = $check->get_result();

  if ($res && $res->num_rows > 0) {
    respond(["status"=>"error","message"=>"Email already exists"],409);
  }

  // ---------------- PASSWORD ----------------
  $hash = password_hash($password, PASSWORD_BCRYPT);

  // ---------------- INSERT USER ----------------
  $stmt = $conn->prepare("INSERT INTO users (first_name,last_name,email,phone,password_hash,role,plan)
                          VALUES (?,?,?,?,?,?,?)");

  if (!$stmt) respond(["status"=>"error","message"=>"Insert prepare failed"],500);

  $stmt->bind_param("sssssss",$firstName,$lastName,$email,$phone,$hash,$role,$plan);

  if (!$stmt->execute()) {
    respond(["status"=>"error","message"=>"Insert failed"],500);
  }

  $userId = $stmt->insert_id;

  // ---------------- RESPONSE ----------------
  respond([
    "status"=>"success",
    "message"=>"Registered successfully",
    "user_id"=>$userId,
    "plan"=>$plan
  ]);

} catch (Throwable $e) {
  respond(["status"=>"error","message"=>"Server error"],500);
}