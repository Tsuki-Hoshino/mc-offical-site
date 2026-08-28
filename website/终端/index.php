<?php
declare(strict_types=1);

require_once __DIR__ . '/../统一认证/site_settings.php';

auth_require();
if (!auth_is_superadmin()) {
    http_response_code(403);
    exit('Forbidden');
}

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function terminal_h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

try {
    $settings = site_settings_read();
} catch (Throwable $exception) {
    error_log('Terminal settings read failed: ' . $exception->getMessage());
    $settings = site_default_settings();
}

$terminalUrl = trim((string) ($settings['terminalUrl'] ?? ''));
$terminalKey = trim((string) ($settings['terminalKey'] ?? ''));
$configured = $terminalUrl !== '' && $terminalKey !== '';
$csrf = auth_csrf_token();
?><!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive,nosnippet">
    <meta name="theme-color" content="#f9a8d4">
    <title>终端 | Minecraft 生存服务器</title>
    <link rel="stylesheet" href="/assets/site.css?v=20260829a">
    <link rel="stylesheet" href="/assets/xterm/xterm.min.css">
    <script src="/assets/lenis.min.js?v=1.3.25"></script>
    <script src="/assets/site-config.php?v=20260829b"></script>
    <script src="/assets/xterm/xterm.min.js"></script>
    <script src="/assets/xterm/xterm-addon-fit.min.js"></script>
    <script src="/assets/site.js?v=20260811a"></script>
</head>
<body class="admin-page terminal-page">
<header class="topbar"><div class="shell"><a class="brand" href="/">Minecraft 生存服务器</a><nav class="nav" aria-label="站点导航"><a href="/">首页</a><a href="/状态/">实时状态</a><a href="/统计数据/">玩家统计</a><a href="/配方/">配方</a><a href="/附魔计算/">附魔计算</a><a href="/经纬度/">经纬度</a><a href="/计划表/">计划表</a><a class="nav-account" href="/admin/">后台</a><a class="nav-account" href="/终端/" aria-current="page">终端</a><a href="/个人资料/">个人资料</a><form class="machine-logout" method="post" action="/统一认证/logout.php"><input type="hidden" name="next" value="/"><input type="hidden" name="csrf_token" value="<?= terminal_h($csrf) ?>"><button type="submit">退出</button></form></nav></div></header>
<?php if (!$configured): ?>
<main class="admin-main shell">
    <header class="admin-heading">
        <div>
            <h1>终端</h1>
            <p>服务器控制台尚未配置完成。</p>
        </div>
    </header>
    <section class="admin-panel">
        <div class="admin-fixed">
            <span>缺少面板地址或面板密钥</span>
            <strong>请先在后台填写 MCSManager 守护进程地址与密钥，保存后即可在此管理 Minecraft 实例。</strong>
            <a class="machine-button primary" href="/admin/">前往后台配置</a>
        </div>
    </section>
