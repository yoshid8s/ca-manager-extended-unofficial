<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CAマネージャーのメタボックス追加
 */
function camui_add_ca_meta_box() {
	$screens = array( 'post', 'page' );

	foreach ( $screens as $screen ) {
		add_meta_box(
			'cam_ca_manager_box',
			'CAマネージャー',
			'camui_render_ca_meta_box',
			$screen,
			'normal',
			'high'
		);
	}
}
add_action( 'add_meta_boxes', 'camui_add_ca_meta_box' );

/**
 * 旧単一データから初期アイテムを作る
 *
 * @param int $post_id Post ID.
 * @return array
 */
function camui_get_legacy_embedded_items( $post_id ) {
	$selector      = get_post_meta( $post_id, '_cam_embedded_selector', true );
	$rights_holder = get_post_meta( $post_id, '_cam_rights_holder', true );
	$source_url    = get_post_meta( $post_id, '_cam_source_url', true );
	$description   = get_post_meta( $post_id, '_cam_license_note', true );

	if ( empty( $selector ) && empty( $rights_holder ) && empty( $source_url ) && empty( $description ) ) {
		return array();
	}

	return array(
	    array(
	    	'kind'          => 'article',
	    	'selector'      => $selector,
	    	'selected_text' => '',
	    	'image_url'     => '',
	    	'image_alt'     => '',
	    	'caption'       => '',
	    	'rights_holder' => $rights_holder,
	    	'source_url'    => $source_url,
	    	'description'   => $description,
	    ),
    );
}

/**
 * 複数埋め込みデータ取得
 *
 * @param int $post_id Post ID.
 * @return array
 */
function camui_get_embedded_items( $post_id ) {
	$items = get_post_meta( $post_id, '_cam_embedded_items', true );

	if ( is_array( $items ) && ! empty( $items ) ) {
		return $items;
	}

	return camui_get_legacy_embedded_items( $post_id );
}

/**
 * 画面側・保存側で使うテキスト正規化
 *
 * @param string $text Text.
 * @return string
 */
function camui_normalize_selected_text( string $text ): string {
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( '/[\x{00A0}\s]+/u', ' ', $text );
	return trim( $text );
}

/**
 * 正規化テキストから selector を生成
 *
 * @param string $text Text.
 * @return string
 */
function camui_selector_from_selected_text( string $text ): string {
	$normalized = camui_normalize_selected_text( $text );

	if ( '' === $normalized ) {
		return '';
	}

	return '#op-body-' . substr( sha1( $normalized ), 0, 12 );
}

/**
 * 埋め込み1件のUI描画
 *
 * @param int   $index Item index.
 * @param array $item  Item data.
 */
