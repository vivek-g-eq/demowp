<?php

/**
 * Core Cloudflare - Network Admin UI
 *
 * @package Core_Cloudflare
 */

declare(strict_types=1);

namespace Core_Cloudflare;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Class Admin_UI
 *
 * Single Responsibility: render the Network Admin "Cloudflare" menu and
 * its tabs, and handle the AJAX actions those tabs trigger. Delegates
 * all actual work to Zone_Manager, Cache_Purge, Settings, and the Logger.
 */
final class Admin_UI
{

	private const MENU_SLUG = 'core-cloudflare';
	private const NONCE_ACTION = 'core_cloudflare_admin_action';

	private Config $config;
	private Zone_Manager $zones;
	private Cache_Purge $purge;
	private Logger $logger;
	private Cloudflare_API $api;
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Config       $config Config service.
	 * @param Zone_Manager $zones  Zone manager.
	 * @param Cache_Purge  $purge  Cache purge service.
	 * @param Logger          $logger Logger service.
	 * @param Cloudflare_API $api    Cloudflare API client.
	 */
	public function __construct(Config $config, Zone_Manager $zones, Cache_Purge $purge, Logger $logger, Cloudflare_API $api)
	{
		$this->config     = $config;
		$this->zones      = $zones;
		$this->purge      = $purge;
		$this->logger     = $logger;
		$this->api        = $api;
		$this->settings   = new Settings($config);
	}

