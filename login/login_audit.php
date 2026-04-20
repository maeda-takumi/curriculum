<?php

declare(strict_types=1);

function login_audit_file_path(): string
{
    return __DIR__ . '/login_attempts.jsonl';
}

/**
 * @param array<string, mixed> $context
 */
function write_login_audit_log(string $result, string $emailInput, string $passwordInput, array $context = []): void
{
    $record = [
        'timestamp' => gmdate('c'),
        'result' => $result,
        'email_input' => $emailInput,
        // NOTE: パスワードの平文は残さず、照合用に長さとハッシュのみ保存する
        'password_length' => mb_strlen($passwordInput, '8bit'),
        'password_sha256' => hash('sha256', $passwordInput),
        'context' => $context,
    ];

    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    file_put_contents(login_audit_file_path(), $json . PHP_EOL, FILE_APPEND | LOCK_EX);
}
