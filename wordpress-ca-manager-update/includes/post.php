<?php
/** 投稿閲覧画面 */

namespace Profile\Post;

if ( ! function_exists( 'WP_Filesystem' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
WP_Filesystem();

require_once __DIR__ . '/debug.php';
use function Profile\Debug\debug;

require_once __DIR__ . '/config.php';
use const Profile\Config\PROFILE_DEFAULT_CA_EXTERNAL_DIR;

/** 投稿閲覧画面の初期化　OGP追加　竹内変更箇所 */
function init() {
	\add_action( 'wp_head', '\Profile\Post\cas_script' );
	\add_action( 'wp_head', '\Profile\Post\opg_meta_tags', 5 );

	\add_action( 'init', '\Profile\Post\register_op_share_route' );
	\add_filter( 'query_vars', '\Profile\Post\add_op_share_query_vars' );
	\add_action( 'template_redirect', '\Profile\Post\render_op_share_page' );

	\add_action( 'rest_api_init', '\Profile\Post\register_op_share_rest_api' );

	\add_filter( 'render_block_core/image', '\Profile\Post\block_image', 10, 2 );
	\add_filter( 'the_content', '\Profile\Post\inject_integrity_into_content_images', 20 );
	\add_filter( 'the_content', '\Profile\Post\add_ids_to_content_paragraphs', 30 );
	\add_action( 'template_redirect', '\Profile\Post\start_title_buffer' );
}
/** 対象にheader領域を追加 */

function start_title_buffer() {
	if ( ! \is_singular( array( 'post', 'page' ) ) ) {
		return;
	}

	ob_start( '\Profile\Post\inject_id_into_main_title' );
}

function inject_id_into_main_title( string $html ): string {
	if ( ! \is_singular( array( 'post', 'page' ) ) ) {
		return $html;
	}

	$post = \get_queried_object();
	if ( ! $post instanceof \WP_Post ) {
		return $html;
	}

	$title_text = $post->post_title;
	$title_id   = profile_paragraph_id_from_text( $title_text );

	$pattern = '#<h1\b([^>]*)class=(["\'])([^"\']*\b(?:page-title|entry-title)\b[^"\']*)\2([^>]*)>.*?</h1>#is';

	$replacement = '<h1$1class=$2$3$2$4>'
		. '<span id="' . \esc_attr( $title_id ) . '" class=" typesquare_option">'
		. \esc_html( $title_text )
		. '</span>'
		. '</h1>';

	return preg_replace( $pattern, $replacement, $html, 1 ) ?? $html;
}

/** 竹内追加コード：ID方式を連番からハッシュ値に変える基礎関数　*/

function profile_normalize_paragraph_text( string $text ): string {
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( '/[\x{00A0}\s]+/u', ' ', $text );
	return trim( $text );
}

function profile_paragraph_id_from_text( string $text ): string {
	$normalized = profile_normalize_paragraph_text( $text );
	return 'op-body-' . substr( sha1( $normalized ), 0, 12 );
}

function profile_target_tag_names(): array {
	return array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote', 'figcaption', 'pre' );
}

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

function profile_should_skip_text_target_node( \DOMElement $el ): bool {
	$tag = strtolower( $el->tagName );

	if ( ! in_array( $tag, profile_target_tag_names(), true ) ) {
		return true;
	}

	// Instagram埋め込み用の補助段落は除外
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

/** 竹内追加コード：ID方式を連番からハッシュ値に変える基礎関数　*/

function add_ids_to_content_paragraphs( string $content ): string {
	if ( ! \is_singular( array( 'post', 'page' ) ) ) {
		return $content;
	}

	$target_tags = profile_target_tag_names();
	$has_target = false;

	foreach ( $target_tags as $tag ) {
		if ( false !== stripos( $content, '<' . $tag ) ) {
			$has_target = true;
			break;
		}
	}

	if ( ! $has_target ) {
		return $content;
	}

	libxml_use_internal_errors( true );

	$doc = new \DOMDocument();
	$loaded = $doc->loadHTML(
		'<?xml encoding="utf-8" ?>' . $content,
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);

	if ( ! $loaded ) {
		return $content;
	}

	$xpath = new \DOMXPath( $doc );
	$nodes = $xpath->query( profile_target_xpath() );

	if ( ! $nodes ) {
		return $content;
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
 * Script要素
 */
function cas_script() {
	if ( ! \is_singular( array( 'post', 'page' ) ) ) {
		debug( 'Not a target singular post/page, skipping CAS script injection' );
		return;
	}

	$post_id = \get_the_ID();
	$cas     = \get_post_meta( $post_id, '_profile_post_cas', true );

	if ( ! $cas ) {
		debug( "No CAS found for post ID: {$post_id}" );
		return;
	}

	$embedded_or_external = \get_option( 'profile_ca_embedded_or_external', 'embedded' );

	switch ( $embedded_or_external ) {
		case 'embedded':
			error_log( 'POST_META_CAS=' . wp_json_encode( $cas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			echo '<script type="application/cas+json">' . \wp_json_encode( $cas ) . '</script>' . PHP_EOL;
			break;

		case 'external':
			global $wp_filesystem;
			$dir_name = PROFILE_DEFAULT_CA_EXTERNAL_DIR;
			$dir      = ABSPATH . "{$dir_name}/";

			if ( ! $wp_filesystem->exists( $dir ) ) {
				debug( "Directory does not exist, attempting to create: {$dir}" );
				if ( ! $wp_filesystem->mkdir( $dir ) ) {
					debug( "Failed to create directory: {$dir}" );
					return;
				}
			}

			$filename     = "{$post_id}_cas.json";
			$existing_cas = $wp_filesystem->get_contents( "{$dir}{$filename}" );

			if ( \wp_json_encode( $cas ) !== $existing_cas ) {
				$write_result = $wp_filesystem->put_contents(
					"{$dir}{$filename}",
					\wp_json_encode( $cas ),
					FS_CHMOD_FILE
				);
				if ( ! $write_result ) {
					debug( "Failed to write JSON file: {$filename}" );
					return;
				}
			}

			$url      = \home_url();
			$endpoint = "{$url}/{$dir_name}/{$filename}";

			echo '<script src="' . \esc_url( $endpoint ) . '" type="application/cas+json"></script>' . PHP_EOL;
			break;
	}
}

/**
 * OPG対応Shareページ自動作成

 */
function register_op_share_route() {
	\add_rewrite_rule(
		'^op-share/([a-zA-Z0-9_-]+)/?$',
		'index.php?op_share_hash=$matches[1]',
		'top'
	);
}

function add_op_share_query_vars( $vars ) {
	$vars[] = 'op_share_hash';
	return $vars;
}

/**
 * Render OP Share page.
 */
function render_op_share_page() {
	$hash = \get_query_var( 'op_share_hash' );

	if ( ! $hash ) {
		return;
	}

	$hash = \sanitize_text_field( $hash );

	$data = \get_option( 'opg_share_' . $hash );

	if ( ! is_array( $data ) ) {
		$data = array();
	}

	$text = isset( $data['text'] ) ? (string) $data['text'] : '';

	if ( '' === $text ) {
		$text = 'この文章ブロックには OP/CAS による発信主体情報が付与されています。';
	}

	$title = isset( $data['title'] ) && '' !== $data['title']
		? (string) $data['title']
		: '共有された本文ブロック';

	$author = isset( $data['author'] ) && '' !== $data['author']
		? (string) $data['author']
		: 'Yoshifumi Takeuchi';

	$source = isset( $data['source'] ) ? (string) $data['source'] : '';

	$cas_url = isset( $data['cas_url'] ) ? (string) $data['cas_url'] : '';

	if ( '' === $cas_url && ! empty( $data['post_id'] ) ) {
		$cas_url = \home_url( '/cas/' . \absint( $data['post_id'] ) . '_cas.json' );
	}

	$page_url = \home_url( '/op-share/' . $hash );

	$description = function_exists( 'mb_substr' )
		? \mb_substr( $text, 0, 120, 'UTF-8' )
		: \substr( $text, 0, 120 );

	\status_header( 200 );

	echo '<!DOCTYPE html>';
	echo '<html lang="ja" prefix="og: https://ogp.me/ns#">';
	echo '<head>';
	echo '<meta charset="UTF-8">';
	echo '<title>' . \esc_html( $title ) . '</title>';

	echo '<meta property="og:title" content="共有された本文ブロック">';
	echo '<meta property="og:type" content="article">';
	echo '<meta property="og:url" content="' . \esc_url( $page_url ) . '">';
	echo '<meta property="og:description" content="' . \esc_attr( $description ) . '">';

	$og_image = \plugin_dir_url( dirname( __FILE__ ) ) . 'assets/op-share-card-v2.png';

	echo '<meta property="og:image" content="' . \esc_url( $og_image ) . '">';
	echo '<meta name="twitter:card" content="summary">';
	echo '<meta name="twitter:title" content="共有された本文ブロック">';
	echo '<meta name="twitter:description" content="' . \esc_attr( $description ) . '">';
	echo '<meta name="twitter:image" content="' . \esc_url( $og_image ) . '">';

	echo '<meta property="og:op:type" content="TextBlockAttestation">';
	echo '<meta property="og:op:block_hash" content="' . \esc_attr( $hash ) . '">';

	if ( '' !== $cas_url ) {
		echo '<meta property="og:op:cas" content="' . \esc_url( $cas_url ) . '">';
	}

	echo '<meta property="og:op:block_text" content="' . \esc_attr( $text ) . '">';
	echo '</head>';

	echo '<body>';
	echo '<h1>OPを使って、Xで共有されたWeb記事のブロック（段落）</h1>';

	echo '<p>' . \nl2br( \esc_html( $text ) ) . '</p>';
	echo '<hr>';

	echo '<p>発信者 ' . \esc_html( $author ) . '</p>';

	if ( '' !== $source ) {
		echo '<p><a href="' . \esc_url( $source ) . '">記事タイトル　' . \esc_html( $title ) . '</a></p>';
	} else {
		echo '<p>記事タイトル　' . \esc_html( $title ) . '</p>';
	}

	echo '<p>OPは、Webページの発信者情報と記事の改ざん検証に用いるために開発されたWeb技術で、Originator Profile技術研究組合が開発しています。</p>';
	echo '<p>この共有ブロックには、発信者情報や改ざん検証に用いるための OP/CAS 情報が付与されています。</p>';
	echo '<p>このページは、元記事の一部テキストを共有するための OP Block Share ページです。</p>';
	echo '<p>Block hash: ' . \esc_html( $hash ) . '</p>';

	echo '</body>';
	echo '</html>';

	exit;
}


/**
 * OPG対応

 */
function opg_meta_tags() {
	if ( ! \is_singular( array( 'post', 'page' ) ) ) {
		return;
	}

	$post_id = \get_the_ID();
	$cas     = \get_post_meta( $post_id, '_profile_post_cas', true );

	if ( ! $cas ) {
		return;
	}

	$embedded_or_external = \get_option( 'profile_ca_embedded_or_external', 'embedded' );

	if ( 'external' !== $embedded_or_external ) {
		return;
	}

	$dir_name = PROFILE_DEFAULT_CA_EXTERNAL_DIR;
	$url      = \home_url();
	$endpoint = "{$url}/{$dir_name}/{$post_id}_cas.json";
	$share_base = \home_url( '/op-share/' );

	echo "\n<!-- OPG / OP metadata -->\n";
	echo '<meta property="og:op:type" content="ArticleAttestation">' . "\n";
	echo '<meta property="og:op:cas" content="' . \esc_url( $endpoint ) . '">' . "\n";
	echo '<meta property="og:op:share_base" content="' . \esc_url( $share_base ) . '">' . "\n";
	echo "<!-- /OPG / OP metadata -->\n";
}

/**
 * Register OP Share REST API.
 */
function register_op_share_rest_api() {
	\register_rest_route(
		'opg/v1',
		'/share',
		array(
			'methods'             => 'POST',
			'callback'            => '\Profile\Post\save_op_share_data',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Save OP Share data by hash.
 *
 * Endpoint:
 * POST /wp-json/opg/v1/share
 */
function save_op_share_data( \WP_REST_Request $request ) {
	$hash   = sanitize_text_field( $request->get_param( 'hash' ) );
	$text   = sanitize_textarea_field( $request->get_param( 'text' ) );
	$post_id = absint( $request->get_param( 'post_id' ) );

	$cas_url = esc_url_raw( $request->get_param( 'cas_url' ) );
	$title   = sanitize_text_field( $request->get_param( 'title' ) );
	$source  = esc_url_raw( $request->get_param( 'source' ) );
	$author  = sanitize_text_field( $request->get_param( 'author' ) );

	if ( '' === $hash || '' === $text ) {
		return new \WP_Error(
			'opg_share_invalid',
			'Invalid OP share data.',
			array( 'status' => 400 )
		);
	}

	$data = array(
		'hash'       => $hash,
		'text'       => $text,
		'post_id'    => $post_id,
		'cas_url'    => $cas_url,
		'title'      => $title,
		'source'     => $source,
		'author'     => $author,
		'created_at' => current_time( 'mysql' ),
	);

	$option_key = 'opg_share_' . $hash;

	update_option( $option_key, $data, false );

	return new \WP_REST_Response(
		array(
			'ok'        => true,
			'hash'      => $hash,
			'share_url' => home_url( '/op-share/' . $hash ),
		),
		200
	);
}

/**
 * 画像要素

 * @param string $content ブロックコンテンツ
 * @param array  $block   ブロック
 * @return string ブロックコンテンツ
 */
function block_image( string $content, array $block ): string {
	$id = $block['attrs']['id'] ?? null;

	if ( ! $id ) {
		debug( 'Image block has no ID attribute, skipping integrity injection' );
		return $content;
	}

	$integrity = \get_post_meta( $id, '_profile_attachment_integrity', true );
	$integrity = \is_array( $integrity ) ? \implode( ' ', $integrity ) : null;

	if ( ! $integrity ) {
		debug( "No Integrity metadata found for attachment ID: {$id}" );
		return $content;
	}

	return \str_replace( '<img ', '<img integrity="' . \esc_attr( $integrity ) . '" ', $content );
}
function inject_integrity_into_content_images( string $content ): string {
	if ( false === stripos( $content, '<img' ) ) {
		return $content;
	}

	libxml_use_internal_errors( true );

	$doc = new \DOMDocument();
	$loaded = $doc->loadHTML(
		'<?xml encoding="utf-8" ?>' . $content,
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);

	if ( ! $loaded ) {
		return $content;
	}

	$images = $doc->getElementsByTagName( 'img' );

	foreach ( $images as $img ) {
		if ( $img->hasAttribute( 'integrity' ) ) {
			continue;
		}

		$attachment_id = null;

		$class = $img->getAttribute( 'class' );
		if ( preg_match( '/wp-image-([0-9]+)/', $class, $m ) ) {
			$attachment_id = (int) $m[1];
		}

		if ( ! $attachment_id ) {
			$src = $img->getAttribute( 'src' );
			$attachment_id = \attachment_url_to_postid( $src );
		}

		if ( ! $attachment_id ) {
			continue;
		}

		$integrity = \get_post_meta( $attachment_id, '_profile_attachment_integrity', true );
		if ( \is_array( $integrity ) && ! empty( $integrity ) ) {
			$img->setAttribute( 'integrity', implode( ' ', $integrity ) );
		}
	}

	return $doc->saveHTML();
}