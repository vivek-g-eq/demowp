<?php

/**
 * Core Cloudflare - Config Service
 *
 * @package Core_Cloudflare
 */

declare(strict_types=1);

namespace Core_Cloudflare;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class Config
 *
 * Single Responsibility: provide validated, dot-notation read access to
 * plugin configuration, AND persist admin-edited settings.
 *
 * Precedence (highest wins):
 *   1. Values saved via the Configuration tab (stored in the
 *      `core_cloudflare_settings` network site option).
 *   2. wp-config.php constants — optional first-run seeds.
 *   3. Hardcoded safe defaults.
 *
 * No class outside of Config should read the `core_cloudflare_settings` option directly.
 *
 * SECURITY: `api_token` is encrypted at rest (see
 * Encryptor) using key material derived from this install's own
 * AUTH_KEY/SECURE_AUTH_KEY. They are decrypted only in memory for the
 * duration of a request and are never re-encoded into page HTML.
 */
final class Config
{

	private const OPTION_SETTINGS = 'core_cloudflare_settings';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Resolved configuration array (DB settings merged over seed defaults).
	 *
	 * @var array<string, mixed>
	 */
	private array $data = [];

	/**
	 * Validation warnings collected on load (missing token, etc).
	 *
	 * @var string[]
	 */
	private array $warnings = [];

	/**
	 * Private constructor — use instance().
	 */
	private function __construct()
	{
		$this->load();
	}

	/**
	 * Get the singleton config instance.
	 *
	 * @return self
	 */
	public static function instance(): self
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hardcoded safe defaults, lowest precedence.
	 *
	 * @return array<string, mixed>
	 */
	private function hardcoded_defaults(): array
	{
		return [
			'environment'        => 'staging',
			'api_token'          => '',
			'account_id'         => '',
			'capability'         => (is_multisite() ? 'manage_network' : 'manage_options'),
			'credentials'        => [
				'staging'    => [
					'api_token'  => '',
					'account_id' => '',
				],
				'production' => [
					'api_token'  => '',
					'account_id' => '',
				],
			],
			'domains'            => [
				'staging'    => [],
				'production' => [],
			],
			'api_timeout'        => 15,
			'log_retention_days' => 30,
		];
	}

	/**
	 * One-time seed values sourced from optional wp-config.php constants.
	 * Only used the first time the DB option is created —
	 * once an admin saves settings via the UI, this is never consulted
	 * again for those fields.
	 *
	 * @return array<string, mixed>
	 */
	private function seed_defaults(): array
	{
		$seed = [];

		// Optional wp-config.php constants are only first-run seeds.
		if (defined('CORE_CLOUDFLARE_ENVIRONMENT')) {
			$seed['environment'] = CORE_CLOUDFLARE_ENVIRONMENT;
		}
		if (defined('CORE_CLOUDFLARE_API_TOKEN')) {
			$seed['api_token'] = CORE_CLOUDFLARE_API_TOKEN;
		}
		if (defined('CORE_CLOUDFLARE_ACCOUNT_ID')) {
			$seed['account_id'] = CORE_CLOUDFLARE_ACCOUNT_ID;
		}
		if (defined('CORE_CLOUDFLARE_CAPABILITY')) {
			$seed['capability'] = CORE_CLOUDFLARE_CAPABILITY;
		}

		return $seed;
	}

