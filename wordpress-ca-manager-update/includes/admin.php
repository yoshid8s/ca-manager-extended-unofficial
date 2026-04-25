<?php
/** 管理者画面 */

namespace Profile\Admin;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-ad-menus.php';
use const Profile\Config\PROFILE_DEFAULT_CA_SERVER_HOSTNAME;
use const Profile\Config\PROFILE_DEFAULT_CA_TARGET_TYPE;
use const Profile\Config\PROFILE_DEFAULT_CA_TARGET_CSS_SELECTOR;
use const Profile\Config\PROFILE_DEFAULT_CA_TARGET_HTML;
use const Profile\Config\PROFILE_DEFAULT_CA_LOG_DIR;

/** 管理者画面の初期化 */
function init() {
	\add_action( 'admin_menu', '\Profile\Admin\add_options_page' );
	\add_action( 'admin_menu', '\Profile\Admin\add_ad_options_pages', 11 );
	\add_action( 'admin_init', '\Profile\Admin\register_settings' );
	\add_action( 'admin_post_cam_ad_slot_create', '\Profile\Admin\handle_ad_slot_create' );
	\add_action( 'admin_post_cam_ad_application_create', '\Profile\Admin\handle_ad_application_create' );
	\add_action( 'admin_post_cam_ad_slot_deactivate', '\Profile\Admin\handle_ad_slot_deactivate' );
	\add_action( 'admin_post_cam_ad_application_approve', '\Profile\Admin\handle_ad_application_approve' );
	\add_action( 'admin_post_cam_ad_application_reject', '\Profile\Admin\handle_ad_application_reject' );
	\add_action( 'admin_post_cam_ad_application_ready', '\Profile\Admin\handle_ad_application_ready' );
	\add_action( 'admin_post_cam_ad_application_issue_ca', '\Profile\Admin\handle_ad_application_issue_ca' );
	\add_action( 'admin_post_cam_ad_application_assign_post', '\Profile\Admin\handle_ad_application_assign_post' );
	\add_action(
		'admin_post_profile_ca_download_log',
		function () {
			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( '権限がありません。' );
			}
			check_admin_referer( 'profile_ca_download_log' );
			$upload_dir = wp_upload_dir();
			$log_file   = $upload_dir['basedir'] . '/' . PROFILE_DEFAULT_CA_LOG_DIR . '/ca-manager-debug.log';

			if ( ! $wp_filesystem->exists( $log_file ) ) {
				wp_die( 'ログファイルが存在しません。' );
			}

			$contents = $wp_filesystem->get_contents( $log_file );

			if ( false === $contents || null === $contents ) {
				wp_die( 'ログファイルを読み込めませんでした。' );
			}

			$contents_safe = preg_replace(
				'/[\x00-\x08\x0B\x0C\x0E-\x1F]+/',
				'',
				(string) $contents
			);

			if ( false === $contents_safe || null === $contents_safe ) {
				wp_die( 'ログの整形に失敗しました。' );
			}


			if ( function_exists( 'ob_get_level' ) ) {
				while ( ob_get_level() > 0 ) {
					ob_end_clean();
				}
			}
			nocache_headers();

			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Disposition: attachment; filename="ca-manager-debug.log"' );
			header( 'Content-Length: ' . strlen( $contents_safe ) );
			header( 'X-Content-Type-Options: nosniff' );
			echo $contents_safe; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}
	);
	\add_action(
		'update_option_profile_ca_log_option',
		function ( $old_value, $value ) {
			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}
			if ( '0' === $value ) {
				$upload_dir = wp_upload_dir();
				$log_file   = $upload_dir['basedir'] . '/' . PROFILE_DEFAULT_CA_LOG_DIR . '/ca-manager-debug.log';
				if ( $wp_filesystem->exists( $log_file ) ) {
					wp_delete_file( $log_file );
				}
			}
		},
		10,
		2
	);
}

/** 設定画面の追加 */
function add_options_page() {
	\add_options_page( 'CA Manager', 'CA Manager', 'manage_options', 'ca-manager', '\Profile\Admin\settings_page' );
}

/** 広告管理ページの追加 */
function add_ad_options_pages() {
	\add_menu_page(
		'CA広告管理',
		'CA広告管理',
		'manage_options',
		'cam-ad-main',
		'\Profile\Admin\ad_applications_page',
		'dashicons-megaphone',
		25
	);

	// ★ これを追加（親と同じスラッグ）

	\add_submenu_page(
		null,
		'広告申込詳細',
		'広告申込詳細',
		'manage_options',
		'cam-ad-application-detail',
		'\Profile\Admin\ad_application_detail_page'
	);
}

/** 設定項目の追加 */
function register_settings() {
	\register_setting( 'ca-manager', 'profile_ca_server_hostname', array( 'default' => PROFILE_DEFAULT_CA_SERVER_HOSTNAME ) );
	\register_setting( 'ca-manager', 'profile_ca_issuer_id' );
	\register_setting( 'ca-manager', 'profile_ca_server_admin_secret' );
	\register_setting( 'ca-manager', 'profile_ca_target_type', array( 'default' => PROFILE_DEFAULT_CA_TARGET_TYPE ) );
	\register_setting( 'ca-manager', 'profile_ca_target_css_selector', array( 'default' => PROFILE_DEFAULT_CA_TARGET_CSS_SELECTOR ) );
	\register_setting( 'ca-manager', 'profile_ca_target_html', array( 'default' => PROFILE_DEFAULT_CA_TARGET_HTML ) );
	\register_setting( 'ca-manager', 'profile_ca_embedded_or_external', array( 'default' => 'embedded' ) );
	\register_setting( 'ca-manager', 'profile_ca_log_option', array( 'default' => '0' ) );
	\add_settings_section( 'profile_settings', '設定', '\Profile\Admin\profile_settings_section', 'ca-manager' );
	\add_settings_field( 'profile_ca_issuer_id', 'CA issuer\'s Originator Profile ID', '\Profile\Admin\profile_ca_issuer_id_field', 'ca-manager', 'profile_settings' );
	\add_settings_field( 'profile_ca_server_hostname', 'CAサーバーホスト名', '\Profile\Admin\profile_ca_server_hostname_field', 'ca-manager', 'profile_settings' );
	\add_settings_field( 'profile_ca_server_admin_secret', '認証情報', '\Profile\Admin\profile_ca_server_admin_secret_field', 'ca-manager', 'profile_settings' );
	\add_settings_field( 'profile_ca_target_type', '検証対象の種別', '\Profile\Admin\profile_ca_target_type_field', 'ca-manager', 'profile_settings' );
	\add_settings_field( 'profile_ca_target_css_selector', '検証対象要素CSSセレクター', '\Profile\Admin\profile_ca_target_css_selector_field', 'ca-manager', 'profile_settings' );
	\add_settings_field( 'profile_ca_target_html', '検証対象要素の存在するHTML', '\Profile\Admin\profile_ca_target_html_field', 'ca-manager', 'profile_settings' );
	\add_settings_field( 'profile_ca_embedded_or_external', 'CA Presentation Type', '\Profile\Admin\profile_ca_embedded_or_external_field', 'ca-manager', 'profile_settings' );
	\add_settings_field( 'profile_ca_log_option', 'ログの出力設定', '\Profile\Admin\profile_ca_log_option_field', 'ca-manager', 'profile_settings' );
}

/** 設定画面 */
function settings_page() {
	?>
	<div class="wrap">
		<?php
		if ( isset( $_GET['cam_bulk_issue_done'] ) ) {
			$total    = isset( $_GET['total'] ) ? \absint( $_GET['total'] ) : 0;
			$success  = isset( $_GET['success'] ) ? \absint( $_GET['success'] ) : 0;
			$skipped  = isset( $_GET['skipped'] ) ? \absint( $_GET['skipped'] ) : 0;
			$failed   = isset( $_GET['failed'] ) ? \absint( $_GET['failed'] ) : 0;
			$warnings = isset( $_GET['warnings'] ) ? \absint( $_GET['warnings'] ) : 0;

			$report = array();

			if ( \function_exists( '\Profile\Issue\cam_get_bulk_article_ca_report' ) ) {
				$report = \Profile\Issue\cam_get_bulk_article_ca_report();
			}

			if ( $failed > 0 ) {
				echo '<div class="notice notice-warning is-dismissible"><p>';
				echo '記事CA一括発行を実行しました。対象 ' . \esc_html( $total ) . ' 件中、成功 ' . \esc_html( $success ) . ' 件、スキップ ' . \esc_html( $skipped ) . ' 件、失敗 ' . \esc_html( $failed ) . ' 件、注意 ' . \esc_html( $warnings ) . ' 件です。';
				echo '</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>';
				echo '記事CA一括発行を実行しました。対象 ' . \esc_html( $total ) . ' 件中、成功 ' . \esc_html( $success ) . ' 件、スキップ ' . \esc_html( $skipped ) . ' 件、失敗 0 件、注意 ' . \esc_html( $warnings ) . ' 件です。';
				echo '</p></div>';
			}

			$failed_items  = isset( $report['failed_items'] ) && \is_array( $report['failed_items'] ) ? $report['failed_items'] : array();
			$warning_items = isset( $report['warning_items'] ) && \is_array( $report['warning_items'] ) ? $report['warning_items'] : array();

			if ( ! empty( $failed_items ) ) {
				echo '<div class="notice notice-error"><p><strong>失敗ページ</strong></p><ul>';
				foreach ( $failed_items as $item ) {
					$title    = isset( $item['title'] ) ? $item['title'] : '';
					$edit_url = isset( $item['edit_url'] ) ? $item['edit_url'] : '';
					$view_url = isset( $item['view_url'] ) ? $item['view_url'] : '';
					$reason   = isset( $item['reason'] ) ? $item['reason'] : '';
					$post_id  = isset( $item['post_id'] ) ? (int) $item['post_id'] : 0;

					echo '<li>';
					echo 'ID ' . \esc_html( (string) $post_id ) . ' : ' . \esc_html( $title );
					if ( '' !== $edit_url ) {
						echo ' [<a href="' . \esc_url( $edit_url ) . '">編集</a>]';
					}
					if ( '' !== $view_url ) {
						echo ' [<a href="' . \esc_url( $view_url ) . '" target="_blank" rel="noopener noreferrer">表示</a>]';
					}
					if ( '' !== $reason ) {
						echo ' - ' . \esc_html( $reason );
					}
					echo '</li>';
				}
				echo '</ul></div>';
			}

			if ( ! empty( $warning_items ) ) {
				echo '<div class="notice notice-warning"><p><strong>成功だが検証失敗の可能性あり</strong></p><ul>';
				foreach ( $warning_items as $item ) {
					$title    = isset( $item['title'] ) ? $item['title'] : '';
					$edit_url = isset( $item['edit_url'] ) ? $item['edit_url'] : '';
					$view_url = isset( $item['view_url'] ) ? $item['view_url'] : '';
					$reason   = isset( $item['reason'] ) ? $item['reason'] : '';
					$post_id  = isset( $item['post_id'] ) ? (int) $item['post_id'] : 0;

					echo '<li>';
					echo 'ID ' . \esc_html( (string) $post_id ) . ' : ' . \esc_html( $title );
					if ( '' !== $edit_url ) {
						echo ' [<a href="' . \esc_url( $edit_url ) . '">編集</a>]';
					}
					if ( '' !== $view_url ) {
						echo ' [<a href="' . \esc_url( $view_url ) . '" target="_blank" rel="noopener noreferrer">表示</a>]';
					}
					if ( '' !== $reason ) {
						echo ' - ' . \esc_html( $reason );
					}
					echo '</li>';
				}
				echo '</ul></div>';
			}

			if ( \function_exists( '\Profile\Issue\cam_delete_bulk_article_ca_report' ) ) {
				\Profile\Issue\cam_delete_bulk_article_ca_report();
			}
		}
		?>

		<h1>CA Manager</h1>

		<form method="post" action="options.php">
			<?php \settings_fields( 'ca-manager' ); ?>
			<?php \do_settings_sections( 'ca-manager' ); ?>
			<?php \submit_button(); ?>
		</form>

		<?php cam_context_ads_settings_block(); ?>

		<hr style="margin: 32px 0;">

		<h2>記事CA一括発行</h2>
		<p>公開済みの記事・固定ページのうち、記事CAが未発行のページに対してのみ、記事CAを一括発行します。</p>

		<?php
		$cam_unissued_count = 0;

		if ( \function_exists( '\Profile\Issue\cam_get_posts_without_main_article_ca' ) ) {
			$cam_unissued_ids   = \Profile\Issue\cam_get_posts_without_main_article_ca();
			$cam_unissued_count = \is_array( $cam_unissued_ids ) ? \count( $cam_unissued_ids ) : 0;
		}
		?>

		<p>
			<strong>記事CA未発行件数:</strong>
			<?php echo \esc_html( $cam_unissued_count ); ?> 件
		</p>

		<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" style="max-width: 720px;">
			<?php \wp_nonce_field( 'cam_bulk_issue_article_ca_action', 'cam_bulk_issue_article_ca_nonce' ); ?>
			<input type="hidden" name="action" value="cam_bulk_issue_article_ca">

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="cam_bulk_editor_name">編集責任者</label>
						</th>
						<td>
							<input
								type="text"
								id="cam_bulk_editor_name"
								name="cam_bulk_editor_name"
								value=""
								class="regular-text"
								placeholder="例: Y&amp;H Inc."
							/>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="cam_bulk_author_name">執筆者</label>
						</th>
						<td>
							<input
								type="text"
								id="cam_bulk_author_name"
								name="cam_bulk_author_name"
								value=""
								class="regular-text"
								placeholder="例: Yoshifumi Takeuchi"
							/>
						</td>
					</tr>
				</tbody>
			</table>

			<?php \submit_button( '未発行の記事CAを一括発行', 'secondary', 'submit', false ); ?>
		</form>
	</div>
	<?php
}

