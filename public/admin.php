<?php
// Admin API (single owner account). All actions except login require an admin
// bearer token.
//   POST /admin.php?do=login         -> { token }
//   POST /admin.php?do=logout
//   GET  /admin.php?do=agents        -> all agents (+ referral counts)
//   POST /admin.php?do=agent-status  -> { id, status }  approve / suspend / pending
//   GET  /admin.php?do=requests      -> all project requests (+ agent + commission)
//   POST /admin.php?do=deal          -> { id, deal_amount, deal_status }
//   GET  /admin.php?do=summary       -> totals (requests, revenue, 30/70, agents)

$config = require __DIR__ . '/../app/bootstrap.php';
cors($config['allowed_origins']);
security_headers();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$do = $_GET['do'] ?? '';
$rate = (float) $config['commission_rate'];

try {
    $pdo = db();

    // Login is handled by the unified /login.php (with brute-force throttling).

    if ($method === 'POST' && $do === 'logout') {
        ckx_logout($pdo);
        json_out(['ok' => true]);
    }

    // ---- everything below requires an admin session ----
    ckx_require_admin($pdo);

    // --------------------------------------------------------------- agents
    if ($method === 'GET' && $do === 'agents') {
        $rows = $pdo->query(
            "SELECT a.id, a.name, a.email, a.phone, a.payout_method, a.payout_number,
                    a.ref_token, a.status, a.created_at, a.approved_at,
                    (SELECT COUNT(*) FROM project_requests pr WHERE pr.agent_id = a.id) AS referrals
             FROM agents a
             ORDER BY (a.status = 'pending') DESC, a.created_at DESC"
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['referrals'] = (int) $r['referrals'];
        }
        unset($r);
        json_out(['agents' => $rows]);
    }

    if ($method === 'POST' && $do === 'agent-status') {
        require_json();
        require_allowed_origin($config['allowed_origins']);
        $body = read_json_body();
        $id = (int) ($body['id'] ?? 0);
        $status = field($body, 'status', 20);
        if (!in_array($status, ['pending', 'approved', 'suspended'], true)) {
            json_out(['error' => 'Invalid status.'], 422);
        }
        $stmt = $pdo->prepare("SELECT * FROM agents WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $agent = $stmt->fetch();
        if (!$agent) {
            json_out(['error' => 'Agent not found.'], 404);
        }
        $wasApproved = $agent['status'] === 'approved';
        if ($status === 'approved') {
            $pdo->prepare("UPDATE agents SET status = 'approved', approved_at = COALESCE(approved_at, NOW()) WHERE id = ?")
                ->execute([$id]);
        } else {
            $pdo->prepare("UPDATE agents SET status = ? WHERE id = ?")->execute([$status, $id]);
        }
        $emailed = false;
        if ($status === 'approved' && !$wasApproved) {
            $emailed = send_agent_approved($config['mail'], $config['site_url'], $agent);
        }
        json_out(['ok' => true, 'emailed' => $emailed]);
    }

    // Distinct dates (YYYY-MM-DD, newest first) that have requests — for filters.
    if ($method === 'GET' && $do === 'dates') {
        $dates = $pdo->query(
            "SELECT DISTINCT DATE(created_at) d FROM project_requests ORDER BY d DESC"
        )->fetchAll(PDO::FETCH_COLUMN);
        json_out(['dates' => $dates]);
    }

    // ------------------------------------------------------------- requests
    if ($method === 'GET' && $do === 'requests') {
        [$cond, $params] = ckx_date_filter(
            (string) ($_GET['month'] ?? ''),
            (string) ($_GET['day'] ?? ''),
            'pr.created_at'
        );

        $limit = 10;
        $ct = $pdo->prepare("SELECT COUNT(*) FROM project_requests pr WHERE $cond");
        $ct->execute($params);
        $total = (int) $ct->fetchColumn();
        $pages = max(1, (int) ceil($total / $limit));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $limit; // both ints, safe to inline

        $stmt = $pdo->prepare(
            "SELECT pr.id, pr.reference, pr.path, pr.name, pr.email, pr.phone,
                    pr.business_name, pr.project_title, pr.service, pr.system_type,
                    pr.budget, pr.custom_budget, pr.description,
                    pr.deal_amount, pr.deal_status, pr.created_at,
                    pr.agent_id, a.name AS agent_name
             FROM project_requests pr
             LEFT JOIN agents a ON a.id = pr.agent_id
             WHERE $cond
             ORDER BY pr.id DESC
             LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['agent_id'] = $r['agent_id'] !== null ? (int) $r['agent_id'] : null;
            $r['deal_amount'] = $r['deal_amount'] !== null ? (float) $r['deal_amount'] : null;
            $r['agent_commission'] =
                ($r['deal_status'] === 'won' && $r['agent_id'] && $r['deal_amount'])
                    ? round($r['deal_amount'] * $rate, 2) : 0.0;
        }
        unset($r);
        json_out([
            'requests' => $rows,
            'rate'     => $rate,
            'page'     => $page,
            'pages'    => $pages,
            'total'    => $total,
            'limit'    => $limit,
        ]);
    }

    if ($method === 'POST' && $do === 'deal') {
        require_json();
        require_allowed_origin($config['allowed_origins']);
        $body = read_json_body();
        $id = (int) ($body['id'] ?? 0);
        $status = field($body, 'deal_status', 10);
        if (!in_array($status, ['lead', 'won', 'lost'], true)) {
            json_out(['error' => 'Invalid deal status.'], 422);
        }
        $amount = null;
        $raw = $body['deal_amount'] ?? null;
        if ($raw !== null && $raw !== '') {
            if (!is_numeric($raw)) {
                json_out(['error' => 'Amount must be a number.'], 422);
            }
            $amount = (float) $raw;
            if ($amount < 0 || $amount > 99999999) {
                json_out(['error' => 'Amount is out of range.'], 422);
            }
        }
        $chk = $pdo->prepare("SELECT 1 FROM project_requests WHERE id = ? LIMIT 1");
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            json_out(['error' => 'Request not found.'], 404);
        }
        $pdo->prepare("UPDATE project_requests SET deal_amount = ?, deal_status = ? WHERE id = ?")
            ->execute([$amount, $status, $id]);
        $commission = ($status === 'won' && $amount) ? round($amount * $rate, 2) : 0.0;
        json_out(['ok' => true, 'commission' => $commission]);
    }

    // -------------------------------------------------------------- summary
    if ($method === 'GET' && $do === 'summary') {
        $a = $pdo->query(
            "SELECT
                COUNT(*) total,
                COALESCE(SUM(deal_status='lead'),0) leads,
                COALESCE(SUM(deal_status='won'),0) won,
                COALESCE(SUM(deal_status='lost'),0) lost,
                COALESCE(SUM(CASE WHEN deal_status='won' THEN deal_amount ELSE 0 END),0) revenue_won,
                COALESCE(SUM(CASE WHEN deal_status='won' AND agent_id IS NOT NULL THEN deal_amount ELSE 0 END),0) agent_revenue
             FROM project_requests"
        )->fetch();
        $ag = $pdo->query(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(status='pending'),0) pending,
                    COALESCE(SUM(status='approved'),0) approved
             FROM agents"
        )->fetch();

        $revenueWon = (float) $a['revenue_won'];
        $agentCommission = round(((float) $a['agent_revenue']) * $rate, 2);
        json_out([
            'requests' => [
                'total' => (int) $a['total'],
                'leads' => (int) $a['leads'],
                'won'   => (int) $a['won'],
                'lost'  => (int) $a['lost'],
            ],
            'revenue' => [
                'won_total'        => round($revenueWon, 2),
                'agent_commission' => $agentCommission,
                'owner_net'        => round($revenueWon - $agentCommission, 2),
            ],
            'agents' => [
                'total'    => (int) $ag['total'],
                'pending'  => (int) $ag['pending'],
                'approved' => (int) $ag['approved'],
            ],
            'rate' => $rate,
        ]);
    }

    json_out(['error' => 'Not found.'], 404);
} catch (Throwable $e) {
    error_log('CKX admin error: ' . $e->getMessage());
    json_out(['error' => 'Something went wrong. Please try again.'], 500);
}
