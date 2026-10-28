<?php
/**
 * 颜色工具：把主题色调整到可访问的对比度。
 *
 * 主题色由封面动态提取，可能是很浅的蓝（本项目为 #a4cdf6），
 * 直接白字压在上面只有 1.7:1。这里按 WCAG 计算，必要时压暗主题色，
 * 或改成深色前景，让按钮/标签在浅色与深色模式下都至少 4.5:1。
 *
 * @package Post_Subscribe_Notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 颜色计算。
 */
class PSN_Color {

	/**
	 * 正文级对比度阈值（WCAG AA）。
	 */
	const AA = 4.5;

	/**
	 * 主题的主色调（与邮件模板共用同一取值规则）。
	 *
	 * @return string
	 */
	public static function theme_accent() {
		$color = '#7a8cff';

		$theme = get_option( 'iro_options' );
		if ( is_array( $theme ) && ! empty( $theme['theme_skin_matching'] ) ) {
			$candidate = (string) $theme['theme_skin_matching'];
			if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $candidate ) ) {
				$color = $candidate;
			}
		}

		return self::normalize_hex( $color );
	}

	/**
	 * 把任意 hex 规范成 #rrggbb（丢弃 alpha）。
	 *
	 * @param string $hex 输入.
	 * @return string
	 */
	public static function normalize_hex( $hex ) {
		$hex = ltrim( (string) $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( strlen( $hex ) > 6 ) {
			$hex = substr( $hex, 0, 6 );
		}

		return preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ? '#' . strtolower( $hex ) : '#7a8cff';
	}

	/**
	 * hex 转 [r,g,b]（0–255）。
	 *
	 * @param string $hex 颜色.
	 * @return int[]
	 */
	public static function to_rgb( $hex ) {
		$hex = ltrim( self::normalize_hex( $hex ), '#' );

		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * 相对亮度（WCAG 2.x）。
	 *
	 * @param int[] $rgb RGB.
	 * @return float
	 */
	public static function luminance( $rgb ) {
		$channel = static function ( $value ) {
			$value /= 255;

			return $value <= 0.03928 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
		};

		return 0.2126 * $channel( $rgb[0] ) + 0.7152 * $channel( $rgb[1] ) + 0.0722 * $channel( $rgb[2] );
	}

	/**
	 * 两色对比度。
	 *
	 * @param string $a 颜色.
	 * @param string $b 颜色.
	 * @return float
	 */
	public static function contrast( $a, $b ) {
		$la = self::luminance( self::to_rgb( $a ) );
		$lb = self::luminance( self::to_rgb( $b ) );

		$light = max( $la, $lb );
		$dark  = min( $la, $lb );

		return ( $light + 0.05 ) / ( $dark + 0.05 );
	}

	/**
	 * 逐步压暗颜色直到满足对比度；已经是深色时改为提亮。
	 *
	 * @param string $hex   起始颜色.
	 * @param string $bg    背景色.
	 * @param float  $target 目标对比度.
	 * @return string
	 */
	public static function ensure_contrast( $hex, $bg = '#ffffff', $target = self::AA ) {
		$hex = self::normalize_hex( $hex );
		$rgb = self::to_rgb( $hex );
		$lum = self::luminance( $rgb );

		$mix_to = $lum > 0.5 ? array( 0, 0, 0 ) : array( 255, 255, 255 );

		// 每次往白/黑挪 4%，最多 30 步（120%），保证一定能收敛。
		for ( $step = 0; $step <= 30; $step++ ) {
			$ratio = $step * 0.04;

			$candidate = array(
				(int) round( $rgb[0] + ( $mix_to[0] - $rgb[0] ) * $ratio ),
				(int) round( $rgb[1] + ( $mix_to[1] - $rgb[1] ) * $ratio ),
				(int) round( $rgb[2] + ( $mix_to[2] - $rgb[2] ) * $ratio ),
			);

			$candidate_hex = sprintf( '#%02x%02x%02x', $candidate[0], $candidate[1], $candidate[2] );

			if ( self::contrast( $candidate_hex, $bg ) >= $target ) {
				return $candidate_hex;
			}
		}

		// 极端情况（例如背景本身是中灰）退到纯黑/纯白。
		return $lum > 0.5 ? '#000000' : '#ffffff';
	}

	/**
	 * 在给定背景下选择可读的前景色（白或近黑）。
	 *
	 * @param string $bg     背景色.
	 * @param float  $target 目标对比度.
	 * @return string
	 */
	public static function foreground( $bg, $target = self::AA ) {
		$white = self::contrast( '#ffffff', $bg );
		$black = self::contrast( '#222222', $bg );

		if ( max( $white, $black ) >= $target ) {
			return $white >= $black ? '#ffffff' : '#222222';
		}

		return $white > $black ? '#ffffff' : '#222222';
	}

	/**
	 * 调整背景，直到深/浅两种前景里至少一种达到对比度。
	 *
	 * 优先只换前景色：主题色常常是很浅的蓝（#a4cdf6），配深色文字既保留品牌色
	 * 又满足 4.5:1。只有真的落在中灰区间、两种前景都不够时，才把背景往黑或白推。
	 *
	 * @param string $bg     原始背景色.
	 * @param float  $target 目标对比度.
	 * @return array{bg:string,fg:string}
	 */
	public static function readable_pair( $bg, $target = self::AA ) {
		$bg      = self::normalize_hex( $bg );
		$white   = self::contrast( '#ffffff', $bg );
		$black   = self::contrast( '#222222', $bg );
		$best    = max( $white, $black );

		if ( $best >= $target ) {
			return array(
				'bg' => $bg,
				'fg' => $white >= $black ? '#ffffff' : '#222222',
			);
		}

		// 中灰区间：往更亮或更暗的方向推，选需要位移更小的一侧。
		$lum  = self::luminance( self::to_rgb( $bg ) );
		$to   = $lum >= 0.5 ? array( 0, 0, 0 ) : array( 255, 255, 255 );
		$rgb  = self::to_rgb( $bg );
		$fg   = $lum >= 0.5 ? '#ffffff' : '#222222';

		for ( $step = 1; $step <= 30; $step++ ) {
			$ratio = $step * 0.04;

			$candidate = sprintf(
				'#%02x%02x%02x',
				(int) round( $rgb[0] + ( $to[0] - $rgb[0] ) * $ratio ),
				(int) round( $rgb[1] + ( $to[1] - $rgb[1] ) * $ratio ),
				(int) round( $rgb[2] + ( $to[2] - $rgb[2] ) * $ratio )
			);

			if ( self::contrast( $fg, $candidate ) >= $target ) {
				return array(
					'bg' => $candidate,
					'fg' => $fg,
				);
			}
		}

		return array(
			'bg' => $lum >= 0.5 ? '#000000' : '#ffffff',
			'fg' => $lum >= 0.5 ? '#ffffff' : '#222222',
		);
	}

	/**
	 * 供卡片使用的三个颜色：按钮底色、按钮前景、焦点环颜色。
	 *
	 * @param string $accent 主题色.
	 * @return array{accent:string,on_accent:string,ring:string}
	 */
	public static function button_palette( $accent = '' ) {
		$accent = '' !== $accent ? self::normalize_hex( $accent ) : self::theme_accent();
		$pair   = self::readable_pair( $accent );

		/**
		 * 过滤按钮配色。
		 *
		 * @param array  $palette 配色.
		 * @param string $accent  原始主题色.
		 */
		return apply_filters(
			'psn_button_palette',
			array(
				'accent'    => $pair['bg'],
				'on_accent' => $pair['fg'],
				'ring'      => self::rgba( $pair['bg'], 0.32 ),
			),
			$accent
		);
	}

	/**
	 * hex 转 rgba() 字符串。
	 *
	 * @param string $hex   颜色.
	 * @param float  $alpha 透明度.
	 * @return string
	 */
	public static function rgba( $hex, $alpha ) {
		$rgb = self::to_rgb( $hex );

		return sprintf( 'rgba(%d, %d, %d, %s)', $rgb[0], $rgb[1], $rgb[2], rtrim( rtrim( sprintf( '%.2f', $alpha ), '0' ), '.' ) );
	}
}
