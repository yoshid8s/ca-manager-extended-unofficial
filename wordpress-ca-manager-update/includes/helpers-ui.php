<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cam_get_status_badge( $status ) {
	$map = array(
		'success' => array(
			'class' => 'ca-badge-success',
			'label' => '発行済み',
		),
		'warning' => array(
			'class' => 'ca-badge-warning',
			'label' => '要確認',
		),
		'error' => array(
			'class' => 'ca-badge-error',
			'label' => 'エラー',
		),
		'default' => array(
			'class' => 'ca-badge-default',
			'label' => '未設定',
		),
	);

	$item = isset( $map[ $status ] ) ? $map[ $status ] : $map['default'];

	return '<span class="ca-badge ' . esc_attr( $item['class'] ) . '">' . esc_html( $item['label'] ) . '</span>';
}

function cam_get_post_meta_value( $post_id, $key, $default = '' ) {
	$value = get_post_meta( $post_id, $key, true );
	return ( '' !== $value && null !== $value ) ? $value : $default;
}

function cam_escape_pretty_json( $data ) {
	if ( empty( $data ) || ! is_array( $data ) ) {
		return esc_html( "{\n  \"message\": \"まだデータがありません\"\n}" );
	}

	return esc_html(
		wp_json_encode(
			$data,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
		)
	);
}