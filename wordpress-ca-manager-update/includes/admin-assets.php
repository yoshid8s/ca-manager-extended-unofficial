<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cam_enqueue_admin_assets( $hook ) {
	global $post_type;

	$allowed_post_types = array( 'post', 'page' );

	$is_post_screen = in_array( $hook, array( 'post.php', 'post-new.php' ), true )
		&& in_array( $post_type, $allowed_post_types, true );

	if ( ! $is_post_screen ) {
		return;
	}

	wp_enqueue_style(
		'cam-admin-ui',
		plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/admin-ui.css',
		array(),
		'1.0.0'
	);

	wp_enqueue_script(
		'cam-admin-ui',
		plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/admin-ui.js',
		array( 'jquery' ),
		'1.0.0',
		true
	);
}
add_action( 'admin_enqueue_scripts', 'cam_enqueue_admin_assets' );