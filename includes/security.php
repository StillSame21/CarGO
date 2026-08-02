<?php

// Starts a session with strict, cookie-only, HttpOnly/SameSite=Lax settings. No-op if already started.
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

// This session's CSRF token, generating one on first use.
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        regenerateCsrfToken();
    }

    return (string) $_SESSION['csrf_token'];
}

// Issues a fresh CSRF token for this session, invalidating the old one.
function regenerateCsrfToken(): string
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    return (string) $_SESSION['csrf_token'];
}

// Hidden CSRF token field, ready to drop into any state-changing <form>.
function csrfInput(): string
{
    $token = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');

    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

// True if $token matches this session's CSRF token via a timing-safe comparison.
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

// True if the current session is a demo login (customer or admin).
function isDemoSession(): bool
{
    return isset($_SESSION['is_demo']) && $_SESSION['is_demo'] === true;
}

// Rejects a demo session's write with a flash notice, since demo accounts are shared and public.
function blockDemoWrite(string $redirect): void
{
    if (!isDemoSession()) {
        return;
    }

    $_SESSION['demo_notice'] = 'Demo mode: this action is disabled.';
    header('Location: ' . $redirect);
    exit;
}

// Clears session data, expires the session cookie, and destroys the session.
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
