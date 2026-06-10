<?php
session_start();

$plan = strtolower(trim($_GET["plan"] ?? ""));
$cycle = strtolower(trim($_GET["cycle"] ?? "monthly"));

if (!in_array($plan, ["premium","pro"], true)) {
  header("Location: pricing.html"); exit;
}

$role = strtolower(trim($_SESSION["role"] ?? ""));
$user_id = (int)($_SESSION["user_id"] ?? 0);

if ($user_id <= 0 || $role !== "member") {
  header("Location: login.html");
  exit;
}
?>
<!doctype html>
<html><body>
<form id="f" action="create_checkout_session.php" method="POST">
  <input type="hidden" name="plan" value="<?= htmlspecialchars($plan) ?>">
  <input type="hidden" name="cycle" value="<?= htmlspecialchars($cycle) ?>">
</form>
<script>document.getElementById('f').submit();</script>
</body></html>
