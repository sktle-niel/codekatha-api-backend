<?php
// API root — a simple health check / endpoint listing.

$config = require __DIR__ . '/../app/bootstrap.php';
cors($config['allowed_origins']);

json_out([
    'name'      => 'CODEKATHAX API',
    'status'    => 'ok',
    'endpoints' => [
        'GET  /'             => 'This health check',
        'POST /requests.php' => 'Create a project request from the website form',
    ],
]);
