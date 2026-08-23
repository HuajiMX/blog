

var global_editormd_config = {};
var wp_editor_container = '#wp-content-editor-container';
var wp_editor = 'wp-content-editor-container';
var githuber_md_editor;
var is_support_inline_keyboard_style = false;
var is_support_html_figure = false;
var spellcheck_dictionary_dir = '';
var spellcheck_lang = 'en_US';

(function($) {
    $(function() {
        var config = window.editormd_config;

        spellcheck_lang = config.editor_spell_check_lang;
        spellcheck_dictionary_dir = 'https://spellcheck-dictionaries.github.io/' + spellcheck_lang + '/';

        is_support_inline_keyboard_style = (config.support_inline_code_keyboard_style === 'yes');
        is_support_html_figure = (config.support_html_figure === 'yes');

        global_editormd_config = {
            width: '100%',
            height: 640,
            path: config.editor_modules_url,
            placeholder: config.placeholder,
            syncScrolling: (config.editor_sync_scrolling === 'yes'),
            watch: (config.editor_live_preview === 'yes'),
            htmlDecode: (config.editor_html_decode === 'yes'),
            theme: config.editor_toolbar_theme, 
            previewTheme: 'default',
            editorTheme: config.editor_editor_theme, 
            tocContainer: (config.support_toc === 'yes') ? '' : false,
            emoji: (config.support_emojify === 'yes'),
            tex: (config.support_katex === 'yes'),
            mathJax: (config.support_mathjax === 'yes'),
            flowChart: (config.support_flowchart === 'yes'),
            sequenceDiagram: (config.support_sequence_diagram === 'yes'),
            taskList: (config.support_task_list === 'yes'),
            mermaid: (config.support_mermaid === 'yes'),
            lineNumbers: (config.editor_line_number === 'yes'),
            previewCodeLineNumber: (config.prism_line_number === 'yes'),
            spellCheck: (config.editor_spell_check === 'yes'),
            matchWordHighlight: (config.editor_match_highlighter === 'yes') ? 'onselected' : false,
            toolbarAutoFixed: true,
            tocm: false, 
            tocDropdown: false,    
            atLink: false,
            imagePasteCallback: config.image_paste_callback,
            toolbarIcons: function () {
                return [
                    'undo', 'redo', '|',
                    'bold', 'del', 'italic', 'quote', '|',
                    'h1', 'h2', 'h3', 'h4', '|',
                    'list-ul', 'list-ol', 'hr', '|',
                    'link', 'reference-link', 'image', 'code', 'code-block', 'table', 'datetime', 'html-entities', 'more', 'pagebreak', config.support_emoji === 'yes' ? 'emoji' : '' + '|',
                    'watch', 'preview', 'fullscreen',  config.support_emojify === 'yes' ? "emoji" : "", 'help', 
                ];
            },
            onfullscreen: function () {
                $(wp_editor_container).css({
                    'position': 'fixed',
                    'z-index': '99999'
                })
            },

            onfullscreenExit: function () {
                $(wp_editor_container).css({
                    'position': 'relative',
                    'z-index': 'auto'
                });
                reload_githuber_md();
            },

            toolbarIconsClass: {
                toc: 'fa-list-alt',
                more: 'fa-ellipsis-h'
            },

            toolbarHandlers: {
                toc: function (cm, icon, cursor, selection) {
                    cm.replaceSelection('[toc]');
                },
                more: function (cm, icon, cursor, selection) {
                    cm.replaceSelection('\r\n<!--more-->\r\n');
                }
            },
            lang: {
                toolbar: {
                    toc: 'The Table Of Contents',
                    more: 'More'
                }
            },
            onchange: function () {
                if (editormd.$katex || (typeof katex !== 'undefined')) {
                    this.katexRender();
                }
            },
        };


        if ($(wp_editor_container).length === 1) {
            githuber_md_editor = editormd(wp_editor, global_editormd_config);
            githuberSetupKatexPreview();
        }

        function reload_githuber_md() {
            //  githuber_md_editor = editormd(wp_editor, global_editormd_config);
        }

        if (typeof image_insert_type !== 'undefined') {
            var image_insert_type = 'markdown';
        }
        $(document).on('change', '.githuber_image_insert', function() {
            // html or markdown
            image_insert_type = $(this).val();
        });

        /*
            $(document).ajaxSuccess(function(event, xhr, settings, data) {
                if (settings.url.indexOf('/wp-admin/admin-ajax.php') !== -1 && typeof data.data !== 'undefined') {
                    if (data.success && typeof data.data === 'string') {

                    }
                }
            });
        */

        wp.media.editor.insert = function (html_str) {
            //console.log(html_str);
            var new_content = '';

            if (html_str.substring(0, 4) === '<img') {
    
                var img_src = $(html_str).attr('src');
                var img_alt = $(html_str).attr('alt');

                if (image_insert_type === 'html') {
                    new_content += html_str;
                } else {
                    new_content += '![' + img_alt + '](' + img_src + ')';
                }

                githuber_md_editor.replaceSelection(new_content);
                image_insert_type = 'markdown';

            } else if (html_str.substring(0, 7) === '<a href' && -1 !== html_str.indexOf('<img')) {

                var a_href = $(html_str).attr('href');
                var img_src = $(html_str).find('img').attr('src');
                var img_alt = $(html_str).find('img').attr('alt');

                if (image_insert_type === 'html') {
                    new_content += html_str;
                } else {
                    new_content += '[![' + img_alt + '](' + img_src + ')](' + a_href + ')';
                }
                githuber_md_editor.replaceSelection(new_content);
                image_insert_type = 'markdown';
            } else if (html_str.substring(0, 1) === '[' && html_str.slice(-1) === ']') {
                new_content += html_str;
                githuber_md_editor.replaceSelection(new_content);
            } else if ((html_str.substring(0, 7) === '<a href')) {
                var ahref = $(html_str).attr('href');
                var inicio_txt = html_str.indexOf('>');
                var fin_txt = html_str.indexOf('<', inicio_txt);
                var txt = html_str.substring(inicio_txt+1, fin_txt);
                if (image_insert_type === 'html') {
                    new_content += html_str;
                } else {
                    new_content += '[' + txt + '](' + ahref +' "' + txt +'")';
                }
                githuber_md_editor.replaceSelection(new_content);
            } else {
                console.log(html_str);
            }
        }
    });
})(jQuery);

