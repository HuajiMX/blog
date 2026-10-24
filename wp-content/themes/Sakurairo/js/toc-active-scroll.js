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
      keepActiveVisible();
    });
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
