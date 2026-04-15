<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 広告CAメタボックス追加
 */
function cam_add_ad_meta_box() {
	add_meta_box(
		'cam_ad_meta_box',
		'広告CA',
		'cam_render_ad_meta_box',
		array( 'post', 'page' ),
		'normal',
		'default'
	);
}
// add_action( 'add_meta_boxes', 'cam_add_ad_meta_box' );

/**
 * 旧広告メタを新形式1件目へ読み替え
 *
 * @param int $post_id Post ID
 * @return array
 */
function cam_get_legacy_ad_item( $post_id ) {
	$status        = get_post_meta( $post_id, '_cam_ad_status', true );
	$advertiser    = get_post_meta( $post_id, '_cam_advertiser', true );
	$campaign_name = get_post_meta( $post_id, '_cam_campaign_name', true );
	$creative_id   = get_post_meta( $post_id, '_cam_creative_id', true );

	if (
		'' === (string) $status &&
		'' === (string) $advertiser &&
		'' === (string) $campaign_name &&
		'' === (string) $creative_id
	) {
		return array();
	}

	$id = 'cam-ad-' . $post_id . '-1';

	return array(
		'enabled'        => 'active' === $status ? '1' : '',
		'status'         => $status ?: 'unset',
		'advertiser'     => $advertiser ?: '',
		'campaign_name'  => $campaign_name ?: '',
		'creative_id'    => $creative_id ?: '',
		'headline'       => $campaign_name ?: '広告',
		'rights_holder'  => '',
		'destination'    => '',
		'image'          => '',
		'ad_code'        => '',
		'id'             => $id,
		'selector'       => '#' . $id,
		'description'    => '記事タイトル下に表示する広告',
		'placement'      => 'top_under_title',
	);
}

/**
 * 保存済み広告CAデータ取得
 *
 * @param int $post_id Post ID
 * @return array
 */
function cam_get_ad_items_for_edit( $post_id ) {
	$ad_items = get_post_meta( $post_id, '_cam_ad_items', true );

	if ( is_array( $ad_items ) && ! empty( $ad_items ) ) {
		return $ad_items;
	}

	$legacy = cam_get_legacy_ad_item( $post_id );
	if ( ! empty( $legacy ) ) {
		return array( $legacy );
	}

	return array(
		array(
			'enabled'        => '',
			'status'         => 'unset',
			'advertiser'     => '',
			'campaign_name'  => '',
			'creative_id'    => '',
			'headline'       => '',
			'rights_holder'  => '',
			'destination'    => '',
			'image'          => '',
			'ad_code'        => '',
			'id'             => '',
			'selector'       => '',
			'description'    => '',
			'placement'      => 'top_under_title',
		),
	);
}

/**
 * メタボックス描画
 */
