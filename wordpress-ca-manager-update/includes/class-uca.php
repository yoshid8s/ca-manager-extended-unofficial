<?php
/** 未署名 Content Attestation */

namespace Profile\Uca;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/debug.php';

use function Profile\Debug\debug;

/**
 * 未署名 Content Attestation
 *
 * @link https://docs.originator-profile.org/en/opb/ca-model/article/
 */
final class Uca {
	/**
	 * 未署名 Content Attestation
	 *
	 * @param string  $issuer CA 発行者
	 * @param string  $url 投稿のパーマリンクURL
	 * @param string  $locale ロケール
	 * @param string  $html HTML
	 * @param string  $target_type 検証対象の種別
	 * @param string  $target_css_selector 検証する対象の要素 CSS セレクター
	 * @param array   $external_resources 外部リソース
	 * @param string  $headline タイトル
	 * @param string  $description 説明
	 * @param ?string $image (optional) 画像URL
	 * @param ?string $author (optional) 著者
	 * @param ?string $date_published (optional) 公開日時
	 * @param ?string $date_modified (optional) 最終更新日時
	 */
	public function __construct(
		public string $issuer,
		public string $url,
		public string $locale,
		public string $html,
		public string $target_type,
		public string $target_css_selector,
		public array $external_resources,
		public string $headline,
		public string $description,
		public ?string $image = null,
		public ?string $author = null,
		public ?string $date_published = null,
		public ?string $date_modified = null,
		public string $subject_type = 'Article',
		public ?string $target_integrity = null,
	) {}


	/**
	 * JSON への変換
	 *
	 * @return mixed JSON
	 */
	public function to_json(): string|false {
	$text_targets = array();

	if ( 'TextTargetIntegrity' === $this->target_type ) {
		libxml_use_internal_errors( true );

		$doc = new \DOMDocument();
		$loaded = $doc->loadHTML(
			'<?xml encoding="utf-8" ?>' . $this->html,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		if ( $loaded ) {
			$xpath = new \DOMXPath( $doc );
			$nodes = $xpath->query(
				'//p[@id and starts-with(@id, "op-body-")]'
				. ' | //h1[@id and starts-with(@id, "op-body-")]'
				. ' | //h2[@id and starts-with(@id, "op-body-")]'
				. ' | //h3[@id and starts-with(@id, "op-body-")]'
				. ' | //h4[@id and starts-with(@id, "op-body-")]'
				. ' | //h5[@id and starts-with(@id, "op-body-")]'
				. ' | //h6[@id and starts-with(@id, "op-body-")]'
				. ' | //ul[@id and starts-with(@id, "op-body-")]'
				. ' | //ol[@id and starts-with(@id, "op-body-")]'
				. ' | //blockquote[@id and starts-with(@id, "op-body-")]'
				. ' | //figcaption[@id and starts-with(@id, "op-body-")]'
				. ' | //span[@id and starts-with(@id, "op-body-")]'
				. ' | //pre[@id and starts-with(@id, "op-body-")]'
			);

			if ( $nodes ) {
				foreach ( $nodes as $node ) {
					$id = $node->getAttribute( 'id' );

					if ( '' === $id ) {
						continue;
					}

					$paragraph_html = $doc->saveHTML( $node );

					if ( false === $paragraph_html || '' === trim( $paragraph_html ) ) {
						continue;
					}

					$paragraph_html = html_entity_decode( $paragraph_html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

					$text_targets[] = array(
						'type'        => 'TextTargetIntegrity',
						'content'     => $paragraph_html,
						'cssSelector' => '#' . $id,
					);
				}
			}
		}
	}

	if (
		empty( $text_targets ) &&
		'ExternalResourceTargetIntegrity' === $this->target_type &&
		'' !== $this->target_css_selector &&
		null !== $this->target_integrity
	) {
		$text_targets = array(
			array(
				'type'        => $this->target_type,
				'cssSelector' => $this->target_css_selector,
				'integrity'   => $this->target_integrity,
			),
		);
	}	


	// TextTargetIntegrity のときだけ従来方式にフォールバック
	if ( empty( $text_targets ) && 'TextTargetIntegrity' === $this->target_type ) {
		$text_targets = array(
			array(
				'type'        => $this->target_type,
				'content'     => $this->html,
				'cssSelector' => $this->target_css_selector,
			),
		);
	}

	$is_ad = ( 'OnlineAd' === $this->subject_type );

	$uca = array(
		'@context'          => array(
			'https://www.w3.org/ns/credentials/v2',
			'https://originator-profile.org/ns/credentials/v1',
			'https://originator-profile.org/ns/cip/v1',
			array(
				'@language' => $this->locale,
			),
		),
		'type'              => array( 'VerifiableCredential', 'ContentAttestation' ),
		'issuer'            => $this->issuer,
		'credentialSubject' => array(
			'type'          => $this->subject_type,
			'headline'      => $is_ad ? null : $this->headline,
			'name'          => $is_ad ? $this->headline : ( 'Image' === $this->subject_type ? $this->headline : null ),
			'image'         => $this->image ? array( 'id' => $this->image ) : null,
			'description'   => $this->description,
			'author'        => $this->author ? array( $this->author ) : null,
			'datePublished' => $this->date_published,
			'dateModified'  => $this->date_modified,
		),
		'allowedUrl'        => $this->url,
		'target'            => array_merge(
			$text_targets,
			array_map(
				fn( $integrity ) => array(
					'type'      => 'ExternalResourceTargetIntegrity',
					'integrity' => $integrity,
				),
				$this->external_resources,
			)
		),
	);

	$uca['credentialSubject'] = array_filter(
		$uca['credentialSubject'],
		function ( mixed $val ) {
			return ! is_null( $val );
		}
	);

	$json = \wp_json_encode( $uca );
	if ( false === $json ) {
		debug( 'Failed to encode UCA to JSON' );
	}
	return $json;
}
}
