<?php
/**
 * Core Cloudflare - Global Helper Functions
 *
 * A small set of procedural helpers that don't warrant their own class.
 * Kept intentionally minimal — anything with state or multiple related
 * responsibilities belongs in a dedicated service class instead.
 *
 * @package Core_Cloudflare
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'core_cloudflare_can_manage' ) ) {
	/**
	 * Whether the current user may access Core Cloudflare admin surfaces.
	 *
	 * Reads the required capability from admin-editable settings
	 * (Configuration tab) when available, falling back to the
	 * CORE_CLOUDFLARE_CAPABILITY constant only if Config hasn't booted
	 * yet (e.g. extremely early hooks).
	 *
	 * @return bool
	 */
	function core_cloudflare_can_manage(): bool {
		$capability = CORE_CLOUDFLARE_CAPABILITY;

		if ( class_exists( '\Core_Cloudflare\Config' ) ) {
			$capability = \Core_Cloudflare\Config::instance()->capability();
		}

		/**
		 * Filter the capability required to manage Core Cloudflare.
		 *
		 * @param string $capability
		 */
		$capability = apply_filters( 'core_cloudflare_capability', $capability );

		return current_user_can( $capability );
	}
}

if ( ! function_exists( 'core_cloudflare_sanitize_domain' ) ) {
	/**
	 * Normalize a domain string for safe use as an array key / API param.
	 *
	 * Strips protocol, path, port, and lowercases the host.
	 *
	 * @param string $domain Raw domain input.
	 * @return string Sanitized domain, or empty string if invalid.
	 */
	function core_cloudflare_sanitize_domain( string $domain ): string {
		$domain = trim( $domain );

		if ( '' === $domain ) {
			return '';
		}

		// Reject a common paste mistake such as:
		// "https://example.com/https://example.com/".
		// The API needs a hostname only; silently accepting the second
		// protocol makes the UI look like it saved the wrong value.
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://.*://#i', $domain ) ) {
			return '';
		}

		// Allow bare "example.com" as well as "https://example.com/path".
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $domain ) ) {
			$domain = 'https://' . $domain;
		}

		$host = wp_parse_url( $domain, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}

		$host = strtolower( $host );

		return sanitize_text_field( $host );
	}
}

if ( ! function_exists( 'core_cloudflare_now' ) ) {
	/**
	 * Current time as a MySQL-formatted GMT string, for consistent
	 * timestamps across logs, options, and UI.
	 *
	 * @return string
	 */
	function core_cloudflare_now(): string {
		return current_time( 'mysql', true );
	}
}
