<?php
declare(strict_types=1);

// Legacy include path kept for existing machine registry pages.
require_once __DIR__ . '/../统一认证/auth.php';

function require_superadmin(): void
{
    require_machine_auth();
    if (!machine_is_superadmin()) {
        http_response_code(403);
        if (function_exists('render_site_header') && function_exists('render_site_footer')) {
            render_site_header('无权访问');
            echo '<main class="machine-main shell"><section class="machine-empty"><h1>无权访问</h1><p>只有超级管理员可以访问后台。</p><a class="machine-button" href="index.php">返回经纬度</a></section></main>';
            render_site_footer();
        } else {
            echo 'Forbidden';
        }
        exit;
    }
}

function require_account_creation_permission(): void
{
    require_machine_auth();
    if (!machine_is_superadmin()) {
        http_response_code(403);
        if (function_exists('render_site_header') && function_exists('render_site_footer')) {
            render_site_header('无权访问');
            echo '<main class="machine-main shell"><section class="machine-empty"><h1>无权访问</h1><p>只有超级管理员可以新增账户。</p><a class="machine-button" href="/admin/users.php">返回账户管理</a></section></main>';
            render_site_footer();
        } else {
            echo 'Forbidden';
        }
        exit;
    }
}
