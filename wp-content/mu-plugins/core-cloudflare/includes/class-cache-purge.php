<?php

/**
 * Core Cloudflare - Cache Purge Service
 *
 * @package Core_Cloudflare
 */

declare(strict_types=1);

namespace Core_Cloudflare;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class Cache_Purge
 *
 * Single Responsibility: purge Cloudflare cache for one, several, or
 * all configured domains, and return a detailed summary. Never aborts
 * a batch because one domain failed.
 */
final class Cache_Purge
{

	private const OPTION_LAST_PURGE = 'core_cloudflare_last_purge';

	private Config $config;
	private Cloudflare_API $api;
	private Zone_Manager $zones;
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Config        $config Config service.
	 * @param Cloudflare_API $api    API client.
	 * @param Zone_Manager  $zones  Zone manager.
	 * @param Logger        $logger Logger service.
	 */
	public function __construct(Config $config, Cloudflare_API $api, Zone_Manager $zones, Logger $logger)
	{
		$this->config = $config;
		$this->api    = $api;
		$this->zones  = $zones;
		$this->logger = $logger;
	}

	/**
	 * Purge every domain configured for the active environment.
	 *
	 * @param string $trigger One of Logger::TRIGGER_* constants.
	 * @return array Purge summary. See purge_domains() for shape.
	 */
	public function purge_all(string $trigger = Logger::TRIGGER_ADMIN): array
	{
		return $this->purge_domains($this->config->active_domains(), $trigger);
	}

	/**
	 * Purge a single domain.
	 *
	 * @param string $domain  Domain to purge.
	 * @param string $trigger One of Logger::TRIGGER_* constants.
	 * @return array Purge summary. See purge_domains() for shape.
	 */
	public function purge_domain(string $domain, string $trigger = Logger::TRIGGER_ADMIN): array
	{
		return $this->purge_domains([$domain], $trigger);
	}