	/**
	 * Load resolved settings: DB option if it exists, otherwise seed
	 * from optional wp-config constants and persist that as the initial option
	 * so the admin UI has something to display and edit immediately.
	 *
	 * @return void
	 */
	private function load(): void
	{
		$defaults = $this->hardcoded_defaults();
		$stored   = get_site_option(self::OPTION_SETTINGS, null);

		if (! is_array($stored)) {
			// First run: seed from wp-config constants, persist
			// an encrypted copy to the DB, but keep the plaintext in memory
			// for this request.
			$stored = array_replace_recursive($defaults, $this->seed_defaults());
			update_site_option(self::OPTION_SETTINGS, $this->encrypt_secrets($stored));
		} else {
			// Existing option: values on disk may be encrypted (or, for
			// installs upgraded from an earlier version, still plaintext —
			// Encryptor::decrypt() passes those through unchanged).
			$stored = $this->decrypt_secrets($stored);
		}

		$merged = array_replace_recursive($defaults, $stored);

		// Backwards compatibility: upgrade older single-token settings into the
		// new environment-scoped credential structure.
		if (! isset($merged['credentials']) || ! is_array($merged['credentials'])) {
			$merged['credentials'] = [
				'staging'    => [
					'api_token'  => '',
					'account_id' => '',
				],
				'production' => [
					'api_token'  => '',
					'account_id' => '',
				],
			];
		}

		$active_environment = isset($merged['environment']) ? sanitize_key((string) $merged['environment']) : 'staging';
		if ('production' === $active_environment || 'staging' === $active_environment) {
			$legacy_token = isset($merged['api_token']) ? (string) $merged['api_token'] : '';
			$legacy_account = isset($merged['account_id']) ? (string) $merged['account_id'] : '';
			$merged['credentials'][$active_environment]['api_token'] = $legacy_token ?: (string) ($merged['credentials'][$active_environment]['api_token'] ?? '');
			$merged['credentials'][$active_environment]['account_id'] = $legacy_account ?: (string) ($merged['credentials'][$active_environment]['account_id'] ?? '');
		}

		$this->data = $this->sanitize_and_validate($merged);
	}

	/**
	 * Return a copy of a settings array with api_token
	 * encrypted, ready for update_site_option(). Never mutates $this->data.
	 *
	 * @param array<string, mixed> $data Plaintext settings array.
	 * @return array<string, mixed>
	 */
	private function encrypt_secrets(array $data): array
	{
		if (isset($data['credentials']) && is_array($data['credentials'])) {
			foreach (['staging', 'production'] as $env) {
				if (isset($data['credentials'][$env]['api_token'])) {
					$data['credentials'][$env]['api_token'] = Encryptor::encrypt((string) $data['credentials'][$env]['api_token']);
				}
			}
		}

		if (isset($data['api_token'])) {
			$data['api_token'] = Encryptor::encrypt((string) $data['api_token']);
		}

		return $data;
	}

	/**
	 * Return a copy of a settings array with api_token decrypted for
	 * in-memory use.
	 *
	 * @param array<string, mixed> $data Settings array as stored in the DB.
	 * @return array<string, mixed>
	 */
	private function decrypt_secrets(array $data): array
	{
		if (isset($data['credentials']) && is_array($data['credentials'])) {
			foreach (['staging', 'production'] as $env) {
				if (isset($data['credentials'][$env]['api_token'])) {
					$data['credentials'][$env]['api_token'] = Encryptor::decrypt((string) $data['credentials'][$env]['api_token']);
				}
			}
		}

		$data['api_token'] = Encryptor::decrypt((string) ($data['api_token'] ?? ''));

		return $data;
	}

