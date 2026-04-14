<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';

start_app_session();
header('Content-Type: application/json');

// Only logged-in users
if (!current_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = (string)($_POST['action'] ?? '');

if ($action === 'add_customer') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        echo json_encode(['error' => 'Name is required.']);
        exit;
    }

    try {
        // Check for duplicate name
        $check = db()->prepare("SELECT id, name FROM customers WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $check->execute([$name]);
        $existing = $check->fetch();
        if ($existing) {
            echo json_encode(['error' => 'duplicate', 'existing_name' => $existing['name']]);
            exit;
        }

        db()->prepare("INSERT INTO customers (name) VALUES (?)")->execute([$name]);
        $id = (int)db()->lastInsertId();
        echo json_encode([
            'ok' => true,
            'customer' => [
                'id' => $id,
                'name' => $name,
                'loyalty_games' => 0,
                'loyalty_vip_games' => 0,
            ]
        ]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['error' => 'Invalid action.']);
