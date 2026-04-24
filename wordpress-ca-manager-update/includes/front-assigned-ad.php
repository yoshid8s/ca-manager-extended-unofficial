<?php
/**
 * 割当済広告の front 表示
 */

namespace Profile\FrontAssignedAd;

/**
 * 初期化
 */
function init() {
	\add_filter( 'the_content', __NAMESPACE__ . '\\inject_assigned_ad_into_content', 20 );

	\add_shortcode( 'cam_assigned_ad_top', __NAMESPACE__ . '\\assigned_ad_top_shortcode' );
	\add_shortcode( 'cam_assigned_ad_middle', __NAMESPACE__ . '\\assigned_ad_middle_shortcode' );
	\add_shortcode( 'cam_assigned_ad_bottom', __NAMESPACE__ . '\\assigned_ad_bottom_shortcode' );
}

/**
 * 投稿に割り当てられた広告申込IDを取得
 *
 * @param int $post_id 投稿ID
 * @return int
 */
function get_assigned_application_id( $post_id ) {
	return (int) \get_post_meta( $post_id, '_cam_assigned_ad_application_id', true );
}

/**
 * 割当済広告の基本情報とアイテムを取得
 *
 * @param int $post_id 投稿ID
 * @return array|null
 */
function get_assigned_ad_data( $post_id ) {
	global $wpdb;

	error_log( 'ASSIGNED start post_id=' . $post_id );

	$application_id = get_assigned_application_id( $post_id );
	error_log( 'ASSIGNED application_id=' . print_r( $application_id, true ) );

	if ( ! $application_id ) {
		error_log( 'ASSIGNED return null: no application_id' );
		return null;
	}

	$applications_table = $wpdb->prefix . 'cam_ad_applications';
	$items_table        = $wpdb->prefix . 'cam_ad_application_items';

	$application = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$applications_table} WHERE id = %d LIMIT 1",
			$application_id
		),
		ARRAY_A
	);
	error_log( 'ASSIGNED application=' . print_r( $application, true ) );

	if ( empty( $application ) || ! is_array( $application ) ) {
		error_log( 'ASSIGNED return null: application empty' );
		return null;
	}

	if ( 'ready' !== $application['status'] && 'approved' !== $application['status'] ) {
		error_log( 'ASSIGNED return null: status=' . $application['status'] );
		return null;
	}

	$items = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$items_table} WHERE application_id = %d ORDER BY id ASC",
			$application_id
		),
		ARRAY_A
	);
	error_log( 'ASSIGNED items=' . print_r( $items, true ) );

	if ( ! is_array( $items ) || empty( $items ) ) {
		error_log( 'ASSIGNED return null: items empty' );
		return null;
	}

	$grouped = array(
		'top'    => null,
		'middle' => null,
		'bottom' => null,
	);

	foreach ( $items as $item ) {
		if ( empty( $item['slot_position'] ) ) {
			continue;
		}

		error_log( 'ASSIGNED slot_position=' . $item['slot_position'] );

		if ( isset( $grouped[ $item['slot_position'] ] ) ) {
			$grouped[ $item['slot_position'] ] = $item;
		}
	}

	error_log( 'ASSIGNED grouped=' . print_r( $grouped, true ) );

	return array(
		'application' => $application,
		'items'       => $grouped,
	);
}

/**
 * 広告HTMLを1件分生成
 *
 * @param array  $item 広告アイテム
 * @param string $position 位置
 * @param int    $post_id 投稿ID
 * @return string
 */
