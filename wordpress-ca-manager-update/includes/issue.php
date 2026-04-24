<?php
/** Content Attestation の発行 */

namespace Profile\Issue;

require_once __DIR__ . '/class-uca.php';
use Profile\Uca\Uca;

require_once __DIR__ . '/config.php';
use const Profile\Config\PROFILE_DEFAULT_CA_SERVER_HOSTNAME;
use const Profile\Config\PROFILE_DEFAULT_CA_SERVER_REQUEST_TIMEOUT;
use const Profile\Config\PROFILE_DEFAULT_CA_TARGET_TYPE;
use const Profile\Config\PROFILE_DEFAULT_CA_TARGET_CSS_SELECTOR;
use const Profile\Config\PROFILE_DEFAULT_CA_TARGET_HTML;

require_once __DIR__ . '/class-casapiauthclient.php';
use Profile\CasApiAuthClient\CasApiAuthClient;

require_once __DIR__ . '/class-casapiauthccsp.php';
use Profile\CasApiAuthClient\CasApiAuthCCSP;

require_once __DIR__ . '/debug.php';
use function Profile\Debug\debug;

require_once __DIR__ . '/url.php';
use function Profile\Url\add_page_query;

/**
 * Base64URL decode
 *
 * @param string $input Input string.
 * @return string
 */
function cam_base64url_decode( string $input ): string {
	$remainder = strlen( $input ) % 4;

	if ( $remainder ) {
		$input .= str_repeat( '=', 4 - $remainder );
	}

	$input = strtr( $input, '-_', '+/' );

	$decoded = base64_decode( $input, true );

	return false === $decoded ? '' : $decoded;
}

/**
 * CAS JWT payload を配列で返す
 *
 * @param string $jwt JWT string.
 * @return array
 */
function cam_decode_cas_jwt_payload( string $jwt ): array {
	$parts = explode( '.', $jwt );

	if ( count( $parts ) < 2 ) {
		return array();
	}

	$payload_json = cam_base64url_decode( $parts[1] );

	if ( '' === $payload_json ) {
		return array();
	}

	$payload = json_decode( $payload_json, true );

	return is_array( $payload ) ? $payload : array();
}

/**
 * 投稿タイトル要素の selector を返す
 *
 * 優先順位:
 * 1. 通常のページ/投稿タイトル領域（page-header / entry-header）
 *    - 見出し要素自身に op-body-* が付く場合
 *    - 見出し子要素に op-body-* が付く場合
 * 2. トップページ等のバナー見出し（banner-title）
 *    - 見出し要素自身に op-body-* が付く場合
 *    - 見出し子要素に op-body-* が付く場合
 * 3. 取れない場合は create_uca_list() と同じ ID 生成ルールでフォールバック
 *
 * @param \WP_Post $post Post object.
 * @return string
 */
function cam_get_post_title_target_selector( \WP_Post $post ): string {
	$post_title = (string) \get_the_title( $post );

	if ( '' === $post_title ) {
		debug( 'TITLE_SELECTOR_EMPTY_TITLE, post_id=' . $post->ID );
		return '';
	}

	// create_uca_list() とできるだけ近い形で HTML を組み立てる
	$content = \apply_filters( 'the_content', $post->post_content );

	$html  = '<div class="cam-title-check-root">';

	// 通常のページ/投稿タイトル領域
	$html .= '<header class="page-header">';
	$html .= '<h1 class="page-title">' . $post_title . '</h1>';
	$html .= '</header>';

	// トップページ・テーマのバナー見出し対策
	$html .= '<div id="wp-custom-header" class="wp-custom-header"></div>';
	$html .= '<div class="banner-caption">';
	$html .= '<div class="container">';
	$html .= '<h2 class="banner-title">' . $post_title . '</h2>';
	$html .= '</div>';
	$html .= '</div>';

	// 本文
	$html .= '<div class="entry-content">' . $content . '</div>';
	$html .= '</div>';

	$html = add_ids_to_paragraphs_for_ca( $html );

	libxml_use_internal_errors( true );

	$doc = new \DOMDocument();
	$loaded = $doc->loadHTML(
		'<?xml encoding="utf-8" ?>' . $html,
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);

	if ( $loaded ) {
		$xpath = new \DOMXPath( $doc );

		$queries = array(
			// 1) 通常のタイトル領域: 見出し要素自身に id が付く場合
			'//header[contains(@class,"page-header")]//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6][@id and starts-with(@id, "op-body-")]',
			'//header[contains(@class,"entry-header")]//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6][@id and starts-with(@id, "op-body-")]',

			// 2) 通常のタイトル領域: 見出しの子要素に id が付く場合
			'//header[contains(@class,"page-header")]//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]//*[@id and starts-with(@id, "op-body-")]',
			'//header[contains(@class,"entry-header")]//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]//*[@id and starts-with(@id, "op-body-")]',

			// 3) バナー見出し: 見出し要素自身に id が付く場合
			'//*[contains(@class,"banner-caption")]//*[contains(@class,"banner-title")][@id and starts-with(@id, "op-body-")]',

			// 4) バナー見出し: 子要素に id が付く場合
			'//*[contains(@class,"banner-caption")]//*[contains(@class,"banner-title")]//*[@id and starts-with(@id, "op-body-")]',

			// 5) wp-custom-header 近傍: 見出し要素自身に id が付く場合
			'//*[@id="wp-custom-header"]/following-sibling::*[contains(@class,"banner-caption")]//*[contains(@class,"banner-title")][@id and starts-with(@id, "op-body-")]',

			// 6) wp-custom-header 近傍: 子要素に id が付く場合
			'//*[@id="wp-custom-header"]/following-sibling::*[contains(@class,"banner-caption")]//*[contains(@class,"banner-title")]//*[@id and starts-with(@id, "op-body-")]',
		);

		foreach ( $queries as $query ) {
			$nodes = $xpath->query( $query );

			if ( $nodes && 0 < $nodes->length ) {
				$node = $nodes->item( 0 );

				if ( $node instanceof \DOMElement ) {
					$id = $node->getAttribute( 'id' );

					if ( '' !== $id ) {
						debug( 'TITLE_SELECTOR_DOM=#' . $id . ', post_id=' . $post->ID . ', query=' . $query );
						return '#' . $id;
					}
				}
			}
		}
	}

	// フォールバック
	$title_id = profile_paragraph_id_from_text( $post_title );

	debug( 'TITLE_SELECTOR_FALLBACK=#' . $title_id . ', post_id=' . $post->ID );

	if ( '' === $title_id ) {
		return '';
	}

	return '#' . $title_id;
}

/**
 * 既存CASの中に main記事CA があるか判定
 *
 * 判定条件:
 * - credentialSubject.type = Article
 * - credentialSubject.datePublished がある
 * - credentialSubject.dateModified がある
 * - target[0].type = TextTargetIntegrity
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function cam_has_main_article_ca( int $post_id ): bool {
	$post_id = \absint( $post_id );

	if ( ! $post_id ) {
		return false;
	}

	$post_cas = \get_post_meta( $post_id, '_profile_post_cas', true );

	$cas_count = \is_array( $post_cas ) ? count( $post_cas ) : 0;
	debug( "CAS_COUNT: post_id={$post_id}, cas_count={$cas_count}" );

	if ( ! \is_array( $post_cas ) || empty( $post_cas ) ) {
		return false;
	}

	foreach ( $post_cas as $cas_jwt ) {
		if ( ! \is_string( $cas_jwt ) || '' === $cas_jwt ) {
			continue;
		}

		$payload = cam_decode_cas_jwt_payload( $cas_jwt );

		if ( empty( $payload ) ) {
			continue;
		}

		if ( cam_is_main_article_ca_signature( $payload ) ) {
			$targets       = $payload['target'] ?? array();
			$first_target  = \is_array( $targets ) && ! empty( $targets ) ? ( $targets[0] ?? array() ) : array();
			$selector      = \is_array( $first_target ) ? ( $first_target['cssSelector'] ?? '' ) : '';

			debug( "MATCH_MAIN_CA_SIGNATURE: post_id={$post_id}, selector={$selector}" );
			return true;
		}
	}

	return false;
}

/**
 * main記事CAが未発行の投稿IDを取得
 *
 * @return array
 */
function cam_get_posts_without_main_article_ca(): array {
	$args = array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'date',
		'order'          => 'ASC',
	);

	$post_ids = \get_posts( $args );

	if ( ! \is_array( $post_ids ) || empty( $post_ids ) ) {
		return array();
	}

	$targets = array();

	foreach ( $post_ids as $post_id ) {
		$post_id = \absint( $post_id );

		if ( cam_should_skip_bulk_article_ca_post( $post_id ) ) {
			continue;
		}

		$has_ca = cam_has_main_article_ca( $post_id );

		debug( "CHECK_POST: post_id={$post_id}, has_main_article_ca=" . ( $has_ca ? 'true' : 'false' ) );

		if ( ! $has_ca ) {
			debug( "TARGET_POST: post_id={$post_id}" );
			$targets[] = $post_id;
		}
	}

	return $targets;
}

/**
 * payload が main記事CAの特徴を満たすか判定
 *
 * main記事CAの特徴:
 * - credentialSubject.type = Article
 * - credentialSubject.datePublished がある
 * - credentialSubject.dateModified がある
 * - target[0].type = TextTargetIntegrity
 *
 * @param array $payload Payload
 * @return bool
 */
function cam_is_main_article_ca_signature( array $payload ): bool {
	$subject      = $payload['credentialSubject'] ?? array();
	$subject_type = $subject['type'] ?? '';
	$date_pub     = $subject['datePublished'] ?? '';
	$date_mod     = $subject['dateModified'] ?? '';
	$targets      = $payload['target'] ?? array();

	if ( 'Article' !== $subject_type ) {
		return false;
	}

	if ( '' === $date_pub || '' === $date_mod ) {
		return false;
	}

	if ( ! \is_array( $targets ) || empty( $targets ) ) {
		return false;
	}

	$first_target = $targets[0] ?? array();

	if ( ! \is_array( $first_target ) ) {
		return false;
	}

	$target_type = $first_target['type'] ?? '';

	return 'TextTargetIntegrity' === $target_type;
}

/**
 * 一括記事CA発行の対象外にする投稿か判定
 *
 * 現時点ではトップページを対象外にする。
 *
 * @param int $post_id Post ID
 * @return bool
 */
function cam_should_skip_bulk_article_ca_post( int $post_id ): bool {
	$post_id = \absint( $post_id );

	if ( ! $post_id ) {
		return true;
	}

	$front_page_id = (int) \get_option( 'page_on_front' );

	if ( $front_page_id > 0 && $front_page_id === $post_id ) {
		debug( "SKIP_BULK_FRONT_PAGE: post_id={$post_id}" );
		return true;
	}

	return false;
}

/**
 * 記事CAの検証失敗リスクを簡易判定
 *
 * これは確定判定ではなく、HTML実体参照や引用符など、
 * ハッシュ不一致を起こしやすい文字を含む場合に注意扱いとする。
 *
 * @param \WP_Post $post Post object.
 * @return array
 */
function cam_detect_article_ca_warning_reasons( \WP_Post $post ): array {
	$reasons = array();

	$title   = (string) \get_the_title( $post );
	$content = (string) $post->post_content;
	$body    = $title . "\n" . $content;

	// HTML entity
	if ( preg_match( '/&(?:[a-zA-Z][a-zA-Z0-9]+|#\d+|#x[0-9A-Fa-f]+);/', $body ) ) {
		$reasons[] = 'HTML実体参照を含む';
	}

	// 単純な引用符・アポストロフィ・曲線引用符
	if ( preg_match( '/["\']|[’‘“”]/u', $body ) ) {
		$reasons[] = '引用符/アポストロフィを含む';
	}

	// ampersand
	if ( false !== strpos( $body, '&' ) ) {
		$reasons[] = '& を含む';
	}

	return array_values( array_unique( $reasons ) );
}

