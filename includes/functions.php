<?php
/**
 * Global Helper Functions
 * ERP v2 - Phase 1
 */

/**
 * Generate UUID v4
 */
function generateUUID(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

/**
 * Escape HTML output
 */
function e(string $string): string {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Get current request ID for audit trail
 */
function getRequestId(): string {
    return $_SESSION['request_id'] ?? generateUUID();
}

/**
 * Get client IP address
 */
function getClientIP(): string {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Get user agent
 */
function getUserAgent(): string {
    return $_SERVER['HTTP_USER_AGENT'] ?? '';
}

/**
 * Redirect to URL
 */
function redirect(string $url): void {
    header("Location: $url");
    exit;
}

/**
 * Set flash message
 */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Get and clear flash message
 */
function getFlash(): ?array {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * Check if request is POST
 */
function isPost(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/**
 * Get POST value with default
 */
function post(string $key, $default = null) {
    return $_POST[$key] ?? $default;
}

/**
 * Get GET value with default
 */
function get(string $key, $default = null) {
    return $_GET[$key] ?? $default;
}

/**
 * CSRF token generation
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCsrf(string $token): bool {
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/**
 * Format date for display
 */
function formatDate(?string $date, string $format = 'd/m/Y'): string {
    if (!$date) return '-';
    return date($format, strtotime($date));
}

/**
 * Format datetime for display
 */
function formatDateTime(?string $datetime, string $format = 'd/m/Y H:i'): string {
    if (!$datetime) return '-';
    return date($format, strtotime($datetime));
}

/**
 * Format number with Thai formatting
 */
function formatNumber($number, int $decimals = 2): string {
    return number_format((float)$number, $decimals, '.', ',');
}

/**
 * JSON response helper
 */
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Check if all required fields are present
 */
function hasRequiredFields(array $data, array $required): bool {
    foreach ($required as $field) {
        if (empty($data[$field])) {
            return false;
        }
    }
    return true;
}

/**
 * Sanitize input for SQL (use prepared statements instead when possible)
 */
function sanitize(string $input): string {
    return trim(strip_tags($input));
}

/**
 * Get base URL
 */
function baseUrl(): string {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "$protocol://$host";
}
