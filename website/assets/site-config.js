window.MCSiteConfig = {
    siteName: '示例服务器',
    serverName: '生存服务器',
    serverAddress: 'mc.example.com',
    editionLabel: 'MINECRAFT JAVA EDITION 26.1 FABRIC WITH CARPET',
    icpNumber: '',
    policeNumber: '',
    policeCode: '',
    offlineAfterSeconds: 15
};

window.applyMCSiteConfig = function () {
    var config = window.MCSiteConfig || {};
    var siteName = config.siteName || 'Minecraft 服务器';
    var serverName = config.serverName || '生存服务器';
    var serverAddress = config.serverAddress || 'mc.example.com';

    document.querySelectorAll('.brand, .site-footer .shell > span').forEach(function (element) {
        element.textContent = siteName;
    });
    document.querySelectorAll('.connect-box strong').forEach(function (element) {
        element.textContent = serverAddress;
    });

    var homeTitle = document.querySelector('.official-title h1');
    if (homeTitle) homeTitle.textContent = serverName;
    var edition = document.querySelector('.official-label');
    if (edition) edition.textContent = config.editionLabel || 'MINECRAFT JAVA EDITION';

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

    document.querySelectorAll('.filing, .history-legal').forEach(renderFiling);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', window.applyMCSiteConfig, { once: true });
} else {
    window.applyMCSiteConfig();
}
