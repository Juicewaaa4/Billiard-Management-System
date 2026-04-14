<?php
$_SESSION['user'] = ['id' => 2, 'username' => 'cashier_test', 'role' => 'cashier'];
ob_start();
require '../kubo.php';
file_put_contents('test_cashier_output.txt', ob_get_clean());
