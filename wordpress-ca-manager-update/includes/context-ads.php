<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 登録済みコンテキスト広告一覧を取得
 *
 * @return array
 */
function cam_get_context_ads() {
	$ads = get_option( 'cam_context_ads', array() );

	return is_array( $ads ) ? $ads : array();
}

/**
 * 投稿genreに一致するコンテキスト広告を1件返す
 *
 * @param int $post_id Post ID.
 * @return array|null
 */
function cam_get_context_ad_for_post( $post_id ) {
	$genre = get_post_meta( $post_id, '_cam_genre', true );

	if ( '' === $genre ) {
		return null;
	}

	$ads = cam_get_context_ads();

	foreach ( $ads as $ad ) {
		$enabled  = ! empty( $ad['enabled'] );
		$status   = isset( $ad['status'] ) ? (string) $ad['status'] : 'inactive';
		$ad_genre = isset( $ad['genre'] ) ? (string) $ad['genre'] : '';

		if ( $enabled && 'active' === $status && $ad_genre === $genre ) {
			return $ad;
		}
	}

	return null;
}