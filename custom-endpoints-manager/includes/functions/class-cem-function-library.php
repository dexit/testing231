<?php
/**
 * CEM Function Library.
 *
 * Static utility helpers available to all microplugin callbacks.
 *
 * @package    Custom_Endpoints_Manager
 * @subpackage Custom_Endpoints_Manager/includes/functions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static utility helpers injected into the microplugin execution namespace.
 *
 * @since 1.0.0
 */
class CEM_Function_Library {

	/**
	 * Return catalog of all library functions for the REST /functions/library endpoint.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public static function get_catalog(): array {
		return array(
			array(
				'name'        => 'CEM_Function_Library::extract_field',
				'signature'   => 'extract_field( array $data, string $path, $default = null )',
				'description' => 'Extract nested value from array using dot-notation path.',
				'category'    => 'data',
				'example'     => "CEM_Function_Library::extract_field( \$data, 'user.address.city', 'N/A' );",
			),
			array(
				'name'        => 'CEM_Function_Library::flatten_array',
				'signature'   => 'flatten_array( array $data, string $prefix = \'\', string $separator = \'.\' ): array',
				'description' => 'Flatten nested array to single-depth with dot-notation keys.',
				'category'    => 'data',
				'example'     => "CEM_Function_Library::flatten_array( [ 'a' => [ 'b' => 1 ] ] ); // [ 'a.b' => 1 ]",
			),
			array(
				'name'        => 'CEM_Function_Library::map_fields',
				'signature'   => 'map_fields( array $data, array $map ): array',
				'description' => 'Remap array keys using [ old_key => new_key ] map.',
				'category'    => 'data',
				'example'     => "CEM_Function_Library::map_fields( \$row, [ 'first_name' => 'name' ] );",
			),
			array(
				'name'        => 'CEM_Function_Library::parse_bool',
				'signature'   => 'parse_bool( $value ): bool',
				'description' => 'Cast string/int/bool to boolean (handles "true","1","yes","on").',
				'category'    => 'parsing',
				'example'     => "CEM_Function_Library::parse_bool( 'yes' ); // true",
			),
			array(
				'name'        => 'CEM_Function_Library::parse_timestamp',
				'signature'   => 'parse_timestamp( $value ): ?int',
				'description' => 'Parse date string or Unix timestamp to integer. Returns null on failure.',
				'category'    => 'parsing',
				'example'     => "CEM_Function_Library::parse_timestamp( '2024-01-15' ); // 1705276800",
			),
			array(
				'name'        => 'CEM_Function_Library::parse_json_string',
				'signature'   => 'parse_json_string( string $value ): array',
				'description' => 'Decode JSON string to array; returns empty array on failure.',
				'category'    => 'parsing',
				'example'     => "CEM_Function_Library::parse_json_string( '{\"key\":\"val\"}' );",
			),
			array(
				'name'        => 'CEM_Function_Library::split_string',
				'signature'   => 'split_string( string $value, string $delimiter = \',\', bool $trim = true ): array',
				'description' => 'Explode string, filter empty parts, optionally trim each element.',
				'category'    => 'parsing',
				'example'     => "CEM_Function_Library::split_string( 'foo, bar , baz' ); // ['foo','bar','baz']",
			),
			array(
				'name'        => 'CEM_Function_Library::create_post',
				'signature'   => 'create_post( array $args ): int|WP_Error',
				'description' => 'Insert a new WP post and return post ID or WP_Error.',
				'category'    => 'wordpress',
				'example'     => "CEM_Function_Library::create_post( [ 'post_title' => 'New Post', 'post_status' => 'publish', 'post_type' => 'post' ] );",
			),
			array(
				'name'        => 'CEM_Function_Library::find_or_create_term',
				'signature'   => 'find_or_create_term( string $name, string $taxonomy, array $args = [] ): int|WP_Error',
				'description' => 'Return existing term ID or create and return new one.',
				'category'    => 'wordpress',
				'example'     => "CEM_Function_Library::find_or_create_term( 'Technology', 'category' );",
			),
			array(
				'name'        => 'CEM_Function_Library::get_post_by_meta',
				'signature'   => 'get_post_by_meta( string $meta_key, string $meta_value, string $post_type = \'post\' ): ?WP_Post',
				'description' => 'Find first post matching meta key/value. Returns WP_Post or null.',
				'category'    => 'wordpress',
				'example'     => "CEM_Function_Library::get_post_by_meta( 'sku', 'ABC-123', 'product' );",
			),
			array(
				'name'        => 'CEM_Function_Library::validate_email',
				'signature'   => 'validate_email( string $email ): bool',
				'description' => 'Check email format via WP is_email().',
				'category'    => 'validation',
				'example'     => "CEM_Function_Library::validate_email( 'user@example.com' ); // true",
			),
			array(
				'name'        => 'CEM_Function_Library::validate_url',
				'signature'   => 'validate_url( string $url ): bool',
				'description' => 'Check URL format via filter_var FILTER_VALIDATE_URL.',
				'category'    => 'validation',
				'example'     => "CEM_Function_Library::validate_url( 'https://example.com' ); // true",
			),
			array(
				'name'        => 'CEM_Function_Library::is_duplicate',
				'signature'   => 'is_duplicate( string $meta_key, string $meta_value, string $post_type = \'post\', int $exclude_id = 0 ): bool',
				'description' => 'Check if a post already exists with given meta key/value.',
				'category'    => 'validation',
				'example'     => "CEM_Function_Library::is_duplicate( 'external_id', '42', 'product' );",
			),
			array(
				'name'        => 'CEM_Function_Library::sanitize_slug',
				'signature'   => 'sanitize_slug( string $text ): string',
				'description' => 'Convert text to URL-safe slug via sanitize_title().',
				'category'    => 'strings',
				'example'     => "CEM_Function_Library::sanitize_slug( 'Hello World!' ); // 'hello-world'",
			),
			array(
				'name'        => 'CEM_Function_Library::truncate_text',
				'signature'   => 'truncate_text( string $text, int $length = 100, string $suffix = \'...\' ): string',
				'description' => 'Truncate string to max character length, appending suffix.',
				'category'    => 'strings',
				'example'     => 'CEM_Function_Library::truncate_text( $long_string, 50 );',
			),
			array(
				'name'        => 'CEM_Function_Library::strip_html_tags',
				'signature'   => 'strip_html_tags( string $text, array $allowed_tags = [] ): string',
				'description' => 'Remove HTML tags. Pass allowed tags as array of tag names.',
				'category'    => 'strings',
				'example'     => "CEM_Function_Library::strip_html_tags( \$html, [ 'p', 'a', 'strong' ] );",
			),
		);
	}

	/**
	 * Extract a nested value from an array using dot-notation path.
	 *
	 * @since 1.0.0
	 * @param array  $data    Source array.
	 * @param string $path    Dot-separated key path.
	 * @param mixed  $default Fallback when path not found.
	 * @return mixed
	 */
	public static function extract_field( array $data, string $path, $default = null ) {
		$keys   = explode( '.', $path );
		$cursor = $data;
		foreach ( $keys as $key ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $key, $cursor ) ) {
				return $default;
			}
			$cursor = $cursor[ $key ];
		}
		return $cursor;
	}

	/**
	 * Flatten a nested array to single depth with dot-notation keys.
	 *
	 * @since 1.0.0
	 * @param array  $data      Source array.
	 * @param string $prefix    Key prefix for recursion.
	 * @param string $separator Key separator character.
	 * @return array
	 */
	public static function flatten_array( array $data, string $prefix = '', string $separator = '.' ): array {
		$result = array();
		foreach ( $data as $key => $value ) {
			$flat_key = '' !== $prefix ? $prefix . $separator . $key : (string) $key;
			if ( is_array( $value ) && ! empty( $value ) ) {
				$result = array_merge( $result, self::flatten_array( $value, $flat_key, $separator ) );
			} else {
				$result[ $flat_key ] = $value;
			}
		}
		return $result;
	}

	/**
	 * Remap array keys using an old→new key map.
	 *
	 * @since 1.0.0
	 * @param array $data Source array.
	 * @param array $map  [ old_key => new_key ] mapping.
	 * @return array
	 */
	public static function map_fields( array $data, array $map ): array {
		$result = array();
		foreach ( $data as $key => $value ) {
			$new_key            = isset( $map[ $key ] ) ? $map[ $key ] : $key;
			$result[ $new_key ] = $value;
		}
		return $result;
	}

	/**
	 * Cast a mixed value to boolean, treating common truthy strings.
	 *
	 * @since 1.0.0
	 * @param mixed $value Value to cast.
	 * @return bool
	 */
	public static function parse_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (bool) $value;
		}
		$str = strtolower( trim( (string) $value ) );
		return in_array( $str, array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Parse a date string or Unix timestamp to an integer timestamp.
	 *
	 * @since 1.0.0
	 * @param mixed $value Date string or numeric timestamp.
	 * @return int|null
	 */
	public static function parse_timestamp( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		$ts = strtotime( (string) $value );
		return ( false === $ts ) ? null : $ts;
	}

	/**
	 * Decode a JSON string to an array, returning empty array on failure.
	 *
	 * @since 1.0.0
	 * @param string $value JSON string.
	 * @return array
	 */
	public static function parse_json_string( string $value ): array {
		if ( '' === trim( $value ) ) {
			return array();
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Explode a delimited string, trim each part, and filter empty values.
	 *
	 * @since 1.0.0
	 * @param string $value     Input string.
	 * @param string $delimiter Explode delimiter.
	 * @param bool   $trim      Whether to trim each element.
	 * @return array
	 */
	public static function split_string( string $value, string $delimiter = ',', bool $trim = true ): array {
		$parts = explode( $delimiter, $value );
		if ( $trim ) {
			$parts = array_map( 'trim', $parts );
		}
		return array_values( array_filter( $parts, 'strlen' ) );
	}

	/**
	 * Insert a new WP post and return its ID or a WP_Error.
	 *
	 * @since 1.0.0
	 * @param array $args wp_insert_post args (merged with safe defaults).
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function create_post( array $args ) {
		$defaults = array(
			'post_status' => 'publish',
			'post_type'   => 'post',
			'post_author' => get_current_user_id(),
		);
		return wp_insert_post( wp_parse_args( $args, $defaults ), true );
	}

	/**
	 * Return existing term ID for a name/taxonomy pair, or insert and return a new one.
	 *
	 * @since 1.0.0
	 * @param string $name     Term name.
	 * @param string $taxonomy Taxonomy slug.
	 * @param array  $args     Additional wp_insert_term args.
	 * @return int|WP_Error Term ID on success, WP_Error on failure.
	 */
	public static function find_or_create_term( string $name, string $taxonomy, array $args = array() ) {
		$existing = get_term_by( 'name', $name, $taxonomy );
		if ( $existing instanceof WP_Term ) {
			return $existing->term_id;
		}
		$result = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return (int) $result['term_id'];
	}

	/**
	 * Find the first post matching a meta key/value pair.
	 *
	 * @since 1.0.0
	 * @param string $meta_key   Post meta key.
	 * @param string $meta_value Post meta value.
	 * @param string $post_type  Post type to search.
	 * @return WP_Post|null
	 */
	public static function get_post_by_meta( string $meta_key, string $meta_value, string $post_type = 'post' ): ?WP_Post {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'posts_per_page' => 1,
				'meta_key'       => $meta_key,
				'meta_value'     => $meta_value,
				'post_status'    => 'any',
			)
		);
		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * Check email format using WP is_email().
	 *
	 * @since 1.0.0
	 * @param string $email Email address to validate.
	 * @return bool
	 */
	public static function validate_email( string $email ): bool {
		return (bool) is_email( $email );
	}

	/**
	 * Check URL format using FILTER_VALIDATE_URL.
	 *
	 * @since 1.0.0
	 * @param string $url URL to validate.
	 * @return bool
	 */
	public static function validate_url( string $url ): bool {
		return (bool) filter_var( $url, FILTER_VALIDATE_URL );
	}

	/**
	 * Check whether a post already exists with the given meta key/value.
	 *
	 * @since 1.0.0
	 * @param string $meta_key   Post meta key.
	 * @param string $meta_value Post meta value.
	 * @param string $post_type  Post type to search.
	 * @param int    $exclude_id Post ID to exclude from the check.
	 * @return bool
	 */
	public static function is_duplicate( string $meta_key, string $meta_value, string $post_type = 'post', int $exclude_id = 0 ): bool {
		$args = array(
			'post_type'      => $post_type,
			'posts_per_page' => 1,
			'post_status'    => 'any',
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'     => array(
				array(
					'key'   => $meta_key,
					'value' => $meta_value,
				),
			),
		);
		if ( $exclude_id > 0 ) {
			$args['post__not_in'] = array( $exclude_id );
		}
		$posts = get_posts( $args );
		return ! empty( $posts );
	}

	/**
	 * Convert text to a URL-safe slug via sanitize_title().
	 *
	 * @since 1.0.0
	 * @param string $text Input text.
	 * @return string
	 */
	public static function sanitize_slug( string $text ): string {
		return sanitize_title( $text );
	}

	/**
	 * Truncate a string to a maximum character length.
	 *
	 * @since 1.0.0
	 * @param string $text   Input string.
	 * @param int    $length Maximum character length.
	 * @param string $suffix Suffix appended when truncated.
	 * @return string
	 */
	public static function truncate_text( string $text, int $length = 100, string $suffix = '...' ): string {
		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, $length ) ) . $suffix;
	}

	/**
	 * Remove HTML tags, optionally preserving a set of allowed tags.
	 *
	 * @since 1.0.0
	 * @param string $text         Input HTML string.
	 * @param array  $allowed_tags Array of tag names to preserve (e.g. ['p','a']).
	 * @return string
	 */
	public static function strip_html_tags( string $text, array $allowed_tags = array() ): string {
		if ( empty( $allowed_tags ) ) {
			return wp_strip_all_tags( $text );
		}
		$allowed = implode(
			'',
			array_map(
				function ( $tag ) {
					return "<{$tag}>";
				},
				$allowed_tags
			)
		);
		return strip_tags( $text, $allowed );
	}
}
