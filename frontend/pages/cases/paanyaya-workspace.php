<?php
$queryString = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ../complaints/paanyaya-workspace.php' . ($queryString !== '' ? '?' . $queryString : ''));
exit;
