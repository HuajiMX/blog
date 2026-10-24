(function () {
  'use strict';

  var toc = null;
  var observer = null;
  var pending = false;

  function schedule() {
    if (pending) return;
    pending = true;
    requestAnimationFrame(function () {
      pending = false;
      fixMathLabels();
      keepActiveVisible();
    });
  }

  function fixMathLabels() {
    if (!toc) return;
    var links = toc.querySelectorAll('a.toc-link[href^="#"]');

    for (var i = 0; i < links.length; i++) {
      var link = links[i];
      if (link.dataset.tocMathFixed) continue;

      var id;
      try {
        id = decodeURIComponent(link.getAttribute('href').slice(1));
      } catch (e) {
        continue;
      }

      var heading = document.getElementById(id);
      if (!heading || !heading.querySelector('.katex')) continue;

      var clone = heading.cloneNode(true);
      clone.removeAttribute('id');
      clone.querySelectorAll('[id]').forEach(function (node) {
        node.removeAttribute('id');
      });
      clone.querySelectorAll('.katex-mathml').forEach(function (node) {
        node.remove();
      });
      clone.querySelectorAll('a').forEach(function (node) {
        while (node.firstChild) {
          node.parentNode.insertBefore(node.firstChild, node);
        }
        node.remove();
      });

      var plain = (clone.textContent || '').replace(/\s+/g, ' ').trim();
      if (!plain) continue;

      // Mark before changing children, so the MutationObserver does not loop.
      link.dataset.tocMathFixed = '1';
      while (link.firstChild) link.removeChild(link.firstChild);
      while (clone.firstChild) link.appendChild(clone.firstChild);
      link.setAttribute('title', plain);
      link.setAttribute('aria-label', plain);
    }
  }

  function keepActiveVisible() {
    if (!toc || !toc.clientHeight) return;
    var active = toc.querySelector('.is-active-link');
    if (!active) return;

    var tocRect = toc.getBoundingClientRect();
    var activeRect = active.getBoundingClientRect();
    var top = activeRect.top - tocRect.top + toc.scrollTop;
    var bottom = top + activeRect.height;

    if (top < toc.scrollTop || bottom > toc.scrollTop + toc.clientHeight) {
      var target = top - (toc.clientHeight - activeRect.height) / 2;
      toc.scrollTo({
        top: Math.max(0, target),
        behavior: 'smooth'
      });
    }
  }

  function bind() {
    var next = document.querySelector('#main-container .toc');
    if (!next) return;
    if (next === toc && observer) {
      schedule();
      return;
    }
    if (observer) observer.disconnect();
    toc = next;
    observer = new MutationObserver(schedule);
    observer.observe(toc, {
      subtree: true,
      childList: true,
      attributes: true,
      attributeFilter: ['class']
    });
    schedule();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }

  document.addEventListener('pjax:complete', bind);
  document.addEventListener('tocEvent', bind);
})();