/** 設定セクション */
function profile_settings_section() {
	?>
		<p>これらの設定が完了しないと Content Attestation (CA) の発行機能は正しく動作しません。正しく設定が反映されると、それ以降に更新した投稿と新規投稿は自動的にCAサーバーに送信されます。</p>
		<p>
			<strong>⚠️ 重要な注意事項：</strong>
			WordPressの
			<a
				href="<?php echo \esc_url( \get_admin_url( null, 'options-permalink.php' ) ); ?>"
				target="_blank"
			>
				パーマリンク設定
			</a>
			を変更すると、各記事のURLが変更されるため、既に発行済みのCAは無効となります。
			パーマリンク設定を運用開始後に変更することは避けてください。
			もしパーマリンク設定を変更した場合は、全ての投稿を再度更新（編集・保存）してCAを再発行してください。
		</p>
	<?php
}

/** CA issuer's OP IDフィールド */
function profile_ca_issuer_id_field() {
	?>
		<input
			name="profile_ca_issuer_id"
			value="<?php echo \esc_attr( \get_option( 'profile_ca_issuer_id' ) ); ?>"
			title="自身のOriginator Profile IDを入力してください (例: dns:media.example.com)"
			placeholder="dns:media.example.com"
			required
			style="width: 320px;"
		>
	<?php
}

/** CAサーバーホスト名フィールド */
function profile_ca_server_hostname_field() {
	?>
		<input
			name="profile_ca_server_hostname"
			value="<?php echo \esc_attr( \get_option( 'profile_ca_server_hostname' ) ); ?>"
			title="有効なドメイン名を入力してください (例: dprexpt.originator-profile.org)"
			placeholder="<?php echo \esc_attr( PROFILE_DEFAULT_CA_SERVER_HOSTNAME ); ?>"
			required
			style="width: 320px;"
		>
	<?php
}

/** CAサーバー認証情報フィールド */
function profile_ca_server_admin_secret_field() {
	?>
		<input
			name="profile_ca_server_admin_secret"
			value="<?php echo \esc_attr( \get_option( 'profile_ca_server_admin_secret' ) ); ?>"
			title="Content Attestation サーバーへのアクセスに必要な認証情報を入力してください"
			type="password"
			autocomplete="off"
			required
			style="width: 320px;"
		>
	<?php
}

/** 検証対象の種別フィールド */
function profile_ca_target_type_field() {
	?>
		<input
			name="profile_ca_target_type"
			value="<?php echo \esc_attr( \get_option( 'profile_ca_target_type' ) ); ?>"
			list="target_integrity_type"
			title="検証対象の種別を入力してください (例: TextTargetIntegrity)"
			placeholder="<?php echo \esc_attr( PROFILE_DEFAULT_CA_TARGET_TYPE ); ?>"
			required
			style="width: 320px;"
		>
		<datalist id="target_integrity_type">
			<option>HtmlTargetIntegrity</option>
			<option>TextTargetIntegrity</option>
			<option>VisibleTextTargetIntegrity</option>
		</datalist>
	<?php
}

/** 検証対象要素CSSセレクターフィールド */
function profile_ca_target_css_selector_field() {
	?>
		<input
			name="profile_ca_target_css_selector"
			value="<?php echo \esc_attr( \get_option( 'profile_ca_target_css_selector' ) ); ?>"
			title="CSS セレクターを入力してください"
			placeholder="<?php echo \esc_attr( PROFILE_DEFAULT_CA_TARGET_CSS_SELECTOR ); ?>"
			required
			style="width: 320px;"
		>
	<?php
}

/** 検証対象要素の存在するHTML */
function profile_ca_target_html_field() {
	?>
		<textarea
			name="profile_ca_target_html"
			title="%CONTENT% → WordPress post content after applying apply_filters()"
			style="font-family: monospace;"
			rows="6"
		><?php echo \esc_html( \get_option( 'profile_ca_target_html' ) ); ?></textarea>
	<?php
}

/** CA Presentation Type フィールド*/
function profile_ca_embedded_or_external_field() {
	$format = \get_option( 'profile_ca_embedded_or_external', 'embedded' );

	?>
	<p>
		<label for="embedded" class="radio-item">
		<input
			type="radio"
			id="embedded"
			name="profile_ca_embedded_or_external"
			value="embedded"
			title="CASをHTML内に埋め込みます"
			<?php checked( $format, 'embedded' ); ?>
		/>
		Embedded (HTML内にJSONを埋め込む)</label>
	</p>
	<p>
		<label for="external" class="radio-item">
		<input
			type="radio"
			id="external"
			name="profile_ca_embedded_or_external"
			value="external"
			title="CASをURLで参照します 選択するとJSONファイルが定数で指定したディレクトリに生成されます"
			<?php checked( $format, 'external' ); ?>
		/>
		External (URLで参照)</label>
	</p>
	<?php
}

/** ログの出力設定フィールド */
function profile_ca_log_option_field() {
	?>
	<p>
		<label for="profile_ca_log_option_false" class="radio-item">
		<input
			type="radio"
			id="profile_ca_log_option_false"
			name="profile_ca_log_option"
			value="0"
			title="ログ出力を無効にします"
			<?php checked( \get_option( 'profile_ca_log_option' ), '0' ); ?>
		/>
		無効化</label>
	</p>
	<p>
		<label for="profile_ca_log_option_true" class="radio-item">
		<input
			type="radio"
			id="profile_ca_log_option_true"
			name="profile_ca_log_option"
			value="1"
			title="ログ出力を有効にします"
			<?php checked( \get_option( 'profile_ca_log_option' ), '1' ); ?>
		/>
		有効化</label>
		<?php
		if ( \get_option( 'profile_ca_log_option' ) === '1' ) {
			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}
			$upload_dir = wp_upload_dir();
			$log_file   = $upload_dir['basedir'] . '/' . PROFILE_DEFAULT_CA_LOG_DIR . '/ca-manager-debug.log';
			if ( $wp_filesystem->exists( $log_file ) ) {
				$url = wp_nonce_url(
					admin_url( 'admin-post.php?action=profile_ca_download_log' ),
					'profile_ca_download_log'
				);
				echo '<p><a class="button button-secondary" href="' . esc_url( $url ) . '">ログをダウンロード</a></p>';
			} else {
				echo '<p>ログファイルはまだ存在しません。</p>';
			}
		}
		?>
	</p>
	<?php
}

/**
 * コンテキスト広告の保存
 */