</main>
<?php else: ?>
<main class="terminal-main shell" data-csrf="<?= terminal_h($csrf) ?>" data-api="./api.php">
    <div class="terminal-head">
        <div>
            <h1>服务器控制台</h1>
            <p>连接面板守护进程，管理 Minecraft 实例与实时输出。</p>
        </div>
        <div class="terminal-daemon-stats">
            <span>面板版本 <strong data-daemon-version>-</strong></span>
            <span>运行实例 <strong data-daemon-instances>-</strong></span>
        </div>
    </div>
    <div class="terminal-grid">
        <aside class="terminal-side">
            <div class="terminal-side-head">
                <h2>实例</h2>
                <button class="machine-button" type="button" data-refresh-instances>刷新</button>
            </div>
            <div class="terminal-instance-list" data-instance-list data-lenis-prevent></div>
        </aside>
        <section class="terminal-console" aria-label="实例控制台">
            <div class="terminal-console-head">
                <div class="terminal-console-title">
                    <span data-console-title>未选择实例</span>
                    <span class="terminal-instance-status" data-console-status hidden></span>
                </div>
                <div class="terminal-font-tools">
                    <button class="machine-button" type="button" data-font-dec aria-label="减小字号" title="减小字号">A-</button>
                    <button class="machine-button" type="button" data-font-inc aria-label="增大字号" title="增大字号">A+</button>
                    <button class="machine-button" type="button" data-font-bold aria-label="加粗" title="加粗">B</button>
                    <select data-font-family aria-label="终端字体" title="终端字体">
                        <option value="'JetBrains Mono'">JetBrains Mono</option>
                        <option value="Consolas">Consolas</option>
                        <option value="'Courier New'">Courier New</option>
                        <option value="monospace">系统等宽</option>
                    </select>
                </div>
                <div class="terminal-console-actions">
                    <button class="machine-button" type="button" data-action="start" disabled>启动</button>
                    <button class="machine-button" type="button" data-action="stop" disabled>停止</button>
                    <button class="machine-button" type="button" data-action="restart" disabled>重启</button>
                    <button class="machine-button danger" type="button" data-action="kill" disabled>强制终止</button>
                    <button class="machine-button" type="button" data-reconnect disabled title="手动重连面板">重连</button>
                </div>
            </div>
            <div class="terminal-output" data-xterm data-lenis-prevent></div>
        </section>
    </div>
    <section class="terminal-files" aria-label="实例文件管理">
        <div class="terminal-files-head">
            <div class="terminal-files-breadcrumb" data-files-breadcrumb aria-label="当前目录路径"></div>
            <div class="terminal-files-actions">
                <button class="machine-button" type="button" data-files-upload>上传</button>
                <button class="machine-button" type="button" data-files-mkdir>新建目录</button>
                <button class="machine-button" type="button" data-files-touch>新建文件</button>
                <button class="machine-button" type="button" data-files-refresh>刷新</button>
            </div>
        </div>
        <div class="terminal-files-status" data-files-status role="status" hidden></div>
        <div class="terminal-files-table machine-admin-table" data-lenis-prevent>
            <table class="terminal-files-list">
                <thead>
                    <tr>
                        <th>名称</th>
                        <th class="terminal-files-size">大小</th>
                        <th class="terminal-files-time">修改时间</th>
                        <th class="terminal-files-ops">操作</th>
                    </tr>
                </thead>
                <tbody data-files-tbody></tbody>
            </table>
        </div>
        <input type="file" data-files-input hidden>
    </section>
    <div class="terminal-status-line" data-status role="status">正在初始化…</div>
    <div class="terminal-confirm" data-confirm hidden>
        <div class="terminal-confirm-panel" role="alertdialog" aria-modal="true" aria-labelledby="terminal-confirm-title">
            <h3 id="terminal-confirm-title">确认操作</h3>
            <p data-confirm-text></p>
            <div class="terminal-confirm-actions">
                <button class="machine-button" type="button" data-confirm-cancel>取消</button>
                <button class="machine-button danger" type="button" data-confirm-ok>确认</button>
            </div>
        </div>
    </div>
    <div class="terminal-confirm" data-file-dialog hidden>
        <div class="terminal-confirm-panel terminal-file-dialog-panel" role="dialog" aria-modal="true" aria-labelledby="terminal-file-dialog-title" data-lenis-prevent>
            <h3 id="terminal-file-dialog-title" data-file-dialog-title>编辑文件</h3>
            <div data-file-dialog-body></div>
            <div class="terminal-confirm-actions">
                <button class="machine-button" type="button" data-file-dialog-cancel>取消</button>
                <button class="machine-button primary" type="button" data-file-dialog-ok>确定</button>
            </div>
        </div>
    </div>
</main>
<?php endif; ?>
<footer class="site-footer"><div class="shell"><span>Minecraft 生存服务器</span><div class="filing"><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"></a><a href="https://beian.mps.gov.cn/#/query/webSearch?code=" target="_blank" rel="noopener noreferrer"></a></div></div></footer>
<?php if ($configured): ?><script src="/终端/assets/terminal.js?v=20260826d"></script><script src="/终端/assets/filemanager.js?v=20260824c"></script><?php endif; ?>
</body>
</html>
