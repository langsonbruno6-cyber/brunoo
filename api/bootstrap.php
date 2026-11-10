<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('bloodlink_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Strict',
        'path' => '/',
    ]);
    session_start();
}

function respond(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function require_method(string $method): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        respond(405, ['error' => 'Method not allowed']);
    }
}

function request_json(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) respond(400, ['error' => 'Invalid JSON request']);
    return $data;
}

function require_login(): array {
    if (empty($_SESSION['user'])) respond(401, ['error' => 'Please sign in']);
    return $_SESSION['user'];
}

function require_role(array $roles): array {
    $user = require_login();
    if (!in_array($user['role'] ?? '', $roles, true)) respond(403, ['error' => 'Insufficient permission']);
    return $user;
}

function require_csrf(): void {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $known = $_SESSION['csrf'] ?? '';
    if (!$known || !$sent || !hash_equals($known, $sent)) {
        respond(419, ['error' => 'Session token expired. Refresh and try again.']);
    }
}

$configFile = __DIR__ . '/../config/config.local.php';
if (!is_file($configFile)) {
    respond(503, ['error' => 'Server setup incomplete: config/config.local.php has not been created.']);
}
$config = require $configFile;
$db = $config['db'] ?? [];
foreach (['host','name','user','pass'] as $key) {
    if (!isset($db[$key]) || $db[$key] === '' || str_contains((string)$db[$key], 'YOUR_')) {
        respond(503, ['error' => 'Database configuration is incomplete.']);
    }
}
try {
    $pdo = new PDO(
        'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=' . ($db['charset'] ?? 'utf8mb4'),
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (Throwable $e) {
    error_log('BloodLink database connection failed: ' . $e->getMessage());
    respond(503, ['error' => 'Database connection failed. Check hosting configuration.']);
}
