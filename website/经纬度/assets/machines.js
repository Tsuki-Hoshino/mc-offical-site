(function () {
    const dimension = document.getElementById('machine-dimension');
    const x = document.getElementById('machine-x');
    const y = document.getElementById('machine-y');
    const z = document.getElementById('machine-z');
    const previewDimension = document.getElementById('coordinate-preview-dimension');
    const previewValue = document.getElementById('coordinate-preview-value');

    function number(value) {
        const parsed = Number(value);
        return value.trim() !== '' && Number.isFinite(parsed) ? parsed : null;
    }

    function format(value) {
        return Number.isInteger(value) ? String(value) : String(Number(value.toFixed(6)));
    }

    function updatePreview() {
        if (!dimension || !x || !y || !z) return;
        const values = [number(x.value), number(y.value), number(z.value)];
        if (dimension.value === 'end') {
            previewDimension.textContent = '末地';
            previewValue.textContent = '末地使用独立坐标系，无需换算';
            return;
        }
        previewDimension.textContent = dimension.value === 'nether' ? '主世界' : '地狱';
        if (values.some(function (value) { return value === null; })) {
            previewValue.textContent = '等待输入坐标';
            return;
        }
        const scale = dimension.value === 'nether' ? 8 : 1 / 8;
        previewValue.textContent = [
            Math.floor(values[0] * scale),
            values[1],
            Math.floor(values[2] * scale)
        ].map(format).join(', ');
    }

    if (dimension) {
        [dimension, x, y, z].forEach(function (field) {
            field.addEventListener('input', updatePreview);
            field.addEventListener('change', updatePreview);
        });
        updatePreview();
    }

    const maxImageEdge = 1920;
    const imageQuality = 0.82;

    function loadImage(file) {
        if ('createImageBitmap' in window) {
            return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bitmap) {
                return {
                    source: bitmap,
                    width: bitmap.width,
                    height: bitmap.height,
                    release: function () { bitmap.close(); }
                };
            });
        }

        return new Promise(function (resolve, reject) {
            const url = URL.createObjectURL(file);
            const image = new Image();
            image.onload = function () {
                resolve({
                    source: image,
                    width: image.naturalWidth,
                    height: image.naturalHeight,
                    release: function () { URL.revokeObjectURL(url); }
                });
            };
            image.onerror = function () {
                URL.revokeObjectURL(url);
                reject(new Error('无法读取图片'));
            };
            image.src = url;
        });
    }

    function canvasBlob(canvas) {
        return new Promise(function (resolve, reject) {
            canvas.toBlob(function (blob) {
                if (blob) resolve(blob);
                else reject(new Error('浏览器不支持图片压缩'));
            }, 'image/webp', imageQuality);
        });
    }

    async function compressImage(file) {
        const decoded = await loadImage(file);
        try {
            const scale = Math.min(1, maxImageEdge / Math.max(decoded.width, decoded.height));
            const width = Math.max(1, Math.round(decoded.width * scale));
            const height = Math.max(1, Math.round(decoded.height * scale));
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const context = canvas.getContext('2d', { alpha: true });
            if (!context) throw new Error('无法创建图片画布');
            context.drawImage(decoded.source, 0, 0, width, height);
            const blob = await canvasBlob(canvas);
            const baseName = file.name.replace(/\.[^.]+$/, '') || 'image';
            return new File([blob], baseName + '.webp', {
                type: 'image/webp',
                lastModified: Date.now()
            });
        } finally {
            decoded.release();
        }
    }

    function setFormBusy(form) {
        if (!form) return;
        const busy = Array.from(form.querySelectorAll('[data-image-compress]')).some(function (input) {
            return input.imageCompressionPending;
        });
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
            button.disabled = busy;
        });
    }

    document.querySelectorAll('.machine-upload input[type="file"]').forEach(function (input) {
        const label = input.parentElement.querySelector('[data-file-label]');
        input.addEventListener('change', async function () {
            const file = input.files && input.files[0];
            input.parentElement.classList.toggle('has-file', Boolean(file));
            input.setCustomValidity('');
            if (!file) {
                label.textContent = '选择文件';
                return;
            }

            const isImage = /^image\//i.test(file.type) || /\.(?:jpe?g|png|gif|webp)$/i.test(file.name);
            if (!input.hasAttribute('data-image-compress') || !isImage) {
                label.textContent = file.name;
                return;
            }

            input.imageCompressionPending = true;
            setFormBusy(input.form);
            label.textContent = '正在压缩图片...';
            try {
                const compressed = await compressImage(file);
                const files = new DataTransfer();
                files.items.add(compressed);
                input.files = files.files;
                label.textContent = compressed.name;
            } catch (error) {
                input.value = '';
                input.parentElement.classList.remove('has-file');
                input.setCustomValidity('图片压缩失败，请重新选择图片。');
                label.textContent = '图片压缩失败';
                input.reportValidity();
            } finally {
                input.imageCompressionPending = false;
                setFormBusy(input.form);
            }
        });
    });

    const photoLink = document.querySelector('[data-machine-photo]');
    const viewer = document.getElementById('machine-image-viewer');
    if (photoLink && viewer && typeof viewer.showModal === 'function') {
        const stage = viewer.querySelector('.recipe-viewer-stage');
        const image = viewer.querySelector('img');
        const closeButton = viewer.querySelector('.recipe-viewer-close');
        let zoom = 1;
        let panX = 0;
        let panY = 0;
        let dragging = false;
        let dragStartX = 0;
        let dragStartY = 0;

        function applyTransform() {
            image.style.transform = 'translate(' + panX + 'px,' + panY + 'px) scale(' + zoom + ')';
            image.classList.toggle('zoomed', zoom > 1);
        }

        function resetTransform() {
            zoom = 1;
            panX = 0;
            panY = 0;
            dragging = false;
            image.classList.remove('dragging');
            applyTransform();
        }

        function unlockPage() {
            document.documentElement.classList.remove('recipe-viewer-open');
            window.dispatchEvent(new Event('site:scroll-unlock'));
        }

        photoLink.addEventListener('click', function (event) {
            event.preventDefault();
            const preview = photoLink.querySelector('img');
            image.src = photoLink.href;
            image.alt = preview ? preview.alt : '';
            resetTransform();
            viewer.showModal();
            document.documentElement.classList.add('recipe-viewer-open');
            window.dispatchEvent(new Event('site:scroll-lock'));
        });

        closeButton.addEventListener('click', function () { viewer.close(); });
        viewer.addEventListener('cancel', function (event) {
            event.preventDefault();
            viewer.close();
        });
        viewer.addEventListener('click', function (event) {
            if (event.target === viewer || event.target === stage) viewer.close();
        });
        viewer.addEventListener('close', function () {
            image.removeAttribute('src');
            image.alt = '';
            resetTransform();
            unlockPage();
        });
        stage.addEventListener('wheel', function (event) {
            event.preventDefault();
            const factor = event.deltaY < 0 ? 1.15 : 1 / 1.15;
            zoom = Math.min(5, Math.max(1, zoom * factor));
            if (zoom === 1) {
                panX = 0;
                panY = 0;
            }
            applyTransform();
        }, { passive: false });
        image.addEventListener('pointerdown', function (event) {
            if (zoom <= 1 || event.button !== 0) return;
            event.preventDefault();
            dragging = true;
            dragStartX = event.clientX - panX;
            dragStartY = event.clientY - panY;
            image.classList.add('dragging');
            image.setPointerCapture(event.pointerId);
        });
        image.addEventListener('pointermove', function (event) {
            if (!dragging) return;
            panX = event.clientX - dragStartX;
            panY = event.clientY - dragStartY;
            applyTransform();
        });
        function stopDragging(event) {
            if (!dragging) return;
            dragging = false;
            image.classList.remove('dragging');
            if (image.hasPointerCapture(event.pointerId)) image.releasePointerCapture(event.pointerId);
        }
        image.addEventListener('pointerup', stopDragging);
        image.addEventListener('pointercancel', stopDragging);
        image.addEventListener('dblclick', resetTransform);
        window.addEventListener('site:before-swap', unlockPage, { once: true });
    }
}());
