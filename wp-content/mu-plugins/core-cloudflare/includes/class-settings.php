<?php
/**
 * Core Cloudflare - Settings Service
 *
 * @package Core_Cloudflare
 */

declare( strict_types=1 );

namespace Core_Cloudflare;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 *
 * Single Responsibility: presentation-facing wrapper around Config for
 * the admin Configuration tab — masking the token for display and
 * shaping data for both the read-only overview and the editable form.
 * Persistence itself is handled by Config::save(); this class never
 * touches the database directly.
 */
final class Settings {

	private Config $config;

	/**
	 * Constructor.
	 *
	 * @param Config $config Config service.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Mask an API token for display, revealing only the last 4 characters.
	 *
	 * @param string|null $environment Optional environment override.
	 * @return string
	 */
	public function masked_token( ?string $environment = null ): string {
		$token = $this->config->api_token( $environment );

		if ( '' === $token ) {
			return __( '(not configured)', 'core-cloudflare' );
		}

		$length = strlen( $token );
		$suffix = substr( $token, -4 );

		return str_repeat( '•', max( 0, $length - 4 ) ) . $suffix;
	}

	public function masked_account_id( ?string $environment = null ): string {
		$account_id = $this->config->account_id( $environment );

		if ( '' === $account_id ) {
			return __( '(not configured)', 'core-cloudflare' );
		}

		return str_repeat( '•', max( 0, strlen( $account_id ) - 4 ) ) . substr( $account_id, -4 );
	}

	/**
	 * Data structure for the Configuration tab.
	 *
	 * @return array{
	 *     environment: string,
	 *     masked_token: string,
	 *     staging_domains: string[],
	 *     production_domains: string[],
	 *     warnings: string[],
	 *     is_valid: bool
	 * }
	 */
	public function overview(): array {
		$environment = $this->config->environment();

		return [
			'environment'         => $environment,
			'masked_token'        => $this->masked_token( $environment ),
			'masked_account_id'   => $this->masked_account_id( $environment ),
			'staging_domains'     => (array) $this->config->get( 'domains.staging', [] ),
			'production_domains'  => (array) $this->config->get( 'domains.production', [] ),
			'warnings'            => $this->config->warnings(),
			'is_valid'            => $this->config->is_valid(),
		];
	}

	/**
	 * Values needed to pre-fill the editable Configuration form.
	 * The token is intentionally NOT included in full — only whether
	 * one is already set — so it is never round-tripped into page HTML.
	 *
	 * @return array{
	 *     environment: string,
	 *     capability: string,
	 *     has_token: bool,
	 *     staging_domains: string,
	 *     production_domains: string,
	 *     api_timeout: int,
	 *     log_retention_days: int
	 * }
	 */
	public function form_values(): array {
		$environment = $this->config->environment();

		return [
			'environment'         => $environment,
			'capability'          => $this->config->capability(),
			'has_token'           => '' !== $this->config->api_token( $environment ),
			'has_account_id'      => '' !== $this->config->account_id( $environment ),
			'credentials'         => [
				'staging'    => [
					'has_token'      => '' !== $this->config->api_token( 'staging' ),
					'has_account_id' => '' !== $this->config->account_id( 'staging' ),
					'masked_token'   => $this->masked_token( 'staging' ),
					'masked_account' => $this->masked_account_id( 'staging' ),
				],
				'production' => [
					'has_token'      => '' !== $this->config->api_token( 'production' ),
					'has_account_id' => '' !== $this->config->account_id( 'production' ),
					'masked_token'   => $this->masked_token( 'production' ),
					'masked_account' => $this->masked_account_id( 'production' ),
				],
			],
			'staging_domains'     => implode( "\n", (array) $this->config->get( 'domains.staging', [] ) ),
			'production_domains'  => implode( "\n", (array) $this->config->get( 'domains.production', [] ) ),
			'api_timeout'         => $this->config->api_timeout(),
			'log_retention_days'  => $this->config->log_retention_days(),
		];
	}
}
