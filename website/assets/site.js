(function () {
    const root = document.documentElement;
    const nativeSetTimeout = window.setTimeout.bind(window);
    const nativeClearTimeout = window.clearTimeout.bind(window);
    const nativeSetInterval = window.setInterval.bind(window);
    const nativeClearInterval = window.clearInterval.bind(window);
    const nativeAddEventListener = EventTarget.prototype.addEventListener;
    const nativeRemoveEventListener = EventTarget.prototype.removeEventListener;
    const pageTimeouts = new Set();
    const pageIntervals = new Set();
    const pageListeners = [];

    let progress = null;
    let loadingLayer = null;
    let progressTimer = 0;
    let navigating = false;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const smoothScroll = window.Lenis && !reduceMotion ? new window.Lenis({
        autoRaf: true,
        duration: 1.05,
        easing: function (value) {
            return Math.min(1, 1.001 - Math.pow(2, -10 * value));
        },
        smoothWheel: true,
        wheelMultiplier: 0.9,
        syncTouch: false
    }) : null;

    nativeAddEventListener.call(window, 'site:scroll-lock', function () {
        if (smoothScroll) smoothScroll.stop();
    });
    nativeAddEventListener.call(window, 'site:scroll-unlock', function () {
        if (smoothScroll && !root.classList.contains('site-pjax-loading')) smoothScroll.start();
    });

    function ensureChrome() {
        if (!progress) {
            progress = document.createElement('div');
            progress.className = 'site-progress';
            progress.setAttribute('aria-hidden', 'true');
            root.appendChild(progress);
        }
        if (!loadingLayer) {
            loadingLayer = document.createElement('div');
            loadingLayer.className = 'site-loading-layer';
            loadingLayer.setAttribute('aria-hidden', 'true');
            loadingLayer.innerHTML = '<span>少女祈祷中...</span>';
            root.appendChild(loadingLayer);
        }
    }

    function startProgress() {
        ensureChrome();
        if (smoothScroll) smoothScroll.stop();
        nativeClearInterval(progressTimer);
        progress.classList.add('active');
        progress.classList.remove('complete');
        progress.style.transform = 'scaleX(.08)';
        loadingLayer.classList.add('active');
        root.classList.add('site-pjax-loading');

        let value = 8;
        progressTimer = nativeSetInterval(function () {
            value = Math.min(90, value + 4 + (Math.random() * 10));
            progress.style.transform = 'scaleX(' + (value / 100) + ')';
        }, 280);
    }

    function finishProgress() {
        ensureChrome();
        nativeClearInterval(progressTimer);
        progress.style.transform = 'scaleX(1)';
        progress.classList.add('complete');
        nativeSetTimeout(function () {
            progress.classList.remove('active', 'complete');
            progress.style.transform = 'scaleX(0)';
            loadingLayer.classList.remove('active');
            root.classList.remove('site-pjax-loading');
            if (smoothScroll) smoothScroll.start();
        }, 240);
    }

    function clearPageResources() {
        window.dispatchEvent(new Event('site:before-swap')); 
        pageTimeouts.forEach(nativeClearTimeout);
        pageIntervals.forEach(nativeClearInterval);
        pageTimeouts.clear();
        pageIntervals.clear();
        pageListeners.splice(0).forEach(function (entry) {
            nativeRemoveEventListener.call(entry.target, entry.type, entry.listener, entry.options);
        });
    }

    async function executePageScripts(nextDocument, pageUrl) {
        const scripts = Array.from(nextDocument.querySelectorAll('script'));
        for (const script of scripts) {
            const type = (script.getAttribute('type') || '').trim();
            if (type && type !== 'text/javascript' && type !== 'application/javascript') {
                continue;
            }

            const source = script.getAttribute('src');
            if (source) {
                const absolute = new URL(source, pageUrl).href;
                if (/\/assets\/(?:site|lenis\.min)\.js(?:\?|$)/.test(absolute)) {
                    continue;
                }
                if (/\/assets\/chart\.umd\.min\.js(?:\?|$)/.test(absolute) && window.Chart) {
                    continue;
                }
                const response = await fetch(absolute, { credentials: 'same-origin', cache: 'force-cache' });
                if (!response.ok) {
                    throw new Error('Script load failed: ' + absolute);
                }
                const code = await response.text();
                Function(code + '\n//# sourceURL=' + absolute)();
            } else if (script.textContent.trim()) {
                Function(script.textContent)();
            }
        }
    }

    async function navigate(url, addHistory) {
        if (navigating) {
            return;
        }
        navigating = true;
        startProgress();
        document.body.classList.add('site-page-leaving');

        try {
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'Pjax' },
                credentials: 'same-origin'
            });
            if (!response.ok) {
                throw new Error('Page load failed');
            }

            const html = await response.text();
            const nextDocument = new DOMParser().parseFromString(html, 'text/html');
            if (!nextDocument.body) {
                throw new Error('Invalid page');
            }

            await new Promise(function (resolve) {
                nativeSetTimeout(resolve, 180);
            });

            clearPageResources();
            if (addHistory) {
                history.pushState({ sitePjax: true }, '', url);
            }

            document.title = nextDocument.title || document.title;
            document.body.className = nextDocument.body.className;
            document.body.innerHTML = nextDocument.body.innerHTML;
            if (smoothScroll) {
                smoothScroll.scrollTo(0, { immediate: true, force: true });
            } else {
                window.scrollTo(0, 0);
            }
            await executePageScripts(nextDocument, url);
            finishProgress();
            navigating = false;
        } catch (error) {
            window.location.href = url;
        }
    }

    root.classList.add('site-motion');
    ensureChrome();
    startProgress();

    nativeAddEventListener.call(document, 'click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const link = event.target.closest('a[href]');
        if (!link || link.target === '_blank' || link.hasAttribute('download') || link.hasAttribute('data-no-pjax')) {
            return;
        }

        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin || !/^https?:$/.test(url.protocol)) {
            return;
        }
        if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) {
            return;
        }

        event.preventDefault();
        navigate(url.href, true);
    });

    nativeAddEventListener.call(window, 'popstate', function () {
        navigate(window.location.href, false);
    });

    nativeAddEventListener.call(window, 'pageshow', function () {
        navigating = false;
        document.body.classList.remove('site-page-leaving');
        finishProgress();
    });

    window.setTimeout = function (callback, delay) {
        const id = nativeSetTimeout.apply(window, arguments);
        pageTimeouts.add(id);
        return id;
    };
    window.clearTimeout = function (id) {
        pageTimeouts.delete(id);
        nativeClearTimeout(id);
    };
    window.setInterval = function (callback, delay) {
        const id = nativeSetInterval.apply(window, arguments);
        pageIntervals.add(id);
        return id;
    };
    window.clearInterval = function (id) {
        pageIntervals.delete(id);
        nativeClearInterval(id);
    };
    EventTarget.prototype.addEventListener = function (type, listener, options) {
        nativeAddEventListener.call(this, type, listener, options);
        if (this === window || this === document) {
            pageListeners.push({ target: this, type: type, listener: listener, options: options });
        }
    };

    if (document.readyState === 'complete') {
        nativeSetTimeout(finishProgress, 100);
    } else {
        nativeAddEventListener.call(window, 'load', function () {
            nativeSetTimeout(finishProgress, 100);
        }, { once: true });
    }

    history.replaceState({ sitePjax: true }, '', window.location.href);
}());
