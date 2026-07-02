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
    ckx_login_guard($pdo, $ipHash); // per-IP brute-force throttle

    // Per-account throttle (catches distributed brute force spread across IPs).
    // Keyed on the email hash whether or not the account exists — no enumeration.
    $acctKey = hash('sha256', 'login|' . strtolower($email) . '|' . $config['ip_salt']);
    try {
        if (ckx_rate_recent($pdo, 'login-fail', $acctKey, 900) >= 10) {
            json_out(['error' => 'Too many login attempts. Please try again in a few minutes.'], 429);
        }
    } catch (Throwable $e) {
        /* rate_hits table not present yet — skip the per-account guard */
    }

    // 1) Admin (owner) account (DB-backed, falls back to .env).
    $admin = ckx_admin_account($pdo, $config['admin']);
    if (
        strtolower($admin['email']) === strtolower($email)
        && $admin['password_hash'] !== ''
        && password_verify($password, $admin['password_hash'])
    ) {
        ckx_login_record($pdo, $ipHash, true);
        ckx_rate_clear($pdo, 'login-fail', $acctKey);
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
        ckx_rate_clear($pdo, 'login-fail', $acctKey);
        $token = ckx_issue_token($pdo, 'agent', (int) $agent['id']);
        json_out(['ok' => true, 'role' => 'agent', 'token' => $token]);
    }

    // Generic message — never reveal which role an email belongs to.
    ckx_login_record($pdo, $ipHash, false);
    try {
        ckx_rate_add($pdo, 'login-fail', $acctKey);
    } catch (Throwable $e) {
        /* rate_hits table not present yet — ignore */
    }
    json_out(['error' => 'Wrong email or password.'], 401);
} catch (Throwable $e) {
    error_log('CKX login error: ' . $e->getMessage());
    json_out(['error' => 'Something went wrong. Please try again.'], 500);
}