/**
 * 一括発行レポートを保存
 *
 * @param array $report Report data.
 * @return void
 */
function cam_store_bulk_article_ca_report( array $report ): void {
	$user_id = \get_current_user_id();

	if ( ! $user_id ) {
		return;
	}

	\set_transient( 'cam_bulk_article_ca_report_' . $user_id, $report, 10 * MINUTE_IN_SECONDS );
}

/**
 * 一括発行レポートを取得
 *
 * @return array
 */
function cam_get_bulk_article_ca_report(): array {
	$user_id = \get_current_user_id();

	if ( ! $user_id ) {
		return array();
	}

	$report = \get_transient( 'cam_bulk_article_ca_report_' . $user_id );

	return \is_array( $report ) ? $report : array();
}

/**
 * 一括発行レポートを削除
 *
 * @return void
 */
function cam_delete_bulk_article_ca_report(): void {
	$user_id = \get_current_user_id();

	if ( ! $user_id ) {
		return;
	}

	\delete_transient( 'cam_bulk_article_ca_report_' . $user_id );
}

/**
 * 記事CA用メタの現在値を取得
 *
 * @param int $post_id Post ID.
 * @return array
 */
function cam_get_article_meta_snapshot( int $post_id ): array {
	$post_id = \absint( $post_id );

	return array(
		'status' => (string) \get_post_meta( $post_id, '_cam_article_ca_status', true ),
		'editor' => (string) \get_post_meta( $post_id, '_cam_editor_name', true ),
		'author' => (string) \get_post_meta( $post_id, '_cam_author_name', true ),
	);
}

/**
 * 記事CA用メタを復元
 *
 * @param int   $post_id Post ID.
 * @param array $snapshot Snapshot.
 * @return void
 */
function cam_restore_article_meta_snapshot( int $post_id, array $snapshot ): void {
	$post_id = \absint( $post_id );

	if ( ! $post_id ) {
		return;
	}

	$status = isset( $snapshot['status'] ) ? (string) $snapshot['status'] : '';
	$editor = isset( $snapshot['editor'] ) ? (string) $snapshot['editor'] : '';
	$author = isset( $snapshot['author'] ) ? (string) $snapshot['author'] : '';

	if ( '' === $status ) {
		\delete_post_meta( $post_id, '_cam_article_ca_status' );
	} else {
		\update_post_meta( $post_id, '_cam_article_ca_status', $status );
	}

	if ( '' === $editor ) {
		\delete_post_meta( $post_id, '_cam_editor_name' );
	} else {
		\update_post_meta( $post_id, '_cam_editor_name', $editor );
	}

	if ( '' === $author ) {
		\delete_post_meta( $post_id, '_cam_author_name' );
	} else {
		\update_post_meta( $post_id, '_cam_author_name', $author );
	}
}

/**
 * 一括発行用に記事CAメタを一時適用
 *
 * @param int    $post_id Post ID.
 * @param string $editor_name Editor name.
 * @param string $author_name Author name.
 * @return void
 */
function cam_apply_bulk_article_meta_values( int $post_id, string $editor_name = '', string $author_name = '' ): void {
	$post_id = \absint( $post_id );

	if ( ! $post_id ) {
		return;
	}

	$editor_name = \trim( $editor_name );
	$author_name = \trim( $author_name );

	if ( '' !== $editor_name ) {
		\update_post_meta( $post_id, '_cam_editor_name', $editor_name );
	}

	if ( '' !== $author_name ) {
		\update_post_meta( $post_id, '_cam_author_name', $author_name );
	}
}

/**
 * CAS payload が main記事CA か判定
 *
 * @param array  $payload Payload
 * @param string $title_selector タイトル selector
 * @return bool
 */
function cam_is_main_article_ca_payload( array $payload, string $title_selector ): bool {
	$subject      = $payload['credentialSubject'] ?? array();
	$subject_type = $subject['type'] ?? '';
	$date_pub     = $subject['datePublished'] ?? '';
	$date_mod     = $subject['dateModified'] ?? '';
	$targets      = $payload['target'] ?? array();

	if ( 'Article' !== $subject_type ) {
		return false;
	}

	if ( '' === $date_pub || '' === $date_mod ) {
		return false;
	}

	if ( ! \is_array( $targets ) || empty( $targets ) ) {
		return false;
	}

	$first_target = $targets[0] ?? array();

	if ( ! \is_array( $first_target ) ) {
		return false;
	}

	$target_type = $first_target['type'] ?? '';
	$selector    = $first_target['cssSelector'] ?? '';

	if ( 'TextTargetIntegrity' !== $target_type ) {
		return false;
	}

	return $title_selector === $selector;
}

/**
 * 投稿の既存 CAS 配列を返す
 *
 * @param int $post_id Post ID
 * @return array
 */
function cam_get_existing_post_cas( int $post_id ): array {
	$post_id = \absint( $post_id );

	if ( ! $post_id ) {
		return array();
	}

	$post_cas = \get_post_meta( $post_id, '_profile_post_cas', true );

	return \is_array( $post_cas ) ? $post_cas : array();
}

/**
 * 記事CAだけを既存 CAS にマージ保存
 *
 * - 既存の embedded text / embedded image / ad は残す
 * - 既存の main記事CA があれば置き換える
 *
 * @param int   $post_id Post ID
 * @param array $new_cas_items 新しい CAS JWT 配列
 * @return bool
 */
/**
 * 記事CAだけを既存 CAS にマージ保存
 *
 * - 既存の embedded text / embedded image / ad は残す
 * - 既存の main記事CA があれば置き換える
 *
 * @param int   $post_id Post ID
 * @param array $new_cas_items 新しい CAS JWT 配列
 * @return bool
 */
function cam_merge_main_article_cas_into_post( int $post_id, array $new_cas_items ): bool {
	$post_id = \absint( $post_id );

	if ( ! $post_id ) {
		return false;
	}

	$post = \get_post( $post_id );

	if ( ! $post instanceof \WP_Post ) {
		return false;
	}

	$existing_cas = cam_get_existing_post_cas( $post_id );
	$kept_cas     = array();
	$new_main_cas = array();

	// 既存CASから main記事CA だけ除外し、それ以外は残す
	foreach ( $existing_cas as $existing_jwt ) {
		if ( ! \is_string( $existing_jwt ) || '' === $existing_jwt ) {
			continue;
		}

		$payload = cam_decode_cas_jwt_payload( $existing_jwt );

		if ( ! empty( $payload ) && cam_is_main_article_ca_signature( $payload ) ) {
			continue;
		}

		$kept_cas[] = $existing_jwt;
	}

	// 新しい記事CAを先頭用に集める
	foreach ( $new_cas_items as $new_jwt ) {
		if ( \is_string( $new_jwt ) && '' !== $new_jwt ) {
			$new_main_cas[] = $new_jwt;
		}
	}

	// main記事CAを先頭、その後ろに既存の埋め込みCA等を残す
	$merged_cas = array_merge( $new_main_cas, $kept_cas );

	\update_post_meta( $post_id, '_profile_post_cas', $merged_cas );
	\update_post_meta( $post_id, '_cam_article_ca_issued', '1' );
	\update_post_meta( $post_id, '_cam_article_ca_last_issued', \current_time( 'mysql' ) );
	\update_post_meta( $post_id, '_cam_article_ca_status', 'success' );

	debug( "cam_merge_main_article_cas_into_post saved _profile_post_cas for post_id={$post_id}" );

	return true;
}

/**
 * main記事CA 用の UCA を作る
 *
 * create_uca_list() の先頭要素（main UCA）だけを使う
 *
 * @param \WP_Post $post Post object
 * @param string   $issuer_id Issuer ID
 * @return Uca|false
 */
function cam_build_main_article_uca_for_post( \WP_Post $post, string $issuer_id ) {
	$uca_list = create_uca_list( $post, $issuer_id );

	if ( ! \is_array( $uca_list ) || empty( $uca_list ) ) {
		debug( "cam_build_main_article_uca_for_post: empty uca_list for post_id={$post->ID}" );
		return false;
	}

	$main_uca = $uca_list[0] ?? false;

	if ( ! $main_uca instanceof Uca ) {
		debug( "cam_build_main_article_uca_for_post: first UCA is invalid for post_id={$post->ID}" );
		return false;
	}

	// 念のため main記事CA か確認
	$main_json = $main_uca->to_json();

	debug( "MAIN_JSON_EXISTS=" . ( ( false !== $main_json && '' !== $main_json ) ? 'yes' : 'no' ) );

	if ( false === $main_json || '' === $main_json ) {
		debug( "cam_build_main_article_uca_for_post: main UCA json empty for post_id={$post->ID}" );
		return false;
	}

	$payload = \json_decode( $main_json, true );

	if ( ! \is_array( $payload ) ) {
		debug( "cam_build_main_article_uca_for_post: main UCA json decode failed for post_id={$post->ID}" );
		return false;
	}

	$subject = $payload['credentialSubject'] ?? [];
	$targets = $payload['target'] ?? [];

	debug( 'MAIN_SUBJECT_TYPE=' . ( $subject['type'] ?? '' ) );
	debug( 'MAIN_HEADLINE=' . ( $subject['headline'] ?? '' ) );
	debug( 'MAIN_TARGET_COUNT_FROM_JSON=' . ( \is_array( $targets ) ? \count( $targets ) : 0 ) );

	if ( \is_array( $targets ) && ! empty( $targets ) ) {
		$ft = $targets[0];

		debug(
			'MAIN_FIRST_TARGET'
			. ' type=' . ( $ft['type'] ?? '' )
			. ', selector=' . ( $ft['cssSelector'] ?? '' )
			. ', integrity=' . ( empty( $ft['integrity'] ) ? '(empty)' : $ft['integrity'] )
		);
	}

	$title_selector = cam_get_post_title_target_selector( $post );

	if ( ! cam_is_main_article_ca_signature( $payload ) ) {
		debug( "cam_build_main_article_uca_for_post: first UCA is not main article signature for post_id={$post->ID}" );
		return false;
	}

	return $main_uca;
}

/**
 * 投稿1件に対して main記事CA だけを発行
 *
 * @param int $post_id Post ID
 * @return bool
 */
/**
 * 投稿1件に対して main記事CA だけを発行
 *
 * @param int    $post_id Post ID
 * @param string $editor_name 編集責任者
 * @param string $author_name 執筆者
 * @return bool
 */
