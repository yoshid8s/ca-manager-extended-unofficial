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

/**
 * 投稿genreに一致するコンテキスト広告から、placement別の広告データを返す
 *
 * @param int    $post_id   Post ID.
 * @param string $placement top / middle / bottom
 * @return array|null
 */
function cam_get_context_ad_for_post_and_placement( $post_id, $placement ) {
	$ad = cam_get_context_ad_for_post( $post_id );

	if ( ! is_array( $ad ) || empty( $ad ) ) {
		return null;
	}

	$placement = (string) $placement;

	if ( ! in_array( $placement, array( 'top', 'middle', 'bottom' ), true ) ) {
		return null;
	}

	$headline_key    = $placement . '_headline';
	$image_key       = $placement . '_image';
	$destination_key = $placement . '_destination';

	$headline    = isset( $ad[ $headline_key ] ) ? (string) $ad[ $headline_key ] : '';
	$image       = isset( $ad[ $image_key ] ) ? (string) $ad[ $image_key ] : '';
	$destination = isset( $ad[ $destination_key ] ) ? (string) $ad[ $destination_key ] : '';

	// その placement に何も設定がなければ null
	if ( '' === $headline && '' === $image && '' === $destination ) {
		return null;
	}

	return array(
		'enabled'     => ! empty( $ad['enabled'] ),
		'status'      => isset( $ad['status'] ) ? (string) $ad['status'] : 'inactive',
		'genre'       => isset( $ad['genre'] ) ? (string) $ad['genre'] : '',
		'advertiser'  => isset( $ad['advertiser'] ) ? (string) $ad['advertiser'] : '',
		'headline'    => $headline,
		'image'       => $image,
		'destination' => $destination,
		'placement'   => $placement,
	);
}
