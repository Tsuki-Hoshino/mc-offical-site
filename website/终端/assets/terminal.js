(function () {
    'use strict';

    if (window.__mcTerminalInstance) {
        window.__mcTerminalInstance.destroy();
        window.__mcTerminalInstance = null;
    }

    var root = document.querySelector('.terminal-main');
    if (!root) return;

    var csrf = root.getAttribute('data-csrf') || '';
    var api = root.getAttribute('data-api') || 'api.php';

    var clientId = '';
    try {
        clientId = window.sessionStorage.getItem('mcTerminalClient') || '';
    } catch (error) {
        clientId = '';
    }
    if (!/^[0-9a-f]{8,32}$/.test(clientId)) {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            clientId = window.crypto.randomUUID().replace(/-/g, '');
        } else {
            clientId = Date.now().toString(16) + Math.floor(Math.random() * 0xFFFFFFFF).toString(16) + Math.floor(Math.random() * 0xFFFFFFFF).toString(16);
        }
        try {
            window.sessionStorage.setItem('mcTerminalClient', clientId);
        } catch (error) {
        }
    }

    var instanceList = root.querySelector('[data-instance-list]');
    var consoleTitle = root.querySelector('[data-console-title]');
    var consoleStatus = root.querySelector('[data-console-status]');
    var xtermContainer = root.querySelector('[data-xterm]');
    var statusLine = root.querySelector('[data-status]');
    var confirmEl = root.querySelector('[data-confirm]');
    var confirmText = confirmEl ? confirmEl.querySelector('[data-confirm-text]') : null;
    var confirmOk = confirmEl ? confirmEl.querySelector('[data-confirm-ok]') : null;
    var confirmCancel = confirmEl ? confirmEl.querySelector('[data-confirm-cancel]') : null;
    var refreshButton = root.querySelector('[data-refresh-instances]');
    var reconnectButton = root.querySelector('[data-reconnect]');
    var daemonVersion = root.querySelector('[data-daemon-version]');
    var daemonInstances = root.querySelector('[data-daemon-instances]');
    var fontFamilySelect = root.querySelector('[data-font-family]');
    var fontDecButton = root.querySelector('[data-font-dec]');
    var fontIncButton = root.querySelector('[data-font-inc]');
    var fontBoldButton = root.querySelector('[data-font-bold]');

    var FONT_KEY = 'mcTerminalFont';
    var fontSize = 14;
    var fontFamily = "'JetBrains Mono'";
    var fontBold = false;

    function loadFontPrefs() {
        try {
            var saved = JSON.parse(window.localStorage.getItem(FONT_KEY) || '{}');
            if (typeof saved.size === 'number' && saved.size >= 10 && saved.size <= 28) fontSize = saved.size;
            if (typeof saved.family === 'string' && saved.family) fontFamily = saved.family;
            if (typeof saved.bold === 'boolean') fontBold = saved.bold;
        } catch (error) {
        }
    }

    function applyFont() {
        if (!term) return;
        term.options.fontSize = fontSize;
        term.options.fontFamily = fontFamily + ", Consolas, 'Courier New', 'Microsoft YaHei', monospace";
        term.options.fontWeight = fontBold ? 'bold' : 'normal';
        term.options.fontWeightBold = fontBold ? 'bold' : 'normal';
        if (fontFamilySelect) fontFamilySelect.value = fontFamily;
        if (fontBoldButton) fontBoldButton.classList.toggle('active', fontBold);
        if (fitAddon) {
            window.setTimeout(function () {
                if (destroyed) return;
                try {
                    fitAddon.fit();
                } catch (error) {
                }
            }, 50);
        }
    }

    function saveFontPrefs() {
        try {
            window.localStorage.setItem(FONT_KEY, JSON.stringify({ size: fontSize, family: fontFamily, bold: fontBold }));
        } catch (error) {
        }
    }

    function changeFontSize(delta) {
        fontSize = Math.max(10, Math.min(28, fontSize + delta));
        applyFont();
        saveFontPrefs();
    }

    function toggleFontBold() {
        fontBold = !fontBold;
        applyFont();
        saveFontPrefs();
    }

    var controller = new AbortController();
    var destroyed = false;
    var instances = [];
    var selectedUuid = '';
    var selectedInstance = null;
    var subscribed = false;
    var selectionSerial = 0;
    var pollGeneration = 0;
    var loadingHistory = false;
    var pendingOutput = [];
    var pollRunningGeneration = 0;
    var refreshTimer = 0;
    var term = null;
    var fitAddon = null;
    var TERMINAL_MIN_COLS = 24;
    var TERMINAL_MAX_COLS = 220;

    var RECONNECT_DELAYS = [3000, 5000, 10000, 20000, 60000];
    var reconnectAttempts = 0;
    var reconnectTimer = 0;
    var retryCooldown = false;
    var readyCallbacks = [];
    var terminalReady = false;

    var STATUS_LABELS = {
        '-1': { text: '忙碌', cls: 'busy' },
        '0': { text: '已停止', cls: 'stopped' },
        '1': { text: '停止中', cls: 'busy' },
        '2': { text: '启动中', cls: 'busy' },
        '3': { text: '运行中', cls: 'running' }
    };

    function setStatus(text, isError) {
        if (!statusLine) return;
        statusLine.textContent = text;
        statusLine.classList.toggle('error', !!isError);
    }

    function updateRetryButton(available) {
        if (!reconnectButton) return;
        if (available && selectedUuid && !retryCooldown) {
            reconnectButton.disabled = false;
        } else {
            reconnectButton.disabled = true;
        }
    }

    function resetReconnect() {
        reconnectAttempts = 0;
        if (reconnectTimer) {
            window.clearTimeout(reconnectTimer);
            reconnectTimer = 0;
        }
        updateRetryButton(false);
    }

    function scheduleReconnect(reason) {
        if (destroyed || !selectedUuid) return;
        updateRetryButton(true);
        if (reconnectTimer) return;
        if (reconnectAttempts >= RECONNECT_DELAYS.length) {
            setStatus(reason + '。自动重连已停止，可点击「重连」按钮手动重试。', true);
            return;
        }
        var delay = RECONNECT_DELAYS[reconnectAttempts];
        reconnectAttempts++;
        setStatus(reason + '，' + Math.round(delay / 1000) + ' 秒后自动重连（' + reconnectAttempts + '/' + RECONNECT_DELAYS.length + '）。', true);
        reconnectTimer = window.setTimeout(function () {
            reconnectTimer = 0;
            if (destroyed || !selectedUuid || subscribed) return;
            selectInstance(selectedUuid, true);
        }, delay);
    }

    function manualReconnect() {
        if (!selectedUuid || retryCooldown) return;
        retryCooldown = true;
        updateRetryButton(false);
        window.setTimeout(function () {
            retryCooldown = false;
            updateRetryButton(!subscribed);
        }, 5000);
        resetReconnect();
        setStatus('正在手动重连…');
        selectInstance(selectedUuid, true);
    }

    function fetchApi(payload, timeoutMs) {
        var body = Object.assign({ csrf_token: csrf, client: clientId }, payload);
        return fetch(api, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal
        }).then(function (response) {
            return response.json().then(function (value) {
                return { ok: response.ok, status: response.status, value: value };
            });
        });
    }

    var followOutput = true;

    function bindSmartScroll() {
        var vp = term.element.querySelector('.xterm-viewport');
        if (!vp) return;
        vp.addEventListener('scroll', function () {
            var atBottom = vp.scrollTop + vp.clientHeight >= vp.scrollHeight - 8;
            if (atBottom !== followOutput) {
                followOutput = atBottom;
            }
        });
        vp.addEventListener('wheel', function (event) {
            var atTop = vp.scrollTop <= 0 && event.deltaY < 0;
            var atBottom = vp.scrollTop + vp.clientHeight >= vp.scrollHeight - 1 && event.deltaY > 0;
            if (!atTop && !atBottom) return;
            event.preventDefault();
            event.stopPropagation();
            var lenisActive = !!window.Lenis && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (lenisActive) {
                var forwarded;
                try {
                    forwarded = new WheelEvent('wheel', {
                        deltaY: event.deltaY,
                        deltaX: event.deltaX,
                        deltaMode: event.deltaMode,
                        clientX: event.clientX,
                        clientY: event.clientY,
                        bubbles: true,
                        cancelable: true
                    });
                } catch (e) {
                    forwarded = null;
                }
                if (forwarded) {
                    window.dispatchEvent(forwarded);
                    return;
                }
            }
            window.scrollBy(0, event.deltaY);
        }, { passive: false });
    }

    function smartWrite(data) {
        if (!term) return;
        term.write(data);
        if (followOutput) {
            term.scrollToBottom();
        }
    }

    function appendText(text) {
        smartWrite(String(text));
    }

    function appendNotice(text) {
        smartWrite('\x1b[38;5;217m' + text + '\x1b[0m\r\n');
    }

    function statusLabel(status) {
        var entry = STATUS_LABELS[String(status)] || { text: '未知', cls: 'stopped' };
        return entry;
    }

    function renderInstances() {
        if (!instanceList) return;
        instanceList.replaceChildren();
        if (!instances.length) {
            var empty = document.createElement('div');
            empty.className = 'terminal-instance-empty';
            empty.textContent = '守护进程上暂无实例';
            instanceList.appendChild(empty);
            return;
        }
        instances.forEach(function (item) {
            var card = document.createElement('button');
            card.type = 'button';
            card.className = 'terminal-instance' + (item.instanceUuid === selectedUuid ? ' active' : '');
            card.setAttribute('aria-pressed', item.instanceUuid === selectedUuid ? 'true' : 'false');
            var status = statusLabel(item.status);
            var label = document.createElement('span');
            label.className = 'terminal-instance-status ' + status.cls;
            label.textContent = status.text;
            var name = document.createElement('strong');
            name.textContent = item.nickname || item.instanceUuid;
            card.appendChild(name);
            card.appendChild(label);
            card.addEventListener('click', function () {
                selectInstance(item.instanceUuid);
            });
            instanceList.appendChild(card);
        });
    }

    function updateConsoleButtons() {
        var item = selectedInstance;
        var status = item ? String(item.status) : '';
        var running = status === '3';
        var stopped = status === '0';
        root.querySelectorAll('[data-action]').forEach(function (button) {
            var action = button.getAttribute('data-action');
            if (!item) {
                button.disabled = true;
                return;
            }
            if (action === 'start') button.disabled = !stopped;
            else button.disabled = !running;
        });
        if (term) term.options.disableStdin = !running;
        if (consoleStatus) {
            if (item) {
                var status = statusLabel(item.status);
                consoleStatus.textContent = status.text;
                consoleStatus.className = 'terminal-instance-status ' + status.cls;
                consoleStatus.hidden = false;
            } else {
                consoleStatus.hidden = true;
            }
        }
    }

    async function loadOverview() {
        try {
            var result = await fetchApi({ action: 'overview' });
            if (!result.ok) throw new Error(result.value.error || '面板概览获取失败');
            if (daemonVersion) daemonVersion.textContent = result.value.data.version || '-';
            if (daemonInstances) daemonInstances.textContent = (result.value.data.running || 0) + ' / ' + (result.value.data.total || 0);
        } catch (error) {
            if (error.name === 'AbortError') return;
            setStatus(error.message || '面板概览获取失败', true);
        }
    }

    async function loadInstances() {
        if (destroyed) return;
        try {
            var result = await fetchApi({ action: 'instances' });
            if (!result.ok) throw new Error(result.value.error || '实例列表获取失败');
            instances = result.value.data.instances || [];
            if (selectedUuid) {
                selectedInstance = instances.find(function (item) { return item.instanceUuid === selectedUuid; }) || null;
                if (!selectedInstance) {
                    selectedUuid = '';
                    selectedInstance = null;
                    subscribed = false;
                    if (consoleTitle) consoleTitle.textContent = '未选择实例';
                }
            }
            renderInstances();
            updateConsoleButtons();
            if (!result.value.error) setStatus('实例列表已更新');
        } catch (error) {
            if (error.name === 'AbortError') return;
            setStatus(error.message || '实例列表获取失败', true);
        }
    }

    async function selectInstance(uuid, silent) {
        if (destroyed) return;
        var serial = ++selectionSerial;
        if (!silent) {
            resetReconnect();
        }
        selectedUuid = uuid;
        selectedInstance = instances.find(function (item) { return item.instanceUuid === uuid; }) || null;
        renderInstances();
        updateConsoleButtons();
        updateRetryButton(false);
        if (!selectedInstance) return;
        if (consoleTitle) consoleTitle.textContent = selectedInstance.nickname || selectedInstance.instanceUuid;
        subscribed = false;
        pollGeneration++;
        loadingHistory = true;
        pendingOutput = [];
        if (term) term.clear();
        setStatus('正在订阅实例输出…');
        window.dispatchEvent(new CustomEvent('terminal:instance-selected', { detail: { uuid: uuid } }));
        if (!silent) {
            appendNotice('—— 已切换到实例 ' + (selectedInstance.nickname || selectedInstance.instanceUuid) + ' ——');
        }
        try {
            var subscribeResult = await fetchApi({ action: 'subscribe', instanceUuid: uuid });
            if (serial !== selectionSerial || selectedUuid !== uuid) return;
            if (!subscribeResult.ok) throw new Error(subscribeResult.value.error || '订阅失败');
            subscribed = true;
            var generation = pollGeneration;
            resetReconnect();
            setStatus('已订阅实例实时输出');
            lastResizeSent = { cols: 0, rows: 0 };
            syncResize();
            startPoll(generation);

            // 先建立实时订阅，避免读取历史日志期间错过新输出。
            try {
                var logResult = await fetchApi({ action: 'log', instanceUuid: uuid });
                if (serial !== selectionSerial || selectedUuid !== uuid) return;
                if (logResult.ok && logResult.value.data && typeof logResult.value.data.text === 'string') {
                    appendText(logResult.value.data.text);
                }
            } catch (logError) {
                if (logError.name === 'AbortError') return;
            } finally {
                if (serial !== selectionSerial || selectedUuid !== uuid) return;
                loadingHistory = false;
                pendingOutput.splice(0).forEach(function (text) {
                    appendText(text);
                });
            }
        } catch (error) {
            if (error.name === 'AbortError') return;
            if (serial !== selectionSerial || selectedUuid !== uuid) return;
            loadingHistory = false;
            pendingOutput = [];
            scheduleReconnect(error.message || '订阅失败');
        }
    }

    function startPoll(generation) {
        if (destroyed) return;
        generation = generation || pollGeneration;
        if (pollRunningGeneration === generation) return;
        pollRunningGeneration = generation;
        pollLoop(generation).finally(function () {
            if (pollRunningGeneration === generation) {
                pollRunningGeneration = 0;
            }
            if (!destroyed && subscribed && generation === pollGeneration) {
                startPoll(generation);
            }
        });
    }

    async function streamPoll(generation) {
        var body = { action: 'poll', csrf_token: csrf, client: clientId };
        var response = await fetch(api, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal
        });
        if (!response.ok) {
            var status = response.status;
            var errMsg = '';
            try {
                var j = await response.json();
                errMsg = j.error || '';
            } catch (e) {
            }
            throw { status: status, message: errMsg || '输出流获取失败' };
        }
        var reader = response.body.getReader();
        var decoder = new TextDecoder('utf-8');
        var buffer = '';
        while (!destroyed && !controller.signal.aborted && generation === pollGeneration) {
            var chunk = await reader.read();
            if (chunk.done) break;
            buffer += decoder.decode(chunk.value, { stream: true });
            var lines = buffer.split('\n');
            buffer = lines.pop();
            for (var i = 0; i < lines.length; i++) {
                var line = lines[i].trim();
                if (!line) continue;
                try {
                    var msg = JSON.parse(line);
                    if (msg.events && msg.events.length) handleEvents(msg.events, generation);
                } catch (e) {
                }
            }
        }
    }

    async function pollLoop(generation) {
        while (!destroyed && !controller.signal.aborted && subscribed && generation === pollGeneration) {
            try {
                await streamPoll(generation);
            } catch (error) {
                if (error && error.name === 'AbortError') break;
                if (generation !== pollGeneration) break;
                if (error && error.status === 403) {
                    setStatus('登录状态已失效，请刷新页面后重试', true);
                    break;
                }
                var message = (error && error.message) || '输出流已断开';
                subscribed = false;
                if (message.indexOf('会话已失效') !== -1 || message.indexOf('重新连接') !== -1) {
                    scheduleReconnect('面板会话已失效');
                } else {
                    scheduleReconnect(message);
                }
                break;
            }
        }
    }

    function handleEvents(events, generation) {
        if (generation !== pollGeneration) return;
        events.forEach(function (eventItem) {
            if (eventItem.type === 'fatal') {
                subscribed = false;
                scheduleReconnect(eventItem.data || '面板连接已断开');
                return;
            }
            if (eventItem.event === 'instance/stdout') {
                var text = eventItem.data;
                if (text && typeof text === 'object') {
                    text = text.text;
                }
                if (typeof text === 'string') {
                    if (loadingHistory) pendingOutput.push(text);
                    else appendText(text);
                }
            } else if (eventItem.event === 'instance/opened' || eventItem.event === 'instance/stopped' || eventItem.event === 'instance/failure') {
                var label = { 'instance/opened': '实例已启动', 'instance/stopped': '实例已停止', 'instance/failure': '实例发生异常' }[eventItem.event];
                appendNotice('—— ' + label + ' ——');
                loadInstances();
            } else if (eventItem.event === 'error') {
                setStatus('面板返回错误：' + (eventItem.data && typeof eventItem.data === 'string' ? eventItem.data : '未知错误'), true);
            } else if (eventItem.event === 'stream/detail' && eventItem.data) {
                var status = (eventItem.data.status == null ? selectedInstance.status : eventItem.data.status);
                if (selectedInstance) {
                    selectedInstance.status = status;
                    updateConsoleButtons();
                    renderInstances();
                }
            }
        });
    }

    function confirmDialog(message) {
        return new Promise(function (resolve) {
            if (!confirmEl || !confirmText || !confirmOk || !confirmCancel) {
                resolve(false);
                return;
            }
            confirmText.textContent = message;
            confirmEl.hidden = false;
            confirmOk.disabled = false;
            confirmCancel.disabled = false;
            function done(value) {
                confirmEl.hidden = true;
                confirmOk.removeEventListener('click', onOk);
                confirmCancel.removeEventListener('click', onCancel);
                resolve(value);
            }
            function onOk() { done(true); }
            function onCancel() { done(false); }
            confirmOk.addEventListener('click', onOk);
            confirmCancel.addEventListener('click', onCancel);
        });
    }

    async function control(action) {
        var item = selectedInstance;
        if (!item) return;
        var labels = { start: '启动', stop: '停止', restart: '重启', kill: '强制终止' };
        var label = labels[action] || action;
        var message = '确认对实例「' + (item.nickname || item.instanceUuid) + '」执行「' + label + '」操作吗？';
        if (action === 'kill') message += ' 该操作会直接终止服务器进程，可能导致数据丢失。';
        var confirmed = await confirmDialog(message);
        if (!confirmed) return;
        var button = root.querySelector('[data-action="' + action + '"]');
        if (button) button.disabled = true;
        try {
            var result = await fetchApi({ action: 'control', operation: action, instanceUuid: item.instanceUuid });
            if (!result.ok) throw new Error(result.value.error || '操作失败');
            setStatus(label + '操作已提交，等待实例状态更新…');
            window.setTimeout(loadInstances, 4000);
        } catch (error) {
            if (error.name === 'AbortError') return;
            setStatus(error.message || '操作失败', true);
        } finally {
            if (button) button.disabled = false;
            updateConsoleButtons();
        }
    }

    async function sendInput(data) {
        var item = selectedInstance;
        if (!item) return;
        try {
            var result = await fetchApi({ action: 'input', input: data, instanceUuid: item.instanceUuid });
            if (!result.ok) throw new Error(result.value.error || '输入发送失败');
        } catch (error) {
            if (error.name === 'AbortError') return;
            setStatus(error.message || '输入发送失败', true);
        }
    }

    var lastResizeSent = { cols: 0, rows: 0 };

    function syncResize() {
        if (!term || !selectedInstance || !subscribed) return;
        var cols = Math.max(TERMINAL_MIN_COLS, term.cols - 1);
        var rows = Math.max(5, term.rows - 1);
        if (cols === lastResizeSent.cols && rows === lastResizeSent.rows) return;
        lastResizeSent = { cols: cols, rows: rows };
        fetchApi({ action: 'resize', cols: cols, rows: rows, instanceUuid: selectedInstance.instanceUuid }).catch(function () {
        });
    }

    function destroy() {
        destroyed = true;
        controller.abort();
        if (refreshTimer) window.clearTimeout(refreshTimer);
        if (reconnectTimer) window.clearTimeout(reconnectTimer);
        window.removeEventListener('site:before-swap', destroy);
        if (term) {
            try {
                term.dispose();
            } catch (error) {
            }
            term = null;
        }
        window.__mcTerminalInstance = null;
        window.__mcTerminalShared = null;
    }

    if (xtermContainer && typeof window.Terminal === 'function') {
        term = new window.Terminal({
            cursorBlink: true,
            scrollback: 10000,
            cols: 80,
            rows: 24,
            rendererType: 'dom',
            fontSize: fontSize,
            fontFamily: fontFamily + ", Consolas, 'Courier New', 'Microsoft YaHei', monospace",
            fontWeight: fontBold ? 'bold' : 'normal',
            fontWeightBold: 'bold',
            theme: {
                background: '#0d0f0e',
                foreground: '#edf1eb',
                cursor: '#f9a8d4',
                black: '#000000', red: '#ff5f5f', green: '#87d787', yellow: '#d7d787',
                blue: '#87afd7', magenta: '#d787d7', cyan: '#87d7d7', white: '#ffffff',
                brightBlack: '#4f9cff', brightRed: '#ff8787', brightGreen: '#afd7af',
                brightYellow: '#d7d7af', brightBlue: '#87c7ff', brightMagenta: '#d7afd7',
                brightCyan: '#afd7d7', brightWhite: '#ffffff'
            }
        });
        term.open(xtermContainer);
        bindSmartScroll();
        if (window.FitAddon && window.FitAddon.FitAddon) {
            fitAddon = new window.FitAddon.FitAddon();
            term.loadAddon(fitAddon);
            var doFit = function () {
                if (destroyed) return;
                try {
                    fitAddon.fit();
                    term.resize(Math.max(TERMINAL_MIN_COLS, Math.min(TERMINAL_MAX_COLS, term.cols)), term.rows);
                } catch (error) {
                }
                syncResize();
            };
            doFit();
            if (window.requestAnimationFrame) {
                window.requestAnimationFrame(function () {
                    doFit();
                    window.requestAnimationFrame(doFit);
                });
            }
            window.setTimeout(doFit, 200);
            window.setTimeout(doFit, 600);
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(function () {
                    doFit();
                });
            }
            window.addEventListener('resize', function () {
                window.setTimeout(doFit, 100);
            });
        }
        term.onData(function (data) {
            sendInput(data);
        });
        term.options.disableStdin = true;
        applyFont();
    }
    if (refreshButton) {
        refreshButton.addEventListener('click', loadInstances);
    }
    if (reconnectButton) {
        reconnectButton.addEventListener('click', manualReconnect);
    }
    if (fontFamilySelect) {
        fontFamilySelect.addEventListener('change', function () {
            fontFamily = fontFamilySelect.value;
            applyFont();
            saveFontPrefs();
        });
    }
    if (fontDecButton) {
        fontDecButton.addEventListener('click', function () { changeFontSize(-1); });
    }
    if (fontIncButton) {
        fontIncButton.addEventListener('click', function () { changeFontSize(1); });
    }
    if (fontBoldButton) {
        fontBoldButton.addEventListener('click', toggleFontBold);
    }
    if (confirmEl) {
        confirmEl.addEventListener('click', function (event) {
            if (event.target === confirmEl && confirmCancel && !confirmCancel.disabled) {
                confirmCancel.click();
            }
        });
    }
    root.querySelectorAll('[data-action]').forEach(function (button) {
        button.addEventListener('click', function () {
            control(button.getAttribute('data-action'));
        });
    });

    window.addEventListener('site:before-swap', destroy);
    window.__mcTerminalInstance = { destroy: destroy };
    window.__mcTerminalShared = {
        selectedUuid: function () { return selectedUuid; },
        selectedInstance: function () { return selectedInstance; },
        refreshInstances: function () { return loadInstances(); },
        whenReady: function (callback) {
            if (typeof callback !== 'function') return;
            if (terminalReady) {
                callback();
            } else {
                readyCallbacks.push(callback);
            }
        }
    };

    loadFontPrefs();
    applyFont();
    (async function init() {
        try {
            await Promise.all([loadInstances(), loadOverview()]);
            terminalReady = true;
            readyCallbacks.splice(0).forEach(function (callback) {
                try { callback(); } catch (error) {}
            });
            window.dispatchEvent(new Event('terminal:ready'));
        } catch (error) {
            setStatus(error.message || '终端初始化失败，请稍后重试。', true);
        }
    })();
    refreshTimer = window.setTimeout(function tick() {
        if (destroyed) return;
        loadOverview();
        loadInstances();
        refreshTimer = window.setTimeout(tick, 20000);
    }, 20000);
}());