function cam_issue_main_article_ca_for_post( int $post_id, string $editor_name = '', string $author_name = '' ): bool {
	$post_id = \absint( $post_id );

	if ( ! $post_id ) {
		return false;
	}

	$post = \get_post( $post_id );

	if ( ! $post instanceof \WP_Post ) {
		debug( "cam_issue_main_article_ca_for_post: post not found, post_id={$post_id}" );
		return false;
	}

	if ( 'publish' !== $post->post_status ) {
		debug( "cam_issue_main_article_ca_for_post: post not publish, post_id={$post_id}" );
		return false;
	}

	if ( ! \in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		debug( "cam_issue_main_article_ca_for_post: unsupported post_type={$post->post_type}, post_id={$post_id}" );
		return false;
	}

	// 既に main記事CA があれば安全のため何もしない
	if ( cam_has_main_article_ca( $post_id ) ) {
		debug( "cam_issue_main_article_ca_for_post: skip existing main article ca, post_id={$post_id}" );
		return true;
	}

	$admin_secret = \get_option( 'profile_ca_server_admin_secret' );
	$hostname     = \get_option( 'profile_ca_server_hostname', PROFILE_DEFAULT_CA_SERVER_HOSTNAME );
	$issuer_id    = \get_option( 'profile_ca_issuer_id' );
	$endpoint     = "https://{$hostname}/ca";

	if ( ! $admin_secret || ! $issuer_id ) {
		debug( 'cam_issue_main_article_ca_for_post: missing admin_secret or issuer_id' );
		return false;
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && 'localhost' === $hostname ) {
		$in_docker = \file_exists( '/.dockerenv' );
		if ( $in_docker ) {
			$endpoint = 'http://host.docker.internal:8080/ca';
		} else {
			$endpoint = 'http://localhost:8080/ca';
		}
	}

	$snapshot = cam_get_article_meta_snapshot( $post_id );

	// 一括入力値を一時適用（CA生成に使う）
	cam_apply_bulk_article_meta_values( $post_id, $editor_name, $author_name );

	$main_uca = cam_build_main_article_uca_for_post( $post, (string) $issuer_id );

	if ( false === $main_uca ) {
		cam_restore_article_meta_snapshot( $post_id, $snapshot );
		debug( "cam_issue_main_article_ca_for_post: failed to build main UCA, post_id={$post_id}" );
		return false;
	}

	$cas = issue_ca( $main_uca, $endpoint, (string) $admin_secret );

	if ( false === $cas || empty( $cas ) ) {
		cam_restore_article_meta_snapshot( $post_id, $snapshot );
		debug( "cam_issue_main_article_ca_for_post: issue_ca failed, post_id={$post_id}" );
		return false;
	}

	$cas_items = \is_array( $cas ) ? $cas : array( $cas );
	$cas_items = array_values(
		array_filter(
			$cas_items,
			static function ( $item ) {
				return \is_string( $item ) && '' !== $item;
			}
		)
	);

	if ( empty( $cas_items ) ) {
		cam_restore_article_meta_snapshot( $post_id, $snapshot );
		debug( "cam_issue_main_article_ca_for_post: no cas items returned, post_id={$post_id}" );
		return false;
	}

	$result = cam_merge_main_article_cas_into_post( $post_id, $cas_items );

	if ( ! $result ) {
		cam_restore_article_meta_snapshot( $post_id, $snapshot );
		return false;
	}

	// 成功後は UI と整合する記事CAメタを残す
	if ( '' !== \trim( $editor_name ) ) {
		\update_post_meta( $post_id, '_cam_editor_name', \trim( $editor_name ) );
	}

	if ( '' !== \trim( $author_name ) ) {
		\update_post_meta( $post_id, '_cam_author_name', \trim( $author_name ) );
	}

	\update_post_meta( $post_id, '_cam_article_ca_status', 'success' );

	return true;
}

/**
 * 記事CA一括発行ハンドラ
 *
 * - main記事CA が無いページだけ対象
 * - 既存の embedded CA は触らない
 *
 * @return void
 */
function cam_handle_bulk_issue_article_ca() {
	if ( ! \current_user_can( 'manage_options' ) ) {
		\wp_die( 'この操作を実行する権限がありません。' );
	}

	\check_admin_referer( 'cam_bulk_issue_article_ca_action', 'cam_bulk_issue_article_ca_nonce' );

	$editor_name = isset( $_POST['cam_bulk_editor_name'] )
		? \sanitize_text_field( \wp_unslash( $_POST['cam_bulk_editor_name'] ) )
		: '';

	$author_name = isset( $_POST['cam_bulk_author_name'] )
		? \sanitize_text_field( \wp_unslash( $_POST['cam_bulk_author_name'] ) )
		: '';

	$post_ids = cam_get_posts_without_main_article_ca();

	$total    = \count( $post_ids );
	$success  = 0;
	$skipped  = 0;
	$failed   = 0;
	$warnings = 0;

	$failed_items  = array();
	$warning_items = array();

	debug( "cam_handle_bulk_issue_article_ca start: total={$total}" );

	foreach ( $post_ids as $post_id ) {
		$post_id = \absint( $post_id );

		$post = \get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			$failed++;
			$failed_items[] = array(
				'post_id'   => $post_id,
				'title'     => '(post not found)',
				'edit_url'  => \admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
				'view_url'  => '',
				'reason'    => '投稿が見つからない',
			);
			continue;
		}

		if ( cam_has_main_article_ca( $post_id ) ) {
			$skipped++;
			continue;
		}

		$result = cam_issue_main_article_ca_for_post( $post_id, $editor_name, $author_name );

		if ( $result ) {
			$success++;

			$warning_reasons = cam_detect_article_ca_warning_reasons( $post );

			if ( ! empty( $warning_reasons ) ) {
				$warnings++;
				$warning_items[] = array(
					'post_id'   => $post_id,
					'title'     => \get_the_title( $post ),
					'edit_url'  => \admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
					'view_url'  => \get_permalink( $post_id ),
					'reason'    => implode( ' / ', $warning_reasons ),
				);
			}
		} else {
			$failed++;

			$failed_items[] = array(
				'post_id'   => $post_id,
				'title'     => \get_the_title( $post ),
				'edit_url'  => \admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
				'view_url'  => \get_permalink( $post_id ),
				'reason'    => '記事CAの組み立てまたは発行に失敗',
			);
		}
	}

	cam_store_bulk_article_ca_report(
		array(
			'failed_items'  => $failed_items,
			'warning_items' => $warning_items,
		)
	);

	debug( "cam_handle_bulk_issue_article_ca done: total={$total}, success={$success}, skipped={$skipped}, failed={$failed}, warnings={$warnings}" );

	$redirect_url = \add_query_arg(
		array(
			'page'                => 'ca-manager',
			'cam_bulk_issue_done' => 1,
			'total'               => $total,
			'success'             => $success,
			'skipped'             => $skipped,
			'failed'              => $failed,
			'warnings'            => $warnings,
		),
		\admin_url( 'options-general.php' )
	);

	\wp_safe_redirect( $redirect_url );
	exit;
}

/** 投稿への署名処理の初期化 */
function init() {
	\add_action( 'transition_post_status', '\Profile\Issue\sign_post', 10, 3 );
	\add_action( 'save_post', '\Profile\Issue\sign_post_on_save', 20, 3 );
	\add_action( 'admin_post_cam_bulk_issue_article_ca', '\Profile\Issue\cam_handle_bulk_issue_article_ca' );
	\add_filter( 'wp_generate_attachment_metadata', '\Profile\Issue\update_attachment_integrity_metadata', 10, 2 );
	\add_filter( 'the_content', '\Profile\Issue\inject_text_target_ids_into_front_html', 98 );
	\add_filter( 'the_content', '\Profile\Issue\inject_embedded_image_ids_into_front_html', 99 );
}

/**
 * 公開済み記事を更新したときの再発行
 *
 * @param int      $post_id Post ID
 * @param \WP_Post $post Post object
 * @param bool     $update 更新かどうか
 */
function sign_post_on_save( int $post_id, \WP_Post $post, bool $update ) {
	debug( "sign_post_on_save called: post_id={$post_id}, post_type={$post->post_type}, post_status={$post->post_status}, update=" . ( $update ? 'true' : 'false' ) );

	if ( ! $update ) {
		debug( "sign_post_on_save skip: update is false for post_id={$post_id}" );
		return;
	}

	if ( \wp_is_post_revision( $post_id ) ) {
		debug( "sign_post_on_save skip: revision for post_id={$post_id}" );
		return;
	}

	if ( \wp_is_post_autosave( $post_id ) ) {
		debug( "sign_post_on_save skip: autosave for post_id={$post_id}" );
		return;
	}

	if ( 'publish' !== $post->post_status ) {
		debug( "sign_post_on_save skip: post_status={$post->post_status} for post_id={$post_id}" );
		return;
	}

	if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		debug( "sign_post_on_save skip: unsupported post_type={$post->post_type} for post_id={$post_id}" );
		return;
	}

	debug( "sign_post_on_save run issue_post_cas for post_id={$post_id}" );
	issue_post_cas( $post );
}

/**
 * 初回公開時の署名
 *
 * @param string   $new_status New post status.
 * @param string   $old_status Old post status.
 * @param \WP_Post $post Post object.
 */
function sign_post( string $new_status, string $old_status, \WP_Post $post ) {
	if ( 'publish' !== $new_status ) {
		debug( "Post status is '{$new_status}', not 'publish'. Skipping CA signing." );
		return;
	}

	if ( 'publish' === $old_status ) {
		debug( "Post ID {$post->ID} is already published. save_post hook will handle updates. Skipping transition hook signing." );
		return;
	}

	if ( \wp_is_post_revision( $post->ID ) || \wp_is_post_autosave( $post->ID ) ) {
		debug( "Post ID {$post->ID} is revision/autosave. Skipping." );
		return;
	}

	if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		debug( "Post type '{$post->post_type}' is not supported. Skipping." );
		return;
	}

	issue_post_cas( $post );
}

/**
 * 共通の CA 発行処理
 *
 * @param \WP_Post $post Post object.
 */