	/**
	 * Purge multiple specific domains. This is the core method — purge_all()
	 * and purge_domain() are both thin wrappers around it.
	 *
	 * @param string[] $domains Domains to purge.
	 * @param string   $trigger One of Logger::TRIGGER_* constants.
	 * @return array{
	 *     success_count: int,
	 *     failure_count: int,
	 *     execution_time: float,
	 *     successful_domains: string[],
	 *     failed_domains: array<string, string>,
	 *     unknown_domains: string[]
	 * }
	 */
	public function purge_domains(array $domains, string $trigger = Logger::TRIGGER_ADMIN): array
	{
		$batch_start = microtime(true);

		// Preserve invalid inputs so every requested item receives an explicit
		// result instead of silently disappearing before processing starts.
		$normalized_domains = [];
		foreach ($domains as $raw_domain) {
			$raw_domain = is_scalar($raw_domain) ? trim((string) $raw_domain) : '';
			$normalized = core_cloudflare_sanitize_domain($raw_domain);
			$key = '' !== $normalized ? $normalized : ('' !== $raw_domain ? $raw_domain : '(empty domain)');
			if (! isset($normalized_domains[$key])) {
				$normalized_domains[$key] = ['domain' => $normalized, 'label' => $key];
			}
		}
		$domains = array_values($normalized_domains);

		/**
		 * Fires before a purge batch begins.
		 *
		 * @param string[] $domains Domains about to be purged.
		 * @param string   $trigger Trigger source.
		 */
		do_action('core_cloudflare_before_purge', $domains, $trigger);

		$successful_domains = [];
		$failed_domains     = [];
		$unknown_domains    = [];

		// Do not validate credentials or fetch zones during settings save. A purge
		// is the real Cloudflare action, so resolve the currently configured
		// environment's accessible zones at purge time and base the final log
		// status on the actual Cloudflare requests/responses.
		$zone_refresh = $this->zones->refresh($trigger);
		if (empty($zone_refresh['success'])) {
			$message = (string) ($zone_refresh['error'] ?? __('Unable to access Cloudflare zones with the configured credentials.', 'core-cloudflare'));
			$failed_domains['__zone_access__'] = $message;
			$this->logger->failure(
				$message,
				[
					'action' => 'cache_purge',
					'phase' => 'zone_access',
					'http_status' => (int) ($zone_refresh['http_status'] ?? 0),
					'error_code' => (string) ($zone_refresh['error_code'] ?? 'cloudflare_api_error'),
				]
			);
		}

		if (! empty($failed_domains['__zone_access__'])) {
			$execution_time = microtime(true) - $batch_start;
			$summary = [
				'success_count' => 0,
				'failure_count' => count($failed_domains),
				'execution_time' => $execution_time,
				'successful_domains' => [],
				'failed_domains' => $failed_domains,
				'unknown_domains' => [],
				'note' => $failed_domains['__zone_access__'],
			];
			update_site_option(self::OPTION_LAST_PURGE, ['timestamp' => core_cloudflare_now(), 'summary' => $summary]);
			do_action('core_cloudflare_after_purge', $summary, $trigger);
			return $summary;
		}

		foreach ($domains as $domain_item) {
			$domain = (string) ($domain_item['domain'] ?? '');
			$label  = (string) ($domain_item['label'] ?? $domain);

			try {
				if ('' === $domain) {
					$message = sprintf(
						__('Invalid domain "%s". Enter a valid domain name or URL. Purge skipped.', 'core-cloudflare'),
						$label
					);
					$failed_domains[$label] = $message;
					$this->logger->failure($message, ['domain' => $label, 'action' => 'cache_purge', 'error_code' => 'invalid_domain']);
					continue;
				}

				$validation = $this->zones->validate_domain($domain);

				if (empty($validation['valid'])) {
					$failed_domains[$domain] = (string) $validation['message'];
					$zone = is_array($validation['zone'] ?? null) ? $validation['zone'] : [];
					$dns = is_array($validation['dns'] ?? null) ? $validation['dns'] : [];

					$this->logger->failure(
						(string) $validation['message'],
						[
							'action'     => 'cache_purge',
							'domain'     => $domain,
							'zone_id'    => (string) ($zone['zone_id'] ?? ''),
							'zone_name'  => (string) ($zone['zone_name'] ?? ''),
							'validation' => (string) ($validation['code'] ?? 'unknown'),
							'error_code'  => (string) ($validation['code'] ?? 'unknown'),
							'note'       => (string) $validation['message'],
						]
					);

					continue;
				}

				$zone = $validation['zone'];
				$zone_name = (string) ($zone['zone_name'] ?? '');
				$dns_record_id = (string) ($validation['dns_record_id'] ?? '');
				$domain_start = microtime(true);

				$response = $this->api->post(
					"/zones/{$zone['zone_id']}/purge_cache",
					['hosts' => [$domain]],
					false,
					[
						'action'    => 'cache_purge',
						'domain'    => $domain,
						'zone_id'   => (string) $zone['zone_id'],
						'zone_name' => $zone_name,
					]
				);

				// The cache-purge operation is logged below as one combined record
				// containing the DNS lookup and Cloudflare purge request/response.
				// Do not let the API client write a second record for the same purge.

				$domain_execution_time = microtime(true) - $domain_start;

				if ($response->success) {
					$successful_domains[] = $domain;
					$this->logger->success([
						'action' => 'cache_purge',
						'domain' => $domain,
						'zone_id' => (string) ($zone['zone_id'] ?? ''),
						'zone_name' => (string) ($zone['zone_name'] ?? ''),
						'dns_record_id' => $dns_record_id,
						'dns' => $dns,
						'request' => [
							'cache_purge' => [
								'method' => 'POST',
								'endpoint' => '/zones/' . (string) ($zone['zone_id'] ?? '') . '/purge_cache',
								'body' => ['hosts' => [$domain]],
							],
						],
						'response' => [
							'cache_purge' => [
								'success' => $response->success,
								'status' => $response->status,
								'error_code' => $response->error_code,
								'error_message' => $response->error_message,
								'data' => $response->data,
							],
						],
						'http_status' => $response->status,
						'execution_time' => $domain_execution_time,
						'note' => 'Cloudflare cache purge succeeded.',
					]);
				} else {
					$this->logger->failure(
						$response->error_message ?: 'Cloudflare cache purge failed.',
						[
							'action' => 'cache_purge',
							'domain' => $domain,
							'zone_id' => (string) ($zone['zone_id'] ?? ''),
							'zone_name' => (string) ($zone['zone_name'] ?? ''),
							'dns_record_id' => $dns_record_id,
							'dns' => $dns,
							'request' => [
								'cache_purge' => [
									'method' => 'POST',
									'endpoint' => '/zones/' . (string) ($zone['zone_id'] ?? '') . '/purge_cache',
									'body' => ['hosts' => [$domain]],
								],
							],
							'response' => [
								'cache_purge' => [
									'success' => $response->success,
									'status' => $response->status,
									'error_code' => $response->error_code,
									'error_message' => $response->error_message,
									'data' => $response->data,
								],
							],
							'http_status' => $response->status,
							'error_code' => $response->error_code,
							'execution_time' => $domain_execution_time,
							'note' => $response->error_message ?: 'Cloudflare cache purge failed.',
						]
					);
					$base_error = $response->error_message ?: __('Cloudflare did not confirm that the cache was cleared. Purge skipped for this domain.', 'core-cloudflare');
					$environment_label = 'production' === $this->config->environment() ? 'Live' : 'Staging';
					$failed_domains[$domain] = sprintf('%s: %s', $environment_label, $base_error);
				}
			} catch (\Throwable $e) {
				// Never allow one unexpected domain failure to abort the batch.
				$exception_message = trim((string) $e->getMessage());
				$message = __('Unexpected error while processing this domain. Purge skipped for this domain; other domains will continue.', 'core-cloudflare');
				$failed_domains[$label] = (defined('WP_DEBUG') && WP_DEBUG && '' !== $exception_message)
					? $message . ' ' . $exception_message
					: $message;
				$this->logger->failure(
					$message,
					[
						'action' => 'cache_purge',
						'domain' => $label,
						'error_code' => 'unexpected_error',
						'exception' => $exception_message,
						'exception_class' => get_class($e),
					]
				);
				continue;
			}
		}

		$execution_time = microtime(true) - $batch_start;


		$summary = [
			'success_count'      => count($successful_domains),
			'failure_count'      => count($failed_domains) + count($unknown_domains),
			'execution_time'     => $execution_time,
			'successful_domains' => $successful_domains,
			'failed_domains'     => $failed_domains,
			'unknown_domains'    => $unknown_domains,
			'note'               => empty($failed_domains) && empty($unknown_domains)
				? __('Cache cleared successfully for all requested domains.', 'core-cloudflare')
				: sprintf(
					/* translators: 1: failed domain count, 2: successful domain count */
					__('Purge completed with %1$d failed domain(s) and %2$d successful domain(s). Failed domains were skipped without stopping the batch.', 'core-cloudflare'),
					count($failed_domains) + count($unknown_domains),
					count($successful_domains)
				),
		];

		update_site_option(
			self::OPTION_LAST_PURGE,
			[
				'timestamp' => core_cloudflare_now(),
				'summary'   => $summary,
			]
		);

		/**
		 * Fires after a purge batch completes.
		 *
		 * @param array  $summary Purge summary (see purge_domains() docblock).
		 * @param string $trigger Trigger source.
		 */
		do_action('core_cloudflare_after_purge', $summary, $trigger);

		return $summary;
	}



	/**
	 * Details of the most recent purge operation, for the Dashboard tab.
	 *
	 * @return array{timestamp: string, summary: array}|null
	 */
	public function last_purge(): ?array
	{
		$value = get_site_option(self::OPTION_LAST_PURGE, null);

		return is_array($value) ? $value : null;
	}
}
