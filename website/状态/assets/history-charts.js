(function () {
    const accent = '#f9a8d4';
    const cyan = '#7dd3fc';
    const muted = '#98a196';
    const definitions = {
        mspt: { title: 'MSPT', columns: ['mspt'], labels: ['MSPT'], colors: [accent], unit: 'ms' },
        process_cpu: { title: 'Java 进程 CPU', columns: ['process_cpu_percent'], labels: ['CPU'], colors: [accent], unit: 'percent' },
        process_memory: { title: 'Java 进程内存', columns: ['process_memory_bytes'], labels: ['工作集'], colors: [accent], unit: 'bytes' },
        host_cpu: { title: '节点 CPU', columns: ['host_cpu_percent'], labels: ['CPU'], colors: [accent], unit: 'percent' },
        host_memory: { title: '节点内存', columns: ['host_memory_used_bytes', 'host_memory_total_bytes'], labels: ['已使用', '总内存'], colors: [accent, cyan], unit: 'bytes' },
        network: { title: '节点网络速度', columns: ['network_receive_rate', 'network_send_rate'], labels: ['下载', '上传'], colors: [cyan, accent], unit: 'rate' }
    };

    function bytes(value, suffix) {
        value = Number(value);
        if (!Number.isFinite(value)) return '-';
        const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        let index = 0;
        while (Math.abs(value) >= 1024 && index < units.length - 1) {
            value /= 1024;
            index++;
        }
        return value.toFixed(index < 2 ? 0 : 2) + ' ' + units[index] + (suffix || '');
    }

    function valueLabel(metric, value) {
        const definition = definitions[metric] || definitions.mspt;
        if (value == null || !Number.isFinite(Number(value))) return '-';
        if (definition.unit === 'bytes') return bytes(value, '');
        if (definition.unit === 'rate') return bytes(value, '/s');
        if (definition.unit === 'percent') return Number(value).toFixed(1) + '%';
        return Number(value).toFixed(2) + ' ms';
    }

    function datasets(metric, points, mini) {
        const definition = definitions[metric] || definitions.mspt;
        return definition.columns.map(function (column, index) {
            return {
                label: definition.labels[index],
                data: points.map(function (point) { return point[column]; }),
                borderColor: definition.colors[index],
                backgroundColor: definition.colors[index] + (mini ? '24' : '18'),
                borderWidth: mini ? 1.5 : 2,
                pointRadius: 0,
                pointHitRadius: mini ? 0 : 8,
                tension: 0.28,
                spanGaps: true,
                fill: definition.columns.length === 1
            };
        });
    }

    function options(metric, mini) {
        const definition = definitions[metric] || definitions.mspt;
        const unitTitles = {
            ms: '毫秒（ms）',
            percent: '使用率（%）',
            bytes: '字节',
            rate: '每秒字节数'
        };
        const axis = mini ? { display: false } : {
            grid: { color: '#30362f88' },
            ticks: { color: muted, maxTicksLimit: 8 }
        };
        return {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: mini ? 180 : 300 },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: !mini && definition.columns.length > 1, labels: { color: '#edf1eb', boxWidth: 12 } },
                tooltip: mini ? { enabled: false } : {
                    callbacks: {
                        label: function (context) { return context.dataset.label + ': ' + valueLabel(metric, context.raw); }
                    }
                }
            },
            scales: {
                x: mini ? axis : {
                    grid: { display: false },
                    ticks: { color: muted, maxTicksLimit: 8, maxRotation: 0 },
                    title: { display: true, text: '时间', color: muted, padding: { top: 10 } }
                },
                y: mini ? axis : {
                    grid: { color: '#30362f88' },
                    ticks: { color: muted, maxTicksLimit: 8 },
                    title: { display: true, text: unitTitles[definition.unit] || '数值', color: muted, padding: { bottom: 10 } }
                }
            }
        };
    }

    function labels(points, mini) {
        return points.map(function (point) {
            const date = new Date(point.time);
            if (Number.isNaN(date.getTime())) return point.time;
            return mini
                ? date.toLocaleTimeString('zh-CN', { hour12: false })
                : date.toLocaleString('zh-CN', { hour12: false });
        });
    }

    window.McHistoryCharts = { definitions: definitions, datasets: datasets, labels: labels, options: options, valueLabel: valueLabel };
}());
