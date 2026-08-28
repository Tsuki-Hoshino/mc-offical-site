(function () {
    let serverClockOffset = 0;
    let skinUrls = new Map();
    let onlineSince = new Map();
    let latestPlayers = [];
    let latestBots = [];
    let hasStatus = false;
    let refreshInFlight = false;
    let lastFreshAt = 0;
    const miniCharts = new Map();
    let streamSocket = null;
    let streamReady = false;
    let streamReconnectDelay = 1000;
    let streamReconnectTimer = 0;
    let pollTimer = 0;

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
    function resolveDisks(runtime) {
        const disks = runtime && runtime.disks;
        if (Array.isArray(disks) && disks.length) return disks;
        const total = Number(runtime && runtime.disk_total_bytes);
        const used = Number(runtime && runtime.disk_used_bytes);
        if (Number.isFinite(total) && total > 0) {
            return [{ name: '', used_bytes: Number.isFinite(used) ? used : 0, total_bytes: total }];
        }
        return [];
    }
    function diskRow(name, used, total) {
        const row = document.createElement('div');
        row.className = 'resource-row';
        row.setAttribute('data-disk-row', '');
        const head = document.createElement('div');
        head.className = 'resource-head';
        const label = document.createElement('span');
        label.textContent = name ? '磁盘 ' + name : '磁盘';
        const value = document.createElement('strong');
        const ratio = total ? used / total * 100 : 0;
        value.textContent = bytes(used) + ' / ' + bytes(total) + ' · ' + ratio.toFixed(1) + '%';
        head.append(label, value);
        const bar = document.createElement('div');
        bar.className = 'bar';
        const fill = document.createElement('i');
        fill.style.width = Math.max(0, Math.min(100, ratio)) + '%';
        bar.append(fill);
        row.append(head, bar);
        return row;
    }
    function renderDisks(disks) {
        const list = document.getElementById('resource-list');
        if (!list) return;
        list.querySelectorAll('[data-disk-row]').forEach(function (el) { el.remove(); });
        const items = Array.isArray(disks) ? disks : [];
        if (!items.length) {
            const row = document.createElement('div');
            row.className = 'resource-row';
            row.setAttribute('data-disk-row', '');
            const head = document.createElement('div');
            head.className = 'resource-head';
            const label = document.createElement('span');
            label.textContent = '磁盘';
            const value = document.createElement('strong');
            value.textContent = '-';
            head.append(label, value);
            row.append(head);
            list.append(row);
            return;
        }
        items.forEach(function (disk) {
            if (!disk || typeof disk !== 'object') return;
            const name = typeof disk.name === 'string' ? disk.name : '';
            const used = Number(disk.used_bytes);
            const total = Number(disk.total_bytes);
            if (!Number.isFinite(total) || total <= 0) return;
            list.append(diskRow(name, Number.isFinite(used) ? used : 0, total));
        });
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
            'host-cpu', 'host-memory', 'rx-rate', 'tx-rate', 'rx-total',
            'tx-total', 'player-count', 'bot-count'
        ].forEach(function (id) { setText(id, '服务器已离线'); });
        ['host-cpu-bar', 'host-memory-bar'].forEach(function (id) { setBar(id, 0); });
        renderDisks(null);
        renderPlayerList('players', [], '服务器已离线');
        renderPlayerList('bots', [], '服务器已离线');
        document.body.classList.add('server-offline');
        setText('telemetry-mode', '服务器已离线');
        setText('updated', '服务器已离线');
        lastFreshAt = 0;
    }
    function showUnavailable(clearData) {
        const state = document.getElementById('state');
        state.textContent = '状态暂时不可用';
        state.className = 'state';
        setText('updated', '连接暂时不可用');
        if (clearData || !hasStatus) {
            serverClockOffset = 0;
            skinUrls = new Map();
            latestPlayers = [];
            latestBots = [];
            onlineSince = new Map();
            [
                'clock', 'mspt', 'mspt-detail', 'process-cpu', 'process-memory', 'uptime',
                'host-cpu', 'host-memory', 'rx-rate', 'tx-rate', 'rx-total',
                'tx-total', 'player-count', 'bot-count'
            ].forEach(function (id) { setText(id, '状态暂时不可用'); });
            ['host-cpu-bar', 'host-memory-bar'].forEach(function (id) { setBar(id, 0); });
            renderDisks(null);
            renderPlayerList('players', [], '状态暂时不可用');
            renderPlayerList('bots', [], '状态暂时不可用');
            document.body.classList.add('server-offline');
            lastFreshAt = 0;
        }
        setText('telemetry-mode', clearData ? '实时上报暂时中断' : (hasStatus ? '连接波动，数据仍在新鲜窗口内' : '正在等待服务器状态'));
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
    async function refresh(streamRecord) {
        if (refreshInFlight && !streamRecord) return;
        if (!streamRecord) refreshInFlight = true;
        try {
            let record = streamRecord;
            if (!record) {
                const response = await fetch('../api/latest.php?type=status', { cache: 'no-store' });
                if (!response.ok) throw new Error(response.status);
                record = await response.json();
            }
            const data = record.payload || {}, runtime = data.runtime || {}, state = document.getElementById('state');
            const reportedState = window.MCServerStatus.state(record);
            if (reportedState !== 'online') {
                if (reportedState === 'offline') {
                    showOffline();
                } else {
                    showUnavailable(true);
                }
                hasStatus = true;
                return;
            }
            state.textContent = '服务器在线';
            state.className = 'state online';
            latestPlayers = Array.isArray(data.online_players) ? data.online_players : [];
            latestBots = Array.isArray(data.bots) ? data.bots : [];
            mergeSkinUrls(data.skin_urls);
            mergeOnlineSince(data.online_since);
            setText('player-count', latestPlayers.length);
            setText('bot-count', latestBots.length);
            renderPlayers();
            document.body.classList.remove('server-offline');
            const telemetry = data.telemetry || {};
            setText('telemetry-origin', telemetry.transport === 'wss_push' ? '数据来源：服务端采集器 WSS' : (telemetry.transport === 'https_push' ? '数据来源：服务端采集器 HTTPS Push' : '数据来源：服务端采集器'));
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
            renderDisks(resolveDisks(runtime));
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
            lastFreshAt = window.MCServerStatus.receivedAt(record);
            hasStatus = true;
        } catch (error) {
            const age = lastFreshAt > 0 ? Date.now() - lastFreshAt : Infinity;
            if (age > Math.max(5, Number(window.MCSiteConfig && window.MCSiteConfig.offlineAfterSeconds) || 15) * 1000) {
                showUnavailable(true);
                hasStatus = true;
            } else if (!hasStatus) {
                showUnavailable();
            }
        } finally {
            if (!streamRecord) refreshInFlight = false;
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

    function streamUrl() {
        if (window.location.protocol !== 'https:') return null;
        return 'wss://' + window.location.host + '/ws/status';
    }
    function stopStream() {
        if (streamSocket) {
            streamSocket.onopen = null;
            streamSocket.onmessage = null;
            streamSocket.onerror = null;
            streamSocket.onclose = null;
            try { streamSocket.close(); } catch (error) {}
            streamSocket = null;
        }
        streamReady = false;
        if (streamReconnectTimer) {
            window.clearTimeout(streamReconnectTimer);
            streamReconnectTimer = 0;
        }
    }
    function stopPolling() {
        if (pollTimer) {
            window.clearInterval(pollTimer);
            pollTimer = 0;
        }
    }
    function startPolling() {
        if (pollTimer) return;
        pollTimer = window.setInterval(function () {
            if (!streamReady) refresh();
        }, 2000);
    }
    function scheduleStreamReconnect() {
        if (streamReconnectTimer) return;
        const delay = streamReconnectDelay;
        streamReconnectDelay = Math.min(5000, streamReconnectDelay * 2);
        streamReconnectTimer = window.setTimeout(function () {
            streamReconnectTimer = 0;
            openStream();
        }, delay);
    }
    function openStream() {
        if (streamSocket) return;
        const url = streamUrl();
        if (!url) {
            startPolling();
            return;
        }
        let socket;
        try {
            socket = new WebSocket(url);
        } catch (error) {
            startPolling();
            scheduleStreamReconnect();
            return;
        }
        streamSocket = socket;
        socket.onopen = function () {
            streamReady = true;
            streamReconnectDelay = 1000;
            stopPolling();
        };
        socket.onmessage = function (event) {
            try {
                const record = JSON.parse(event.data);
                if (record && record.type === 'status') {
                    refresh(record);
                }
            } catch (error) {}
        };
        socket.onerror = function () {};
        socket.onclose = function () {
            if (streamSocket !== socket) return;
            streamSocket = null;
            streamReady = false;
            startPolling();
            scheduleStreamReconnect();
        };
    }

    document.querySelectorAll('[data-mini-metric]').forEach(function (card) {
        card.addEventListener('mouseenter', function () { loadMiniChart(card); }, { once: true });
        card.addEventListener('focus', function () { loadMiniChart(card); }, { once: true });
    });
    window.addEventListener('site:before-swap', function () {
        miniCharts.forEach(function (chart) { chart.destroy(); });
        miniCharts.clear();
        stopStream();
        stopPolling();
    }, { once: true });

    refresh();
    openStream();
    // WSS /ws/status pushes once per second; HTTP polling is only the compatibility fallback.
    startPolling();
    window.setInterval(function () {
        updateClock();
        updateConnectionTimes();
        const maxAge = Math.max(5, Number(window.MCSiteConfig && window.MCSiteConfig.offlineAfterSeconds) || 15) * 1000;
        if (lastFreshAt > 0 && Date.now() - lastFreshAt > maxAge) {
            showUnavailable(true);
            hasStatus = true;
        }
    }, 1000);
}());
