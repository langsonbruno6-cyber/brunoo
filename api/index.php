<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($action === 'login' && $method === 'POST') {
    $input = request_json();
    $email = filter_var(trim((string)($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $password = (string)($input['password'] ?? '');
    if (!$email || $password === '') respond(422, ['error' => 'Email and password are required']);
    $stmt = $pdo->prepare('SELECT id, full_name, email, password_hash, role, is_active FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !(int)$user['is_active'] || !password_verify($password, $user['password_hash'])) {
        respond(401, ['error' => 'Incorrect email or password']);
    }
    session_regenerate_id(true);
    unset($user['password_hash'], $user['is_active']);
    $_SESSION['user'] = $user;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    respond(200, ['user' => $user, 'csrf' => $_SESSION['csrf']]);
}

if ($action === 'logout' && $method === 'POST') {
    require_login(); require_csrf();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
    respond(200, ['ok' => true]);
}

if ($action === 'me' && $method === 'GET') {
    $user = require_login();
    respond(200, ['user' => $user, 'csrf' => $_SESSION['csrf'] ?? null]);
}

if ($action === 'donors' && $method === 'GET') {
    require_role(['admin','doctor','staff']);
    $q = trim((string)($_GET['q'] ?? ''));
    $group = trim((string)($_GET['group'] ?? ''));
    $sql = 'SELECT id, full_name, student_id, phone, blood_group, location, availability, consent_to_contact, created_at FROM donors WHERE is_active = 1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (full_name LIKE ? OR student_id LIKE ? OR phone LIKE ?)';
        $like = '%' . $q . '%'; array_push($params, $like, $like, $like);
    }
    if ($group !== '') { $sql .= ' AND blood_group = ?'; $params[] = $group; }
    $sql .= ' ORDER BY created_at DESC LIMIT 500';
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    respond(200, ['donors' => $stmt->fetchAll()]);
}

if ($action === 'donors' && $method === 'POST') {
    $user = require_role(['admin','doctor','staff']); require_csrf();
    $in = request_json();
    $name = trim((string)($in['full_name'] ?? ''));
    $student = trim((string)($in['student_id'] ?? ''));
    $phone = trim((string)($in['phone'] ?? ''));
    $group = (string)($in['blood_group'] ?? '');
    $location = trim((string)($in['location'] ?? ''));
    $available = ($in['availability'] ?? 'available') === 'unavailable' ? 'unavailable' : 'available';
    $groups = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];
    if ($name === '' || $student === '' || !preg_match('/^\+?[0-9][0-9 ]{7,17}$/', $phone) || !in_array($group, $groups, true) || empty($in['consent_to_contact'])) {
        respond(422, ['error' => 'Enter valid donor details and confirm consent']);
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO donors (full_name,student_id,phone,blood_group,location,availability,consent_to_contact,consent_recorded_at,created_by) VALUES (?,?,?,?,?,?,1,NOW(),?)');
        $stmt->execute([$name,$student,$phone,$group,$location,$available,$user['id']]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') respond(409, ['error' => 'Student ID already exists']);
        throw $e;
    }
    respond(201, ['id' => (int)$pdo->lastInsertId(), 'message' => 'Donor saved']);
}

if ($action === 'requests' && $method === 'GET') {
    require_role(['admin','doctor','staff']);
    $stmt = $pdo->query('SELECT id, request_code, facility_name, contact_phone, blood_group, units_requested, urgency, needed_by, notes, status, created_at FROM emergency_requests ORDER BY created_at DESC LIMIT 300');
    respond(200, ['requests' => $stmt->fetchAll()]);
}

if ($action === 'requests' && $method === 'POST') {
    $user = require_role(['admin','doctor','staff']); require_csrf();
    $in = request_json();
    $facility = trim((string)($in['facility_name'] ?? ''));
    $phone = trim((string)($in['contact_phone'] ?? ''));
    $group = (string)($in['blood_group'] ?? '');
    $units = filter_var($in['units_requested'] ?? null, FILTER_VALIDATE_INT);
    $urgency = (string)($in['urgency'] ?? 'urgent');
    if ($facility === '' || $phone === '' || !in_array($group,['A+','A-','B+','B-','AB+','AB-','O+','O-'],true) || !$units || $units < 1 || $units > 30 || !in_array($urgency,['routine','urgent','critical'],true)) {
        respond(422, ['error' => 'Check facility, phone, blood group, units and urgency']);
    }
    $code = 'BL-' . strtoupper(bin2hex(random_bytes(4)));
    $stmt = $pdo->prepare('INSERT INTO emergency_requests (request_code,facility_name,contact_phone,blood_group,units_requested,urgency,needed_by,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$code,$facility,$phone,$group,$units,$urgency,$in['needed_by'] ?: null,trim((string)($in['notes'] ?? '')),$user['id']]);
    respond(201, ['id' => (int)$pdo->lastInsertId(), 'request_code' => $code]);
}

if ($action === 'matches' && $method === 'GET') {
    require_role(['admin','doctor','staff']);
    $requestId = filter_var($_GET['request_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$requestId) respond(422, ['error' => 'Valid request_id is required']);
    $stmt = $pdo->prepare('SELECT blood_group FROM emergency_requests WHERE id = ?');
    $stmt->execute([$requestId]); $request = $stmt->fetch();
    if (!$request) respond(404, ['error' => 'Request not found']);
    $stmt = $pdo->prepare("SELECT id,full_name,student_id,phone,blood_group,location,availability FROM donors WHERE is_active=1 AND consent_to_contact=1 AND availability='available' AND blood_group=? ORDER BY full_name LIMIT 200");
    $stmt->execute([$request['blood_group']]);
    respond(200, ['notice' => 'Same recorded blood group is only a preliminary contact filter; clinical compatibility and donor eligibility must be assessed by qualified staff.', 'donors' => $stmt->fetchAll()]);
}

respond(404, ['error' => 'Unknown API action']);