	/**
	 * Sanitize a raw settings array, apply the domains filter, and
	 * collect validation warnings. Used both on load and on save.
	 *
	 * @param array<string, mixed> $merged Raw settings array.
	 * @return array<string, mixed> Sanitized settings array.
	 */
	private function sanitize_and_validate(array $merged): array
	{
		$this->warnings = [];

		if (! in_array($merged['environment'], ['staging', 'production'], true)) {
			$this->warnings[] = sprintf(
				/* translators: %s: invalid environment value */
				__('Invalid environment "%s"; falling back to staging.', 'core-cloudflare'),
				(string) $merged['environment']
			);
			$merged['environment'] = 'staging';
		}

		$merged['credentials'] = $merged['credentials'] ?? [];
		foreach (['staging', 'production'] as $env) {
			$credentials = $merged['credentials'][$env] ?? [];
			if (! is_array($credentials)) {
				$credentials = [];
			}

			$merged['credentials'][$env] = [
				'api_token'  => sanitize_text_field((string) ($credentials['api_token'] ?? '')),
				'account_id' => sanitize_text_field((string) ($credentials['account_id'] ?? '')),
			];
		}

		foreach (['staging', 'production'] as $env) {
			$list = $merged['domains'][$env] ?? [];
			if (! is_array($list)) {
				$list = [];
			}

			$clean = [];
			foreach ($list as $domain) {
				$sanitized = core_cloudflare_sanitize_domain((string) $domain);
				if ('' !== $sanitized) {
					$clean[] = $sanitized;
				}
			}

			$merged['domains'][$env] = array_values(array_unique($clean));
		}

		/**
		 * Filter the fully-resolved domain list for the active environment.
		 *
		 * @param string[] $domains     Sanitized domains for the active environment.
		 * @param string   $environment Active environment name.
		 */
		$merged['domains'][$merged['environment']] = apply_filters(
			'core_cloudflare_domains',
			$merged['domains'][$merged['environment']],
			$merged['environment']
		);

		$merged['api_token']          = sanitize_text_field((string) ($merged['api_token'] ?? ''));
		$merged['api_timeout']        = max(5, (int) ($merged['api_timeout'] ?? 15));
		$merged['log_retention_days'] = max(1, (int) ($merged['log_retention_days'] ?? 30));

		$active_token = (string) ($merged['credentials'][$merged['environment']]['api_token'] ?? '');
		$merged['api_token'] = $active_token;
		$merged['account_id'] = (string) ($merged['credentials'][$merged['environment']]['account_id'] ?? '');

		if ('' === $active_token) {
			$this->warnings[] = sprintf(
				/* translators: %s: environment name */
				__('Cloudflare API token is not configured for the "%s" environment.', 'core-cloudflare'),
				$merged['environment']
			);
		}


		// Every configuration warning is explicitly scoped to the active
		// environment so administrators cannot confuse Staging and Live.
		$environment_label = 'production' === $merged['environment'] ? 'Live' : 'Staging';
		$this->warnings = array_values(
			array_unique(
				array_map(
					static function (string $warning) use ($environment_label): string {
						$prefix = $environment_label . ':';
						return 0 === strpos($warning, $prefix) ? $warning : $prefix . ' ' . $warning;
					},
					$this->warnings
				)
			)
		);

		return $merged;
	}

	/**
	 * Persist new settings from the admin Configuration tab.
	 *
	 * Caller (Admin_UI) is responsible for capability + nonce checks
	 * before calling this — Config only sanitizes and validates shape.
	 *
	 * @param array{
	 *     environment?: string,
	 *     api_token?: string,
	 *     capability?: string,
	 *     staging_domains?: string[],
	 *     production_domains?: string[],
	 *     api_timeout?: int,
	 *     log_retention_days?: int
	 * } $input Raw input from the settings form.
	 * @return string[] Validation warnings after save (empty array if fully valid).
	 */
	/**
	 * Validate domain lists against the selected environment.
	 *
	 * The configured Staging/Live lists are the source of truth for
	 * environment classification. Unknown domains are allowed so new
	 * domains can be added, but a domain already assigned to the opposite
	 * environment is rejected.
	 *
	 * @param string[] $staging_domains  Staging domains.
	 * @param string[] $production_domains Live domains.
	 * @return string[] Validation errors.
	 */

