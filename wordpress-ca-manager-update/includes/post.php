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

/** 投稿閲覧画面の初期化　竹内変更箇所 */
function init() {
	\add_action( 'wp_head', '\Profile\Post\cas_script' );
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