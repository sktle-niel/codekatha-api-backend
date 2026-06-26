<?php
// Token-based auth for agents and the admin. Opaque bearer tokens are stored
// only as a sha256 hash in the `sessions` table; the raw token lives client-side.

function ckx_bearer_token(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') {
                $h = $v;
                break;
            }
        }
    }
    return stripos($h, 'Bearer ') === 0 ? trim(substr($h, 7)) : '';
}

// Create a session and return the raw token to hand to the client.
function ckx_issue_token(PDO $pdo, string $userType, int $userId, int $days = 30): string
{
    $token = bin2hex(random_bytes(32));
    $pdo->prepare(
        "INSERT INTO sessions (user_type, user_id, token_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))"
    )->execute([$userType, $userId, hash('sha256', $token), $days]);

    // Opportunistic cleanup of expired sessions.
    $pdo->query("DELETE FROM sessions WHERE expires_at < NOW()");
    return $token;
}

// The current session ['user_type','user_id'] for a valid bearer token, or null.
function ckx_current_session(PDO $pdo): ?array
{
    $token = ckx_bearer_token();
    if ($token === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT user_type, user_id FROM sessions
         WHERE token_hash = ? AND expires_at > NOW() LIMIT 1"
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function ckx_logout(PDO $pdo): void
{
    $token = ckx_bearer_token();
    if ($token !== '') {
        $pdo->prepare("DELETE FROM sessions WHERE token_hash = ?")
            ->execute([hash('sha256', $token)]);
    }
}

// Returns the authenticated, approved agent row — or sends 401/403 and exits.
function ckx_require_agent(PDO $pdo): array
{
    $s = ckx_current_session($pdo);
    if (!$s || $s['user_type'] !== 'agent') {
        json_out(['error' => 'Please log in.'], 401);
    }
    $stmt = $pdo->prepare("SELECT * FROM agents WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $s['user_id']]);
    $agent = $stmt->fetch();
    if (!$agent) {
        json_out(['error' => 'Please log in.'], 401);
    }
    if ($agent['status'] !== 'approved') {
        json_out(['error' => 'Your account is not active yet.'], 403);
    }
    return $agent;
}

// Returns true if the current bearer token is the admin — or sends 401 and exits.
function ckx_require_admin(PDO $pdo): void
{
    $s = ckx_current_session($pdo);
    if (!$s || $s['user_type'] !== 'admin') {
        json_out(['error' => 'Please log in.'], 401);
    }
}

// Brute-force guard: block an IP after too many recent failed logins.
const CKX_LOGIN_MAX_FAILS = 10;   // failed attempts...
const CKX_LOGIN_WINDOW_MIN = 15;  // ...within this many minutes -> blocked

function ckx_login_guard(PDO $pdo, string $ipHash): void
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE ip_hash = ? AND success = 0
           AND created_at > (NOW() - INTERVAL " . CKX_LOGIN_WINDOW_MIN . " MINUTE)"
    );
    $stmt->execute([$ipHash]);
    if ((int) $stmt->fetchColumn() >= CKX_LOGIN_MAX_FAILS) {
        json_out(['error' => 'Too many login attempts. Please try again in a few minutes.'], 429);
    }
}

function ckx_login_record(PDO $pdo, string $ipHash, bool $success): void
{
    $pdo->prepare("INSERT INTO login_attempts (ip_hash, success) VALUES (?, ?)")
        ->execute([$ipHash, $success ? 1 : 0]);
    // Occasional cleanup of old rows.
    if (random_int(1, 25) === 1) {
        $pdo->query("DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    }
}

// A unique short referral code for a new agent.
function ckx_make_ref_token(PDO $pdo): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($try = 0; $try < 6; $try++) {
        $code = '';
        $bytes = random_bytes(8);
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[ord($bytes[$i]) % 31];
        }
        $stmt = $pdo->prepare("SELECT 1 FROM agents WHERE ref_token = ? LIMIT 1");
        $stmt->execute([$code]);
        if (!$stmt->fetch()) {
            return $code;
        }
    }
    return 'A' . bin2hex(random_bytes(4));
}
