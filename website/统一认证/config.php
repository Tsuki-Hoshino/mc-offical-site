<?php
declare(strict_types=1);

$authPrivatePath = __DIR__ . '/../data';

defined('PRIVATE_DATA_DIR') || define('PRIVATE_DATA_DIR', $authPrivatePath);
defined('DATABASE_CONFIG_FILE') || define('DATABASE_CONFIG_FILE', PRIVATE_DATA_DIR . '/database.json');
defined('BOOTSTRAP_ADMIN_FILE') || define('BOOTSTRAP_ADMIN_FILE', PRIVATE_DATA_DIR . '/bootstrap-admin.json');
defined('AUTH_RATE_DIR') || define('AUTH_RATE_DIR', PRIVATE_DATA_DIR . '/auth-rate');
defined('AUTH_SESSION_SECONDS') || define('AUTH_SESSION_SECONDS', 12 * 60 * 60);
defined('AUTH_MAX_FAILURES') || define('AUTH_MAX_FAILURES', 5);
defined('AUTH_FAILURE_WINDOW') || define('AUTH_FAILURE_WINDOW', 15 * 60);
defined('AUTH_BLOCK_SECONDS') || define('AUTH_BLOCK_SECONDS', 15 * 60);
defined('PASSWORD_ITERATIONS') || define('PASSWORD_ITERATIONS', 310000);
