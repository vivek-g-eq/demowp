<?php
declare(strict_types=1);

namespace Core_Cloudflare;

if (!defined('ABSPATH')) {
    exit;
}

final class Zone_Manager
{
    private const OPTION_ZONE_MAP = 'core_cloudflare_zone_map';
    private const OPTION_LAST_REFRESH = 'core_cloudflare_last_zone_refresh';

    public function __construct(
        private Config $config,
        private Cloudflare_API $api,
        private Logger $logger
    ) {}

    /**
     * Validate the active environment's Cloudflare credentials without
     * performing a destructive action.
     *
     * @return array{success: bool, checks: array<string, array<string, mixed>>, warnings: string[]}
     */
    public function check_credentials(): array
    {
        return $this->api->check_credentials(
            $this->config->account_id(),
            $this->config->environment()
        );
    }

    public function refresh(string $trigger = Logger::TRIGGER_ADMIN): array
    {
        $start = microtime(true);

        if ('' === $this->config->api_token()) {
            $environment_label = 'production' === $this->config->environment() ? 'Live' : 'Staging';
            $error = sprintf('%s: %s', $environment_label, __('Cloudflare API token is not configured. Add a valid API token before refreshing zones or purging cache.', 'core-cloudflare'));
            $this->logger->failure($error, [
                'action' => 'zone_refresh',
            ]);
            return [
                'success' => false,
                'mapped' => 0,
                'total_zones' => 0,
                'error' => $error,
                'error_code' => 'missing_token',
            ];
        }

        $map = [];
        $page = 1;
        $total_zones = 0;
        $total_pages = 1;

        do {
            $response = $this->api->get('/zones', [
                'page' => $page,
                'per_page' => 50,
            ]);

            if (!$response->success) {
                $this->logger->failure($response->error_message, [
                    'action' => 'zone_refresh',
                    'http_status' => $response->status,
                    'execution_time' => $response->execution_time,
                ]);

                return [
                    'success' => false,
                    'mapped' => 0,
                    'total_zones' => 0,
                    'error' => sprintf('%s: %s', 'production' === $this->config->environment() ? 'Live' : 'Staging', $response->error_message),
                    'error_code' => $response->error_code ?: 'cloudflare_api_error',
                ];
            }

            $zones = $response->result();
            if (!is_array($zones)) {
                $zones = [];
            }

            foreach ($zones as $zone) {
                if (!is_array($zone) || empty($zone['name']) || empty($zone['id'])) {
                    continue;
                }

                $name = core_cloudflare_sanitize_domain((string) $zone['name']);
                $id = sanitize_text_field((string) $zone['id']);

                if ('' === $name || '' === $id) {
                    continue;
                }

                ++$total_zones;
                $map[$name] = [
                    'zone_id' => $id,
                    'status' => sanitize_key((string) ($zone['status'] ?? 'unknown')),
                    'updated_at' => core_cloudflare_now(),
                ];
            }

            $total_pages = max(1, (int) ($response->data['result_info']['total_pages'] ?? 1));
            ++$page;
        } while ($page <= $total_pages && $page <= 20);

        update_site_option(self::OPTION_ZONE_MAP, $map);
        update_site_option(self::OPTION_LAST_REFRESH, core_cloudflare_now());

        $unmapped = [];
        foreach ($this->config->active_domains() as $domain) {
            if (null === $this->find_zone_in_map($domain, $map)) {
                $unmapped[] = $domain;
            }
        }

        $error = '';
        if ($unmapped) {
            $error = sprintf(
                __('The configured domain does not match any Cloudflare zone accessible by the current API token: %s. Verify the hostname and make sure the token has permission to access that zone.', 'core-cloudflare'),
                implode(', ', $unmapped)
            );
        }

        $this->logger->success([
            'action' => 'zone_refresh',
            'execution_time' => microtime(true) - $start,
            'note' => sprintf(
                'Loaded %d Cloudflare zones; %d configured domains unresolved.',
                count($map),
                count($unmapped)
            ),
        ]);

        return [
            'success' => true,
            'mapped' => count($map),
            'total_zones' => $total_zones,
            'error' => $error,
            'error_code' => '',
        ];
    }

    public function get_zone(string $domain): ?array
    {
        $domain = core_cloudflare_sanitize_domain($domain);
        if ('' === $domain) {
            return null;
        }

        $map = $this->list_zones();
        $zone = $this->find_zone_in_map($domain, $map);

        if (null !== $zone) {
            return $zone;
        }

        $this->refresh(Logger::TRIGGER_HOOK);
        return $this->find_zone_in_map($domain, $this->list_zones());
    }

    private function find_zone_in_map(string $domain, array $map): ?array
    {
        $domain = core_cloudflare_sanitize_domain($domain);
        if ('' === $domain) {
            return null;
        }

        if (isset($map[$domain])) {
            return array_merge($map[$domain], ['zone_name' => $domain]);
        }

        $parts = explode('.', $domain);
        while (count($parts) > 2) {
            array_shift($parts);
            $zone_name = implode('.', $parts);

            if (isset($map[$zone_name])) {
                return array_merge($map[$zone_name], ['zone_name' => $zone_name]);
            }
        }

        return null;
    }

    public function validate_domain(string $domain): array
    {
        $domain = core_cloudflare_sanitize_domain($domain);

        if ('' === $domain) {
            return [
                'valid' => false,
                'code' => 'invalid_domain',
                'message' => __('Invalid domain or URL. Purge skipped for this domain.', 'core-cloudflare'),
                'zone' => null,
            ];
        }

        /*
         * Cache purge does NOT require an exact DNS-record lookup.
         * Only resolve the hostname to an accessible Cloudflare zone.
         */
        $map  = $this->list_zones();
        $zone = $this->find_zone_in_map($domain, $map);

        if (null === $zone || empty($zone['zone_id'])) {
            $refresh = $this->refresh(Logger::TRIGGER_HOOK);

            if (empty($refresh['success'])) {
                return [
                    'valid' => false,
                    'code' => (string) ($refresh['error_code'] ?? 'cloudflare_api_error'),
                    'message' => (string) ($refresh['error'] ?? __('Unable to access Cloudflare zones.', 'core-cloudflare')),
                    'zone' => null,
                ];
            }

            $zone = $this->find_zone_in_map($domain, $this->list_zones());
        }

        if (null === $zone || empty($zone['zone_id'])) {
            return [
                'valid' => false,
                'code' => 'zone_not_found',
                'message' => sprintf(
                    __('Domain %s does not match any accessible Cloudflare zone. Purge skipped for this domain.', 'core-cloudflare'),
                    $domain
                ),
                'zone' => null,
            ];
        }

        return [
            'valid' => true,
            'code' => '',
            'message' => '',
            'zone' => $zone,
        ];
    }

    public function list_zones(): array
    {
        $map = get_site_option(self::OPTION_ZONE_MAP, []);
        return is_array($map) ? $map : [];
    }

    public function last_refresh(): string
    {
        $value = get_site_option(self::OPTION_LAST_REFRESH, '');
        return is_string($value) ? $value : '';
    }

    public function clear_all(): void
    {
        delete_site_option(self::OPTION_ZONE_MAP);
        delete_site_option(self::OPTION_LAST_REFRESH);
    }
}