function cam_handle_save_context_ad() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}

	check_admin_referer( 'cam_save_context_ad' );

	$ads = \get_option( 'cam_context_ads', array() );
	if ( ! \is_array( $ads ) ) {
		$ads = array();
	}

	$ad = array(
		'id'                 => isset( $_POST['cam_context_id'] ) ? sanitize_text_field( wp_unslash( $_POST['cam_context_id'] ) ) : '',
		'enabled'            => ! empty( $_POST['cam_context_enabled'] ) ? 1 : 0,
		'status'             => isset( $_POST['cam_context_status'] ) ? sanitize_text_field( wp_unslash( $_POST['cam_context_status'] ) ) : 'inactive',
		'genre'              => isset( $_POST['cam_context_genre'] ) ? sanitize_text_field( wp_unslash( $_POST['cam_context_genre'] ) ) : '',
		'advertiser'         => isset( $_POST['cam_context_advertiser'] ) ? sanitize_text_field( wp_unslash( $_POST['cam_context_advertiser'] ) ) : '',
		'bid_type'           => isset( $_POST['cam_context_bid_type'] ) ? sanitize_text_field( wp_unslash( $_POST['cam_context_bid_type'] ) ) : 'fixed',
		'bid_price'          => isset( $_POST['cam_context_bid_price'] ) ? (float) $_POST['cam_context_bid_price'] : 0,

		'start_date'         => isset( $_POST['cam_context_start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['cam_context_start_date'] ) ) : '',
		'end_date'           => isset( $_POST['cam_context_end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['cam_context_end_date'] ) ) : '',

		'top_headline'       => isset( $_POST['cam_context_top_headline'] ) ? sanitize_text_field( wp_unslash( $_POST['cam_context_top_headline'] ) ) : '',
		'top_image'          => isset( $_POST['cam_context_top_image'] ) ? esc_url_raw( wp_unslash( $_POST['cam_context_top_image'] ) ) : '',
		'top_destination'    => isset( $_POST['cam_context_top_destination'] ) ? esc_url_raw( wp_unslash( $_POST['cam_context_top_destination'] ) ) : '',

		'middle_headline'    => isset( $_POST['cam_context_middle_headline'] ) ? sanitize_text_field( wp_unslash( $_POST['cam_context_middle_headline'] ) ) : '',
		'middle_image'       => isset( $_POST['cam_context_middle_image'] ) ? esc_url_raw( wp_unslash( $_POST['cam_context_middle_image'] ) ) : '',
		'middle_destination' => isset( $_POST['cam_context_middle_destination'] ) ? esc_url_raw( wp_unslash( $_POST['cam_context_middle_destination'] ) ) : '',

		'bottom_headline'    => isset( $_POST['cam_context_bottom_headline'] ) ? sanitize_text_field( wp_unslash( $_POST['cam_context_bottom_headline'] ) ) : '',
		'bottom_image'       => isset( $_POST['cam_context_bottom_image'] ) ? esc_url_raw( wp_unslash( $_POST['cam_context_bottom_image'] ) ) : '',
		'bottom_destination' => isset( $_POST['cam_context_bottom_destination'] ) ? esc_url_raw( wp_unslash( $_POST['cam_context_bottom_destination'] ) ) : '',
	);

	$allowed_bid_types = array( 'fixed', 'cpm', 'cpc' );

	if ( ! in_array( $ad['bid_type'], $allowed_bid_types, true ) ) {
		$ad['bid_type'] = 'fixed';
	}

	if ( $ad['bid_price'] < 0 ) {
		$ad['bid_price'] = 0;
	}
	if ( '' === $ad['id'] ) {
		$ad['id'] = 'cam-context-' . wp_generate_password( 8, false, false );
	}

	$updated = false;

	foreach ( $ads as $index => $existing ) {
		$existing_id = isset( $existing['id'] ) ? (string) $existing['id'] : '';
		if ( '' !== $existing_id && $existing_id === $ad['id'] ) {
			$ads[ $index ] = $ad;
			$updated = true;
			break;
		}
	}

	if ( ! $updated ) {
		$ads[] = $ad;
	}

	\update_option( 'cam_context_ads', array_values( $ads ) );

	$redirect_url = add_query_arg(
		array(
			'page' => 'ca-manager',
			'tab'  => 'context_ads',
			'cam_context_saved' => '1',
		),
		admin_url( 'options-general.php' )
	);

	wp_safe_redirect( $redirect_url );
	exit;
}
add_action( 'admin_post_cam_save_context_ad', __NAMESPACE__ . '\\cam_handle_save_context_ad' );

/**
 * コンテキスト広告の削除
 */
function cam_handle_delete_context_ad() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}

	check_admin_referer( 'cam_delete_context_ad' );

	$delete_id = isset( $_POST['cam_context_id'] ) ? sanitize_text_field( wp_unslash( $_POST['cam_context_id'] ) ) : '';

	$ads = \get_option( 'cam_context_ads', array() );
	if ( ! \is_array( $ads ) ) {
		$ads = array();
	}

	$filtered = array();

	foreach ( $ads as $ad ) {
		$ad_id = isset( $ad['id'] ) ? (string) $ad['id'] : '';

		if ( '' !== $ad_id && $ad_id === $delete_id ) {
			continue;
		}
		$filtered[] = $ad;
	}

	\update_option( 'cam_context_ads', array_values( $filtered ) );

	$redirect_url = add_query_arg(
		array(
			'page' => 'ca-manager',
			'tab'  => 'context_ads',
			'cam_context_deleted' => '1',
		),
		admin_url( 'options-general.php' )
	);

	wp_safe_redirect( $redirect_url );
	exit;
}
add_action( 'admin_post_cam_delete_context_ad', __NAMESPACE__ . '\\cam_handle_delete_context_ad' );

function cam_context_ads_settings_block() {
	$ads = \get_option( 'cam_context_ads', array() );
	if ( ! \is_array( $ads ) ) {
		$ads = array();
	}

	$impression_stats = \get_option( 'cam_ad_impression_stats', array() );
	if ( ! \is_array( $impression_stats ) ) {
		$impression_stats = array();
	}

	$needs_update = false;

	foreach ( $ads as $index => $ad ) {
		$ad_id = isset( $ad['id'] ) ? (string) $ad['id'] : '';

	if ( '' === $ad_id ) {
		$ads[ $index ]['id'] = 'cam-context-' . ( $index + 1 ) . '-' . wp_generate_password( 6, false, false );
		$needs_update = true;
	}
	}

	if ( $needs_update ) {
		\update_option( 'cam_context_ads', array_values( $ads ) );
	}

	$edit_id = isset( $_GET['cam_edit_context_ad'] ) ? sanitize_text_field( wp_unslash( $_GET['cam_edit_context_ad'] ) ) : '';

	$item = array(
		'id'                 => '',
		'enabled'            => 1,
		'status'             => 'active',
		'genre'              => '',
		'advertiser'         => '',

		'bid_type'  => 'fixed',
		'bid_price' => 0,

		'start_date'         => '',
		'end_date'           => '',

		'top_headline'       => '',
		'top_image'          => '',
		'top_destination'    => '',

		'middle_headline'    => '',
		'middle_image'       => '',
		'middle_destination' => '',

		'bottom_headline'    => '',
		'bottom_image'       => '',
		'bottom_destination' => '',
	);

	if ( '' !== $edit_id ) {
		foreach ( $ads as $ad ) {
			$ad_id = isset( $ad['id'] ) ? (string) $ad['id'] : '';
			if ( $ad_id === $edit_id ) {
				$item = array_merge( $item, $ad );
				break;
			}
		}
	}
	?>
	<h2>コンテキスト広告設定</h2>
	<p>genre に一致した記事に対して、上・中・下の3段階で広告を表示します。</p>

	<?php if ( isset( $_GET['cam_context_saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>コンテキスト広告を保存しました。</p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['cam_context_deleted'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>コンテキスト広告を削除しました。</p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo \esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:40px;">
		<?php wp_nonce_field( 'cam_save_context_ad' ); ?>
		<input type="hidden" name="action" value="cam_save_context_ad">
		<input type="hidden" name="cam_context_id" value="<?php echo \esc_attr( $item['id'] ); ?>">

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="cam_context_enabled">有効化</label></th>
					<td>
						<input type="checkbox" id="cam_context_enabled" name="cam_context_enabled" value="1" <?php \checked( ! empty( $item['enabled'] ) ); ?>>
						genre一致時に広告を表示
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="cam_context_status">状態</label></th>
					<td>
						<select id="cam_context_status" name="cam_context_status">
							<option value="active" <?php \selected( $item['status'], 'active' ); ?>>active</option>
							<option value="inactive" <?php \selected( $item['status'], 'inactive' ); ?>>inactive</option>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="cam_context_genre">genre</label></th>
					<td>
						<select id="cam_context_genre" name="cam_context_genre">
							<option value="">未設定</option>

							<option value="suit" <?php \selected( $item['genre'], 'suit' ); ?>>fashion / suit</option>
							<option value="casual" <?php \selected( $item['genre'], 'casual' ); ?>>fashion / casual</option>
							<option value="vintage" <?php \selected( $item['genre'], 'vintage' ); ?>>fashion / vintage</option>

							<option value="japan" <?php \selected( $item['genre'], 'japan' ); ?>>travel / japan</option>
							<option value="international" <?php \selected( $item['genre'], 'international' ); ?>>travel / international</option>

							<option value="book" <?php \selected( $item['genre'], 'book' ); ?>>culture / book</option>
							<option value="movie" <?php \selected( $item['genre'], 'movie' ); ?>>culture / movie</option>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="cam_context_advertiser">広告主</label></th>
					<td>
						<input type="text" id="cam_context_advertiser" name="cam_context_advertiser" value="<?php echo \esc_attr( $item['advertiser'] ); ?>" class="regular-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cam_context_start_date">掲載開始日</label></th>
					<td>
						<input type="date" id="cam_context_start_date" name="cam_context_start_date" value="<?php echo \esc_attr( $item['start_date'] ); ?>">
						<p class="description">空欄なら開始日の制限なし</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="cam_context_end_date">掲載終了日</label></th>
					<td>
						<input type="date" id="cam_context_end_date" name="cam_context_end_date" value="<?php echo \esc_attr( $item['end_date'] ); ?>">
						<p class="description">空欄なら終了日の制限なし</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cam_context_ad_bid_type">単価種別</label></th>
					<td>
						<select name="cam_context_bid_type" id="cam_context_bid_type">
							<option value="fixed" <?php \selected( $item['bid_type'], 'fixed' ); ?>>fixed</option>
							<option value="cpc" <?php \selected( $item['bid_type'], 'cpc' ); ?>>CPC</option>
							<option value="cpm" <?php \selected( $item['bid_type'], 'cpm' ); ?>>CPM</option>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="cam_context_ad_bid_price">希望単価</label></th>
					<td>
						<input
							type="number"
							name="cam_context_bid_price"
							id="cam_context_ad_bid_price"
							value="<?php echo esc_attr( $item['bid_price'] ); ?>"
							min="0"
							step="1"
						/>
					</td>
					</tr>
			</tbody>
		</table>

		<hr>

		<h3>上段広告（ブランド認知）</h3>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="cam_context_top_headline">見出し</label></th>
					<td>
						<input type="text" id="cam_context_top_headline" name="cam_context_top_headline" value="<?php echo \esc_attr( $item['top_headline'] ); ?>" class="regular-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cam_context_top_image">画像URL</label></th>
					<td>
						<input type="url" id="cam_context_top_image" name="cam_context_top_image" value="<?php echo \esc_attr( $item['top_image'] ); ?>" class="regular-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cam_context_top_destination">遷移先URL</label></th>
					<td>
						<input type="url" id="cam_context_top_destination" name="cam_context_top_destination" value="<?php echo \esc_attr( $item['top_destination'] ); ?>" class="regular-text">
					</td>
				</tr>
			</tbody>
		</table>

		<hr>

		<h3>中段広告（キーメッセージ）</h3>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="cam_context_middle_headline">見出し</label></th>
					<td>
						<input type="text" id="cam_context_middle_headline" name="cam_context_middle_headline" value="<?php echo \esc_attr( $item['middle_headline'] ); ?>" class="regular-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cam_context_middle_image">画像URL</label></th>
					<td>
						<input type="url" id="cam_context_middle_image" name="cam_context_middle_image" value="<?php echo \esc_attr( $item['middle_image'] ); ?>" class="regular-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cam_context_middle_destination">遷移先URL</label></th>
					<td>
						<input type="url" id="cam_context_middle_destination" name="cam_context_middle_destination" value="<?php echo \esc_attr( $item['middle_destination'] ); ?>" class="regular-text">
					</td>
				</tr>
			</tbody>
		</table>

		<hr>

		<h3>下段広告（クロージング）</h3>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="cam_context_bottom_headline">見出し</label></th>
					<td>
						<input type="text" id="cam_context_bottom_headline" name="cam_context_bottom_headline" value="<?php echo \esc_attr( $item['bottom_headline'] ); ?>" class="regular-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cam_context_bottom_image">画像URL</label></th>
					<td>
						<input type="url" id="cam_context_bottom_image" name="cam_context_bottom_image" value="<?php echo \esc_attr( $item['bottom_image'] ); ?>" class="regular-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cam_context_bottom_destination">遷移先URL</label></th>
					<td>
						<input type="url" id="cam_context_bottom_destination" name="cam_context_bottom_destination" value="<?php echo \esc_attr( $item['bottom_destination'] ); ?>" class="regular-text">
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button( '' === $item['id'] ? 'コンテキスト広告を登録する' : 'コンテキスト広告を更新する' ); ?>
	</form>

	<hr>

	<h3 id="cam-context-ads">設定済み広告情報</h3>
	<p>以下は登録済みのコンテキスト広告です。</p>

	<?php if ( empty( $ads ) ) : ?>
		<p>まだ広告は登録されていません。</p>
	<?php else : ?>
		<table class="widefat striped" style="max-width: 1100px;">
			<thead>
				<tr>
					<th>ID</th>
					<th>広告主</th>
					<th>genre</th>
					<th>状態</th>
					<th>開始日</th>
					<th>終了日</th>
					<th>表示回数</th>
					<th>上</th>
					<th>中</th>
					<th>下</th>
					<th>bottom到達</th>
					<th>10秒</th>
					<th>30秒</th>
					<th>60秒</th>
					<th>最終滞在</th>
					<th>クリック</th>
					<th>CTR</th>
					<th>上クリック</th>
					<th>中クリック</th>
					<th>下クリック</th>
					<th>最終クリック</th>
					<th>最終表示</th>
					<th>上段見出し</th>
					<th>中段見出し</th>
					<th>下段見出し</th>
					<th>操作</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $ads as $ad ) : ?>
					<?php
					$ad_id = isset( $ad['id'] ) ? (string) $ad['id'] : '';

					$stat = isset( $impression_stats[ $ad_id ] ) && is_array( $impression_stats[ $ad_id ] )
						? $impression_stats[ $ad_id ]
						: array();
					
					$impressions = isset( $stat['total'] ) ? (int) $stat['total'] : 0;
					$clicks      = isset( $stat['click_total'] ) ? (int) $stat['click_total'] : 0;

					$ctr = 0;
					if ( $impressions > 0 ) {
 					   $ctr = ( $clicks / $impressions ) * 100;
					}
					?>
					<tr>
						<td><?php echo \esc_html( $ad_id ); ?></td>
						<td><?php echo \esc_html( isset( $ad['advertiser'] ) ? $ad['advertiser'] : '' ); ?></td>
						<td><?php echo \esc_html( isset( $ad['genre'] ) ? $ad['genre'] : '' ); ?></td>
						<td><?php echo \esc_html( isset( $ad['status'] ) ? $ad['status'] : '' ); ?></td>
						<td><?php echo \esc_html( isset( $ad['start_date'] ) ? $ad['start_date'] : '' ); ?></td>
						<td><?php echo \esc_html( isset( $ad['end_date'] ) ? $ad['end_date'] : '' ); ?></td>
						<td><?php echo \esc_html( isset( $stat['total'] ) ? (string) $stat['total'] : '0' ); ?></td>
						<td><?php echo \esc_html( isset( $stat['top'] ) ? (string) $stat['top'] : '0' ); ?></td>
						<td><?php echo \esc_html( isset( $stat['middle'] ) ? (string) $stat['middle'] : '0' ); ?></td>
						<td><?php echo \esc_html( isset( $stat['bottom'] ) ? (string) $stat['bottom'] : '0' ); ?></td>
						<td><?php echo \esc_html( isset( $stat['bottom_reach'] ) ? (string) $stat['bottom_reach'] : '0' ); ?></td>
						<td><?php echo \esc_html( isset( $stat['time_10'] ) ? (string) $stat['time_10'] : '0' ); ?></td>
						<td><?php echo \esc_html( isset( $stat['time_30'] ) ? (string) $stat['time_30'] : '0' ); ?></td>
						<td><?php echo \esc_html( isset( $stat['time_60'] ) ? (string) $stat['time_60'] : '0' ); ?></td>
						<td><?php echo \esc_html( isset( $stat['last_time_seen'] ) ? (string) $stat['last_time_seen'] : '' ); ?></td>
						<td><?php echo \esc_html( isset( $stat['click_total'] ) ? (string) $stat['click_total'] : '0' ); ?></td>
						<td>
						<?php

    						if ( $impressions > 0 ) {
        						echo esc_html( number_format( ($clicks / $impressions) * 100, 2 ) ) . '%';
    						} else {
        					echo '-';
    						}
						?>
						</td>	
						<td><?php echo \esc_html( isset( $stat['click_top'] ) ? (string) $stat['click_top'] : '0' ); ?></td>
						<td><?php echo \esc_html( isset( $stat['click_middle'] ) ? (string) $stat['click_middle'] : '0' ); ?></td>
						<td><?php echo \esc_html( isset( $stat['click_bottom'] ) ? (string) $stat['click_bottom'] : '0' ); ?></td>
						<td><?php echo \esc_html( isset( $stat['last_click_seen'] ) ? (string) $stat['last_click_seen'] : '' ); ?></td>
						<td><?php echo \esc_html( isset( $stat['last_seen'] ) ? (string) $stat['last_seen'] : '' ); ?></td>
						<td><?php echo \esc_html( isset( $ad['top_headline'] ) ? $ad['top_headline'] : '' ); ?></td>
						<td><?php echo \esc_html( isset( $ad['middle_headline'] ) ? $ad['middle_headline'] : '' ); ?></td>
						<td><?php echo \esc_html( isset( $ad['bottom_headline'] ) ? $ad['bottom_headline'] : '' ); ?></td>
						<td>
							<a class="button button-secondary" href="<?php echo \esc_url( add_query_arg( array(
								'page' => 'ca-manager',
								'cam_edit_context_ad' => $ad_id,
							), admin_url( 'options-general.php' ) ) ); ?>">編集</a>

							<form method="post" action="<?php echo \esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-left:8px;">
								<?php wp_nonce_field( 'cam_delete_context_ad' ); ?>
								<input type="hidden" name="action" value="cam_delete_context_ad">
								<input type="hidden" name="cam_context_id" value="<?php echo \esc_attr( $ad_id ); ?>">
								<button type="submit" class="button button-secondary" onclick="return confirm('この広告を削除しますか？');">削除</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
}

