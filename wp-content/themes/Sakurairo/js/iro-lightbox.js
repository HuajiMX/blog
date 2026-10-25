/**
 * 文章图片灯箱
 *
 * 通过事件委托工作，兼容主题的 PJAX 页面切换，不依赖任何第三方库。
 * 仅在主题内置 lightbox 关闭时启用，避免和 baguetteBox / fancybox 重复触发。
 */
(function () {
  'use strict';

  var config = window._iro || {};
  if (config.baguetteBox || config.fancybox || config.lightGallery) return;

  var LABELS = {
    viewer: '图片查看器',
    close: '关闭图片灯箱',
    prev: '上一张',
    next: '下一张',
    view: '查看大图',
    error: '图片加载失败',
    zoomHint: '拖动查看细节，再次点击还原'
  };

  var overlay = null;
  var frame = null;
  var image = null;
  var caption = null;
  var counter = null;
  var error = null;
  var prevBtn = null;
  var nextBtn = null;
  var closeBtn = null;
  var gallery = [];
  var index = 0;
  var loadToken = 0;
  var lastFocus = null;
  var closeTimer = 0;
  var suppressTimer = 0;
  var resizeTimer = 0;
  var isOpen = false;
  var zoomed = false;
  var panX = 0;
  var panY = 0;
  var drag = null;
  var suppressClick = false;
  var touchStart = null;
  var preloaded = Object.create(null);

  function isPlaceholder(src) {
    if (!src) return true;
    if (/^(about:blank|#|javascript:|vbscript:)/i.test(src)) return true;
    if (/(puff-load|lazyload-blur|loading_ph|spacer|blank\.(gif|png)|transparent\.gif)/i.test(src)) {
      return true;
    }
    if (/^data:image\/svg\+xml/i.test(src) && /(spinner|loader|placeholder|loading)/i.test(src)) {
      return true;
    }
    return false;
  }

  function getSource(img) {
    var attrs = [
      'data-src',
      'data-original',
      'data-lazy-src',
      'data-full-src',
      'data-large-file',
      'data-highres'
    ];

    for (var i = 0; i < attrs.length; i++) {
      var value = img.getAttribute(attrs[i]);
      if (value && !isPlaceholder(value)) return value;
    }

    var src = img.currentSrc || img.getAttribute('src') || '';
    if (src && !isPlaceholder(src)) return src;
    return '';
  }

  function isImageHref(href) {
    if (!href) return true;
    href = href.trim();
    if (!href || href.charAt(0) === '#' || /^javascript:/i.test(href)) return true;
    if (/^data:image\//i.test(href)) return true;

    try {
      var url = new URL(href, window.location.href);
      if (/\.(png|jpe?g|gif|webp|avif|bmp|svg)$/i.test(url.pathname)) return true;
    } catch (e) {
      // 非标准 URL 交给浏览器处理
    }
    return false;
  }

  function isCandidate(img) {
    if (!img || img.tagName !== 'IMG') return false;

    if (img.closest('.iro-lightbox, noscript, .smilie, .wp-smiley, .comment, .comments, .post-meta, .site-footer, .ai-excerpt, button, .bookmark-card, .blogcard, .wp-block-embed, .wp-block-bookmark, .ghcard, .steam-profile, .bilibili-favlist, .video-modal')) {
      return false;
    }

    var classes = img.className || '';
    if (/(^|\s)(no-lightbox|smilie-emoji-in-content|wp-smiley|emoji|avatar|gravatar|emoji-item)(\s|$)/i.test(classes)) {
      return false;
    }

    if (img.hasAttribute('data-no-lightbox') || img.hasAttribute('usemap')) return false;
    if (img.getAttribute('aria-hidden') === 'true') return false;

    var link = img.closest('a');
    if (link) {
      var allow = isImageHref(link.getAttribute('href') || '');
      if (!allow) {
        allow =
          link.hasAttribute('data-fancybox') ||
          img.classList.contains('fancybox') ||
          /(^|\s)(fancybox|lightbox|gallery|gallery-item|wp-block-image)(\s|$)/i.test(link.className || '');
      }
      if (!allow) return false;
    }

    return !!getSource(img);
  }

  function isVisibleEnough(img) {
    var rect = img.getBoundingClientRect();
    if (!rect.width || !rect.height) return false;
    if (rect.width < 48 || rect.height < 24) return false;
    return rect.width * rect.height >= 3072;
  }

  function isEligible(img) {
    return isCandidate(img) && isVisibleEnough(img);
  }

  function captionFor(img) {
    var figure = img.closest('figure');
    var captionNode = figure ? figure.querySelector('figcaption') : null;
    var text = captionNode
      ? captionNode.textContent
      : img.getAttribute('data-caption') || img.getAttribute('alt') || img.getAttribute('title') || '';

    return String(text || '').replace(/\s+/g, ' ').trim();
  }

  function collect(scope) {
    var nodes = scope.querySelectorAll('img');
    var list = [];

    for (var i = 0; i < nodes.length; i++) {
      if (isEligible(nodes[i])) list.push(nodes[i]);
    }
    return list;
  }

  function preloadNeighbors() {
    if (gallery.length < 2) return;

    for (var i = -1; i <= 1; i += 2) {
      var next = (index + i + gallery.length) % gallery.length;
      var src = getSource(gallery[next]);
      if (!src || preloaded[src]) continue;

      preloaded[src] = true;
      var preload = new Image();
      preload.decoding = 'async';
      preload.src = src;
    }
  }

  function handleLoad(token) {
    if (token !== loadToken || !frame || !image) return;
    frame.classList.remove('is-loading');
    image.classList.add('is-ready');
    preloadNeighbors();
  }

  function handleError(token) {
    if (token !== loadToken || !frame || !error) return;
    frame.classList.remove('is-loading');
    frame.classList.add('is-error');
    error.hidden = false;
  }

  function show(next) {
    if (!gallery.length) return;

    index = ((next % gallery.length) + gallery.length) % gallery.length;

    var current = gallery[index];
    var source = getSource(current);
    var text = captionFor(current);
    var token = ++loadToken;
    var single = gallery.length < 2;

    resetZoom();

    overlay.classList.toggle('is-single', single);
    prevBtn.disabled = single;
    nextBtn.disabled = single;
    counter.textContent = index + 1 + ' / ' + gallery.length;
    caption.textContent = text;
    image.alt = text || '图片';

    image.classList.remove('is-ready', 'is-error');
    frame.classList.remove('is-error');
    error.hidden = true;

    if (!source) {
      frame.classList.remove('is-loading');
      frame.classList.add('is-error');
      error.hidden = false;
      return;
    }

    frame.classList.add('is-loading');
    image.onload = function () {
      handleLoad(token);
    };
    image.onerror = function () {
      handleError(token);
    };
    image.src = source;

    // 缓存图片可能不会再次触发 load
    if (image.complete && image.naturalWidth) handleLoad(token);
  }

  function go(delta) {
    if (!gallery.length || gallery.length < 2) return;
    show(index + delta);
  }

  function resetZoom() {
    zoomed = false;
    panX = 0;
    panY = 0;
    drag = null;

    if (!image) return;
    image.classList.remove('is-zoomed', 'is-dragging');
    image.style.setProperty('--iro-lb-zoom', '1');
    image.style.setProperty('--iro-lb-pan-x', '0px');
    image.style.setProperty('--iro-lb-pan-y', '0px');
    image.style.transformOrigin = '50% 50%';
    image.removeAttribute('title');
  }

  function applyPan() {
    if (!image) return;
    image.style.setProperty('--iro-lb-pan-x', panX + 'px');
    image.style.setProperty('--iro-lb-pan-y', panY + 'px');
  }

  function toggleZoom(event) {
    if (!image || !image.naturalWidth || frame.classList.contains('is-loading')) return;

    if (zoomed) {
      resetZoom();
      return;
    }

    var rect = image.getBoundingClientRect();
    if (!rect.width || !rect.height) return;

    var ratio = image.naturalWidth / rect.width;
    var zoom = Math.min(3.2, Math.max(1.8, ratio));
    var clientX = event && typeof event.clientX === 'number'
      ? event.clientX
      : rect.left + rect.width / 2;
    var clientY = event && typeof event.clientY === 'number'
      ? event.clientY
      : rect.top + rect.height / 2;
    var originX = Math.max(0, Math.min(100, ((clientX - rect.left) / rect.width) * 100));
    var originY = Math.max(0, Math.min(100, ((clientY - rect.top) / rect.height) * 100));

    image.style.transformOrigin = originX + '% ' + originY + '%';
    image.style.setProperty('--iro-lb-zoom', zoom.toFixed(3));
    image.classList.add('is-zoomed');
    image.setAttribute('title', LABELS.zoomHint);
    zoomed = true;
  }

  function suppressNextClick() {
    suppressClick = true;
    window.clearTimeout(suppressTimer);
    suppressTimer = window.setTimeout(function () {
      suppressClick = false;
    }, 300);
  }

  function lockScroll() {
    var gutter = window.innerWidth - document.documentElement.clientWidth;
    if (gutter > 0) {
      document.documentElement.style.setProperty('--iro-lightbox-gutter', gutter + 'px');
    }
    document.documentElement.classList.add('iro-lightbox-open');
  }

  function unlockScroll() {
    document.documentElement.classList.remove('iro-lightbox-open');
    document.documentElement.style.removeProperty('--iro-lightbox-gutter');
  }

  function restoreFocus() {
    var target = lastFocus;
    lastFocus = null;
    if (!target || typeof target.focus !== 'function') return;

    try {
      target.focus({ preventScroll: true });
    } catch (e) {
      try {
        target.focus();
      } catch (ignore) {
        // 元素可能已随 PJAX 被移除
      }
    }
  }

  function finishClose() {
    if (!overlay) return;

    overlay.hidden = true;
    loadToken++;
    image.onload = null;
    image.onerror = null;
    image.removeAttribute('src');
    image.classList.remove('is-ready', 'is-loading', 'is-error');
    frame.classList.remove('is-loading', 'is-error');
    error.hidden = true;
    caption.textContent = '';
    counter.textContent = '';
    overlay.classList.remove('is-single');
  }

  function close(immediate) {
    if (!isOpen || !overlay) return;

    isOpen = false;
    window.clearTimeout(closeTimer);
    overlay.classList.remove('is-open');
    unlockScroll();
    resetZoom();
    restoreFocus();

    if (immediate) {
      finishClose();
    } else {
      closeTimer = window.setTimeout(finishClose, 280);
    }
  }

  function open(img) {
    if (!img || !img.closest('.entry-content') || !isEligible(img)) return;

    var scope = img.closest('.entry-content');
    gallery = collect(scope);
    if (gallery.indexOf(img) === -1) gallery = [img];
    if (!gallery.length) return;

    index = gallery.indexOf(img);
    if (index < 0) index = 0;

    ensureDom();
    isOpen = true;
    var active = document.activeElement;
    lastFocus = active && active !== document.body ? active : img;
    window.clearTimeout(closeTimer);
    overlay.hidden = false;

    // 强制一次布局，让打开时的缩放/透明度过渡生效
    overlay.getBoundingClientRect();
    overlay.classList.add('is-open');
    lockScroll();
    show(index);

    try {
      closeBtn.focus({ preventScroll: true });
    } catch (e) {
      closeBtn.focus();
    }
  }

  function trapTab(event) {
    var focusables = overlay.querySelectorAll('button:not([disabled])');
    if (!focusables.length) return;

    var first = focusables[0];
    var last = focusables[focusables.length - 1];
    var active = document.activeElement;

    if (event.shiftKey) {
      if (active === first || !overlay.contains(active)) {
        event.preventDefault();
        last.focus();
      }
    } else if (active === last || !overlay.contains(active)) {
      event.preventDefault();
      first.focus();
    }
  }

  function onKeydown(event) {
    if (!isOpen) return;

    var key = event.key;

    if (key === 'Escape' || key === 'Esc') {
      event.preventDefault();
      close();
      return;
    }

    if (key === 'ArrowLeft') {
      event.preventDefault();
      go(-1);
      return;
    }

    if (key === 'ArrowRight') {
      event.preventDefault();
      go(1);
      return;
    }

    if (key === 'Tab') {
      trapTab(event);
      return;
    }

    if (key === '+' || key === '=') {
      event.preventDefault();
      if (!zoomed) toggleZoom(null);
      return;
    }

    if (key === '-' || key === '0') {
      event.preventDefault();
      resetZoom();
    }
  }

  function onDocumentClick(event) {
    if (suppressClick) {
      suppressClick = false;
      return;
    }

    var target = event.target;
    if (!target || target.tagName !== 'IMG') return;

    if (overlay && overlay.contains(target)) {
      if (target === image) {
        event.preventDefault();
        toggleZoom(event);
      }
      return;
    }

    if (event.defaultPrevented) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (!target.closest('.entry-content')) return;
    if (!isEligible(target)) return;

    event.preventDefault();
    open(target);
  }

  function onDocumentKeydown(event) {
    var target = document.activeElement;
    if (!target || target.tagName !== 'IMG') return;
    if (event.key !== 'Enter' && event.key !== ' ') return;
    if (!target.closest('.entry-content')) return;
    if (!isEligible(target)) return;

    event.preventDefault();
    open(target);
  }

  function onImagePointerDown(event) {
    if (!zoomed || !image) return;
    if (event.button !== undefined && event.button !== 0) return;

    drag = {
      id: event.pointerId,
      x: event.clientX,
      y: event.clientY,
      panX: panX,
      panY: panY,
      moved: false
    };

    image.classList.add('is-dragging');
    try {
      image.setPointerCapture(event.pointerId);
    } catch (e) {
      // 某些浏览器不支持 pointer capture，不影响拖动
    }
    event.preventDefault();
  }

  function onImagePointerMove(event) {
    if (!drag || !image || event.pointerId !== drag.id) return;

    var dx = event.clientX - drag.x;
    var dy = event.clientY - drag.y;

    if (Math.abs(dx) + Math.abs(dy) > 4) drag.moved = true;
    panX = drag.panX + dx;
    panY = drag.panY + dy;
    applyPan();

    if (drag.moved) event.preventDefault();
  }

  function onImagePointerUp(event) {
    if (!drag || !image) return;

    var moved = drag.moved;
    var pointerId = drag.id;
    drag = null;
    image.classList.remove('is-dragging');

    try {
      if (image.hasPointerCapture && image.hasPointerCapture(pointerId)) {
        image.releasePointerCapture(pointerId);
      }
    } catch (e) {
      // 忽略浏览器差异
    }

    if (moved) suppressNextClick();
  }

  function onStageTouchStart(event) {
    if (event.touches.length !== 1) {
      touchStart = null;
      return;
    }
    touchStart = {
      x: event.touches[0].clientX,
      y: event.touches[0].clientY,
      time: Date.now()
    };
  }

  function onStageTouchEnd(event) {
    if (!touchStart) return;

    var start = touchStart;
    var touch = event.changedTouches && event.changedTouches[0];
    touchStart = null;

    if (!touch || zoomed) return;

    var dx = touch.clientX - start.x;
    var dy = touch.clientY - start.y;
    var elapsed = Date.now() - start.time;

    if (elapsed < 700 && Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.3) {
      suppressNextClick();
      go(dx < 0 ? 1 : -1);
    }
  }

  function ensureDom() {
    if (overlay) return;

    overlay = document.createElement('div');
    overlay.className = 'iro-lightbox';
    overlay.hidden = true;
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', LABELS.viewer);

    overlay.innerHTML =
      '<div class="iro-lightbox__backdrop"></div>' +
      '<div class="iro-lightbox__stage">' +
        '<div class="iro-lightbox__topbar">' +
          '<span class="iro-lightbox__counter" aria-live="polite" aria-atomic="true"></span>' +
          '<button type="button" class="iro-lightbox__close" aria-label="' + LABELS.close + '">' +
            '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>' +
          '</button>' +
        '</div>' +
        '<button type="button" class="iro-lightbox__nav iro-lightbox__nav--prev" aria-label="' + LABELS.prev + '">' +
          '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg>' +
        '</button>' +
        '<figure class="iro-lightbox__figure">' +
          '<div class="iro-lightbox__frame">' +
            '<img class="iro-lightbox__image" alt="" draggable="false">' +
            '<span class="iro-lightbox__spinner" aria-hidden="true"></span>' +
            '<span class="iro-lightbox__error" role="alert" hidden>' + LABELS.error + '</span>' +
          '</div>' +
          '<figcaption class="iro-lightbox__caption" id="iro-lightbox-caption" aria-live="polite"></figcaption>' +
        '</figure>' +
        '<button type="button" class="iro-lightbox__nav iro-lightbox__nav--next" aria-label="' + LABELS.next + '">' +
          '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>' +
        '</button>' +
      '</div>';

    document.body.appendChild(overlay);
    overlay.setAttribute('aria-describedby', 'iro-lightbox-caption');

    frame = overlay.querySelector('.iro-lightbox__frame');
    image = overlay.querySelector('.iro-lightbox__image');
    caption = overlay.querySelector('.iro-lightbox__caption');
    counter = overlay.querySelector('.iro-lightbox__counter');
    error = overlay.querySelector('.iro-lightbox__error');
    prevBtn = overlay.querySelector('.iro-lightbox__nav--prev');
    nextBtn = overlay.querySelector('.iro-lightbox__nav--next');
    closeBtn = overlay.querySelector('.iro-lightbox__close');

    overlay.addEventListener('click', function (event) {
      var target = event.target;
      if (
        target === overlay ||
        target.classList.contains('iro-lightbox__backdrop') ||
        target.classList.contains('iro-lightbox__stage')
      ) {
        close();
      }
    });

    closeBtn.addEventListener('click', function () {
      close();
    });

    prevBtn.addEventListener('click', function () {
      go(-1);
    });

    nextBtn.addEventListener('click', function () {
      go(1);
    });

    image.addEventListener('pointerdown', onImagePointerDown);
    image.addEventListener('pointermove', onImagePointerMove);
    image.addEventListener('pointerup', onImagePointerUp);
    image.addEventListener('pointercancel', onImagePointerUp);

    var stage = overlay.querySelector('.iro-lightbox__stage');
    stage.addEventListener('touchstart', onStageTouchStart, { passive: true });
    stage.addEventListener('touchend', onStageTouchEnd, { passive: true });

    document.addEventListener('keydown', onKeydown);
  }

  function markImages() {
    var nodes = document.querySelectorAll('.entry-content img');

    for (var i = 0; i < nodes.length; i++) {
      var img = nodes[i];
      if (img.getAttribute('data-iro-lightbox') === 'ready') continue;
      if (!isCandidate(img)) continue;
      if (img.closest('a')) continue;

      var rect = img.getBoundingClientRect();
      if (rect.width && rect.height && !isVisibleEnough(img)) continue;

      var text = captionFor(img);
      img.setAttribute('data-iro-lightbox', 'ready');
      img.setAttribute('tabindex', '0');
      img.setAttribute('role', 'button');
      img.setAttribute('aria-label', text ? LABELS.view + '：' + text : LABELS.view);
    }
  }

  document.addEventListener('click', onDocumentClick);
  document.addEventListener('keydown', onDocumentKeydown);

  document.addEventListener('pjax:complete', function () {
    if (isOpen) close(true);
    markImages();
  });

  window.addEventListener('load', markImages);

  window.addEventListener('resize', function () {
    if (!isOpen || !zoomed) return;
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(resetZoom, 160);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', markImages);
  } else {
    markImages();
  }
})();