function camui_render_embedded_item( $index, $item ) {
	$kind          = isset( $item['kind'] ) && in_array( $item['kind'], array( 'article', 'image' ), true ) ? $item['kind'] : 'article';
    $selector      = isset( $item['selector'] ) ? $item['selector'] : '';
    $selected_text = isset( $item['selected_text'] ) ? $item['selected_text'] : '';
    $image_url     = isset( $item['image_url'] ) ? $item['image_url'] : '';
    $image_alt     = isset( $item['image_alt'] ) ? $item['image_alt'] : '';
    $caption       = isset( $item['caption'] ) ? $item['caption'] : '';
    $rights_holder = isset( $item['rights_holder'] ) ? $item['rights_holder'] : '';
    $source_url    = isset( $item['source_url'] ) ? $item['source_url'] : '';
    $description   = isset( $item['description'] ) ? $item['description'] : '';
	?>
	<div class="camui-embedded-item" data-camui-embedded-index="<?php echo esc_attr( $index ); ?>">
		<div class="camui-embedded-item-header">
			<strong>埋め込みコンテンツ <?php echo esc_html( $index + 1 ); ?></strong>
			<button type="button" class="button-link-delete camui-remove-embedded-item">削除</button>
		</div>

		<table class="form-table" role="presentation">
			<tbody>
                <tr>
	                <th scope="row">
	                	<label for="cam_kind_<?php echo esc_attr( $index ); ?>" data-cam-field-label="kind">種別</label>
	                </th>
	            <td>
		            <select
		            	id="cam_kind_<?php echo esc_attr( $index ); ?>"
		            	name="cam_embedded_items[<?php echo esc_attr( $index ); ?>][kind]"
		            	class="cam-embedded-kind"
		            	data-cam-field="kind"
		            >
			            <option value="article" <?php selected( $kind, 'article' ); ?>>テキスト</option>
			            <option value="image" <?php selected( $kind, 'image' ); ?>>画像</option>
		            </select>
	            </td>
                </tr>
				<tr>
					<th scope="row">
						<label
							for="cam_selector_<?php echo esc_attr( $index ); ?>"
							data-cam-field-label="selector"
						>CSSセレクタ</label>
					</th>
					<td>
						<input
							type="text"
							id="cam_selector_<?php echo esc_attr( $index ); ?>"
							name="cam_embedded_items[<?php echo esc_attr( $index ); ?>][selector]"
							value="<?php echo esc_attr( $selector ); ?>"
							class="regular-text code cam-embedded-selector-input"
							data-cam-field="selector"
							readonly
						/>
						<input
							type="hidden"
							id="cam_selected_text_<?php echo esc_attr( $index ); ?>"
							class="cam-embedded-selected-text"
							name="cam_embedded_items[<?php echo esc_attr( $index ); ?>][selected_text]"
							value="<?php echo esc_attr( $selected_text ); ?>"
							data-cam-field="selected_text"
						/>
						<button type="button" class="button cam-selector-pick-button">選択</button>
						<button type="button" class="button cam-selector-clear-button">クリア</button>
						<p class="description cam-selector-help-text">
	                        <?php if ( 'image' === $kind ) : ?>
	                            「選択」を押したあと、編集画面内の対象画像をクリックしてください。
                            <?php else : ?>
                            	「選択」を押したあと、編集画面内の対象テキストブロックをクリックしてください。selector は保存せず、保存時に selected_text から自動生成します。
                            <?php endif; ?>
                        </p>
                        <tr class="cam-image-fields" <?php echo ( 'image' === $kind ) ? '' : 'style="display:none;"'; ?>>
	                        <th scope="row">
	                    	<label for="cam_image_url_<?php echo esc_attr( $index ); ?>" data-cam-field-label="image_url">画像URL</label>
	                        </th>
	                        <td>
		                        <input
		                        	type="url"
		                        	id="cam_image_url_<?php echo esc_attr( $index ); ?>"
		                        	name="cam_embedded_items[<?php echo esc_attr( $index ); ?>][image_url]"
		                        	value="<?php echo esc_attr( $image_url ); ?>"
		                        	class="regular-text code cam-embedded-image-url"
		                        	data-cam-field="image_url"
		                        />
	                        </td>
                        </tr>
                        <tr class="cam-image-fields" <?php echo ( 'image' === $kind ) ? '' : 'style="display:none;"'; ?>>
                    	    <th scope="row">
                    		    <label for="cam_image_alt_<?php echo esc_attr( $index ); ?>" data-cam-field-label="image_alt">代替テキスト</label>
                    	    </th>
                    	    <td>
                    		    <input
	                    	    	type="text"
	                    	    	id="cam_image_alt_<?php echo esc_attr( $index ); ?>"
	                    	    	name="cam_embedded_items[<?php echo esc_attr( $index ); ?>][image_alt]"
		                        	value="<?php echo esc_attr( $image_alt ); ?>"
		                    	    class="regular-text cam-embedded-image-alt"
		                    	    data-cam-field="image_alt"
		                         />
	                        </td>
                        </tr>
                        <tr class="cam-image-fields" <?php echo ( 'image' === $kind ) ? '' : 'style="display:none;"'; ?>>
	                        <th scope="row">
	                        	<label for="cam_caption_<?php echo esc_attr( $index ); ?>" data-cam-field-label="caption">キャプション</label>
	                        </th>
	                        <td>
	                        	<textarea
		                        	id="cam_caption_<?php echo esc_attr( $index ); ?>"
		                        	name="cam_embedded_items[<?php echo esc_attr( $index ); ?>][caption]"
		                        	rows="3"
		                        	class="large-text cam-embedded-caption"
		                        	data-cam-field="caption"
		                        ><?php echo esc_textarea( $caption ); ?></textarea>
	                        </td>
                        </tr>
						<div class="cam-selector-preview" style="margin-top:8px;padding:10px;border:1px solid #dcdcde;background:#fff;white-space:pre-wrap;"><?php echo esc_html( $selector ? '現在のselector: ' . $selector : '未選択' ); ?></div>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label
							for="cam_rights_holder_<?php echo esc_attr( $index ); ?>"
							data-cam-field-label="rights_holder"
						>権利者</label>
					</th>
					<td>
						<input
							type="text"
							id="cam_rights_holder_<?php echo esc_attr( $index ); ?>"
							name="cam_embedded_items[<?php echo esc_attr( $index ); ?>][rights_holder]"
							value="<?php echo esc_attr( $rights_holder ); ?>"
							class="regular-text"
							data-cam-field="rights_holder"
						/>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label
							for="cam_source_url_<?php echo esc_attr( $index ); ?>"
							data-cam-field-label="source_url"
						>出典URL</label>
					</th>
					<td>
						<input
							type="url"
							id="cam_source_url_<?php echo esc_attr( $index ); ?>"
							name="cam_embedded_items[<?php echo esc_attr( $index ); ?>][source_url]"
							value="<?php echo esc_attr( $source_url ); ?>"
							class="regular-text code"
							data-cam-field="source_url"
						/>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label
							for="cam_description_<?php echo esc_attr( $index ); ?>"
							data-cam-field-label="description"
						>備考</label>
					</th>
					<td>
						<textarea
							id="cam_description_<?php echo esc_attr( $index ); ?>"
							name="cam_embedded_items[<?php echo esc_attr( $index ); ?>][description]"
							rows="4"
							class="large-text"
							data-cam-field="description"
						><?php echo esc_textarea( $description ); ?></textarea>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * メタボックス描画
 *
 * @param WP_Post $post Post object.
 */
function camui_render_ca_meta_box( $post ) {
	wp_nonce_field( 'camui_save_ca_meta_box', 'camui_ca_meta_box_nonce' );

	$article_ca_status = get_post_meta( $post->ID, '_cam_article_ca_status', true );
	$article_ca_status = $article_ca_status ? $article_ca_status : 'default';

	$ad_ca_status = get_post_meta( $post->ID, '_cam_ad_ca_status', true );
	$ad_ca_status = $ad_ca_status ? $ad_ca_status : 'default';

	$editor_name     = get_post_meta( $post->ID, '_cam_editor_name', true );
	$author_name     = get_post_meta( $post->ID, '_cam_author_name', true );
	$advertiser_name = get_post_meta( $post->ID, '_cam_advertiser_name', true );
	$campaign_name   = get_post_meta( $post->ID, '_cam_campaign_name', true );
	$creative_id     = get_post_meta( $post->ID, '_cam_creative_id', true );

	$embedded_items = camui_get_embedded_items( $post->ID );
	if ( empty( $embedded_items ) ) {
		$embedded_items = array(
	        array(
	        	'kind'          => 'article',
	        	'selector'      => '',
	        	'selected_text' => '',
	        	'image_url'     => '',
	        	'image_alt'     => '',
	        	'caption'       => '',
	        	'rights_holder' => '',
	        	'source_url'    => '',
	        	'description'   => '',
	            ),
        );
	}
	?>
	<div id="camui-tabs" class="camui-tabs-wrap">
		<div class="camui-tab-buttons">
			<button type="button" class="button button-primary camui-tab-button is-active" data-camui-tab="article">記事CA</button>
			<button type="button" class="button camui-tab-button" data-camui-tab="ad">広告CA</button>
			<button type="button" class="button camui-tab-button" data-camui-tab="embedded">埋め込みコンテンツ</button>
		</div>

		<div class="camui-tab-panels">
			<div class="camui-tab-panel is-active" data-camui-panel="article">
				<h3>記事CA</h3>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="cam_article_ca_status">状態</label></th>
							<td>
								<select name="cam_article_ca_status" id="cam_article_ca_status">
									<option value="default" <?php selected( $article_ca_status, 'default' ); ?>>未設定</option>
									<option value="success" <?php selected( $article_ca_status, 'success' ); ?>>発行済み</option>
									<option value="warning" <?php selected( $article_ca_status, 'warning' ); ?>>要確認</option>
									<option value="error" <?php selected( $article_ca_status, 'error' ); ?>>エラー</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="cam_editor_name">編集責任者</label></th>
							<td><input type="text" id="cam_editor_name" name="cam_editor_name" value="<?php echo esc_attr( $editor_name ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="cam_author_name">執筆者</label></th>
							<td><input type="text" id="cam_author_name" name="cam_author_name" value="<?php echo esc_attr( $author_name ); ?>" class="regular-text" /></td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="camui-tab-panel" data-camui-panel="ad" hidden>
	            <h3>広告CA</h3>

	            <?php
	            $ad_items = function_exists( 'cam_get_ad_items_for_edit' )
		            ? cam_get_ad_items_for_edit( $post->ID )
		            : array();

	            $item = ! empty( $ad_items ) && is_array( $ad_items[0] ?? null )
		            ? $ad_items[0]
		            : array(
		            	'enabled'       => '',
		            	'status'        => 'unset',
		            	'advertiser'    => '',
		            	'campaign_name' => '',
		            	'creative_id'   => '',
		            	'headline'      => '',
		            	'rights_holder' => '',
		            	'destination'   => '',
		            	'image'         => '',
		            	'ad_code'       => '',
		            	'id'            => '',
		            	'description'   => '',
		            );
	            ?>

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
			            <textarea id="cam_ad_code" name="cam_ad_items[0][ad_code]" rows="8" placeholder="<script>..."><?php echo esc_textarea( $item['ad_code'] ?? '' ); ?></textarea>
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
            </div>

			<div class="camui-tab-panel" data-camui-panel="embedded" hidden>
				<h3>埋め込みコンテンツ</h3>

				<div id="camui-embedded-items">
					<?php foreach ( $embedded_items as $index => $item ) : ?>
						<?php camui_render_embedded_item( $index, $item ); ?>
					<?php endforeach; ?>
				</div>

				<p style="margin-top:12px;">
					<button type="button" class="button button-secondary" id="camui-add-embedded-item">＋ 埋め込みコンテンツを追加</button>
				</p>
			</div>
		</div>
	</div>

	<script type="text/template" id="camui-embedded-item-template">
		<div class="camui-embedded-item" data-camui-embedded-index="__INDEX__">
			<div class="camui-embedded-item-header">
				<strong>埋め込みコンテンツ __NUMBER__</strong>
				<button type="button" class="button-link-delete camui-remove-embedded-item">削除</button>
			</div>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
	                    <th scope="row">
		                    <label for="cam_kind___INDEX__" data-cam-field-label="kind">種別</label>
	                    </th>
	                    <td>
		                  <select
		                      	id="cam_kind___INDEX__"
		                    	name="cam_embedded_items[__INDEX__][kind]"
		                	    class="cam-embedded-kind"
		                	    data-cam-field="kind"
		                    >
		                	    <option value="article">テキスト</option>
		                	    <option value="image">画像</option>
		                    </select>
	                    </td>
                    </tr>
                    <tr>
						<th scope="row">
							<label for="cam_selector___INDEX__" data-cam-field-label="selector">CSSセレクタ</label>
						</th>
						<td>
							<input
								type="text"
								id="cam_selector___INDEX__"
								name="cam_embedded_items[__INDEX__][selector]"
								value=""
								class="regular-text code cam-embedded-selector-input"
								data-cam-field="selector"
								readonly
							/>
							<input
								type="hidden"
								id="cam_selected_text___INDEX__"
								class="cam-embedded-selected-text"
								name="cam_embedded_items[__INDEX__][selected_text]"
								value=""
								data-cam-field="selected_text"
							/>
                            <tr class="cam-image-fields" style="display:none;">
	                            <th scope="row">
		                            <label for="cam_image_url___INDEX__" data-cam-field-label="image_url">画像URL</label>
	                            </th>
	                            <td>
		                            <input
		                            	type="url"
		                            	id="cam_image_url___INDEX__"
		                            	name="cam_embedded_items[__INDEX__][image_url]"
		                            	value=""
		                            	class="regular-text code cam-embedded-image-url"
		                            	data-cam-field="image_url"
		                            />
	                            </td>
                            </tr>
                            <tr class="cam-image-fields" style="display:none;">
	                            <th scope="row">
	                            	<label for="cam_image_alt___INDEX__" data-cam-field-label="image_alt">代替テキスト</label>
	                            </th>
	                            <td>
	                            	<input
		                            	type="text"
		                            	id="cam_image_alt___INDEX__"
		                            	name="cam_embedded_items[__INDEX__][image_alt]"
		                            	value=""
		                            	class="regular-text cam-embedded-image-alt"
		                            	data-cam-field="image_alt"
		                            />
	                            </td>
                            </tr>
                            <tr class="cam-image-fields" style="display:none;">
	                            <th scope="row">
		                            <label for="cam_caption___INDEX__" data-cam-field-label="caption">キャプション</label>
	                            </th>
	                            <td>
	                            	<textarea
		                            	id="cam_caption___INDEX__"
		                            	name="cam_embedded_items[__INDEX__][caption]"
		                            	rows="3"
		                            	class="large-text cam-embedded-caption"
		                            	data-cam-field="caption"
		                            ></textarea>
	                            </td>
                            </tr>
							<button type="button" class="button cam-selector-pick-button">選択</button>
							<button type="button" class="button cam-selector-clear-button">クリア</button>
							<p class="description">「選択」を押したあと、編集画面内の対象テキストブロックをクリックしてください。selector は保存せず、保存時に selected_text から自動生成します。</p>
							<div class="cam-selector-preview" style="margin-top:8px;padding:10px;border:1px solid #dcdcde;background:#fff;white-space:pre-wrap;">未選択</div>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="cam_rights_holder___INDEX__" data-cam-field-label="rights_holder">権利者</label>
						</th>
						<td>
							<input
								type="text"
								id="cam_rights_holder___INDEX__"
								name="cam_embedded_items[__INDEX__][rights_holder]"
								value=""
								class="regular-text"
								data-cam-field="rights_holder"
							/>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="cam_source_url___INDEX__" data-cam-field-label="source_url">出典URL</label>
						</th>
						<td>
							<input
								type="url"
								id="cam_source_url___INDEX__"
								name="cam_embedded_items[__INDEX__][source_url]"
								value=""
								class="regular-text code"
								data-cam-field="source_url"
							/>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="cam_description___INDEX__" data-cam-field-label="description">備考</label>
						</th>
						<td>
							<textarea
								id="cam_description___INDEX__"
								name="cam_embedded_items[__INDEX__][description]"
								rows="4"
								class="large-text"
								data-cam-field="description"
							></textarea>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</script>
	<?php
}

/**
 * 保存処理
 *
 * @param int $post_id Post ID.
 */
function camui_save_ca_meta_box( $post_id ) {
	if ( ! isset( $_POST['camui_ca_meta_box_nonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['camui_ca_meta_box_nonce'] ) ), 'camui_save_ca_meta_box' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( isset( $_POST['post_type'] ) && 'page' === $_POST['post_type'] ) {
		if ( ! current_user_can( 'edit_page', $post_id ) ) {
			return;
		}
	} else {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
	}

	$fields = array(
		'_cam_article_ca_status' => array( 'post_key' => 'cam_article_ca_status', 'type' => 'text' ),
		'_cam_editor_name'       => array( 'post_key' => 'cam_editor_name', 'type' => 'text' ),
		'_cam_author_name'       => array( 'post_key' => 'cam_author_name', 'type' => 'text' ),
		'_cam_ad_ca_status'      => array( 'post_key' => 'cam_ad_ca_status', 'type' => 'text' ),
		'_cam_advertiser_name'   => array( 'post_key' => 'cam_advertiser_name', 'type' => 'text' ),
		'_cam_campaign_name'     => array( 'post_key' => 'cam_campaign_name', 'type' => 'text' ),
		'_cam_creative_id'       => array( 'post_key' => 'cam_creative_id', 'type' => 'text' ),
	);

	foreach ( $fields as $meta_key => $field ) {
		$post_key = $field['post_key'];

		if ( ! isset( $_POST[ $post_key ] ) ) {
			continue;
		}

		$raw_value = wp_unslash( $_POST[ $post_key ] );

		switch ( $field['type'] ) {
			case 'text':
			default:
				$value = sanitize_text_field( $raw_value );
				break;
		}

		update_post_meta( $post_id, $meta_key, $value );
	}

    /*
	 * 広告CA
	 * - cam_ad_items[0][...] を新UIから保存する
	 */
	if ( isset( $_POST['cam_ad_items'] ) && is_array( $_POST['cam_ad_items'] ) && ! empty( $_POST['cam_ad_items'][0] ) ) {
		$raw_ad_items = wp_unslash( $_POST['cam_ad_items'] );
		$item         = $raw_ad_items[0];

		$enabled       = ! empty( $item['enabled'] ) ? '1' : '';
		$status        = isset( $item['status'] ) ? sanitize_text_field( $item['status'] ) : 'unset';
		$advertiser    = isset( $item['advertiser'] ) ? sanitize_text_field( $item['advertiser'] ) : '';
		$campaign_name = isset( $item['campaign_name'] ) ? sanitize_text_field( $item['campaign_name'] ) : '';
		$creative_id   = isset( $item['creative_id'] ) ? sanitize_text_field( $item['creative_id'] ) : '';
		$headline      = isset( $item['headline'] ) ? sanitize_text_field( $item['headline'] ) : '';
		$rights_holder = isset( $item['rights_holder'] ) ? sanitize_text_field( $item['rights_holder'] ) : '';
		$destination   = isset( $item['destination'] ) ? esc_url_raw( $item['destination'] ) : '';
		$image         = isset( $item['image'] ) ? esc_url_raw( $item['image'] ) : '';
		$description   = isset( $item['description'] ) ? sanitize_textarea_field( $item['description'] ) : '';
		$placement     = isset( $item['placement'] ) ? sanitize_text_field( $item['placement'] ) : 'top_under_title';
		$id            = isset( $item['id'] ) ? sanitize_title( $item['id'] ) : '';

		// ad_code は将来 script を扱う前提なので最小限の整形
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
		} else {
			if ( '' === $id ) {
				$id = 'cam-ad-' . $post_id . '-1';
			}

			$selector = '#' . $id;

			$clean_item = array(
				'enabled'       => $enabled,
				'status'        => in_array( $status, array( 'unset', 'active', 'inactive' ), true ) ? $status : 'unset',
				'advertiser'    => $advertiser,
				'campaign_name' => $campaign_name,
				'creative_id'   => $creative_id,
				'headline'      => $headline,
				'rights_holder' => $rights_holder,
				'destination'   => $destination,
				'image'         => $image,
				'ad_code'       => $ad_code,
				'id'            => $id,
				'selector'      => $selector,
				'description'   => $description,
				'placement'     => $placement ?: 'top_under_title',
			);

			update_post_meta( $post_id, '_cam_ad_items', array( $clean_item ) );
		}
	}

/*
 * 埋め込みコンテンツ
 * - cam_embedded_items が無い、または有効アイテム 0 件なら既存値を削除する
 * - 有効アイテムが 1 件以上あるときだけ更新する
 */
$parsed_embedded_items = array();

if ( isset( $_POST['cam_embedded_items'] ) && is_array( $_POST['cam_embedded_items'] ) ) {
	foreach ( wp_unslash( $_POST['cam_embedded_items'] ) as $item ) {
		$kind = isset( $item['kind'] ) && in_array( $item['kind'], array( 'article', 'image' ), true )
			? sanitize_text_field( $item['kind'] )
			: 'article';

		$selected_text = isset( $item['selected_text'] ) ? sanitize_textarea_field( $item['selected_text'] ) : '';
		$selector      = '';

		if ( 'article' === $kind ) {
			// article は selector を保存しない。
			// 発行時に必ず selected_text から再解決する。
			$selector = '';
		} else {
			$selector = isset( $item['selector'] ) ? sanitize_text_field( $item['selector'] ) : '';
		}

		$image_url = isset( $item['image_url'] ) ? esc_url_raw( $item['image_url'] ) : '';
		$image_alt = isset( $item['image_alt'] ) ? sanitize_text_field( $item['image_alt'] ) : '';
		$caption   = isset( $item['caption'] ) ? sanitize_textarea_field( $item['caption'] ) : '';

		$rights_holder = isset( $item['rights_holder'] ) ? sanitize_text_field( $item['rights_holder'] ) : '';
		$source_url    = isset( $item['source_url'] ) ? esc_url_raw( $item['source_url'] ) : '';
		$description   = isset( $item['description'] ) ? sanitize_textarea_field( $item['description'] ) : '';

		if ( '' === $selector && '' === $selected_text && '' === $image_url && '' === $rights_holder && '' === $source_url && '' === $description ) {
			continue;
		}

		$parsed_embedded_items[] = array(
			'kind'          => $kind,
			'selector'      => $selector,
			'selected_text' => $selected_text,
			'image_url'     => $image_url,
			'image_alt'     => $image_alt,
			'caption'       => $caption,
			'rights_holder' => $rights_holder,
			'source_url'    => $source_url,
			'description'   => $description,
		);
	}
}

if ( empty( $parsed_embedded_items ) ) {
	delete_post_meta( $post_id, '_cam_embedded_items' );
	delete_post_meta( $post_id, '_cam_embedded_selector' );
	delete_post_meta( $post_id, '_cam_rights_holder' );
	delete_post_meta( $post_id, '_cam_source_url' );
	delete_post_meta( $post_id, '_cam_license_note' );
} else {
	update_post_meta( $post_id, '_cam_embedded_items', $parsed_embedded_items );

	// 旧単一データも先頭アイテムで同期
	update_post_meta( $post_id, '_cam_embedded_selector', $parsed_embedded_items[0]['selector'] );
	update_post_meta( $post_id, '_cam_rights_holder', $parsed_embedded_items[0]['rights_holder'] );
	update_post_meta( $post_id, '_cam_source_url', $parsed_embedded_items[0]['source_url'] );
	update_post_meta( $post_id, '_cam_license_note', $parsed_embedded_items[0]['description'] );
}
}
add_action( 'save_post', 'camui_save_ca_meta_box' );

/**
 * 編集画面フッターに selector 選択用スクリプトを出力
 */
function camui_render_selector_picker_script() {
	$screen = get_current_screen();

	if ( ! $screen || ! in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
		return;
	}
	?>
	<script>
	(function () {
		const wrap = document.getElementById('camui-tabs');
		if (!wrap) {
			return;
		}

		const tabButtons = wrap.querySelectorAll('.camui-tab-button');
		const tabPanels = wrap.querySelectorAll('.camui-tab-panel');
		const embeddedItemsWrap = document.getElementById('camui-embedded-items');
		const addEmbeddedItemButton = document.getElementById('camui-add-embedded-item');
		const embeddedTemplate = document.getElementById('camui-embedded-item-template');

		let isPicking = false;
		let activePickerItem = null;

		function activateTab(tabName) {
			tabButtons.forEach((button) => {
				const isActive = button.getAttribute('data-camui-tab') === tabName;
				button.classList.toggle('button-primary', isActive);
				button.classList.toggle('is-active', isActive);
				if (!isActive) {
					button.classList.remove('button-primary');
				}
			});

			tabPanels.forEach((panel) => {
				const isActive = panel.getAttribute('data-camui-panel') === tabName;
				panel.hidden = !isActive;
				panel.classList.toggle('is-active', isActive);
			});
		}

		tabButtons.forEach((button) => {
			button.addEventListener('click', function () {
				activateTab(button.getAttribute('data-camui-tab'));
			});
		});

		function setPreview(item, text) {
			const preview = item.querySelector('.cam-selector-preview');
			if (preview) {
				preview.textContent = text;
			}
		}

		function getSelectorInput(item) {
			return item.querySelector('.cam-embedded-selector-input');
		}

		function getSelectedTextInput(item) {
			return item.querySelector('.cam-embedded-selected-text');
		}

		function normalizeText(text) {
			if (!text) return '';
			return text
				.replace(/\u00A0/g, ' ')
				.replace(/\s+/g, ' ')
				.trim();
		}

        function escapeCssId(value) {
	        if (!value) return '';
	        if (window.CSS && typeof window.CSS.escape === 'function') {
        		return window.CSS.escape(value);
            }
	            return String(value).replace(/[^a-zA-Z0-9\-_]/g, '\\$&');
        }

        function findClosestIdSelector(element) {
        	if (!element) return '';

        	if (element.id) {
	        	return '#' + escapeCssId(element.id);
	        }

	        const withId = element.closest('[id]');
	        if (withId && withId.id) {
	        	return '#' + escapeCssId(withId.id);
	        }

	        return '';
        }

        function buildImageSelector(element) {
	        if (!element) return '';

	        // まず画像自身
	        if (element.tagName && element.tagName.toLowerCase() === 'img' && element.id) {
	        	return '#' + escapeCssId(element.id);
	        }

	        // figure に id があれば優先
	        const figure = element.closest('figure[id]');
	        if (figure && figure.id) {
		        return '#' + escapeCssId(figure.id);
	        }

	        // それ以外は直近の id を持つ祖先
	        return findClosestIdSelector(element);
        }

        function getEmbeddedKind(item) {
	        const select = item.querySelector('.cam-embedded-kind');
	        return select ? select.value : 'article';
        }

        function toggleEmbeddedKindFields(item) {
        	const kind = getEmbeddedKind(item);
        	const imageRows = item.querySelectorAll('.cam-image-fields');
	        const helpText = item.querySelector('.cam-selector-help-text');

        	imageRows.forEach((row) => {
	    	row.style.display = (kind === 'image') ? '' : 'none';
    	    });

    	    if (helpText) {
		     helpText.textContent = (kind === 'image')
		    	? '「選択」を押したあと、編集画面内の対象画像をクリックしてください。'
		    	: '「選択」を押したあと、編集画面内の対象ブロックをクリックしてください。selector は保存時に自動生成されます。';
	        }
        }

		function stopPicking() {
			isPicking = false;
			activePickerItem = null;
			document.body.classList.remove('cam-selector-picking');
		}

		function startPicking(item) {
			isPicking = true;
			activePickerItem = item;
			document.body.classList.add('cam-selector-picking');
			activateTab('embedded');
			setPreview(item, '選択モードです。対象ブロックをクリックしてください。');
		}

		function getSelectableElement(target, kind) {
	        if (!target) return null;

	        if (kind === 'image') {
	        	const directImage = target.closest('img');
		        if (directImage) {
		        	return directImage;
		        }

		        const block = target.closest('.block-editor-block-list__block, [data-block]');
		        if (!block) {
			        return null;
		        }

		        return block.querySelector('img');
	        }

	        const directSelectable = target.closest('p, h1, h2, h3, h4, h5, h6, blockquote, figcaption, pre, ul, ol');
	        if (directSelectable) {
	        	return directSelectable;
	        }

            const block = target.closest('.block-editor-block-list__block, [data-block]');
	        if (!block) {
		        return null;
	        }

	        return block.querySelector('p, h1, h2, h3, h4, h5, h6, blockquote, figcaption, pre, ul, ol');
        }

		function getCandidateText(element) {
			if (!element) return '';
			return element.innerText || element.textContent || '';
		}

		document.addEventListener('click', function (event) {
	        if (!isPicking || !activePickerItem) return;

	        const kind = getEmbeddedKind(activePickerItem);
	        const selectable = getSelectableElement(event.target, kind);
	        if (!selectable) return;

	        event.preventDefault();
	        event.stopPropagation();

	        const textInput = getSelectedTextInput(activePickerItem);
	        const selectorInput = getSelectorInput(activePickerItem);

	   if (kind === 'image') {
	        const imageUrlInput = activePickerItem.querySelector('.cam-embedded-image-url');
	        const imageAltInput = activePickerItem.querySelector('.cam-embedded-image-alt');
	        const captionInput = activePickerItem.querySelector('.cam-embedded-caption');

	        const imageUrl = selectable.getAttribute('src') || '';
	        const imageAlt = selectable.getAttribute('alt') || '';
	        const imageSelector = buildImageSelector(selectable);

	        if (textInput) {
		        textInput.value = '';
	        }
	        if (imageUrlInput) {
	        	imageUrlInput.value = imageUrl;
	        }
	        if (imageAltInput) {
	        	imageAltInput.value = imageAlt;
	        }

	        let caption = '';
	        const figure = selectable.closest('figure');
	        if (figure) {
		        const figcaption = figure.querySelector('figcaption');
		        if (figcaption) {
			        caption = normalizeText(figcaption.innerText || figcaption.textContent || '');
		        }
	        }
	        if (captionInput && !captionInput.value) {
	        	captionInput.value = caption;
	        }

	        if (selectorInput) {
	        	selectorInput.value = imageSelector;
	        }

	        if (!imageSelector) {
	        	setPreview(
		        	activePickerItem,
		        	'画像は見つかりましたが、使える id selector を取得できませんでした。\n\n' +
			        (imageUrl ? 'URL: ' + imageUrl + '\n' : '') +
		        	(imageAlt ? 'alt: ' + imageAlt + '\n' : '') +
		        	'この画像または親要素に id が必要です。'
		        );
		        stopPicking();
		        return;
	        }

	        setPreview(
		        activePickerItem,
		        '画像を保存しました。\n\n' +
		        'selector: ' + imageSelector + '\n' +
	        	(imageUrl ? 'URL: ' + imageUrl + '\n' : '') +
		        (imageAlt ? 'alt: ' + imageAlt + '\n' : '') +
	        	(caption ? 'caption: ' + caption : '')
	        );

	        stopPicking();
	        return;
        }

	    const text = getCandidateText(selectable);
        const normalized = normalizeText(text);

        if (!normalized) {
	        setPreview(activePickerItem, 'この要素からテキストを取得できませんでした。別の段落・見出し・pre・リストを選択してください。');
	        return;
        }

        if (textInput) {
        	textInput.value = normalized;
        }

        // article は selector を保存しない。
        // 保存時に selected_text から #op-body-xxxx を生成させる。
        if (selectorInput) {
        	selectorInput.value = '';
        }

        setPreview(
	        activePickerItem,
	        '選択テキストを保存しました。\n\n' +
	        'selector は保存せず、保存時に selected_text から自動生成します。\n\n' +
	        normalized.substring(0, 300)
        );

        stopPicking();
    }, true);

		function syncEmbeddedItemIndices() {
			if (!embeddedItemsWrap) {
				return;
			}

			const items = embeddedItemsWrap.querySelectorAll('.camui-embedded-item');

			items.forEach((item, index) => {
				item.setAttribute('data-camui-embedded-index', index);

				const header = item.querySelector('.camui-embedded-item-header strong');
				if (header) {
					header.textContent = '埋め込みコンテンツ ' + (index + 1);
				}

				item.querySelectorAll('[data-cam-field]').forEach((field) => {
					const key = field.getAttribute('data-cam-field');
					field.name = 'cam_embedded_items[' + index + '][' + key + ']';
					field.id = 'cam_' + key + '_' + index;
				});

				item.querySelectorAll('label[data-cam-field-label]').forEach((label) => {
					const key = label.getAttribute('data-cam-field-label');
					label.htmlFor = 'cam_' + key + '_' + index;
				});
			});
		}

		function bindEmbeddedItem(item) {
			const pickButton = item.querySelector('.cam-selector-pick-button');
			const clearButton = item.querySelector('.cam-selector-clear-button');
			const removeButton = item.querySelector('.camui-remove-embedded-item');

			if (pickButton) {
				pickButton.addEventListener('click', function () {
					if (isPicking && activePickerItem === item) {
						stopPicking();
						const input = getSelectorInput(item);
						setPreview(item, input && input.value ? '現在のselector: ' + input.value : '未選択');
					} else {
						startPicking(item);
					}
				});
			}

			if (clearButton) {
				clearButton.addEventListener('click', function () {
					const input = getSelectorInput(item);
					const textInput = getSelectedTextInput(item);

					if (input) {
						input.value = '';
					}
					if (textInput) {
						textInput.value = '';
					}

					setPreview(item, '未選択');
					if (activePickerItem === item) {
						stopPicking();
					}
				});
			}

			if (removeButton) {
				removeButton.addEventListener('click', function () {
					if (activePickerItem === item) {
						stopPicking();
					}
					item.remove();
					syncEmbeddedItemIndices();
				});
			}
            const kindSelect = item.querySelector('.cam-embedded-kind');
            if (kindSelect) {
	            kindSelect.addEventListener('change', function () {
	        	toggleEmbeddedKindFields(item);
	            });
	            toggleEmbeddedKindFields(item);
            }
		}

		if (addEmbeddedItemButton && embeddedTemplate && embeddedItemsWrap) {
			addEmbeddedItemButton.addEventListener('click', function () {
				const index = embeddedItemsWrap.querySelectorAll('.camui-embedded-item').length;
				let html = embeddedTemplate.innerHTML;
				html = html.replace(/__INDEX__/g, String(index));
				html = html.replace(/__NUMBER__/g, String(index + 1));

				const temp = document.createElement('div');
				temp.innerHTML = html.trim();
				const item = temp.firstElementChild;

				if (!item) {
					return;
				}

				embeddedItemsWrap.appendChild(item);
				bindEmbeddedItem(item);
				syncEmbeddedItemIndices();
			});
		}

		if (embeddedItemsWrap) {
			embeddedItemsWrap.querySelectorAll('.camui-embedded-item').forEach(bindEmbeddedItem);
			syncEmbeddedItemIndices();
		}

		const style = document.createElement('style');
		style.textContent = `
			#camui-tabs .camui-tab-buttons {
				display: flex;
				gap: 8px;
				margin-bottom: 16px;
			}
			#camui-tabs .camui-tab-panel {
				padding-top: 4px;
			}
			#camui-tabs .camui-embedded-item {
				margin-bottom: 20px;
				padding: 12px;
				border: 1px solid #dcdcde;
				background: #fff;
			}
			#camui-tabs .camui-embedded-item-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				margin-bottom: 8px;
			}
			body.cam-selector-picking p:hover,
			body.cam-selector-picking h1:hover,
			body.cam-selector-picking h2:hover,
			body.cam-selector-picking h3:hover,
			body.cam-selector-picking h4:hover,
			body.cam-selector-picking h5:hover,
			body.cam-selector-picking h6:hover,
			body.cam-selector-picking blockquote:hover,
			body.cam-selector-picking figcaption:hover,
			body.cam-selector-picking pre:hover,
			body.cam-selector-picking ul:hover,
			body.cam-selector-picking ol:hover {
				outline: 2px solid #2271b1 !important;
				cursor: crosshair !important;
			}
            body.cam-selector-picking img:hover {
	            outline: 2px solid #2271b1 !important;
	            cursor: crosshair !important;
            }
		`;
		document.head.appendChild(style);
	})();
	</script>
	<?php
}
add_action( 'admin_footer-post.php', 'camui_render_selector_picker_script' );
add_action( 'admin_footer-post-new.php', 'camui_render_selector_picker_script' );