<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

start_app_session();
logout();
redirect('index.php');

