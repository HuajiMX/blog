<?php
/**
 * 過去の lito/blogcard から su/blogcard へのデータ移行処理まとめ
 *
 * ※移行機能は完了したため、このファイルはメイン処理から読み込まれておらず無効化されています。
 * 移行コードの履歴・バックアップとして残しています。
 */

// 直接アクセスを防ぐ
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =========================================================================
 * 1. REST API の登録 (blogcard-for-wp.php の wpbc_rest_api_init 内に記述されていたもの)
 * =========================================================================
	register_rest_route(
		'wpbc/v1',
		'/migrate',
		array(
			'methods'             => 'POST',
			'callback'            => 'wpbc_migrate_lito_to_su',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'action' => array(
					'required' => true,
					'type'     => 'string',
					'enum'     => array( 'count', 'process' ),
				),
			),
		)
	);
*/

/* =========================================================================
 * 2. 設定画面へのセクション追加 (includes/admin-settings.php の wpbc_register_settings 内に記述されていたもの)
 * =========================================================================
	// データ移行セクションを追加
	add_settings_section(
		'wpbc_migration_section',
		'データ移行',
		'wpbc_migration_section_callback',
		'wpbc-settings'
	);
*/

/* =========================================================================
 * 3. 実際の処理関数群
 * ========================================================================= */

/**
 * データ移行セクションのコールバック
 */
function wpbc_migration_section_callback() {
	?>
	<p>過去の <code>lito/blogcard</code> ブロックを新しい <code>su/blogcard</code> に一括で変換します。<br>
	<strong style="color: #d63638;">※ 念のため、実行前にデータベースのバックアップを取ることを推奨します。</strong></p>

	<div class="wpbc-migration-area" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin-top: 15px; max-width: 600px;">
		<p id="wpbc-migration-status">移行対象の確認をしています...</p>
		
		<div id="wpbc-migration-progress-wrap" style="display: none; width: 100%; background: #f0f0f1; border-radius: 3px; margin: 10px 0;">
			<div id="wpbc-migration-progress-bar" style="height: 20px; width: 0%; background: #2271b1; border-radius: 3px; transition: width 0.3s; text-align: center; color: #fff; line-height: 20px; font-size: 12px;">0%</div>
		</div>

		<button type="button" class="button button-primary" id="wpbc-start-migration-btn" disabled>移行を開始する</button>
	</div>

	<script>
	document.addEventListener('DOMContentLoaded', function() {
		const statusText = document.getElementById('wpbc-migration-status');
		const startBtn = document.getElementById('wpbc-start-migration-btn');
		const progressWrap = document.getElementById('wpbc-migration-progress-wrap');
		const progressBar = document.getElementById('wpbc-migration-progress-bar');
		
		let totalItems = 0;
		let processedItems = 0;
		const nonce = '<?php echo wp_create_nonce( 'wp_rest' ); ?>';
		const apiRoot = '<?php echo esc_url_raw( rest_url() ); ?>';

		// 初期ロード時に件数を取得
		fetch(apiRoot + 'wpbc/v1/migrate', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce
			},
			body: JSON.stringify({ action: 'count' })
		})
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				totalItems = data.total;
				if (totalItems > 0) {
					statusText.innerHTML = `<strong>${totalItems}</strong> 件の <code>lito/blogcard</code> が見つかりました。`;
					startBtn.disabled = false;
				} else {
					statusText.innerHTML = '移行対象の <code>lito/blogcard</code> は見つかりませんでした。（すべて最新です）';
				}
			} else {
				statusText.innerHTML = '<span style="color: #d63638;">エラー: 対象件数の取得に失敗しました。</span>';
			}
		})
		.catch(err => {
			console.error(err);
			statusText.innerHTML = '<span style="color: #d63638;">通信エラーが発生しました。</span>';
		});

		// 移行開始ボタンクリック
		startBtn.addEventListener('click', function() {
			if (!confirm('移行を開始します。よろしいですか？\n※完了するまでこのページを閉じないでください。')) {
				return;
			}

			startBtn.disabled = true;
			progressWrap.style.display = 'block';
			processBatch();
		});

		// バッチ処理関数
		function processBatch() {
			statusText.innerHTML = '処理中... そのままお待ちください。';

			fetch(apiRoot + 'wpbc/v1/migrate', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce
				},
				body: JSON.stringify({ action: 'process' })
			})
			.then(response => response.json())
			.then(data => {
				if (data.success) {
					processedItems += data.processed;
					
					// 進捗率の計算
					let percent = 0;
					if (totalItems > 0) {
						percent = Math.min(100, Math.round((totalItems - data.remaining) / totalItems * 100));
					}
					
					progressBar.style.width = percent + '%';
					progressBar.textContent = percent + '%';

					if (data.remaining > 0 && data.processed > 0) {
						// まだ残っている場合は再帰的に呼び出す
						setTimeout(processBatch, 500); // サーバー負荷軽減のため0.5秒待つ
					} else {
						// 完了
						progressBar.style.width = '100%';
						progressBar.textContent = '100%';
						progressBar.style.background = '#00a32a';
						statusText.innerHTML = '<span style="color: #00a32a; font-weight: bold;">移行が完了しました！</span>';
					}
				} else {
					statusText.innerHTML = `<span style="color: #d63638;">エラー: ${data.message || '処理中にエラーが発生しました。'}</span>`;
					startBtn.disabled = false;
				}
			})
			.catch(err => {
				console.error(err);
				statusText.innerHTML = '<span style="color: #d63638;">通信エラーが発生しました。処理が中断されました。</span>';
				startBtn.disabled = false;
			});
		}
	});
	</script>
	<?php
}

