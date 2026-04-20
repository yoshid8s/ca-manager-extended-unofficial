<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 記事タイトル直下などに使う広告HTMLを生成
 *
 * @param int $post_id Post ID
 * @return string
 */
function cam_get_top_ad_html( $post_id ) {
	$ad = null;

	// まず投稿個別の広告設定を優先
	$ad_items = get_post_meta( $post_id, '_cam_ad_items', true );
	if ( is_array( $ad_items ) && ! empty( $ad_items[0] ) ) {
		$ad = $ad_items[0];
	}

	// 個別広告が無ければ genre一致のコンテキスト広告を使う
	if ( ! is_array( $ad ) || empty( $ad ) ) {
		$ad = cam_get_context_ad_for_post( $post_id );
	}

	if ( ! is_array( $ad ) || empty( $ad ) ) {
		return '';
	}

	$enabled     = ! empty( $ad['enabled'] );
	$status      = isset( $ad['status'] ) ? (string) $ad['status'] : 'unset';
	$id          = isset( $ad['id'] ) ? (string) $ad['id'] : '';
	$headline    = isset( $ad['headline'] ) ? (string) $ad['headline'] : '';
	$advertiser  = isset( $ad['advertiser'] ) ? (string) $ad['advertiser'] : '';
	$destination = isset( $ad['destination'] ) ? (string) $ad['destination'] : '';
	$image       = isset( $ad['image'] ) ? (string) $ad['image'] : '';
	$ad_code     = isset( $ad['ad_code'] ) ? (string) $ad['ad_code'] : '';

	// まずは enabled と active の両方を条件にする
	if ( ! $enabled || 'active' !== $status ) {
		return '';
	}

	// id が無い場合はコンテキスト広告用に自動付与
	if ( '' === $id ) {
		$id = 'cam-context-ad-' . $post_id;
	}

	$label = $headline;
	if ( '' === $label ) {
		$label = $advertiser ? $advertiser : 'Advertisement';
	}

	$html  = '<div class="cam-top-ad-slot" data-cam-placement="top_under_title">';
	$html .= '<div class="cam-top-ad-inner">';

	// まずは画像広告優先
	if ( '' !== $image ) {
		$image_html = '<img id="' . esc_attr( $id ) . '" src="' . esc_url( $image ) . '" alt="' . esc_attr( $label ) . '" class="cam-top-ad-image">';

		if ( '' !== $destination ) {
			$html .= '<a href="' . esc_url( $destination ) . '" class="cam-top-ad-link">';
			$html .= $image_html;
			$html .= '</a>';
		} else {
			$html .= $image_html;
		}
	} elseif ( '' !== $ad_code ) {
		$html .= $ad_code;
	} else {
		$html .= '<div class="cam-top-ad-placeholder">' . esc_html( $label ) . '</div>';
	}

	$html .= '</div>';
	$html .= '</div>';

	return $html;
}

/**
 * [cam_ad_top] ショートコード
 *
 * 投稿本文に [cam_ad_top] を書くと、その位置に広告を表示する
 *
 * @return string
 */
function cam_ad_top_shortcode() {
	if ( is_admin() ) {
		return '';
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return '';
	}

	return cam_get_top_ad_html( $post_id );
}
add_shortcode( 'cam_ad_top', 'cam_ad_top_shortcode' );

/**
 * 最低限のスタイル
 */
function cam_top_ad_inline_style() {
	if ( is_admin() || ! is_singular( array( 'post', 'page' ) ) ) {
		return;
	}
	?>
	<style>
		.cam-top-ad-slot {
			margin: 0 0 28px;
			text-align: left;
		}
		.cam-top-ad-inner {
			display: block;
			width: 100%;
		}
		.cam-top-ad-link,
		.cam-top-ad-link:hover {
			display: block;
			text-decoration: none;
			border: 0;
		}
		.cam-top-ad-image {
			display: block;
			max-width: 100%;
			height: auto;
			margin: 0;
		}
		.cam-top-ad-placeholder {
			padding: 24px 16px;
			border: 1px solid #ddd;
			background: #fafafa;
			text-align: left;
		}
		.cam-context-ad-slot {
			margin: 32px 0;
		}

		.cam-context-ad-inner {
			display: block;
			width: 100%;
		}

		.cam-context-ad-link,
		.cam-context-ad-link:hover {
			display: block;
			text-decoration: none;
			border: 0;
		}

		.cam-context-ad-image {
			display: block;
			max-width: 100%;
			height: auto;
		}

		.cam-context-ad-text {
			padding: 16px;
			border: 1px solid #ddd;
			background: #fafafa;
		}
	</style>
	<?php
}
add_action( 'wp_head', 'cam_top_ad_inline_style' );

/**
 * コンテキスト広告（placement別）HTML生成
 *
 * @param int    $post_id
 * @param string $placement top / middle / bottom
 * @return string
 */
function cam_get_context_ad_html( $post_id, $placement = 'top' ) {

	$ad = cam_get_context_ad_for_post_and_placement( $post_id, $placement );

	if ( ! is_array( $ad ) || empty( $ad ) ) {
		return '';
	}

	// 有効チェック
	if ( empty( $ad['enabled'] ) || 'active' !== $ad['status'] ) {
		return '';
	}

	$id = 'cam-context-ad-' . $placement . '-' . $post_id;

	$headline    = $ad['headline'];
	$image       = $ad['image'];
	$destination = $ad['destination'];
	$advertiser  = $ad['advertiser'];

	$label = $headline;
	if ( '' === $label ) {
		$label = $advertiser ? $advertiser : 'Advertisement';
	}

	$html  = '<div class="cam-context-ad-slot cam-context-ad-' . esc_attr( $placement ) . '" data-cam-placement="' . esc_attr( $placement ) . '">';
	$html .= '<div class="cam-context-ad-inner">';

	// 画像優先
	if ( '' !== $image ) {
		$image_html = '<img id="' . esc_attr( $id ) . '" src="' . esc_url( $image ) . '" alt="' . esc_attr( $label ) . '" class="cam-context-ad-image">';

		if ( '' !== $destination ) {
			$html .= '<a href="' . esc_url( $destination ) . '" class="cam-context-ad-link">';
			$html .= $image_html;
			$html .= '</a>';
		} else {
			$html .= $image_html;
		}
	} else {
		// テキスト fallback
		$html .= '<div id="' . esc_attr( $id ) . '" class="cam-context-ad-text">';
		$html .= esc_html( $label );
		$html .= '</div>';
	}

	$html .= '</div>';
	$html .= '</div>';

	return $html;
}

function cam_context_ad_top_shortcode() {
	if ( is_admin() ) return '';
	return cam_get_context_ad_html( get_the_ID(), 'top' );
}
add_shortcode( 'cam_context_ad_top', 'cam_context_ad_top_shortcode' );

function cam_context_ad_middle_shortcode() {
	if ( is_admin() ) return '';
	return cam_get_context_ad_html( get_the_ID(), 'middle' );
}
add_shortcode( 'cam_context_ad_middle', 'cam_context_ad_middle_shortcode' );

function cam_context_ad_bottom_shortcode() {
	if ( is_admin() ) return '';
	return cam_get_context_ad_html( get_the_ID(), 'bottom' );
}
add_shortcode( 'cam_context_ad_bottom', 'cam_context_ad_bottom_shortcode' );