	/**
	 * Validate the two environment domain lists.
	 *
	 * Environment classification is explicit:
	 * - A domain in both lists is rejected.
	 * - Staging URLs must use a common non-live environment marker unless
	 *   already configured as a known staging domain.
	 * - Live URLs must not use a staging marker.
	 * - Every hostname must be syntactically valid.
	 * - DNS is checked when available to reject malformed/concatenated domains.
	 *
	 * @param string[] $staging_domains   Staging domains.
	 * @param string[] $production_domains Live domains.
	 * @return string[] Validation errors.
	 */
	public function validate_environment_domains(array $staging_domains, array $production_domains): array
	{
		$errors = [];

		$sets = [
			'staging'    => [],
			'production' => [],
		];

		$staging_markers = [
			'staging',
			'stage',
			'test',
			'testing',
			'dev',
			'development',
			'qa',
			'uat',
			'sandbox',
			'preview',
			'preprod',
		];

		$live_markers = [
			'live',
			'prod',
			'production',
		];

		foreach (
			[
				'staging'    => $staging_domains,
				'production' => $production_domains,
			] as $environment => $domains
		) {
			foreach ($domains as $raw_domain) {
				$raw_domain = trim((string) $raw_domain);

				/*
			 * Reject duplicated URLs such as:
			 * https://example.com/https://example.com
			 */
				if (preg_match('#^[a-z][a-z0-9+.-]*://.*://#i', $raw_domain)) {
					$errors[] = sprintf(
						'Invalid %s domain/URL "%s". Do not paste the URL twice; enter one domain or URL per line.',
						'staging' === $environment ? 'Staging' : 'Live',
						$raw_domain
					);

					continue;
				}

				$domain = core_cloudflare_sanitize_domain($raw_domain);

				if ('' === $domain) {
					$errors[] = sprintf(
						'Invalid %s domain/URL "%s". Enter one valid domain or URL per line.',
						'staging' === $environment ? 'Staging' : 'Live',
						$raw_domain
					);

					continue;
				}

				$domain = strtolower(trim($domain, '.'));

				$host_labels = explode('.', $domain);

				/*
			 * A hostname must contain at least:
			 * example.com
			 */
				if (count($host_labels) < 2) {
					$errors[] = sprintf(
						'Invalid domain "%s". A full hostname is required.',
						$domain
					);

					continue;
				}

				/*
			 * Validate every hostname label.
			 */
				$invalid_label = false;

				foreach ($host_labels as $label) {
					if (
						'' === $label ||
						strlen($label) > 63 ||
						! preg_match(
							'/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i',
							$label
						)
					) {
						$invalid_label = true;
						break;
					}
				}

				if ($invalid_label) {
					$errors[] = sprintf(
						'Invalid %s hostname "%s". Check the domain and enter one URL per line.',
						'staging' === $environment ? 'Staging' : 'Live',
						$domain
					);

					continue;
				}

				/**
				 * Reject repeated/concatenated domain names.
				 *
				 * Examples:
				 * example.deexample.de
				 * coresarms.co.ukcoresarms.co.uk
				 * decoresarms.dedecoresarms.de
				 *
				 * This check does not use a hardcoded TLD/suffix list.
				 */
				$concatenated = false;
				$domain_length = strlen($domain);

				/*
			 * Check for an exact repeated hostname:
			 *
			 * example.deexample.de
			 * abc.comabc.com
			 */
				if (0 === $domain_length % 2) {
					$half = (int) ($domain_length / 2);

					$first_half  = substr($domain, 0, $half);
					$second_half = substr($domain, $half);

					if ($first_half === $second_half) {
						$concatenated = true;
					}
				}

				/*
			 * Check malformed overlapping concatenation such as:
			 *
			 * decoresarms.dedecoresarms.de
			 *
			 * The same hostname ending is repeated inside the hostname.
			 */
				if (! $concatenated && count($host_labels) >= 2) {
					$last_label = end($host_labels);

					foreach ($host_labels as $index => $label) {
						if ($index === array_key_last($host_labels)) {
							continue;
						}

						/*
					 * Example:
					 * decoresarms
					 * dedecoresarms
					 *
					 * The second label contains the first label
					 * with the TLD/prefix repeated.
					 */
						if (
							$label !== '' &&
							$last_label !== '' &&
							strlen($label) > strlen($last_label) &&
							false !== strpos($label, $last_label)
						) {
							$prefix = substr(
								$label,
								0,
								strlen($label) - strlen($last_label)
							);

							if (
								'' !== $prefix &&
								false !== strpos($domain, $prefix . $label)
							) {
								$concatenated = true;
								break;
							}
						}
					}
				}

				if ($concatenated) {
					$errors[] = sprintf(
						'%s: Invalid hostname "%s". Multiple domains appear to be concatenated. Enter exactly one domain or URL per line.',
						'staging' === $environment ? 'Staging' : 'Live',
						$domain
					);

					continue;
				}

				/*
			 * If DNS functions are available, reject a hostname that has
			 * no DNS record.
			 *
			 * No TLD list is required here. This works with:
			 * .de, .com, .co.uk, .com.au, .co.in, .io, .ai, etc.
			 */
				if (function_exists('checkdnsrr')) {
					$has_dns_record =
						checkdnsrr($domain, 'A') ||
						checkdnsrr($domain, 'AAAA') ||
						checkdnsrr($domain, 'CNAME');

					if (! $has_dns_record) {
						$errors[] = sprintf(
							'%s: Invalid hostname "%s". No DNS record was found for this domain.',
							'staging' === $environment ? 'Staging' : 'Live',
							$domain
						);

						continue;
					}
				}

				/*
			 * Reject exact duplicates within the same environment.
			 */
				if (isset($sets[$environment][$domain])) {
					$errors[] = sprintf(
						'Duplicate domain "%s" is listed more than once.',
						$domain
					);

					continue;
				}

				$sets[$environment][$domain] = true;

				$has_staging_marker = false;
				$has_live_marker    = false;

				foreach ($host_labels as $label) {
					$label = strtolower($label);

					foreach ( $staging_markers as $marker ) {
						if ( $label === $marker ) {
							$has_staging_marker = true;
							break;
						}
					}

					foreach ( $live_markers as $marker ) {
						if ( $label === $marker ) {
							$has_live_marker = true;
							break;
						}
					}
				}

				/*
			 * Prevent obvious Live domains from being added to Staging.
			 */
				if (
					'staging' === $environment &&
					$has_live_marker &&
					! $has_staging_marker
				) {
					$errors[] = sprintf(
						'Domain "%s" looks like a Live domain and cannot be added to Staging. Use the Live environment.',
						$domain
					);
				}

				/*
			 * Prevent obvious Staging/Test domains from being added to Live.
			 */
				if (
					'production' === $environment &&
					$has_staging_marker
				) {
					$errors[] = sprintf(
						'Domain "%s" looks like a Staging/test domain and cannot be added to Live. Use the Staging environment.',
						$domain
					);
				}
			}
		}

		/*
	 * A domain cannot be assigned to both environments.
	 */
		foreach (
			array_intersect(
				array_keys($sets['staging']),
				array_keys($sets['production'])
			) as $domain
		) {
			$errors[] = sprintf(
				'Domain "%s" cannot be configured as both Staging and Live. Choose one environment.',
				$domain
			);
		}

		return array_values(array_unique($errors));
	}