/* -------------------------------------------------------------------------
 * KaTeX live-preview integration.
 *
 * Renders $...$ (inline) and $$...$$ (display) math inside the Editor.md
 * preview. The math content is protected from the Markdown parser before
 * rendering, then handed to KaTeX as .editormd-tex elements.
 * ---------------------------------------------------------------------- */
function githuberSetupKatexPreview() {
    if (typeof editormd === 'undefined' || !editormd.$marked) {
        setTimeout(githuberSetupKatexPreview, 100);
        return;
    }

    if (window.__githuberKatexSetupDone) {
        return;
    }
    window.__githuberKatexSetupDone = true;

    console.log('[Githuber MD] KaTeX preview: marked wrapped');

    var originalMarked = editormd.$marked;

    // Hide $ inside code spans / fences / HTML code blocks so the math
    // patterns below never touch code content.
    function protectKatexMath(md, tokens) {
        md = md.replace(
            /(```[\s\S]*?```|~~~[\s\S]*?~~~|`[^`\n]+`|<pre\b[^>]*>[\s\S]*?<\/pre>|<code\b[^>]*>[\s\S]*?<\/code>)/g,
            function (m) {
                return m.replace(/\$/g, 'GMDKATEXDOLLAR');
            }
        );

        // Display math: $$...$$ (may span multiple lines).
        md = md.replace(/\$\$([\s\S]+?)\$\$/g, function (m, g1) {
            var key = 'GMDKATEX{' + tokens.length + '}';
            tokens.push({ key: key, math: g1, display: true });
            return key;
        });

        // Inline math: $...$ (single line, unescaped delimiters).
        md = md.replace(
            /(?<![\\$])\$(?!\$)([^\$\n]+?)(?<!\\)\$(?!\$)/g,
            function (m, g1) {
                var key = 'GMDKATEX{' + tokens.length + '}';
                tokens.push({ key: key, math: g1, display: false });
                return key;
            }
        );

        return md;
    }

    function restoreKatexMath(html, tokens) {
        // Bring back $ inside code.
        html = html.split('GMDKATEXDOLLAR').join('$');

        // Strip math placeholders from HTML attribute values (e.g. the
        // heading renderer puts the raw text into `name="..."`), so they
        // never leak HTML into attributes.
        html = html.replace(/([a-zA-Z][a-zA-Z0-9_-]*="[^"]*")/g, function (attr) {
            return attr.replace(/GMDKATEX\{\d+\}/g, '');
        });

        for (var i = 0; i < tokens.length; i++) {
            var t = tokens[i];
            var attr = t.display ? ' data-katex-display="true"' : '';
            var escaped = $('<span/>').text(t.math).html();
            html = html.split(t.key).join(
                '<span class="' + editormd.classNames.tex + '"' + attr + '>' + escaped + '</span>'
            );
        }

        return html;
    }

    // Wrap the markdown -> html step so math is never parsed by markdown.
    // Keep marked's static members (Renderer, Parser, options, ...) on the
    // wrapper, because Editor.md builds its renderer via editormd.$marked.Renderer.
    var wrappedMarked = function (md, renderer, options) {
        var tokens = [];
        var protectedMd = protectKatexMath(md, tokens);
        var html = originalMarked.call(this, protectedMd, renderer, options);
        return restoreKatexMath(html, tokens);
    };
    for (var k in originalMarked) {
        if (Object.prototype.hasOwnProperty.call(originalMarked, k)) {
            wrappedMarked[k] = originalMarked[k];
        }
    }
    editormd.$marked = wrappedMarked;

    // Render .editormd-tex nodes with KaTeX, honouring display mode.
    if (editormd.prototype) {
        editormd.prototype.katexRender = function () {
            var katexObj = editormd.$katex || (typeof katex !== 'undefined' ? katex : null);
            if (!katexObj) {
                return this;
            }
            var nodes = this.previewContainer.find('.' + editormd.classNames.tex);
            if (nodes.length > 0) {
                console.log('[Githuber MD] KaTeX render:', nodes.length, 'node(s)');
            }
            nodes.each(function () {
                var el = $(this);
                if (el.find('.katex').length > 0) {
                    return; // Already rendered.
                }
                var display = el.attr('data-katex-display') === 'true';
                try {
                    katexObj.render(el.text(), el[0], {
                        displayMode: display,
                        throwOnError: false
                    });
                } catch (e) {
                    el.text(e.message);
                }
            });
            return this;
        };
    }

    // Make sure KaTeX is available, then re-render the current preview so
    // already-open posts also get their math rendered.
    var rerenderKatexPreview = function () {
        if (typeof githuber_md_editor !== 'undefined' && githuber_md_editor) {
            githuber_md_editor.save();
        }
    };

    var ensureKatex = function () {
        if (editormd.$katex || (typeof katex !== 'undefined')) {
            rerenderKatexPreview();
            return;
        }
        if (window.__githuberKatexLoading) {
            return;
        }
        window.__githuberKatexLoading = true;
        editormd.loadKaTeX(function () {
            editormd.$katex = katex;
            console.log('[Githuber MD] KaTeX loaded for preview');
            rerenderKatexPreview();
        });
    };

    ensureKatex();
}


