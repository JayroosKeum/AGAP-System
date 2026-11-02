<?php

declare(strict_types=1);

header('Location: frontend/pages/auth/verify-email.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 302);
exit;
