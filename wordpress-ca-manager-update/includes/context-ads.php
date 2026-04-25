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

	$post = get_post( $post_id );

	$post_text = '';
	if ( $post instanceof \WP_Post ) {
		$post_text = strtolower(
			wp_strip_all_tags(
				(string) $post->post_title . ' ' . (string) $post->post_content
			)
		);
	}

	$ads        = cam_get_context_ads();
	$candidates = array();
	$today      = current_time( 'Y-m-d' );

	foreach ( $ads as $ad ) {
		$enabled    = ! empty( $ad['enabled'] );
		$status     = isset( $ad['status'] ) ? (string) $ad['status'] : 'inactive';
		$ad_genre   = isset( $ad['genre'] ) ? (string) $ad['genre'] : '';
		$start_date = isset( $ad['start_date'] ) ? (string) $ad['start_date'] : '';
		$end_date   = isset( $ad['end_date'] ) ? (string) $ad['end_date'] : '';

		if ( ! $enabled || 'active' !== $status || $ad_genre !== $genre ) {
			continue;
		}

		if ( '' !== $start_date && $today < $start_date ) {
			continue;
		}

		if ( '' !== $end_date && $today > $end_date ) {
			continue;
		}

        $score      = 0;
        $advertiser = isset( $ad['advertiser'] ) ? strtolower( (string) $ad['advertiser'] ) : '';
        $bid_price  = isset( $ad['bid_price'] ) ? (float) $ad['bid_price'] : 0;

        if ( '' !== $advertiser && '' !== $post_text && false !== strpos( $post_text, $advertiser ) ) {
        	$score += 1000;
        }

        $score += $bid_price;

        $ad['_match_score'] = $score;
        $ad['_bid_price']   = $bid_price;
        $candidates[]       = $ad;
	}

	if ( empty( $candidates ) ) {
		return null;
	}

	usort(
		$candidates,
		function ( $a, $b ) {
			$score_a = isset( $a['_match_score'] ) ? (int) $a['_match_score'] : 0;
			$score_b = isset( $b['_match_score'] ) ? (int) $b['_match_score'] : 0;

			return $score_b <=> $score_a;
		}
	);


    if ( function_exists( '\Profile\Debug\debug' ) ) {
	    \Profile\Debug\debug(
		    'AD_SELECT_CANDIDATES post_id=' . $post_id . ' ' .
		    wp_json_encode(
			    array_map(
			    	function ( $ad ) {
				    	return array(
				    		'id'         => isset( $ad['id'] ) ? $ad['id'] : '',
				    		'advertiser' => isset( $ad['advertiser'] ) ? $ad['advertiser'] : '',
				    		'genre'      => isset( $ad['genre'] ) ? $ad['genre'] : '',
				    		'score'      => isset( $ad['_match_score'] ) ? $ad['_match_score'] : 0,
                            'bid_price'  => isset( $ad['_bid_price'] ) ? $ad['_bid_price'] : 0,
				    		'enabled'    => isset( $ad['enabled'] ) ? $ad['enabled'] : '',
				    		'status'     => isset( $ad['status'] ) ? $ad['status'] : '',
				    	);
				    },
				    $candidates
			    ),
			    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		    )
	    );
    }

	return $candidates[0];
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
    	'id'          => isset( $ad['id'] ) ? (string) $ad['id'] : '',
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
/**
 * コンテキスト広告の表示回数を記録
 *
 * @param array $args {
 *   @type string $ad_id
 *   @type string $placement top / middle / bottom
 *   @type int    $post_id
 *   @type string $genre
 * }
 * @return void
 */
