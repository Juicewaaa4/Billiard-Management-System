<?php
$_SESSION['user'] = ['id' => 2, 'username' => 'cashier_test', 'role' => 'cashier'];
ob_start();
require __DIR__ . '/../kubo.php';
file_put_contents(__DIR__ . '/test_cashier_output.txt', ob_get_clean());
