<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 広告管理メニュー追加
 */
function cam_ad_register_admin_menus() {
	\add_submenu_page(
		'cam-ad-main',
		'広告枠設定',
		'広告枠設定',
		'manage_options',
		'cam-ad-slots',
		'cam_ad_render_slots_page'
	);

	\add_submenu_page(
		'cam-ad-main',
		'広告申込一覧',
		'広告申込一覧',
		'manage_options',
		'cam-ad-applications',
		'cam_ad_render_applications_page'
	);

	\add_submenu_page(
		'cam-ad-main',
		'承認済広告',
		'承認済広告',
		'manage_options',
		'cam-ad-approved',
		'cam_ad_render_approved_page'
	);

	\add_submenu_page(
		'cam-ad-main',
		'広告掲載実績',
		'広告掲載実績',
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
		<p>各ページにCA付き広告枠を自動的に設置するページです（今後開発予定）</p>
	</div>
	<?php
}

/**
 * 広告申込一覧画面
 */
function cam_ad_render_applications_page() {
	global $wpdb;

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '権限がありません。' );
	}

	$applications_table = $wpdb->prefix . 'cam_ad_applications';

	$applications = $wpdb->get_results(
		"SELECT * FROM {$applications_table} ORDER BY id DESC",
		ARRAY_A
	);

	echo '<div class="wrap">';
	echo '<h1>CA広告申込一覧</h1>';

	echo '<p>';
	echo '<a href="' . esc_url( admin_url( 'admin.php?page=cam-ad-main' ) ) . '" class="button button-primary">広告申込を登録する</a>';
	echo '</p>';

	echo '<h2>登録済み広告申込</h2>';

	if ( empty( $applications ) ) {
		echo '<p>まだ広告申込は登録されていません。</p>';
		echo '</div>';
		return;
	}

	echo '<table class="widefat striped">';
	echo '<thead>';
	echo '<tr>';
	echo '<th>ID</th>';
	echo '<th>受付番号</th>';
	echo '<th>広告主名</th>';
	echo '<th>genre</th>';
	echo '<th>広告枠</th>';
	echo '<th>掲載期間</th>';
	echo '<th>単価</th>';
	echo '<th>状態</th>';
	echo '<th>登録日時</th>';
	echo '<th>操作</th>';
	echo '</tr>';
	echo '</thead>';
	echo '<tbody>';

	foreach ( $applications as $row ) {
        $slot_name = '';

        if ( ! empty( $row['slot_id'] ) ) {
            $slot = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT slot_name FROM {$wpdb->prefix}cam_ad_slots WHERE id = %d",
                     (int) $row['slot_id']
                ),
                ARRAY_A
            );

            if ( $slot && isset( $slot['slot_name'] ) ) {
                $slot_name = $slot['slot_name'];
            }
        }
		$id = isset( $row['id'] ) ? (int) $row['id'] : 0;

		$detail_url = add_query_arg(
			array(
				'page'           => 'cam-ad-application-detail',
				'application_id' => $id,
			),
			admin_url( 'admin.php' )
		);

		echo '<tr>';
		echo '<td>' . esc_html( $id ) . '</td>';
		echo '<td>' . esc_html( isset( $row['application_code'] ) ? $row['application_code'] : '' ) . '</td>';
		echo '<td>' . esc_html( isset( $row['advertiser_name_snapshot'] ) ? $row['advertiser_name_snapshot'] : '' ) . '</td>';
		echo '<td>' . esc_html( isset( $row['genre'] ) ? $row['genre'] : '' ) . '</td>';
		echo '<td>' . esc_html( $slot_name ) . '</td>';
		echo '<td>' . esc_html( isset( $row['start_date'] ) ? $row['start_date'] : '' ) . ' ～ ' . esc_html( isset( $row['end_date'] ) ? $row['end_date'] : '' ) . '</td>';
		echo '<td>' . esc_html( isset( $row['bid_type'] ) ? $row['bid_type'] : '' ) . ' / ' . esc_html( isset( $row['bid_price'] ) ? $row['bid_price'] : '' ) . '</td>';
		echo '<td>' . esc_html( isset( $row['status'] ) ? $row['status'] : '' ) . '</td>';
		echo '<td>' . esc_html( isset( $row['created_at'] ) ? $row['created_at'] : '' ) . '</td>';
		$status = isset( $row['status'] ) ? (string) $row['status'] : '';

        $approve_url = wp_nonce_url(
	        add_query_arg(
		        array(
			        'action'         => 'cam_ad_application_approve',
			        'application_id' => $id,
		        ),
		        admin_url( 'admin-post.php' )
	        ),
	        'cam_ad_application_approve_' . $id
        );

        $reject_url = wp_nonce_url(
	        add_query_arg(
		        array(
		        	'action'         => 'cam_ad_application_reject',
		        	'application_id' => $id,
		        ),
		        admin_url( 'admin-post.php' )
	        ),
	        'cam_ad_application_reject_' . $id
        );

        echo '<td>';

        if ( 'ready' === $status ) {
	        echo '配信対象';
        } elseif ( 'rejected' === $status ) {
        	echo '却下済';
        } else {
        	echo '<a href="' . esc_url( $approve_url ) . '">承認</a>';
        	echo ' | ';
        	echo '<a href="' . esc_url( $reject_url ) . '">却下</a>';
        }

        echo ' | <a href="' . esc_url( $detail_url ) . '">詳細</a>';
        echo '</td>';
		echo '</tr>';
	}

	echo '</tbody>';
	echo '</table>';
	echo '</div>';
}

