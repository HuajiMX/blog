<?php
/**
 * 后台设置页面。
 *
 * @package RSS_Post_Types
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 后台界面。
 */
class RPT_Admin {

	const PAGE     = 'rss-post-types';
	const GROUP    = 'rpt_settings_group';

	/**
	 * 注册后台钩子。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_footer', array( __CLASS__, 'copy_script' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RPT_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * 在「设置」菜单下添加页面。
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_options_page(
			rpt_t( 'page_title' ),
			rpt_t( 'menu_title' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * 注册设置项（走 options.php，自带 nonce 与权限校验）。
	 *
	 * @return void
	 */
	public static function register_setting() {
		register_setting(
			self::GROUP,
			RPT_OPTION,
			array(
				'type'              => 'array',
				'description'       => rpt_t( 'page_title' ),
				'sanitize_callback' => array( 'RPT_Options', 'sanitize' ),
				'default'           => RPT_Options::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * 插件列表里的「设置」链接。
	 *
	 * @param string[] $links 已有链接.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );

		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html( rpt_t( 'settings_link' ) ) . '</a>' );

		return $links;
	}

	/**
	 * 单个类型的独立订阅地址。
	 *
	 * @param string $slug 类型标识.
	 * @return string
	 */
	private static function feed_url( $slug ) {
		if ( 'post' === $slug ) {
			return get_feed_link();
		}

		$type = get_post_type_object( $slug );
		if ( $type && ! empty( $type->has_archive ) ) {
			$link = get_post_type_archive_feed_link( $slug );
			if ( ! empty( $link ) ) {
				return $link;
			}
		}

		return add_query_arg( 'post_type', $slug, get_feed_link() );
	}

