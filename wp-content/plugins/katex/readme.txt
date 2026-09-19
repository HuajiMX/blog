=== KaTeX ===
Contributors: beskhue
Tags: katex, latex, math, tex, mathjax
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 2.2.5
Requires PHP: 5.3
License: GPLv2 or later

Use the fastest math typesetting library on your website.

== Description ==
The KaTeX WordPress plugin enables you to use the fastest [TeX math typesetting engine](https://github.com/Khan/KaTeX) on your WordPress website. You can include TeX inside a `[katex]...[/katex]` shortcode or in a Gutenberg block. Either way the math will render beautifully on your website. When using Gutenberg blocks, the equations will render immediately inside your editor!

Equations in blocks or using the `[katex display=true]...[/katex]` shortcode will render on page in display mode--with bigger symbols--centered on their own line.

For compatibility with other LaTeX plugins, this plugin optionally supports `[latex]...[/latex]` shortcodes.

[Plugin Website](https://wordpress.org/plugins/katex)

== Installation ==
1. Upload the `katex` folder to your `/wp-content/plugins/` directory or automatically download and install the plugin through WordPress's plugin manager;
1. Activate the plugin in WordPress; and
1. Use the `[latex]` shortcode or KaTeX Gutenberg blocks in your posts and pages.

== Frequently Asked Questions ==
= Can I move from LaTeX plugin X to this plugin? =

You should be able to replace any other LaTeX plugin using `[latex]` shortcodes without having to make changes to existing posts. Other plugins might handle display-mode latex other than `[latex display=true]...[/latex]`, in which case old posts unfortunately have to be changed.

== Screenshots ==
1. Preview your TeX right inside the editor.
1. TeX is rendered inside your visitors' browsers.

== Assets ==
This plugin includes minified assets provided by the KaTeX project.
The source code is available in [the KaTeX git repository on GitHub](https://github.com/KaTeX/KaTeX/tree/v0.16.22). 

== Changelog ==
= 2.2.5 =
* As per WordPress's guidelines, remove the ability to fetch KaTeX resources through jsDelivr's CDN.
* Various internal improvements based on [WordPress's Plugin Check](https://wordpress.org/plugins/plugin-check/).

= 2.2.4 =
* Upgrade KaTeX resources to v0.16.22.

= 2.2.3 =
* More robustly render shortcodes (even more robustly than 2.2.2).

= 2.2.2 =
* More robustly render shortcodes.
* Fix undefined variable notice.

= 2.2.1 =
* Trigger rendering of KaTeX in more cases when the DOM is mutated (in 2.2.0, accidentally only a limited set of cases was checked).

= 2.2.0 =
* Trigger rendering of KaTeX when the DOM is mutated by inserting a `.katex-eq` node. This allows rendering KaTeX markup that is not present when the page is loaded.
* Upgrade KaTeX resources to v0.13.13.

= 2.1.2 =
* Always load JavaScript and CSS assets by default. An option is introduced to switch to the old behavior of loading only when KaTeX is used on the page.
* Make it easier for other code to manually trigger rendering of KaTeX.
* Upgrade KaTeX resources to v0.13.0.

= 2.1.1 =
* Remove `<br>` tags added by WordPress to shortcode output.

= 2.1.0 =
* Prevent WordPress from texturizing KaTeX (prior to this change, WordPress would sometimes change e.g. apostrophes to quotation marks).
* Fix issue where custom class names on the KaTeX block sometimes broke editor rendering.
* Add some keywords to allow searching for the KaTeX block in the editor's block list.
* Update KaTeX resources to v0.12.0.

= 2.0.2 =
* Fix block editor variable scoping.

= 2.0.1 =
* Upgrade KaTeX resources to v0.11.1.

= 2.0.0 =
* Support adding CSS classes to KaTeX Gutenberg Blocks to help with styling. Backwards compatibility note: KaTeX Gutenberg Blocks are now rendered wrapped in a `div` element on which classes `wp-block-katex-display-block` and `katex-eq` are set. You can add more classes to this `div` through WordPress's post editor. Previously, KaTeX Gutenberg Blocks were rendered wrapped in an unclassed `span`. KaTeX shortcodes are still wrapped inside a `span` with only the class `katex-eq` set. If you depend on old behavior for styling, you might need to update your styling rules.

= 1.0.5 =
* Fix 1.0.4 release issue: KaTeX resources were not committed correctly.

= 1.0.4 =
* Upgrade KaTeX resources to v0.10.2.

= 1.0.3 =
* Fix warnings related to plugin options that occurred on PHP 5.
* Clean up the plugin's options on plugin deletion.

= 1.0.2 =
* Upgrade KaTeX resources to v0.10.1.

= 1.0.1 =
* Fix issue where KaTeX resources would not be loaded on the admin pages.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==