function cam_render_ad_meta_box( $post ) {
	wp_nonce_field( 'cam_save_ad_meta_box', 'cam_ad_meta_box_nonce' );

	$ad_items = cam_get_ad_items_for_edit( $post->ID );
	$item     = $ad_items[0];
	?>
	<style>
		.cam-ad-box p {
			margin: 0 0 14px;
		}
		.cam-ad-box label {
			display: block;
			font-weight: 600;
			margin-bottom: 4px;
		}
		.cam-ad-box input[type="text"],
		.cam-ad-box input[type="url"],
		.cam-ad-box select,
		.cam-ad-box textarea {
			width: 100%;
			max-width: 900px;
		}
		.cam-ad-note {
			color: #666;
			font-size: 12px;
			margin-top: 4px;
		}
		.cam-ad-preview {
			margin-top: 12px;
			padding: 12px;
			border: 1px solid #ddd;
			background: #fff;
			max-width: 900px;
		}
		.cam-ad-preview img {
			max-width: 100%;
			height: auto;
			display: block;
		}
	</style>

	<div class="cam-ad-box">
		<p>
			<label for="cam_ad_enabled">表示する</label>
			<input
				type="checkbox"
				id="cam_ad_enabled"
				name="cam_ad_items[0][enabled]"
				value="1"
				<?php checked( ! empty( $item['enabled'] ) ); ?>
			>
			タイトル直下に広告を表示
		</p>

		<p>
			<label for="cam_ad_status">状態</label>
			<select id="cam_ad_status" name="cam_ad_items[0][status]">
				<option value="unset" <?php selected( $item['status'] ?? 'unset', 'unset' ); ?>>未設定</option>
				<option value="active" <?php selected( $item['status'] ?? '', 'active' ); ?>>有効</option>
				<option value="inactive" <?php selected( $item['status'] ?? '', 'inactive' ); ?>>無効</option>
			</select>
		</p>

		<p>
			<label for="cam_ad_advertiser">広告主</label>
			<input type="text" id="cam_ad_advertiser" name="cam_ad_items[0][advertiser]" value="<?php echo esc_attr( $item['advertiser'] ?? '' ); ?>">
		</p>

		<p>
			<label for="cam_ad_campaign_name">キャンペーン名</label>
			<input type="text" id="cam_ad_campaign_name" name="cam_ad_items[0][campaign_name]" value="<?php echo esc_attr( $item['campaign_name'] ?? '' ); ?>">
		</p>

		<p>
			<label for="cam_ad_creative_id">クリエイティブID</label>
			<input type="text" id="cam_ad_creative_id" name="cam_ad_items[0][creative_id]" value="<?php echo esc_attr( $item['creative_id'] ?? '' ); ?>">
		</p>

		<p>
			<label for="cam_ad_headline">広告見出し / 名称</label>
			<input type="text" id="cam_ad_headline" name="cam_ad_items[0][headline]" value="<?php echo esc_attr( $item['headline'] ?? '' ); ?>">
		</p>

		<p>
			<label for="cam_ad_rights_holder">権利者</label>
			<input type="text" id="cam_ad_rights_holder" name="cam_ad_items[0][rights_holder]" value="<?php echo esc_attr( $item['rights_holder'] ?? '' ); ?>">
		</p>

		<p>
			<label for="cam_ad_destination">遷移先URL</label>
			<input type="url" id="cam_ad_destination" name="cam_ad_items[0][destination]" value="<?php echo esc_attr( $item['destination'] ?? '' ); ?>" placeholder="https://example.com/">
		</p>

		<p>
			<label for="cam_ad_image">広告画像URL</label>
			<input type="url" id="cam_ad_image" name="cam_ad_items[0][image]" value="<?php echo esc_attr( $item['image'] ?? '' ); ?>" placeholder="https://example.com/banner.jpg">
			<div class="cam-ad-note">まずはURL入力で運用。将来メディアライブラリ選択ボタンを追加できます。</div>
		</p>

		<p>
			<label for="cam_ad_code">広告コード（将来のGoogle Ads用）</label>
			<textarea id="cam_ad_code" name="cam_ad_items[0][ad_code]" rows="8" placeholder="<script>..."></textarea>
			<div class="cam-ad-note">今は画像広告優先。コードが入っている場合は将来こちらを優先表示できます。</div>
		</p>

		<p>
			<label for="cam_ad_description">説明</label>
			<textarea id="cam_ad_description" name="cam_ad_items[0][description]" rows="3"><?php echo esc_textarea( $item['description'] ?? '' ); ?></textarea>
		</p>

		<p>
			<label for="cam_ad_id">広告ID（空欄なら自動発行）</label>
			<input type="text" id="cam_ad_id" name="cam_ad_items[0][id]" value="<?php echo esc_attr( $item['id'] ?? '' ); ?>" placeholder="空欄で自動発行">
			<div class="cam-ad-note">selector は自動で #広告ID になります。表示位置はタイトル直下固定です。</div>
		</p>

		<input type="hidden" name="cam_ad_items[0][placement]" value="top_under_title">

		<?php if ( ! empty( $item['image'] ) ) : ?>
			<div class="cam-ad-preview">
				<strong>広告画像プレビュー</strong>
				<p><img src="<?php echo esc_url( $item['image'] ); ?>" alt=""></p>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * 保存処理
 */
function cam_save_ad_meta_box( $post_id ) {
	if ( ! isset( $_POST['cam_ad_meta_box_nonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( $_POST['cam_ad_meta_box_nonce'], 'cam_save_ad_meta_box' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$raw_items = $_POST['cam_ad_items'] ?? array();
	if ( ! is_array( $raw_items ) || empty( $raw_items[0] ) ) {
		delete_post_meta( $post_id, '_cam_ad_items' );
		return;
	}

	$item = $raw_items[0];

	$enabled        = ! empty( $item['enabled'] ) ? '1' : '';
	$status         = sanitize_text_field( $item['status'] ?? 'unset' );
	$advertiser     = sanitize_text_field( $item['advertiser'] ?? '' );
	$campaign_name  = sanitize_text_field( $item['campaign_name'] ?? '' );
	$creative_id    = sanitize_text_field( $item['creative_id'] ?? '' );
	$headline       = sanitize_text_field( $item['headline'] ?? '' );
	$rights_holder  = sanitize_text_field( $item['rights_holder'] ?? '' );
	$destination    = esc_url_raw( $item['destination'] ?? '' );
	$image          = esc_url_raw( $item['image'] ?? '' );
	$description    = sanitize_textarea_field( $item['description'] ?? '' );
	$placement      = sanitize_text_field( $item['placement'] ?? 'top_under_title' );
	$id             = sanitize_title( $item['id'] ?? '' );

	// ad_code は将来scriptを許容したいので、今は最小限の整形に留める
	$ad_code = isset( $item['ad_code'] ) ? trim( (string) $item['ad_code'] ) : '';

	if (
		'' === $advertiser &&
		'' === $campaign_name &&
		'' === $creative_id &&
		'' === $headline &&
		'' === $destination &&
		'' === $image &&
		'' === $ad_code
	) {
		delete_post_meta( $post_id, '_cam_ad_items' );
		return;
	}

	if ( '' === $id ) {
		$id = 'cam-ad-' . $post_id . '-1';
	}

	$selector = '#' . $id;

	$clean_item = array(
		'enabled'        => $enabled,
		'status'         => in_array( $status, array( 'unset', 'active', 'inactive' ), true ) ? $status : 'unset',
		'advertiser'     => $advertiser,
		'campaign_name'  => $campaign_name,
		'creative_id'    => $creative_id,
		'headline'       => $headline,
		'rights_holder'  => $rights_holder,
		'destination'    => $destination,
		'image'          => $image,
		'ad_code'        => $ad_code,
		'id'             => $id,
		'selector'       => $selector,
		'description'    => $description,
		'placement'      => 'top_under_title',
	);

	update_post_meta( $post_id, '_cam_ad_items', array( $clean_item ) );

	// 旧広告設定が残っていても、新広告CAに統合したので同期しておく
	update_post_meta( $post_id, '_cam_ad_status', $clean_item['status'] );
	update_post_meta( $post_id, '_cam_advertiser', $clean_item['advertiser'] );
	update_post_meta( $post_id, '_cam_campaign_name', $clean_item['campaign_name'] );
	update_post_meta( $post_id, '_cam_creative_id', $clean_item['creative_id'] );
}
// add_action( 'save_post', 'cam_save_ad_meta_box' );