/**
 * Plugin links
 *
 * @param array $actions プラグインアクションリンク
 */
function add_action_links( array $actions ) {
	$menu_settings_url = '<a href="' . \get_admin_url( null, '/options-general.php?page=ca-manager' ) . '">設定</a>';
	$menu_auth_url     = '<a href="' . \get_home_url( null, '/cas-auth/' ) . '" target="_blank">API認証(OIDC)</a>';

	array_unshift( $actions, $menu_auth_url );
	array_unshift( $actions, $menu_settings_url );

	return $actions;
}

/** 広告枠設定ページ */
function ad_slots_page() {
	$slots   = get_ad_slots();
	$message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';
	?>
	<div class="wrap">
		<h1>広告枠設定</h1>

		<?php if ( 'created' === $message ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>広告枠を登録しました。</p>
			</div>
		<?php elseif ( 'error_required' === $message ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>枠コード・枠名・genreは必須です。</p>
			</div>
		<?php elseif ( 'error_duplicate' === $message ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>同じ枠コードが既に存在します。別の枠コードを入力してください。</p>
			</div>
		<?php elseif ( 'error_db' === $message ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>DB保存時にエラーが発生しました。</p>
			</div>
		<?php elseif ( 'deactivated' === $message ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>広告枠を無効化しました。</p>
			</div>
		<?php elseif ( 'error_invalid_slot' === $message ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>対象の広告枠が不正です。</p>
			</div>
		<?php endif; ?>

		<h2>新規広告枠登録</h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cam_ad_slot_create">
			<?php wp_nonce_field( 'cam_ad_slot_create' ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="slot_code">枠コード</label></th>
						<td>
							<input name="slot_code" type="text" id="slot_code" class="regular-text" placeholder="fashion_top">
							<p class="description">半角英数字とアンダースコア推奨。重複不可。</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="slot_name">枠名</label></th>
						<td>
							<input name="slot_name" type="text" id="slot_name" class="regular-text" placeholder="Fashion 上段枠">
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="genre">genre</label></th>
						<td>
							<?php $genre_options = get_ad_slot_genre_options(); ?>
							<select name="genre" id="genre">
								<option value="">選択してください</option>
								<?php foreach ( $genre_options as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>">
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="page_type">ページ種別</label></th>
						<td>
							<select name="page_type" id="page_type">
								<option value="post">post</option>
								<option value="page">page</option>
								<option value="archive">archive</option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="status">状態</label></th>
						<td>
							<select name="status" id="status">
								<option value="active">active</option>
								<option value="inactive">inactive</option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="description">説明</label></th>
						<td>
							<textarea name="description" id="description" rows="4" class="large-text" placeholder="この広告枠の用途や対象ページなど"></textarea>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( '広告枠を登録する' ); ?>
		</form>

		<hr>

		<h2>登録済み広告枠</h2>

		<?php if ( empty( $slots ) ) : ?>
			<p>まだ広告枠は登録されていません。</p>
		<?php else : ?>
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th>ID</th>
						<th>枠コード</th>
						<th>枠名</th>
						<th>genre</th>
						<th>ページ種別</th>
						<th>状態</th>
						<th>登録日時</th>
						<th>操作</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $slots as $slot ) : ?>
						<tr>
							<td><?php echo esc_html( $slot['id'] ); ?></td>
							<td><?php echo esc_html( $slot['slot_code'] ); ?></td>
							<td><?php echo esc_html( $slot['slot_name'] ); ?></td>
							<td><?php echo esc_html( $slot['genre'] ); ?></td>
							<td><?php echo esc_html( $slot['page_type'] ); ?></td>
							<td><?php echo esc_html( $slot['status'] ); ?></td>
							<td><?php echo esc_html( $slot['created_at'] ); ?></td>
							<td>
								<a href="#" onclick="return false;">編集（準備中）</a> |
								<?php if ( 'active' === $slot['status'] ) : ?>
									<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array(
										'action'  => 'cam_ad_slot_deactivate',
										'slot_id' => $slot['id'],
									), admin_url( 'admin-post.php' ) ), 'cam_ad_slot_deactivate_' . $slot['id'] ) ); ?>">無効化</a>
								<?php else : ?>
									<span>無効</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * 広告枠用 genre 候補
 *
 * @return array
 */
function get_ad_slot_genre_options() {
	return array(
		'fashion_suit'   => 'fashion / suit',
		'fashion_casual' => 'fashion / casual',
		'watch'          => 'watch',
		'shoe_elegant'   => 'shoe / elegant',
		'shoe_rugged'    => 'shoe / rugged',
		'lifestyle'      => 'lifestyle',
		'culture_book'   => 'culture / book',
		'culture_movie'  => 'culture / movie',
	);
}

/** 広告申込一覧ページ */
function ad_applications_page() {
	$slots        = get_active_ad_slots_for_select();
	$applications = get_ad_applications();
	$message      = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';
	?>
	<div class="wrap">
		<h1>広告申込登録</h1>

		<?php if ( 'created' === $message ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>広告申込を登録しました。</p>
			</div>
		<?php elseif ( 'error_required' === $message ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>広告主名・genre・広告枠は必須です。</p>
			</div>
		<?php elseif ( 'error_slot' === $message ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>選択した広告枠が不正です。</p>
			</div>
		<?php elseif ( 'error_date' === $message ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>掲載開始日と掲載終了日の指定が不正です。</p>
			</div>
		<?php elseif ( 'error_db' === $message ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>DB保存時にエラーが発生しました。</p>
			</div>
		<?php elseif ( 'error_genre_mismatch' === $message ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>広告枠とgenreが一致していません。</p>
			</div>
		<?php elseif ( 'approved' === $message ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>広告申込を承認しました。</p>
			</div>
		<?php elseif ( 'rejected' === $message ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>広告申込を却下しました。</p>
			</div>
		<?php elseif ( 'error_invalid_application' === $message ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>対象の広告申込が不正です。</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cam_ad_application_create">
			<?php wp_nonce_field( 'cam_ad_application_create' ); ?>

			<h2>申込基本情報</h2>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="advertiser_name">広告主名</label></th>
						<td>
							<input name="advertiser_name" type="text" id="advertiser_name" class="regular-text" placeholder="A広告主">
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="genre">genre</label></th>
						<td>
							<?php $genre_options = get_ad_slot_genre_options(); ?>
							<select name="genre" id="genre">
								<option value="">選択してください</option>
								<?php foreach ( $genre_options as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>">
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="slot_id">広告枠</label></th>
						<td>
							<select name="slot_id" id="slot_id">
								<option value="">選択してください</option>
								<?php foreach ( $slots as $slot ) : ?>
									<option value="<?php echo esc_attr( $slot['id'] ); ?>">
										<?php
										echo esc_html(
											$slot['slot_name'] . ' / ' .
											$slot['slot_code'] . ' / ' .
											$slot['genre']
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="start_date">掲載開始日</label></th>
						<td>
							<input name="start_date" type="date" id="start_date">
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="end_date">掲載終了日</label></th>
						<td>
							<input name="end_date" type="date" id="end_date">
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="bid_type">単価種別</label></th>
						<td>
							<select name="bid_type" id="bid_type">
								<option value="fixed">fixed</option>
								<option value="cpm">cpm</option>
								<option value="cpc">cpc</option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="bid_price">希望単価</label></th>
						<td>
							<input name="bid_price" type="number" id="bid_price" class="small-text" step="0.01" min="0" value="0">
						</td>
					</tr>
				</tbody>
			</table>

			<hr>

			<h2>広告クリエイティブ</h2>

			<h3>上段広告</h3>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="top_headline">見出し</label></th>
						<td><input name="top_headline" type="text" id="top_headline" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="top_image_url">画像URL</label></th>
						<td><input name="top_image_url" type="url" id="top_image_url" class="large-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="top_landing_url">リンク先URL</label></th>
						<td><input name="top_landing_url" type="url" id="top_landing_url" class="large-text"></td>
					</tr>
				</tbody>
			</table>

			<h3>中段広告</h3>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="middle_headline">見出し</label></th>
						<td><input name="middle_headline" type="text" id="middle_headline" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="middle_image_url">画像URL</label></th>
						<td><input name="middle_image_url" type="url" id="middle_image_url" class="large-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="middle_landing_url">リンク先URL</label></th>
						<td><input name="middle_landing_url" type="url" id="middle_landing_url" class="large-text"></td>
					</tr>
				</tbody>
			</table>

			<h3>下段広告</h3>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="bottom_headline">見出し</label></th>
						<td><input name="bottom_headline" type="text" id="bottom_headline" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="bottom_image_url">画像URL</label></th>
						<td><input name="bottom_image_url" type="url" id="bottom_image_url" class="large-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="bottom_landing_url">リンク先URL</label></th>
						<td><input name="bottom_landing_url" type="url" id="bottom_landing_url" class="large-text"></td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( '広告申込を登録する' ); ?>
		</form>
		<hr>

		<h2>登録済み広告申込</h2>

		<?php if ( empty( $applications ) ) : ?>
			<p>まだ広告申込は登録されていません。</p>
		<?php else : ?>
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th>ID</th>
						<th>受付番号</th>
						<th>広告主名</th>
						<th>genre</th>
						<th>広告枠</th>
						<th>掲載期間</th>
						<th>単価</th>
						<th>状態</th>
						<th>登録日時</th>
						<th>操作</th>
					</tr>
				</thead>
			<tbody>
				<?php foreach ( $applications as $application ) : ?>
					<tr>
						<td><?php echo esc_html( $application['id'] ); ?></td>
						<td><?php echo esc_html( $application['application_code'] ); ?></td>
						<td><?php echo esc_html( $application['advertiser_name_snapshot'] ); ?></td>
						<td><?php echo esc_html( $application['genre'] ); ?></td>
						<td>
							<?php
							echo esc_html(
								( $application['slot_name'] ? $application['slot_name'] : '未設定' ) .
								' / ' .
								( $application['slot_code'] ? $application['slot_code'] : '-' )
							);
							?>
						</td>
						<td>
							<?php
							echo esc_html(
								$application['start_date'] . ' ～ ' . $application['end_date']
							);
							?>
						</td>
						<td>
							<?php
							echo esc_html(
								$application['bid_type'] . ' / ' . $application['bid_price']
							);
							?>
						</td>
						<td><?php echo esc_html( $application['status'] ); ?></td>
						<td><?php echo esc_html( $application['created_at'] ); ?></td>
						<td>
							<?php if ( 'pending' === $application['status'] ) : ?>
								<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array(
									'action'         => 'cam_ad_application_approve',
									'application_id' => $application['id'],
								), admin_url( 'admin-post.php' ) ), 'cam_ad_application_approve_' . $application['id'] ) ); ?>">承認</a>
								|
								<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array(
									'action'         => 'cam_ad_application_reject',
									'application_id' => $application['id'],
								), admin_url( 'admin-post.php' ) ), 'cam_ad_application_reject_' . $application['id'] ) ); ?>">却下</a>
								|
								<a href="<?php echo esc_url( add_query_arg(
									array(
										'page'           => 'cam-ad-application-detail',
										'application_id' => $application['id'],
									),
									admin_url( 'admin.php' )
								) ); ?>">詳細</a>

							<?php elseif ( 'approved' === $application['status'] ) : ?>
								<span>承認済</span>
								|
								<a href="<?php echo esc_url( add_query_arg(
									array(
										'page'           => 'cam-ad-application-detail',
										'application_id' => $application['id'],
									),
									admin_url( 'admin.php' )
								) ); ?>">詳細</a>

							<?php elseif ( 'ready' === $application['status'] ) : ?>
								<span>配信対象</span>
								|
								<a href="<?php echo esc_url( add_query_arg(
									array(
										'page'           => 'cam-ad-application-detail',
										'application_id' => $application['id'],
									),
									admin_url( 'admin.php' )
								) ); ?>">詳細</a>

							<?php elseif ( 'rejected' === $application['status'] ) : ?>
								<span>却下済</span>
								|
								<a href="<?php echo esc_url( add_query_arg(
									array(
										'page'           => 'cam-ad-application-detail',
										'application_id' => $application['id'],
									),
									admin_url( 'admin.php' )
								) ); ?>">詳細</a>

							<?php else : ?>
								<a href="<?php echo esc_url( add_query_arg(
									array(
										'page'           => 'cam-ad-application-detail',
										'application_id' => $application['id'],
									),
									admin_url( 'admin.php' )
								) ); ?>">詳細</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	</div>
	<?php
}

