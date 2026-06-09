<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once "db.php";

function respond($arr, $code = 200) {
  http_response_code($code);
  echo json_encode($arr);
  exit;
}

try {

  // ---------------- METHOD CHECK ----------------
  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respond([
      "status" => "error",
      "message" => "Invalid request method"
    ], 405);
  }

  // ---------------- INPUT ----------------
  $firstName = trim($_POST['firstName'] ?? '');
  $lastName  = trim($_POST['lastName'] ?? '');
  $email     = trim($_POST['email'] ?? '');
  $phone     = trim($_POST['phone'] ?? '');
  $password  = $_POST['password'] ?? '';
  $role      = strtolower(trim($_POST['role'] ?? 'member'));
  $plan      = strtolower(trim($_POST['plan'] ?? 'premium'));

  // ---------------- VALIDATION ----------------
  if ($firstName === '' || $lastName === '' || $email === '' || $phone === '' || $password === '') {
    respond(["status"=>"error","message"=>"Missing required fields"],400);
  }

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(["status"=>"error","message"=>"Invalid email format"],400);
  }

  // normalize
  if (!in_array($role, ["member","trainer","admin"])) $role = "member";
  if (!in_array($plan, ["basic","premium","pro"])) $plan = "premium";

  // ---------------- DB CHECK ----------------
  $check = $conn->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
  if (!$check) {
    respond([
      "status"=>"error",
      "message"=>"DB prepare failed",
      "debug"=>$conn->error
    ],500);
  }

  $check->bind_param("s", $email);
  $check->execute();
  $res = $check->get_result();

  if ($res && $res->num_rows > 0) {
    respond(["status"=>"error","message"=>"Email already exists"],409);
  }

  $check->close();

  // ---------------- PASSWORD HASH ----------------
  $hash = password_hash($password, PASSWORD_BCRYPT);

  // ---------------- INSERT USER ----------------
  $stmt = $conn->prepare("
    INSERT INTO users (first_name, last_name, email, phone, password_hash, role, plan)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  ");

  if (!$stmt) {
    respond([
      "status"=>"error",
      "message"=>"Insert prepare failed",
      "debug"=>$conn->error
    ],500);
  }

  $stmt->bind_param(
    "sssssss",
    $firstName,
    $lastName,
    $email,
    $phone,
    $hash,
    $role,
    $plan
  );

  if (!$stmt->execute()) {
    respond([
      "status"=>"error",
      "message"=>"Insert failed",
      "debug"=>$stmt->error
    ],500);
  }

  $userId = $stmt->insert_id;
  $stmt->close();

  // ---------------- SUCCESS ----------------
  respond([
    "status" => "success",
    "message" => "Registered successfully",
    "user_id" => $userId,
    "plan" => $plan
  ]);

} catch (Throwable $e) {

  respond([
    "status" => "error",
    "message" => "Server error",
    "debug" => $e->getMessage()
  ], 500);
}