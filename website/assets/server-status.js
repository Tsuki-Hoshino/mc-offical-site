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
        return data.online === true;
    }

    function isStale(record) {
        const timestamp = receivedAt(record);
        if (!Number.isFinite(timestamp)) return true;
        const age = Date.now() - timestamp;
        return age < -60000 || age > offlineAfterMilliseconds();
    }

    function isFresh(record) {
        return isOnline(record) && !isStale(record);
    }

    function state(record) {
        const data = record && record.payload ? record.payload : {};
        if (data.online === false) return 'offline';
        return isFresh(record) ? 'online' : 'unavailable';
    }

    window.MCServerStatus = {
        isOnline: isOnline,
        isStale: isStale,
        isFresh: isFresh,
        state: state,
        receivedAt: receivedAt
    };
}());
