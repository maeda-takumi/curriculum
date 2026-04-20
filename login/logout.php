<?php

declare(strict_types=1);

session_start();
$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/login/logout.php');
$scriptDir = str_replace('\\', '/', dirname($scriptName));
$appBase = str_replace('\\', '/', dirname($scriptDir));
if ($appBase === '.' || $appBase === '/') {
    $appBase = '';
}
$curriculum = (string)($_GET['curriculum'] ?? '');
$nextPath = ($appBase === '' ? '' : rtrim($appBase, '/')) . '/?page=index';
if ($curriculum === 'lesson') {
    $nextPath .= '&curriculum=lesson';
}
$_SESSION = [];
session_destroy();

header('Location: index.php?next=' . rawurlencode($nextPath));
exit;
