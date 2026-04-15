<?php
namespace Profile\AdminEmbeddedContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function render_embedded_content_ui( $post ) {
	$data = get_post_meta( $post->ID, '_profile_embedded_content_infos', true );
	$data = is_array( $data ) ? $data : array();

	wp_nonce_field( 'profile_embedded_content_nonce', 'profile_embedded_content_nonce_field' );
	?>

	<div id="embedded-wrapper">
		<?php foreach ( $data as $i => $item ) : ?>
			<?php render_item( $i, $item ); ?>
		<?php endforeach; ?>
	</div>

	<button type="button" class="button" onclick="addEmbedded()">＋追加</button>

	<script>
	function addEmbedded() {
		const wrapper = document.getElementById('embedded-wrapper');
		const index = wrapper.children.length;

		const html = `
		<div class="embedded-item" style="border:1px solid #ccc;padding:10px;margin-bottom:10px;">
			<p>
				<label>CSSセレクタ（必須）</label><br>
				<input type="text" name="embedded[${index}][selector]" style="width:100%;">
			</p>

			<p>
				<label>権利者</label><br>
				<input type="text" name="embedded[${index}][rights_holder]" style="width:100%;">
			</p>

			<p>
				<label>出典URL</label><br>
				<input type="text" name="embedded[${index}][source_url]" style="width:100%;">
			</p>

			<p>
				<label>説明</label><br>
				<textarea name="embedded[${index}][description]" style="width:100%;"></textarea>
			</p>
		</div>
		`;

		wrapper.insertAdjacentHTML('beforeend', html);
	}
	</script>

	<?php
}

function render_item( $i, $item ) {
	?>
	<div class="embedded-item" style="border:1px solid #ccc;padding:10px;margin-bottom:10px;">

		<p>
			<label>CSSセレクタ</label><br>
			<input type="text" name="embedded[<?php echo esc_attr( $i ); ?>][selector]"
				value="<?php echo esc_attr( $item['selector'] ?? '' ); ?>" style="width:100%;">
		</p>

		<p>
			<label>権利者</label><br>
			<input type="text" name="embedded[<?php echo esc_attr( $i ); ?>][rights_holder]"
				value="<?php echo esc_attr( $item['rights_holder'] ?? '' ); ?>" style="width:100%;">
		</p>

		<p>
			<label>出典URL</label><br>
			<input type="text" name="embedded[<?php echo esc_attr( $i ); ?>][source_url]"
				value="<?php echo esc_attr( $item['source_url'] ?? '' ); ?>" style="width:100%;">
		</p>

		<p>
			<label>説明</label><br>
			<textarea name="embedded[<?php echo esc_attr( $i ); ?>][description]" style="width:100%;"><?php echo esc_textarea( $item['description'] ?? '' ); ?></textarea>
		</p>

	</div>
	<?php
}

function save_embedded_content( $post_id ) {
	if ( ! isset( $_POST['profile_embedded_content_nonce_field'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['profile_embedded_content_nonce_field'] ) ), 'profile_embedded_content_nonce' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
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

	$data = isset( $_POST['embedded'] ) ? wp_unslash( $_POST['embedded'] ) : array();

	$clean = array();

	foreach ( $data as $item ) {
		if ( empty( $item['selector'] ) ) {
			continue;
		}

		$clean[] = array(
			'selector'      => sanitize_text_field( $item['selector'] ?? '' ),
			'rights_holder' => sanitize_text_field( $item['rights_holder'] ?? '' ),
			'source_url'    => esc_url_raw( $item['source_url'] ?? '' ),
			'description'   => sanitize_textarea_field( $item['description'] ?? '' ),
		);
	}

	update_post_meta( $post_id, '_profile_embedded_content_infos', $clean );
}

add_action( 'save_post', __NAMESPACE__ . '\\save_embedded_content' );