/**
 * ブロックの一括置換（lito/blogcard から su/blogcard）用REST APIコールバック
 */
function wpbc_migrate_lito_to_su( $request ) {
	$action = $request->get_param( 'action' );

	// lito/blogcard を含む可能性のある投稿を検索
	$args = array(
		'post_type'      => 'any',
		'post_status'    => 'any',
		'posts_per_page' => ( 'count' === $action ) ? -1 : 10, // 処理バッチは10件ずつ
		's'              => 'wp:lito/blogcard',
		'fields'         => 'ids', // IDのみ取得して軽量化
	);

	$query = new WP_Query( $args );
	$total = $query->found_posts;

	if ( 'count' === $action ) {
		return array(
			'success' => true,
			'total'   => $total,
		);
	}

	// 実際の移行処理（バッチ処理）
	$processed = 0;
	$updated   = 0;

	if ( $query->have_posts() ) {
		foreach ( $query->posts as $post_id ) {
			$post = get_post( $post_id );
			++$processed;

			if ( strpos( $post->post_content, 'wp:lito/blogcard' ) === false ) {
				continue; // 念のためダブルチェック
			}

			// ブロックをパースして一括置換
			$blocks  = parse_blocks( $post->post_content );
			$changed = false;

			$blocks = wpbc_replace_lito_blocks_recursive( $blocks, $changed );

			if ( $changed ) {
				$new_content = serialize_blocks( $blocks );
				wp_update_post(
					array(
						'ID'           => $post->ID,
						'post_content' => wp_slash( $new_content ),
					)
				);
				++$updated;
			}
		}
	}

	return array(
		'success'   => true,
		'processed' => $processed,
		'updated'   => $updated,
		'remaining' => max( 0, $total - $processed ), // 残り件数
	);
}

/**
 * ブロック配列を再帰的に走査し、lito/blogcard を su/blogcard に置換する
 */
function wpbc_replace_lito_blocks_recursive( $blocks, &$changed ) {
	foreach ( $blocks as &$block ) {
		if ( 'lito/blogcard' === $block['blockName'] ) {
			$block['blockName'] = 'su/blogcard';

			$new_attrs = array();
			$attrs     = isset( $block['attrs'] ) ? $block['attrs'] : array();

			// 共通属性を引き継ぎ
			$copy_keys = array( 'url', 'target', 'noopener', 'nofollow', 'noreferrer', 'showThumbnail' );
			foreach ( $copy_keys as $key ) {
				if ( isset( $attrs[ $key ] ) ) {
					$new_attrs[ $key ] = $attrs[ $key ];
				}
			}

			// URLからの後方互換用： lito側には innerURL や cite 属性がない場合はurlを見る
			if ( empty( $new_attrs['url'] ) && isset( $attrs['url'] ) ) {
				$new_attrs['url'] = $attrs['url'];
			}

			// json属性を展開する
			if ( isset( $attrs['json'] ) && is_array( $attrs['json'] ) ) {
				$json = $attrs['json'];
				if ( isset( $json['title'] ) ) {
					$new_attrs['title'] = $json['title'];
				}
				if ( isset( $json['description'] ) ) {
					$new_attrs['description'] = $json['description'];
				}
				if ( ! empty( $json['thumbnail'] ) ) {
					$new_attrs['thumbnailUrl']  = $json['thumbnail'];
					$new_attrs['showThumbnail'] = true; // 画像が存在する場合はサムネイルを表示状態に
				}
			}

			// favicionなど、古い独自属性がある場合は無視するか対応するか判断（基本は自動取得される）

			$block['attrs'] = $new_attrs;

			// ServerSideRenderを使うため、innerBlocks / innerHTML を空にする
			$block['innerBlocks']  = array();
			$block['innerHTML']    = '';
			$block['innerContent'] = array();

			$changed = true;
		}

		// ネストされたブロックに対しても再帰処理
		if ( ! empty( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = wpbc_replace_lito_blocks_recursive( $block['innerBlocks'], $changed );
		}
	}

	return $blocks;
}
