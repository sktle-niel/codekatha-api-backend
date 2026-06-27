<?php
// Loads the environment and shared helpers, then returns the config array.
// Every endpoint starts with:  $config = require __DIR__ . '/../app/bootstrap.php';

require __DIR__ . '/env.php';
load_env(dirname(__DIR__) . '/.env'); // .env lives at the project root, outside the web root

require __DIR__ . '/http.php';
require __DIR__ . '/input.php';
require __DIR__ . '/db.php';
require __DIR__ . '/mailer.php';
require __DIR__ . '/validation.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/settings.php';

return require __DIR__ . '/config.php';
