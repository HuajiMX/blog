<?php
/**
 * Server-side rendering for the Blogcard block
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// 属性の取得
$url            = isset( $attributes['url'] ) ? $attributes['url'] : '';
$title          = isset( $attributes['title'] ) ? $attributes['title'] : '';
$description    = isset( $attributes['description'] ) ? $attributes['description'] : '';
$thumbnail_url  = isset( $attributes['thumbnailUrl'] ) ? $attributes['thumbnailUrl'] : '';
$thumbnail_id   = isset( $attributes['thumbnailId'] ) ? $attributes['thumbnailId'] : 0;
$show_thumbnail = isset( $attributes['showThumbnail'] ) ? $attributes['showThumbnail'] : false;
$favicon        = isset( $attributes['favicon'] ) ? $attributes['favicon'] : '';
$target         = isset( $attributes['target'] ) ? $attributes['target'] : '_blank';
$noopener       = isset( $attributes['noopener'] ) ? $attributes['noopener'] : true;
$nofollow       = isset( $attributes['nofollow'] ) ? $attributes['nofollow'] : false;
$noreferrer     = isset( $attributes['noreferrer'] ) ? $attributes['noreferrer'] : false;
$sponsored      = isset( $attributes['sponsored'] ) ? $attributes['sponsored'] : false;
$ugc            = isset( $attributes['ugc'] ) ? $attributes['ugc'] : false;

// URLがない場合は何も出力しない
if ( empty( $url ) ) {
	return;
}

// rel属性を動的に構築
$rel_attributes = array();
if ( $noopener ) {
	$rel_attributes[] = 'noopener';
}
if ( $noreferrer ) {
	$rel_attributes[] = 'noreferrer';
}
if ( $nofollow ) {
	$rel_attributes[] = 'nofollow';
}
if ( $sponsored ) {
	$rel_attributes[] = 'sponsored';
}
if ( $ugc ) {
	$rel_attributes[] = 'ugc';
}

$rel = ! empty( $rel_attributes ) ? implode( ' ', $rel_attributes ) : '';

// サムネイル表示判定
$display_thumbnail = $thumbnail_url;

// 内部サイトの Home URL かつ thumbnailUrl が空の場合は、サイト共通の OG Image を取得
if ( empty( $display_thumbnail ) && function_exists( 'wpbc_is_internal_url' ) && wpbc_is_internal_url( $url ) ) {
	$normalized_url = rtrim( $url, '/' );
	$home_url       = rtrim( home_url(), '/' );
	$site_url       = rtrim( site_url(), '/' );
	if ( $normalized_url === $home_url || $normalized_url === $site_url ) {
		$display_thumbnail = wpbc_get_site_og_image();
	}
}

$should_show_thumbnail = $show_thumbnail && ! empty( $display_thumbnail );

// ドメイン取得
$domain     = '';
$parsed_url = parse_url( $url );
if ( isset( $parsed_url['host'] ) ) {
	$domain = $parsed_url['host'];
}

// Faviconのフォールバック
if ( empty( $favicon ) && ! empty( $domain ) ) {
	// 内部サイトの場合はサイト共通のファビコンを取得
	if ( function_exists( 'wpbc_is_internal_url' ) && wpbc_is_internal_url( $url ) ) {
		$favicon = wpbc_get_site_favicon();
	}

	// それでも空の場合は Google Favicon API を使用（外部サイトなど）
	if ( empty( $favicon ) ) {
		$favicon = 'https://www.google.com/s2/favicons?domain=' . urlencode( $domain ) . '&sz=16';
	}
}

// HTML出力
$wrapper_attributes = get_block_wrapper_attributes();

?>
<div <?php echo $wrapper_attributes; ?>>
	<article class="wp-blogcard" cite="<?php echo esc_url( $url ); ?>">
		<a
			href="<?php echo esc_url( $url ); ?>"
			aria-label="<?php echo esc_attr( $title ); ?>"
			<?php if ( ! empty( $target ) ) : ?>
				target="<?php echo esc_attr( $target ); ?>"
			<?php endif; ?>
			<?php if ( ! empty( $rel ) ) : ?>
				rel="<?php echo esc_attr( $rel ); ?>"
			<?php endif; ?>
			class="wp-blogcard-item"
		>
			<?php if ( $should_show_thumbnail ) : ?>
				<figure class="wp-blogcard-figure">
					<img src="<?php echo esc_url( $display_thumbnail ); ?>" alt="" aria-hidden="true" />
				</figure>
			<?php endif; ?>
			<div class="wp-blogcard-content">
				<div class="wp-blogcard-title"><?php echo esc_html( $title ); ?></div>
				<div class="wp-blogcard-description"><?php echo esc_html( $description ); ?></div>
				<div class="wp-blogcard-cite">
					<?php if ( ! empty( $favicon ) ) : ?>
						<img
							class="wp-blogcard-favicon"
							src="<?php echo esc_url( $favicon ); ?>"
							alt=""
							aria-hidden="true"
						/>
					<?php endif; ?>
					<div class="wp-blogcard-domain"><?php echo esc_html( $domain ); ?></div>
				</div>
			</div>
		</a>
	</article>
</div>