function cam_log_context_ad_impression( array $args ) {
	$ad_id     = isset( $args['ad_id'] ) ? (string) $args['ad_id'] : '';
	$placement = isset( $args['placement'] ) ? (string) $args['placement'] : '';
	$post_id   = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
	$genre     = isset( $args['genre'] ) ? (string) $args['genre'] : '';

	if ( '' === $ad_id ) {
		return;
	}

	if ( ! in_array( $placement, array( 'top', 'middle', 'bottom' ), true ) ) {
		$placement = 'top';
	}

	$stats = get_option( 'cam_ad_impression_stats', array() );

	if ( ! is_array( $stats ) ) {
		$stats = array();
	}

	if ( ! isset( $stats[ $ad_id ] ) || ! is_array( $stats[ $ad_id ] ) ) {
        $stats[ $ad_id ] = array(
	        'total'           => 0,
	        'top'             => 0,
	        'middle'          => 0,
	        'bottom'          => 0,
	        'bottom_reach'    => 0,
	        'last_post_id'    => 0,
	        'last_genre'      => '',
	        'last_seen'       => '',
	        'last_reach_seen' => '',
            'click_total'     => 0,
            'click_top'       => 0,
            'click_middle'    => 0,
            'click_bottom'    => 0,
            'last_click_seen' => '',
            'time_10'         => 0,
            'time_30'         => 0,
            'time_60'         => 0,
            'last_time_seen'  => '',
        );
	}

	$stats[ $ad_id ]['total'] = isset( $stats[ $ad_id ]['total'] ) ? (int) $stats[ $ad_id ]['total'] + 1 : 1;
	$stats[ $ad_id ][ $placement ] = isset( $stats[ $ad_id ][ $placement ] ) ? (int) $stats[ $ad_id ][ $placement ] + 1 : 1;
	$stats[ $ad_id ]['last_post_id'] = $post_id;
	$stats[ $ad_id ]['last_genre']   = $genre;
	$stats[ $ad_id ]['last_seen']    = current_time( 'mysql' );

	update_option( 'cam_ad_impression_stats', $stats, false );

    if ( function_exists( '\Profile\Debug\debug' ) ) {
	    \Profile\Debug\debug(
	        'AD_IMPRESSION: ad_id=' . $ad_id .
		    ', placement=' . $placement .
		    ', post_id=' . $post_id .
		    ', genre=' . $genre
	    );
    }
}

/**
 * コンテキスト広告の bottom 到達回数を記録
 *
 * @param array $args {
 *   @type string $ad_id
 *   @type int    $post_id
 *   @type string $genre
 * }
 * @return void
 */
function cam_log_context_ad_bottom_reach( array $args ) {
	$ad_id   = isset( $args['ad_id'] ) ? (string) $args['ad_id'] : '';
	$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
	$genre   = isset( $args['genre'] ) ? (string) $args['genre'] : '';

	if ( '' === $ad_id ) {
		return;
	}

	$stats = get_option( 'cam_ad_impression_stats', array() );

	if ( ! is_array( $stats ) ) {
		$stats = array();
	}

	if ( ! isset( $stats[ $ad_id ] ) || ! is_array( $stats[ $ad_id ] ) ) {
		$stats[ $ad_id ] = array(
			'total'           => 0,
			'top'             => 0,
			'middle'          => 0,
			'bottom'          => 0,
			'bottom_reach'    => 0,
			'last_post_id'    => 0,
			'last_genre'      => '',
			'last_seen'       => '',
			'last_reach_seen' => '',
            'click_total'     => 0,
            'click_top'       => 0,
            'click_middle'    => 0,
            'click_bottom'    => 0,
            'last_click_seen' => '',
            'time_10'        => 0,
            'time_30'        => 0,
            'time_60'        => 0,
            'last_time_seen' => '',
		);
	}

	if ( ! isset( $stats[ $ad_id ]['bottom_reach'] ) ) {
		$stats[ $ad_id ]['bottom_reach'] = 0;
	}

	$stats[ $ad_id ]['bottom_reach'] = (int) $stats[ $ad_id ]['bottom_reach'] + 1;
	$stats[ $ad_id ]['last_post_id'] = $post_id;
	$stats[ $ad_id ]['last_genre']   = $genre;
	$stats[ $ad_id ]['last_reach_seen'] = current_time( 'mysql' );

	update_option( 'cam_ad_impression_stats', $stats, false );

	if ( function_exists( '\Profile\Debug\debug' ) ) {
		\Profile\Debug\debug(
			'AD_BOTTOM_REACH: ad_id=' . $ad_id .
			', post_id=' . $post_id .
			', genre=' . $genre
		);
	}
}

/**
 * AJAX: bottom 到達ログ記録
 *
 * @return void
 */
function cam_ajax_log_context_ad_bottom_reach() {
	$ad_id   = isset( $_POST['ad_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ad_id'] ) ) : '';
	$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
	$genre   = isset( $_POST['genre'] ) ? sanitize_text_field( wp_unslash( $_POST['genre'] ) ) : '';

	if ( '' === $ad_id ) {
		wp_send_json_error( array( 'message' => 'missing ad_id' ), 400 );
	}

	cam_log_context_ad_bottom_reach(
		array(
			'ad_id'   => $ad_id,
			'post_id' => $post_id,
			'genre'   => $genre,
		)
	);

	wp_send_json_success( array( 'logged' => true ) );
}
add_action( 'wp_ajax_cam_log_context_ad_bottom_reach', 'cam_ajax_log_context_ad_bottom_reach' );
add_action( 'wp_ajax_nopriv_cam_log_context_ad_bottom_reach', 'cam_ajax_log_context_ad_bottom_reach' );