/**
 * 承認済広告画面
 */
function cam_ad_render_approved_page() {
	global $wpdb;

	$table = $wpdb->prefix . 'cam_ad_applications';

	$rows = $wpdb->get_results(
		"SELECT * FROM {$table} WHERE status IN ('approved','ready') ORDER BY id DESC",
		ARRAY_A
	);
	?>
	<div class="wrap">
		<h1>CA承認済広告</h1>

		<table class="widefat fixed striped">
			<thead>
				<tr>
					<th>ID</th>
					<th>広告主</th>
					<th>genre</th>
					<th>状態</th>
					<th>操作</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="5">データがありません</td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['id'] ); ?></td>
							<td><?php echo esc_html( $row['advertiser_name_snapshot'] ); ?></td>
							<td><?php echo esc_html( $row['genre'] ); ?></td>
							<td><?php echo esc_html( $row['status'] ); ?></td>
							<td>
								<a href="<?php echo esc_url( wp_nonce_url(
									add_query_arg(
										array(
											'action' => 'cam_convert_application_to_context_ad',
											'application_id' => $row['id'],
										),
										admin_url( 'admin-post.php' )
									),
									'cam_convert_application_to_context_ad_' . $row['id']
								) ); ?>">コンテキスト広告化</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * 広告掲載実績画面
 */
function cam_ad_render_stats_page() {

    $stats = get_option( 'cam_ad_impression_stats', array() );

    if ( ! is_array( $stats ) ) {
	    $stats = array();
    }

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '権限がありません。' );
	}

	$ads = get_option( 'cam_context_ads', array() );

	if ( ! is_array( $ads ) ) {
		$ads = array();
	}

	echo '<div class="wrap">';
	echo '<h1>CA広告掲載実績</h1>';
	echo '<p>以下は登録済みのコンテキスト広告の掲載実績です。</p>';

	if ( empty( $ads ) ) {
		echo '<p>まだ広告は登録されていません。</p>';
		echo '</div>';
		return;
	}

	echo '<table class="widefat striped">';
	echo '<thead>';
	echo '<tr>';
	echo '<th>ID</th>';
	echo '<th>広告主</th>';
	echo '<th>genre</th>';
	echo '<th>状態</th>';
	echo '<th>開始日</th>';
	echo '<th>終了日</th>';
	echo '<th>表示回数</th>';
	echo '<th>上</th>';
	echo '<th>中</th>';
	echo '<th>下</th>';
	echo '<th>bottom到達</th>';
	echo '<th>10秒</th>';
	echo '<th>30秒</th>';
	echo '<th>60秒</th>';
	echo '<th>最終滞在</th>';
	echo '<th>クリック</th>';
    echo '<th>CTR</th>';
	echo '<th>上クリック</th>';
	echo '<th>中クリック</th>';
	echo '<th>下クリック</th>';
	echo '<th>最終クリック</th>';
	echo '<th>最終表示</th>';
	echo '<th>上段見出し</th>';
	echo '<th>中段見出し</th>';
	echo '<th>下段見出し</th>';
	echo '</tr>';
	echo '</thead>';
	echo '<tbody>';

