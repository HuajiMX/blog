/*
 * Editor preview for wp-githuber-md.
 *
 * Replaces the old inline patch that carried its own copy of the math regexes,
 * its own placeholders and loaded a second, older KaTeX (0.12.0) from the
 * Githuber vendor directory. The preview now:
 *
 *   - takes the syntax table from the server (katexMathConfig.rules),
 *   - produces the exact markup the front end renders,
 *   - uses the same KaTeX build as the front end,
 *   - delegates rendering to katex-core.js.
 *
 * Activated by wp-githuber-md through `window.iroKatexPreview.setup(editor)`
 * once Editor.md exists; does nothing on screens without the editor.
 */

(function (window, document) {
  "use strict";

  var core = window.iroKatex;
  if (!core) {
    return;
  }

  var RENDER = (window.katexMathConfig && window.katexMathConfig.render) || {};
  var TEQ_CLASS = RENDER.texClass || "katex-eq";
  var RENDERED_CLASS = RENDER.renderedClass || "katex-rendered";

  var setupDone = false;

  /**
   * Wrap marked so math is never parsed by Markdown.
   *
   * @param {Object} editormd
   */
  function wrapMarked(editormd) {
    var original = editormd.$marked;
    if (!original || original.__iroKatexWrapped) {
      return;
    }

    var wrapped = function (markdown, renderer, options) {
      var result = core.protectMarkdown(markdown);
      var html = original.call(this, result.text, renderer, options);
      return core.restoreMarkdown(html, result.tokens);
    };
    wrapped.__iroKatexWrapped = true;

    // Editor.md builds its renderer through `editormd.$marked.Renderer`, so the
    // static members have to survive the wrap.
    for (var key in original) {
      if (Object.prototype.hasOwnProperty.call(original, key)) {
        wrapped[key] = original[key];
      }
    }

    editormd.$marked = wrapped;
  }

  /**
   * Render math inside one editor preview pane.
   *
   * @param {Object} editor Editor.md instance.
   * @returns {Object} The editor, for chaining (Editor.md convention).
   */
  function renderPreview(editor) {
    if (!editor || !editor.previewContainer) {
      return editor;
    }

    var root = editor.previewContainer[0];
    if (!root) {
      return editor;
    }

    // The preview is rewritten on every keystroke, so previously rendered nodes
    // are gone; nothing to un-mark, just render what is unrendered.
    core.render(root);
    return editor;
  }

  /**
   * Install the preview integration.
   *
   * @param {Object} editor Editor.md instance, optional.
   */
  function setup(editor) {
    if (typeof editormd === "undefined") {
      return;
    }

    if (!setupDone) {
      wrapMarked(editormd);

      if (editormd.prototype) {
        editormd.prototype.katexRender = function () {
          return renderPreview(this);
        };
      }

      setupDone = true;
    }

    if (editor) {
      renderPreview(editor);
    } else if (window.githuber_md_editor) {
      renderPreview(window.githuber_md_editor);
    }
  }

  window.iroKatexPreview = {
    setup: setup,
    render: renderPreview,
  };

  // Editor.md's own patch (`onchange`) is gone, so re-render after every edit.
  document.addEventListener("DOMContentLoaded", function () {
    if (window.githuber_md_editor) {
      setup(window.githuber_md_editor);
    }
  });
})(window, document);