	public function save(array $input): array
	{
		$current = $this->data;
		$environment = sanitize_key((string) ($input['environment'] ?? $current['environment']));
		if (! in_array($environment, ['staging', 'production'], true)) {
			$environment = 'staging';
		}

		$updated = [
			'environment'        => $environment,
			'api_token'          => (string) ($input['api_token'] ?? $current['api_token']),
			'account_id'         => (string) ($input['account_id'] ?? $current['account_id']),
			'capability'         => sanitize_key((string) ($input['capability'] ?? $current['capability'])),
			'credentials'        => [
				'staging'    => [
					'api_token'  => (string) ($current['credentials']['staging']['api_token'] ?? ''),
					'account_id' => (string) ($current['credentials']['staging']['account_id'] ?? ''),
				],
				'production' => [
					'api_token'  => (string) ($current['credentials']['production']['api_token'] ?? ''),
					'account_id' => (string) ($current['credentials']['production']['account_id'] ?? ''),
				],
			],
			'domains'            => [
				'staging'    => $input['staging_domains'] ?? $current['domains']['staging'],
				'production' => $input['production_domains'] ?? $current['domains']['production'],
			],
			'api_timeout'        => (int) ($input['api_timeout'] ?? $current['api_timeout']),
			'log_retention_days' => (int) ($input['log_retention_days'] ?? $current['log_retention_days']),
		];

		if ('' !== (string) ($input['api_token'] ?? '')) {
			$updated['credentials'][$environment]['api_token'] = (string) $input['api_token'];
		}
		// A blank Account ID field means "leave the existing protected value"
		// rather than deleting it. The explicit Delete Credentials action is
		// used when an administrator intends to remove it.
		if ('' !== trim((string) ($input['account_id'] ?? ''))) {
			$updated['credentials'][$environment]['account_id'] = trim((string) $input['account_id']);
		}

		// When an administrator switches the selected environment and enters a
		// domain that was previously assigned to the other environment, treat the
		// save as an explicit MOVE rather than an attempt to configure the same
		// domain twice. The selected environment always wins. This makes both
		// directions work consistently: Live -> Staging and Staging -> Live.
		$selected_domains = (array) $updated['domains'][$environment];
		$selected_keys    = [];
		foreach ($selected_domains as $selected_domain) {
			$normalized = core_cloudflare_sanitize_domain((string) $selected_domain);
			if ('' !== $normalized) {
				$selected_keys[strtolower($normalized)] = true;
			}
		}

		$opposite = 'production' === $environment ? 'staging' : 'production';
		$updated['domains'][$opposite] = array_values(array_filter(
			(array) $updated['domains'][$opposite],
			static function ($opposite_domain) use ($selected_keys): bool {
				$normalized = core_cloudflare_sanitize_domain((string) $opposite_domain);
				return '' === $normalized || ! isset($selected_keys[strtolower($normalized)]);
			}
		));

		$environment_errors = $this->validate_environment_domains(
			(array) $updated['domains']['staging'],
			(array) $updated['domains']['production']
		);

		if (! empty($environment_errors)) {
			$this->warnings = array_values(array_unique(array_merge($this->warnings, $environment_errors)));
			return $this->warnings;
		}

		$sanitized = $this->sanitize_and_validate($updated);

		// A successful credential save must immediately clear any stale
		// "API token is not configured" warning for the selected environment.
		// This applies equally to Staging and Live. The warning is only valid
		// when that environment actually has no token after the save.
		$active_token_after_save = trim((string) ($sanitized['credentials'][$environment]['api_token'] ?? ''));
		if ('' !== $active_token_after_save) {
			$environment_label = 'production' === $environment ? 'Live' : 'Staging';
			$missing_token_warning = sprintf(
				__('Cloudflare API token is not configured for the "%s" environment.', 'core-cloudflare'),
				$environment
			);
			$missing_token_warning_label = sprintf(
				__('%s: Cloudflare API token is not configured. Add an active API token before using Cloudflare actions.', 'core-cloudflare'),
				$environment_label
			);

			$this->warnings = array_values(array_filter(
				$this->warnings,
				static function (string $warning) use ($missing_token_warning, $missing_token_warning_label): bool {
					return $warning !== $missing_token_warning && $warning !== $missing_token_warning_label;
				}
			));
		}

		update_site_option(self::OPTION_SETTINGS, $this->encrypt_secrets($sanitized));

		$this->data = $sanitized;

		return $this->warnings;
	}