	/**
	 * Register all WordPress hooks for the admin UI.
	 *
	 * @return void
	 */
	public function register(): void
	{
		add_action(is_multisite() ? 'network_admin_menu' : 'admin_menu', [$this, 'register_menu']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

		add_action('wp_ajax_core_cloudflare_purge', [$this, 'ajax_purge']);
		add_action('wp_ajax_core_cloudflare_refresh_zones', [$this, 'ajax_refresh_zones']);
		add_action('wp_ajax_core_cloudflare_clear_logs', [$this, 'ajax_clear_logs']);
		add_action('wp_ajax_core_cloudflare_save_settings', [$this, 'ajax_save_settings']);
		add_action('wp_ajax_core_cloudflare_delete_credentials', [$this, 'ajax_delete_credentials']);

		add_action('admin_post_core_cloudflare_download_log', [$this, 'handle_log_download']);
	}

	/**
	 * Register the top-level Network Admin menu and its tabs (as
	 * submenu pages sharing one render callback).
	 *
	 * @return void
	 */
	public function register_menu(): void
	{
		if (! core_cloudflare_can_manage()) {
			return;
		}

		$capability = $this->config->capability();

		add_menu_page(
			__('Cloudflare', 'core-cloudflare'),
			__('Cloudflare', 'core-cloudflare'),
			$capability,
			self::MENU_SLUG,
			[$this, 'render_page'],
			'dashicons-cloud',
			76
		);

		$tabs = $this->tabs();
		foreach ($tabs as $tab_slug => $tab_label) {
			add_submenu_page(
				self::MENU_SLUG,
				sprintf('%s — %s', __('Cloudflare', 'core-cloudflare'), $tab_label),
				$tab_label,
				$capability,
				self::MENU_SLUG . '&tab=' . $tab_slug,
				[$this, 'render_page']
			);
		}
	}

	/**
	 * Ordered map of tab slug => label.
	 *
	 * @return array<string, string>
	 */
	private function tabs(): array
	{
		return [
			'dashboard'     => __('Dashboard', 'core-cloudflare'),
			'cache-purge'   => __('Cache Purge', 'core-cloudflare'),
			'configuration' => __('Configuration', 'core-cloudflare'),
			'logs'          => __('Logs', 'core-cloudflare'),
		];
	}

	/**
	 * Enqueue admin CSS/JS only on our own screens.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets(string $hook): void
	{
		if (false === strpos($hook, self::MENU_SLUG)) {
			return;
		}

		wp_enqueue_style(
			'core-cloudflare-admin',
			CORE_CLOUDFLARE_URL . 'assets/css/admin.css',
			[],
			CORE_CLOUDFLARE_VERSION
		);

		wp_enqueue_script(
			'core-cloudflare-admin',
			CORE_CLOUDFLARE_URL . 'assets/js/admin.js',
			['jquery'],
			CORE_CLOUDFLARE_VERSION,
			true
		);

		wp_localize_script(
			'core-cloudflare-admin',
			'CoreCloudflare',
			[
				'ajaxUrl'      => admin_url('admin-ajax.php'),
				'environment' => $this->config->environment(),
				'nonce'       => wp_create_nonce(self::NONCE_ACTION),
				'i18n'    => [
					// Confirm dialogs.
					'confirmPurgeAll'       => __('Purge cache for ALL configured domains? This cannot be undone.', 'core-cloudflare'),
					'confirmPurgeSelected'  => __('Purge cache for the selected domains?', 'core-cloudflare'),
					'confirmClearLogs'      => __('Delete all Core Cloudflare log files? This cannot be undone.', 'core-cloudflare'),
					// State labels.
					'working'               => __('Working…', 'core-cloudflare'),
					'labelSuccess'          => __('Success', 'core-cloudflare'),
					'labelFailed'           => __('Failed', 'core-cloudflare'),
					'labelSuccessful'       => __('Successful', 'core-cloudflare'),
					'labelUnknown'          => __('Unknown (no matching zone)', 'core-cloudflare'),
					'labelExecTime'         => __('Execution Time', 'core-cloudflare'),
					// Button labels.
					'btnSaveSettings'       => __('Save Settings', 'core-cloudflare'),
					'btnRefreshZones'       => __('Refresh Zones', 'core-cloudflare'),
					// Zone refresh.
					/* translators: %1$d: mapped zones count, %2$d: total zones count */
					'zoneMapped'            => __('Mapped %1$d of %2$d zones.', 'core-cloudflare'),
					// Table column headers.
					'colDomain'             => __('Domain', 'core-cloudflare'),
					'colHttpStatus'         => __('HTTP Status', 'core-cloudflare'),
					'colNote'               => __('Note', 'core-cloudflare'),
					// Error messages.
					'errorRequestFailed'    => __('The request could not be completed.', 'core-cloudflare'),
					'errorPurgeFailed'      => __('Purge could not be completed.', 'core-cloudflare'),
					'errorRefreshFailed'    => __('Zone refresh failed.', 'core-cloudflare'),
					'errorSaveFailed'       => __('Save failed.', 'core-cloudflare'),
					'errorConnectionFailed' => __('Connection failed.', 'core-cloudflare'),
					'errorNoDomainSelected' => __('Select at least one domain.', 'core-cloudflare'),
				],
			]
		);
	}

	/**
	 * Verify the shared admin AJAX nonce and capability. Dies with a
	 * JSON error and appropriate status code on failure.
	 *
	 * @return void
	 */

	private function guard_ajax(): void
	{
		if (! core_cloudflare_can_manage()) {
			wp_send_json_error(['message' => __('Insufficient permissions.', 'core-cloudflare')], 403);
		}

		check_ajax_referer(self::NONCE_ACTION, 'nonce');
	}

	/**
	 * AJAX: purge one, several, or all domains.
	 *
	 * @return void
	 */
	public function ajax_purge(): void
	{
		try {
			$this->guard_ajax();

			$scope = isset($_POST['scope']) ? sanitize_key(wp_unslash($_POST['scope'])) : 'selected';

			if ('all' === $scope) {
				$summary = $this->purge->purge_all(Logger::TRIGGER_ADMIN);
			} else {
				$raw_domains = isset($_POST['domains']) ? (array) wp_unslash($_POST['domains']) : [];
				$domains     = array_values(array_filter(array_map('sanitize_text_field', $raw_domains)));

				if (empty($domains)) {
					wp_send_json_error(['message' => __('No domains selected.', 'core-cloudflare')], 400);
				}

				$summary = $this->purge->purge_domains($domains, Logger::TRIGGER_ADMIN);
			}

			wp_send_json_success($summary);
		} catch (\Throwable $e) {
			$this->logger->failure(
				'Purge request failed: ' . $e->getMessage(),
				[
					'action' => 'cache_purge_ajax',
				]
			);

			wp_send_json_error(
				[
					'message' => sprintf(
						'%s: %s',
						('production' === $this->config->environment() ? 'Live' : 'Staging'),
						(defined('WP_DEBUG') && WP_DEBUG && '' !== trim((string) $e->getMessage()))
							? 'Purge request failed: ' . $e->getMessage()
							: __('The purge request could not be completed. Check the Cloudflare log for details.', 'core-cloudflare')
					),
				],
				500
			);
		}
	}

	/**
	 * AJAX: refresh the zone map.
	 *
	 * @return void
	 */
	public function ajax_refresh_zones(): void
	{
		$this->guard_ajax();

		$result = $this->zones->refresh(Logger::TRIGGER_ADMIN);

		if ($result['success']) {
			wp_send_json_success($result);
		}

		wp_send_json_error($result, 400);
	}

	/**
	 * AJAX: save settings from the editable Configuration tab.
	 *
	 * The API token field is only overwritten if the user actually
	 * typed a new value — an empty submission leaves the stored token
	 * untouched, since the form never round-trips the real token into
	 * the page for security reasons.
	 *
	 * @return void
	 */
	public function ajax_save_settings(): void
	{
		$this->guard_ajax();

		$environment = isset($_POST['environment']) ? sanitize_key(wp_unslash($_POST['environment'])) : 'staging';
		$new_token   = isset($_POST['api_token']) ? trim(sanitize_text_field(wp_unslash($_POST['api_token']))) : '';
		$account_id  = isset($_POST['account_id']) ? trim(sanitize_text_field(wp_unslash($_POST['account_id']))) : '';

		// Cloudflare API Tokens must be entered as the token value only.
		// Be forgiving if an administrator pastes "Bearer <token>" or quotes
		// around the token; otherwise Cloudflare returns 6003/6111 and the UI
		// incorrectly makes a valid token look invalid.
		if ('' !== $new_token) {
			$new_token = trim($new_token, " 	

 \"'");
			$new_token = preg_replace('/^Bearer\\s+/i', '', $new_token) ?? $new_token;
			$new_token = trim($new_token, " 	

 \"'");
		}

		$staging_raw    = isset($_POST['staging_domains']) ? (string) wp_unslash($_POST['staging_domains']) : '';
		$production_raw = isset($_POST['production_domains']) ? (string) wp_unslash($_POST['production_domains']) : '';

		$to_list = static function (string $raw): array {
			// The normal UI contract is one domain per line. Commas/semicolons
			// are also accepted, but NEVER silently split or repair concatenated
			// hostnames. A value such as
			// "coresarms.co.ukcoresarms.co.uk" must reach validation so the
			// administrator gets a clear error in the selected environment.
			$raw = trim($raw);
			$lines = preg_split('/[\r\n]+/', $raw) ?: [];
			$domains = [];
			foreach ($lines as $line) {
				$line = trim($line);
				if ('' === $line) {
					continue;
				}
				foreach (preg_split('/[;,]+/', $line) ?: [] as $value) {
					$value = trim($value);
					if ('' !== $value) {
						$domains[] = $value;
					}
				}
			}
			return array_values(array_unique($domains));
		};

		$input = [
			'environment'        => $environment,
			'staging_domains'    => $to_list($staging_raw),
			'production_domains' => $to_list($production_raw),
			'api_timeout'        => isset($_POST['api_timeout']) ? absint($_POST['api_timeout']) : $this->config->api_timeout(),
			'log_retention_days' => isset($_POST['log_retention_days']) ? absint($_POST['log_retention_days']) : $this->config->log_retention_days(),
		];

		// Only overwrite the token if the admin actually entered a new one.
		if ('' !== $new_token) {
			$input['api_token'] = $new_token;
		}
		if ('' !== $account_id) {
			$input['account_id'] = $account_id;
		}

		// If the selected environment contains a domain that is currently assigned
		// to the opposite environment, this save is a MOVE. Remove that domain
		// from the opposite list before validation so Live -> Staging and
		// Staging -> Live behave identically. The selected environment wins.
		$selected_domains = 'production' === $environment
			? (array) $input['production_domains']
			: (array) $input['staging_domains'];
		$selected_keys = [];
		foreach ($selected_domains as $selected_domain) {
			$normalized = core_cloudflare_sanitize_domain((string) $selected_domain);
			if ('' !== $normalized) {
				$selected_keys[strtolower($normalized)] = true;
			}
		}

		$opposite_field = 'production' === $environment ? 'staging_domains' : 'production_domains';
		$input[$opposite_field] = array_values(array_filter(
			(array) $input[$opposite_field],
			static function ($opposite_domain) use ($selected_keys): bool {
				$normalized = core_cloudflare_sanitize_domain((string) $opposite_domain);
				return '' === $normalized || ! isset($selected_keys[strtolower($normalized)]);
			}
		));

		$environment_errors = $this->config->validate_environment_domains(
			(array) $input['staging_domains'],
			(array) $input['production_domains']
		);

		// The selected environment must have all three required settings.
		// Blank protected credential fields preserve an existing value, but a
		// missing value is reported against the exact field that needs attention.
		$existing_token      = $this->config->api_token($environment);
		$existing_account_id = $this->config->account_id($environment);
		$active_domains      = 'production' === $environment
			? (array) $input['production_domains']
			: (array) $input['staging_domains'];
		$environment_label   = 'production' === $environment ? 'Live' : 'Staging';
		$field_errors        = [
			'api_token'         => [],
			'account_id'        => [],
			'staging_domains'   => [],
			'production_domains' => [],
		];

		if ('' === $new_token && '' === $existing_token) {
			$field_errors['api_token'][] = sprintf(
				__('%s: Cloudflare API Token is empty. Enter a valid Cloudflare API Token.', 'core-cloudflare'),
				$environment_label
			);
		}
		if ('' === $account_id && '' === $existing_account_id) {
			$field_errors['account_id'][] = sprintf(
				__('%s: Cloudflare Account ID is empty. Enter your Cloudflare Account ID.', 'core-cloudflare'),
				$environment_label
			);
		}
		// Attach domain validation errors to the environment textarea that
		// actually contains the invalid value.
		foreach ($environment_errors as $error) {
			$error = (string) $error;
			if (false !== stripos($error, 'cannot be added to Staging')) {
				$field_errors['staging_domains'][] = $error;
			} elseif (false !== stripos($error, 'cannot be added to Live')) {
				$field_errors['production_domains'][] = $error;
			} elseif (false !== stripos($error, 'Staging')) {
				$field_errors['staging_domains'][] = $error;
			} elseif (false !== stripos($error, 'Live') || false !== stripos($error, 'production')) {
				$field_errors['production_domains'][] = $error;
			} else {
				$domain_field = 'production' === $environment ? 'production_domains' : 'staging_domains';
				$field_errors[$domain_field][] = $error;
			}
		}


		$field_errors = array_map(static fn(array $errors): array => array_values(array_unique($errors)), $field_errors);
		$all_errors  = [];
		foreach ($field_errors as $errors) {
			$all_errors = array_merge($all_errors, $errors);
		}

		if (! empty($all_errors)) {
			$all_errors = array_values(array_unique($all_errors));
			// Keep validation failures as a normal AJAX response (HTTP 200).
			// This lets the settings form's .done() handler render the exact
			// Live/Staging validation message instead of jQuery routing it to
			// .fail() and leaving the user with a generic AJAX failure.
			wp_send_json_error(
				[
					// The first validation error is the useful headline; the complete
					// list remains available below the form and in field_errors.
					'message'      => $all_errors[0],
					'errors'       => $all_errors,
					'field_errors' => $field_errors,
				]
			);
		}

		$warnings = $this->config->save($input);
		$settings = new Settings($this->config);

		wp_send_json_success(
			[
				'message'           => __('Settings saved. Cloudflare access will be checked when a purge request is executed.', 'core-cloudflare'),
				'warnings'          => array_values(array_unique($warnings)),
				'masked_token'      => $settings->masked_token($environment),
				'masked_account_id' => $settings->masked_account_id($environment),
				'has_token'         => '' !== $this->config->api_token($environment),
				'has_account_id'    => '' !== $this->config->account_id($environment),
				'zones_refreshed'   => false,
				'credential_checks' => [],
			]
		);
	}

	/**
	 * AJAX: delete the selected environment credentials.
	 *
	 * @return void
	 */
	public function ajax_delete_credentials(): void
	{
		$this->guard_ajax();

		$environment = isset($_POST['environment']) ? sanitize_key(wp_unslash($_POST['environment'])) : $this->config->environment();
		if (! in_array($environment, ['staging', 'production'], true)) {
			wp_send_json_error(['message' => __('Invalid environment.', 'core-cloudflare')], 400);
		}

		$this->config->delete_credentials($environment);
		// The old token may have had access to zones that the replacement
		// token does not. Remove the cached zone map so stale zone IDs can
		// never be used after credential deletion.
		$this->zones->clear_all();
		$settings = new Settings($this->config);

		wp_send_json_success(
			[
				'message'           => sprintf(__('Credentials deleted for the %s environment.', 'core-cloudflare'), ucfirst($environment)),
				'masked_token'      => $settings->masked_token($environment),
				'masked_account_id' => $settings->masked_account_id($environment),
			]
		);
	}

	/**
	 * AJAX: clear all log files.
	 *
	 * @return void
	 */
	public function ajax_clear_logs(): void
	{
		$this->guard_ajax();

		$deleted = $this->logger->clear_all();

		wp_send_json_success(
			[
				/* translators: %d: number of files deleted */
				'message' => sprintf(__('Deleted %d log file(s).', 'core-cloudflare'), $deleted),
			]
		);
	}

	/**
	 * Handle a raw log file download via admin-post.php.
	 *
	 * @return void
	 */
	public function handle_log_download(): void
	{
		if (! core_cloudflare_can_manage()) {
			wp_die(esc_html__('Insufficient permissions.', 'core-cloudflare'), 403);
		}

		check_admin_referer(self::NONCE_ACTION, '_wpnonce');

		$date = isset($_GET['date']) ? sanitize_text_field(wp_unslash($_GET['date'])) : '';

		if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			wp_die(esc_html__('Invalid date.', 'core-cloudflare'), 400);
		}

		$path = $this->logger->download_path($date);

		if (! is_readable($path)) {
			wp_die(esc_html__('Log file not found.', 'core-cloudflare'), 404);
		}

		nocache_headers();
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="core-cloudflare-' . $date . '.log"');
		header('Content-Length: ' . (string) filesize($path));

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		readfile($path);
		exit;
	}

	/**
	 * Render the current tab's page. Performs the capability check
	 * again at render time as defense in depth.
	 *
	 * @return void
	 */
	public function render_page(): void
	{
		if (! core_cloudflare_can_manage()) {
			wp_die(esc_html__('You do not have permission to access this page.', 'core-cloudflare'));
		}

		$tabs       = $this->tabs();
		$active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'dashboard';
		if (! array_key_exists($active_tab, $tabs)) {
			$active_tab = 'dashboard';
		}

		echo '<div class="wrap core-cloudflare-wrap">';
		echo '<h1>' . esc_html__('Cloudflare', 'core-cloudflare') . '</h1>';

		echo '<h2 class="nav-tab-wrapper">';
		foreach ($tabs as $slug => $label) {
			$url   = (is_multisite() ? network_admin_url('admin.php?page=') : admin_url('admin.php?page=')) . self::MENU_SLUG . '&tab=' . $slug;
			$class = 'nav-tab' . ($slug === $active_tab ? ' nav-tab-active' : '');
			printf(
				'<a href="%1$s" class="%2$s">%3$s</a>',
				esc_url($url),
				esc_attr($class),
				esc_html($label)
			);
		}
		echo '</h2>';

		echo '<div class="core-cloudflare-tab-content">';

		switch ($active_tab) {
			case 'cache-purge':
				$this->render_cache_purge_tab();
				break;
			case 'configuration':
				$this->render_configuration_tab();
				break;
			case 'logs':
				$this->render_logs_tab();
				break;
			case 'dashboard':
			default:
				$this->render_dashboard_tab();
				break;
		}

		echo '</div></div>';
	}

	/**
	 * Dashboard tab: environment/API status overview.
	 *
	 * @return void
	 */
	private function render_dashboard_tab(): void
	{
		$overview     = $this->settings->overview();
		$zone_map     = $this->zones->list_zones();
		$last_refresh = $this->zones->last_refresh();
		$last_purge   = $this->purge->last_purge();
		$credential_check = ['warnings' => []]; ?>
		<div class="core-cloudflare-grid">
			<div class="core-cloudflare-card">
				<h3><?php esc_html_e('Environment', 'core-cloudflare'); ?></h3>
				<p class="core-cloudflare-badge core-cloudflare-badge-<?php echo esc_attr($overview['environment']); ?>">
					<?php echo esc_html(ucfirst($overview['environment'])); ?>
				</p>
			</div>

			<div class="core-cloudflare-card">
				<h3><?php esc_html_e('API Status', 'core-cloudflare'); ?></h3>
				<p><?php echo $overview['is_valid'] ? esc_html__('Configured', 'core-cloudflare') : esc_html__('Incomplete', 'core-cloudflare'); ?></p>
			</div>

			<div class="core-cloudflare-card">
				<h3><?php esc_html_e('Total Configured Domains', 'core-cloudflare'); ?></h3>
				<p><?php echo esc_html((string) count($this->config->active_domains())); ?></p>
			</div>
		</div>

		<?php
		$dashboard_warnings = array_values(array_unique(array_merge(
			(array) $overview['warnings'],
			(array) ($credential_check['warnings'] ?? array())
		)));
		?>
		<?php if (! empty($dashboard_warnings)) : ?>
			<div class="notice notice-warning inline">
				<p><strong><?php esc_html_e('Configuration and Cloudflare access warnings:', 'core-cloudflare'); ?></strong></p>
				<ul>
					<?php foreach ($dashboard_warnings as $warning) : ?>
						<li><?php echo esc_html($warning); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<h3><?php esc_html_e('Recent Activity', 'core-cloudflare'); ?></h3>
		<?php $this->render_recent_activity_tab_log_table($this->logger->read_entries(null, []), 10); ?>
	<?php
	}

	/**
	 * Cache Purge tab.
	 *
	 * @return void
	 */
	private function render_cache_purge_tab(): void
	{
		$domains = $this->config->active_domains();
	?>
		<div class="cc-page-header">
			<div>
				<h2><?php esc_html_e('Cache Purge', 'core-cloudflare'); ?></h2>
				<p><?php esc_html_e('Clear Cloudflare cache for the domains in the active environment.', 'core-cloudflare'); ?></p>
			</div>
			<?php if (! empty($domains)) : ?>
				<span class="cc-count-badge"><?php echo esc_html(count($domains)); ?> <?php esc_html_e('domains', 'core-cloudflare'); ?></span>
			<?php endif; ?>
		</div>

		<?php if (empty($domains)) : ?>
			<div class="notice notice-error inline">
				<p><?php esc_html_e('No domains are configured for the active environment.', 'core-cloudflare'); ?></p>
			</div>
			<?php return; ?>
		<?php endif; ?>

		<div class="cc-purge-toolbar">
			<div class="cc-purge-actions">
				<button type="button" class="button button-primary button-large" id="cc-purge-all"><?php esc_html_e('Purge All Domains', 'core-cloudflare'); ?></button>
				<button type="button" class="button button-large" id="cc-purge-selected"><?php esc_html_e('Purge Selected', 'core-cloudflare'); ?></button>
			</div>
			<span id="cc-selected-count" class="cc-selected-count">0 <?php esc_html_e('selected', 'core-cloudflare'); ?></span>
		</div>

		<div class="cc-domain-panel">
			<div class="cc-panel-header">
				<div>
					<strong><?php esc_html_e('Configured domains', 'core-cloudflare'); ?></strong>
					<span><?php esc_html_e('Select individual domains or purge the complete active environment.', 'core-cloudflare'); ?></span>
				</div>
				<label class="cc-select-all"><input type="checkbox" id="cc-select-all-domains" /><span><?php esc_html_e('Select all', 'core-cloudflare'); ?></span></label>
			</div>
			<table class="widefat core-cloudflare-domain-table">
				<thead>
					<tr>
						<th class="check-column"></th>
						<th><?php esc_html_e('Domain', 'core-cloudflare'); ?></th>
						<th><?php esc_html_e('Action', 'core-cloudflare'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($domains as $domain) : ?>
						<tr>
							<td class="check-column cpc"><input type="checkbox" class="cc-domain-checkbox" value="<?php echo esc_attr($domain); ?>" /></td>
							<td><span class="cc-domain-name"><?php echo esc_html($domain); ?></span></td>
							<td><button type="button" class="button cc-purge-site" data-domain="<?php echo esc_attr($domain); ?>"><?php esc_html_e('Purge This Site', 'core-cloudflare'); ?></button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div id="cc-purge-progress" class="cc-purge-progress" style="display:none;">
			<span class="spinner is-active"></span><strong><?php esc_html_e('Purging Cloudflare cache…', 'core-cloudflare'); ?></strong>
			<span><?php esc_html_e('Validating each domain and sending the purge request.', 'core-cloudflare'); ?></span>
		</div>

		<div id="cc-purge-result" class="core-cloudflare-result"></div>
	<?php
	}

	/**
	 * Configuration tab: fully editable settings form, saved via AJAX
	 * to the `core_cloudflare_settings` network site option. No manual
	 * file editing is required — optional wp-config constants are
	 * only used to seed values the very first time the plugin runs.
	 *
	 * @return void
	 */
	private function render_configuration_tab(): void
	{
		$overview = $this->settings->overview();
		$form     = $this->settings->form_values();
	?>
		<form id="cc-settings-form" data-credentials="<?php echo esc_attr(wp_json_encode($form['credentials'] ?? [])); ?>">
			<table class="form-table">
				<tr>
					<th><label for="cc-environment"><?php esc_html_e('Environment', 'core-cloudflare'); ?></label></th>
					<td>
						<select name="environment" id="cc-environment">
							<option value="staging" <?php selected($form['environment'], 'staging'); ?>><?php esc_html_e('Staging', 'core-cloudflare'); ?></option>
							<option value="production" <?php selected($form['environment'], 'production'); ?>><?php esc_html_e('Production', 'core-cloudflare'); ?></option>
						</select>
						<p class="description"><?php esc_html_e('Select the environment first. Only that environment domain list is active. Staging/test domains cannot be saved as Live, and Live domains cannot be saved as Staging.', 'core-cloudflare'); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="cc-api-token"><?php esc_html_e('Cloudflare API Token', 'core-cloudflare'); ?> <span class="core-cloudflare-lock-badge" title="<?php esc_attr_e('Encrypted at rest', 'core-cloudflare'); ?>"></span></label></th>
					<td>
						<div id="cc-token-display" class="cc-credential-display<?php echo $form['has_token'] ? '' : ' is-hidden'; ?>">
							<span class="cc-credential-icon">🔒</span>
							<code id="cc-token-masked"><?php echo esc_html($this->settings->masked_token($form['environment'])); ?></code>
						</div>
						<input type="text" name="api_token" id="cc-api-token" class="regular-text<?php echo $form['has_token'] ? ' is-hidden' : ''; ?>" autocomplete="off" placeholder="<?php esc_attr_e('Enter Cloudflare API token', 'core-cloudflare'); ?>" />
						<div id="cc-api-token-error" class="cc-field-error" aria-live="polite"></div>
					</td>
				</tr>
				<tr>
					<th><label for="cc-account-id"><?php esc_html_e('Cloudflare Account ID', 'core-cloudflare'); ?></label></th>
					<td>
						<div id="cc-account-display" class="cc-credential-display<?php echo $form['has_account_id'] ? '' : ' is-hidden'; ?>">
							<span class="cc-credential-icon">🔒</span>
							<code id="cc-account-id-masked"><?php echo esc_html($this->settings->masked_account_id($form['environment'])); ?></code>
						</div>
						<input type="text" name="account_id" id="cc-account-id" class="regular-text<?php echo $form['has_account_id'] ? ' is-hidden' : ''; ?>" autocomplete="off" placeholder="<?php esc_attr_e('Enter Cloudflare Account ID', 'core-cloudflare'); ?>" value="" />
						<div id="cc-account-id-error" class="cc-field-error" aria-live="polite"></div>
					</td>
				</tr>
				<tr>
					<th></th>
					<td><button type="button" class="button button-secondary" id="cc-delete-credentials"><?php echo esc_html(sprintf(__('Delete %s Credentials', 'core-cloudflare'), ucfirst($form['environment']))); ?></button></td>
				</tr>
				<tr id="cc-row-staging-domains">
					<th><label for="cc-staging-domains"><?php esc_html_e('Staging Domains', 'core-cloudflare'); ?></label></th>
					<td>
						<textarea name="staging_domains" id="cc-staging-domains" rows="6" class="large-text" placeholder="staging.example.com"><?php echo esc_textarea($form['staging_domains']); ?></textarea>
						<p class="description"><?php esc_html_e('One domain or URL per line. Only the selected environment is used for purge.', 'core-cloudflare'); ?></p>
						<div id="cc-staging-domains-error" class="cc-field-error" aria-live="polite"></div>
					</td>
				</tr>
				<tr id="cc-row-production-domains">
					<th><label for="cc-production-domains"><?php esc_html_e('Live Domains', 'core-cloudflare'); ?></label></th>
					<td>
						<textarea name="production_domains" id="cc-production-domains" rows="6" class="large-text" placeholder="https://www.example.com"><?php echo esc_textarea($form['production_domains']); ?></textarea>
						<p class="description"><?php esc_html_e('One domain or URL per line. Only the selected environment is used for purge.', 'core-cloudflare'); ?></p>
						<div id="cc-production-domains-error" class="cc-field-error" aria-live="polite"></div>
					</td>
				</tr>
				<tr>
					<th><label for="cc-api-timeout"><?php esc_html_e('API Timeout (seconds)', 'core-cloudflare'); ?></label></th>
					<td><input type="number" min="5" max="120" name="api_timeout" id="cc-api-timeout" value="<?php echo esc_attr((string) $form['api_timeout']); ?>" /></td>
				</tr>
				<tr>
					<th><label for="cc-log-retention"><?php esc_html_e('Log Retention (days)', 'core-cloudflare'); ?></label></th>
					<td><input type="number" min="1" max="365" name="log_retention_days" id="cc-log-retention" value="<?php echo esc_attr((string) $form['log_retention_days']); ?>" /></td>
				</tr>
			</table>

			<p>
				<button type="submit" class="button button-primary" id="cc-save-settings"><?php esc_html_e('Save Settings', 'core-cloudflare'); ?></button>
			</p>

			<div id="cc-settings-result" class="core-cloudflare-result"></div>
		</form>

		<?php if (! empty($overview['warnings'])) : ?>
			<div class="notice notice-warning inline">
				<p><strong><?php esc_html_e('Current warnings:', 'core-cloudflare'); ?></strong></p>
				<ul><?php foreach ($overview['warnings'] as $warning) : ?><li><?php echo esc_html($warning); ?></li><?php endforeach; ?></ul>
			</div>
		<?php endif; ?>
	<?php
	}

	/**
	 * Logs tab: search/filter/paginate/download/clear.
	 *
	 * @return void
	 */
	private function render_logs_tab(): void
	{
		$request = [
			'paged'      => isset($_GET['paged']) ? absint($_GET['paged']) : 1,
			'start_date' => isset($_GET['start_date']) ? sanitize_text_field(wp_unslash($_GET['start_date'])) : '',
			'end_date'   => isset($_GET['end_date']) ? sanitize_text_field(wp_unslash($_GET['end_date'])) : '',
			'domain'     => isset($_GET['domain']) ? sanitize_text_field(wp_unslash($_GET['domain'])) : '',
			'status'     => isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '',
			'search'     => isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '',
		];

		$result = $this->logger->query_from_request(array_filter($request, static fn($v) => '' !== $v) + ['paged' => $request['paged']]);

	?>
		<form method="get" class="core-cloudflare-log-filters">
			<input type="hidden" name="page" value="core-cloudflare" />
			<input type="hidden" name="tab" value="logs" />

			<input type="date" name="start_date" value="<?php echo esc_attr($request['start_date']); ?>" />
			<input type="date" name="end_date" value="<?php echo esc_attr($request['end_date']); ?>" />
			<input type="text" name="domain" placeholder="<?php esc_attr_e('Domain', 'core-cloudflare'); ?>" value="<?php echo esc_attr($request['domain']); ?>" />

			<select name="status">
				<option value=""><?php esc_html_e('All Statuses', 'core-cloudflare'); ?></option>
				<option value="success" <?php selected($request['status'], 'success'); ?>><?php esc_html_e('Success', 'core-cloudflare'); ?></option>
				<option value="failure" <?php selected($request['status'], 'failure'); ?>><?php esc_html_e('Failure', 'core-cloudflare'); ?></option>
			</select>

			<input type="text" name="search" placeholder="<?php esc_attr_e('Search…', 'core-cloudflare'); ?>" value="<?php echo esc_attr($request['search']); ?>" />

			<button type="submit" class="button"><?php esc_html_e('Filter', 'core-cloudflare'); ?></button>
			<button type="button" class="button" id="cc-clear-logs"><?php esc_html_e('Clear Logs', 'core-cloudflare'); ?></button>
		</form>

		<?php $this->render_log_table($result['items'], null); ?>

		<?php
		$total_pages = (int) ceil($result['total'] / max(1, $result['per_page']));
		if ($total_pages > 1) :
		?>
			<div class="tablenav">
				<div class="tablenav-pages">
					<?php
					echo paginate_links(
						[
							'base'      => add_query_arg('paged', '%#%'),
							'format'    => '',
							'current'   => $result['page'],
							'total'     => $total_pages,
						]
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>
			</div>
		<?php endif; ?>
	<?php
	}

	/**
	 * Render a table of log entries, optionally capped to $limit rows.
	 *
	 * @param array<int, array<string, mixed>> $entries Log entries.
	 * @param int|null                         $limit   Max rows to show, or null for all.
	 * @return void
	 */
	private function render_log_table(array $entries, ?int $limit): void
	{
		if (null !== $limit) {
			$entries = array_slice($entries, 0, $limit);
		}
	?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e('Timestamp', 'core-cloudflare'); ?></th>
					<th><?php esc_html_e('Environment', 'core-cloudflare'); ?></th>
					<th><?php esc_html_e('Domain', 'core-cloudflare'); ?></th>
					<th><?php esc_html_e('Zone ID', 'core-cloudflare'); ?></th>
					<th><?php esc_html_e('HTTP Status', 'core-cloudflare'); ?></th>
					<th><?php esc_html_e('Execution Time', 'core-cloudflare'); ?></th>
					<th><?php esc_html_e('Result', 'core-cloudflare'); ?></th>
					<th><?php esc_html_e('Action', 'core-cloudflare'); ?></th>
					<th><?php esc_html_e('Note', 'core-cloudflare'); ?></th>
					<th><?php esc_html_e('Details', 'core-cloudflare'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if (empty($entries)) : ?>
					<tr>
						<td colspan="10"><?php esc_html_e('No log entries found.', 'core-cloudflare'); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ($entries as $entry) : ?>
						<?php
						// Raw API request rows are implementation-level entries.
						// Business actions such as cache_purge contain the full request +
						// Cloudflare response in the same record and are the only rows
						// that should be shown in the admin log table.
						if ('api_request' === (string) ($entry['action'] ?? '')) {
							continue;
						}
						?>
						<tr>
							<td><?php echo esc_html((string) ($entry['timestamp'] ?? '')); ?></td>
							<td><span class="cc-environment-label cc-environment-<?php echo esc_attr((string) ($entry['environment'] ?? 'staging')); ?>"><?php echo esc_html('production' === (string) ($entry['environment'] ?? '') ? 'Live' : 'Staging'); ?></span></td>
							<td><?php echo esc_html((string) ($entry['domain'] ?? '')); ?></td>
							<td>
								<?php if (! empty($entry['success'])) : ?>
									<code><?php echo esc_html((string) ($entry['zone_id'] ?? '')); ?></code>
								<?php else : ?>
									<?php echo esc_html('Failure: ' . (string) ($entry['error_message'] ?? $entry['note'] ?? 'Cloudflare cache purge failed.')); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html((string) ($entry['http_status'] ?? '')); ?></td>
							<td><?php echo esc_html(isset($entry['execution_time']) ? number_format_i18n((float) $entry['execution_time'], 3) . 's' : ''); ?></td>
							<td>
								<?php if (! empty($entry['success'])) : ?>
									<span class="core-cloudflare-status core-cloudflare-status-success"><?php esc_html_e('Success', 'core-cloudflare'); ?></span>
								<?php else : ?>
									<span class="core-cloudflare-status core-cloudflare-status-failure"><?php esc_html_e('Failure', 'core-cloudflare'); ?></span>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html((string) ($entry['action'] ?? '')); ?></code></td>
							<td><?php echo esc_html((string) ($entry['note'] ?? '')); ?></td>
							<td>
								<details class="cc-log-details">
									<summary><?php esc_html_e('View request & Cloudflare response', 'core-cloudflare'); ?></summary>
									<?php if (! empty($entry['error_message'])) : ?><p><strong><?php esc_html_e('Error:', 'core-cloudflare'); ?></strong> <?php echo esc_html((string) $entry['error_message']); ?></p><?php endif; ?>
									<?php if (isset($entry['request'])) : ?><strong><?php esc_html_e('Request:', 'core-cloudflare'); ?></strong>
										<pre><?php echo esc_html(wp_json_encode($entry['request'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]'); ?></pre><?php endif; ?>
									<?php if (isset($entry['response'])) : ?><strong><?php esc_html_e('Cloudflare API Response:', 'core-cloudflare'); ?></strong>
										<pre><?php echo esc_html(wp_json_encode($entry['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]'); ?></pre><?php endif; ?>
								</details>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	<?php
	}


	private function render_recent_activity_log_table(array $entries, ?int $limit): void
	{
		if (null !== $limit) {
			$entries = array_slice($entries, 0, $limit);
		}
	?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e('Timestamp', 'core-cloudflare'); ?></th>
					<th><?php esc_html_e('Environment', 'core-cloudflare'); ?></th>
					<th><?php esc_html_e('Domain', 'core-cloudflare'); ?></th>
					<th><?php esc_html_e('Zone ID', 'core-cloudflare'); ?></th>
					<th><?php esc_html_e('HTTP Status', 'core-cloudflare'); ?></th>
					<th><?php esc_html_e('Execution Time', 'core-cloudflare'); ?></th>
					<th><?php esc_html_e('Result', 'core-cloudflare'); ?></th>
					<th><?php esc_html_e('Action', 'core-cloudflare'); ?></th>
					<th><?php esc_html_e('Note', 'core-cloudflare'); ?></th>

				</tr>
			</thead>
			<tbody>
				<?php if (empty($entries)) : ?>
					<tr>
						<td colspan="10"><?php esc_html_e('No log entries found.', 'core-cloudflare'); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ($entries as $entry) : ?>
						<?php
						// Raw API request rows are implementation-level entries.
						// Business actions such as cache_purge contain the full request +
						// Cloudflare response in the same record and are the only rows
						// that should be shown in the admin log table.
						if ('api_request' === (string) ($entry['action'] ?? '')) {
							continue;
						}
						?>
						<tr>
							<td><?php echo esc_html((string) ($entry['timestamp'] ?? '')); ?></td>
							<td><span class="cc-environment-label cc-environment-<?php echo esc_attr((string) ($entry['environment'] ?? 'staging')); ?>"><?php echo esc_html('production' === (string) ($entry['environment'] ?? '') ? 'Live' : 'Staging'); ?></span></td>
							<td><?php echo esc_html((string) ($entry['domain'] ?? '')); ?></td>
							<td>
								<?php if (! empty($entry['success'])) : ?>
									<code><?php echo esc_html((string) ($entry['zone_id'] ?? '')); ?></code>
								<?php else : ?>
									<?php echo esc_html('Failure: ' . (string) ($entry['error_message'] ?? $entry['note'] ?? 'Cloudflare cache purge failed.')); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html((string) ($entry['http_status'] ?? '')); ?></td>
							<td><?php echo esc_html(isset($entry['execution_time']) ? number_format_i18n((float) $entry['execution_time'], 3) . 's' : ''); ?></td>
							<td>
								<?php if (! empty($entry['success'])) : ?>
									<span class="core-cloudflare-status core-cloudflare-status-success"><?php esc_html_e('Success', 'core-cloudflare'); ?></span>
								<?php else : ?>
									<span class="core-cloudflare-status core-cloudflare-status-failure"><?php esc_html_e('Failure', 'core-cloudflare'); ?></span>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html((string) ($entry['action'] ?? '')); ?></code></td>
							<td><?php echo esc_html((string) ($entry['note'] ?? '')); ?></td>

						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
<?php
	}


	/**
	 * Render a table of log entries, optionally capped to $limit rows.
	 *
	 * @param array<int, array<string, mixed>> $entries Log entries.
	 * @param int|null                         $limit   Max rows to show, or null for all.
	 * @return void
	 */
	private function render_recent_activity_tab_log_table(array $entries, ?int $limit): void
	{
		// Dashboard intentionally uses the same detailed log renderer as the
		// Logs tab so Recent Activity never hides response/error details.
		$this->render_recent_activity_log_table($entries, $limit);
	}
}
