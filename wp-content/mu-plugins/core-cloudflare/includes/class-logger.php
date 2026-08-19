<?php
/**
 * Core Cloudflare - Logger
 *
 * @package Core_Cloudflare
 */

declare( strict_types=1 );

namespace Core_Cloudflare;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Logger
 *
 * Single Responsibility: persist structured log entries to daily JSON
 * daily JSON Lines files under the plugin's logs/ directory, rotate old
 * files, and provide read access for the admin log screen.
 *
 * Contains no Cloudflare or purge business logic.
 */
final class Logger {

	public const TRIGGER_ADMIN = 'admin_ui';
	public const TRIGGER_HOOK  = 'hook';
	public const TRIGGER_CRON  = 'cron';

	/**
	 * @var Config
	 */
	private Config $config;

	/**
	 * Constructor.
	 *
	 * @param Config $config Config service.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
		$this->ensure_log_dir();
	}

	/**
	 * Make sure the log directory exists and is protected from direct
	 * web access (index.php + .htaccess as defense in depth).
	 *
	 * @return void
	 */
	private function ensure_log_dir(): void {
		if ( ! file_exists( CORE_CLOUDFLARE_LOG_DIR ) ) {
			wp_mkdir_p( CORE_CLOUDFLARE_LOG_DIR );
		}

		if ( ! is_dir( CORE_CLOUDFLARE_LOG_DIR ) || ! is_writable( CORE_CLOUDFLARE_LOG_DIR ) ) {
			return;
		}

		$index_file = CORE_CLOUDFLARE_LOG_DIR . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}

