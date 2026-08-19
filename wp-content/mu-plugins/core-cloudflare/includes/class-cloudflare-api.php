<?php

/**
 * Core Cloudflare - Cloudflare API Client
 *
 * @package Core_Cloudflare
 */

declare(strict_types=1);

namespace Core_Cloudflare;

if (! defined('ABSPATH')) {
	exit;
}

final class API_Response
{

	/**
	 * @param bool                 $success        Whether the call succeeded.
	 * @param int                  $status         HTTP status code (0 if request never completed).
	 * @param array<string, mixed> $data           Decoded JSON body, or empty array.
	 * @param string               $error_message  Human-readable error, empty on success.
	 * @param float                $execution_time Seconds elapsed for the request.
	 */
	public function __construct(
		public readonly bool $success,
		public readonly int $status,
		public readonly array $data,
		public readonly string $error_message,
		public readonly float $execution_time,
		public readonly string $error_code = ''
	) {}

	/**
	 * Build a failure response for transport-level errors (no HTTP body).
	 *
	 * @param int    $status         HTTP status, or 0 if the request never completed.
	 * @param string $error_message  Human-readable error message.
	 * @param float  $execution_time Seconds elapsed.
	 * @return self
	 */
	public static function failure(int $status, string $error_message, float $execution_time, string $error_code = ''): self
	{
		return new self(false, $status, [], $error_message, $execution_time, $error_code);
	}

	/**
	 * Convenience accessor for the Cloudflare `result` payload.
	 *
	 * @return mixed
	 */
	public function result(): mixed
	{
		return $this->data['result'] ?? null;
	}
}


/**
 * Class Cloudflare_API
 *
 * Single Responsibility: raw transport to the Cloudflare REST API.
 * Handles authentication headers, GET/POST, timeouts, HTTP error
 * parsing, rate limit detection, and response validation.
 *
 * This class knows NOTHING about zones, domains, or purge semantics —
 * it only knows how to talk to https://api.cloudflare.com/client/v4.
 */
final class Cloudflare_API
{

	private const BASE_URL = 'https://api.cloudflare.com/client/v4';

	/**
	 * @var Config
	 */
	private Config $config;

	/**
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Config $config Config service.
	 * @param Logger $logger Logger service.
	 */
	public function __construct(Config $config, Logger $logger)
	{
		$this->config = $config;
		$this->logger = $logger;
	}

	/**
	 * Perform a GET request.
	 *
	 * @param string $endpoint Endpoint relative to the API base, e.g. '/zones'.
	 * @param array  $query    Query string parameters.
	 * @return API_Response
	 */
	public function get(string $endpoint, array $query = [], bool $log = true, array $log_context = []): API_Response
	{
		$url = self::BASE_URL . $endpoint;
		if (! empty($query)) {
			$url = add_query_arg($query, $url);
		}

		return $this->request('GET', $url, null, $log, $log_context);
	}

	/**
	 * Perform a POST request.
	 *
	 * @param string $endpoint Endpoint relative to the API base.
	 * @param array  $body     Request body, JSON-encoded automatically.
	 * @return API_Response
	 */
	public function post(string $endpoint, array $body = [], bool $log = true, array $log_context = []): API_Response
	{
		$url = self::BASE_URL . $endpoint;

		return $this->request('POST', $url, $body, $log, $log_context);
	}

	/**
	 * Verify a token that was submitted in the current settings request.
	 * This deliberately bypasses the stored Config token so a replacement
	 * token is always checked before it is persisted.
	 *
	 * @param string $token Token submitted by the administrator.
	 * @return API_Response
	 */
	public function verify_token(string $token, ?string $environment = null): API_Response
	{
		$log_context = [];
		if (null !== $environment) {
			$log_context['environment'] = sanitize_key($environment);
		}

		return $this->request('GET', self::BASE_URL . '/user/tokens/verify', null, false, $log_context, $token, $environment);
	}

