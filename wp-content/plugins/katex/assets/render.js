/*
 * Front-end renderer.
 *
 * Renders `.katex-eq` elements produced by scripts/grammar.php. All rendering
 * logic lives in katex-core.js so the editor preview cannot drift from the
 * site.
 */

(function (window, document) {
  "use strict";

  function renderAll() {
    if (window.iroKatex) {
      return window.iroKatex.render(document);
    }
    return 0;
  }

  function renderWithin(root) {
    if (window.iroKatex && root) {
      return window.iroKatex.render(root);
    }
    return 0;
  }

  function observe() {
    // Only the article body is watched. Watching document.body for attributes
    // and character data made every theme animation trigger a full scan.
    var targets = document.querySelectorAll("#main, .entry-content, .comments");
    if (!targets.length) {
      targets = [document.body];
    }

    var observer = new MutationObserver(function (mutations) {
      for (var i = 0; i < mutations.length; i++) {
        var added = mutations[i].addedNodes;
        for (var j = 0; j < added.length; j++) {
          var node = added[j];
          if (node.nodeType !== 1) {
            continue;
          }
          if (node.classList && node.classList.contains("katex-eq")) {
            renderWithin(node.parentNode || document);
          } else if (node.querySelector && node.querySelector(".katex-eq:not(.katex-rendered)")) {
            renderWithin(node);
          }
        }
      }
    });

    for (var i = 0; i < targets.length; i++) {
      observer.observe(targets[i], { childList: true, subtree: true });
    }
  }

  function boot() {
    renderAll();
    observe();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }

  // Sakurairo replaces the article body through Pjax and re-builds the table of
  // contents afterwards; both can introduce fresh math.
  document.addEventListener("pjax:complete", function () {
    renderAll();
  });
  document.addEventListener("tocEvent", function () {
    renderAll();
  });

  window.iroKatexRender = renderAll;
})(window, document);