		$htaccess_file = CORE_CLOUDFLARE_LOG_DIR . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $htaccess_file, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder Allow,Deny\nDeny from all\n</IfModule>\n" );
		}
	}

	/**
	 * Path to today's log file.
	 *
	 * @param string|null $date Optional Y-m-d date; defaults to today (UTC).
	 * @return string
	 */
	private function file_path_for( ?string $date = null ): string {
		$date = $date ?? gmdate( 'Y-m-d' );
		$date = preg_replace( '/[^0-9\-]/', '', $date ) ?? gmdate( 'Y-m-d' );

		return CORE_CLOUDFLARE_LOG_DIR . 'core-cloudflare-' . $date . '.log';
	}

	/**
	 * Write a structured log entry.
	 *
	 * @param array{
	 *     domain?: string,
	 *     zone_id?: string,
	 *     http_status?: int,
	 *     request?: mixed,
	 *     response?: mixed,
	 *     execution_time?: float,
	 *     success?: bool,
	 *     error_message?: string,
	 *     note?: string,
		 *     action?: string
	 * } $entry Log entry fields.
	 * @return void
	 */
	public function log( array $entry ): void {
		$record = wp_parse_args(
			$entry,
			[
				'timestamp'      => core_cloudflare_now(),
				'environment'    => $this->config->environment(),
				'domain'         => '',
				'zone_id'        => '',
				'http_status'    => 0,
				'request'        => [],
				'response'       => [],
				'execution_time' => 0.0,
				'success'        => false,
				'error_message'  => '',
				'note'           => '',
				'action'         => '',
			]
		);

		/**
		 * Filter a log entry immediately before it is written to disk.
		 *
		 * @param array $record Log record.
		 */
		$record = apply_filters( 'core_cloudflare_log_entry', $record );

		$line = wp_json_encode( $record );
		if ( false === $line ) {
			return;
		}

		$file = $this->file_path_for();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX );
	}

	/**
	 * Convenience wrapper for success entries.
	 *
	 * @param array $context Extra fields to merge in (domain, zone_id, etc).
	 * @return void
	 */
	public function success( array $context = [] ): void {
		$this->log( array_merge( $context, [ 'success' => true ] ) );
	}

	/**
	 * Convenience wrapper for failure entries.
	 *
	 * @param string $error_message Human-readable error.
	 * @param array  $context       Extra fields to merge in.
	 * @return void
	 */
	public function failure( string $error_message, array $context = [] ): void {
		$this->log(
			array_merge(
				$context,
				[
					'success'       => false,
					'error_message' => $error_message,
					'note'          => $context['note'] ?? $error_message,
				]
			)
		);
	}

	/**
	 * List available log dates (Y-m-d), newest first.
	 *
	 * @return string[]
	 */
	public function list_log_dates(): array {
		$files = glob( CORE_CLOUDFLARE_LOG_DIR . 'core-cloudflare-*.log' );
		if ( ! is_array( $files ) ) {
			return [];
		}

		$dates = [];
		foreach ( $files as $file ) {
			if ( preg_match( '/core-cloudflare-(\d{4}-\d{2}-\d{2})\.log$/', $file, $matches ) ) {
				$dates[] = $matches[1];
			}
		}

		rsort( $dates );

		return $dates;
	}

	/**
	 * Read and parse entries for a given date, optionally filtered.
	 *
	 * @param string|null $date    Y-m-d, defaults to today.
	 * @param array       $filters Optional filters: domain, status ('success'|'failure').
	 * @return array<int, array<string, mixed>>
	 */
	public function read_entries( ?string $date = null, array $filters = [] ): array {
		$file = $this->file_path_for( $date );

		if ( ! is_readable( $file ) ) {
			return [];
		}

		$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( ! is_array( $lines ) ) {
			return [];
		}

		$entries = [];
		foreach ( $lines as $line ) {
			$decoded = json_decode( $line, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			if ( isset( $decoded['action'] ) && 'zone_refresh' === (string) $decoded['action'] ) {
				continue;
			}

			if ( ! $this->entry_matches_filters( $decoded, $filters ) ) {
				continue;
			}

			$entries[] = $decoded;
		}

		return array_reverse( $entries );
	}

	/**
	 * Check whether a decoded entry matches the requested filters.
	 *
	 * @param array<string, mixed> $entry   Decoded log entry.
	 * @param array<string, mixed> $filters Filters (domain, status, trigger).
	 * @return bool
	 */
	private function entry_matches_filters( array $entry, array $filters ): bool {
		if ( ! empty( $filters['domain'] ) ) {
			$needle = strtolower( (string) $filters['domain'] );
			$hay    = strtolower( (string) ( $entry['domain'] ?? '' ) );
			if ( false === strpos( $hay, $needle ) ) {
				return false;
			}
		}

		if ( ! empty( $filters['status'] ) ) {
			$want_success = 'success' === $filters['status'];
			if ( (bool) ( $entry['success'] ?? false ) !== $want_success ) {
				return false;
			}
		}

		if ( ! empty( $filters['search'] ) ) {
			$needle   = strtolower( (string) $filters['search'] );
			$haystack = strtolower( wp_json_encode( $entry ) ?: '' );
			if ( false === strpos( $haystack, $needle ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Read entries across a date range, most recent first, with pagination.
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @param array  $filters    Optional filters (domain, status, search).
	 * @param int    $page       1-indexed page number.
	 * @param int    $per_page   Items per page.
	 * @return array{items: array<int, array<string,mixed>>, total: int}
	 */
	public function query( string $start_date, string $end_date, array $filters = [], int $page = 1, int $per_page = 50 ): array {
		$dates = array_filter(
			$this->list_log_dates(),
			static fn( string $d ): bool => $d >= $start_date && $d <= $end_date
		);

		$all = [];
		foreach ( $dates as $date ) {
			$all = array_merge( $all, $this->read_entries( $date, $filters ) );
		}

		$total  = count( $all );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$items  = array_slice( $all, $offset, $per_page );

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * Delete log files older than the configured retention window.
	 * Intended to be called from a daily cron event.
	 *
	 * @return int Number of files deleted.
	 */
	public function rotate(): int {
		$retention_days = $this->config->log_retention_days();
		$cutoff         = gmdate( 'Y-m-d', strtotime( "-{$retention_days} days" ) );
		$deleted        = 0;

		foreach ( $this->list_log_dates() as $date ) {
			if ( $date < $cutoff ) {
				$file = $this->file_path_for( $date );
				if ( file_exists( $file ) && @unlink( $file ) ) {
					++$deleted;
				}
			}
		}

		return $deleted;
	}

	/**
	 * Delete all log files immediately (used by the "Clear Logs" admin action).
	 *
	 * @return int Number of files deleted.
	 */
	public function clear_all(): int {
		$deleted = 0;
		foreach ( $this->list_log_dates() as $date ) {
			$file = $this->file_path_for( $date );
			if ( file_exists( $file ) && @unlink( $file ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}
	/** Build a paginated log query from admin request values. */
	public function query_from_request( array $request ): array {
		$per_page   = 50;
		$page       = max( 1, (int) ( $request['paged'] ?? 1 ) );
		$start_date = (string) ( $request['start_date'] ?? gmdate( 'Y-m-d', strtotime( '-7 days' ) ) );
		$end_date   = (string) ( $request['end_date'] ?? gmdate( 'Y-m-d' ) );
		$filters    = array_filter( [
			'domain' => (string) ( $request['domain'] ?? '' ),
			'status' => (string) ( $request['status'] ?? '' ),
			'search' => (string) ( $request['search'] ?? '' ),
		] );
		$result = $this->query( $start_date, $end_date, $filters, $page, $per_page );
		return [
			'items' => $result['items'],
			'total' => $result['total'],
			'per_page' => $per_page,
			'page' => $page,
		];
	}

	/** Return a raw log path for a validated date. */
	public function download_path( string $date ): string {
		return $this->file_path_for( $date );
	}

}