function render_ad_item( $item, $position, $post_id ) {
	if ( empty( $item ) || ! is_array( $item ) ) {
		return '';
	}

	$headline    = isset( $item['headline'] ) ? $item['headline'] : '';
	$image_url   = isset( $item['image_url'] ) ? $item['image_url'] : '';
	$landing_url = isset( $item['landing_url'] ) ? $item['landing_url'] : '';

	if ( '' === $headline && '' === $image_url && '' === $landing_url ) {
		return '';
	}

	$wrapper_id = 'cam-assigned-ad-' . \sanitize_html_class( $position ) . '-' . (int) $post_id;

	ob_start();
	?>
	<div
		id="<?php echo esc_attr( $wrapper_id ); ?>"
		class="cam-assigned-ad cam-assigned-ad-<?php echo esc_attr( $position ); ?>"
		data-cam-ad-position="<?php echo esc_attr( $position ); ?>"
		style="margin: 24px 0; padding: 12px; border: 1px solid #ddd; background: #fafafa;"
	>
		<?php if ( ! empty( $headline ) ) : ?>
			<div class="cam-assigned-ad-headline" style="margin-bottom: 8px; font-weight: 600;">
				<?php echo esc_html( $headline ); ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $landing_url ) ) : ?>
			<a href="<?php echo esc_url( $landing_url ); ?>" target="_blank" rel="noopener sponsored">
		<?php endif; ?>

		<?php if ( ! empty( $image_url ) ) : ?>
			<img
				src="<?php echo esc_url( $image_url ); ?>"
				alt="<?php echo esc_attr( $headline ); ?>"
				class="cam-assigned-ad-image"
				style="max-width: 100%; height: auto; display: block;"
			>
		<?php endif; ?>

		<?php if ( ! empty( $landing_url ) ) : ?>
			</a>
		<?php endif; ?>
	</div>
	<?php

	return (string) ob_get_clean();
}

/**
 * 投稿本文へ広告を差し込む
 *
 * @param string $content 本文
 * @return string
 */
function inject_assigned_ad_into_content( $content ) {
	if ( ! \is_singular( 'post' ) || ! \in_the_loop() || ! \is_main_query() ) {
		return $content;
	}

	$post_id = \get_the_ID();
	if ( ! $post_id ) {
		return $content;
	}

    error_log( 'cam assigned post_id=' . $post_id );
    error_log( 'cam assigned application_id=' . get_assigned_application_id( $post_id ) );

	$ad_data = get_assigned_ad_data( $post_id );
	if ( empty( $ad_data ) ) {
		return $content;
	}

	$top_ad    = render_ad_item( $ad_data['items']['top'], 'top', $post_id );
	$middle_ad = render_ad_item( $ad_data['items']['middle'], 'middle', $post_id );
	$bottom_ad = render_ad_item( $ad_data['items']['bottom'], 'bottom', $post_id );

	$paragraphs = explode( '</p>', $content );

	if ( count( $paragraphs ) > 2 && '' !== $middle_ad ) {
		$paragraphs[1] .= '</p>' . $middle_ad;
		$content = implode( '</p>', $paragraphs );
	}

	return $top_ad . $content . $bottom_ad;
}

/**
 * 指定位置の割当広告HTMLを返す
 *
 * @param int    $post_id 投稿ID
 * @param string $position top|middle|bottom
 * @return string
 */
function render_assigned_ad_by_position( $post_id, $position ) {
	$post_id  = (int) $post_id;
	$position = (string) $position;

	if ( ! $post_id || '' === $position ) {
		return '';
	}

	$assigned = get_assigned_ad_data( $post_id );

	if (
		! is_array( $assigned ) ||
		empty( $assigned['items'][ $position ] ) ||
		! is_array( $assigned['items'][ $position ] )
	) {
		return '';
	}

	return render_ad_item( $assigned['items'][ $position ], $position, $post_id );
}

/**
 * 割当広告 shortcode: top
 *
 * @return string
 */
function assigned_ad_top_shortcode() {
	if ( \is_admin() ) {
		return '';
	}

	$post_id = \get_the_ID();
	if ( ! $post_id ) {
		return '';
	}

	return render_assigned_ad_by_position( $post_id, 'top' );
}

/**
 * 割当広告 shortcode: middle
 *
 * @return string
 */
function assigned_ad_middle_shortcode() {
	if ( \is_admin() ) {
		return '';
	}

	$post_id = \get_the_ID();
	if ( ! $post_id ) {
		return '';
	}

	return render_assigned_ad_by_position( $post_id, 'middle' );
}

/**
 * 割当広告 shortcode: bottom
 *
 * @return string
 */
function assigned_ad_bottom_shortcode() {
	if ( \is_admin() ) {
		return '';
	}

	$post_id = \get_the_ID();
	if ( ! $post_id ) {
		return '';
	}

	return render_assigned_ad_by_position( $post_id, 'bottom' );
}
