/**
 * IRO Editor Image Paste
 *
 * 在 Githuber MD（CodeMirror）编辑器里粘贴或拖入图片时：
 * 1. 先在光标处插入一个唯一的占位符；
 * 2. 立即上传到 WordPress 媒体库；
 * 3. 上传成功后把占位符替换成 `![alt](url)`。
 *
 * 该脚本使用捕获阶段监听，因此即使 Githuber MD 自带的 Image Paste 也开着，
 * 也不会重复上传。
 */
(function () {
    'use strict';

    var config = window.iroImagePaste;

    if (!config || !config.ajaxUrl) {
        return;
    }

    var state = {
        cm: null,
        wrapper: null,
        attached: false,
        counter: 0,
        dragDepth: 0,
        overlay: null
    };

    function init() {
        waitForEditor(0);
    }

    function waitForEditor(attempt) {
        var editor = window.githuber_md_editor;
        var cm = editor && (editor.cm || editor.codeEditor);

        if (cm && cm.getWrapperElement && cm.getDoc) {
            attach(cm);
            return;
        }

        // 最多等待 60 秒，避免脚本在编辑器初始化前就放弃。
        if (attempt < 600) {
            window.setTimeout(function () {
                waitForEditor(attempt + 1);
            }, 100);
        }
    }

    function attach(cm) {
        var wrapper = cm.getWrapperElement();

        if (state.attached && state.cm === cm && state.wrapper === wrapper) {
            return;
        }

        state.cm = cm;
        state.wrapper = wrapper;
        state.attached = true;
        state.dragDepth = 0;

        wrapper.classList.add('iro-image-paste-ready');
        createDropOverlay(wrapper);

        wrapper.addEventListener('paste', onPaste, true);
        wrapper.addEventListener('dragenter', onDragEnter, true);
        wrapper.addEventListener('dragover', onDragOver, true);
        wrapper.addEventListener('dragleave', onDragLeave, true);
        wrapper.addEventListener('drop', onDrop, true);

        window.addEventListener('dragend', clearDropState);
        window.addEventListener('blur', clearDropState);
    }

    /* ---------------------------------------------------------------------
     * 粘贴 / 拖放
     * ------------------------------------------------------------------ */

    function onPaste(event) {
        var files = getImageFiles(event.clipboardData);

        if (!files.length) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        handleFiles(files);
    }

    function onDragEnter(event) {
        if (!hasDraggedFiles(event.dataTransfer)) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        state.dragDepth += 1;
        setDropState(true);
    }

    function onDragOver(event) {
        if (!hasDraggedFiles(event.dataTransfer)) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'copy';
        }

        setDropState(true);
    }

    function onDragLeave() {
        // dragleave 时部分浏览器已清空 dataTransfer.types，不能再依赖 hasDraggedFiles，
        // 否则拖出编辑器后提示层会一直留在屏幕上。
        if (!state.wrapper || !state.wrapper.classList.contains('iro-image-paste-dragging')) {
            return;
        }

        state.dragDepth = Math.max(0, state.dragDepth - 1);

        if (state.dragDepth === 0) {
            setDropState(false);
        }
    }

    function onDrop(event) {
        if (!hasDraggedFiles(event.dataTransfer)) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        var files = getImageFiles(event.dataTransfer);
        clearDropState();

        if (files.length) {
            handleFiles(files);
        } else {
            // 拖入 PDF、压缩包等非图片文件时给出明确提示，
            // 同时阻止浏览器直接打开这个文件。
            showToast(config.strings.notImage, 'error', 4000);
        }
    }

    function hasDraggedFiles(dataTransfer) {
        if (!dataTransfer) {
            return false;
        }

        if (dataTransfer.types) {
            for (var i = 0; i < dataTransfer.types.length; i++) {
                if (dataTransfer.types[i] === 'Files') {
                    return true;
                }
            }
        }

        return !!(dataTransfer.files && dataTransfer.files.length);
    }

    /**
     * 从 ClipboardEvent/DataTransfer 中提取图片文件。
     *
     * @param {DataTransfer|null} dataTransfer
     * @return {File[]}
     */
    function getImageFiles(dataTransfer) {
        var files = [];
        var i;

        if (!dataTransfer) {
            return files;
        }

        if (dataTransfer.items && dataTransfer.items.length) {
            for (i = 0; i < dataTransfer.items.length; i++) {
                var item = dataTransfer.items[i];

                if (item && item.kind === 'file') {
                    var itemFile = item.getAsFile();

                    // 有些浏览器给出的 item.type 为空，用 File 自身的类型或扩展名兜底。
                    if (itemFile && looksLikeImage(itemFile.type, itemFile.name)) {
                        files.push(itemFile);
                    }
                }
            }
        }

        if (!files.length && dataTransfer.files && dataTransfer.files.length) {
            for (i = 0; i < dataTransfer.files.length; i++) {
                var file = dataTransfer.files[i];

                if (file && looksLikeImage(file.type, file.name)) {
                    files.push(file);
                }
            }
        }

        return files;
    }

    function looksLikeImage(type, name) {
        if (typeof type === 'string' && type.indexOf('image/') === 0) {
            return true;
        }

        // 少数浏览器从剪贴板给出的 File.type 为空，此时用扩展名兜底。
        return /\.(png|jpe?g|gif|webp|avif)$/i.test(String(name || ''));
    }

    /* ---------------------------------------------------------------------
     * 上传与插入
     * ------------------------------------------------------------------ */

    function handleFiles(files) {
        var images = files.slice(0, 10);
        var tokens = [];

        images.forEach(function () {
            // 用纯文本占位符而不是图片语法：上传期间实时预览不会去请求一个
            // 不存在的图片地址，也不会出现破图图标。
            tokens.push('⟦图片上传中… #' + uniqueId() + '⟧');
        });

        if (!tokens.length) {
            return;
        }

        // 先插入占位符，再开始上传：上传前的同步校验（例如文件过大）
        // 才能正确地把对应的占位符删掉。
        insertAtCursor(tokens.join('\n'));

        images.forEach(function (file, index) {
            upload(file, tokens[index]);
        });
    }

    function insertAtCursor(text) {
        var cm = state.cm;
        var doc = cm.getDoc();
        var from = doc.getCursor('from');
        var to = doc.getCursor('to');
        var line = doc.getLine(from.line) || '';

        // 在已有文字的行尾粘贴时，让图片单独占一行，避免和正文粘在一起。
        if (from.ch === line.length && line.trim() !== '') {
            text = '\n' + text + '\n';
        }

        cm.operation(function () {
            doc.replaceRange(text, from, to);
        });

        doc.setCursor(doc.posFromIndex(doc.indexFromPos(from) + text.length));
        cm.focus();
    }

    function upload(file, token) {
        if (config.maxSize && file.size > config.maxSize) {
            removeToken(token);
            showToast(formatString(config.strings.tooLarge, config.maxSizeLabel), 'error', 6000);
            return;
        }

        var toast = showToast(config.strings.uploading, 'progress', 0);
        var xhr = new XMLHttpRequest();
        var formData = new FormData();

        formData.append('action', 'iro_paste_image');
        formData.append('nonce', config.nonce);
        formData.append('post_id', String(config.postId || 0));
        formData.append('file', file, uploadFilename(file));

        xhr.open('POST', config.ajaxUrl, true);
        xhr.timeout = 120000;
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        if (xhr.upload) {
            xhr.upload.onprogress = function (event) {
                if (event.lengthComputable && event.total > 0) {
                    var percent = Math.min(99, Math.round((event.loaded / event.total) * 100));

                    toast.update(formatString(config.strings.uploadingProgress, percent));
                }
            };
        }

        xhr.onload = function () {
            var response = parseJson(xhr.responseText);

            if (xhr.status >= 200 && xhr.status < 300 && response && response.success && response.data && response.data.url) {
                var markdown = buildMarkdown(response.data, file);
                var inserted = replaceToken(token, markdown);

                toast.done(inserted ? config.strings.inserted : config.strings.insertedButMoved, 'success');
                return;
            }

            removeToken(token);

            var message = config.strings.uploadFailed;

            if (response && response.data) {
                if (typeof response.data === 'string') {
                    message = response.data;
                } else if (response.data.message) {
                    message = response.data.message;
                }
            } else if (xhr.status === 0) {
                message = config.strings.networkError;
            } else if (!response) {
                message = config.strings.serverError;
            }

            toast.fail(message);

            if (!response) {
                console.error('[IRO image paste] Unexpected response:', xhr.status, xhr.responseText);
            }
        };

        xhr.onerror = function () {
            removeToken(token);
            toast.fail(config.strings.networkError);
        };

        xhr.ontimeout = function () {
            removeToken(token);
            toast.fail(config.strings.networkError);
        };

        xhr.onabort = function () {
            removeToken(token);
            toast.fail(config.strings.uploadFailed);
        };

        xhr.send(formData);
    }

    function uploadFilename(file) {
        var name = typeof file.name === 'string' ? file.name : '';

        if (!/\.[a-z0-9]{2,5}$/i.test(name)) {
            name = 'pasted-' + timestamp() + '.' + extensionForMime(file.type);
        }

        return name;
    }

    function timestamp() {
        var date = new Date();
        var pad = function (value) {
            return String(value).padStart(2, '0');
        };

        return date.getFullYear() +
            pad(date.getMonth() + 1) +
            pad(date.getDate()) + '-' +
            pad(date.getHours()) +
            pad(date.getMinutes()) +
            pad(date.getSeconds());
    }

    function extensionForMime(mime) {
        switch (String(mime || '').toLowerCase()) {
            case 'image/jpeg':
                return 'jpg';
            case 'image/gif':
                return 'gif';
            case 'image/webp':
                return 'webp';
            case 'image/avif':
                return 'avif';
            case 'image/png':
            default:
                return 'png';
        }
    }

    function buildMarkdown(data, file) {
        var alt = String(data.alt || altFromFilename(file.name) || '图片');
        var url = String(data.url || '');

        alt = alt.replace(/[\r\n]+/g, ' ').replace(/([\\\[\]])/g, '\\$1').trim();

        if (!alt) {
            alt = '图片';
        }

        // URL 中有空格或括号时用尖括号包起来，避免破坏 Markdown 语法。
        if (/[\s()<>]/.test(url)) {
            url = '<' + url.replace(/</g, '%3C').replace(/>/g, '%3E') + '>';
        }

        return '![' + alt + '](' + url + ')';
    }

    function altFromFilename(name) {
        var base = String(name || '').replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ').trim();

        if (!base || /^(image|blob|clipboard|screenshot|pasted|untitled)$/i.test(base)) {
            return '';
        }

        return base;
    }

    /**
     * 用最终 Markdown 替换占位符。
     *
     * @param {string} token
     * @param {string} markdown
     * @return {boolean} 是否找到了占位符。
     */
    function replaceToken(token, markdown) {
        var doc = state.cm.getDoc();
        var value = doc.getValue();
        var index = value.indexOf(token);

        if (index === -1) {
            // 用户可能已经删掉了占位符；退回到当前光标处插入。
            doc.replaceRange(markdown, doc.getCursor());
            return false;
        }

        var from = doc.posFromIndex(index);
        var to = doc.posFromIndex(index + token.length);

        state.cm.operation(function () {
            doc.replaceRange(markdown, from, to);
        });

        return true;
    }

    function removeToken(token) {
        var doc = state.cm.getDoc();
        var value = doc.getValue();
        var index = value.indexOf(token);

        if (index === -1) {
            return;
        }

        var from = doc.posFromIndex(index);
        var to = doc.posFromIndex(index + token.length);

        state.cm.operation(function () {
            doc.replaceRange('', from, to);
        });
    }

    function uniqueId() {
        state.counter += 1;

        return Date.now().toString(36) + '-' + state.counter.toString(36) + '-' + Math.random().toString(36).slice(2, 7);
    }

    function parseJson(text) {
        if (!text) {
            return null;
        }

        try {
            return JSON.parse(text);
        } catch (error) {
            return null;
        }
    }

    function formatString(template, value) {
        return String(template || '').replace('%d', String(value)).replace('%s', String(value));
    }

    /* ---------------------------------------------------------------------
     * 拖放提示层
     * ------------------------------------------------------------------ */

    function createDropOverlay(wrapper) {
        var overlay = document.createElement('div');

        overlay.className = 'iro-image-paste-drop';
        overlay.setAttribute('aria-hidden', 'true');
        overlay.innerHTML =
            '<div class="iro-image-paste-drop__inner">' +
                '<span class="iro-image-paste-drop__icon" aria-hidden="true">' +
                    '<svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' +
                        '<path d="M12 16V4"></path>' +
                        '<path d="M7 9l5-5 5 5"></path>' +
                        '<path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"></path>' +
                    '</svg>' +
                '</span>' +
                '<strong></strong>' +
                '<small></small>' +
            '</div>';

        overlay.querySelector('strong').textContent = config.strings.dropHint;
        overlay.querySelector('small').textContent = config.strings.dropSubHint;

        wrapper.appendChild(overlay);
        state.overlay = overlay;
    }

    function setDropState(active) {
        if (!state.wrapper) {
            return;
        }

        state.wrapper.classList.toggle('iro-image-paste-dragging', active);

        if (state.overlay) {
            state.overlay.setAttribute('aria-hidden', active ? 'false' : 'true');
        }
    }

    function clearDropState() {
        state.dragDepth = 0;
        setDropState(false);
    }

    /* ---------------------------------------------------------------------
     * 轻量 Toast
     * ------------------------------------------------------------------ */

    function toastContainer() {
        var container = document.getElementById('iro-image-paste-toasts');

        if (container) {
            return container;
        }

        container = document.createElement('div');
        container.id = 'iro-image-paste-toasts';
        container.setAttribute('aria-live', 'polite');
        document.body.appendChild(container);

        return container;
    }

    function showToast(message, type, timeout) {
        var container = toastContainer();
        var element = document.createElement('div');
        var icon = document.createElement('span');
        var text = document.createElement('span');
        var dismissTimer = null;

        element.className = 'iro-image-paste-toast is-' + type;
        element.setAttribute('role', type === 'error' ? 'alert' : 'status');

        icon.className = 'iro-image-paste-toast__icon';
        icon.setAttribute('aria-hidden', 'true');

        text.className = 'iro-image-paste-toast__text';
        text.textContent = message;

        if (type === 'success') {
            icon.innerHTML = '<svg viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10.5l4 4 8-8"></path></svg>';
        } else if (type === 'error') {
            icon.innerHTML = '<svg viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l8 8M14 6l-8 8"></path></svg>';
        }

        element.appendChild(icon);
        element.appendChild(text);
        container.appendChild(element);

        // 最多同时显示 4 条，避免连续粘贴时铺满屏幕。
        while (container.children.length > 4) {
            container.removeChild(container.firstChild);
        }

        window.requestAnimationFrame(function () {
            element.classList.add('is-visible');
        });

        var dismissAfter = function (delay) {
            window.clearTimeout(dismissTimer);
            dismissTimer = window.setTimeout(function () {
                element.classList.remove('is-visible');

                window.setTimeout(function () {
                    if (element.parentNode) {
                        element.parentNode.removeChild(element);
                    }
                }, 220);
            }, delay);
        };

        var toast = {
            update: function (nextMessage) {
                text.textContent = nextMessage;
            },
            done: function (nextMessage, nextType) {
                element.classList.remove('is-progress');
                element.classList.add('is-' + (nextType || 'success'));
                text.textContent = nextMessage;
                dismissAfter(2500);
            },
            fail: function (nextMessage) {
                element.classList.remove('is-progress');
                element.classList.add('is-error');
                text.textContent = nextMessage;
                dismissAfter(7000);
            }
        };

        if (timeout > 0) {
            dismissAfter(timeout);
        }

        return toast;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
