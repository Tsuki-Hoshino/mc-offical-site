(function () {
    let serverClockOffset = 0;
    let skinUrls = new Map();
    let onlineSince = new Map();
    let latestPlayers = [];
    let latestBots = [];
    const miniCharts = new Map();

    function bytes(value) {
        value = Number(value);
        if (!Number.isFinite(value)) return '-';
        const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        let index = 0;
        while (value >= 1024 && index < units.length - 1) { value /= 1024; index++; }
        return value.toFixed(index < 2 ? 0 : 2) + ' ' + units[index];
    }
    function rate(value) { value = Number(value); return !Number.isFinite(value) || value <= 0 ? '0 KiB/s' : bytes(value) + '/s'; }
    function percent(value) { return value == null ? '-' : Number(value).toFixed(1) + '%'; }
    function duration(seconds) {
        if (seconds == null) return '-';
        seconds = Math.floor(seconds);
        const days = Math.floor(seconds / 86400), hours = Math.floor(seconds % 86400 / 3600), minutes = Math.floor(seconds % 3600 / 60);
        return (days ? days + '天 ' : '') + hours + '小时 ' + minutes + '分';
    }
    function time(value) {
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString('zh-CN', { hour12: false });
    }
    function connectionDuration(value) {
        const startedAt = new Date(value).getTime();
        if (!Number.isFinite(startedAt)) return '在线时间未知';
        let seconds = Math.max(0, Math.floor((Date.now() + serverClockOffset - startedAt) / 1000));
        const days = Math.floor(seconds / 86400);
        seconds %= 86400;
        const hours = Math.floor(seconds / 3600);
        seconds %= 3600;
        const minutes = Math.floor(seconds / 60);
        seconds %= 60;
        return '在线 ' + (days ? days + '天 ' : '') + (hours ? hours + '小时 ' : '') + minutes + '分 ' + String(seconds).padStart(2, '0') + '秒';
    }
    function updateConnectionTimes() {
        document.querySelectorAll('[data-online-since]').forEach(function (element) {
            element.textContent = connectionDuration(element.dataset.onlineSince);
        });
    }
    function setText(id, value) { document.getElementById(id).textContent = value; }
    function setBar(id, value) { document.getElementById(id).style.width = Math.max(0, Math.min(100, Number(value) || 0)) + '%'; }
    function resource(id, barId, used, total) {
        const ratio = total ? used / total * 100 : 0;
        setText(id, bytes(used) + ' / ' + bytes(total) + ' · ' + ratio.toFixed(1) + '%');
        setBar(barId, ratio);
    }
    function updateClock() {
        if (!serverClockOffset) return;
        setText('clock', new Date(Date.now() + serverClockOffset).toLocaleString('zh-CN', { hour12: false }));
    }
    function createAvatar(name) {
        const avatar = document.createElement('span');
        avatar.className = 'avatar online-avatar';
        avatar.setAttribute('role', 'img');
        avatar.setAttribute('aria-label', name);
        const skinUrl = skinUrls.get(name.toLowerCase());
        if (skinUrl) {
            avatar.classList.add('has-skin');
            avatar.style.setProperty('--skin', 'url("' + skinUrl + '")');
        } else {
            avatar.textContent = name.slice(0, 1);
        }
        return avatar;
    }
    function renderPlayerList(id, names, emptyText) {
        const list = document.getElementById(id);
        list.replaceChildren();
        if (!names.length) {
            const empty = document.createElement('div');
            empty.className = 'online-player-empty';
            empty.textContent = emptyText;
            list.append(empty);
            return;
        }
        names.forEach(function (name) {
            const row = document.createElement('a');
            row.className = 'online-player';
            row.href = '../统计数据/玩家/?id=' + encodeURIComponent(name);
            const label = document.createElement('span');
            const info = document.createElement('span');
            const elapsed = document.createElement('small');
            const startedAt = onlineSince.get(name.toLowerCase());
            info.className = 'online-player-info';
            label.className = 'online-player-name';
            label.textContent = name;
            elapsed.className = 'online-duration';
            if (startedAt) {
                elapsed.dataset.onlineSince = startedAt;
                elapsed.textContent = connectionDuration(startedAt);
            } else {
                elapsed.textContent = '在线时间未知';
            }
            info.append(label, elapsed);
            row.append(createAvatar(name), info);
            list.append(row);
        });
    }
    function renderPlayers() {
        renderPlayerList('players', latestPlayers, '暂无玩家在线');
        renderPlayerList('bots', latestBots, '暂无假人在线');
    }
    function showOffline() {
        const state = document.getElementById('state');
        state.textContent = '服务器已离线';
        state.className = 'state offline';
        serverClockOffset = 0;
        latestPlayers = [];
        latestBots = [];
        onlineSince = new Map();
        [
            'clock', 'mspt', 'mspt-detail', 'process-cpu', 'process-memory', 'uptime',
            'host-cpu', 'host-memory', 'disk', 'rx-rate', 'tx-rate', 'rx-total',
            'tx-total', 'player-count', 'bot-count'
        ].forEach(function (id) { setText(id, '服务器已离线'); });
        ['host-cpu-bar', 'host-memory-bar', 'disk-bar'].forEach(function (id) { setBar(id, 0); });
        renderPlayerList('players', [], '服务器已离线');
        renderPlayerList('bots', [], '服务器已离线');
        document.body.classList.add('server-offline');
        setText('telemetry-mode', '服务器已离线');
        setText('updated', '服务器已离线');
    }
    function mergeSkinUrls(entries) {
        if (!entries || typeof entries !== 'object') return;
        Object.keys(entries).forEach(function (name) {
            const url = entries[name];
            if (name && typeof url === 'string' && url.startsWith('https://textures.minecraft.net/')) {
                skinUrls.set(name.toLowerCase(), url);
            }
        });
    }
    function mergeOnlineSince(entries) {
        onlineSince = new Map();
        if (!entries || typeof entries !== 'object') return;
        Object.keys(entries).forEach(function (name) {
            if (name && !Number.isNaN(new Date(entries[name]).getTime())) {
                onlineSince.set(name.toLowerCase(), entries[name]);
            }
        });
    }
    async function loadSkins() {
        try {
            const response = await fetch('../api/latest.php?type=stats', { cache: 'no-store' });
            if (!response.ok) return;
            const record = await response.json();
            const items = record.payload && Array.isArray(record.payload.items) ? record.payload.items : [];
            items.forEach(function (item) {
                if (item.name && item.skin_url) skinUrls.set(String(item.name).toLowerCase(), item.skin_url);
            });
            renderPlayers();
        } catch (error) {}
    }
    async function refresh() {
        try {
            const response = await fetch('../api/latest.php?type=status', { cache: 'no-store' });
            if (!response.ok) throw new Error(response.status);
            const record = await response.json(), data = record.payload || {}, runtime = data.runtime || {}, state = document.getElementById('state');
            if (!window.MCServerStatus.isOnline(record)) {
                showOffline();
                return;
            }
            state.textContent = data.online ? '服务器在线' : '服务器离线';
            state.className = 'state ' + (data.online ? 'online' : 'offline');
            latestPlayers = Array.isArray(data.online_players) ? data.online_players : [];
            latestBots = Array.isArray(data.bots) ? data.bots : [];
            mergeSkinUrls(data.skin_urls);
            mergeOnlineSince(data.online_since);
            setText('player-count', latestPlayers.length);
            setText('bot-count', latestBots.length);
            renderPlayers();
            document.body.classList.remove('server-offline');
            const telemetry = data.telemetry || {};
            setText('telemetry-origin', telemetry.transport === 'https_push' ? '数据来源：服务端采集器 HTTPS Push' : '数据来源：服务端采集器');
            setText('telemetry-mode', '实时上报');
            setText('updated', time(record.received_at));
            setText('mspt', runtime.mspt == null ? '-' : Number(runtime.mspt).toFixed(1) + ' ms');
            setText('mspt-detail', 'P50 ' + (runtime.mspt_p50 == null ? '-' : runtime.mspt_p50) + ' / P95 ' + (runtime.mspt_p95 == null ? '-' : runtime.mspt_p95) + ' / P99 ' + (runtime.mspt_p99 == null ? '-' : runtime.mspt_p99) + ' ms');
            setText('process-cpu', percent(runtime.process_cpu_percent));
            setText('process-memory', bytes(runtime.process_memory_bytes));
            setText('uptime', duration(runtime.uptime_seconds));
            setText('host-cpu', percent(runtime.host_cpu_percent));
            setBar('host-cpu-bar', runtime.host_cpu_percent);
            resource('host-memory', 'host-memory-bar', runtime.host_memory_used_bytes, runtime.host_memory_total_bytes);
            resource('disk', 'disk-bar', runtime.disk_used_bytes, runtime.disk_total_bytes);
            setText('rx-rate', rate(runtime.network_receive_rate));
            setText('tx-rate', rate(runtime.network_send_rate));
            setText('rx-total', bytes(runtime.network_received_bytes));
            setText('tx-total', bytes(runtime.network_sent_bytes));
            const serverTime = new Date(runtime.server_time);
            if (!Number.isNaN(serverTime.getTime())) {
                serverClockOffset = serverTime.getTime() - Date.now();
                updateClock();
                updateConnectionTimes();
            }
        } catch (error) {
            showOffline();
        }
    }
    async function loadMiniChart(card) {
        if (card.dataset.chartState || !window.Chart || !window.McHistoryCharts) return;
        card.dataset.chartState = 'loading';
        const metric = card.dataset.miniMetric;
        try {
            const response = await fetch('../api/history.php?metric=' + encodeURIComponent(metric), { cache: 'no-store' });
            if (!response.ok) throw new Error(response.status);
            const result = await response.json();
            const canvas = card.querySelector('canvas');
            const chart = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: McHistoryCharts.labels(result.points, true),
                    datasets: McHistoryCharts.datasets(metric, result.points, true)
                },
                options: McHistoryCharts.options(metric, true)
            });
            miniCharts.set(card, chart);
            card.dataset.chartState = result.points.length ? 'ready' : 'empty';
        } catch (error) {
            card.dataset.chartState = 'error';
        }
    }

    document.querySelectorAll('[data-mini-metric]').forEach(function (card) {
        card.addEventListener('mouseenter', function () { loadMiniChart(card); }, { once: true });
        card.addEventListener('focus', function () { loadMiniChart(card); }, { once: true });
    });
    window.addEventListener('site:before-swap', function () {
        miniCharts.forEach(function (chart) { chart.destroy(); });
        miniCharts.clear();
    }, { once: true });

    loadSkins();
    refresh();
    setInterval(refresh, 1000);
    setInterval(function () {
        updateClock();
        updateConnectionTimes();
    }, 1000);
}());
