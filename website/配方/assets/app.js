(function () {
    const form = document.querySelector('.search');
    const input = document.querySelector('#q');
    const searchSuggestion = document.querySelector('#search-suggestion');
    const searchIcon = document.querySelector('.moe-search-icon');
    const grid = document.querySelector('#recipe-grid');
    const empty = document.querySelector('#empty-state');
    const count = document.querySelector('#result-count');
    const viewer = document.querySelector('#recipe-viewer');
    const viewerBody = document.querySelector('#recipe-viewer-body');
    const viewerStage = document.querySelector('#recipe-viewer-stage');
    const viewerImage = document.querySelector('#recipe-viewer-image');
    const viewerTitle = document.querySelector('#recipe-viewer-title');
    const viewerResult = document.querySelector('#recipe-viewer-result');
    const viewerCount = document.querySelector('#recipe-viewer-count');
    const viewerPack = document.querySelector('#recipe-viewer-pack');
    const viewerMaterialList = document.querySelector('#recipe-viewer-material-list');
    const viewerClose = document.querySelector('#recipe-viewer-close');

    if (!form || !input || !searchSuggestion || !searchIcon || !grid || !empty || !count || !viewer || !viewerBody || !viewerStage || !viewerImage || !viewerTitle || !viewerResult || !viewerCount || !viewerPack || !viewerMaterialList || !viewerClose) {
        return;
    }

    let controller = null;
    let timer = 0;
    let closeTimer = 0;
    let zoom = 1;
    let panX = 0;
    let panY = 0;
    let dragging = false;
    let dragStartX = 0;
    let dragStartY = 0;
    let activeSource = null;
    let viewerAnimation = null;
    let searchSuggestionIndex = 0;
    const searchSuggestions = [
        '中文：深色橡木栅栏门',
        '拼音：shense xiangmu zhalanmen',
        '首字母：ssxmzlm',
        '英文：dark oak fence gate',
        'MC ID：minecraft:dark_oak_fence_gate',
        '数据包 ID：xekr lazy dark_oak_fence_gate'
    ];

    function rotateSearchSuggestion() {
        searchSuggestion.classList.add('leaving');
        window.setTimeout(function () {
            searchSuggestionIndex = (searchSuggestionIndex + 1) % searchSuggestions.length;
            searchSuggestion.textContent = searchSuggestions[searchSuggestionIndex];
            searchSuggestion.classList.remove('leaving');
            searchSuggestion.classList.add('entering');
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(function () {
                    searchSuggestion.classList.remove('entering');
                });
            });
        }, 220);
    }

    window.setInterval(rotateSearchSuggestion, 4000);

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function render(items) {
        count.textContent = String(items.length);
        empty.hidden = items.length > 0;
        grid.innerHTML = items.map(function (item) {
            const title = escapeHtml(item.title);
            const file = escapeHtml(item.file || item.thumbnail || '');
            const pack = escapeHtml(item.pack || 'unknown');
            const result = escapeHtml(item.result);
            const amount = escapeHtml(item.count);
            const materials = escapeHtml(JSON.stringify(item.materials || []));
            const typeLabel = escapeHtml(item.recipe_type_label || '');
            const preview = file
                ? '<a class="image-link" href="' + file + '" aria-haspopup="dialog" data-title="' + title + '" data-result="' + result + '" data-count="' + amount + '" data-pack="' + pack + '" data-materials="' + materials + '"><img src="' + file + '" alt="' + title + '" loading="lazy"></a>'
                : '<div class="recipe-image-placeholder" aria-hidden="true"><span>' + escapeHtml(String(item.title || '?').slice(0, 2)) + '</span></div>';

            return [
                '<article class="recipe-card">',
                preview,
                '<div class="recipe-meta">',
                '<h2>' + title + '</h2>',
                '<code>' + result + '</code>',
                '<div class="subline">',
                '<span>' + pack + '</span>',
                '<span>数量 ' + amount + '</span>',
                typeLabel ? '<span>' + typeLabel + '</span>' : '',
                '</div>',
                '</div>',
                '</article>'
            ].join('');
        }).join('');
    }

    function updateUrl(query) {
        const url = new URL(window.location.href);
        if (query) {
            url.searchParams.set('q', query);
        } else {
            url.searchParams.delete('q');
        }
        window.history.replaceState(null, '', url);
    }

    function renderViewerMaterials(rawMaterials) {
        let materials = [];
        try {
            materials = JSON.parse(rawMaterials || '[]');
        } catch (error) {
            materials = [];
        }
        if (!Array.isArray(materials) || materials.length === 0) {
            viewerMaterialList.innerHTML = '<li class="materials-empty">材料数据未收录</li>';
            return;
        }
        viewerMaterialList.innerHTML = materials.map(function (material) {
            const id = escapeHtml(material.id || 'unknown');
            const name = escapeHtml(material.name || material.id || '未知材料');
            const amount = escapeHtml(material.count || 1);
            return '<li title="' + id + '"><span>' + name + '</span><strong>&times; ' + amount + '</strong></li>';
        }).join('');
    }

    function applyImageTransform() {
        viewerImage.style.transform = 'translate(' + panX + 'px, ' + panY + 'px) scale(' + zoom + ')';
        viewerImage.classList.toggle('zoomed', zoom > 1);
    }

    function resetImageTransform() {
        zoom = 1;
        panX = 0;
        panY = 0;
        dragging = false;
        viewerImage.classList.remove('dragging');
        applyImageTransform();
    }

    function lockPageScroll() {
        document.documentElement.classList.add('recipe-viewer-open');
        window.dispatchEvent(new Event('site:scroll-lock'));
    }

    function unlockPageScroll() {
        document.documentElement.classList.remove('recipe-viewer-open');
        window.dispatchEvent(new Event('site:scroll-unlock'));
    }

    function sourceTransform(sourceRect) {
        const targetRect = viewer.getBoundingClientRect();
        const sourceCenterX = sourceRect.left + sourceRect.width / 2;
        const sourceCenterY = sourceRect.top + sourceRect.height / 2;
        const targetCenterX = targetRect.left + targetRect.width / 2;
        const targetCenterY = targetRect.top + targetRect.height / 2;
        const scaleX = Math.max(.02, sourceRect.width / targetRect.width);
        const scaleY = Math.max(.02, sourceRect.height / targetRect.height);
        return 'translate3d(' + (sourceCenterX - targetCenterX) + 'px,' + (sourceCenterY - targetCenterY) + 'px,0) scale(' + scaleX + ',' + scaleY + ')';
    }

    function animateViewerOpen(sourceRect) {
        viewer.classList.add('viewer-opening');
        viewerAnimation = viewer.animate([
            { transform: sourceTransform(sourceRect), opacity: .28, borderRadius: '3px' },
            { transform: 'translate3d(0,0,0) scale(1,1)', opacity: 1, borderRadius: '3px' }
        ], {
            duration: 420,
            easing: 'cubic-bezier(.16,1,.3,1)',
            fill: 'both'
        });
        viewerAnimation.finished.then(function () {
            viewer.classList.remove('viewer-opening');
            if (viewerAnimation) {
                viewerAnimation.cancel();
                viewerAnimation = null;
            }
        }).catch(function () {});
    }

    function openViewer(link) {
        const image = link.querySelector('img');
        const sourceRect = (image || link).getBoundingClientRect();
        activeSource = image || link;
        viewerImage.src = link.href;
        viewerImage.alt = image ? image.alt : '';
        viewerTitle.textContent = link.dataset.title || (image ? image.alt : '配方大图');
        viewerResult.textContent = link.dataset.result || '-';
        viewerCount.textContent = link.dataset.count || '-';
        viewerPack.textContent = link.dataset.pack || 'unknown';
        renderViewerMaterials(link.dataset.materials);
        resetImageTransform();
        viewer.showModal();
        lockPageScroll();
        animateViewerOpen(sourceRect);
    }

    function closeViewer() {
        if (!viewer.open || viewer.classList.contains('closing')) {
            return;
        }
        viewer.classList.add('closing');
        if (viewerAnimation) {
            viewerAnimation.cancel();
            viewerAnimation = null;
        }
        const sourceRect = activeSource && activeSource.isConnected
            ? activeSource.getBoundingClientRect()
            : { left: innerWidth / 2, top: innerHeight / 2, width: 1, height: 1 };
        viewerAnimation = viewer.animate([
            { transform: 'translate3d(0,0,0) scale(1,1)', opacity: 1 },
            { transform: sourceTransform(sourceRect), opacity: .18 }
        ], {
            duration: 320,
            easing: 'cubic-bezier(.7,0,.84,0)',
            fill: 'both'
        });
        window.clearTimeout(closeTimer);
        closeTimer = window.setTimeout(function () {
            viewer.close();
        }, 340);
    }

    grid.addEventListener('click', function (event) {
        const link = event.target.closest('.image-link');
        if (!link || typeof viewer.showModal !== 'function') {
            return;
        }

        event.preventDefault();
        openViewer(link);
    });

    viewerStage.addEventListener('wheel', function (event) {
        event.preventDefault();
        event.stopPropagation();
        const factor = event.deltaY < 0 ? 1.15 : (1 / 1.15);
        zoom = Math.min(5, Math.max(1, zoom * factor));
        if (zoom === 1) {
            panX = 0;
            panY = 0;
        }
        applyImageTransform();
    }, { passive: false });

    viewer.addEventListener('wheel', function (event) {
        event.preventDefault();
        event.stopPropagation();
    }, { passive: false });

    viewerImage.addEventListener('pointerdown', function (event) {
        if (zoom <= 1 || event.button !== 0) {
            return;
        }
        event.preventDefault();
        dragging = true;
        dragStartX = event.clientX - panX;
        dragStartY = event.clientY - panY;
        viewerImage.classList.add('dragging');
        viewerImage.setPointerCapture(event.pointerId);
    });

    viewerImage.addEventListener('pointermove', function (event) {
        if (!dragging) {
            return;
        }
        panX = event.clientX - dragStartX;
        panY = event.clientY - dragStartY;
        applyImageTransform();
    });

    function stopDragging(event) {
        if (!dragging) {
            return;
        }
        dragging = false;
        viewerImage.classList.remove('dragging');
        if (viewerImage.hasPointerCapture(event.pointerId)) {
            viewerImage.releasePointerCapture(event.pointerId);
        }
    }

    viewerImage.addEventListener('pointerup', stopDragging);
    viewerImage.addEventListener('pointercancel', stopDragging);
    viewerImage.addEventListener('dblclick', resetImageTransform);
    viewer.addEventListener('cancel', function (event) {
        event.preventDefault();
        closeViewer();
    });
    viewerClose.addEventListener('click', closeViewer);
    viewer.addEventListener('click', function (event) {
        if (event.target === viewer || event.target === viewerBody || event.target === viewerStage) {
            closeViewer();
        }
    });
    viewer.addEventListener('close', function () {
        window.clearTimeout(closeTimer);
        viewer.classList.remove('closing');
        viewer.classList.remove('viewer-opening');
        if (viewerAnimation) {
            viewerAnimation.cancel();
            viewerAnimation = null;
        }
        activeSource = null;
        viewerImage.removeAttribute('src');
        viewerImage.alt = '';
        viewerTitle.textContent = '配方大图';
        viewerResult.textContent = '-';
        viewerCount.textContent = '-';
        viewerPack.textContent = '-';
        viewerMaterialList.innerHTML = '';
        resetImageTransform();
        unlockPageScroll();
    });
    window.addEventListener('site:before-swap', function () {
        document.documentElement.classList.remove('recipe-viewer-open');
    }, { once: true });
    function search() {
        const query = input.value.trim();
        updateUrl(query);

        if (controller) {
            controller.abort();
        }
        controller = new AbortController();

        fetch('api/search.php?q=' + encodeURIComponent(query), {
            signal: controller.signal,
            headers: {
                'Accept': 'application/json'
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Search failed');
                }
                return response.json();
            })
            .then(function (payload) {
                render(payload.items || []);
            })
            .catch(function (error) {
                if (error.name !== 'AbortError') {
                    form.submit();
                }
            });
    }

    searchIcon.addEventListener('click', function () {
        input.focus();
    });
    input.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(search, 120);
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        window.clearTimeout(timer);
        search();
    });
}());
