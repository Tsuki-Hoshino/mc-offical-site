(function () {
    'use strict';

    if (window.__mcFileManager) {
        window.__mcFileManager.destroy();
        window.__mcFileManager = null;
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

    var breadcrumbEl = root.querySelector('[data-files-breadcrumb]');
    var tbody = root.querySelector('[data-files-tbody]');
    var filesStatus = root.querySelector('[data-files-status]');
    var uploadInput = root.querySelector('[data-files-input]');
    var uploadButton = root.querySelector('[data-files-upload]');
    var mkdirButton = root.querySelector('[data-files-mkdir]');
    var touchButton = root.querySelector('[data-files-touch]');
    var refreshButton = root.querySelector('[data-files-refresh]');
    var dialog = root.querySelector('[data-file-dialog]');
    var dialogTitle = dialog ? dialog.querySelector('[data-file-dialog-title]') : null;
    var dialogBody = dialog ? dialog.querySelector('[data-file-dialog-body]') : null;
    var dialogOk = dialog ? dialog.querySelector('[data-file-dialog-ok]') : null;
    var dialogCancel = dialog ? dialog.querySelector('[data-file-dialog-cancel]') : null;
    var filesTable = root.querySelector('.terminal-files-table');

    var controller = new AbortController();
    var destroyed = false;
    var loading = false;
    var cwdSegments = [];
    var limits = { maxUploadBytes: 104857600, maxEditBytes: 5242880 };
    var uploadBusy = false;

    function setStatus(text, isError) {
        if (!filesStatus) return;
        filesStatus.textContent = text;
        filesStatus.classList.toggle('error', !!isError);
        filesStatus.hidden = false;
    }

    function currentPath() {
        return cwdSegments.join('/') || '.';
    }

    function joinPath(name) {
        return currentPath() === '.' ? name : currentPath() + '/' + name;
    }

    function selectedInstanceUuid() {
        var shared = window.__mcTerminalShared;
        if (!shared) return '';
        return String(shared.selectedUuid() || '');
    }

    function fetchApi(payload) {
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

    function formatSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
        return (bytes / 1073741824).toFixed(2) + ' GB';
    }

    function formatTime(value) {
        var date = new Date(value);
        if (isNaN(date.getTime())) return '-';
        var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
            + ' ' + pad(date.getHours()) + ':' + pad(date.getMinutes());
    }

    function renderBreadcrumb() {
        if (!breadcrumbEl) return;
        breadcrumbEl.replaceChildren();
        var rootButton = document.createElement('button');
        rootButton.type = 'button';
        rootButton.textContent = '根目录';
        rootButton.title = '返回实例根目录';
        rootButton.addEventListener('click', function () {
            cwdSegments = [];
            loadList();
        });
        breadcrumbEl.appendChild(rootButton);
        var acc = [];
        cwdSegments.forEach(function (segment, index) {
            acc.push(segment);
            (function (segments) {
                var separator = document.createElement('span');
                separator.textContent = '/';
                separator.setAttribute('aria-hidden', 'true');
                var button = document.createElement('button');
                button.type = 'button';
                button.textContent = segment;
                button.addEventListener('click', function () {
                    cwdSegments = segments.slice();
                    loadList();
                });
                breadcrumbEl.appendChild(separator);
                breadcrumbEl.appendChild(button);
            })(acc.slice());
        });
    }

    function renderEmpty(message) {
        if (!tbody) return;
        tbody.replaceChildren();
        var tr = document.createElement('tr');
        var td = document.createElement('td');
        td.colSpan = 4;
        td.className = 'terminal-files-empty';
        td.textContent = message;
        tr.appendChild(td);
        tbody.appendChild(tr);
    }

    function renderRows(items) {
        if (!tbody) return;
        tbody.replaceChildren();
        if (!items.length) {
            renderEmpty('此目录为空');
            return;
        }
        items.forEach(function (item) {
            var name = String(item.name || '');
            var isDir = Number(item.type) === 0;
            var tr = document.createElement('tr');

            var nameCell = document.createElement('td');
            var nameWrap = document.createElement('div');
            nameWrap.className = 'terminal-file-name';
            var typeMark = document.createElement('span');
            typeMark.className = 'terminal-file-type' + (isDir ? ' dir' : '');
            typeMark.textContent = isDir ? '目录' : '文件';
            nameWrap.appendChild(typeMark);
            var nameText = document.createElement('strong');
            nameText.textContent = name + (isDir ? '/' : '');
            nameText.title = name;
            nameWrap.appendChild(nameText);
            nameCell.appendChild(nameWrap);
            if (isDir) {
                nameCell.tabIndex = 0;
                nameCell.setAttribute('role', 'button');
                nameCell.addEventListener('click', function () {
                    cwdSegments.push(name);
                    loadList();
                });
                nameCell.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        cwdSegments.push(name);
                        loadList();
                    }
                });
            }
            tr.appendChild(nameCell);

            var sizeCell = document.createElement('td');
            sizeCell.className = 'terminal-files-size';
            sizeCell.textContent = isDir ? '-' : formatSize(Number(item.size) || 0);
            tr.appendChild(sizeCell);

            var timeCell = document.createElement('td');
            timeCell.className = 'terminal-files-time';
            timeCell.textContent = formatTime(String(item.time || ''));
            tr.appendChild(timeCell);

            var opsCell = document.createElement('td');
            opsCell.className = 'terminal-files-ops';
            var ops = document.createElement('div');
            ops.className = 'terminal-file-ops';
            if (!isDir) {
                ops.appendChild(makeOpButton('编辑', function () { openEdit(name); }));
                ops.appendChild(makeOpButton('下载', function () { downloadFile(name); }));
            }
            ops.appendChild(makeOpButton('重命名', function () { openRename(name, isDir); }));
            var deleteButton = makeOpButton('删除', function () { openDelete(name, isDir); });
            deleteButton.classList.add('danger');
            ops.appendChild(deleteButton);
            opsCell.appendChild(ops);
            tr.appendChild(opsCell);

            tbody.appendChild(tr);
        });
    }

    function makeOpButton(label, onClick) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'terminal-file-op';
        button.textContent = label;
        button.addEventListener('click', onClick);
        return button;
    }

    function requireInstance() {
        var uuid = selectedInstanceUuid();
        if (!uuid) {
            setStatus('请先在控制台选择一个实例。', true);
            return '';
        }
        return uuid;
    }

    async function loadList() {
        var uuid = requireInstance();
        if (!uuid || loading) return;
        loading = true;
        setStatus('正在加载目录…');
        try {
            var result = await fetchApi({
                action: 'fileList',
                instanceUuid: uuid,
                path: currentPath(),
                page: 0,
                pageSize: 100
            });
            if (!result.ok) throw new Error(result.value.error || '目录加载失败');
            var data = result.value.data || {};
            renderRows(data.items || []);
            renderBreadcrumb();
            var total = Number(data.total) || 0;
            setStatus('共 ' + total + ' 项' + (total > 100 ? '（仅显示前 100 项）' : ''));
        } catch (error) {
            if (error.name === 'AbortError') return;
            setStatus(error.message || '目录加载失败', true);
        } finally {
            loading = false;
        }
    }

    async function loadLimits() {
        try {
            var result = await fetchApi({ action: 'fileLimits' });
            if (result.ok && result.value.data) {
                limits.maxUploadBytes = Number(result.value.data.maxUploadBytes) || limits.maxUploadBytes;
                limits.maxEditBytes = Number(result.value.data.maxEditBytes) || limits.maxEditBytes;
            }
        } catch (error) {
        }
    }

    function openDialog(title, bodyElement, okLabel, danger, onSubmit) {
        if (!dialog || !dialogTitle || !dialogBody || !dialogOk || !dialogCancel) return;
        dialogTitle.textContent = title;
        dialogBody.replaceChildren(bodyElement);
        dialogOk.textContent = okLabel || '确定';
        dialogOk.classList.toggle('danger', !!danger);
        dialogOk.disabled = false;
        dialog.hidden = false;
        var finished = false;
        function closeDialog() {
            if (finished) return;
            finished = true;
            dialog.hidden = true;
            dialogOk.removeEventListener('click', onOk);
            dialogCancel.removeEventListener('click', onCancel);
        }
        function onOk() {
            if (finished || dialogOk.disabled) return;
            var value = onSubmit();
            if (value === false) return;
            closeDialog();
        }
        function onCancel() {
            closeDialog();
        }
        dialogOk.addEventListener('click', onOk);
        dialogCancel.addEventListener('click', onCancel);
    }

    function makeInputDialog(value, placeholder) {
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'terminal-file-dialog-input';
        input.value = value;
        input.placeholder = placeholder || '';
        input.maxLength = 200;
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && dialogOk && !dialogOk.disabled) {
                dialogOk.click();
            }
        });
        window.setTimeout(function () {
            if (!destroyed && !dialog.hidden) {
                input.focus();
                input.select();
            }
        }, 50);
        return input;
    }

    function validName(name) {
        name = String(name || '').trim();
        if (!name) return { error: '名称不能为空。' };
        if (name.length > 200) return { error: '名称过长。' };
        if (/[<>:"|?*;\\/\x00-\x1F\x7F]/.test(name)) return { error: '名称包含非法字符。' };
        if (name === '.' || name === '..') return { error: '名称无效。' };
        return { name: name };
    }

    var BINARY_EXTS = /\.(jar|zip|gz|7z|rar|tar|png|jpe?g|gif|webp|bmp|ico|exe|dll|bin|dat|class|mp3|mp4|woff2?|ttf|otf|pack)$/i;

    async function openEdit(name) {
        var uuid = requireInstance();
        if (!uuid) return;
        setStatus('正在读取文件…');
        var result;
        try {
            result = await fetchApi({ action: 'fileRead', instanceUuid: uuid, path: joinPath(name) });
        } catch (error) {
            if (error.name === 'AbortError') return;
            setStatus(error.message || '读取失败', true);
            return;
        }
        if (!result.ok) {
            setStatus(result.value.error || '读取失败', true);
            return;
        }
        var text = String(result.value.data.text || '');
        var size = Number(result.value.data.size) || 0;
        if (size > 1048576) {
            setStatus('文件较大（' + formatSize(size) + '），编辑时可能卡顿。');
        }
        var textarea = document.createElement('textarea');
        textarea.className = 'terminal-file-dialog-textarea';
        textarea.setAttribute('data-lenis-prevent', '');
        textarea.value = text;
        textarea.spellcheck = false;
        textarea.wrap = 'off';
        var wrapper = document.createElement('div');
        if (BINARY_EXTS.test(name)) {
            var binaryHint = document.createElement('p');
            binaryHint.className = 'terminal-file-dialog-text';
            binaryHint.textContent = '该文件可能是二进制文件，直接编辑保存可能损坏内容，建议改用下载/上传方式修改。';
            wrapper.appendChild(binaryHint);
        }
        wrapper.appendChild(textarea);
        openDialog('编辑 ' + name, wrapper, '保存', false, function () {
            saveEdit(name, textarea.value);
            return true;
        });
    }

    async function saveEdit(name, text) {
        var uuid = selectedInstanceUuid();
        if (!uuid) return;
        setStatus('正在保存文件…');
        try {
            var result = await fetchApi({
                action: 'fileWrite',
                instanceUuid: uuid,
                path: joinPath(name),
                text: text
            });
            if (!result.ok) throw new Error(result.value.error || '保存失败');
            setStatus('已保存 ' + name);
        } catch (error) {
            if (error.name === 'AbortError') return;
            setStatus(error.message || '保存失败', true);
        }
    }

    function openRename(name, isDir) {
        var input = makeInputDialog(name, '新的' + (isDir ? '目录' : '文件') + '名');
        openDialog('重命名 ' + name, input, '重命名', false, function () {
            var checked = validName(input.value);
            if (checked.error) {
                setStatus(checked.error, true);
                return false;
            }
            if (checked.name === name) return true;
            doRename(name, checked.name);
            return true;
        });
    }

    async function doRename(oldName, newName) {
        var uuid = selectedInstanceUuid();
        if (!uuid) return;
        setStatus('正在重命名…');
        try {
            var result = await fetchApi({
                action: 'fileMove',
                instanceUuid: uuid,
                source: joinPath(oldName),
                destination: joinPath(newName)
            });
            if (!result.ok) throw new Error(result.value.error || '重命名失败');
            setStatus('已重命名为 ' + newName);
            loadList();
        } catch (error) {
            if (error.name === 'AbortError') return;
            setStatus(error.message || '重命名失败', true);
        }
    }

    function openCreate(kind) {
        var isDir = kind === 'dir';
        var input = makeInputDialog('', isDir ? '新目录名' : '新文件名');
        openDialog(isDir ? '新建目录' : '新建文件', input, '创建', false, function () {
            var checked = validName(input.value);
            if (checked.error) {
                setStatus(checked.error, true);
                return false;
            }
            doCreate(checked.name, isDir);
            return true;
        });
    }

    async function doCreate(name, isDir) {
        var uuid = selectedInstanceUuid();
        if (!uuid) return;
        setStatus('正在创建…');
        try {
            var result = await fetchApi({
                action: isDir ? 'fileMkdir' : 'fileTouch',
                instanceUuid: uuid,
                path: joinPath(name)
            });
            if (!result.ok) throw new Error(result.value.error || '创建失败');
            setStatus('已创建 ' + name);
            loadList();
        } catch (error) {
            if (error.name === 'AbortError') return;
            setStatus(error.message || '创建失败', true);
        }
    }

    function openDelete(name, isDir) {
        var label = isDir ? '目录' : '文件';
        var paragraph = document.createElement('p');
        paragraph.className = 'terminal-file-dialog-text';
        paragraph.textContent = '确认删除' + label + '「' + name + '」吗？' + (isDir ? ' 目录内所有内容都将被删除，且无法恢复。' : ' 删除后无法恢复。');
        openDialog('删除确认', paragraph, '删除', true, function () {
            doDelete(name);
            return true;
        });
    }

    async function doDelete(name) {
        var uuid = selectedInstanceUuid();
        if (!uuid) return;
        setStatus('正在删除…');
        try {
            var result = await fetchApi({
                action: 'fileDelete',
                instanceUuid: uuid,
                paths: [joinPath(name)]
            });
            if (!result.ok) throw new Error(result.value.error || '删除失败');
            setStatus('已删除 ' + name);
            loadList();
        } catch (error) {
            if (error.name === 'AbortError') return;
            setStatus(error.message || '删除失败', true);
        }
    }

    async function downloadFile(name) {
        var uuid = requireInstance();
        if (!uuid) return;
        setStatus('正在生成下载链接…');
        var result;
        try {
            result = await fetchApi({ action: 'fileDownload', instanceUuid: uuid, path: joinPath(name) });
        } catch (error) {
            if (error.name === 'AbortError') return;
            setStatus(error.message || '下载失败', true);
            return;
        }
        if (!result.ok || !result.value.data || !result.value.data.url) {
            setStatus(result.value.error || '下载失败', true);
            return;
        }
        var link = document.createElement('a');
        link.href = result.value.data.url;
        link.download = name;
        link.rel = 'noopener';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setStatus('已开始下载 ' + name + '（数据流直连节点，不经过站点服务器）');
    }

    function uploadViaXhr(url, file) {
        return new Promise(function (resolve, reject) {
            var form = new FormData();
            form.append('file', file, file.name);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', url + '?overwrite=false', true);
            xhr.upload.addEventListener('progress', function (event) {
                if (event.lengthComputable) {
                    var percent = Math.round(event.loaded / event.total * 100);
                    setStatus('正在上传 ' + file.name + '（' + percent + '%）');
                }
            });
            xhr.addEventListener('load', function () {
                uploadInput.value = '';
                if (xhr.status === 200) {
                    resolve();
                } else {
                    reject(new Error('节点返回错误（HTTP ' + xhr.status + '）'));
                }
            });
            xhr.addEventListener('error', function () {
                uploadInput.value = '';
                reject(new Error('xhr-blocked'));
            });
            xhr.addEventListener('abort', function () {
                uploadInput.value = '';
                reject(new Error('上传已取消。'));
            });
            xhr.send(form);
        });
    }

    function uploadViaForm(url, file) {
        return new Promise(function (resolve) {
            var originalParent = uploadInput.parentNode;
            var frameName = 'mcfileupload-' + Date.now();
            var iframe = document.createElement('iframe');
            iframe.name = frameName;
            iframe.hidden = true;
            document.body.appendChild(iframe);
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = url + '?overwrite=false';
            form.enctype = 'multipart/form-data';
            form.target = frameName;
            form.style.display = 'none';
            form.appendChild(uploadInput);
            document.body.appendChild(form);
            form.submit();
            window.setTimeout(function () {
                uploadInput.value = '';
                form.remove();
                if (uploadInput.parentNode !== originalParent && originalParent) {
                    originalParent.appendChild(uploadInput);
                }
                iframe.remove();
                resolve();
            }, 2500);
        });
    }

    function uploadFile(file) {
        var uuid = requireInstance();
        if (!uuid || uploadBusy) return;
        uploadBusy = true;
        setStatus('正在准备上传…');
        fetchApi({ action: 'fileUpload', instanceUuid: uuid, path: currentPath() })
            .then(function (result) {
                if (!result.ok || !result.value.data || !result.value.data.url) {
                    throw new Error(result.value.error || '上传准备失败');
                }
                return result.value.data.url;
            })
            .then(function (url) {
                return uploadViaXhr(url, file).catch(function (error) {
                    if (error && error.message === 'xhr-blocked') {
                        setStatus('直连上传被浏览器拦截（混合内容），改用表单方式上传…');
                        return uploadViaForm(url, file);
                    }
                    throw error;
                });
            })
            .then(function () {
                setStatus('上传已提交：' + file.name + '（直连节点，未经过站点服务器）');
                loadList();
            })
            .catch(function (error) {
                if (error.name === 'AbortError') return;
                setStatus(error.message || '上传失败', true);
            })
            .finally(function () {
                if (uploadInput) uploadInput.value = '';
                uploadBusy = false;
            });
    }

    function onInstanceSelected() {
        loadList();
    }

    function onTerminalReady() {
        loadList();
    }

    function destroy() {
        destroyed = true;
        controller.abort();
        window.removeEventListener('site:before-swap', destroy);
        window.removeEventListener('terminal:instance-selected', onInstanceSelected);
        window.removeEventListener('terminal:ready', onTerminalReady);
        window.__mcFileManager = null;
    }

    if (uploadInput && uploadButton) {
        uploadButton.addEventListener('click', function () {
            if (!requireInstance()) return;
            uploadInput.click();
        });
        uploadInput.addEventListener('change', function () {
            var file = uploadInput.files && uploadInput.files[0];
            if (!file) return;
            if (file.size > limits.maxUploadBytes) {
                uploadInput.value = '';
                setStatus('文件超过单次上传上限（' + formatSize(limits.maxUploadBytes) + '）。', true);
                return;
            }
            uploadFile(file);
        });
    }
    if (mkdirButton) {
        mkdirButton.addEventListener('click', function () {
            if (requireInstance()) openCreate('dir');
        });
    }
    if (touchButton) {
        touchButton.addEventListener('click', function () {
            if (requireInstance()) openCreate('file');
        });
    }
    if (refreshButton) {
        refreshButton.addEventListener('click', loadList);
    }
    if (filesTable) {
        filesTable.addEventListener('wheel', function (event) {
            var atTop = filesTable.scrollTop <= 0 && event.deltaY < 0;
            var atBottom = filesTable.scrollTop + filesTable.clientHeight >= filesTable.scrollHeight - 1 && event.deltaY > 0;
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
    if (dialog) {
        dialog.addEventListener('wheel', function (event) {
            var scrollTarget = event.target.closest('.terminal-file-dialog-textarea, .terminal-file-dialog-panel');
            if (!scrollTarget) {
                event.preventDefault();
                event.stopPropagation();
                return;
            }
            event.stopPropagation();
            var canScrollUp = scrollTarget.scrollTop > 0;
            var canScrollDown = scrollTarget.scrollTop + scrollTarget.clientHeight < scrollTarget.scrollHeight - 1;
            if ((event.deltaY < 0 && !canScrollUp) || (event.deltaY > 0 && !canScrollDown)) {
                event.preventDefault();
            }
        }, { passive: false, capture: true });
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog && dialogCancel) {
                dialogCancel.click();
            }
        });
    }

    window.addEventListener('site:before-swap', destroy);
    window.addEventListener('terminal:instance-selected', onInstanceSelected);
    window.addEventListener('terminal:ready', onTerminalReady);
    if (window.__mcTerminalShared && typeof window.__mcTerminalShared.whenReady === 'function') {
        window.__mcTerminalShared.whenReady(onTerminalReady);
    }
    window.__mcFileManager = { destroy: destroy };

    loadLimits();
    if (selectedInstanceUuid()) loadList();
    else setStatus('正在等待终端实例列表…');
}());
