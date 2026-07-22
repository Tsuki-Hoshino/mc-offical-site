(function () {
    function receivedAt(record) {
        if (!record || typeof record.received_at !== 'string') return NaN;
        return new Date(record.received_at).getTime();
    }

    function offlineAfterMilliseconds() {
        const configured = Number(window.MCSiteConfig && window.MCSiteConfig.offlineAfterSeconds);
        return Math.max(5, Number.isFinite(configured) ? configured : 15) * 1000;
    }

    function isOnline(record) {
        const data = record && record.payload ? record.payload : {};
        const timestamp = receivedAt(record);
        if (data.online !== true || !Number.isFinite(timestamp)) return false;
        const age = Date.now() - timestamp;
        return age >= -60000 && age <= offlineAfterMilliseconds();
    }

    window.MCServerStatus = {
        isOnline: isOnline,
        receivedAt: receivedAt
    };
}());
