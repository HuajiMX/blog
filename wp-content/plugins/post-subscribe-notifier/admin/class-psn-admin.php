<?php
/**
 * 后台菜单与设置页。
 *
 * @package Post_Subscribe_Notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 后台入口。
 */
class PSN_Admin {

	const PAGE_SETTINGS    = 'psn-settings';
	const PAGE_SUBSCRIBERS = 'psn-subscribers';
	const PAGE_JOBS        = 'psn-jobs';
	const GROUP            = 'psn_settings_group';

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public static function init() {
		require_once PSN_DIR . 'admin/class-psn-actions.php';

		PSN_Actions::init();

		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PSN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * 顶级菜单 + 三个子页。
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_menu_page(
			psn_t( 'menu_title' ),
			psn_t( 'menu_title' ),
			'manage_options',
			self::PAGE_SETTINGS,
			array( __CLASS__, 'render_settings' ),
			'dashicons-email-alt',
			58
		);

		add_submenu_page(
			self::PAGE_SETTINGS,
			psn_t( 'page_settings' ),
			psn_t( 'page_settings' ),
			'manage_options',
			self::PAGE_SETTINGS,
			array( __CLASS__, 'render_settings' )
		);

		add_submenu_page(
			self::PAGE_SETTINGS,
			psn_t( 'page_subscribers' ),
			psn_t( 'page_subscribers' ),
			'manage_options',
			self::PAGE_SUBSCRIBERS,
			array( __CLASS__, 'render_subscribers' )
		);

		add_submenu_page(
			self::PAGE_SETTINGS,
			psn_t( 'page_jobs' ),
			psn_t( 'page_jobs' ),
			'manage_options',
			self::PAGE_JOBS,
			array( __CLASS__, 'render_jobs' )
		);
	}