function issue_post_cas( \WP_Post $post ) {
	debug( "issue_post_cas start: post_id={$post->ID}" );

	foreach ( \get_attached_media( 'image', $post->ID ) as $attachment ) {
		$metadata = \wp_get_attachment_metadata( $attachment->ID );
		update_attachment_integrity_metadata( $metadata, $attachment->ID );
	}

	$embedded_items = \get_post_meta( $post->ID, '_cam_embedded_items', true );

	if ( \is_array( $embedded_items ) && ! empty( $embedded_items ) ) {
		foreach ( $embedded_items as $item ) {
			$kind      = isset( $item['kind'] ) ? (string) $item['kind'] : '';
			$image_url = isset( $item['image_url'] ) ? (string) $item['image_url'] : '';

			if ( 'image' !== $kind || '' === $image_url ) {
				continue;
			}

			$attachment_id = \attachment_url_to_postid( $image_url );

			if ( ! $attachment_id ) {
				debug( 'issue_post_cas embedded image attachment not found for url=' . $image_url );
				continue;
			}

			$existing_integrity = \get_post_meta( $attachment_id, '_profile_attachment_integrity', true );
			if ( \is_array( $existing_integrity ) && ! empty( $existing_integrity ) ) {
				debug( 'issue_post_cas embedded image integrity already exists for attachment_id=' . $attachment_id );
				continue;
			}

			$metadata = \wp_get_attachment_metadata( $attachment_id );
			if ( ! \is_array( $metadata ) || empty( $metadata['file'] ) ) {
				debug( 'issue_post_cas embedded image metadata missing for attachment_id=' . $attachment_id );
				continue;
			}

			update_attachment_integrity_metadata( $metadata, $attachment_id );
			debug( 'issue_post_cas generated integrity for embedded attachment_id=' . $attachment_id );
		}
	}

	$admin_secret = \get_option( 'profile_ca_server_admin_secret' );
	$hostname     = \get_option( 'profile_ca_server_hostname', PROFILE_DEFAULT_CA_SERVER_HOSTNAME );
	$issuer_id    = \get_option( 'profile_ca_issuer_id' );
	$endpoint     = "https://{$hostname}/ca";

	debug( "issue_post_cas config: hostname={$hostname}, issuer_id=" . ( $issuer_id ? $issuer_id : '(empty)' ) . ", admin_secret=" . ( $admin_secret ? '(set)' : '(empty)' ) );

	if ( ! $admin_secret || ! $issuer_id ) {
		debug( 'Missing required CA server configuration (admin_secret or issuer_id)' );
		return;
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && 'localhost' === $hostname ) {
		$in_docker = \file_exists( '/.dockerenv' );
		if ( $in_docker ) {
			$endpoint = 'http://host.docker.internal:8080/ca';
		} else {
			$endpoint = 'http://localhost:8080/ca';
		}
	}

	debug( "issue_post_cas endpoint: {$endpoint}" );

	$uca_list = create_uca_list( $post, $issuer_id );

	debug( 'issue_post_cas uca_list count: ' . count( $uca_list ) );

	if ( empty( $uca_list ) ) {
		debug( "UCA list is empty for post ID: {$post->ID}" );
		return;
	}

	$post_cas = array();

	foreach ( $uca_list as $index => $uca ) {
		$seq = $index + 1;
		debug( "issue_post_cas issuing seq={$seq}" );

		$cas = issue_ca( $uca, $endpoint, $admin_secret );

		if ( false === $cas || empty( $cas ) ) {
			debug( "Failed to issue CA for post ID: {$post->ID}, sequence: {$seq}" );
			continue;
		}

		debug( "issue_post_cas success seq={$seq}" );

		$cas_items = is_array( $cas ) ? $cas : array();

		foreach ( $cas_items as $cas_item ) {
			if ( is_string( $cas_item ) && '' !== $cas_item ) {
				$post_cas[] = $cas_item;
			}
		}
	}

	debug( 'ISSUE_POST_CAS=' . \wp_json_encode( $post_cas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

	if ( ! empty( $post_cas[0] ) && is_string( $post_cas[0] ) ) {
		debug( 'FIRST_CAS_JWT=' . $post_cas[0] );
	}

	\update_post_meta( $post->ID, '_profile_post_cas', $post_cas );
	debug( "issue_post_cas saved _profile_post_cas for post_id={$post->ID}" );

	$saved_post_cas = \get_post_meta( $post->ID, '_profile_post_cas', true );
	debug(
		'SAVED_PROFILE_POST_CAS=' .
		\wp_json_encode( $saved_post_cas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
	);
}

/**
 * 添付ファイルの整合性メタデータの更新
 *
 * @param array $metadata Attachment metadata.
 * @param int   $attachment_id Attachment ID.
 * @return array Attachment metadata.
 */
function update_attachment_integrity_metadata( array $metadata, int $attachment_id ): array {
	$original_file = WP_CONTENT_DIR . "/uploads/{$metadata['file']}";
	$integrity     = array();

	$integrity['full'] = create_integrity( $original_file );

	foreach ( $metadata['sizes'] as $size => $data ) {
		if ( ! isset( $data['file'] ) ) {
			debug( "Size '{$size}' has no file data for attachment ID: {$attachment_id}" );
			continue;
		}

		$file               = \dirname( $original_file ) . "/{$data['file']}";
		$integrity[ $size ] = create_integrity( $file );
	}

	\update_post_meta( $attachment_id, '_profile_attachment_integrity', $integrity );

	return $metadata;
}

/**
 * Integrity の計算
 *
 * @param string $file ファイルパス
 * @return string Integrity Metadata
 */
function create_integrity( string $file ): string {
	$alg  = 'sha256';
	$hash = \hash_file( $alg, $file, true );
	$val  = \base64_encode( $hash ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	return "{$alg}-{$val}";
}

/**
 * 画像URLに対応する integrity を添付メタから取得
 *
 * @param string $image_url 画像URL
 * @return ?string
 */
function find_attachment_integrity_by_image_url( string $image_url ): ?string {
	if ( '' === trim( $image_url ) ) {
		return null;
	}

	$attachment_id   = \attachment_url_to_postid( $image_url );
	$target_basename = \wp_basename( \wp_parse_url( $image_url, PHP_URL_PATH ) ?: '' );

	// attachment_url_to_postid() で取れない場合は basename から逆引き
	if ( ! $attachment_id && '' !== $target_basename ) {
		$attachments = \get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'post_mime_type' => 'image',
				'fields'         => 'ids',
			)
		);

		foreach ( $attachments as $candidate_id ) {
			$metadata = \wp_get_attachment_metadata( $candidate_id );
			if ( ! is_array( $metadata ) ) {
				continue;
			}

			// フルサイズ一致
			if ( isset( $metadata['file'] ) && $target_basename === \wp_basename( $metadata['file'] ) ) {
				$attachment_id = (int) $candidate_id;
				break;
			}

			// 生成サイズ一致
			if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size => $data ) {
					if ( ! isset( $data['file'] ) ) {
						continue;
					}

					if ( $target_basename === \wp_basename( $data['file'] ) ) {
						$attachment_id = (int) $candidate_id;
						break 2;
					}
				}
			}
		}
	}

	if ( ! $attachment_id ) {
		debug( 'find_attachment_integrity_by_image_url: attachment not found for url=' . $image_url );
		return null;
	}

	$integrities = \get_post_meta( $attachment_id, '_profile_attachment_integrity', true );
	if ( ! is_array( $integrities ) || empty( $integrities ) ) {
		debug( 'find_attachment_integrity_by_image_url: integrity meta missing for attachment_id=' . $attachment_id );
		return null;
	}

	$metadata = \wp_get_attachment_metadata( $attachment_id );
	if ( ! is_array( $metadata ) ) {
		debug( 'find_attachment_integrity_by_image_url: attachment metadata missing for attachment_id=' . $attachment_id );
		return null;
	}

	// フルサイズ一致
	if (
		isset( $metadata['file'], $integrities['full'] ) &&
		$target_basename === \wp_basename( $metadata['file'] )
	) {
		return is_string( $integrities['full'] ) ? $integrities['full'] : null;
	}

	// 各サイズ一致
	if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
		foreach ( $metadata['sizes'] as $size => $data ) {
			if ( ! isset( $data['file'], $integrities[ $size ] ) ) {
				continue;
			}

			if ( $target_basename === \wp_basename( $data['file'] ) ) {
				return is_string( $integrities[ $size ] ) ? $integrities[ $size ] : null;
			}
		}
	}

	debug( 'find_attachment_integrity_by_image_url: no matching integrity for url=' . $image_url );
	return null;
}

/**
 * 画像URLに対応する attachment の全 integrity を連結して返す
 *
 * @param string $image_url 画像URL
 * @return ?string
 */
function find_attachment_all_integrities_by_image_url( string $image_url ): ?string {
	if ( '' === trim( $image_url ) ) {
		return null;
	}

	$attachment_id   = \attachment_url_to_postid( $image_url );
	$target_basename = \wp_basename( \wp_parse_url( $image_url, PHP_URL_PATH ) ?: '' );

	if ( ! $attachment_id && '' !== $target_basename ) {
		$attachments = \get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'post_mime_type' => 'image',
				'fields'         => 'ids',
			)
		);

		foreach ( $attachments as $candidate_id ) {
			$metadata = \wp_get_attachment_metadata( $candidate_id );
			if ( ! is_array( $metadata ) ) {
				continue;
			}

			if ( isset( $metadata['file'] ) && $target_basename === \wp_basename( $metadata['file'] ) ) {
				$attachment_id = (int) $candidate_id;
				break;
			}

			if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size => $data ) {
					if ( ! isset( $data['file'] ) ) {
						continue;
					}

					if ( $target_basename === \wp_basename( $data['file'] ) ) {
						$attachment_id = (int) $candidate_id;
						break 2;
					}
				}
			}
		}
	}

	if ( ! $attachment_id ) {
		debug( 'find_attachment_all_integrities_by_image_url: attachment not found for url=' . $image_url );
		return null;
	}

	$integrities = \get_post_meta( $attachment_id, '_profile_attachment_integrity', true );
	if ( ! is_array( $integrities ) || empty( $integrities ) ) {
		debug( 'find_attachment_all_integrities_by_image_url: integrity meta missing for attachment_id=' . $attachment_id );
		return null;
	}

	$all = array();

	if ( isset( $integrities['full'] ) && is_string( $integrities['full'] ) && '' !== $integrities['full'] ) {
		$all[] = $integrities['full'];
	}

	foreach ( $integrities as $key => $value ) {
		if ( 'full' === $key ) {
			continue;
		}
		if ( is_string( $value ) && '' !== $value ) {
			$all[] = $value;
		}
	}

	$all = array_values( array_unique( $all ) );

	return ! empty( $all ) ? implode( ' ', $all ) : null;
}

/**
 * 本文テキスト正規化
 *
 * @param string $text テキスト
 * @return string
 */
function profile_normalize_paragraph_text( string $text ): string {
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( '/[\x{00A0}\s]+/u', ' ', $text );
	return trim( $text );
}

/**
 * 埋め込みマッチ用の強力な正規化
 *
 * @param string $text テキスト
 * @return string
 */
function profile_normalize_embedded_match_text( string $text ): string {
	$text = wp_strip_all_tags( $text );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	$text = str_replace(
		array('“','”','„','‟','«','»','"','＂','‘','’','‚','‛',"'","ʼ","`","´","「","」","『","』"),
		'',
		$text
	);

	$text = str_replace(
		array("–","—","―","‐","‒","−"),
		'-',
		$text
	);

	$text = str_replace("\xE3\x80\x80", ' ', $text);
	$text = preg_replace('/\s+/u', ' ', $text);

	return trim($text);
}

/**
 * 埋め込み照合用トークン配列
 *
 * @param string $text テキスト
 * @return array
 */
function profile_embedded_match_tokens( string $text ): array {
	$text = profile_normalize_embedded_match_text( $text );

	if ( '' === $text ) {
		return array();
	}

	$parts = preg_split( '/[\s\-—–―、。,.!?:;()\[\]{}\/]+/u', $text );
	if ( ! is_array( $parts ) ) {
		return array();
	}

	$parts = array_filter(
		array_map(
			static function ( $v ) {
				return trim( (string) $v );
			},
			$parts
		),
		static function ( $v ) {
			return '' !== $v && mb_strlen( $v ) >= 2;
		}
	);

	return array_values( array_unique( $parts ) );
}

/**
 * トークン重なりスコア
 *
 * @param array $a tokens
 * @param array $b tokens
 * @return float
 */
function profile_embedded_token_overlap_score( array $a, array $b ): float {
	if ( empty( $a ) || empty( $b ) ) {
		return 0.0;
	}

	$intersection = array_intersect( $a, $b );
	return count( $intersection ) / max( count( $a ), count( $b ) );
}

/**
 * テキストから安定 ID を作る
 *
 * @param string $text テキスト
 * @return string
 */
function profile_paragraph_id_from_text( string $text ): string {
	$normalized = profile_normalize_paragraph_text( $text );
	return 'op-body-' . substr( sha1( $normalized ), 0, 12 );
}

/**
 * ID 付与対象タグ
 *
 * @return array
 */
function profile_target_tag_names(): array {
	return array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote', 'figcaption', 'pre' );
}

/**
 * ID 付与対象 XPath
 *
 * @return string
 */
function profile_target_xpath(): string {
	return '//p'
		. ' | //h1'
		. ' | //h2'
		. ' | //h3'
		. ' | //h4'
		. ' | //h5'
		. ' | //h6'
		. ' | //ul'
		. ' | //ol'
		. ' | //blockquote'
		. ' | //figcaption'
		. ' | //pre';
}

/**
 * ID 付与対象から除外するか
 *
 * @param \DOMElement $el DOM element
 * @return bool
 */