	/**
	 * Check whether the configured API token is active and can read the
	 * configured Cloudflare account's zones. Cache Purge permission cannot
	 * be tested safely without performing a purge; a 403 from the purge
	 * endpoint is handled explicitly by request().
	 *
	 * @param string $account_id Optional configured Cloudflare Account ID.
	 * @return array{success: bool, checks: array<string, array<string, mixed>>, warnings: string[]}
	 */
	public function check_credentials(string $account_id = '', ?string $environment_override = null): array
	{
		$environment_key = $environment_override ? sanitize_key($environment_override) : $this->config->environment();
		if (! in_array($environment_key, ['staging', 'production'], true)) {
			$environment_key = $this->config->environment();
		}

		$environment = 'production' === $environment_key ? 'Live' : 'Staging';
		$token = trim($this->config->api_token($environment_key));
		$checks = [];
		$warnings = [];

		if ('' === $token) {
			$message = sprintf(
				/* translators: %s: environment label */
				__('%s: Cloudflare API token is not configured. Add an active API token before using Cloudflare actions.', 'core-cloudflare'),
				$environment
			);
			return [
				'success' => false,
				'checks' => ['token' => ['ok' => false, 'message' => $message]],
				'warnings' => [$message],
			];
		}

		/*
		 * Use the same /zones endpoint that the Cloudflare token is expected
		 * to access. This avoids rejecting a valid API token when the token
		 * works for /zones but the optional /user/tokens/verify endpoint is
		 * unavailable to that token/account. The selected environment token is
		 * passed explicitly so Live can never accidentally use Staging.
		 */
		$query = [];
		if ('' !== trim($account_id)) {
			$query['account'] = ['id' => trim($account_id)];
		}

		$zones = $this->request(
			'GET',
			add_query_arg($query, self::BASE_URL . '/zones'),
			null,
			false,
			['environment' => $environment_key, 'credential_check' => 'zones'],
			$token,
			$environment_key
		);

		$zones_ok = $zones->success;
		$zone_count = is_array($zones->result()) ? count($zones->result()) : 0;

		if ($zones_ok && '' !== trim($account_id) && 0 === $zone_count) {
			$zones_ok = false;
			$zones_message = sprintf(
				__('%s: Cloudflare API token is valid, but no zones are accessible for the configured Account ID. Check the Account ID and Zone Read permission.', 'core-cloudflare'),
				$environment
			);
		} elseif ($zones_ok) {
			$zones_message = sprintf(
				__('%s: Cloudflare API token is valid and zone access is available (%d zone(s) visible).', 'core-cloudflare'),
				$environment,
				$zone_count
			);
		} elseif (401 === $zones->status) {
			$zones_message = sprintf(
				__('%s: Cloudflare rejected the API token (HTTP 401). Check that the token is active and that the plugin is using the same token that works in Postman.', 'core-cloudflare'),
				$environment
			);
		} elseif (403 === $zones->status) {
			$zones_message = sprintf(
				__('%s: Cloudflare accepted the API token but denied zone access (HTTP 403). Check Zone Read permission and Account ID.', 'core-cloudflare'),
				$environment
			);
		} else {
			$zones_message = sprintf(
				__('%s: Cloudflare zone access check failed (HTTP %d).', 'core-cloudflare'),
				$environment,
				$zones->status
			);
		}

		$checks['token'] = [
			'ok' => $zones_ok,
			'message' => $zones_message,
			'error_code' => $zones->error_code,
			'http_status' => $zones->status,
		];
		$checks['zone_read'] = [
			'ok' => $zones_ok,
			'message' => $zones_message,
			'error_code' => $zones->error_code,
			'http_status' => $zones->status,
		];

		if (! $zones_ok) {
			$warnings[] = $zones_message;
			return ['success' => false, 'checks' => $checks, 'warnings' => $warnings];
		}

		if ('' === trim($account_id)) {
			$checks['account_access'] = [
				'ok' => null,
				'message' => sprintf(
					__('%s: Cloudflare Account ID is not configured. Zone access was verified using the token scope.', 'core-cloudflare'),
					$environment
				),
			];
		}

		$purge_message = sprintf(
			__('%s: Cache Purge permission is required for purge actions. It will be reported explicitly if Cloudflare returns a permission error.', 'core-cloudflare'),
			$environment
		);
		$checks['cache_purge'] = [
			'ok' => null,
			'message' => $purge_message,
			'permission' => 'Cache Purge',
		];

		return [
			'success' => empty($warnings),
			'checks' => $checks,
			'warnings' => $warnings,
		];
	}

