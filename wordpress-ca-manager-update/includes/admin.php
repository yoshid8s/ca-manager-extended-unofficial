<?php
/** 管理者画面 */

namespace Profile\Admin;

require_once __DIR__ . '/config.php';
use const Profile\Config\PROFILE_DEFAULT_CA_SERVER_HOSTNAME;
use const Profile\Config\PROFILE_DEFAULT_CA_TARGET_TYPE;
use const Profile\Config\PROFILE_DEFAULT_CA_TARGET_CSS_SELECTOR;
use const Profile\Config\PROFILE_DEFAULT_CA_TARGET_HTML;
use const Profile\Config\PROFILE_DEFAULT_CA_LOG_DIR;

/** 管理者画面の初期化 */
function init() {
	\add_action( 'admin_menu', '\Profile\Admin\add_options_page' );
	\add_action( 'admin_init', '\Profile\Admin\register_settings' );
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

			$contents      = $wp_filesystem->get_contents( $log_file );
			$contents_safe = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]+/', '', $contents );
			if ( false === $contents_safe ) {
				wp_die( 'ログファイルを読み込めませんでした。' );
			}
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Disposition: attachment; filename="ca-manager-debug.log"' );
			header( 'Content-Length: ' . strlen( $contents_safe ) );
			header( 'X-Content-Type-Options: nosniff' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $contents_safe;
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
	\register_setting( 'ca-manager', 'cam_context_ads' );
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

	<h3>設定済み広告情報</h3>
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