foreach ( $ads as $ad ) {
	$ad_id = isset( $ad['id'] ) ? (string) $ad['id'] : '';
	$stat  = isset( $stats[ $ad_id ] ) && is_array( $stats[ $ad_id ] ) ? $stats[ $ad_id ] : array();

    $impressions = isset( $stat['total'] ) ? (int) $stat['total'] : 0;
    $clicks      = isset( $stat['click_total'] ) ? (int) $stat['click_total'] : 0;

    $ctr = 0;
    if ( $impressions > 0 ) {
        $ctr = ( $clicks / $impressions ) * 100;
    }

	echo '<tr>';
	echo '<td>' . esc_html( $ad_id ) . '</td>';
	echo '<td>' . esc_html( isset( $ad['advertiser'] ) ? $ad['advertiser'] : '' ) . '</td>';
	echo '<td>' . esc_html( isset( $ad['genre'] ) ? $ad['genre'] : '' ) . '</td>';
	echo '<td>' . esc_html( isset( $ad['status'] ) ? $ad['status'] : '' ) . '</td>';
	echo '<td>' . esc_html( isset( $ad['start_date'] ) ? $ad['start_date'] : '' ) . '</td>';
	echo '<td>' . esc_html( isset( $ad['end_date'] ) ? $ad['end_date'] : '' ) . '</td>';

	echo '<td>' . esc_html( isset( $stat['total'] ) ? (int) $stat['total'] : 0 ) . '</td>';
	echo '<td>' . esc_html( isset( $stat['top'] ) ? (int) $stat['top'] : 0 ) . '</td>';
	echo '<td>' . esc_html( isset( $stat['middle'] ) ? (int) $stat['middle'] : 0 ) . '</td>';
	echo '<td>' . esc_html( isset( $stat['bottom'] ) ? (int) $stat['bottom'] : 0 ) . '</td>';
	echo '<td>' . esc_html( isset( $stat['bottom_reach'] ) ? (int) $stat['bottom_reach'] : 0 ) . '</td>';

	echo '<td>' . esc_html( isset( $stat['time_10'] ) ? (int) $stat['time_10'] : 0 ) . '</td>';
	echo '<td>' . esc_html( isset( $stat['time_30'] ) ? (int) $stat['time_30'] : 0 ) . '</td>';
	echo '<td>' . esc_html( isset( $stat['time_60'] ) ? (int) $stat['time_60'] : 0 ) . '</td>';
	echo '<td>' . esc_html( isset( $stat['last_time_seen'] ) ? $stat['last_time_seen'] : '' ) . '</td>';

	echo '<td>' . esc_html( isset( $stat['click_total'] ) ? (int) $stat['click_total'] : 0 ) . '</td>';
    echo '<td>' . esc_html( number_format( $ctr, 2 ) ) . '%</td>';
	echo '<td>' . esc_html( isset( $stat['click_top'] ) ? (int) $stat['click_top'] : 0 ) . '</td>';
	echo '<td>' . esc_html( isset( $stat['click_middle'] ) ? (int) $stat['click_middle'] : 0 ) . '</td>';
	echo '<td>' . esc_html( isset( $stat['click_bottom'] ) ? (int) $stat['click_bottom'] : 0 ) . '</td>';
	echo '<td>' . esc_html( isset( $stat['last_click_seen'] ) ? $stat['last_click_seen'] : '' ) . '</td>';
	echo '<td>' . esc_html( isset( $stat['last_seen'] ) ? $stat['last_seen'] : '' ) . '</td>';

	echo '<td>' . esc_html( isset( $ad['top_headline'] ) ? $ad['top_headline'] : '' ) . '</td>';
	echo '<td>' . esc_html( isset( $ad['middle_headline'] ) ? $ad['middle_headline'] : '' ) . '</td>';
	echo '<td>' . esc_html( isset( $ad['bottom_headline'] ) ? $ad['bottom_headline'] : '' ) . '</td>';
	echo '</tr>';
}

	echo '</tbody>';
	echo '</table>';
	echo '</div>';
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 承認済み申込を既存コンテキスト広告へ登録
 */
\add_action( 'admin_post_cam_convert_application_to_context_ad', 'handle_convert_application_to_context_ad' );