	/**
	 * 插件列表里的「设置」链接。
	 *
	 * @param string[] $links 已有链接.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SETTINGS );

		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html( psn_t( 'settings_link' ) ) . '</a>' );

		return $links;
	}

	/**
	 * 注册设置项。
	 *
	 * @return void
	 */
	public static function register_setting() {
		register_setting(
			self::GROUP,
			PSN_OPTION,
			array(
				'type'              => 'array',
				'description'       => psn_t( 'menu_title' ),
				'sanitize_callback' => array( 'PSN_Settings', 'sanitize' ),
				'default'           => PSN_Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * 设置页。
	 *
	 * @return void
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = PSN_Settings::get();
		$types    = PSN_Settings::available_post_types();
		$pending  = PSN_Queue::count( PSN_Queue::TYPE_MAIL );
		$queued   = PSN_Queue::count( PSN_Queue::TYPE_WEBHOOK );
		$today    = PSN_Queue::sent_today();

		?>
		<div class="wrap psn-wrap">
			<h1><?php echo esc_html( psn_t( 'menu_title' ) ); ?></h1>

			<?php PSN_Diagnostics::render_notice(); ?>
			<?php settings_errors( PSN_OPTION ); ?>

			<p class="description" style="max-width:820px;">
				<?php
				echo esc_html(
					sprintf(
						'当前待发邮件 %1$d 封、待推 WebHook %2$d 条；今日已发 %3$d 封（上限 %4$s）。',
						$pending,
						$queued,
						$today,
						(int) $settings['daily_cap'] > 0 ? (string) (int) $settings['daily_cap'] : '不限'
					)
				);
				?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php echo esc_html( psn_t( 'section_general' ) ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html( psn_t( 'section_general' ) ); ?></th>
						<td>
							<label for="psn-mail-enabled">
								<input type="checkbox" id="psn-mail-enabled" name="psn_settings[mail_enabled]" value="1" <?php checked( $settings['mail_enabled'], 1 ); ?> />
								<?php echo esc_html( psn_t( 'opt_mail_enabled' ) ); ?>
							</label>
							<p class="description"><?php echo esc_html( psn_t( 'opt_mail_enabled_desc' ) ); ?></p>

							<label for="psn-webhook-enabled">
								<input type="checkbox" id="psn-webhook-enabled" name="psn_settings[webhook_enabled]" value="1" <?php checked( $settings['webhook_enabled'], 1 ); ?> />
								<?php echo esc_html( psn_t( 'opt_webhook_enabled' ) ); ?>
							</label>
							<p class="description"><?php echo esc_html( psn_t( 'opt_webhook_enabled_desc' ) ); ?></p>

							<label for="psn-delete-data">
								<input type="checkbox" id="psn-delete-data" name="psn_settings[delete_data]" value="1" <?php checked( $settings['delete_data'], 1 ); ?> />
								<?php echo esc_html( psn_t( 'opt_delete_data' ) ); ?>
							</label>
							<p class="description"><?php echo esc_html( psn_t( 'opt_delete_data_desc' ) ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html( psn_t( 'section_mail' ) ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html( psn_t( 'field_post_types' ) ); ?></th>
						<td>
							<?php foreach ( $types as $slug ) : ?>
								<?php
								$object = get_post_type_object( $slug );
								$label  = $object ? $object->labels->name : $slug;
								?>
								<label style="display:inline-block;margin-right:16px;">
									<input type="checkbox" name="psn_settings[post_types][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $settings['post_types'], true ) ); ?> />
									<?php echo esc_html( $label ); ?> <code><?php echo esc_html( $slug ); ?></code>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php echo esc_html( psn_t( 'field_post_types_desc' ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="psn-from-name"><?php echo esc_html( psn_t( 'field_from_name' ) ); ?></label></th>
						<td><input type="text" class="regular-text" id="psn-from-name" name="psn_settings[from_name]" value="<?php echo esc_attr( $settings['from_name'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="psn-from-email"><?php echo esc_html( psn_t( 'field_from_email' ) ); ?></label></th>
						<td>
							<input type="email" class="regular-text" id="psn-from-email" name="psn_settings[from_email]" value="<?php echo esc_attr( $settings['from_email'] ); ?>" />
							<p class="description"><?php echo esc_html( psn_t( 'field_from_email_desc' ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="psn-reply-to"><?php echo esc_html( psn_t( 'field_reply_to' ) ); ?></label></th>
						<td><input type="email" class="regular-text" id="psn-reply-to" name="psn_settings[reply_to]" value="<?php echo esc_attr( $settings['reply_to'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="psn-subject"><?php echo esc_html( psn_t( 'field_subject' ) ); ?></label></th>
						<td>
							<input type="text" class="large-text" id="psn-subject" name="psn_settings[subject_tpl]" value="<?php echo esc_attr( $settings['subject_tpl'] ); ?>" />
							<p class="description"><?php echo esc_html( psn_t( 'placeholder_subject' ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="psn-intro"><?php echo esc_html( psn_t( 'field_intro' ) ); ?></label></th>
						<td><textarea class="large-text" rows="2" id="psn-intro" name="psn_settings[intro]"><?php echo esc_textarea( $settings['intro'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html( psn_t( 'field_include_excerpt' ) ); ?></th>
						<td>
							<label><input type="checkbox" name="psn_settings[include_excerpt]" value="1" <?php checked( $settings['include_excerpt'], 1 ); ?> /> <?php echo esc_html( psn_t( 'field_include_excerpt' ) ); ?></label>
							&nbsp;&nbsp;
							<label><input type="checkbox" name="psn_settings[include_cover]" value="1" <?php checked( $settings['include_cover'], 1 ); ?> /> <?php echo esc_html( psn_t( 'field_include_cover' ) ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="psn-batch"><?php echo esc_html( psn_t( 'field_batch' ) ); ?></label></th>
						<td>
							<input type="number" min="1" max="100" id="psn-batch" name="psn_settings[batch]" value="<?php echo esc_attr( (string) $settings['batch'] ); ?>" class="small-text" />
							<p class="description"><?php echo esc_html( psn_t( 'field_batch_desc' ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="psn-interval"><?php echo esc_html( psn_t( 'field_interval' ) ); ?></label></th>
						<td>
							<input type="number" min="0" max="2000" step="50" id="psn-interval" name="psn_settings[interval]" value="<?php echo esc_attr( (string) $settings['interval'] ); ?>" class="small-text" />
							<p class="description"><?php echo esc_html( psn_t( 'field_interval_desc' ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="psn-daily-cap"><?php echo esc_html( psn_t( 'field_daily_cap' ) ); ?></label></th>
						<td>
							<input type="number" min="0" max="10000" id="psn-daily-cap" name="psn_settings[daily_cap]" value="<?php echo esc_attr( (string) $settings['daily_cap'] ); ?>" class="small-text" />
							<p class="description"><?php echo esc_html( psn_t( 'field_daily_cap_desc' ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html( psn_t( 'field_republish' ) ); ?></th>
						<td><label><input type="checkbox" name="psn_settings[republish]" value="1" <?php checked( $settings['republish'], 1 ); ?> /> <?php echo esc_html( psn_t( 'field_republish' ) ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="psn-confirm-ttl"><?php echo esc_html( psn_t( 'field_confirm_ttl' ) ); ?></label></th>
						<td>
							<input type="number" min="1" max="720" id="psn-confirm-ttl" name="psn_settings[confirm_ttl]" value="<?php echo esc_attr( (string) $settings['confirm_ttl'] ); ?>" class="small-text" />
							<p class="description"><?php echo esc_html( psn_t( 'field_confirm_ttl_desc' ) ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html( psn_t( 'section_webhook' ) ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="psn-webhook-url"><?php echo esc_html( psn_t( 'field_webhook_url' ) ); ?></label></th>
						<td>
							<input type="url" class="large-text code" id="psn-webhook-url" name="psn_settings[webhook_url]" value="<?php echo esc_attr( $settings['webhook_url'] ); ?>" placeholder="http://127.0.0.1:8899/hook" />
							<p class="description"><?php echo esc_html( psn_t( 'field_webhook_url_desc' ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="psn-webhook-secret"><?php echo esc_html( psn_t( 'field_webhook_secret' ) ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="psn-webhook-secret" name="psn_settings[webhook_secret]" value="<?php echo esc_attr( $settings['webhook_secret'] ); ?>" autocomplete="off" />
							<p class="description"><?php echo esc_html( psn_t( 'field_webhook_secret_desc' ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="psn-webhook-headers"><?php echo esc_html( psn_t( 'field_webhook_headers' ) ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="2" id="psn-webhook-headers" name="psn_settings[webhook_headers]"><?php echo esc_textarea( $settings['webhook_headers'] ); ?></textarea>
							<p class="description"><?php echo esc_html( psn_t( 'field_webhook_headers_desc' ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html( psn_t( 'field_webhook_timeout' ) ); ?></th>
						<td>
							<input type="number" min="1" max="15" name="psn_settings[webhook_timeout]" value="<?php echo esc_attr( (string) $settings['webhook_timeout'] ); ?>" class="small-text" /> <?php echo esc_html( psn_t( 'field_webhook_timeout' ) ); ?>
							&nbsp;&nbsp;
							<label for="psn-webhook-retries"><?php echo esc_html( psn_t( 'field_webhook_retries' ) ); ?></label>
							<input type="number" min="0" max="5" id="psn-webhook-retries" name="psn_settings[webhook_retries]" value="<?php echo esc_attr( (string) $settings['webhook_retries'] ); ?>" class="small-text" />
							<p class="description"><?php echo esc_html( psn_t( 'field_webhook_retries_desc' ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html( psn_t( 'section_webhook' ) ); ?></th>
						<td>
							<label for="psn-notify-pending">
								<input type="checkbox" id="psn-notify-pending" name="psn_settings[notify_pending]" value="1" <?php checked( $settings['notify_pending'], 1 ); ?> />
								<?php echo esc_html( psn_t( 'field_notify_pending' ) ); ?>
							</label>
							<p class="description"><?php echo esc_html( psn_t( 'field_notify_pending_desc' ) ); ?></p>

							<label for="psn-include-paragraph">
								<input type="checkbox" id="psn-include-paragraph" name="psn_settings[include_paragraph]" value="1" <?php checked( $settings['include_paragraph'], 1 ); ?> />
								<?php echo esc_html( psn_t( 'field_include_paragraph' ) ); ?>
							</label>
							<br />
							<label for="psn-include-shuoshuo">
								<input type="checkbox" id="psn-include-shuoshuo" name="psn_settings[include_shuoshuo]" value="1" <?php checked( $settings['include_shuoshuo'], 1 ); ?> />
								<?php echo esc_html( psn_t( 'field_include_shuoshuo' ) ); ?>
							</label>
							<br />
							<label for="psn-notify-self">
								<input type="checkbox" id="psn-notify-self" name="psn_settings[notify_self]" value="1" <?php checked( $settings['notify_self'], 1 ); ?> />
								<?php echo esc_html( psn_t( 'field_notify_self' ) ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="psn-summary"><?php echo esc_html( psn_t( 'field_summary_tpl' ) ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="2" id="psn-summary" name="psn_settings[summary_tpl]"><?php echo esc_textarea( $settings['summary_tpl'] ); ?></textarea>
							<p class="description"><?php echo esc_html( psn_t( 'placeholder_summary' ) ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( psn_t( 'saved' ) ); ?>
			</form>

			<hr />
			<h2><?php echo esc_html( psn_t( 'section_test' ) ); ?></h2>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( PSN_Diagnostics::action_url( PSN_Diagnostics::ACTION_MAIL ) ); ?>"><?php echo esc_html( psn_t( 'test_mail_btn' ) ); ?></a>
				<span class="description"><?php echo esc_html( psn_t( 'test_mail_hint' ) ); ?></span>
			</p>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( PSN_Diagnostics::action_url( PSN_Diagnostics::ACTION_WEBHOOK ) ); ?>"><?php echo esc_html( psn_t( 'test_webhook_btn' ) ); ?></a>
				<span class="description"><?php echo esc_html( psn_t( 'test_webhook_hint' ) ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * 订阅者列表页。
	 *
	 * @return void
	 */
	public static function render_subscribers() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		require_once PSN_DIR . 'admin/class-psn-subscribers-table.php';

		$table = new PSN_Subscribers_Table();
		$table->prepare_items();
		?>
		<div class="wrap psn-wrap">
			<h1><?php echo esc_html( psn_t( 'page_subscribers' ) ); ?></h1>
			<?php $table->render_notices(); ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SUBSCRIBERS ); ?>" />
				<?php $table->views(); ?>
				<?php $table->search_box( '搜索邮箱/昵称', 'psn-search' ); ?>
			</form>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SUBSCRIBERS ); ?>" />
				<?php $table->display(); ?>
			</form>
			<p>
				<a class="button" href="<?php echo esc_url( PSN_Subscribers_Table::export_url() ); ?>"><?php echo esc_html( psn_t( 'export_csv' ) ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * 推送日志页。
	 *
	 * @return void
	 */
	public static function render_jobs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		require_once PSN_DIR . 'admin/class-psn-jobs-table.php';

		$table = new PSN_Jobs_Table();
		$table->prepare_items();
		?>
		<div class="wrap psn-wrap">
			<h1><?php echo esc_html( psn_t( 'page_jobs' ) ); ?></h1>
			<?php $table->render_notices(); ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_JOBS ); ?>" />
				<?php $table->views(); ?>
			</form>
			<?php $table->display(); ?>
		</div>
		<?php
	}
}
