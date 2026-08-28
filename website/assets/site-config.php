<?php
declare(strict_types=1);

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../统一认证/site_settings.php';

try {
    $config = site_public_settings();
} catch (Throwable $exception) {
    error_log('Site config load failed: ' . $exception->getMessage());
    $config = site_default_settings();
    $config['icpNumber'] = SITE_ICP_NUMBER;
    $config['policeNumber'] = SITE_POLICE_NUMBER;
    $config['policeCode'] = SITE_POLICE_CODE;
    $config['featureDefinitions'] = site_feature_definitions();
    $config['currentUser'] = null;
}

echo 'window.MCSiteConfig = ' . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n";
?>
window.applyMCSiteConfig = function () {
    var config = window.MCSiteConfig || {};
    var siteName = config.siteName || 'Minecraft 服务器';
    var serverName = config.serverName || '生存服务器';
    var serverAddress = config.serverAddress || 'mc.example.com';
    var features = config.features || {};
    var featureDefinitions = config.featureDefinitions || {};
    var currentUser = config.currentUser || null;

    function text(selector, value) {
        document.querySelectorAll(selector).forEach(function (element) {
            element.textContent = value;
        });
    }

    function enabled(key) {
        return features[key] !== false;
    }

    function pathOf(href) {
        try {
            return new URL(href, window.location.href).pathname;
        } catch (error) {
            return '';
        }
    }

    function renderFiling(container) {
        container.replaceChildren();
        if (config.icpNumber) {
            var icp = document.createElement('a');
            icp.href = 'https://beian.miit.gov.cn/';
            icp.target = '_blank';
            icp.rel = 'noopener noreferrer';
            icp.textContent = config.icpNumber;
            container.appendChild(icp);
        }
        if (config.policeNumber) {
            var police = document.createElement('a');
            police.href = config.policeCode
                ? 'https://beian.mps.gov.cn/#/query/webSearch?code=' + encodeURIComponent(config.policeCode)
                : 'https://beian.mps.gov.cn/';
            police.target = '_blank';
            police.rel = 'noopener noreferrer';
            police.textContent = config.policeNumber;
            container.appendChild(police);
        }
        container.hidden = !container.children.length;
    }

    text('.brand', siteName);
    text('.site-footer .shell > span', siteName);
    text('.connect-box strong', serverAddress);

    var homeTitle = document.querySelector('.official-title h1');
    if (homeTitle) homeTitle.textContent = serverName;
    var edition = document.querySelector('.official-label');
    if (edition) edition.textContent = config.editionLabel || 'MINECRAFT JAVA EDITION';
    var description = document.querySelector('meta[name="description"]');
    if (description && config.homeDescription) description.setAttribute('content', config.homeDescription);
    var contentTitle = document.querySelector('#content-title');
    if (contentTitle && config.homeContentTitle) contentTitle.textContent = config.homeContentTitle;
    var contentSubtitle = document.querySelector('.content-heading p');
    if (contentSubtitle && config.homeContentSubtitle) contentSubtitle.textContent = config.homeContentSubtitle;

    document.querySelectorAll('.filing, .history-legal').forEach(renderFiling);

    if (currentUser) {
        document.querySelectorAll('.topbar .nav').forEach(function (nav) {
            if (config.terminalUrl && currentUser.isSuperadmin && !nav.querySelector('a[href="/终端/"], a[href="../终端/"], a[href="./终端/"]')) {
                var terminal = document.createElement('a');
                terminal.className = 'nav-account';
                terminal.href = '/终端/';
                terminal.textContent = '终端';
                var admin = nav.querySelector('a[href="/admin/"], a[href="../admin/"], a[href="./admin/"]');
                if (admin) nav.insertBefore(terminal, admin);
                else nav.appendChild(terminal);
            }
            if (currentUser.isSuperadmin && !nav.querySelector('a[href="/admin/"], a[href="../admin/"], a[href="./admin/"]')) {
                var link = document.createElement('a');
                link.className = 'nav-account';
                link.href = '/admin/';
                link.textContent = '后台';
                var logout = nav.querySelector('form.machine-logout');
                if (logout) nav.insertBefore(link, logout);
                else nav.appendChild(link);
            }
            if (!nav.querySelector('a[href="/个人资料/"], a[href="../个人资料/"], a[href="./个人资料/"]')) {
                var profile = document.createElement('a');
                profile.href = '/个人资料/';
                profile.textContent = '个人资料';
                var logout = nav.querySelector('form.machine-logout');
                if (logout) nav.insertBefore(profile, logout);
                else nav.appendChild(profile);
            }
        });
    }

    document.querySelectorAll('a[href]').forEach(function (link) {
        var pathname = pathOf(link.getAttribute('href'));
        Object.keys(featureDefinitions).forEach(function (key) {
            var prefix = featureDefinitions[key].path;
            if (!enabled(key) && prefix && pathname.indexOf(prefix) === 0) {
                link.hidden = true;
            }
        });
    });

    var currentFeature = Object.keys(featureDefinitions).find(function (key) {
        var prefix = featureDefinitions[key].path;
        return prefix && window.location.pathname.indexOf(prefix) === 0;
    });
    if (currentFeature && !enabled(currentFeature) && !document.body.classList.contains('admin-page')) {
        var main = document.querySelector('main');
        var topbar = document.querySelector('.topbar');
        if (main) main.hidden = true;
        if (topbar && !document.querySelector('[data-feature-disabled-panel]')) {
            var panel = document.createElement('main');
            panel.className = 'shell site-feature-disabled';
            panel.setAttribute('data-feature-disabled-panel', '');
            panel.innerHTML = '<h1>功能暂未开放</h1><p>该模块当前未在站点展示中启用。</p><a class="machine-button primary" href="/">返回首页</a>';
            topbar.insertAdjacentElement('afterend', panel);
        }
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', window.applyMCSiteConfig, { once: true });
} else {
    window.applyMCSiteConfig();
}
