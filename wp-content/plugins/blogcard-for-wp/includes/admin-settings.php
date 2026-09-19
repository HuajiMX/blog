<?php
/**
 * 管理画面の設定ページ
 */

// 直接アクセスを防ぐ
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 設定メニューを追加
 */
function wpbc_add_admin_menu() {
	add_options_page(
		'SU Blogcard',
		'SU Blogcard',
		'manage_options',
		'wpbc-settings',
		'wpbc_settings_page'
	);
}
add_action( 'admin_menu', 'wpbc_add_admin_menu' );

/**
 * 設定を登録
 */
function wpbc_register_settings() {
	register_setting( 'wpbc_settings_group', 'wpbc_settings' );

	add_settings_section(
		'wpbc_general_section',
		'一般設定',
		null,
		'wpbc-settings'
	);

	add_settings_field(
		'wpbc_external_target',
		'外部リンクのターゲット',
		'wpbc_external_target_callback',
		'wpbc-settings',
		'wpbc_general_section'
	);

	add_settings_field(
		'wpbc_internal_target',
		'内部リンクのターゲット',
		'wpbc_internal_target_callback',
		'wpbc-settings',
		'wpbc_general_section'
	);

	add_settings_field(
		'wpbc_cache_duration',
		'キャッシュ保持期間',
		'wpbc_cache_duration_callback',
		'wpbc-settings',
		'wpbc_general_section'
	);

	add_settings_field(
		'wpbc_delete_data',
		'データ削除設定',
		'wpbc_delete_data_callback',
		'wpbc-settings',
		'wpbc_general_section'
	);
}
add_action( 'admin_init', 'wpbc_register_settings' );

/**
 * 設定ページのHTMLを出力
 */
function wpbc_settings_page() {
	?>
	<div class="wrap">
		<h1>SU Blogcard</h1>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'wpbc_settings_group' );
			do_settings_sections( 'wpbc-settings' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

/**
 * 外部リンクターゲット設定フィールド
 */
function wpbc_external_target_callback() {
	$options = get_option( 'wpbc_settings' );
	$target  = isset( $options['external_target'] ) ? $options['external_target'] : '_blank';
	?>
	<select name="wpbc_settings[external_target]">
		<option value="_blank" <?php selected( $target, '_blank' ); ?>>_blank (新しいタブで開く)</option>
		<option value="_self" <?php selected( $target, '_self' ); ?>>_self (同じタブで開く)</option>
	</select>
	<p class="description">外部サイトへのリンクのデフォルトのターゲット属性を設定します。</p>
	<?php
}

/**
 * 内部リンクターゲット設定フィールド
 */
function wpbc_internal_target_callback() {
	$options = get_option( 'wpbc_settings' );
	$target  = isset( $options['internal_target'] ) ? $options['internal_target'] : '_self';
	?>
	<select name="wpbc_settings[internal_target]">
		<option value="_blank" <?php selected( $target, '_blank' ); ?>>_blank (新しいタブで開く)</option>
		<option value="_self" <?php selected( $target, '_self' ); ?>>_self (同じタブで開く)</option>
	</select>
	<p class="description">自サイト内へのリンクのデフォルトのターゲット属性を設定します。</p>
	<?php
}

/**
 * キャッシュ保持期間設定フィールド
 */
function wpbc_cache_duration_callback() {
	$options  = get_option( 'wpbc_settings' );
	$duration = isset( $options['cache_duration'] ) ? $options['cache_duration'] : 24;
	?>
	<input type="number" name="wpbc_settings[cache_duration]" value="<?php echo esc_attr( $duration ); ?>" min="1" step="1"> 時間
	<p class="description">ブログカードのメタデータ情報のキャッシュ保持期間を設定します（単位：時間）。</p>
	<?php
}

/**
 * データ削除設定フィールド
 */
function wpbc_delete_data_callback() {
	$options = get_option( 'wpbc_settings' );
	$checked = isset( $options['delete_data'] ) ? $options['delete_data'] : 0;
	?>
	<label>
		<input type="checkbox" name="wpbc_settings[delete_data]" value="1" <?php checked( $checked, 1 ); ?>>
		プラグイン削除時にすべてのデータ（設定とキャッシュ）を削除する
	</label>
	<p class="description"><strong style="color: #d63638;">注意：</strong> この設定を有効にすると、プラグインをアンインストールした際に、保存された設定とキャッシュデータがすべて削除されます。</p>
	<?php
}


