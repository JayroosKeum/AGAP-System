<?php

declare(strict_types=1);

// Azure App Service serves the repository root. Keep the existing frontend
// layout while providing the default document expected by the web server.
header('Location: frontend/pages/landing/index.php', true, 302);
exit;