/**
 * コンテキスト広告のクリック回数を記録
 *
 * @param array $args {
 *   @type string $ad_id
 *   @type string $placement top / middle / bottom
 *   @type int    $post_id
 *   @type string $genre
 * }
 * @return void
 */
function cam_log_context_ad_click( array $args ) {
	$ad_id     = isset( $args['ad_id'] ) ? (string) $args['ad_id'] : '';
	$placement = isset( $args['placement'] ) ? (string) $args['placement'] : '';
	$post_id   = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
	$genre     = isset( $args['genre'] ) ? (string) $args['genre'] : '';

	if ( '' === $ad_id ) {
		return;
	}

	if ( ! in_array( $placement, array( 'top', 'middle', 'bottom' ), true ) ) {
		$placement = 'top';
	}

	$stats = get_option( 'cam_ad_impression_stats', array() );

	if ( ! is_array( $stats ) ) {
		$stats = array();
	}

	if ( ! isset( $stats[ $ad_id ] ) || ! is_array( $stats[ $ad_id ] ) ) {
		$stats[ $ad_id ] = array(
			'total'           => 0,
			'top'             => 0,
			'middle'          => 0,
			'bottom'          => 0,
			'bottom_reach'    => 0,
			'click_total'     => 0,
			'click_top'       => 0,
			'click_middle'    => 0,
			'click_bottom'    => 0,
			'last_post_id'    => 0,
			'last_genre'      => '',
			'last_seen'       => '',
			'last_reach_seen' => '',
			'last_click_seen' => '',
            'time_10'        => 0,
            'time_30'        => 0,
            'time_60'        => 0,
            'last_time_seen' => '',
		);
	}

	if ( ! isset( $stats[ $ad_id ]['click_total'] ) ) {
		$stats[ $ad_id ]['click_total'] = 0;
	}
	if ( ! isset( $stats[ $ad_id ]['click_top'] ) ) {
		$stats[ $ad_id ]['click_top'] = 0;
	}
	if ( ! isset( $stats[ $ad_id ]['click_middle'] ) ) {
		$stats[ $ad_id ]['click_middle'] = 0;
	}
	if ( ! isset( $stats[ $ad_id ]['click_bottom'] ) ) {
		$stats[ $ad_id ]['click_bottom'] = 0;
	}

	$stats[ $ad_id ]['click_total'] = (int) $stats[ $ad_id ]['click_total'] + 1;
	$stats[ $ad_id ][ 'click_' . $placement ] = (int) $stats[ $ad_id ][ 'click_' . $placement ] + 1;
	$stats[ $ad_id ]['last_post_id'] = $post_id;
	$stats[ $ad_id ]['last_genre']   = $genre;
	$stats[ $ad_id ]['last_click_seen'] = current_time( 'mysql' );

	update_option( 'cam_ad_impression_stats', $stats, false );

	if ( function_exists( '\Profile\Debug\debug' ) ) {
		\Profile\Debug\debug(
			'AD_CLICK: ad_id=' . $ad_id .
			', placement=' . $placement .
			', post_id=' . $post_id .
			', genre=' . $genre
		);
	}
}

/**
 * コンテキスト広告クリック記録用URLを返す
 *
 * @param string $ad_id
 * @param string $placement
 * @param int    $post_id
 * @param string $genre
 * @return string
 */
function cam_get_context_ad_click_url( $ad_id, $placement, $post_id, $genre ) {
	$args = array(
		'cam_context_click' => 1,
		'ad_id'             => (string) $ad_id,
		'placement'         => (string) $placement,
		'post_id'           => (int) $post_id,
		'genre'             => (string) $genre,
	);

	return add_query_arg( $args, home_url( '/' ) );
}

/**
 * コンテキスト広告クリックを記録してリダイレクト
 *
 * @return void
 */
function cam_handle_context_ad_click_redirect() {
	if ( empty( $_GET['cam_context_click'] ) ) {
		return;
	}

	$ad_id     = isset( $_GET['ad_id'] ) ? sanitize_text_field( wp_unslash( $_GET['ad_id'] ) ) : '';
	$placement = isset( $_GET['placement'] ) ? sanitize_text_field( wp_unslash( $_GET['placement'] ) ) : '';
	$post_id   = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
	$genre     = isset( $_GET['genre'] ) ? sanitize_text_field( wp_unslash( $_GET['genre'] ) ) : '';

	if ( '' === $ad_id || ! in_array( $placement, array( 'top', 'middle', 'bottom' ), true ) ) {
		return;
	}

	$ads = get_option( 'cam_context_ads', array() );
	if ( ! is_array( $ads ) ) {
		return;
	}

	$destination = '';

	foreach ( $ads as $ad ) {
		$current_id = isset( $ad['id'] ) ? (string) $ad['id'] : '';

		if ( $current_id !== $ad_id ) {
			continue;
		}

		if ( 'top' === $placement ) {
			$destination = isset( $ad['top_destination'] ) ? (string) $ad['top_destination'] : '';
		} elseif ( 'middle' === $placement ) {
			$destination = isset( $ad['middle_destination'] ) ? (string) $ad['middle_destination'] : '';
		} elseif ( 'bottom' === $placement ) {
			$destination = isset( $ad['bottom_destination'] ) ? (string) $ad['bottom_destination'] : '';
		}

		break;
	}

	$destination = esc_url_raw( $destination );

	if ( '' === $destination ) {
		wp_die( 'Invalid destination' );
	}

	cam_log_context_ad_click(
		array(
			'ad_id'     => $ad_id,
			'placement' => $placement,
			'post_id'   => $post_id,
			'genre'     => $genre,
		)
	);

	wp_redirect( $destination );
	exit;
}
add_action( 'template_redirect', 'cam_handle_context_ad_click_redirect', 1 );

