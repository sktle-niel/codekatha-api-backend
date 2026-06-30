<?php
// Unified login for both the admin (owner) and agents. One form — the server
// decides the role, issues a role-stamped token, and tells the client where to
// go. Every protected endpoint still enforces its own role, so this is safe.
//   POST /login.php  { email, password }  ->  { role: 'admin'|'agent', token }

$config = require __DIR__ . '/../app/bootstrap.php';
cors($config['allowed_origins']);
security_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(['error' => 'Method not allowed. Use POST.'], 405);
}
require_json();
require_allowed_origin($config['allowed_origins']);

try {
    $pdo = db();
    $body = read_json_body();
    $email = field($body, 'email', 160);
    $password = (string) ($body['password'] ?? '');

    $ipHash = hash('sha256', client_ip() . '|' . $config['ip_salt']);
    ckx_login_guard($pdo, $ipHash); // brute-force throttle

    // 1) Admin (owner) account (DB-backed, falls back to .env).
    $admin = ckx_admin_account($pdo, $config['admin']);
    if (
        strtolower($admin['email']) === strtolower($email)
        && $admin['password_hash'] !== ''
        && password_verify($password, $admin['password_hash'])
    ) {
        ckx_login_record($pdo, $ipHash, true);
        $token = ckx_issue_token($pdo, 'admin', 0); // sliding session (see CKX_SESSION_DAYS)
        json_out(['ok' => true, 'role' => 'admin', 'token' => $token]);
    }

    // 2) Agent account.
    $stmt = $pdo->prepare("SELECT * FROM agents WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $agent = $stmt->fetch();
    if ($agent && password_verify($password, $agent['password_hash'])) {
        if ($agent['status'] === 'pending') {
            json_out(['error' => 'Your application is still pending approval.'], 403);
        }
        if ($agent['status'] !== 'approved') {
            json_out(['error' => 'This account is not active.'], 403);
        }
        ckx_login_record($pdo, $ipHash, true);
        $token = ckx_issue_token($pdo, 'agent', (int) $agent['id']);
        json_out(['ok' => true, 'role' => 'agent', 'token' => $token]);
    }

    // Generic message — never reveal which role an email belongs to.
    ckx_login_record($pdo, $ipHash, false);
    json_out(['error' => 'Wrong email or password.'], 401);
} catch (Throwable $e) {
    error_log('CKX login error: ' . $e->getMessage());
    json_out(['error' => 'Something went wrong. Please try again.'], 500);
}
