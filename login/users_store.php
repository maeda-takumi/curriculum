<?php

declare(strict_types=1);

function users_file_path(): string
{
    return __DIR__ . '/users.json';
}

function normalize_status(mixed $status): string
{
    return ((string)$status) === 'active' ? 'active' : 'inactive';
}
function normalize_role(mixed $role): string
{
    return ((string)$role) === 'admin' ? 'admin' : 'user';
}
/**
 * @return array<string, bool>
 */
function default_lesson_week_locks(): array
{
    return [
        'week1' => false,
        'week2' => false,
        'week3' => false,
        'week4' => false,
        'week5' => false,
        'week6' => false,
        'week7' => false,
        'week8' => false,
        'week9' => false,
        'week10' => false,
        'week11' => false,
        'week12' => false,
    ];
}

/**
 * @return array<string, bool>
 */
function normalize_lesson_week_locks(mixed $weekLocks): array
{
    $normalized = default_lesson_week_locks();
    if (!is_array($weekLocks)) {
        return $normalized;
    }

    foreach ($normalized as $weekKey => $_) {
        $normalized[$weekKey] = filter_var($weekLocks[$weekKey] ?? false, FILTER_VALIDATE_BOOL);
    }

    return $normalized;
}
/**
 * @return array<string, bool>
 */
function default_phase_locks(): array
{
    return [
        'phase0' => false,
        'phase1' => false,
        'phase2' => false,
        'phase3' => false,
        'phase4' => false,
        'phase5' => false,
        'phase6' => false,
        'phase7' => false,
        'phase8' => false,
    ];
}

/**
 * @return array<string, bool>
 */
function normalize_phase_locks(mixed $phaseLocks): array
{
    $normalized = default_phase_locks();
    if (!is_array($phaseLocks)) {
        return $normalized;
    }

    foreach ($normalized as $phaseKey => $_) {
        $normalized[$phaseKey] = filter_var($phaseLocks[$phaseKey] ?? false, FILTER_VALIDATE_BOOL);
    }

    return $normalized;
}
/**
 * @return array<string, bool>
 */
function default_claude_phase_locks(): array
{
    return default_phase_locks();
}

/**
 * @return array<string, bool>
 */
function normalize_claude_phase_locks(mixed $phaseLocks): array
{
    return normalize_phase_locks($phaseLocks);
}
function load_users(): array
{
    $path = users_file_path();
    if (!is_file($path)) {
        $defaultPassword = 'password123';
        $default = [[
            'id' => 1,
            'line_name' => 'admin',
            'real_name' => '',
            'email' => 'admin@example.com',
            'password' => $defaultPassword,
            'password_hash' => password_hash($defaultPassword, PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'admin',
        ]];
        save_users($default);
        return $default;
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [];
    }

    $normalized = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }

        $legacyUsername = trim((string)($row['username'] ?? ''));
        $email = trim((string)($row['email'] ?? ''));
        if ($email === '') {
            $email = $legacyUsername;
        }

        $lineName = trim((string)($row['line_name'] ?? ''));
        if ($lineName === '') {
            $lineName = $legacyUsername !== '' ? $legacyUsername : $email;
        }
        $realName = trim((string)($row['real_name'] ?? ''));
        $password = (string)($row['password'] ?? '');
        $passwordHash = (string)($row['password_hash'] ?? '');

        $normalized[] = [
            'id' => (int)($row['id'] ?? 0),
            'line_name' => $lineName,
            'real_name' => $realName,
            'email' => $email,
            'password' => $password,
            'password_hash' => $passwordHash,
            'status' => normalize_status($row['status'] ?? 'inactive'),
            'role' => normalize_role($row['role'] ?? 'user'),
            'phase_locks' => normalize_phase_locks($row['phase_locks'] ?? null),
            'claude_phase_locks' => normalize_claude_phase_locks($row['claude_phase_locks'] ?? null),
            'lesson_week_locks' => normalize_lesson_week_locks($row['lesson_week_locks'] ?? null),
        ];
    }

    return $normalized;
}

function save_users(array $users): void
{
    file_put_contents(users_file_path(), json_encode(array_values($users), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function next_user_id(array $users): int
{
    $ids = array_column($users, 'id');
    return $ids === [] ? 1 : ((int)max($ids) + 1);
}
