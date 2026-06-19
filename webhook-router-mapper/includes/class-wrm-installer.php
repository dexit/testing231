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

	const string ROUTES_TABLE         = 'wrm_routes';
	const string CAPTURES_TABLE       = 'wrm_captures';
	const string JOBS_TABLE           = 'wrm_jobs';
	const string SCHEDULES_TABLE      = 'wrm_schedules';
	const string MESSAGES_TABLE       = 'wrm_messages';
	const string MESSAGE_EVENTS_TABLE = 'wrm_message_events';
	const string METRICS_TABLE        = 'wrm_metrics';

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
				ip_allowlist  text                 NOT NULL DEFAULT '',
				ip_blocklist  text                 NOT NULL DEFAULT '',
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
				sig_status   varchar(20)          NOT NULL DEFAULT 'skipped',
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

		// Schedules table — timer-driven and URL-triggered mapping runs.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wrm_schedules (
				id            bigint(20) unsigned  NOT NULL AUTO_INCREMENT,
				label         varchar(255)         NOT NULL DEFAULT '',
				mapping_id    bigint(20) unsigned  NOT NULL DEFAULT 0,
				interval_key  varchar(40)          NOT NULL DEFAULT 'manual',
				seed_payload  longtext             DEFAULT NULL,
				source_url    varchar(500)         NOT NULL DEFAULT '',
				source_method varchar(10)          NOT NULL DEFAULT 'GET',
				trigger_token varchar(64)          NOT NULL DEFAULT '',
				run_mode      enum('sync','async') NOT NULL DEFAULT 'async',
				status        enum('active','paused') NOT NULL DEFAULT 'active',
				last_run      datetime             DEFAULT NULL,
				next_run      datetime             DEFAULT NULL,
				last_result   text                 DEFAULT NULL,
				created_at    datetime             NOT NULL,
				PRIMARY KEY (id),
				KEY status (status),
				KEY next_run (next_run),
				KEY trigger_token (trigger_token)
			) {$charset};"
		);

		// Messages table — one row per outbound email/SMS/WhatsApp message,
		// keyed by a tracking token for open/click/deliverability correlation.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wrm_messages (
				id                  bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token               varchar(64)         NOT NULL DEFAULT '',
				channel             varchar(20)         NOT NULL DEFAULT 'email',
				provider            varchar(40)         NOT NULL DEFAULT '',
				recipient           varchar(255)        NOT NULL DEFAULT '',
				subject             varchar(255)        NOT NULL DEFAULT '',
				mapping_id          bigint(20) unsigned NOT NULL DEFAULT 0,
				capture_id          bigint(20) unsigned NOT NULL DEFAULT 0,
				provider_message_id varchar(191)        NOT NULL DEFAULT '',
				status              varchar(20)         NOT NULL DEFAULT 'queued',
				open_count          int unsigned        NOT NULL DEFAULT 0,
				click_count         int unsigned        NOT NULL DEFAULT 0,
				sent_at             datetime            DEFAULT NULL,
				first_open_at       datetime            DEFAULT NULL,
				created_at          datetime            NOT NULL,
				PRIMARY KEY (id),
				KEY token (token),
				KEY channel (channel),
				KEY status (status),
				KEY provider_message_id (provider_message_id)
			) {$charset};"
		);

		// Message events — append-only deliverability/engagement log per message.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wrm_message_events (
				id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				message_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event      varchar(30)         NOT NULL DEFAULT '',
				url        varchar(500)        NOT NULL DEFAULT '',
				ip         varchar(45)         NOT NULL DEFAULT '',
				user_agent varchar(255)        NOT NULL DEFAULT '',
				data       longtext            DEFAULT NULL,
				created_at datetime            NOT NULL,
				PRIMARY KEY (id),
				KEY message_id (message_id),
				KEY event (event)
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

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}wrm_metrics (
				id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				route_slug varchar(200)        NOT NULL DEFAULT '',
				bucket     datetime            NOT NULL,
				captures   int unsigned        NOT NULL DEFAULT 0,
				jobs_ok    int unsigned        NOT NULL DEFAULT 0,
				jobs_failed int unsigned       NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				UNIQUE KEY slug_bucket (route_slug(100), bucket)
			) {$charset};"
		);

		update_option( 'wrm_db_version', WRM_DB_VERSION );
		self::register_cpt_and_tax();
		flush_rewrite_rules();
	}

	/**
	 * Run install() on version bump so existing sites pick up new tables/columns
	 * without needing a manual deactivate/reactivate. Cheap dbDelta is idempotent.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'wrm_db_version' ) !== WRM_DB_VERSION ) {
			self::install();
		}
	}

	public static function register_cpt_and_tax(): void {
		// WP 6.9+/7.0+ — disable Gutenberg for wrm_mapping so the jQuery
		// mapping builder meta box remains the primary editing interface.
		add_filter(
			'use_block_editor_for_post_type',
			static function ( bool $use, string $post_type ): bool {
				return 'wrm_mapping' === $post_type ? false : $use;
			},
			10,
			2
		);

		// WP 6.9+ REST controller — register the CPT's REST base so the admin
		// API can link to the WP native endpoint and future tooling can discover it.
		add_filter(
			'register_post_type_args',
			static function ( array $args, string $post_type ): array {
				if ( 'wrm_mapping' !== $post_type ) {
					return $args;
				}
				// Ensure the REST namespace is set even if show_in_rest is later
				// enabled by a third-party plugin or site option.
				$args['rest_base']      = 'wrm-mappings';
				$args['rest_namespace'] = 'wrm/v1';
				return $args;
			},
			10,
			2
		);

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
