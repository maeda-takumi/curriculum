<?php

declare(strict_types=1);

function feedbacks_file_path(): string
{
    return __DIR__ . '/feedbacks.json';
}

function feedback_upload_directory(): string
{
    return __DIR__ . '/../feedback_uploads';
}

function feedback_normalize_curriculum(mixed $curriculum): string
{
    $value = (string)$curriculum;

    return in_array($value, ['practice', 'lesson', 'claude', 'claude_lesson'], true) ? $value : 'practice';
}

/**
 * @return array<string, string>
 */
function feedback_curriculum_options(): array
{
    return [
        'practice' => 'Practice',
        'lesson' => 'Lesson',
        'claude' => 'Claude',
        'claude_lesson' => 'Claude Lesson',
    ];
}

/**
 * @return array<int, array{id:int,user_id:int,curriculum:string,name:string,html_file:string,created_at:string,updated_at:string}>
 */
function load_feedbacks(): array
{
    $path = feedbacks_file_path();
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [];
    }

    $feedbacks = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }

        $id = (int)($row['id'] ?? 0);
        $userId = (int)($row['user_id'] ?? 0);
        $name = trim((string)($row['name'] ?? ''));
        $htmlFile = basename((string)($row['html_file'] ?? ''));
        if ($id <= 0 || $userId <= 0 || $name === '' || $htmlFile === '') {
            continue;
        }

        $feedbacks[] = [
            'id' => $id,
            'user_id' => $userId,
            'curriculum' => feedback_normalize_curriculum($row['curriculum'] ?? 'practice'),
            'name' => $name,
            'html_file' => $htmlFile,
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    usort($feedbacks, static fn(array $a, array $b): int => ($b['id'] <=> $a['id']));

    return $feedbacks;
}

function save_feedbacks(array $feedbacks): void
{
    $json = json_encode(array_values($feedbacks), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Failed to encode feedbacks.json.');
    }

    if (file_put_contents(feedbacks_file_path(), $json, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write feedbacks.json.');
    }
}

function next_feedback_id(array $feedbacks): int
{
    $ids = array_map(static fn(array $feedback): int => (int)($feedback['id'] ?? 0), $feedbacks);

    return $ids === [] ? 1 : (max($ids) + 1);
}

function feedback_upload_path(string $htmlFile): string
{
    return feedback_upload_directory() . '/' . basename($htmlFile);
}

function find_feedback_by_id(int $id): ?array
{
    foreach (load_feedbacks() as $feedback) {
        if ((int)$feedback['id'] === $id) {
            return $feedback;
        }
    }

    return null;
}

/**
 * @param array<int, array<string, mixed>> $users
 */
function find_feedback_user(array $users, int $userId): ?array
{
    foreach ($users as $user) {
        if ((int)($user['id'] ?? 0) === $userId) {
            return $user;
        }
    }

    return null;
}

function feedback_absolute_url(string $path): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host . $path;
}