function profile_should_skip_text_target_node( \DOMElement $el ): bool {
	$tag = strtolower( $el->tagName );

	if ( ! in_array( $tag, profile_target_tag_names(), true ) ) {
		return true;
	}

	$link = $el->getElementsByTagName( 'a' )->item( 0 );
	if ( $link instanceof \DOMElement ) {
		$href = $link->getAttribute( 'href' );
		if (
			str_contains( $href, 'instagram.com/reel/' ) ||
			str_contains( $href, 'utm_source=ig_embed' ) ||
			str_contains( $href, 'utm_campaign=loading' )
		) {
			return true;
		}
	}

	$text = profile_normalize_paragraph_text( $el->textContent );
	return '' === $text;
}

/**
 * 対象タグへ ID を付与
 *
 * @param string $html HTML
 * @return string
 */
function add_ids_to_paragraphs_for_ca( string $html ): string {
	$target_tags = profile_target_tag_names();
	$has_target  = false;

	foreach ( $target_tags as $tag ) {
		if ( false !== stripos( $html, '<' . $tag ) ) {
			$has_target = true;
			break;
		}
	}

	if ( ! $has_target ) {
		return $html;
	}

	libxml_use_internal_errors( true );

	$doc = new \DOMDocument();
	$loaded = $doc->loadHTML(
		'<?xml encoding="utf-8" ?>' . $html,
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);

	if ( ! $loaded ) {
		debug( 'add_ids_to_paragraphs_for_ca: DOM loadHTML failed' );
		return $html;
	}

	$xpath = new \DOMXPath( $doc );
	$nodes = $xpath->query( profile_target_xpath() );

	if ( ! $nodes ) {
		debug( 'add_ids_to_paragraphs_for_ca: xpath query failed' );
		return $html;
	}

	$seen_ids = array();

	foreach ( $nodes as $node ) {
		if ( ! $node instanceof \DOMElement ) {
			continue;
		}

		if ( profile_should_skip_text_target_node( $node ) ) {
			continue;
		}

		if ( $node->hasAttribute( 'id' ) ) {
			$current_id = (string) $node->getAttribute( 'id' );

			// 既存の op-body-* は一度捨てて再計算する
			if ( 0 !== strpos( $current_id, 'op-body-' ) ) {
				continue;
			}

			$node->removeAttribute( 'id' );
		}

		$text    = profile_normalize_paragraph_text( $node->textContent );
		$base_id = profile_paragraph_id_from_text( $text );
		$final_id = $base_id;

		if ( ! isset( $seen_ids[ $base_id ] ) ) {
			$seen_ids[ $base_id ] = 1;
		} else {
			$seen_ids[ $base_id ]++;
			$final_id = $base_id . '-' . $seen_ids[ $base_id ];
			debug( 'add_ids_to_paragraphs_for_ca duplicate id adjusted: ' . $base_id . ' -> ' . $final_id );
		}

		$node->setAttribute( 'id', $final_id );
	}

	return $doc->saveHTML();
}

/**
 * CSS selector (#id のみ) で対象 HTML を抜き出す
 *
 * @param string $html HTML
 * @param string $selector CSS selector
 * @return string
 */
function extract_target_html_by_selector( string $html, string $selector ): string {
	if ( ! str_starts_with( $selector, '#' ) ) {
		debug( "extract_target_html_by_selector: selector is not id selector: {$selector}" );
		return '';
	}

	$id = substr( $selector, 1 );

	libxml_use_internal_errors( true );

	$doc = new \DOMDocument();
	$loaded = $doc->loadHTML(
		'<?xml encoding="utf-8" ?>' . $html,
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);

	if ( ! $loaded ) {
		debug( "extract_target_html_by_selector: DOM loadHTML failed for selector={$selector}" );
		return '';
	}

	$xpath = new \DOMXPath( $doc );
	$nodes = $xpath->query( '//*[@id="' . $id . '"]' );

	if ( ! $nodes || 0 === $nodes->length ) {
		debug( "extract_target_html_by_selector: no node found for selector={$selector}" );
		return '';
	}

	$node_html = $doc->saveHTML( $nodes->item( 0 ) );
	return \is_string( $node_html ) ? $node_html : '';
}

/**
 * 画像URLから対象HTMLを抜き出す
 *
 * @param string $html HTML
 * @param string $image_url image URL
 * @return string
 */
function extract_image_html_by_url( string $html, string $image_url ): string {
	if ( '' === trim( $html ) || '' === trim( $image_url ) ) {
		return '';
	}

	libxml_use_internal_errors( true );

	$doc = new \DOMDocument();
	$loaded = $doc->loadHTML(
		'<?xml encoding="utf-8" ?>' . $html,
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);

	if ( ! $loaded ) {
		libxml_clear_errors();
		return '';
	}

	$xpath = new \DOMXPath( $doc );
	$imgs  = $xpath->query( '//img[@src]' );

	if ( ! $imgs ) {
		libxml_clear_errors();
		return '';
	}

	$target_basename = \wp_basename( \wp_parse_url( $image_url, PHP_URL_PATH ) ?: '' );

	foreach ( $imgs as $img ) {
		if ( ! $img instanceof \DOMElement ) {
			continue;
		}

		$src = (string) $img->getAttribute( 'src' );
		if ( '' === $src ) {
			continue;
		}

		$src_basename = \wp_basename( \wp_parse_url( $src, PHP_URL_PATH ) ?: '' );

		if ( $src === $image_url || $src_basename === $target_basename ) {
			$node = $img;

			if ( $img->parentNode instanceof \DOMElement && 'figure' === strtolower( $img->parentNode->nodeName ) ) {
				$node = $img->parentNode;
			}

			$result = $doc->saveHTML( $node );
			libxml_clear_errors();

			return is_string( $result ) ? $result : '';
		}
	}

	libxml_clear_errors();
	return '';
}

/**
 * 埋め込み画像に安定した id を付与する
 *
 * @param string $html HTML
 * @param array  $embedded_items _cam_embedded_items
 * @return string
 */
function add_ids_to_embedded_images_for_ca( string $html, array $embedded_items ): string {
	if ( '' === trim( $html ) || empty( $embedded_items ) ) {
		return $html;
	}

	libxml_use_internal_errors( true );

	$doc = new \DOMDocument();
	$loaded = $doc->loadHTML(
		'<?xml encoding="utf-8" ?>' . $html,
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);

	if ( ! $loaded ) {
		libxml_clear_errors();
		debug( 'add_ids_to_embedded_images_for_ca: DOM loadHTML failed' );
		return $html;
	}

	$xpath = new \DOMXPath( $doc );
	$imgs  = $xpath->query( '//img[@src]' );

	if ( ! $imgs ) {
		libxml_clear_errors();
		return $html;
	}

	foreach ( $embedded_items as $item ) {
		$kind      = isset( $item['kind'] ) ? (string) $item['kind'] : 'article';
		$image_url = isset( $item['image_url'] ) ? (string) $item['image_url'] : '';

		if ( 'image' !== $kind || '' === $image_url ) {
			continue;
		}

		$target_basename = \wp_basename( \wp_parse_url( $image_url, PHP_URL_PATH ) ?: '' );
		$target_id       = 'op-image-' . substr( sha1( $image_url ), 0, 8 );

		foreach ( $imgs as $img ) {
			if ( ! $img instanceof \DOMElement ) {
				continue;
			}

			$src = (string) $img->getAttribute( 'src' );
			if ( '' === $src ) {
				continue;
			}

			$src_basename = \wp_basename( \wp_parse_url( $src, PHP_URL_PATH ) ?: '' );

			if ( $src === $image_url || $src_basename === $target_basename ) {
				$img->setAttribute( 'id', $target_id );

				debug( 'add_ids_to_embedded_images_for_ca assigned id=' . $target_id . ' for image_url=' . $image_url );

				break;
			}
		}
	}

	$result = $doc->saveHTML();
	libxml_clear_errors();

	return is_string( $result ) ? $result : $html;
}

/**
 * 公開HTMLにも本文 target 用 id を付与する
 *
 * @param string $content the_content 後のHTML
 * @return string
 */
function inject_text_target_ids_into_front_html( string $content ): string {
	if ( ! \is_singular( array( 'post', 'page' ) ) ) {
		return $content;
	}

	if ( '' === trim( $content ) ) {
		return $content;
	}

	debug( 'inject_text_target_ids_into_front_html called' );

	return add_ids_to_paragraphs_for_ca( $content );
}

/**
 * 公開HTMLにも埋め込み画像 id を付与する
 *
 * @param string $content the_content 後のHTML
 * @return string
 */
function inject_embedded_image_ids_into_front_html( string $content ): string {
	if ( ! \is_singular( array( 'post', 'page' ) ) ) {
		return $content;
	}

	$post_id = \get_the_ID();
	if ( ! $post_id ) {
		return $content;
	}

	$embedded_items = \get_post_meta( $post_id, '_cam_embedded_items', true );
	if ( ! \is_array( $embedded_items ) || empty( $embedded_items ) ) {
		return $content;
	}

	return add_ids_to_embedded_images_for_ca( $content, $embedded_items );
}

/**
 * 指定 selector の id を本文HTMLから外す
 *
 * @param string $html HTML
 * @param array  $selectors #id selector の配列
 * @return string
 */
function remove_target_nodes_from_html( string $html, array $selectors ): string {
	if ( '' === trim( $html ) || empty( $selectors ) ) {
		return $html;
	}

	libxml_use_internal_errors( true );

	$doc = new \DOMDocument();
	$loaded = $doc->loadHTML(
		'<?xml encoding="utf-8" ?>' . $html,
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);

	if ( ! $loaded ) {
		libxml_clear_errors();
		return $html;
	}

	$xpath = new \DOMXPath( $doc );

	foreach ( $selectors as $selector ) {
		if ( ! is_string( $selector ) || '' === $selector || ! str_starts_with( $selector, '#' ) ) {
			continue;
		}

		$id = substr( $selector, 1 );
		if ( '' === $id ) {
			continue;
		}

		$nodes = $xpath->query( '//*[@id="' . $id . '"]' );
		if ( ! $nodes || 0 === $nodes->length ) {
			continue;
		}

		foreach ( $nodes as $node ) {
			if ( $node instanceof \DOMElement && $node->parentNode ) {
				$node->parentNode->removeChild( $node );
				debug( 'remove_target_nodes_from_html removed node id=' . $id );
			}
		}
	}

	$result = $doc->saveHTML();
	libxml_clear_errors();

	return is_string( $result ) ? $result : $html;
}

/**
 * selected_text から実際の selector を再解決
 *
 * @param string $html HTML
 * @param string $selected_text 選択テキスト
 * @return string
 */