	/**
	 * Perform the underlying HTTP request via the WordPress HTTP API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $url    Full request URL.
	 * @param array|null $body   Request body for POST, or null.
	 * @return API_Response
	 */
	private function request(string $method, string $url, ?array $body, bool $log = true, array $log_context = [], ?string $token_override = null, ?string $environment_override = null): API_Response
	{
		$start_time = microtime(true);

		$token = null !== $token_override
			? trim($token_override)
			: trim($this->config->api_token($environment_override));

		// Never send a duplicated auth scheme. Older saved values or a pasted
		// "Bearer <token>" value can otherwise become "Bearer Bearer <token>",
		// which Cloudflare rejects as error 6003/6111.
		$token = preg_replace('/^Bearer\\s+/i', '', $token) ?? $token;
		$token = trim($token, " 	 \"'");

		if ('' === $token) {
			return API_Response::failure(
				0,
				__('Cloudflare API token is not configured. Add a valid API token in the plugin settings before trying again.', 'core-cloudflare'),
				microtime(true) - $start_time,
				'missing_token'
			);
		}

		$args = [
			'method'  => $method,
			'timeout' => $this->config->api_timeout(),
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
		];

		if (null !== $body) {
			$encoded = wp_json_encode($body);
			$args['body'] = false === $encoded ? '{}' : $encoded;
		}

		$response = 'GET' === $method ? wp_remote_get($url, $args) : wp_remote_post($url, $args);

		$execution_time = microtime(true) - $start_time;

		if (is_wp_error($response)) {
			$message = $response->get_error_message();
			$error_code = (string) $response->get_error_code();
			if ($log) {
				$this->log_api_exchange($method, $url, $body, null, 0, $execution_time, false, $message, $error_code, $log_context);
			}

			$is_timeout = $this->is_timeout_error($message, $error_code);
			$public_message = $is_timeout
				? __('Cloudflare API request timed out. The domain was not changed; you can retry the purge.', 'core-cloudflare')
				: __('Network error while contacting Cloudflare. The domain was not changed; you can retry the purge.', 'core-cloudflare');

			return API_Response::failure(
				0,
				$public_message,
				$execution_time,
				$is_timeout ? 'timeout' : 'network_failure'
			);
		}

		$status  = (int) wp_remote_retrieve_response_code($response);
		$raw     = wp_remote_retrieve_body($response);
		$headers = wp_remote_retrieve_headers($response);
		$headers = is_object($headers) ? $headers->getAll() : (array) $headers;

		if (429 === $status) {
			$message = __('Cloudflare rate limit reached. No changes were made. Please wait a moment and retry.', 'core-cloudflare');
			if ($log) {
				$this->log_api_exchange($method, $url, $body, ['raw_body' => $raw, 'headers' => $headers], $status, $execution_time, false, $message, 'rate_limit', $log_context);
			}
			return API_Response::failure($status, $message, $execution_time, 'rate_limit');
		}

		$decoded = json_decode($raw, true);

		if (! is_array($decoded)) {
			$message = __('Cloudflare returned an invalid API response. No changes were made for this domain.', 'core-cloudflare');
			if ($log) {
				$this->log_api_exchange($method, $url, $body, ['raw_body' => $raw, 'headers' => $headers], $status, $execution_time, false, $message, 'invalid_api_response', $log_context);
			}
			return API_Response::failure($status, $message, $execution_time, 'invalid_api_response');
		}

		$success = (bool) ($decoded['success'] ?? false) && $status >= 200 && $status < 300;

		if (! $success) {
			$error_code = $this->error_code_for_status($status);
			$error_message = $this->extract_error_message($decoded, $status, $error_code, $environment_override);
			if ($log) {
				$this->log_api_exchange($method, $url, $body, $decoded, $status, $execution_time, false, $error_message, $error_code, $log_context);
			}

			return new API_Response(
				false,
				$status,
				$decoded,
				$error_message,
				$execution_time,
				$error_code
			);
		}

		if ($log) {
			$this->log_api_exchange($method, $url, $body, $decoded, $status, $execution_time, true, '', '', $log_context);
		}

		return new API_Response(
			true,
			$status,
			$decoded,
			'',
			$execution_time,
			''
		);
	}

	/**
	 * Persist the real Cloudflare request/response exchange for the Logs tab.
	 * Authentication headers are deliberately never stored.
	 *
	 * @param string $method HTTP method.
	 * @param string $url Full API URL.
	 * @param array|null $body Request body.
	 * @param mixed $response Decoded response or raw response metadata.
	 * @param int $status HTTP status.
	 * @param float $execution_time Seconds elapsed.
	 * @param bool $success Whether Cloudflare reported success.
	 * @param string $error_message Human-readable error.
	 * @param string $error_code Stable application error code.
	 * @return void
	 */
	private function log_api_exchange(string $method, string $url, ?array $body, mixed $response, int $status, float $execution_time, bool $success, string $error_message = '', string $error_code = '', array $log_context = []): void
	{
		$path = (string) (wp_parse_url($url, PHP_URL_PATH) ?: '');
		$query = wp_parse_url($url, PHP_URL_QUERY);
		$request = ['method' => $method, 'endpoint' => $path];
		if (is_string($query) && '' !== $query) {
			$request['query'] = $query;
		}
		if (null !== $body) {
			$request['body'] = $body;
		}

		$this->logger->log(array_merge(
			[
				'action'         => 'api_request',
				'request'        => $request,
				'response'       => $response,
				'http_status'    => $status,
				'execution_time' => $execution_time,
				'success'        => $success,
				'error_message'  => $error_message,
				'error_code'     => $error_code,
				'note'           => $success ? 'Cloudflare API response received.' : ($error_message ?: 'Cloudflare API request failed.'),
			],
			$log_context
		));
	}

