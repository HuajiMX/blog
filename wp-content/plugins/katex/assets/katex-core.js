/*
 * Shared math core.
 *
 * Loaded before render.js (front end) and preview.js (editor), so both use the
 * same syntax table and the same rendering call. The table itself comes from
 * the server (katex_math_rules_for_js()), which keeps PHP and JS in sync
 * instead of maintaining two copies of every regular expression.
 *
 * Config arrives as `katexMathConfig`, localized onto this script's handle by
 * scripts/resource.php (front end) and scripts/preview.php (editor).
 */

(function (window) {
  "use strict";

  var config = window.katexMathConfig || {};
  var RENDER = config.render || {};
  var OPTIONS = config.katex || {};

  var TEQ_CLASS = RENDER.texClass || "katex-eq";
  var DISPLAY_ATTR = RENDER.displayAttr || "data-katex-display";
  var SOURCE_ATTR = RENDER.sourceAttr || "data-katex-tex";
  var RENDERED_CLASS = RENDER.renderedClass || "katex-rendered";

  var rules = (config.rules || []).map(function (rule) {
    return {
      id: rule.id,
      display: !!rule.display,
      block: !!rule.block,
      re: new RegExp(rule.pattern, "g" + (rule.flags || "").replace("g", "")),
    };
  });

  // Regions where `$` is literal: fences, code spans, link destinations,
  // shortcodes. Sent by the server so the preview protects exactly what the
  // save-time pass protects.
  var literalPatterns = (config.literal || []).map(function (entry) {
    var flags = "g" + (entry.flags || "").replace("g", "");
    return new RegExp(entry.pattern, flags);
  });

  /**
   * Escape TeX for an HTML attribute and for element text.
   */
  function escapeTex(tex) {
    return String(tex)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  /**
   * Build the same markup the PHP side produces (grammar.php).
   */
  function mathElement(tex, display, block) {
    var source = escapeTex(tex);
    var tag = block ? "div" : "span";
    var text = block
      ? source.replace(/\n/g, "&#10;")
      : source.replace(/\s+/g, " ").trim();

    return (
      "<" + tag +
      ' class="' + TEQ_CLASS + '"' +
      " " + DISPLAY_ATTR + '="' + (display ? "true" : "false") + '"' +
      " " + SOURCE_ATTR + '="' + source.replace(/\n/g, "&#10;") + '">' +
      text +
      "</" + tag + ">"
    );
  }

  /**
   * Hide Markdown regions where `$` is literal, so a `$$` inside a fence cannot
   * pair with the next `$$` in the document and a `$` inside a link or a
   * shortcode is never rewritten. Mirrors katex_protect_markdown_literal().
   */
  function protectCode(markdown, store) {
    literalPatterns.forEach(function (re) {
      markdown = markdown.replace(re, function (match) {
        var key = "\u0001MCODE" + store.length + "\u0001";
        store.push({ key: key, html: match });
        return key;
      });
    });

    return markdown;
  }

  /**
   * Replace math in a Markdown source with placeholders.
   *
   * @param {string} markdown
   * @returns {{text: string, tokens: Array}}
   */
  function protectMarkdown(markdown) {
    var store = [];
    var tokens = [];

    if (typeof markdown !== "string" || markdown === "") {
      return { text: markdown, tokens: tokens };
    }
    if (markdown.indexOf("$") === -1 && markdown.indexOf("\\") === -1) {
      return { text: markdown, tokens: tokens };
    }

    var text = protectCode(markdown, store);

    rules.forEach(function (rule) {
      rule.re.lastIndex = 0;
      text = text.replace(rule.re, function (match, tex) {
        var block = !!rule.block && match.indexOf("\n") !== -1;
        var key = "\u0001MMATH" + tokens.length + "\u0001";
        tokens.push({
          key: key,
          html: mathElement(tex, rule.display, block),
          // Fallback when a "block" formula ends up sharing a paragraph with
          // other content: a <div> may not live inside a <p>.
          inline: mathElement(tex, rule.display, false),
          // Attribute-safe fallback (heading ids, title, alt).
          plain: escapeTex(tex).replace(/\s+/g, " ").trim(),
          block: block,
        });
        return key;
      });
    });

    store.forEach(function (entry) {
      text = text.split(entry.key).join(entry.html);
    });

    return { text: text, tokens: tokens };
  }

  /**
   * Put math elements back into marked's HTML.
   *
   * Block tokens were wrapped in <p>…</p>; drop that wrapper because a <div>
   * may not live inside a <p>. When the token shares its paragraph with other
   * text the block form is impossible, so fall back to the inline element.
   */
  function restoreMarkdown(html, tokens) {
    if (!tokens || !tokens.length) {
      return html;
    }

    // A token can land inside an attribute: Editor.md puts the heading text in
    // `id="…"` and `name="…"`, and its heading renderer lowercases that text, so
    // the token arrives as `\u0001mmath0\u0001`. Replacing it there would inject
    // markup into the attribute, so degrade it to the formula's plain text
    // instead, exactly as katex_markdown_restore() does on the server.
    var parts = html.split(/(<[^>]*>)/);
    for (var i = 1; i < parts.length; i += 2) {
      if (!/\u0001mmath/i.test(parts[i])) {
        continue;
      }
      for (var j = 0; j < tokens.length; j++) {
        parts[i] = parts[i].replace(new RegExp(tokens[j].key, "gi"), tokens[j].plain);
      }
    }
    html = parts.join("");

    tokens.forEach(function (token) {
      if (token.block) {
        var wrapped = new RegExp("<p>\\s*" + token.key + "\\s*</p>", "g");
        html = html.replace(wrapped, function () {
          return token.html;
        });

        if (html.indexOf(token.key) !== -1) {
          html = html.split(token.key).join(token.inline);
        }
      }
      html = html.split(token.key).join(token.html);
    });

    return html;
  }

  /**
   * TeX source of a math element: attribute first (texturize-proof), text as
   * fallback for markup produced without the attribute.
   */
  function texOf(el) {
    var tex = el.getAttribute(SOURCE_ATTR);
    if (tex === null) {
      tex = (el.textContent || "").replace(/\u00a0/g, " ");
    }
    return tex;
  }

  /**
   * Render every unrendered math element inside `root`.
   *
   * @param {Element|Document} root
   * @returns {number} Number of elements rendered.
   */
  function render(root) {
    if (typeof katex === "undefined") {
      return 0;
    }

    root = root || document;
    var nodes = root.querySelectorAll("." + TEQ_CLASS + ":not(." + RENDERED_CLASS + ")");
    var count = 0;

    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      el.classList.add(RENDERED_CLASS);

      var options = {
        displayMode: el.getAttribute(DISPLAY_ATTR) === "true",
        throwOnError: OPTIONS.throwOnError !== undefined ? OPTIONS.throwOnError : false,
        strict: OPTIONS.strict !== undefined ? OPTIONS.strict : "ignore",
        output: OPTIONS.output || "html",
      };

      try {
        katex.render(texOf(el), el, options);
        count++;
      } catch (err) {
        el.classList.add("katex-error");
        el.textContent = err && err.message ? err.message : "KaTeX error";
      }
    }

    return count;
  }

  window.iroKatex = {
    rules: rules,
    protectMarkdown: protectMarkdown,
    restoreMarkdown: restoreMarkdown,
    mathElement: mathElement,
    render: render,
    renderAll: function () {
      return render(document);
    },
  };
})(window);
