<?php

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        regenerateCsrfToken();
    }

    return (string) $_SESSION['csrf_token'];
}

function regenerateCsrfToken(): string
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    return (string) $_SESSION['csrf_token'];
}

function csrfInput(): string
{
    $token = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');

    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function isValidCsrfToken(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals((string) $_SESSION['csrf_token'], $token);
}

function requireValidCsrfToken(): void
{
    // Every state-changing POST must include the token from the current session.
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        throw new InvalidArgumentException('Security token expired. Please refresh and try again.');
    }
}

function destroySecureSession(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }

    session_destroy();
}
