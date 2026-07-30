<?php
declare(strict_types=1);

require_once __DIR__ . '/../统一认证/config.php';

define('LEGACY_SQLITE_PATH', PRIVATE_DATA_DIR . '/machine-registry.sqlite');
define('UPLOAD_DIR', PRIVATE_DATA_DIR . '/uploads');

const ALLOWED_PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const ALLOWED_DOCUMENT_EXTENSIONS = ['pdf', 'txt', 'md', 'markdown', 'jpg', 'jpeg', 'png', 'gif', 'webp'];

const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

const SITE_CSS_VERSION = '20260731a';
const SITE_JS_VERSION = '20260731a';
const MACHINE_JS_VERSION = '20260724a';
