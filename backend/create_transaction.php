<?php
session_start();
header("Content-Type: application/json");
require_once "../db.php";

$user_id = $_SESSION["user_id"] ?? 0;
$role = $_SESSION["role"] ?? "";

if (!$user_id || $role !== "member") {
    http_response_code(401);
    echo json_encode(["ok"=>false]);
    exit;
}

$plan = $_POST["plan_code"] ?? "";
$billing = $_POST["billing_cycle"] ?? "";
$method = $_POST["payment_method"] ?? "";
$total = $_POST["total"] ?? 0;

if (!$plan || !$billing || !$method || !$total) {
    echo json_encode(["ok"=>false]);
    exit;
}

$order_id = "SF" . rand(10000,99999);

$stmt = $conn->prepare("INSERT INTO transactions
(order_id,user_id,plan_code,billing_cycle,payment_method,total)
VALUES (?,?,?,?,?,?)");

$stmt->bind_param("sisssd",
$order_id,$user_id,$plan,$billing,$method,$total);

if($stmt->execute()){
    echo json_encode(["ok"=>true,"order_id"=>$order_id]);
}else{
    echo json_encode(["ok"=>false]);
}
