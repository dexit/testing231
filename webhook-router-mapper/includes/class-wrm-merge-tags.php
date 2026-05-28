<?php
/**
 * Merge tag resolver — replaces {{tag.path}} in template strings.
 *
 * Supported tag namespaces:
 *   {{now}}             — Unix timestamp
 *   {{date}}            — Y-m-d
 *   {{datetime}}        — Y-m-d H:i:s
 *   {{post.id}}         — Post ID from context
 *   {{post.title}}      — Post title
 *   {{meta.key}}        — Post meta saved during mapping
 *   {{payload.dot.path}} — Dot-path into payload (prefix optional)
 *   {{dot.path}}        — Bare dot-path, searches payload directly
 *
 * @package Webhook_Router_Mapper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WRM_Merge_Tags {

	/**
	 * Replace {{tag}} occurrences in $template using $context.
	 *
	 * @param string $template  Template string containing {{tags}}.
	 * @param array  $context {
	 *   @type array        $payload Normalized incoming payload.
	 *   @type WP_Post|null $post    Post created/updated by mapper.
	 *   @type array        $meta    Meta key→value saved by mapper.
	 *   @type int          $now     Unix timestamp (defaults to time()).
	 * }
	 */
	public static function resolve( string $template, array $context ): string {
		return (string) preg_replace_callback(
			'/\{\{([^}]+)\}\}/',
			static function ( array $m ) use ( $context ): string {
				return (string) ( self::lookup( trim( $m[1] ), $context ) ?? '' );
			},
			$template
		);
	}

	private static function lookup( string $tag, array $context ) {
		$now = (int) ( $context['now'] ?? time() );

		switch ( $tag ) {
			case 'now':
				return (string) $now;
			case 'date':
				return gmdate( 'Y-m-d', $now );
			case 'time':
				return gmdate( 'H:i:s', $now );
			case 'datetime':
				return gmdate( 'Y-m-d H:i:s', $now );
			case 'post.id':
				return isset( $context['post'] ) ? (string) $context['post']->ID : '';
			case 'post.title':
				return isset( $context['post'] ) ? $context['post']->post_title : '';
			case 'post.status':
				return isset( $context['post'] ) ? $context['post']->post_status : '';
		}

		if ( str_starts_with( $tag, 'meta.' ) ) {
			$key = substr( $tag, 5 );
			return isset( $context['meta'][ $key ] ) ? (string) $context['meta'][ $key ] : null;
		}

		if ( str_starts_with( $tag, 'payload.' ) ) {
			return self::get_by_path( $context['payload'] ?? array(), substr( $tag, 8 ) );
		}

		// Bare path — try payload directly
		return self::get_by_path( $context['payload'] ?? array(), $tag );
	}

	/**
	 * Extract value from nested array using dot-notation path.
	 */
	public static function get_by_path( array $data, string $path ) {
		$parts = explode( '.', $path );
		$cur   = $data;
		foreach ( $parts as $part ) {
			if ( ! is_array( $cur ) || ! array_key_exists( $part, $cur ) ) {
				return null;
			}
			$cur = $cur[ $part ];
		}
		return $cur;
	}
}
