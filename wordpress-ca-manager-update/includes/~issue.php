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

/** 投稿への署名処理の初期化 */
function init() {
	\add_action( 'transition_post_status', '\Profile\Issue\sign_post', 10, 3 );
	\add_action( 'save_post', '\Profile\Issue\sign_post_on_save', 20, 3 );
	\add_filter( 'wp_generate_attachment_metadata', '\Profile\Issue\update_attachment_integrity_metadata', 10, 2 );
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

	// 引用符・括弧類を削除
	$text = str_replace(
		array('“','”','„','‟','«','»','"','＂','‘','’','‚','‛',"'","ʼ","`","´","「","」","『","』"),
		'',
		$text
	);

	// ダッシュ類統一
	$text = str_replace(
		array("–","—","―","‐","‒","−"),
		'-',
		$text
	);

	// 全角スペース→半角
	$text = str_replace("\xE3\x80\x80", ' ', $text);

	// 連続空白を1つに
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

	foreach ( $nodes as $node ) {
		if ( ! $node instanceof \DOMElement ) {
			continue;
		}

		if ( profile_should_skip_text_target_node( $node ) ) {
			continue;
		}

		if ( $node->hasAttribute( 'id' ) ) {
			continue;
		}

		$text = profile_normalize_paragraph_text( $node->textContent );
		$node->setAttribute( 'id', profile_paragraph_id_from_text( $text ) );
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

		// 完全一致優先
		if ( $src === $image_url || $src_basename === $target_basename ) {
			// figure が親なら figure 全体を返す
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

		// 1. 完全一致
		if ( $text === $selected_text_normalized ) {
			debug( "resolve_selector_by_selected_text: exact match id=#{$id}" );
			return '#' . $id;
		}

		// 2. 部分一致
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

		// 3. トークン重なり率
		$score = profile_embedded_token_overlap_score( $selected_tokens, $node_tokens );

		if ( $score > $best_score ) {
			$best_score    = $score;
			$best_selector = '#' . $id;
		}
	}

	// 閾値は少し緩め
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
	$selector      = '';

	// selected_text がある場合は、保存済み selector を信用せず毎回 HTML から再解決
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
				'selector'      => $selector,
				'selected_text' => $selected_text,
				'headline'      => $info['headline'] ?? '',
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		)
	);

	if ( 'image' === $kind ) {
		debug( 'IMAGE_BRANCH_ENTERED' );
		$image_url = $info['image_url'] ?? '';

		// selector で取れなかった場合は image_url から再解決
		if ( '' === $target_html && '' !== $image_url ) {
			$target_html = extract_image_html_by_url( $full_html, $image_url );

			debug(
				'create_embedded_uca image resolved by image_url: selector=' .
				$selector .
				', image_url=' . $image_url .
				', target_html_length=' . strlen( $target_html )
			);
		}

		if ( '' === $target_html ) {
			debug( "create_embedded_uca skipped: image not found selector='{$selector}'" );
			return null;
		}

		debug( 'create_embedded_uca creating image UCA for image_url=' . $image_url );

		$final_selector = $selector;

		// block selector は実HTMLに無いので使わない
		if ( '' === $final_selector || str_starts_with( $final_selector, '#block-' ) ) {
			$final_selector = '#op-image-' . substr( sha1( $image_url ), 0, 8 );
		}

		return new Uca(
			issuer: $issuer_id,
			url: $permalink,
			locale: $locale,
			html: $target_html,
			target_type: 'ExternalResourceTargetIntegrity',
			target_css_selector: $final_selector,
			external_resources: array(
				array(
					'url' => $image_url,
				),
			),
			headline: $info['headline'] ?? '',
			description: $info['description'] ?? '',
			image: $image_url,
			author: $info['author'] ?? null,
			date_published: null,
			date_modified: null,
		);
	}

	if ( 'article' === $kind ) {
		if ( '' === $selector || '' === $target_html ) {
			debug( "create_embedded_uca skipped: selector='{$selector}', target_html_length=" . strlen( $target_html ) );
			return null;
		}

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

		$content = \apply_filters( 'the_content', $content );
		debug( 'create_uca_list after the_content filter, page=' . $page );

		$content = add_ids_to_paragraphs_for_ca( $content );
		debug( 'create_uca_list after add_ids_to_paragraphs_for_ca, page=' . $page );

		$title_text = $post->post_title;
		$title_id   = profile_paragraph_id_from_text( $title_text );

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

		$external_resources = external_resources_from_html( $html, '//img[@integrity]' );
		debug( 'create_uca_list after external_resources_from_html, page=' . $page . ', count=' . count( $external_resources ) );

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

		// 埋め込み対象 selector を本文CAから除外する
		$embedded_items_for_main = \get_post_meta( $post->ID, '_cam_embedded_items', true );
		$selectors_to_remove_from_main = array();

		if ( \is_array( $embedded_items_for_main ) && ! empty( $embedded_items_for_main ) ) {
			foreach ( $embedded_items_for_main as $item ) {
					$selected_text = isset( $item['selected_text'] ) ? (string) $item['selected_text'] : '';
					$selector      = isset( $item['selector'] ) ? (string) $item['selector'] : '';

					if ( '' !== $selected_text ) {
						$selector = resolve_selector_by_selected_text( $html, $selected_text );
					}

					if ( '' !== $selector && str_starts_with( $selector, '#' ) ) {
						$selectors_to_remove_from_main[] = $selector;
					}
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

		debug(
			'MAIN_HTML_HEAD=' .
			mb_substr(
				profile_normalize_paragraph_text( wp_strip_all_tags( $main_html ) ),
				0,
				200
			)
		);

		debug( 'create_uca_list before new Uca, page=' . $page );

		$uca = new Uca(
			issuer: $issuer_id,
			url: $permalink,
			locale: $locale,
			html: $main_html,
			target_type: 'TextTargetIntegrity',
			target_css_selector: '#op-body-*',
			external_resources: $external_resources,
			headline: $post->post_title,
			description: \has_excerpt( $post ) ? \get_the_excerpt( $post ) : '',
			image: \has_post_thumbnail( $post ) ? \get_the_post_thumbnail_url( $post ) : null,
			author: $author_name,
			date_published: \get_the_date( \DateTimeInterface::RFC3339, $post ),
			date_modified: \get_the_modified_date( \DateTimeInterface::RFC3339, $post ),
		);

		debug( 'create_uca_list after new Uca, page=' . $page );

		\array_push( $uca_list, $uca );
		debug( 'create_uca_list after push main uca, page=' . $page );

		// 埋め込みコンテンツ（複数件対応）
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

			// 旧単一メタ fallback
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
		debug( 'HTTP error: ' . $res['response']['code'] );
		return false;
	}

	return \json_decode( $res['body'], true );
}