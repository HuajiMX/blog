/*
 * Copyright (C) 2018  Thomas Churchman
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

(function(wpBlocks, wpElement, wpI18n) {
  const { registerBlockType } = wpBlocks;
  const { createElement } = wpElement;
  const { __ } = wpI18n;

  const parser = new DOMParser();
  const decodeHtml = str => {
    const dom = parser.parseFromString(
      "<!doctype html><body>" + str,
      "text/html"
    );
    return dom.body.textContent;
  };

  registerBlockType("katex/display-block", {
    title: "KaTeX",
    icon: createElement("span", {}, "Ka"),
    category: "formatting",
    keywords: [__("katex"), __("latex"), __("math")],
    attributes: {
      content: {
        type: "string",
        source: "html",
        selector: "pre"
      },
      className: {
        type: "string"
      }
    },
    supports: {
      anchor: true
    },
    edit({ className, attributes, setAttributes }) {
      const content = attributes.content;

      function onChangeContent(content) {
        setAttributes({ content });
      }

      let rendered;
      try {
        rendered = katex.renderToString(decodeHtml(content), {
          displayMode: "true",
          throwOnError: false
        });
      } catch (e) {
        rendered = `<span style='color: red; text-align: center;'>${e}</span>`;
      }

      return createElement(
        "div",
        { className: "wp-block-katex-block-editor" },
        [
          createElement("div", { className: "katex-editor", key: "editor" }, [
            createElement((wp.blockEditor || wp.editor).PlainText, {
              onChange: onChangeContent,
              value: content,
              key: "field"
            }),
            createElement("hr", { key: "ruler" })
          ]),
          createElement("div", {
            className,
            dangerouslySetInnerHTML: {
              __html: rendered
            },
            key: "render"
          })
        ]
      );
    },
    save({ attributes }) {
      const content = attributes.content;

      return createElement(
        "div",
        {
          className: "katex-eq",
          "data-katex-display": "true"
        },
        createElement("pre", {}, content)
      );
    },
    deprecated: [
      {
        attributes: {
          content: {
            type: "string",
            source: "html",
            selector: "div"
          }
        },
        supports: {
          anchor: true
        },
        save({ attributes }) {
          const content = attributes.content;

          return createElement(
            "div",
            {
              className: "katex-eq",
              "data-katex-display": "true"
            },
            content
          );
        },
      },
      {
        attributes: {
          content: {
            type: "string",
            source: "html",
            selector: "span"
          }
        },
        save({ attributes }) {
          const content = attributes.content;

          return createElement(
            "span",
            {
              className: "katex-eq",
              "data-katex-display": "true"
            },
            content
          );
        }
      }
    ]
  });
})(wp.blocks, wp.element, wp.i18n);
