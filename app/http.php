<?php
// Small HTTP helpers shared by every endpoint.

// Emit CORS headers for an allowed origin and short-circuit preflight requests.
function cors(array $allowed): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowAny = in_array('*', $allowed, true);

    if ($origin !== '' && ($allowAny || in_array($origin, $allowed, true))) {
        header('Access-Control-Allow-Origin: ' . ($allowAny ? '*' : $origin));
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
    }
    header('Vary: Origin');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// Send a JSON response and stop.
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Decode the JSON request body into an array (empty array if missing/invalid).
function read_json_body(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}
