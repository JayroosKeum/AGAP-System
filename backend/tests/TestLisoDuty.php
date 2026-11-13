<?php

declare(strict_types=1);

function startTestLisoDuty(PDO $db, int $lisoId): int
{
    $db->prepare('INSERT INTO liso_duty_sessions (user_id, started_by) VALUES (?, ?)')
        ->execute([$lisoId, $lisoId]);
    $sessionId = (int) $db->lastInsertId();
    register_shutdown_function(static function () use ($db, $sessionId): void {
        $db->prepare('DELETE FROM liso_duty_sessions WHERE duty_session_id = ?')->execute([$sessionId]);
    });
    return $sessionId;
}
