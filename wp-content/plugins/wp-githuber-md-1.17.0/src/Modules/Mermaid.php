<?php
/**
 * Module Name: Mermaid
 * Module Description: Generation of diagrams and flowcharts from text in a similar manner as markdown.
 *
 * @author Terry Lin
 * @link https://terryl.in/
 *
 * @package Githuber
 * @since 1.4.0
 * @version 1.4.0
 */

namespace Githuber\Module;

/**
 * Mermaid.
 */
class Mermaid extends ModuleAbstract {

	/**
	 * The version of flowchart.js we are using.
	 *
	 * @var string
	 */
	public $mermaid_version = '9.4.3';

	/**
	 * Constructer.
	 */
	public function __construct() {
		parent::__construct();
	}

	/**
	 * Constants.
	 */
	const MD_POST_META_MERMAID = '_is_githuber_mermaid';

	/**
	 * Initialize.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'front_enqueue_scripts' ) );
		add_action( 'wp_print_footer_scripts', array( $this, 'front_print_footer_scripts' ) );
	}

	/**
	 * Register CSS style files for frontend use.
	 *
	 * @return void
	 */
	public function front_enqueue_styles() {

	}

	/**
	 * Register JS files for frontend use.
	 *
	 * @return void
	 */
	public function front_enqueue_scripts() {
		if ( $this->is_module_should_be_loaded( self::MD_POST_META_MERMAID ) ) {
			$option = githuber_get_option( 'mermaid_src', 'githuber_modules' );

			switch ( $option ) {
				case 'cloudflare':
					$script_url = 'https://cdnjs.cloudflare.com/ajax/libs/mermaid/' . $this->mermaid_version . '/mermaid.min.js';
					break;

				case 'jsdelivr':
					$script_url = 'https://cdn.jsdelivr.net/npm/mermaid@' . $this->mermaid_version . '/dist/mermaid.min.js';
					break;

				default:
					$script_url = $this->githuber_plugin_url . 'assets/vendor/mermaid/mermaid.min.js';
					break;
			}

			wp_enqueue_script( 'mermaid', $script_url, array(), $this->mermaid_version, true );
		}
	}

	/**
	 * Print Javascript plaintext in page footer.
	 */
	public function front_print_footer_scripts() {
		$script = '
			<script id="module-mermaid">
				(function(){
					/* 该内联脚本输出在 body 末尾（wp_footer），早于主题 app.js/page.js 的代码高亮。
					   1) 立即给 Mermaid 代码块打上 no-highlight，避免 highlight.js 把它当普通代码
					      自动高亮（报 Unescaped HTML 并破坏渲染）。
					   2) 清理主题给 Mermaid 代码块附加的代码块装饰：highlight-wrap 边框、
					      data-rel 语言标签、复制按钮、code-block id 等。 */
					var isMermaid = function(el) {
						return el && el.classList && (el.classList.contains("mermaid") || el.classList.contains("language-mermaid"));
					};
					var markNoHighlight = function() {
						var els = document.querySelectorAll("pre code.language-mermaid, pre code.mermaid");
						for (var i = 0; i < els.length; i++) {
							els[i].classList.add("no-highlight");
							if (els[i].parentElement) {
								els[i].parentElement.classList.add("no-highlight");
							}
						}
					};
					var cleanMermaidDecor = function() {
						var codes = document.querySelectorAll("pre code.mermaid, pre code.language-mermaid");
						for (var i = 0; i < codes.length; i++) {
							var code = codes[i], pre = code.parentElement;
							if (pre) {
								pre.classList.remove("highlight-wrap");
								["autocomplete", "autocorrect", "autocapitalize", "spellcheck", "contenteditable", "design"].forEach(function(a) {
									pre.removeAttribute(a);
								});
								var btn = pre.querySelector(".copy-code");
								if (btn) { btn.remove(); }
							}
							code.removeAttribute("data-rel");
							code.removeAttribute("id");
						}
					};
					markNoHighlight();
					cleanMermaidDecor();
					/* 主题的代码高亮是异步的（highlight.js chunk 加载完成后才执行），
					   会在 Mermaid 渲染后才给代码块加边框/语言标签/复制按钮。
					   用多次延迟清理覆盖该时机；不使用 MutationObserver 监听全 body，
					   避免高频回调导致页面卡死。 */
					setTimeout(cleanMermaidDecor, 600);
					setTimeout(cleanMermaidDecor, 1500);
					setTimeout(cleanMermaidDecor, 4000);
					/* PJAX / AJAX 换页后重新清理 + 重新渲染 Mermaid */
					document.addEventListener("pjax:complete", function() {
						setTimeout(function() {
							markNoHighlight();
							cleanMermaidDecor();
							/* 换页后 DOM 已被替换，需重新渲染 Mermaid 图表 */
							if (typeof mermaid !== "undefined") {
								var els = document.querySelectorAll("pre code.language-mermaid");
								if (els.length > 0) {
									for (var i = 0; i < els.length; i++) {
										els[i].classList.add("mermaid");
										els[i].classList.remove("language-mermaid");
										if (els[i].parentElement) {
											els[i].parentElement.setAttribute("style", "text-align: center; background: none;");
										}
									}
									mermaid.init();
								}
							}
						}, 200);
					});
				})();
				(function($) {
					$(function() {
						if (typeof mermaid !== "undefined") {
							if ($(".language-mermaid").length > 0) {
								$(".language-mermaid").parent("pre").attr("style", "text-align: center; background: none;");
								$(".language-mermaid").addClass("mermaid").removeClass("language-mermaid");
								mermaid.init();
							}
						}
					});
				})(jQuery);
			</script>
		';
		echo preg_replace( '/\s+/', ' ', $script );
	}
}
