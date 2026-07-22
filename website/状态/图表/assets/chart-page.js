(function () {
    const params = new URLSearchParams(window.location.search);
    const metric = McHistoryCharts.definitions[params.get('metric')] ? params.get('metric') : 'mspt';
    const definition = McHistoryCharts.definitions[metric];
    const fromInput = document.getElementById('history-from');
    const toInput = document.getElementById('history-to');
    const form = document.getElementById('history-filter');
    const status = document.getElementById('history-status');
    const title = document.getElementById('history-title');
    const canvas = document.getElementById('history-chart');
    const presetButtons = document.querySelectorAll('[data-history-range]');
    let chart = null;
    let controller = null;

    function pad(value) { return String(value).padStart(2, '0'); }
    function formatInput(date) {
        return [date.getFullYear(), pad(date.getMonth() + 1), pad(date.getDate())].join('/') + ' '
            + [pad(date.getHours()), pad(date.getMinutes()), pad(date.getSeconds())].join(':');
    }
    function updateMetricLinks() {
        document.querySelectorAll('[data-history-metric]').forEach(function (link) {
            link.classList.toggle('active', link.dataset.historyMetric === metric);
        });
    }
    function updateUrl() {
        const url = new URL(window.location.href);
        url.searchParams.set('metric', metric);
        if (fromInput.value.trim()) url.searchParams.set('from', fromInput.value.trim()); else url.searchParams.delete('from');
        if (toInput.value.trim()) url.searchParams.set('to', toInput.value.trim()); else url.searchParams.delete('to');
        window.history.replaceState(window.history.state, '', url);
    }
    async function loadHistory() {
        if (controller) controller.abort();
        controller = new AbortController();
        status.textContent = '正在读取历史数据';
        form.classList.add('loading');
        const query = new URLSearchParams({ metric: metric, from: fromInput.value.trim(), to: toInput.value.trim() });
        try {
            const response = await fetch('../../api/history.php?' + query.toString(), { cache: 'no-store', signal: controller.signal });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || String(response.status));
            const options = McHistoryCharts.options(metric, false);
            options.scales.y.ticks.callback = function (value) { return McHistoryCharts.valueLabel(metric, value); };
            if (chart) chart.destroy();
            chart = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: McHistoryCharts.labels(result.points, false),
                    datasets: McHistoryCharts.datasets(metric, result.points, false)
                },
                options: options
            });
            status.textContent = result.points.length
                ? result.points.length + ' 个数据点 · 聚合间隔 ' + result.bucket_seconds + ' 秒'
                : '这个时间范围内暂无数据';
            updateUrl();
        } catch (error) {
            if (error.name !== 'AbortError') status.textContent = error.message === 'invalid_time' ? '时间格式错误，请使用 YYYY/MM/DD HH:mm:ss 或时间戳' : '历史数据读取失败：' + error.message;
        } finally {
            form.classList.remove('loading');
        }
    }

    const now = new Date();
    const hourAgo = new Date(now.getTime() - 3600000);
    fromInput.value = params.get('from') || formatInput(hourAgo);
    toInput.value = params.get('to') || formatInput(now);
    title.textContent = definition.title + ' 历史图表';
    document.title = definition.title + ' 历史图表 | ' + ((window.MCSiteConfig && window.MCSiteConfig.siteName) || 'Minecraft 服务器');
    updateMetricLinks();
    if (params.has('from') || params.has('to')) {
        presetButtons.forEach(function (button) { button.classList.remove('active'); });
    }
    presetButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const end = new Date();
            const seconds = Number(button.dataset.historyRange);
            fromInput.value = formatInput(new Date(end.getTime() - seconds * 1000));
            toInput.value = formatInput(end);
            presetButtons.forEach(function (item) { item.classList.toggle('active', item === button); });
            loadHistory();
        });
    });
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        presetButtons.forEach(function (button) { button.classList.remove('active'); });
        loadHistory();
    });
    window.addEventListener('site:before-swap', function () { if (chart) chart.destroy(); }, { once: true });
    loadHistory();
}());