function resolve_selector_by_selected_text( string $html, string $selected_text ): string {
	$selected_text_normalized = profile_normalize_embedded_match_text(
		profile_normalize_paragraph_text( $selected_text )
	);

	if ( '' === $selected_text_normalized ) {
		return '';
	}

	$selected_tokens = profile_embedded_match_tokens( $selected_text_normalized );

	libxml_use_internal_errors( true );

	$doc = new \DOMDocument();
	$loaded = $doc->loadHTML(
		'<?xml encoding="utf-8" ?>' . $html,
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);

	if ( ! $loaded ) {
		debug( 'resolve_selector_by_selected_text: DOM loadHTML failed' );
		return '';
	}

	$xpath = new \DOMXPath( $doc );
	$nodes = $xpath->query( profile_target_xpath() );

	if ( ! $nodes ) {
		debug( 'resolve_selector_by_selected_text: xpath query failed' );
		return '';
	}

	$best_selector = '';
	$best_score    = 0.0;

	foreach ( $nodes as $node ) {
		if ( ! $node instanceof \DOMElement ) {
			continue;
		}

		if ( profile_should_skip_text_target_node( $node ) ) {
			continue;
		}

		$text = profile_normalize_embedded_match_text(
			profile_normalize_paragraph_text( $node->textContent )
		);

		if ( '' === $text ) {
			continue;
		}

		$id = $node->getAttribute( 'id' );
		if ( '' === $id ) {
			continue;
		}

		$node_tokens = profile_embedded_match_tokens( $text );

		debug(
			'resolve_selector candidate: id=#' . $id .
			', score=' . profile_embedded_token_overlap_score( $selected_tokens, $node_tokens ) .
			', text_head=' . mb_substr( $text, 0, 120 )
		);

		if ( $text === $selected_text_normalized ) {
			debug( "resolve_selector_by_selected_text: exact match id=#{$id}" );
			return '#' . $id;
		}

		if (
			false !== mb_strpos( $text, $selected_text_normalized ) ||
			false !== mb_strpos( $selected_text_normalized, $text )
		) {
			$score = 0.95;
			if ( $score > $best_score ) {
				$best_score    = $score;
				$best_selector = '#' . $id;
			}
			continue;
		}

		$score = profile_embedded_token_overlap_score( $selected_tokens, $node_tokens );

		if ( $score > $best_score ) {
			$best_score    = $score;
			$best_selector = '#' . $id;
		}
	}

	if ( '' !== $best_selector && $best_score >= 0.35 ) {
		debug( "resolve_selector_by_selected_text: fuzzy match selector={$best_selector}, score={$best_score}" );
		return $best_selector;
	}

	debug( 'resolve_selector_by_selected_text: no node matched selected_text' );
	return '';
}

/**
 * 埋め込みコンテンツ用の UCA を作る
 *
 * @param array  $info 設定情報
 * @param string $issuer_id issuer
 * @param string $permalink permalink
 * @param string $locale locale
 * @param string $full_html page full html
 * @return ?Uca
 */
