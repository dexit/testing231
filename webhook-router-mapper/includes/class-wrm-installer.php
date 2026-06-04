<?php
/**
 * DB installer: creates wrm_routes, wrm_captures, wrm_jobs tables.
 * Also registers the wrm_mapping CPT and wrm_provider taxonomy.
 *
 * @package Webhook_Router_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRM_Installer {

	const ROUTES_TABLE   = 'wrm_routes';
	const CAPTURES_TABLE = 'wrm_captures';
	const JOBS_TABLE     = 'wrm_jobs';

	public static function install(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wrm_routes (
				id           bigint(20) unsigned  NOT NULL AUTO_INCREMENT,
				slug         varchar(200)         NOT NULL,
				label        varchar(255)         NOT NULL DEFAULT '',
				methods      varchar(50)          NOT NULL DEFAULT 'POST',
				provider     varchar(50)          NOT NULL DEFAULT 'custom',
				auth_token   varchar(255)         NOT NULL DEFAULT '',
				rate_limit   smallint unsigned    NOT NULL DEFAULT 0,
				rate_window  smallint unsigned    NOT NULL DEFAULT 60,
				run_mode     enum('sync','async') NOT NULL DEFAULT 'async',
				mapping_id   bigint(20) unsigned  NOT NULL DEFAULT 0,
				status       enum('active','paused') NOT NULL DEFAULT 'active',
				created_at   datetime             NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY slug (slug)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wrm_captures (
				id           bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				route_slug   varchar(200)        NOT NULL DEFAULT '',
				method       varchar(10)         NOT NULL DEFAULT 'POST',
				provider     varchar(50)         NOT NULL DEFAULT 'custom',
				payload      longtext            NOT NULL,
				headers      text                DEFAULT NULL,
				source_ip    varchar(45)         DEFAULT NULL,
				mapped       tinyint(1)          NOT NULL DEFAULT 0,
				mapping_log  text                DEFAULT NULL,
				created_at   datetime            NOT NULL,
				PRIMARY KEY (id),
				KEY route_slug (route_slug),
				KEY mapped (mapped),
				KEY created_at (created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wrm_jobs (
				id            bigint(20) unsigned  NOT NULL AUTO_INCREMENT,
				job_key       varchar(64)          NOT NULL DEFAULT '',
				route_slug    varchar(200)         NOT NULL DEFAULT '',
				capture_id    bigint(20) unsigned  NOT NULL DEFAULT 0,
				mapping_id    bigint(20) unsigned  NOT NULL DEFAULT 0,
				status        enum('queued','running','done','failed','dead','paused') NOT NULL DEFAULT 'queued',
				attempt       tinyint unsigned     NOT NULL DEFAULT 0,
				max_attempts  tinyint unsigned     NOT NULL DEFAULT 3,
				result        longtext             DEFAULT NULL,
				error_message text                 DEFAULT NULL,
				duration_ms   int unsigned         DEFAULT NULL,
				queued_at     datetime             NOT NULL,
				started_at    datetime             DEFAULT NULL,
				finished_at   datetime             DEFAULT NULL,
				next_retry_at datetime             DEFAULT NULL,
				PRIMARY KEY (id),
				KEY status (status),
				KEY route_slug (route_slug),
				KEY next_retry_at (next_retry_at)
			) {$charset};"
		);

		// Logs table
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wrm_logs (
				id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				level      enum('debug','info','warning','error','exception') NOT NULL DEFAULT 'info',
				context    varchar(50) NOT NULL DEFAULT '',
				ref_id     bigint(20) unsigned NOT NULL DEFAULT 0,
				message    text NOT NULL,
				data       longtext DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY level (level),
				KEY context_level (context, level),
				KEY created_at (created_at)
			) {$charset};"
		);

		update_option( 'wrm_db_version', WRM_DB_VERSION );
		self::register_cpt_and_tax();
		flush_rewrite_rules();
	}

	public static function register_cpt_and_tax(): void {
		register_taxonomy(
			'wrm_provider',
			'wrm_mapping',
			array(
				'label'        => __( 'Provider', 'wrm' ),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => false,
				'hierarchical' => false,
				'rewrite'      => false,
			)
		);

		register_post_type(
			'wrm_mapping',
			array(
				'label'           => __( 'Mappings', 'wrm' ),
				'labels'          => array(
					'name'          => __( 'Mappings', 'wrm' ),
					'singular_name' => __( 'Mapping', 'wrm' ),
					'add_new_item'  => __( 'Add New Mapping', 'wrm' ),
					'edit_item'     => __( 'Edit Mapping', 'wrm' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'capability_type'   => array( 'wrm_mapping', 'wrm_mappings' ),
				'map_meta_cap'      => true,
				'capabilities'      => array(
					'edit_post'              => 'manage_options',
					'edit_posts'             => 'manage_options',
					'edit_others_posts'      => 'manage_options',
					'publish_posts'          => 'manage_options',
					'read_post'              => 'manage_options',
					'read_private_posts'     => 'manage_options',
					'delete_post'            => 'manage_options',
					'delete_posts'           => 'manage_options',
					'delete_private_posts'   => 'manage_options',
					'delete_published_posts' => 'manage_options',
					'delete_others_posts'    => 'manage_options',
					'edit_private_posts'     => 'manage_options',
					'edit_published_posts'   => 'manage_options',
					'create_posts'           => 'manage_options',
				),
				'supports'        => array( 'title', 'revisions' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'taxonomies'      => array( 'wrm_provider' ),
			)
		);
	}
}
