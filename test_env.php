<?php
header('Content-Type: application/json');
echo json_encode([
  "MYSQLHOST"     => getenv('MYSQLHOST'),
  "MYSQLUSER"     => getenv('MYSQLUSER'),
  "MYSQLPASSWORD" => getenv('MYSQLPASSWORD') ? "***SET***" : "NOT SET",
  "MYSQLDATABASE" => getenv('MYSQLDATABASE'),
  "MYSQLPORT"     => getenv('MYSQLPORT'),
  "STRIPE_KEY"    => getenv('STRIPE_SECRET_KEY') ? "***SET***" : "NOT SET",
], JSON_PRETTY_PRINT);
