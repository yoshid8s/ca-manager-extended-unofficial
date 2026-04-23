<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 広告管理メニュー追加
 */
function cam_ad_register_admin_menus() {
	\add_options_page(
		'CA広告枠設定',
		'CA広告枠設定',
		'manage_options',
		'cam-ad-slots',
		'cam_ad_render_slots_page'
	);

	\add_options_page(
		'CA広告申込一覧',
		'CA広告申込一覧',
		'manage_options',
		'cam-ad-applications',
		'cam_ad_render_applications_page'
	);

	\add_options_page(
		'CA承認済広告',
		'CA承認済広告',
		'manage_options',
		'cam-ad-approved',
		'cam_ad_render_approved_page'
	);

	\add_options_page(
		'CA広告統計',
		'CA広告統計',
		'manage_options',
		'cam-ad-stats',
		'cam_ad_render_stats_page'
	);
}
\add_action( 'admin_menu', 'cam_ad_register_admin_menus', 20 );

/**
 * 広告枠設定画面
 */
function cam_ad_render_slots_page() {
	?>
	<div class="wrap">
		<h1>CA広告枠設定</h1>
		<p>ここに広告枠設定画面を作成します。</p>
	</div>
	<?php
}

/**
 * 広告申込一覧画面
 */
function cam_ad_render_applications_page() {
	?>
	<div class="wrap">
		<h1>CA広告申込一覧</h1>
		<p>ここに広告申込一覧画面を作成します。</p>
	</div>
	<?php
}

/**
 * 承認済広告画面
 */
function cam_ad_render_approved_page() {
	?>
	<div class="wrap">
		<h1>CA承認済広告</h1>
		<p>ここに承認済広告画面を作成します。</p>
	</div>
	<?php
}

/**
 * 広告統計画面
 */
function cam_ad_render_stats_page() {
	?>
	<div class="wrap">
		<h1>CA広告統計</h1>
		<p>ここに広告統計画面を作成します。</p>
	</div>
	<?php
}