/**
 * コンテキスト広告の滞在秒数到達回数を記録
 *
 * @param array $args {
 *   @type string $ad_id
 *   @type int    $post_id
 *   @type string $genre
 *   @type int    $seconds 10 / 30 / 60
 * }
 * @return void
 */
function cam_log_context_ad_time_reach( array $args ) {
	$ad_id   = isset( $args['ad_id'] ) ? (string) $args['ad_id'] : '';
	$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
	$genre   = isset( $args['genre'] ) ? (string) $args['genre'] : '';
	$seconds = isset( $args['seconds'] ) ? (int) $args['seconds'] : 0;

	if ( '' === $ad_id ) {
		return;
	}

	if ( ! in_array( $seconds, array( 10, 30, 60 ), true ) ) {
		return;
	}

	$stats = get_option( 'cam_ad_impression_stats', array() );

	if ( ! is_array( $stats ) ) {
		$stats = array();
	}

	if ( ! isset( $stats[ $ad_id ] ) || ! is_array( $stats[ $ad_id ] ) ) {
		$stats[ $ad_id ] = array(
			'total'           => 0,
			'top'             => 0,
			'middle'          => 0,
			'bottom'          => 0,
			'bottom_reach'    => 0,
			'click_total'     => 0,
			'click_top'       => 0,
			'click_middle'    => 0,
			'click_bottom'    => 0,
			'time_10'         => 0,
			'time_30'         => 0,
			'time_60'         => 0,
			'last_post_id'    => 0,
			'last_genre'      => '',
			'last_seen'       => '',
			'last_reach_seen' => '',
			'last_click_seen' => '',
            'last_time_seen' => '',
		);
	}

	$key = 'time_' . $seconds;

	if ( ! isset( $stats[ $ad_id ][ $key ] ) ) {
		$stats[ $ad_id ][ $key ] = 0;
	}

	$stats[ $ad_id ][ $key ] = (int) $stats[ $ad_id ][ $key ] + 1;
	$stats[ $ad_id ]['last_post_id']   = $post_id;
	$stats[ $ad_id ]['last_genre']     = $genre;
	$stats[ $ad_id ]['last_time_seen'] = current_time( 'mysql' );

	update_option( 'cam_ad_impression_stats', $stats, false );

	if ( function_exists( '\Profile\Debug\debug' ) ) {
		\Profile\Debug\debug(
			'AD_TIME_REACH: ad_id=' . $ad_id .
			', post_id=' . $post_id .
			', genre=' . $genre .
			', seconds=' . $seconds
		);
	}
}

/**
 * AJAX: 滞在秒数到達ログ記録
 *
 * @return void
 */
function cam_ajax_log_context_ad_time_reach() {
	$ad_id   = isset( $_POST['ad_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ad_id'] ) ) : '';
	$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
	$genre   = isset( $_POST['genre'] ) ? sanitize_text_field( wp_unslash( $_POST['genre'] ) ) : '';
	$seconds = isset( $_POST['seconds'] ) ? (int) $_POST['seconds'] : 0;

	if ( '' === $ad_id ) {
		wp_send_json_error( array( 'message' => 'missing ad_id' ), 400 );
	}

	cam_log_context_ad_time_reach(
		array(
			'ad_id'   => $ad_id,
			'post_id' => $post_id,
			'genre'   => $genre,
			'seconds' => $seconds,
		)
	);

	wp_send_json_success( array( 'logged' => true ) );
}
add_action( 'wp_ajax_cam_log_context_ad_time_reach', 'cam_ajax_log_context_ad_time_reach' );
add_action( 'wp_ajax_nopriv_cam_log_context_ad_time_reach', 'cam_ajax_log_context_ad_time_reach' );