	/**
	 * 输出页面。
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings   = RPT_Options::get();
		$types      = RPT_Options::available_post_types();
		$selected   = RPT_Options::feed_post_types();
		$site_feed  = get_feed_link();
		$posts_per  = (int) get_option( 'posts_per_rss' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( rpt_t( 'page_title' ) ); ?></h1>
			<p class="description" style="max-width:760px;"><?php echo esc_html( rpt_t( 'intro' ) ); ?></p>

			<h2><?php echo esc_html( rpt_t( 'section_status' ) ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html( rpt_t( 'site_feed' ) ); ?></th>
					<td>
						<input type="text" readonly class="regular-text code" id="rpt-feed-site" value="<?php echo esc_url( $site_feed ); ?>" onclick="this.select();" />
						<a class="button button-small" href="<?php echo esc_url( $site_feed ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( rpt_t( 'preview' ) ); ?></a>
						<button type="button" class="button button-small rpt-copy" data-target="rpt-feed-site" data-copied="<?php echo esc_attr( rpt_t( 'copied' ) ); ?>"><?php echo esc_html( rpt_t( 'copy' ) ); ?></button>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html( rpt_t( 'current_types' ) ); ?></th>
					<td>
						<?php
						if ( empty( $selected ) ) {
							echo '<em>' . esc_html( rpt_t( 'none_selected' ) ) . '</em>';
						} else {
							$labels = array();
							foreach ( $selected as $slug ) {
								if ( isset( $types[ $slug ] ) ) {
									$labels[] = $types[ $slug ]->labels->name;
								} else {
									$labels[] = $slug;
								}
							}
							echo '<strong>' . esc_html( implode( '、', $labels ) ) . '</strong> <code>' . esc_html( implode( ', ', $selected ) ) . '</code>';
						}
						?>
					</td>
				</tr>
			</table>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php echo esc_html( rpt_t( 'section_types' ) ); ?></h2>
				<?php if ( empty( $types ) ) : ?>
					<p><?php echo esc_html( rpt_t( 'no_public_types' ) ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped" style="max-width:1100px;">
						<thead>
							<tr>
								<th scope="col" style="width:26%;"><?php echo esc_html( rpt_t( 'col_type' ) ); ?></th>
								<th scope="col" style="width:14%;"><?php echo esc_html( rpt_t( 'col_slug' ) ); ?></th>
								<th scope="col" style="width:8%;"><?php echo esc_html( rpt_t( 'col_count' ) ); ?></th>
								<th scope="col"><?php echo esc_html( rpt_t( 'col_feed' ) ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $types as $slug => $type ) : ?>
								<?php
								$counts    = wp_count_posts( $slug );
								$published = isset( $counts->publish ) ? (int) $counts->publish : 0;
								$url       = self::feed_url( $slug );
								$field_id  = 'rpt-feed-' . sanitize_html_class( $slug );
								?>
								<tr>
									<td>
										<label for="rpt-type-<?php echo esc_attr( sanitize_html_class( $slug ) ); ?>">
											<input type="checkbox" id="rpt-type-<?php echo esc_attr( sanitize_html_class( $slug ) ); ?>" name="rpt_settings[post_types][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $settings['post_types'], true ) ); ?> />
											<strong><?php echo esc_html( $type->labels->name ); ?></strong>
										</label>
										<span class="description">— <?php echo esc_html( ! empty( $type->_builtin ) ? rpt_t( 'builtin' ) : rpt_t( 'custom' ) ); ?></span>
									</td>
									<td><code><?php echo esc_html( $slug ); ?></code></td>
									<td><?php echo esc_html( number_format_i18n( $published ) ); ?></td>
									<td>
										<input type="text" readonly class="regular-text code" id="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_url( $url ); ?>" onclick="this.select();" />
										<a class="button button-small" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( rpt_t( 'preview' ) ); ?></a>
										<button type="button" class="button button-small rpt-copy" data-target="<?php echo esc_attr( $field_id ); ?>" data-copied="<?php echo esc_attr( rpt_t( 'copied' ) ); ?>"><?php echo esc_html( rpt_t( 'copy' ) ); ?></button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description" style="max-width:900px;"><?php echo esc_html( rpt_t( 'feed_hint' ) ); ?></p>
				<?php endif; ?>

				<h2><?php echo esc_html( rpt_t( 'section_options' ) ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html( rpt_t( 'section_options' ) ); ?></th>
						<td>
							<label for="rpt-archives">
								<input type="checkbox" id="rpt-archives" name="rpt_settings[archives]" value="1" <?php checked( $settings['archives'], 1 ); ?> />
								<?php echo esc_html( rpt_t( 'opt_archives' ) ); ?>
							</label>
							<p class="description"><?php echo esc_html( rpt_t( 'opt_archives_desc' ) ); ?></p>
							<label for="rpt-skip-front">
								<input type="checkbox" id="rpt-skip-front" name="rpt_settings[skip_front]" value="1" <?php checked( $settings['skip_front'], 1 ); ?> />
								<?php echo esc_html( rpt_t( 'opt_skip_front' ) ); ?>
							</label>
							<p class="description"><?php echo esc_html( rpt_t( 'opt_skip_front_desc' ) ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<p class="description" style="max-width:900px;">
				<?php echo esc_html( sprintf( rpt_t( 'count_hint' ), $posts_per ) ); ?><br />
				<?php echo esc_html( rpt_t( 'cache_hint' ) ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * 复制按钮的小脚本，只在本插件页面输出。
	 *
	 * @return void
	 */
	public static function copy_script() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, self::PAGE ) ) {
			return;
		}
		?>
		<script>
		( function () {
			var buttons = document.querySelectorAll( '.rpt-copy' );
			Array.prototype.forEach.call( buttons, function ( button ) {
				button.addEventListener( 'click', function () {
					var input = document.getElementById( button.getAttribute( 'data-target' ) );
					if ( ! input ) {
						return;
					}
					var label = button.textContent;
					var done  = function () {
						button.textContent = button.getAttribute( 'data-copied' ) || label;
						window.setTimeout( function () {
							button.textContent = label;
						}, 1500 );
					};
					input.removeAttribute( 'readonly' );
					input.select();
					input.setSelectionRange( 0, 99999 );
					input.setAttribute( 'readonly', 'readonly' );
					if ( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText( input.value ).then( done, function () {
							try {
								document.execCommand( 'copy' );
								done();
							} catch ( e ) {}
						} );
					} else {
						try {
							document.execCommand( 'copy' );
							done();
						} catch ( e ) {}
					}
				} );
			} );
		}() );
		</script>
		<?php
	}
}