	/**
	 * Retrieve a config value using dot notation.
	 *
	 * @param string|null $key     Dot-notation key (e.g. 'domains.staging'), or null for everything.
	 * @param mixed       $default Fallback if key not found.
	 * @return mixed
	 */
	public function get(?string $key = null, mixed $default = null): mixed
	{
		if (null === $key) {
			return $this->data;
		}

		$segments = explode('.', $key);
		$value    = $this->data;

		foreach ($segments as $segment) {
			if (is_array($value) && array_key_exists($segment, $value)) {
				$value = $value[$segment];
			} else {
				return $default;
			}
		}

		return $value;
	}

	/**
	 * Active environment ('staging' or 'production').
	 *
	 * @return string
	 */
	public function environment(): string
	{
		return (string) $this->get('environment', 'staging');
	}

	/**
	 * Domains configured for the currently active environment only.
	 *
	 * @return string[]
	 */
	public function active_domains(): array
	{
		return (array) $this->get('domains.' . $this->environment(), []);
	}

	/**
	 * API token for the active or requested environment, never logged or echoed in full elsewhere.
	 *
	 * @param string|null $environment Environment override.
	 * @return string
	 */
	/**
	 * Return the configured environment for a domain.
	 *
	 * @param string $domain Domain or URL.
	 * @return string|null 'staging', 'production', or null when unassigned.
	 */
	public function environment_for_domain(string $domain): ?string
	{
		$domain = core_cloudflare_sanitize_domain($domain);
		if ('' === $domain) {
			return null;
		}

		foreach (['staging', 'production'] as $environment) {
			$domains = (array) $this->get('domains.' . $environment, []);
			if (in_array($domain, $domains, true)) {
				return $environment;
			}
		}

		return null;
	}