function create_embedded_uca(
	array $info,
	string $issuer_id,
	string $permalink,
	string $locale,
	string $full_html
): ?Uca {
	$kind          = $info['kind'] ?? '';
	$selected_text = $info['selected_text'] ?? '';
	$selector = '';

	if ( '' !== $selected_text ) {
		$selector = resolve_selector_by_selected_text( $full_html, $selected_text );
	} else {
		$selector = $info['selector'] ?? '';
	}

	$target_html = '';
	if ( '' !== $selector ) {
		$target_html = extract_target_html_by_selector( $full_html, $selector );
	}

	debug(
		'create_embedded_uca info=' .
		\wp_json_encode(
			array(
				'kind'          => $kind,
				'selected_text' => $selected_text,
				'headline'      => $info['headline'] ?? '',
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		)
	);

	if ( 'image' === $kind ) {
		$image_url = $info['image_url'] ?? '';

		if ( '' === $image_url ) {
			debug( 'create_embedded_uca skipped: image_url empty' );
			return null;
		}

		$resolved_selector = '#op-image-' . substr( sha1( $image_url ), 0, 8 );
		$target_html       = extract_target_html_by_selector( $full_html, $resolved_selector );

		debug(
			'create_embedded_uca image resolved by injected id: selector=' .
			$resolved_selector .
			', image_url=' . $image_url .
			', target_html_length=' . strlen( $target_html )
		);

		if ( '' === $target_html ) {
			debug( "create_embedded_uca skipped: injected image selector not found selector='{$resolved_selector}'" );
			return null;
		}

		$image_target_integrity = null;

		$raw_integrities = external_resources_from_html( $target_html, '//img[@integrity]' );
		if ( ! empty( $raw_integrities ) && is_string( $raw_integrities[0] ) ) {
			$image_target_integrity = trim( preg_replace( '/\s+/', ' ', $raw_integrities[0] ) );
		}

		// HTML 上に integrity が無い場合は attachment メタからフォールバック
		if ( null === $image_target_integrity || '' === $image_target_integrity ) {
			$image_target_integrity = find_attachment_all_integrities_by_image_url( $image_url );

			debug(
				'create_embedded_uca image fallback integrities from attachment=' .
				( $image_target_integrity ?: '(null)' ) .
				', image_url=' . $image_url
			);
		}

		debug(
			'create_embedded_uca image target_integrity(full)=' .
			( $image_target_integrity ?: '(null)' ) .
			', image_url=' . $image_url
		);

		if ( null === $image_target_integrity || '' === $image_target_integrity ) {
			debug( "create_embedded_uca skipped: target integrity not found for image_url='{$image_url}'" );
			return null;
		}

		debug( 'create_embedded_uca creating image UCA for selector=' . $resolved_selector );

		return new Uca(
			issuer: $issuer_id,
			url: $permalink,
			locale: $locale,
			html: $target_html,
			target_type: 'ExternalResourceTargetIntegrity',
			target_css_selector: $resolved_selector,
			target_integrity: $image_target_integrity,
			external_resources: array(),
			headline: $info['headline'] ?? '',
			description: $info['description'] ?? '',
			image: $image_url,
			author: $info['author'] ?? null,
			date_published: null,
			date_modified: null,
			subject_type: 'Image',
		);
	}	

	if ( '' === $selector || '' === $target_html ) {
		debug( "create_embedded_uca skipped: selector='{$selector}', target_html_length=" . strlen( $target_html ) );
		return null;
	}

	if ( 'article' === $kind ) {
		debug( "create_embedded_uca creating article UCA for selector={$selector}" );
		return new Uca(
			issuer: $issuer_id,
			url: $permalink,
			locale: $locale,
			html: $target_html,
			target_type: 'TextTargetIntegrity',
			target_css_selector: $selector,
			external_resources: array(),
			headline: $info['headline'] ?? '',
			description: $info['description'] ?? '',
			image: null,
			author: $info['author'] ?? null,
			date_published: null,
			date_modified: null,
		);
	}

	debug( "create_embedded_uca skipped: unsupported kind={$kind}" );
	return null;
}

/**
 * 未署名 Content Attestation の一覧の作成
 *
 * @param \WP_Post $post Post object.
 * @param string   $issuer_id CA 発行者 ID
 * @return list<Uca>
 */
function create_uca_list( \WP_Post $post, string $issuer_id ): array {
	global $wp_rewrite;

	debug( "create_uca_list start: post_id={$post->ID}" );

	$uca_list = array();

	$postdata = \generate_postdata( $post );
	debug( 'create_uca_list after generate_postdata' );

	if ( ! $postdata ) {
		debug( "Failed to generate postdata for post ID: {$post->ID}" );
		return $uca_list;
	}

	$pages = $postdata['pages'];
	debug( 'create_uca_list pages count: ' . count( $pages ) );

	foreach ( $pages as $page => $content ) {
		debug( 'create_uca_list foreach start: raw_page=' . $page );
		++$page;
		debug( 'create_uca_list page number=' . $page );

		$featured = '';

		if ( 1 === $page && \has_post_thumbnail( $post ) ) {
			$featured = \get_the_post_thumbnail(
				$post,
				'full',
				array(
					'class' => 'profile-featured-image',
				)
			);
		}

		$has_assigned_ad_shortcode =
			false !== strpos( $content, '[cam_assigned_ad_top]' ) ||
			false !== strpos( $content, '[cam_assigned_ad_middle]' ) ||
			false !== strpos( $content, '[cam_assigned_ad_bottom]' );

		$content = \apply_filters( 'the_content', $content );

		debug( 'create_uca_list after the_content filter, page=' . $page );

		/** 広告CA挿入 **/

		$ad_html_for_ca = '';
		if ( \function_exists( '\cam_get_top_ad_html' ) ) {
			$ad_html_for_ca = \cam_get_top_ad_html( $post->ID );
		}

		if ( '' !== $ad_html_for_ca ) {
			$content = str_replace( '[cam_ad_top]', $ad_html_for_ca, $content );
			debug( 'create_uca_list replaced [cam_ad_top] with ad html, page=' . $page );
		} else {
			$content = str_replace( '[cam_ad_top]', '', $content );
			debug( 'create_uca_list removed [cam_ad_top] because ad html empty, page=' . $page );
		}

		debug(
			'ASSIGNED_RENDER_EXISTS=' .
			( \function_exists( '\Profile\FrontAssignedAd\render_assigned_ad_by_position' ) ? 'yes' : 'no' ) .
			', post_id=' . $post->ID
		);

		$assigned_top_html = '';
		if ( \function_exists( '\Profile\FrontAssignedAd\render_assigned_ad_by_position' ) ) {
			$assigned_top_html = \Profile\FrontAssignedAd\render_assigned_ad_by_position( $post->ID, 'top' );
		}

		if ( '' !== $assigned_top_html ) {
			$content = str_replace( '[cam_assigned_ad_top]', $assigned_top_html, $content );
			debug( 'create_uca_list replaced [cam_assigned_ad_top] with assigned ad html, page=' . $page );
		} else {
			$content = str_replace( '[cam_assigned_ad_top]', '', $content );
			debug( 'create_uca_list removed [cam_assigned_ad_top] because assigned ad html empty, page=' . $page );
		}

		$assigned_middle_html = '';
		if ( \function_exists( '\Profile\FrontAssignedAd\render_assigned_ad_by_position' ) ) {
			$assigned_middle_html = \Profile\FrontAssignedAd\render_assigned_ad_by_position( $post->ID, 'middle' );
		}

		if ( '' !== $assigned_middle_html ) {
			$content = str_replace( '[cam_assigned_ad_middle]', $assigned_middle_html, $content );
			debug( 'create_uca_list replaced [cam_assigned_ad_middle] with assigned ad html, page=' . $page );
		} else {
			$content = str_replace( '[cam_assigned_ad_middle]', '', $content );
			debug( 'create_uca_list removed [cam_assigned_ad_middle] because assigned ad html empty, page=' . $page );
		}

		$assigned_bottom_html = '';
		if ( \function_exists( '\Profile\FrontAssignedAd\render_assigned_ad_by_position' ) ) {
			$assigned_bottom_html = \Profile\FrontAssignedAd\render_assigned_ad_by_position( $post->ID, 'bottom' );
		}

		if ( '' !== $assigned_bottom_html ) {
			$content = str_replace( '[cam_assigned_ad_bottom]', $assigned_bottom_html, $content );
			debug( 'create_uca_list replaced [cam_assigned_ad_bottom] with assigned ad html, page=' . $page );
		} else {
			$content = str_replace( '[cam_assigned_ad_bottom]', '', $content );
			debug( 'create_uca_list removed [cam_assigned_ad_bottom] because assigned ad html empty, page=' . $page );
		}

		/** 広告CA挿入ここまで **/		

		$content = add_ids_to_paragraphs_for_ca( $content );
		debug( 'create_uca_list after add_ids_to_paragraphs_for_ca, page=' . $page );

		$embedded_items_for_ids = \get_post_meta( $post->ID, '_cam_embedded_items', true );
		if ( \is_array( $embedded_items_for_ids ) && ! empty( $embedded_items_for_ids ) ) {
			$content = add_ids_to_embedded_images_for_ca( $content, $embedded_items_for_ids );
			debug( 'create_uca_list after add_ids_to_embedded_images_for_ca, page=' . $page );
		}

		$title_text = $post->post_title;
		$title_id   = profile_paragraph_id_from_text( $title_text );

		debug( 'MAIN_TITLE_TEXT=' . $title_text );
		debug( 'MAIN_TITLE_ID=' . $title_id );

		$title_html = '<h1 class="page-title typesquare_option">'
			. '<span id="' . esc_attr( $title_id ) . '" class=" typesquare_option">'
			. esc_html( $title_text )
			. '</span>'
			. '</h1>';

		$html = content_to_html(
			$title_html . $featured . $content,
			\get_option( 'profile_ca_target_html', PROFILE_DEFAULT_CA_TARGET_HTML )
		);
		debug( 'create_uca_list after content_to_html, page=' . $page );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$snapshot_dir  = ABSPATH . 'profile-test-snapshots/';
			$snapshot_file = $snapshot_dir . "{$post->ID}.{$page}.snapshot.html";

			if ( ! file_exists( $snapshot_dir ) ) {
				if ( ! mkdir( $snapshot_dir, 0755, true ) ) {
					error_log( 'Failed to create snapshot dir: ' . $snapshot_dir );
				}
			}

			$result = file_put_contents( $snapshot_file, $html );

			if ( false === $result ) {
				error_log( 'Failed to write snapshot file: ' . $snapshot_file );
			} else {
				error_log( 'Snapshot file written: ' . $snapshot_file . ' (' . $result . ' bytes)' );
			}
		}

		$permalink = \get_permalink( $post );
		if ( $page > 1 ) {
			if ( $wp_rewrite->using_permalinks() ) {
				$permalink .= $wp_rewrite->use_trailing_slashes ? '' : '/';
				$permalink .= \user_trailingslashit( $page );
			} else {
				$permalink = add_page_query( $permalink, $page );
			}
		}

		$locale = \str_replace( '_', '-', \get_locale() );
		debug( 'create_uca_list after locale, page=' . $page );

		$author_name = \get_post_meta( $post->ID, '_cam_author_name', true );
		if ( empty( $author_name ) ) {
			$author_name = \get_the_author_meta( 'display_name', $post->post_author );
		}
		debug( 'create_uca_list after author_name, value=' . $author_name );

		$embedded_items_for_main = \get_post_meta( $post->ID, '_cam_embedded_items', true );
		$selectors_to_remove_from_main = array();

		if ( \is_array( $embedded_items_for_main ) && ! empty( $embedded_items_for_main ) ) {
			foreach ( $embedded_items_for_main as $item ) {
				$kind          = isset( $item['kind'] ) ? (string) $item['kind'] : 'article';
				$selected_text = isset( $item['selected_text'] ) ? (string) $item['selected_text'] : '';
				$selector      = isset( $item['selector'] ) ? (string) $item['selector'] : '';
				$image_url     = isset( $item['image_url'] ) ? (string) $item['image_url'] : '';

				if ( 'image' === $kind && '' !== $image_url ) {
					$selector = '#op-image-' . substr( sha1( $image_url ), 0, 8 );
				} elseif ( '' !== $selected_text ) {
					$selector = resolve_selector_by_selected_text( $html, $selected_text );
				}

				if ( '' !== $selector && str_starts_with( $selector, '#' ) ) {
					$selectors_to_remove_from_main[] = $selector;
				}
			}
		}

		// ▼ 広告セレクタも main から除外
		$ad_items_for_main = \get_post_meta( $post->ID, '_cam_ad_items', true );

		if ( \is_array( $ad_items_for_main ) && ! empty( $ad_items_for_main[0]['selector'] ) ) {
   			 $ad_selector = (string) $ad_items_for_main[0]['selector'];

    		if ( '' !== $ad_selector && str_starts_with( $ad_selector, '#' ) ) {
       		 $selectors_to_remove_from_main[] = $ad_selector;
    		}
		}

		debug(
			'MAIN_CA_REMOVED_SELECTORS=' .
			\wp_json_encode(
				array_values( array_unique( $selectors_to_remove_from_main ) ),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		);

		$main_html = remove_target_nodes_from_html(
			$html,
			array_values( array_unique( $selectors_to_remove_from_main ) )
		);

		$raw_external_resources = external_resources_from_html( $main_html, '//img[@integrity]' );

		$external_resources = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $raw_integrity ) {
							if ( ! is_string( $raw_integrity ) ) {
								return '';
							}
							return trim( preg_replace( '/\s+/', ' ', $raw_integrity ) );
						},
						$raw_external_resources
					)
				)
			)
		);

		debug(
			'create_uca_list after external_resources_from_html, page=' .
			$page .
			', raw_count=' . count( $raw_external_resources ) .
			', normalized_count=' . count( $external_resources )
		);	

		debug(
			'MAIN_HTML_HEAD=' .
			mb_substr(
				profile_normalize_paragraph_text( wp_strip_all_tags( $main_html ) ),
				0,
				200
			)
		);

		debug( 'MAIN_TARGET_COUNT=' . ( is_array( $targets ) ? count( $targets ) : 0 ) );

		if ( is_array( $targets ) ) {
			foreach ( $targets as $i => $t ) {
				$type      = $t['type'] ?? '';
				$selector  = $t['cssSelector'] ?? '';
				$integrity = $t['integrity'] ?? '';

				debug(
					'MAIN_TARGET[' . $i . ']'
					. ' type=' . $type
					. ', selector=' . $selector
					. ', integrity=' . ( '' === $integrity ? '(empty)' : $integrity )
				);
			}
		}

		debug( 'create_uca_list before new Uca, page=' . $page );

		$embedded_items      = \get_post_meta( $post->ID, '_cam_embedded_items', true );
		$has_embedded_image  = false;

		if ( \is_array( $embedded_items ) && ! empty( $embedded_items ) ) {
			foreach ( $embedded_items as $item ) {
				$kind = isset( $item['kind'] ) ? (string) $item['kind'] : '';
				if ( 'image' === $kind ) {
					$has_embedded_image = true;
					break;
				}
			}
		}

		$main_external_resources = $external_resources;

		debug(
			'MAIN_EXTERNAL_RESOURCES mode=' .
			( $has_embedded_image ? 'embedded_image_present_use_empty' : 'no_embedded_image_use_main_resources' ) .
			', count=' . count( $main_external_resources )
		);

		$uca = new Uca(
			issuer: $issuer_id,
			url: $permalink,
			locale: $locale,
			html: $main_html,
			target_type: \get_option( 'profile_ca_target_type', PROFILE_DEFAULT_CA_TARGET_TYPE ),
			target_css_selector: \get_option( 'profile_ca_target_css_selector', PROFILE_DEFAULT_CA_TARGET_CSS_SELECTOR ),
			external_resources: $main_external_resources,
			headline: $post->post_title,
			description: \has_excerpt( $post ) ? \get_the_excerpt( $post ) : '',
			image: \has_post_thumbnail( $post ) ? \get_the_post_thumbnail_url( $post ) : null,
			author: $author_name,
			date_published: \get_the_date( \DateTimeInterface::RFC3339, $post ),
			date_modified: \get_the_modified_date( \DateTimeInterface::RFC3339, $post ),
			genre: \get_post_meta( $post->ID, '_cam_genre', true ),
		);

		debug( 'create_uca_list after new Uca, page=' . $page );

		\array_push( $uca_list, $uca );
		debug( 'create_uca_list after push main uca, page=' . $page );

		$embedded_items = \get_post_meta( $post->ID, '_cam_embedded_items', true );
		debug( 'RAW_EMBEDDED_ITEMS=' . \wp_json_encode( $embedded_items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

		$embedded_infos = array();

		if ( \is_array( $embedded_items ) && ! empty( $embedded_items ) ) {
			debug( 'USING_MULTI_EMBEDDED_ITEMS' );

			foreach ( $embedded_items as $item ) {
				debug( 'EMBEDDED_ITEM_LOOP=' . \wp_json_encode( $item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

				$kind          = isset( $item['kind'] ) ? $item['kind'] : 'article';
				$selector      = isset( $item['selector'] ) ? $item['selector'] : '';
				$selected_text = isset( $item['selected_text'] ) ? $item['selected_text'] : '';
				$image_url     = isset( $item['image_url'] ) ? $item['image_url'] : '';
				$image_alt     = isset( $item['image_alt'] ) ? $item['image_alt'] : '';
				$caption       = isset( $item['caption'] ) ? $item['caption'] : '';
				$rights_holder = isset( $item['rights_holder'] ) ? $item['rights_holder'] : '';
				$source_url    = isset( $item['source_url'] ) ? $item['source_url'] : '';
				$description   = isset( $item['description'] ) ? $item['description'] : '';

				if ( 'image' === $kind ) {
					if ( empty( $selector ) && empty( $image_url ) && empty( $rights_holder ) ) {
						debug( 'EMBEDDED_ITEM_SKIPPED_EMPTY_IMAGE' );
						continue;
					}

					$embedded_infos[] = array(
						'kind'          => 'image',
						'selector'      => $selector,
						'selected_text' => '',
						'image_url'     => $image_url,
						'image_alt'     => $image_alt,
						'caption'       => $caption,
						'headline'      => ! empty( $rights_holder ) ? $rights_holder . ' の引用画像' : '引用画像',
						'description'   => $description ?: $caption,
						'author'        => $rights_holder ?: '',
						'editor'        => $rights_holder ?: '',
						'rights_holder' => $rights_holder ?: '',
						'source_url'    => $source_url ?: '',
						'usage_type'    => 'quoted-image',
					);
				} else {
					if ( empty( $selector ) && empty( $rights_holder ) && empty( $source_url ) && empty( $description ) ) {
						debug( 'EMBEDDED_ITEM_SKIPPED_EMPTY' );
						continue;
					}

					$embedded_infos[] = array(
						'kind'          => 'article',
						'selector'      => $selector,
						'selected_text' => $selected_text,
						'headline'      => ! empty( $rights_holder ) ? $rights_holder . ' の引用記事' : '引用記事',
						'description'   => $description ?: '',
						'author'        => $rights_holder ?: '',
						'editor'        => $rights_holder ?: '',
						'rights_holder' => $rights_holder ?: '',
						'source_url'    => $source_url ?: '',
						'usage_type'    => 'quoted-text',
					);
				}
			}
		} else {
			debug( 'USING_LEGACY_EMBEDDED_FALLBACK' );

			$embedded_rights_holder = \get_post_meta( $post->ID, '_cam_rights_holder', true );
			$embedded_source_url    = \get_post_meta( $post->ID, '_cam_source_url', true );
			$embedded_license_note  = \get_post_meta( $post->ID, '_cam_license_note', true );
			$embedded_selector      = \get_post_meta( $post->ID, '_cam_embedded_selector', true );

			if ( ! empty( $embedded_rights_holder ) || ! empty( $embedded_source_url ) || ! empty( $embedded_license_note ) ) {
				$embedded_infos[] = array(
					'kind'          => 'article',
					'selector'      => ! empty( $embedded_selector ) ? $embedded_selector : '#op-body-6f16c1935e7b',
					'selected_text' => '',
					'headline'      => ! empty( $embedded_rights_holder ) ? $embedded_rights_holder . ' の引用記事' : '引用記事',
					'description'   => $embedded_license_note ?: '',
					'author'        => $embedded_rights_holder ?: '',
					'editor'        => $embedded_rights_holder ?: '',
					'rights_holder' => $embedded_rights_holder ?: '',
					'source_url'    => $embedded_source_url ?: '',
					'usage_type'    => 'quoted-text',
				);
			}
		}

		debug( 'EMBEDDED_INFOS=' . \wp_json_encode( $embedded_infos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

		foreach ( $embedded_infos as $info ) {
			$embedded_uca = create_embedded_uca(
				$info,
				$issuer_id,
				$permalink,
				$locale,
				$html
			);

			if ( $embedded_uca instanceof Uca ) {
				\array_push( $uca_list, $embedded_uca );
				debug( 'create_uca_list after push embedded uca, page=' . $page . ', selector=' . ( $info['selector'] ?? '' ) );
			} else {
				debug( 'create_uca_list embedded uca was null, page=' . $page . ', selector=' . ( $info['selector'] ?? '' ) );
			}
		}

		// ▼ 広告CA追加
		$ad_items = \get_post_meta( $post->ID, '_cam_ad_items', true );

		if ( \is_array( $ad_items ) && ! empty( $ad_items[0] ) ) {
    		$ad_item = $ad_items[0];

    		$ad_enabled     = ! empty( $ad_item['enabled'] );
    		$ad_status      = isset( $ad_item['status'] ) ? (string) $ad_item['status'] : 'unset';
    		$ad_id          = isset( $ad_item['id'] ) ? (string) $ad_item['id'] : '';
    		$ad_selector    = isset( $ad_item['selector'] ) ? (string) $ad_item['selector'] : '';
    		$ad_headline    = isset( $ad_item['headline'] ) ? (string) $ad_item['headline'] : '';
    		$ad_description = isset( $ad_item['description'] ) ? (string) $ad_item['description'] : '';
    		$ad_image       = isset( $ad_item['image'] ) ? (string) $ad_item['image'] : '';
    		$ad_author      = isset( $ad_item['advertiser'] ) ? (string) $ad_item['advertiser'] : '';
			$ad_destination = isset( $ad_item['destination'] ) ? (string) $ad_item['destination'] : '';

    		if ( $ad_enabled && 'active' === $ad_status && '' !== $ad_selector && '' !== $ad_id ) {
				$ad_target_html = '';

				if ( isset( $ad_html_for_ca ) && '' !== $ad_html_for_ca ) {
    				$ad_target_html = extract_target_html_by_selector( $ad_html_for_ca, $ad_selector );
				}

				$ad_target_integrity = null;

				if ( '' !== $ad_target_html ) {
					$raw_integrities = external_resources_from_html( $ad_target_html, '//img[@integrity]' );

					if ( ! empty( $raw_integrities ) && is_string( $raw_integrities[0] ) ) {
						// img タグの integrity 属性を丸ごと使う
						$ad_target_integrity = trim( preg_replace( '/\s+/', ' ', $raw_integrities[0] ) );
						debug( 'ad full integrity from html=' . $ad_target_integrity );
					}
				}

				// フォールバックは最後のみ
				if ( ( null === $ad_target_integrity || '' === $ad_target_integrity ) && '' !== $ad_image ) {
					$ad_target_integrity = find_attachment_all_integrities_by_image_url( $ad_image );
					debug( 'ad fallback all integrities from attachment=' . ( $ad_target_integrity ?: '(null)' ) );
				}

				if ( '' !== $ad_target_html && null !== $ad_target_integrity ) {
					$ad_uca = new Uca(
						issuer: $issuer_id,
						url: $permalink,
						locale: $locale,
						html: $ad_target_html,
						target_type: 'ExternalResourceTargetIntegrity',
						target_css_selector: $ad_selector,
						external_resources: array(),
						headline: $ad_headline ?: 'Advertisement',
						description: $ad_description,
						image: '' !== $ad_image ? $ad_image : null,
						author: '' !== $ad_author ? $ad_author : null,
						date_published: null,
						date_modified: null,
						subject_type: 'OnlineAd',
						target_integrity: $ad_target_integrity,
						landing_page_url: '' !== $ad_destination ? $ad_destination : null,
					);

					\array_push( $uca_list, $ad_uca );
					debug( 'create_uca_list after push ad uca, selector=' . $ad_selector . ', integrity=' . $ad_target_integrity );
				} else {
					debug( 'create_uca_list ad target html or integrity missing, selector=' . $ad_selector );
				}
			}
		}

		// ▼ コンテキスト広告CA追加
		$has_post_specific_ad = false;

		if ( \is_array( $ad_items ) && ! empty( $ad_items[0] ) ) {
			$existing_ad_item = $ad_items[0];

			$existing_enabled = ! empty( $existing_ad_item['enabled'] );
			$existing_status  = isset( $existing_ad_item['status'] ) ? (string) $existing_ad_item['status'] : 'unset';

			if ( $existing_enabled && 'active' === $existing_status ) {
				$has_post_specific_ad = true;
			}
		}

		// 投稿個別広告が無いときだけ、genre一致のコンテキスト広告を広告CA化
		if ( ! $has_post_specific_ad && ! $has_assigned_ad_shortcode ) {
			$placements = array( 'top', 'middle', 'bottom' );

			foreach ( $placements as $placement ) {
				$context_ad = \cam_get_context_ad_for_post_and_placement( $post->ID, $placement );

				if ( ! \is_array( $context_ad ) || empty( $context_ad ) ) {
					continue;
				}

				$context_enabled    = ! empty( $context_ad['enabled'] );
				$context_status     = isset( $context_ad['status'] ) ? (string) $context_ad['status'] : 'inactive';
				$context_headline   = isset( $context_ad['headline'] ) ? (string) $context_ad['headline'] : '';
				$context_advertiser = isset( $context_ad['advertiser'] ) ? (string) $context_ad['advertiser'] : '';
				$context_image      = isset( $context_ad['image'] ) ? (string) $context_ad['image'] : '';
				$context_destination = isset( $context_ad['destination'] ) ? (string) $context_ad['destination'] : '';
				$context_genre = isset( $context_ad['genre'] ) ? (string) $context_ad['genre'] : '';

				if ( ! $context_enabled || 'active' !== $context_status ) {
					continue;
				}

				$context_ad_id       = 'cam-context-ad-' . $placement . '-' . $post->ID;
				$context_ad_selector = '#' . $context_ad_id;
				$context_ad_html     = '';
				$context_target_html = '';

				// 表示側と同じHTMLを使う
				if ( \function_exists( '\cam_get_context_ad_html' ) ) {
					$context_ad_html = \cam_get_context_ad_html( $post->ID, $placement );
				}

				if ( '' !== $context_ad_html ) {
					$context_target_html = extract_target_html_by_selector( $context_ad_html, $context_ad_selector );
				}

				$context_target_integrity = null;

				// imgタグの integrity 属性があればそれを使う
				if ( '' !== $context_target_html ) {
					$raw_integrities = external_resources_from_html( $context_target_html, '//img[@integrity]' );

					if ( ! empty( $raw_integrities ) && \is_string( $raw_integrities[0] ) ) {
						$context_target_integrity = trim( preg_replace( '/\s+/', ' ', $raw_integrities[0] ) );
						debug( 'context ad[' . $placement . '] full integrity from html=' . $context_target_integrity );
					}
				}

				// 無ければ画像URLから attachment integrity を拾う
				if ( ( null === $context_target_integrity || '' === $context_target_integrity ) && '' !== $context_image ) {
					$context_target_integrity = find_attachment_all_integrities_by_image_url( $context_image );
					debug( 'context ad[' . $placement . '] fallback all integrities from attachment=' . ( $context_target_integrity ?: '(null)' ) );
				}

				if ( '' !== $context_target_html && null !== $context_target_integrity && '' !== $context_target_integrity ) {
					$context_ad_uca = new Uca(
						issuer: $issuer_id,
						url: $permalink,
						locale: $locale,
						html: $context_target_html,
						target_type: 'ExternalResourceTargetIntegrity',
						target_css_selector: $context_ad_selector,
						external_resources: array(),
						headline: $context_headline ?: ( $context_advertiser ?: 'Advertisement' ),
						description: 'Context Ad / ' . $placement . ' / genre=' . $context_genre,
						image: '' !== $context_image ? $context_image : null,
						author: '' !== $context_advertiser ? $context_advertiser : null,
						date_published: null,
						date_modified: null,
						subject_type: 'OnlineAd',
						target_integrity: $context_target_integrity,
						landing_page_url: '' !== $context_destination ? $context_destination : null,
					);

					\array_push( $uca_list, $context_ad_uca );
					debug( 'create_uca_list after push context ad uca, placement=' . $placement . ', selector=' . $context_ad_selector . ', integrity=' . $context_target_integrity );
				} else {
					debug( 'create_uca_list context ad target html or integrity missing, placement=' . $placement . ', selector=' . $context_ad_selector );
				}
			}
		}
		// ▲ コンテキスト広告CA追加

	}

	debug( 'create_uca_list end, total=' . count( $uca_list ) );
	return $uca_list;
}

/**
 * WordPress post contentからHTMLへの変換
 *
 * @param string $content WordPress post content
 * @param string $template テンプレート (%CONTENT% を置換)
 * @return string HTML
 */
function content_to_html( string $content, string $template ): string {
	return \str_replace( '%CONTENT%', $content, $template );
}

/**
 * HTMLから外部リソースのIntegrityを取得
 *
 * @param string $html HTML
 * @param string $xpath_query XPathクエリ
 * @return array<string>
 */
function external_resources_from_html( string $html, string $xpath_query ): array {
	$document = new \DOMDocument();
	$loaded   = $document->loadHTML( $html );

	if ( ! $loaded ) {
		debug( 'external_resources_from_html: loadHTML failed' );
		return array();
	}

	$xpath     = new \DOMXpath( $document );
	$elements  = $xpath->query( $xpath_query );
	$resources = array();

	if ( $elements ) {
		foreach ( $elements as $element ) {
			if ( isset( $element->attributes['integrity'] ) && $element->attributes['integrity']->value ) {
				array_push( $resources, $element->attributes['integrity']->value );
			}
		}
	} else {
		debug( "No external resources found matching Xpath query: {$xpath_query}" );
	}

	return $resources;
}

/**
 * Content Attestation の発行
 *
 * @param Uca    $uca 未署名 Content Attestation オブジェクト
 * @param string $endpoint Content Attestation サーバー CA 登録・更新エンドポイント
 * @param string $admin_secret Content Attestation サーバー認証情報
 * @return mixed
 */
function issue_ca( Uca $uca, string $endpoint, string $admin_secret ): mixed {
	$args = array(
		'method'  => 'POST',
		'timeout' => PROFILE_DEFAULT_CA_SERVER_REQUEST_TIMEOUT,
		'headers' => array(
			'content-type' => 'application/json',
		),
		'body'    => $uca->to_json(),
	);

	$secret_arr = explode( ':', $admin_secret );
	$username   = $secret_arr[0] ?? '';

	switch ( $username ) {
		case 'OIDC':
			$api_auth = new CasApiAuthClient();
			if ( ! $api_auth->init_oidc( $admin_secret ) ) {
				debug( 'Failed to initialize OIDC client.' );
				return false;
			}
			$args['headers']['authorization'] = 'Bearer ' . $api_auth->get_api_token();
			break;

		case 'CCSP':
			$api_auth = new CasApiAuthCCSP();
			if ( ! $api_auth->init_ccsp( $admin_secret ) ) {
				debug( 'Failed to initialize CCSP client.' );
				return false;
			}
			$args['headers']['authorization'] = 'Bearer ' . $api_auth->get_api_token();
			break;

		default:
			$args['headers']['authorization'] = 'Basic ' . \sodium_bin2base64( $admin_secret, SODIUM_BASE64_VARIANT_ORIGINAL );
			break;
	}

	$res = \wp_remote_request( $endpoint, $args );

	if ( \is_wp_error( $res ) ) {
		$error_message = $res->get_error_message();
		debug( 'Failed to request: ' . $error_message );
		return false;
	}

	if ( 200 !== $res['response']['code'] ) {
		debug(
			'HTTP error: ' . $res['response']['code'] .
			', body=' . wp_remote_retrieve_body( $res )
		);
		return false;
	}

	return \json_decode( $res['body'], true );
}