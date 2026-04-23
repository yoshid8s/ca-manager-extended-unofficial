<?php
namespace Profile\Ad;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DB {

	/**
	 * 有効化時に呼ぶ
	 */
	public static function install() {
		self::create_tables();
		\update_option( 'cam_ad_db_version', '1.0.0' );
	}

	/**
	 * 広告申込フロー用テーブル作成
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		$tables_sql = array();

		/*
		 * 1. 広告主テーブル
		 */
		$advertisers_table = $prefix . 'cam_advertisers';

		$tables_sql[] = "CREATE TABLE {$advertisers_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			company_type varchar(20) NOT NULL DEFAULT 'advertiser',
			company_name varchar(255) NOT NULL,
			contact_name varchar(255) DEFAULT NULL,
			email varchar(255) DEFAULT NULL,
			phone varchar(50) DEFAULT NULL,
			postal_code varchar(20) DEFAULT NULL,
			address text DEFAULT NULL,
			website_url text DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY company_type (company_type),
			KEY status (status),
			KEY company_name (company_name(191))
		) {$charset_collate};";

		/*
		 * 2. 広告枠テーブル
		 */
		$slots_table = $prefix . 'cam_ad_slots';

		$tables_sql[] = "CREATE TABLE {$slots_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slot_code varchar(100) NOT NULL,
			slot_name varchar(255) NOT NULL,
			genre varchar(100) DEFAULT NULL,
			position varchar(20) NOT NULL,
			page_type varchar(50) DEFAULT 'post',
			target_post_id bigint(20) unsigned DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			description text DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slot_code (slot_code),
			KEY genre (genre),
			KEY position (position),
			KEY page_type (page_type),
			KEY target_post_id (target_post_id),
			KEY status (status)
		) {$charset_collate};";

		/*
		 * 3. 広告申込ヘッダテーブル
		 */
		$applications_table = $prefix . 'cam_ad_applications';

		$tables_sql[] = "CREATE TABLE {$applications_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			application_code varchar(100) NOT NULL,
			advertiser_id bigint(20) unsigned DEFAULT NULL,
			applicant_type varchar(20) NOT NULL DEFAULT 'advertiser',
			advertiser_name_snapshot varchar(255) NOT NULL,
			contact_name_snapshot varchar(255) DEFAULT NULL,
			email_snapshot varchar(255) DEFAULT NULL,
			phone_snapshot varchar(50) DEFAULT NULL,
			address_snapshot text DEFAULT NULL,
			genre varchar(100) DEFAULT NULL,
			slot_id bigint(20) unsigned DEFAULT NULL,
			start_date date DEFAULT NULL,
			end_date date DEFAULT NULL,
			bid_type varchar(20) NOT NULL DEFAULT 'fixed',
			bid_price decimal(10,2) DEFAULT 0.00,
			status varchar(20) NOT NULL DEFAULT 'pending',
			review_comment text DEFAULT NULL,
			admin_memo text DEFAULT NULL,
			reviewed_by bigint(20) unsigned DEFAULT NULL,
			reviewed_at datetime DEFAULT NULL,
			approved_at datetime DEFAULT NULL,
			delivered_at datetime DEFAULT NULL,
			expired_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY application_code (application_code),
			KEY advertiser_id (advertiser_id),
			KEY applicant_type (applicant_type),
			KEY genre (genre),
			KEY slot_id (slot_id),
			KEY start_date (start_date),
			KEY end_date (end_date),
			KEY status (status)
		) {$charset_collate};";

		/*
		 * 4. 広告申込明細テーブル
		 */
		$items_table = $prefix . 'cam_ad_application_items';

		$tables_sql[] = "CREATE TABLE {$items_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			application_id bigint(20) unsigned NOT NULL,
			slot_position varchar(20) NOT NULL,
			headline varchar(255) DEFAULT NULL,
			body_text text DEFAULT NULL,
			image_url text DEFAULT NULL,
			attachment_id bigint(20) unsigned DEFAULT NULL,
			landing_url text DEFAULT NULL,
			ca_status varchar(20) NOT NULL DEFAULT 'not_issued',
			ca_identifier varchar(255) DEFAULT NULL,
			item_status varchar(20) NOT NULL DEFAULT 'pending',
			display_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY application_id (application_id),
			KEY slot_position (slot_position),
			KEY ca_status (ca_status),
			KEY item_status (item_status)
		) {$charset_collate};";

		/*
		 * 5. 審査履歴テーブル
		 */
		$reviews_table = $prefix . 'cam_ad_reviews';

		$tables_sql[] = "CREATE TABLE {$reviews_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			application_id bigint(20) unsigned NOT NULL,
			action varchar(20) NOT NULL,
			review_comment text DEFAULT NULL,
			reviewed_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY application_id (application_id),
			KEY action (action),
			KEY reviewed_by (reviewed_by)
		) {$charset_collate};";

		/*
		 * 6. 配信ログテーブル
		 */
		$logs_table = $prefix . 'cam_ad_delivery_logs';

		$tables_sql[] = "CREATE TABLE {$logs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			application_id bigint(20) unsigned NOT NULL,
			item_id bigint(20) unsigned DEFAULT NULL,
			slot_id bigint(20) unsigned DEFAULT NULL,
			post_id bigint(20) unsigned DEFAULT NULL,
			page_url text DEFAULT NULL,
			event_type varchar(20) NOT NULL,
			event_time datetime NOT NULL,
			ip_hash varchar(128) DEFAULT NULL,
			user_agent text DEFAULT NULL,
			referer_url text DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY application_id (application_id),
			KEY item_id (item_id),
			KEY slot_id (slot_id),
			KEY post_id (post_id),
			KEY event_type (event_type),
			KEY event_time (event_time)
		) {$charset_collate};";

		/*
		 * 7. 日次集計テーブル
		 */
		$stats_table = $prefix . 'cam_ad_daily_stats';

		$tables_sql[] = "CREATE TABLE {$stats_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stat_date date NOT NULL,
			application_id bigint(20) unsigned NOT NULL,
			item_id bigint(20) unsigned DEFAULT NULL,
			slot_id bigint(20) unsigned DEFAULT NULL,
			impressions int(11) NOT NULL DEFAULT 0,
			clicks int(11) NOT NULL DEFAULT 0,
			ctr decimal(8,4) NOT NULL DEFAULT 0.0000,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_daily_stat (stat_date, application_id, item_id, slot_id),
			KEY application_id (application_id),
			KEY stat_date (stat_date)
		) {$charset_collate};";

		foreach ( $tables_sql as $sql ) {
			\dbDelta( $sql );
		}
	}
}
