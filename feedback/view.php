<?php

declare(strict_types=1);

require_once __DIR__ . '/../login/feedback_auth.php';
require_once __DIR__ . '/../login/feedback_store.php';

$currentUser = require_feedback_user();
$isAdmin = normalize_role($currentUser['role'] ?? 'user') === 'admin';
$currentUserId = (int)($currentUser['id'] ?? 0);
$feedbackId = (int)($_GET['id'] ?? 0);
$feedback = $feedbackId > 0 ? find_feedback_by_id($feedbackId) : null;

if ($feedback === null) {
    http_response_code(404);
    echo '404 Not Found';
    exit;
}

if (!$isAdmin && (int)$feedback['user_id'] !== $currentUserId) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

$htmlPath = feedback_upload_path((string)$feedback['html_file']);
$uploadDir = realpath(feedback_upload_directory());
$realHtmlPath = realpath($htmlPath);

if ($uploadDir === false || $realHtmlPath === false || strncmp($realHtmlPath, $uploadDir, strlen($uploadDir)) !== 0 || !is_file($realHtmlPath)) {
    http_response_code(404);
    echo 'HTML file not found.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
readfile($realHtmlPath);