/** 承認済広告ページ */
function ad_approved_page() {
	$applications = get_approved_ad_applications();
	$posts        = get_ad_assignable_posts();
	$message      = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';
	
	?>
	<div class="wrap">
		<h1>承認済広告</h1>

		<?php if ( 'ready' === $message ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>広告を配信対象に設定しました。</p>
			</div>
		<?php elseif ( 'error_invalid_application' === $message ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>対象の広告申込が不正です。</p>
			</div>
		<?php elseif ( 'error_db' === $message ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>DB更新時にエラーが発生しました。</p>
			</div>
		<?php elseif ( 'error_assign_required' === $message ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>掲載先投稿を選択してください。</p>
			</div>
		<?php elseif ( 'error_invalid_post' === $message ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>掲載先投稿が不正です。</p>
			</div>
		<?php endif; ?>

		<?php if ( empty( $applications ) ) : ?>
			<p>承認済みの広告はまだありません。</p>
		<?php else : ?>
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th>ID</th>
						<th>受付番号</th>
						<th>広告主名</th>
						<th>genre</th>
						<th>広告枠</th>
						<th>掲載期間</th>
						<th>単価</th>
						<th>承認日時</th>
						<th>操作</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $applications as $application ) : ?>
						<tr>
							<td><?php echo esc_html( $application['id'] ); ?></td>
							<td><?php echo esc_html( $application['application_code'] ); ?></td>
							<td><?php echo esc_html( $application['advertiser_name_snapshot'] ); ?></td>
							<td><?php echo esc_html( $application['genre'] ); ?></td>
							<td>
								<?php
								echo esc_html(
									( $application['slot_name'] ? $application['slot_name'] : '未設定' ) .
									' / ' .
									( $application['slot_code'] ? $application['slot_code'] : '-' )
								);
								?>
							</td>
							<td>
								<?php
								echo esc_html(
									$application['start_date'] . ' ～ ' . $application['end_date']
								);
								?>
							</td>
							<td>
								<?php
								echo esc_html(
									$application['bid_type'] . ' / ' . $application['bid_price']
								);
								?>
							</td>
							<td><?php echo esc_html( $application['approved_at'] ); ?></td>
							<td>
								<?php if ( 'approved' === $application['status'] ) : ?>
									<a href="<?php echo esc_url( wp_nonce_url( add_query_arg(
										array(
											'action'         => 'cam_ad_application_ready',
											'application_id' => $application['id'],
										),
										admin_url( 'admin-post.php' )
									), 'cam_ad_application_ready_' . $application['id'] ) ); ?>">配信対象にする</a>
									|
									<a href="<?php echo esc_url( add_query_arg(
										array(
											'page'           => 'cam-ad-application-detail',
											'application_id' => $application['id'],
										),
										admin_url( 'admin.php' )
									) ); ?>">詳細</a>

								<?php elseif ( 'ready' === $application['status'] ) : ?>
									<span>配信対象</span>
									|
									<a href="<?php echo esc_url( add_query_arg(
										array(
											'page'           => 'cam-ad-application-detail',
											'application_id' => $application['id'],
										),
										admin_url( 'admin.php' )
									) ); ?>">詳細</a>

									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
										<input type="hidden" name="action" value="cam_ad_application_assign_post">
										<input type="hidden" name="application_id" value="<?php echo esc_attr( $application['id'] ); ?>">
										<?php wp_nonce_field( 'cam_ad_application_assign_post_' . $application['id'] ); ?>

										<select name="post_id">
											<option value="">掲載先投稿を選択</option>
											<?php foreach ( $posts as $candidate_post ) : ?>
												<option value="<?php echo esc_attr( $candidate_post->ID ); ?>">
													<?php echo esc_html( $candidate_post->post_title . ' (ID:' . $candidate_post->ID . ')' ); ?>
												</option>
											<?php endforeach; ?>
										</select>

										<button type="submit" class="button button-secondary">投稿へ割当</button>
									</form>

								<?php else : ?>
									<a href="<?php echo esc_url( add_query_arg(
										array(
											'page'           => 'cam-ad-application-detail',
											'application_id' => $application['id'],
										),
										admin_url( 'admin.php' )
									) ); ?>">詳細</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * 広告申込詳細ページ
 */
