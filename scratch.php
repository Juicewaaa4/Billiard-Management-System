<?php
require 'config/database.php';
// End any karaoke game_sessions that belong to kubo tables which do NOT have an active kubo_rental
$stmt = db()->prepare("
    UPDATE game_sessions gs
    JOIN tables t ON t.id = gs.table_id
    SET gs.end_time = NOW(), gs.is_voided = 1, gs.void_reason = 'System cleanup (orphaned)'
    WHERE gs.end_time IS NULL AND t.type = 'kubo' 
    AND t.id NOT IN (SELECT table_id FROM kubo_rentals WHERE status = 'active' AND is_voided = 0)
");
$stmt->execute();
echo "Cleaned up orphaned sessions.";