	/**
	 * Extract a human-readable error message from a Cloudflare error payload.
	 *
	 * @param array<string, mixed> $decoded Decoded JSON body.
	 * @param int                  $status  HTTP status code.
	 * @return string
	 */
	private function extract_error_message(array $decoded, int $status, string $error_code = '', ?string $environment_override = null): string
	{
		// Authentication and permission errors must never be hidden by a raw
		// Cloudflare error payload because these messages tell the administrator
		// what action is actually required.
		$environment_key = $environment_override ? sanitize_key($environment_override) : $this->config->environment();
		$environment_label = 'production' === $environment_key ? 'Live' : 'Staging';
		if ('invalid_token' === $error_code) {
			return sprintf(__('%s: Invalid or unauthorized Cloudflare API token. Check the token and make sure it is active.', 'core-cloudflare'), $environment_label);
		}

		if ('missing_permissions' === $error_code) {
			return sprintf(__('%s: Cloudflare API token is missing the permissions required to access this zone or purge its cache. Required permissions include Zone Read and Cache Purge.', 'core-cloudflare'), $environment_label);
		}

		if (! empty($decoded['errors']) && is_array($decoded['errors'])) {
			$messages = [];
			$codes    = [];

			foreach ($decoded['errors'] as $error) {
				if (is_array($error)) {
					if (isset($error['code'])) {
						$codes[] = (string) $error['code'];
					}
					if (! empty($error['message'])) {
						$messages[] = (string) $error['message'];
					}
					if (! empty($error['error_chain']) && is_array($error['error_chain'])) {
						foreach ($error['error_chain'] as $chain_error) {
							if (is_array($chain_error)) {
								if (isset($chain_error['code'])) {
									$codes[] = (string) $chain_error['code'];
								}
								if (! empty($chain_error['message'])) {
									$messages[] = (string) $chain_error['message'];
								}
							}
						}
					}
				}
			}

			if (in_array('6003', $codes, true) || in_array('6111', $codes, true)) {
				return sprintf(
					__('%s: Cloudflare rejected the Authorization header. Enter only the API Token value (do not include "Bearer", quotes, or the API key format).', 'core-cloudflare'),
					$environment_label
				);
			}

			if (! empty($messages)) {
				return implode('; ', array_values(array_unique($messages)));
			}
		}

		return match ($error_code) {
			'rate_limit'          => __('Cloudflare rate limit reached. No changes were made. Please wait a moment and retry.', 'core-cloudflare'),
			'not_found'           => __('The requested Cloudflare resource was not found. Verify that the zone still exists and that the token can access it.', 'core-cloudflare'),
			'server_error'        => __('Cloudflare is temporarily unavailable. No changes were made for this domain. Please retry later.', 'core-cloudflare'),
			default               => sprintf(
				/* translators: %d: HTTP status code */
				__('Cloudflare API request failed (HTTP %d). No changes were made for this domain.', 'core-cloudflare'),
				$status
			),
		};
	}

	/**
	 * Map HTTP status codes to stable application error codes.
	 */
	private function error_code_for_status(int $status): string
	{
		return match (true) {
			401 === $status => 'invalid_token',
			403 === $status => 'missing_permissions',
			404 === $status => 'not_found',
			429 === $status => 'rate_limit',
			$status >= 500  => 'server_error',
			default         => 'cloudflare_api_error',
		};
	}

	/**
	 * Detect WordPress transport timeout errors across common HTTP transports.
	 */
	private function is_timeout_error(string $message, string $error_code = ''): bool
	{
		$message = strtolower($message);
		$code    = strtolower($error_code);

		return false !== strpos($message, 'timed out')
			|| false !== strpos($message, 'timeout')
			|| in_array($code, ['http_request_failed', 'curl_error_28'], true) && false !== strpos($message, '28');
	}
}