function ad_application_detail_page() {
	$application_id = isset( $_GET['application_id'] ) ? absint( $_GET['application_id'] ) : 0;
	$application    = get_ad_application_by_id( $application_id );
	$items          = get_ad_application_items( $application_id );
	$message        = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';

	?>
	<div class="wrap">
		<h1>広告申込詳細</h1>

		<?php if ( 'ca_issued' === $message ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>広告CAを発行し、CA状態を更新しました。</p>
			</div>
		<?php elseif ( 'error_not_ready' === $message ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>この広告申込はまだ配信対象ではないため、CA発行できません。</p>
			</div>
		<?php elseif ( 'error_no_items' === $message ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>広告クリエイティブが存在しないため、CA発行できません。</p>
			</div>
		<?php elseif ( 'error_issue_ca' === $message ) : ?>
			<div class="notice notice-error is-dismissible">
				<p>広告CAの発行に失敗しました。</p>
			</div>
		<?php endif; ?>

		<?php if ( empty( $application ) ) : ?>
			<div class="notice notice-error">
				<p>対象の広告申込が見つかりません。</p>
			</div>
		<?php else : ?>
			<h2>基本情報</h2>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">申込ID</th>
						<td><?php echo esc_html( $application['id'] ); ?></td>
					</tr>
					<tr>
						<th scope="row">受付番号</th>
						<td><?php echo esc_html( $application['application_code'] ); ?></td>
					</tr>
					<tr>
						<th scope="row">広告主名</th>
						<td><?php echo esc_html( $application['advertiser_name_snapshot'] ); ?></td>
					</tr>
					<tr>
						<th scope="row">genre</th>
						<td><?php echo esc_html( $application['genre'] ); ?></td>
					</tr>
					<tr>
						<th scope="row">広告枠</th>
						<td>
							<?php
							echo esc_html(
								( ! empty( $application['slot_name'] ) ? $application['slot_name'] : '未設定' ) .
								' / ' .
								( ! empty( $application['slot_code'] ) ? $application['slot_code'] : '-' )
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row">掲載期間</th>
						<td>
							<?php
							echo esc_html(
								$application['start_date'] . ' ～ ' . $application['end_date']
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row">単価</th>
						<td>
							<?php
							echo esc_html(
								$application['bid_type'] . ' / ' . $application['bid_price']
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row">状態</th>
						<td><?php echo esc_html( $application['status'] ); ?></td>
					</tr>
					<tr>
						<th scope="row">登録日時</th>
						<td><?php echo esc_html( $application['created_at'] ); ?></td>
					</tr>
					<tr>
						<th scope="row">承認日時</th>
						<td><?php echo esc_html( isset( $application['approved_at'] ) ? $application['approved_at'] : '' ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2>広告クリエイティブ</h2>

			<?php if ( empty( $items ) ) : ?>
				<p>広告クリエイティブは登録されていません。</p>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th>位置</th>
							<th>見出し</th>
							<th>画像</th>
							<th>リンク先URL</th>
							<th>CA状態</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $item ) : ?>
							<tr>
								<td><?php echo esc_html( $item['slot_position'] ); ?></td>
								<td><?php echo esc_html( $item['headline'] ); ?></td>
								<td>
									<?php if ( ! empty( $item['image_url'] ) ) : ?>
										<img
											src="<?php echo esc_url( $item['image_url'] ); ?>"
											alt="<?php echo esc_attr( $item['headline'] ); ?>"
											style="max-width: 240px; height: auto; border: 1px solid #ddd; padding: 4px; background: #fff;"
										>
										<div style="margin-top: 6px; word-break: break-all; font-size: 12px; color: #666;">
											<?php echo esc_html( $item['image_url'] ); ?>
										</div>
									<?php else : ?>
										<span>-</span>
									<?php endif; ?>
								</td>
								<td style="word-break: break-all;">
									<?php if ( ! empty( $item['landing_url'] ) ) : ?>
										<a href="<?php echo esc_url( $item['landing_url'] ); ?>" target="_blank" rel="noopener noreferrer">
											<?php echo esc_html( $item['landing_url'] ); ?>
										</a>
									<?php else : ?>
										<span>-</span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $item['ca_status'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p style="margin-top: 20px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cam-ad-applications' ) ); ?>" class="button">申込一覧へ戻る</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cam-ad-approved' ) ); ?>" class="button">承認済広告へ戻る</a>
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $application ) && 'ready' === $application['status'] ) : ?>
			<p style="margin-top: 20px;">
				<a
					href="<?php echo esc_url( wp_nonce_url( add_query_arg(
						array(
							'action'         => 'cam_ad_application_issue_ca',
							'application_id' => $application['id'],
						),
						admin_url( 'admin-post.php' )
					), 'cam_ad_application_issue_ca_' . $application['id'] ) ); ?>"
					class="button button-primary"
				>CA発行</a>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/** 広告統計ページ */
function ad_stats_page() {
	?>
	<div class="wrap">
		<h1>CA広告統計</h1>
		<p>ここに広告統計画面を作成します。</p>
	</div>
	<?php
}

/**
 * 広告枠を保存
 */
function handle_ad_slot_create() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '権限がありません。' );
	}

	check_admin_referer( 'cam_ad_slot_create' );

	global $wpdb;

	$table = $wpdb->prefix . 'cam_ad_slots';

	$slot_code   = isset( $_POST['slot_code'] ) ? sanitize_text_field( wp_unslash( $_POST['slot_code'] ) ) : '';
	$slot_name   = isset( $_POST['slot_name'] ) ? sanitize_text_field( wp_unslash( $_POST['slot_name'] ) ) : '';
	$genre       = isset( $_POST['genre'] ) ? sanitize_text_field( wp_unslash( $_POST['genre'] ) ) : '';
	$position = 'top';

	$allowed_genres = array_keys( get_ad_slot_genre_options() );
	if ( ! in_array( $genre, $allowed_genres, true ) ) {
		$genre = '';
	}

	$page_type   = isset( $_POST['page_type'] ) ? sanitize_text_field( wp_unslash( $_POST['page_type'] ) ) : 'post';
	$status      = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'active';
	$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

	if ( '' === $slot_code || '' === $slot_name || '' === $genre ) {
		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-slots',
				'message' => 'error_required',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	$allowed_page_types = array( 'post', 'page', 'archive' );
	if ( ! in_array( $page_type, $allowed_page_types, true ) ) {
		$page_type = 'post';
	}

	$allowed_statuses = array( 'active', 'inactive' );
	if ( ! in_array( $status, $allowed_statuses, true ) ) {
		$status = 'active';
	}

	$now = current_time( 'mysql' );

	$inserted = $wpdb->insert(
		$table,
		array(
			'slot_code'   => $slot_code,
			'slot_name'   => $slot_name,
			'genre'       => $genre,
			'position'    => $position,
			'page_type'   => $page_type,
			'status'      => $status,
			'description' => $description,
			'created_at'  => $now,
			'updated_at'  => $now,
		),
		array(
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
		)
	);

	if ( false === $inserted ) {
		$message = false !== strpos( $wpdb->last_error, 'Duplicate entry' ) ? 'error_duplicate' : 'error_db';

		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-slots',
				'message' => $message,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	$redirect_url = add_query_arg(
		array(
			'page'    => 'cam-ad-slots',
			'message' => 'created',
		),
		admin_url( 'admin.php' )
	);
	wp_safe_redirect( $redirect_url );
	exit;
}

/**
 * 広告枠一覧取得
 *
 * @return array
 */
function get_ad_slots() {
	global $wpdb;

	$table = $wpdb->prefix . 'cam_ad_slots';

	$sql = "SELECT id, slot_code, slot_name, genre, page_type, status, created_at
			FROM {$table}
			ORDER BY id DESC";

	$results = $wpdb->get_results( $sql, ARRAY_A );

	return is_array( $results ) ? $results : array();
}

/**
 * 有効な広告枠一覧取得（select用）
 *
 * @return array
 */
function get_active_ad_slots_for_select() {
	global $wpdb;

	$table = $wpdb->prefix . 'cam_ad_slots';

	$sql = "SELECT id, slot_code, slot_name, genre
			FROM {$table}
			WHERE status = 'active'
			ORDER BY id DESC";

	$results = $wpdb->get_results( $sql, ARRAY_A );

	return is_array( $results ) ? $results : array();
}

/**
 * 広告申込一覧取得
 *
 * @return array
 */
function get_ad_applications() {
	global $wpdb;

	$applications_table = $wpdb->prefix . 'cam_ad_applications';
	$slots_table        = $wpdb->prefix . 'cam_ad_slots';

	$sql = "SELECT
				a.id,
				a.application_code,
				a.advertiser_name_snapshot,
				a.genre,
				a.start_date,
				a.end_date,
				a.bid_type,
				a.bid_price,
				a.status,
				a.created_at,
				s.slot_name,
				s.slot_code
			FROM {$applications_table} a
			LEFT JOIN {$slots_table} s
				ON a.slot_id = s.id
			ORDER BY a.id DESC";

	$results = $wpdb->get_results( $sql, ARRAY_A );

	return is_array( $results ) ? $results : array();
}

/**
 * 承認済広告一覧取得
 *
 * @return array
 */
function get_approved_ad_applications() {
	global $wpdb;

	$applications_table = $wpdb->prefix . 'cam_ad_applications';
	$slots_table        = $wpdb->prefix . 'cam_ad_slots';

	$sql = "SELECT
				a.id,
				a.application_code,
				a.advertiser_name_snapshot,
				a.genre,
				a.start_date,
				a.end_date,
				a.bid_type,
				a.bid_price,
				a.status,
				a.approved_at,
				a.created_at,
				s.slot_name,
				s.slot_code
			FROM {$applications_table} a
			LEFT JOIN {$slots_table} s
				ON a.slot_id = s.id
			WHERE a.status IN ('approved', 'ready')
			ORDER BY a.id DESC";

	$results = $wpdb->get_results( $sql, ARRAY_A );

	return is_array( $results ) ? $results : array();
}

/**
 * 広告申込ヘッダ取得
 *
 * @param int $application_id 申込ID
 * @return array|null
 */
function get_ad_application_by_id( $application_id ) {
	global $wpdb;

	$applications_table = $wpdb->prefix . 'cam_ad_applications';
	$slots_table        = $wpdb->prefix . 'cam_ad_slots';

	$sql = $wpdb->prepare(
		"SELECT
			a.*,
			s.slot_name,
			s.slot_code
		FROM {$applications_table} a
		LEFT JOIN {$slots_table} s
			ON a.slot_id = s.id
		WHERE a.id = %d
		LIMIT 1",
		$application_id
	);

	$result = $wpdb->get_row( $sql, ARRAY_A );

	return is_array( $result ) ? $result : null;
}

/**
 * 広告申込アイテム取得
 *
 * @param int $application_id 申込ID
 * @return array
 */
function get_ad_application_items( $application_id ) {
	global $wpdb;

	$items_table = $wpdb->prefix . 'cam_ad_application_items';

	$sql = $wpdb->prepare(
		"SELECT *
		FROM {$items_table}
		WHERE application_id = %d
		ORDER BY id ASC",
		$application_id
	);

	$results = $wpdb->get_results( $sql, ARRAY_A );

	return is_array( $results ) ? $results : array();
}

/**
 * 広告申込登録
 */
function handle_ad_application_create() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '権限がありません。' );
	}

	check_admin_referer( 'cam_ad_application_create' );

	global $wpdb;

	$applications_table = $wpdb->prefix . 'cam_ad_applications';
	$items_table        = $wpdb->prefix . 'cam_ad_application_items';
	$slots_table        = $wpdb->prefix . 'cam_ad_slots';

	$advertiser_name = isset( $_POST['advertiser_name'] ) ? sanitize_text_field( wp_unslash( $_POST['advertiser_name'] ) ) : '';
	$genre           = isset( $_POST['genre'] ) ? sanitize_text_field( wp_unslash( $_POST['genre'] ) ) : '';
	$slot_id         = isset( $_POST['slot_id'] ) ? absint( $_POST['slot_id'] ) : 0;

	$allowed_genres = array_keys( get_ad_slot_genre_options() );
	if ( ! in_array( $genre, $allowed_genres, true ) ) {
		$genre = '';
	}

	$start_date      = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
	$end_date        = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
	$bid_type        = isset( $_POST['bid_type'] ) ? sanitize_text_field( wp_unslash( $_POST['bid_type'] ) ) : 'fixed';
	$bid_price       = isset( $_POST['bid_price'] ) ? (float) $_POST['bid_price'] : 0;

	if ( '' === $advertiser_name || '' === $genre || 0 === $slot_id ) {
		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-applications',
				'message' => 'error_required',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	if (
		( '' !== $start_date && ! strtotime( $start_date ) ) ||
		( '' !== $end_date && ! strtotime( $end_date ) ) ||
		( '' !== $start_date && '' !== $end_date && strtotime( $start_date ) > strtotime( $end_date ) )
	) {
		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-approved',
				'message' => 'approved',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	$slot_exists = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$slots_table} WHERE id = %d",
			$slot_id
		)
	);

	if ( ! $slot_exists ) {
		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-applications',
				'message' => 'error_slot',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	$slot_genre = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT genre FROM {$slots_table} WHERE id = %d LIMIT 1",
			$slot_id
		)
	);

	if ( empty( $slot_genre ) || $slot_genre !== $genre ) {
		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-applications',
				'message' => 'error_genre_mismatch',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	$allowed_bid_types = array( 'fixed', 'cpm', 'cpc' );
	if ( ! in_array( $bid_type, $allowed_bid_types, true ) ) {
		$bid_type = 'fixed';
	}

	$application_code = 'cam-app-' . wp_generate_uuid4();
	$now              = current_time( 'mysql' );

	$inserted = $wpdb->insert(
		$applications_table,
		array(
			'application_code'         => $application_code,
			'advertiser_id'            => null,
			'applicant_type'           => 'advertiser',
			'advertiser_name_snapshot' => $advertiser_name,
			'contact_name_snapshot'    => '',
			'email_snapshot'           => '',
			'phone_snapshot'           => '',
			'address_snapshot'         => '',
			'genre'                    => $genre,
			'slot_id'                  => $slot_id,
			'start_date'               => $start_date,
			'end_date'                 => $end_date,
			'bid_type'                 => $bid_type,
			'bid_price'                => $bid_price,
			'status'                   => 'pending',
			'review_comment'           => '',
			'admin_memo'               => '',
			'created_at'               => $now,
			'updated_at'               => $now,
		),
		array(
			'%s',
			'%d',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%d',
			'%s',
			'%s',
			'%s',
			'%f',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
		)
	);

	if ( false === $inserted ) {
		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-applications',
				'message' => 'error_db',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	$application_id = (int) $wpdb->insert_id;

	$items = array(
		'top'    => array(
			'headline'    => isset( $_POST['top_headline'] ) ? sanitize_text_field( wp_unslash( $_POST['top_headline'] ) ) : '',
			'image_url'   => isset( $_POST['top_image_url'] ) ? esc_url_raw( wp_unslash( $_POST['top_image_url'] ) ) : '',
			'landing_url' => isset( $_POST['top_landing_url'] ) ? esc_url_raw( wp_unslash( $_POST['top_landing_url'] ) ) : '',
		),
		'middle' => array(
			'headline'    => isset( $_POST['middle_headline'] ) ? sanitize_text_field( wp_unslash( $_POST['middle_headline'] ) ) : '',
			'image_url'   => isset( $_POST['middle_image_url'] ) ? esc_url_raw( wp_unslash( $_POST['middle_image_url'] ) ) : '',
			'landing_url' => isset( $_POST['middle_landing_url'] ) ? esc_url_raw( wp_unslash( $_POST['middle_landing_url'] ) ) : '',
		),
		'bottom' => array(
			'headline'    => isset( $_POST['bottom_headline'] ) ? sanitize_text_field( wp_unslash( $_POST['bottom_headline'] ) ) : '',
			'image_url'   => isset( $_POST['bottom_image_url'] ) ? esc_url_raw( wp_unslash( $_POST['bottom_image_url'] ) ) : '',
			'landing_url' => isset( $_POST['bottom_landing_url'] ) ? esc_url_raw( wp_unslash( $_POST['bottom_landing_url'] ) ) : '',
		),
	);

	foreach ( $items as $position => $item ) {
		if ( '' === $item['headline'] && '' === $item['image_url'] && '' === $item['landing_url'] ) {
			continue;
		}

		$wpdb->insert(
			$items_table,
			array(
				'application_id' => $application_id,
				'slot_position'  => $position,
				'headline'       => $item['headline'],
				'body_text'      => '',
				'image_url'      => $item['image_url'],
				'attachment_id'  => null,
				'landing_url'    => $item['landing_url'],
				'ca_status'      => 'not_issued',
				'ca_identifier'  => '',
				'item_status'    => 'pending',
				'display_order'  => 0,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
			)
		);
	}

	$redirect_url = add_query_arg(
		array(
			'page'    => 'cam-ad-applications',
			'message' => 'created',
		),
		admin_url( 'admin.php' )
	);
	wp_safe_redirect( $redirect_url );
	exit;
}
/**
 * 広告枠を無効化
 */
function handle_ad_slot_deactivate() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '権限がありません。' );
	}

	$slot_id = isset( $_GET['slot_id'] ) ? absint( $_GET['slot_id'] ) : 0;

	check_admin_referer( 'cam_ad_slot_deactivate_' . $slot_id );

	if ( ! $slot_id ) {
		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-slots',
				'message' => 'error_invalid_slot',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	global $wpdb;

	$table = $wpdb->prefix . 'cam_ad_slots';

	$updated = $wpdb->update(
		$table,
		array(
			'status'     => 'inactive',
			'updated_at' => current_time( 'mysql' ),
		),
		array(
			'id' => $slot_id,
		),
		array(
			'%s',
			'%s',
		),
		array(
			'%d',
		)
	);

	if ( false === $updated ) {
		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-slots',
				'message' => 'error_db',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	$redirect_url = add_query_arg(
		array(
			'page'    => 'cam-ad-slots',
			'message' => 'deactivated',
		),
		admin_url( 'admin.php' )
	);
	wp_safe_redirect( $redirect_url );
	exit;
}
/**
 * 広告申込を承認
 */
function handle_ad_application_approve() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '権限がありません。' );
	}

	$application_id = isset( $_GET['application_id'] ) ? absint( $_GET['application_id'] ) : 0;

	check_admin_referer( 'cam_ad_application_approve_' . $application_id );

	if ( ! $application_id ) {
		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-applications',
				'message' => 'error_invalid_application',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	global $wpdb;

	$table = $wpdb->prefix . 'cam_ad_applications';

	$updated = $wpdb->update(
		$table,
		array(
			'status'      => 'approved',
			'approved_at' => current_time( 'mysql' ),
			'reviewed_at' => current_time( 'mysql' ),
			'reviewed_by' => get_current_user_id(),
			'updated_at'  => current_time( 'mysql' ),
		),
		array(
			'id' => $application_id,
		),
		array(
			'%s',
			'%s',
			'%s',
			'%d',
			'%s',
		),
		array(
			'%d',
		)
	);

	if ( false === $updated ) {
		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-applications',
				'message' => 'error_db',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	$redirect_url = add_query_arg(
		array(
			'page'    => 'cam-ad-approved',
			'message' => 'approved',
		),
		admin_url( 'admin.php' )
	);
	wp_safe_redirect( $redirect_url );
	exit;
}

/**
 * 広告申込を却下
 */
function handle_ad_application_reject() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '権限がありません。' );
	}

	$application_id = isset( $_GET['application_id'] ) ? absint( $_GET['application_id'] ) : 0;

	check_admin_referer( 'cam_ad_application_reject_' . $application_id );

	if ( ! $application_id ) {
		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-applications',
				'message' => 'error_invalid_application',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	global $wpdb;

	$table = $wpdb->prefix . 'cam_ad_applications';

	$updated = $wpdb->update(
		$table,
		array(
			'status'      => 'rejected',
			'reviewed_at' => current_time( 'mysql' ),
			'reviewed_by' => get_current_user_id(),
			'updated_at'  => current_time( 'mysql' ),
		),
		array(
			'id' => $application_id,
		),
		array(
			'%s',
			'%s',
			'%d',
			'%s',
		),
		array(
			'%d',
		)
	);

	if ( false === $updated ) {
		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-applications',
				'message' => 'error_db',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	$redirect_url = add_query_arg(
		array(
			'page'    => 'cam-ad-applications',
			'message' => 'rejected',
		),
		admin_url( 'admin.php' )
	);
	wp_safe_redirect( $redirect_url );
	exit;
}

/**
 * 広告申込を配信対象化
 */
function handle_ad_application_ready() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '権限がありません。' );
	}

	$application_id = isset( $_GET['application_id'] ) ? absint( $_GET['application_id'] ) : 0;

	check_admin_referer( 'cam_ad_application_ready_' . $application_id );

	if ( ! $application_id ) {
		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-approved',
				'message' => 'error_invalid_application',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	global $wpdb;

	$applications_table = $wpdb->prefix . 'cam_ad_applications';
	$items_table        = $wpdb->prefix . 'cam_ad_application_items';

	$updated = $wpdb->update(
		$applications_table,
		array(
			'status'     => 'ready',
			'updated_at' => current_time( 'mysql' ),
		),
		array(
			'id'     => $application_id,
			'status' => 'approved',
		),
		array(
			'%s',
			'%s',
		),
		array(
			'%d',
			'%s',
		)
	);

	if ( false === $updated ) {
		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-approved',
				'message' => 'error_db',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	$application = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$applications_table} WHERE id = %d LIMIT 1",
			$application_id
		),
		ARRAY_A
	);

	if ( empty( $application ) ) {
		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-approved',
				'message' => 'error_invalid_application',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	$items = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$items_table} WHERE application_id = %d ORDER BY id ASC",
			$application_id
		),
		ARRAY_A
	);

	$genre_map = array(
		'fashion_suit'   => 'suit',
		'fashion_casual' => 'casual',
		'fashion_vintage'=> 'vintage',
		'culture_book'   => 'book',
		'culture_movie'  => 'movie',
	);

	$app_genre     = isset( $application['genre'] ) ? (string) $application['genre'] : '';
	$context_genre = isset( $genre_map[ $app_genre ] ) ? $genre_map[ $app_genre ] : $app_genre;

	if ( function_exists( '\Profile\Debug\debug' ) ) {
		\Profile\Debug\debug(
			'READY_SYNC_BEFORE_CONTEXT_AD application_id=' . $application_id . ' ' .
			wp_json_encode(
				array(
					'id'         => isset( $application['id'] ) ? $application['id'] : '',
					'advertiser' => isset( $application['advertiser_name_snapshot'] ) ? $application['advertiser_name_snapshot'] : '',
					'bid_type'   => isset( $application['bid_type'] ) ? $application['bid_type'] : '',
					'bid_price'  => isset( $application['bid_price'] ) ? $application['bid_price'] : '',
					'genre'      => isset( $application['genre'] ) ? $application['genre'] : '',
					'context_genre' => $context_genre,
					'status'     => isset( $application['status'] ) ? $application['status'] : '',
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		);
	}

	$context_ad = array(
		'id'                 => 'cam-context-app-' . $application_id,
		'enabled'            => 1,
		'status'             => 'active',
		'genre'              => $context_genre,
		'advertiser'         => isset( $application['advertiser_name_snapshot'] ) ? (string) $application['advertiser_name_snapshot'] : '',
		'bid_type'           => isset( $application['bid_type'] ) ? (string) $application['bid_type'] : 'fixed',
		'bid_price'          => isset( $application['bid_price'] ) ? (float) $application['bid_price'] : 0,
		'start_date'         => isset( $application['start_date'] ) ? (string) $application['start_date'] : '',
		'end_date'           => isset( $application['end_date'] ) ? (string) $application['end_date'] : '',

		'top_headline'       => '',
		'top_image'          => '',
		'top_destination'    => '',

		'middle_headline'    => '',
		'middle_image'       => '',
		'middle_destination' => '',

		'bottom_headline'    => '',
		'bottom_image'       => '',
		'bottom_destination' => '',
	);

	if ( is_array( $items ) ) {
		foreach ( $items as $item ) {
			$position = isset( $item['slot_position'] ) ? (string) $item['slot_position'] : '';

			if ( 'top' === $position ) {
				$context_ad['top_headline']    = isset( $item['headline'] ) ? (string) $item['headline'] : '';
				$context_ad['top_image']       = isset( $item['image_url'] ) ? (string) $item['image_url'] : '';
				$context_ad['top_destination'] = isset( $item['landing_url'] ) ? (string) $item['landing_url'] : '';
			}

			if ( 'middle' === $position ) {
				$context_ad['middle_headline']    = isset( $item['headline'] ) ? (string) $item['headline'] : '';
				$context_ad['middle_image']       = isset( $item['image_url'] ) ? (string) $item['image_url'] : '';
				$context_ad['middle_destination'] = isset( $item['landing_url'] ) ? (string) $item['landing_url'] : '';
			}

			if ( 'bottom' === $position ) {
				$context_ad['bottom_headline']    = isset( $item['headline'] ) ? (string) $item['headline'] : '';
				$context_ad['bottom_image']       = isset( $item['image_url'] ) ? (string) $item['image_url'] : '';
				$context_ad['bottom_destination'] = isset( $item['landing_url'] ) ? (string) $item['landing_url'] : '';
			}
		}
	}

	$ads = get_option( 'cam_context_ads', array() );
	if ( ! is_array( $ads ) ) {
		$ads = array();
	}

	$synced  = false;
	$sync_id = $context_ad['id'];

	foreach ( $ads as $index => $existing_ad ) {
		$existing_id = isset( $existing_ad['id'] ) ? (string) $existing_ad['id'] : '';

		if ( $existing_id === $sync_id ) {
			$ads[ $index ] = $context_ad;
			$synced = true;
			break;
		}
	}

	if ( ! $synced ) {
		$ads[] = $context_ad;
	}

	update_option( 'cam_context_ads', array_values( $ads ) );

	$redirect_url = add_query_arg(
		array(
			'page'    => 'cam-ad-approved',
			'message' => 'ready',
		),
		admin_url( 'admin.php' )
	);

	wp_safe_redirect( $redirect_url );
	exit;
}

/**
 * 広告申込のCA発行
 */
function handle_ad_application_issue_ca() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '権限がありません。' );
	}

	$application_id = isset( $_GET['application_id'] ) ? absint( $_GET['application_id'] ) : 0;

	check_admin_referer( 'cam_ad_application_issue_ca_' . $application_id );

	if ( ! $application_id ) {
		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-application-detail',
				'message' => 'error_issue_ca',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	global $wpdb;

	$applications_table = $wpdb->prefix . 'cam_ad_applications';
	$items_table        = $wpdb->prefix . 'cam_ad_application_items';

	$application = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$applications_table} WHERE id = %d LIMIT 1",
			$application_id
		),
		ARRAY_A
	);

	if ( empty( $application ) || 'ready' !== $application['status'] ) {
		$redirect_url = add_query_arg(
			array(
				'page'           => 'cam-ad-application-detail',
				'application_id' => $application_id,
				'message'        => 'error_not_ready',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	$items = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$items_table} WHERE application_id = %d ORDER BY id ASC",
			$application_id
		),
		ARRAY_A
	);

	if ( empty( $items ) ) {
		$redirect_url = add_query_arg(
			array(
				'page'           => 'cam-ad-application-detail',
				'application_id' => $application_id,
				'message'        => 'error_no_items',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	foreach ( $items as $item ) {
		$updated = $wpdb->update(
			$items_table,
			array(
				'ca_status'     => 'issued',
				'ca_identifier' => 'cam-ad-ca-' . $application_id . '-' . $item['id'],
				'updated_at'    => current_time( 'mysql' ),
			),
			array(
				'id' => $item['id'],
			),
			array(
				'%s',
				'%s',
				'%s',
			),
			array(
				'%d',
			)
		);

		if ( false === $updated ) {
			$redirect_url = add_query_arg(
				array(
					'page'           => 'cam-ad-application-detail',
					'application_id' => $application_id,
					'message'        => 'error_issue_ca',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect_url );
			exit;
		}
	}

	$redirect_url = add_query_arg(
		array(
			'page'           => 'cam-ad-application-detail',
			'application_id' => $application_id,
			'message'        => 'ca_issued',
		),
		admin_url( 'admin.php' )
	);
	wp_safe_redirect( $redirect_url );
	exit;
}

/**
 * 掲載先候補の投稿一覧取得
 *
 * @return array
 */
function get_ad_assignable_posts() {
	$posts = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);

	return is_array( $posts ) ? $posts : array();
}

/**
 * 広告申込を投稿に割り当て
 */
function handle_ad_application_assign_post() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '権限がありません。' );
	}

	$application_id = isset( $_POST['application_id'] ) ? absint( wp_unslash( $_POST['application_id'] ) ) : 0;
	$post_id        = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

	check_admin_referer( 'cam_ad_application_assign_post_' . $application_id );

	if ( ! $application_id || ! $post_id ) {
		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-approved',
				'message' => 'error_assign_required',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	global $wpdb;

	$applications_table = $wpdb->prefix . 'cam_ad_applications';

	$application = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$applications_table} WHERE id = %d LIMIT 1",
			$application_id
		),
		ARRAY_A
	);

	if ( empty( $application ) || 'ready' !== $application['status'] ) {
		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-approved',
				'message' => 'error_invalid_application',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post ) {
		$redirect_url = add_query_arg(
			array(
				'page'    => 'cam-ad-approved',
				'message' => 'error_invalid_post',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	update_post_meta( $post_id, '_cam_assigned_ad_application_id', $application_id );

	$redirect_url = add_query_arg(
		array(
			'post'   => $post_id,
			'action' => 'edit',
		),
		admin_url( 'post.php' )
	);

	wp_safe_redirect( $redirect_url );
	exit;
}