	/**
	 * Check whether a domain belongs to the active environment.
	 *
	 * @param string $domain Domain or URL.
	 * @return array{valid: bool, code: string, message: string}
	 */
	public function validate_active_domain(string $domain): array
	{
		$domain      = core_cloudflare_sanitize_domain($domain);
		$environment = $this->environment();

		if ('' === $domain) {
			return [
				'valid'   => false,
				'code'    => 'invalid_domain',
				'message' => __('The domain or URL is invalid.', 'core-cloudflare'),
			];
		}

		$assigned = $this->environment_for_domain($domain);

		if (null === $assigned) {
			return [
				'valid'   => false,
				'code'    => 'domain_not_configured',
				'message' => sprintf(
					/* translators: %s: domain */
					__('Domain %s is not configured for Staging or Live. Add it to the correct environment first.', 'core-cloudflare'),
					$domain
				),
			];
		}

		if ($assigned !== $environment) {
			return [
				'valid'   => false,
				'code'    => 'wrong_environment',
				'message' => sprintf(
					/* translators: %1$s: domain, %2$s: selected environment */
					__('Domain %1$s belongs to %2$s, not the selected %3$s environment.', 'core-cloudflare'),
					$domain,
					'production' === $assigned ? 'Live' : 'Staging',
					'production' === $environment ? 'Live' : 'Staging'
				),
			];
		}

		return [
			'valid'   => true,
			'code'    => 'environment_valid',
			'message' => '',
		];
	}

	public function api_token(?string $environment = null): string
	{
		$env = $environment ? sanitize_key($environment) : $this->environment();
		return (string) $this->get('credentials.' . $env . '.api_token', '');
	}

	/**
	 * Account ID for the active or requested environment.
	 *
	 * @param string|null $environment Environment override.
	 * @return string
	 */
	public function account_id(?string $environment = null): string
	{
		$env = $environment ? sanitize_key($environment) : $this->environment();
		return (string) $this->get('credentials.' . $env . '.account_id', '');
	}

	/**
	 * Delete credentials for a specific environment.
	 *
	 * @param string $environment Environment name.
	 * @return void
	 */
	public function delete_credentials(string $environment): void
	{
		if (! in_array($environment, ['staging', 'production'], true)) {
			return;
		}

		$current = $this->data;
		$current['credentials'][$environment]['api_token'] = '';
		$current['credentials'][$environment]['account_id'] = '';

		$sanitized = $this->sanitize_and_validate($current);
		update_site_option(self::OPTION_SETTINGS, $this->encrypt_secrets($sanitized));
		$this->data = $sanitized;
	}

	/**
	 * Capability required to access Core Cloudflare admin surfaces,
	 * editable from the Configuration tab. Always one of a fixed
	 * whitelist — see sanitize_and_validate().
	 *
	 * @return string
	 */
	public function capability(): string
	{
		return (string) $this->get('capability', is_multisite() ? 'manage_network' : 'manage_options');
	}

	/**
	 * HTTP timeout in seconds, filterable at runtime.
	 *
	 * @return int
	 */
	public function api_timeout(): int
	{
		/**
		 * Filter the Cloudflare API request timeout.
		 *
		 * @param int $timeout Seconds.
		 */
		return (int) apply_filters('core_cloudflare_api_timeout', (int) $this->get('api_timeout', 15));
	}

	/**
	 * Days of log retention before rotation deletes a file.
	 *
	 * @return int
	 */
	public function log_retention_days(): int
	{
		return max(1, (int) $this->get('log_retention_days', 30));
	}

	/**
	 * Whether the config is minimally valid (token only). Domains are optional
	 * and Account ID is intentionally not part of validation — scoped Cloudflare API
	 * tokens can list/purge every zone they have access to without an
	 * account ID being supplied on each request.
	 *
	 * @return bool
	 */
	public function is_valid(): bool
	{
		// Domains are optional configuration. Credentials are required to use
		// Cloudflare actions; the actual zone/DNS/purge access is verified when
		// a purge request is executed.
		return '' !== $this->api_token();
	}

	/**
	 * Human-readable validation warnings, for the Configuration/Dashboard tabs.
	 *
	 * @return string[]
	 */
	public function warnings(): array
	{
		return $this->warnings;
	}
}
