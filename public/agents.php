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
    // Profile + totals (computed across ALL referrals). The client list itself
    // is fetched separately and paginated via ?do=clients.
    if ($method === 'GET' && $do === 'me') {
        $agent = ckx_require_agent($pdo);
        $rate = (float) $config['commission_rate'];

        $agg = $pdo->prepare(
            "SELECT COUNT(*) referrals,
                    COALESCE(SUM(deal_status='won'),0) won,
                    COALESCE(SUM(deal_status='lead'),0) pending,
                    COALESCE(SUM(CASE WHEN deal_status='won' THEN deal_amount ELSE 0 END),0) won_revenue
             FROM project_requests WHERE agent_id = ?"
        );
        $agg->execute([(int) $agent['id']]);
        $s = $agg->fetch();

        json_out([
            'agent' => agent_public($agent),
            'stats' => [
                'referrals' => (int) $s['referrals'],
                'won'       => (int) $s['won'],
                'pending'   => (int) $s['pending'],
                'earnings'  => round(((float) $s['won_revenue']) * $rate, 2),
                'rate'      => $rate,
            ],
        ]);
    }

    // Distinct dates (newest first) that this agent has referrals on — for filters.
    if ($method === 'GET' && $do === 'dates') {
        $agent = ckx_require_agent($pdo);
        $stmt = $pdo->prepare(
            "SELECT DISTINCT DATE(created_at) d FROM project_requests
             WHERE agent_id = ? ORDER BY d DESC"
        );
        $stmt->execute([(int) $agent['id']]);
        json_out(['dates' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
    }

    // --------------------------------------------------------- clients (page)
    if ($method === 'GET' && $do === 'clients') {
        $agent = ckx_require_agent($pdo);
        $rate = (float) $config['commission_rate'];
        $aid = (int) $agent['id'];

        [$cond, $dp] = ckx_date_filter(
            (string) ($_GET['month'] ?? ''),
            (string) ($_GET['day'] ?? '')
        );
        $where = "agent_id = ? AND $cond";
        $params = array_merge([$aid], $dp);

        $limit = 10;
        $ct = $pdo->prepare("SELECT COUNT(*) FROM project_requests WHERE $where");
        $ct->execute($params);
        $total = (int) $ct->fetchColumn();
        $pages = max(1, (int) ceil($total / $limit));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $limit; // both ints, safe to inline

        $stmt = $pdo->prepare(
            "SELECT reference, name, business_name, project_title, path,
                    deal_amount, deal_status, created_at
             FROM project_requests WHERE $where
             ORDER BY id DESC LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);
        $clients = $stmt->fetchAll();
        foreach ($clients as &$c) {
            $c['deal_amount'] = $c['deal_amount'] !== null ? (float) $c['deal_amount'] : null;
            $c['commission'] = ($c['deal_status'] === 'won' && $c['deal_amount'])
                ? round($c['deal_amount'] * $rate, 2) : 0.0;
        }
        unset($c);

        json_out([
            'clients'  => $clients,
            'page'     => $page,
            'pages'    => $pages,
            'total'    => $total,
            'has_more' => $page < $pages,
        ]);
    }

    json_out(['error' => 'Not found.'], 404);
} catch (Throwable $e) {
    error_log('CKX agents error: ' . $e->getMessage());
    json_out(['error' => 'Something went wrong. Please try again.'], 500);
}
