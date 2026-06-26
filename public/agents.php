<?php
// Agents API
//   POST /agents.php?do=apply    -> register an agent (status: pending)
//   POST /agents.php?do=login    -> log in (must be approved) -> { token, agent }
//   POST /agents.php?do=logout   -> end the current session
//   GET  /agents.php?do=me       -> profile + referred clients + earnings (auth)

$config = require __DIR__ . '/../app/bootstrap.php';
cors($config['allowed_origins']);
security_headers();

const PAYOUT_METHODS = ['GCash', 'Maya', 'Bank', 'Other'];
const AGENT_SIGNUP_MAX_PER_IP = 2; // max applications from one IP per 24h

function agent_public(array $a): array
{
    return [
        'id'            => (int) $a['id'],
        'name'          => $a['name'],
        'email'         => $a['email'],
        'phone'         => $a['phone'],
        'payout_method' => $a['payout_method'],
        'payout_number' => $a['payout_number'],
        'ref_token'     => $a['ref_token'],
        'status'        => $a['status'],
        'created_at'    => $a['created_at'],
    ];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$do = $_GET['do'] ?? '';

try {
    $pdo = db();

    // ---------------------------------------------------------------- apply
    if ($method === 'POST' && $do === 'apply') {
        require_json();
        require_allowed_origin($config['allowed_origins']);
        $body = read_json_body();

        if (trim((string) ($body['hp'] ?? '')) !== '') {
            json_out(['ok' => true], 200); // honeypot
        }

        $ipHash = hash('sha256', client_ip() . '|' . $config['ip_salt']);

        $name     = field($body, 'name', 120);
        $email    = field($body, 'email', 160);
        $phone    = field($body, 'phone', 60);
        $password = (string) ($body['password'] ?? '');
        $payoutMethod = field($body, 'payoutMethod', 40);
        $payoutNumber = field($body, 'payoutNumber', 120);

        $errors = [];
        $set = function (string $k, ?string $m) use (&$errors) {
            if ($m !== null) $errors[$k] = $m;
        };
        $set('name', ckx_validate_name($name));
        $set('email', ckx_validate_email($email));
        $set('phone', ckx_validate_contact($phone));
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        } elseif (strlen($password) > 200) {
            $errors['password'] = 'Password is too long.';
        }
        if (!in_array($payoutMethod, PAYOUT_METHODS, true)) {
            $errors['payoutMethod'] = 'Please choose a payout method.';
        }
        if ($payoutNumber === '') {
            $errors['payoutNumber'] = 'Please enter your payout number/account.';
        }
        if ($errors) {
            json_out(['errors' => $errors], 422);
        }

        // Email must be unique.
        $exists = $pdo->prepare("SELECT 1 FROM agents WHERE email = ? LIMIT 1");
        $exists->execute([$email]);
        if ($exists->fetch()) {
            json_out(['errors' => ['email' => 'An account with this email already exists.']], 409);
        }

        // Per-IP signup limit (anti-spam): cap applications from one network per day.
        $sc = $pdo->prepare(
            "SELECT COUNT(*) FROM agents WHERE ip_hash = ? AND created_at > (NOW() - INTERVAL 1 DAY)"
        );
        $sc->execute([$ipHash]);
        if ((int) $sc->fetchColumn() >= AGENT_SIGNUP_MAX_PER_IP) {
            json_out(['error' => 'Too many applications from your network. Please try again later or contact us directly.'], 429);
        }

        $token = ckx_make_ref_token($pdo);
        $pdo->prepare(
            "INSERT INTO agents (name, email, phone, password_hash, payout_method, payout_number, ref_token, status, ip_hash)
             VALUES (?,?,?,?,?,?,?, 'pending', ?)"
        )->execute([
            $name,
            $email,
            $phone ?: null,
            password_hash($password, PASSWORD_DEFAULT),
            $payoutMethod,
            $payoutNumber,
            $token,
            $ipHash,
        ]);

        json_out([
            'ok' => true,
            'message' => 'Application received. We will review it and email you once approved.',
        ], 201);
    }

    // Login is handled by the unified /login.php (with brute-force throttling).

    // --------------------------------------------------------------- logout
    if ($method === 'POST' && $do === 'logout') {
        ckx_logout($pdo);
        json_out(['ok' => true]);
    }

    // ------------------------------------------------------------------- me
    if ($method === 'GET' && $do === 'me') {
        $agent = ckx_require_agent($pdo);
        $rate = (float) $config['commission_rate'];

        $stmt = $pdo->prepare(
            "SELECT reference, name, business_name, project_title, path,
                    deal_amount, deal_status, created_at
             FROM project_requests WHERE agent_id = ? ORDER BY id DESC"
        );
        $stmt->execute([(int) $agent['id']]);
        $clients = $stmt->fetchAll();

        $won = 0;
        $pending = 0;
        $earnings = 0.0;
        foreach ($clients as &$c) {
            $c['deal_amount'] = $c['deal_amount'] !== null ? (float) $c['deal_amount'] : null;
            $c['commission'] = ($c['deal_status'] === 'won' && $c['deal_amount'])
                ? round($c['deal_amount'] * $rate, 2) : 0.0;
            if ($c['deal_status'] === 'won') {
                $won++;
                $earnings += $c['commission'];
            } elseif ($c['deal_status'] === 'lead') {
                $pending++;
            }
        }
        unset($c);

        json_out([
            'agent' => agent_public($agent),
            'stats' => [
                'referrals' => count($clients),
                'won'       => $won,
                'pending'   => $pending,
                'earnings'  => round($earnings, 2),
                'rate'      => $rate,
            ],
            'clients' => $clients,
        ]);
    }

    json_out(['error' => 'Not found.'], 404);
} catch (Throwable $e) {
    error_log('CKX agents error: ' . $e->getMessage());
    json_out(['error' => 'Something went wrong. Please try again.'], 500);
}