function handle_convert_application_to_context_ad() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '権限がありません。' );
	}

	$application_id = isset( $_GET['application_id'] ) ? absint( $_GET['application_id'] ) : 0;
	if ( ! $application_id ) {
		wp_die( 'application_id が不正です。' );
	}

	check_admin_referer( 'cam_convert_application_to_context_ad_' . $application_id );

	global $wpdb;

	$applications_table = $wpdb->prefix . 'cam_ad_applications';
	$items_table        = $wpdb->prefix . 'cam_ad_application_items';

	$application = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$applications_table} WHERE id = %d LIMIT 1",
			$application_id
		),
		ARRAY_A
	);

	if ( empty( $application ) || ! is_array( $application ) ) {
		wp_die( '申込データが見つかりません。' );
	}

	if ( ! in_array( $application['status'], array( 'approved', 'ready' ), true ) ) {
		wp_die( '承認済みまたは配信準備完了の申込のみ登録できます。' );
	}

	$items = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$items_table} WHERE application_id = %d ORDER BY id ASC",
			$application_id
		),
		ARRAY_A
	);

	if ( empty( $items ) || ! is_array( $items ) ) {
		wp_die( '広告クリエイティブが見つかりません。' );
	}

	$grouped = array(
		'top'    => null,
		'middle' => null,
		'bottom' => null,
	);

    foreach ( $items as $item ) {
	    $pos = isset( $item['slot_position'] ) ? trim( strtolower( (string) $item['slot_position'] ) ) : '';

	    if ( '' === $pos ) {
		    continue;
	    }

	    if ( array_key_exists( $pos, $grouped ) ) {
		    $grouped[ $pos ] = $item;
	    }
    }

	$context_ads = get_option( 'cam_context_ads', array() );
	if ( ! is_array( $context_ads ) ) {
		$context_ads = array();
	}

	// 申込ID単位で既存変換済み広告を探す
	$existing_index = null;
	foreach ( $context_ads as $index => $ad ) {
		if (
			is_array( $ad ) &&
			isset( $ad['source_application_id'] ) &&
			(int) $ad['source_application_id'] === $application_id
		) {
			$existing_index = $index;
			break;
		}
	}


    $source_genre = isset( $application['genre'] ) ? (string) $application['genre'] : '';

    $genre_map = array(
	    'fashion_suit'   => 'suit',
	    'fashion_casual' => 'casual',
	    'fashion_vintage'=> 'vintage',
	    'culture_book'   => 'book',
	    'culture_movie'  => 'movie',
	    'travel_japan'   => 'japan',
	    'travel_international' => 'international',
    );

    $context_genre = isset( $genre_map[ $source_genre ] ) ? $genre_map[ $source_genre ] : $source_genre;


    $pricing_type = '';
    if ( isset( $application['bid_type'] ) ) {
    	$pricing_type = (string) $application['bid_type'];
    }

    $unit_price = 0;
    if ( isset( $application['bid_price'] ) ) {
	    $unit_price = (float) $application['bid_price'];
    }

    $context_ad = array(
		'id'                    => $existing_index !== null && ! empty( $context_ads[ $existing_index ]['id'] )
			? (string) $context_ads[ $existing_index ]['id']
			: 'cam-context-' . wp_generate_password( 8, false, false ),
		'source_application_id' => $application_id,
		'advertiser'            => isset( $application['advertiser_name_snapshot'] ) ? (string) $application['advertiser_name_snapshot'] : '',
		'genre'                 => $context_genre,
        'enabled'               => 1,
		'status'                => 'active',
        'bid_type'              => $pricing_type,
        'bid_price'             => $unit_price,
        'pricing_type'          => $pricing_type,
        'unit_price'            => $unit_price,
		'start_date'            => isset( $application['start_date'] ) ? (string) $application['start_date'] : '',
		'end_date'              => isset( $application['end_date'] ) ? (string) $application['end_date'] : '',
		'impression_count'      => $existing_index !== null && isset( $context_ads[ $existing_index ]['impression_count'] ) ? (int) $context_ads[ $existing_index ]['impression_count'] : 0,
		'top_impression_count'  => $existing_index !== null && isset( $context_ads[ $existing_index ]['top_impression_count'] ) ? (int) $context_ads[ $existing_index ]['top_impression_count'] : 0,
		'mid_impression_count'  => $existing_index !== null && isset( $context_ads[ $existing_index ]['mid_impression_count'] ) ? (int) $context_ads[ $existing_index ]['mid_impression_count'] : 0,
		'bot_impression_count'  => $existing_index !== null && isset( $context_ads[ $existing_index ]['bot_impression_count'] ) ? (int) $context_ads[ $existing_index ]['bot_impression_count'] : 0,
		'bottom_reached'        => $existing_index !== null && isset( $context_ads[ $existing_index ]['bottom_reached'] ) ? (int) $context_ads[ $existing_index ]['bottom_reached'] : 0,
		'sec10_count'           => $existing_index !== null && isset( $context_ads[ $existing_index ]['sec10_count'] ) ? (int) $context_ads[ $existing_index ]['sec10_count'] : 0,
		'sec30_count'           => $existing_index !== null && isset( $context_ads[ $existing_index ]['sec30_count'] ) ? (int) $context_ads[ $existing_index ]['sec30_count'] : 0,
		'sec60_count'           => $existing_index !== null && isset( $context_ads[ $existing_index ]['sec60_count'] ) ? (int) $context_ads[ $existing_index ]['sec60_count'] : 0,
		'last_stay_at'          => $existing_index !== null && isset( $context_ads[ $existing_index ]['last_stay_at'] ) ? (string) $context_ads[ $existing_index ]['last_stay_at'] : '',
		'top_click_count'       => $existing_index !== null && isset( $context_ads[ $existing_index ]['top_click_count'] ) ? (int) $context_ads[ $existing_index ]['top_click_count'] : 0,
		'mid_click_count'       => $existing_index !== null && isset( $context_ads[ $existing_index ]['mid_click_count'] ) ? (int) $context_ads[ $existing_index ]['mid_click_count'] : 0,
		'bot_click_count'       => $existing_index !== null && isset( $context_ads[ $existing_index ]['bot_click_count'] ) ? (int) $context_ads[ $existing_index ]['bot_click_count'] : 0,
		'final_click_count'     => $existing_index !== null && isset( $context_ads[ $existing_index ]['final_click_count'] ) ? (int) $context_ads[ $existing_index ]['final_click_count'] : 0,
		'last_display_at'       => $existing_index !== null && isset( $context_ads[ $existing_index ]['last_display_at'] ) ? (string) $context_ads[ $existing_index ]['last_display_at'] : '',
		'top_headline'          => ! empty( $grouped['top']['headline'] ) ? (string) $grouped['top']['headline'] : '',
		'top_destination'       => ! empty( $grouped['top']['landing_url'] ) ? (string) $grouped['top']['landing_url'] : '',
		'top_image'             => ! empty( $grouped['top']['image_url'] ) ? (string) $grouped['top']['image_url'] : '',
		'middle_headline'          => ! empty( $grouped['middle']['headline'] ) ? (string) $grouped['middle']['headline'] : '',
		'middle_destination'       => ! empty( $grouped['middle']['landing_url'] ) ? (string) $grouped['middle']['landing_url'] : '',
		'middle_image'             => ! empty( $grouped['middle']['image_url'] ) ? (string) $grouped['middle']['image_url'] : '',
		'bottom_headline'          => ! empty( $grouped['bottom']['headline'] ) ? (string) $grouped['bottom']['headline'] : '',
		'bottom_destination'       => ! empty( $grouped['bottom']['landing_url'] ) ? (string) $grouped['bottom']['landing_url'] : '',
		'bottom_image'             => ! empty( $grouped['bottom']['image_url'] ) ? (string) $grouped['bottom']['image_url'] : '',
	);

	if ( null !== $existing_index ) {
		$context_ads[ $existing_index ] = $context_ad;
	} else {
		$context_ads[] = $context_ad;
	}

	update_option( 'cam_context_ads', array_values( $context_ads ) );

	// ready にしておくと管理上わかりやすい
	$wpdb->update(
		$applications_table,
		array(
			'status'     => 'ready',
			'updated_at' => current_time( 'mysql' ),
		),
		array( 'id' => $application_id ),
		array( '%s', '%s' ),
		array( '%d' )
	);

    $redirect_url = add_query_arg(
	    array(
	    	'page'                  => 'ca-manager',
		    'cam_context_converted' => 1,
	    ),
	    admin_url( 'options-general.php' )
    );

    $redirect_url .= '#cam-context-ads';

    wp_safe_redirect( $redirect_url );
    exit;
}
