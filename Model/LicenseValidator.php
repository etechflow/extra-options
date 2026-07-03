<?php

declare(strict_types=1);

namespace Etechflow\OptionsPlugin\Model;

use Magento\Framework\App\Cache\Type\Config as ConfigCacheType;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * License validation for Etechflow_OptionsPlugin.
 *
 * Hybrid model — follows LICENSING_IMPLEMENTATION_GUIDE.md:
 *   - SP-XXXX keys  -> portal validation (domain + server IP must match).
 *   - HMAC keys     -> local HMAC-SHA256 per-module key OR shared bundle key.
 *   - Common dev hostnames auto-detect and bypass.
 *
 * IMPORTANT (protocol): MODULE_ID + SECRET_FRAGMENTS are unique to this
 * module; BUNDLE_ID + BUNDLE_SECRET_FRAGMENTS + XML_PATH_BUNDLE_LICENSE_KEY
 * are byte-identical across EVERY eTechFlow module so a single bundle key
 * activates all of them. Do not change the bundle constants here without
 * changing them everywhere.
 */
class LicenseValidator
{
    // ── per-module config paths ─────────────────────────────────────────────
    public const XML_PATH_LICENSE_KEY            = 'etechflow_options/license/license_key';
    public const XML_PATH_ISSUED_KEY             = 'etechflow_options/license/issued_key';
    public const XML_PATH_ISSUED_AT              = 'etechflow_options/license/issued_at';
    public const XML_PATH_IP_BLOCKED             = 'etechflow_options/license/ip_blocked';
    public const XML_PATH_PORTAL_URL             = 'etechflow_options/license/portal_url';
    public const XML_PATH_PRODUCTION_ENVIRONMENT = 'etechflow_options/license/production_environment';

    /** Shared bundle config path — same value across all eTechFlow modules. */
    public const XML_PATH_BUNDLE_LICENSE_KEY = 'etechflow_bundle/license/license_key';

    // ── portal ──────────────────────────────────────────────────────────────
    private const DEFAULT_PORTAL_URL   = 'https://license-service.etechflow.com/license/validate';
    public  const PORTAL_CACHE_TTL     = 30;   // 30 s — suspensions apply within 30 s
    public  const PORTAL_CACHE_TTL_BAD = 60;   // 60 s — re-check quickly after block lifted

    // ── cache (unique per module) ────────────────────────────────────────────
    private const CACHE_TAG    = 'ETECHFLOW_EO';
    private const CACHE_PREFIX = 'etf_eo_lic_';

    // ── HMAC — per-module (UNIQUE to options-plugin; do not reuse elsewhere) ──
    private const MODULE_ID = 'options-plugin';

    private const SECRET_FRAGMENTS = [
        'eTF-EO-2026',
        'M4kP-vR7n',
        'T9wL-bH3j',
        'K2yX-dF6m',
    ];

    // ── HMAC — shared bundle (MUST be identical in every eTechFlow module) ──
    private const BUNDLE_ID = 'etechflow-bundle';

    private const BUNDLE_SECRET_FRAGMENTS = [
        'eTF-BUNDLE-2026',
        'k2D9-mP4x',
        'L8nR-vH2j',
        'X7tY-zW5q',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly CacheInterface $cache,
        private readonly Curl $curl,
        private readonly WriterInterface $configWriter
    ) {
    }

    // ── public API ──────────────────────────────────────────────────────────

    public function isValid(): bool
    {
        $host = $this->getCurrentHost();
        if ($host === '') {
            return false;
        }
        if (!$this->isProductionEnvironment()) {
            return true;
        }
        if ($this->isDevelopmentHost($host)) {
            return true;
        }
        return $this->checkKey($host);
    }

    public function computeKey(string $host): string
    {
        $payload = $this->canonicalize($host) . ':' . self::MODULE_ID;
        $secret  = implode('', self::SECRET_FRAGMENTS);
        $raw     = hash_hmac('sha256', $payload, $secret, true);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function computeBundleKey(string $host): string
    {
        $payload = $this->canonicalize($host) . ':' . self::BUNDLE_ID;
        $secret  = implode('', self::BUNDLE_SECRET_FRAGMENTS);
        $raw     = hash_hmac('sha256', $payload, $secret, true);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function canonicalize(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }

    public function getConfiguredKey(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_LICENSE_KEY, ScopeInterface::SCOPE_STORE);
        return trim((string) $value);
    }

    public function getConfiguredBundleKey(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_BUNDLE_LICENSE_KEY, ScopeInterface::SCOPE_STORE);
        return trim((string) $value);
    }

    public function isProductionEnvironment(): bool
    {
        // Sandbox toggle removed: production licensing is always enforced.
        return true;
    }

    public function getPortalUrl(): string
    {
        $value = trim((string) $this->scopeConfig->getValue(self::XML_PATH_PORTAL_URL));
        return $value !== '' ? $value : self::DEFAULT_PORTAL_URL;
    }

    public function getCurrentHost(): string
    {
        try {
            $url  = $this->storeManager->getStore()->getBaseUrl();
            $host = parse_url($url, PHP_URL_HOST);
            return is_string($host) ? strtolower($host) : '';
        } catch (\Exception) {
            return '';
        }
    }

    public function isDevHost(?string $host = null): bool
    {
        $check = $host !== null
            ? $this->canonicalize($host)
            : $this->canonicalize($this->getCurrentHost());
        return $this->isDevelopmentHost($check);
    }

    // ── private helpers ─────────────────────────────────────────────────────

    private function checkKey(string $host): bool
    {
        $configuredKey = $this->getConfiguredKey();
        if ($configuredKey === '') {
            return false;
        }

        // SP-XXXX subscription key → ALWAYS validate live against the portal
        // (result cached for PORTAL_CACHE_TTL only). No offline grace, no issued-key
        // fallback: the portal is the single source of truth, so a server-IP
        // mismatch, suspension, or expiry locks the module within the cache window.
        if (str_starts_with($configuredKey, 'SP-')) {
            return $this->portalResult($host, $configuredKey)['valid'];
        }

        // HMAC per-module key (offline)
        if (hash_equals($this->computeKey($host), $configuredKey)) {
            return true;
        }
        // Shared bundle key
        $bundleKey = $this->getConfiguredBundleKey();
        return $bundleKey !== '' && hash_equals($this->computeBundleKey($host), $bundleKey);
    }

    /**
     * Live portal check. Returns ['valid' => bool, 'features' => array].
     *
     * @return array{valid: bool, features: array<string,mixed>}
     */
    private function portalResult(string $host, string $key): array
    {
        $cacheKey = self::CACHE_PREFIX . md5($host . ':' . $key);
        $cached   = $this->cache->load($cacheKey);
        if ($cached !== false) {
            $d = json_decode((string) $cached, true);
            if (is_array($d) && array_key_exists('valid', $d)) {
                return [
                    'valid'    => (bool) $d['valid'],
                    'features' => isset($d['features']) && is_array($d['features']) ? $d['features'] : [],
                ];
            }
        }

        $url = $this->getPortalUrl()
            . '?domain=' . urlencode($host)
            . '&license_key=' . urlencode($key)
            . '&platform=magento&module=' . self::MODULE_ID;

        $valid    = false;
        $features = [];
        $status   = 0;
        $body     = '';

        try {
            $this->curl->setTimeout(10);
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('User-Agent', 'ETechFlow-EO/1.0');
            $this->curl->get($url);
            $status = (int) $this->curl->getStatus();
            $body   = (string) $this->curl->getBody();
        } catch (\Throwable) {
            // Portal unreachable — fail closed for THIS request without caching.
            return ['valid' => false, 'features' => []];
        }

        if ($status === 200 && $body !== '') {
            $data     = json_decode($body, true);
            $valid    = !empty($data['valid']);
            $features = (is_array($data) && isset($data['features']) && is_array($data['features'])) ? $data['features'] : [];
        }

        $ttl = $valid ? self::PORTAL_CACHE_TTL : self::PORTAL_CACHE_TTL_BAD;
        $this->cache->save(
            json_encode(['valid' => $valid, 'features' => $valid ? $features : []]),
            $cacheKey,
            [self::CACHE_TAG],
            $ttl
        );

        return ['valid' => $valid, 'features' => $valid ? $features : []];
    }

    /**
     * Plan feature flags for the currently-active subscription.
     *
     * @return array<string,mixed>
     */
    public function getPlanFeatures(): array
    {
        $host = $this->getCurrentHost();
        if ($host === '') {
            return [];
        }
        $key = $this->getConfiguredKey();
        if (!str_starts_with($key, 'SP-')) {
            return [];
        }
        return $this->portalResult($host, $key)['features'];
    }

    public function isFeatureEnabled(string $flag, bool $default = true): bool
    {
        $features = $this->getPlanFeatures();
        if (!array_key_exists($flag, $features)) {
            return $default;
        }
        return (bool) $features[$flag];
    }

    /**
     * Current licence state for the configured key, read live (cached 60s) from
     * the portal's /license/status endpoint. Read-only: NEVER clears the key.
     * Returns one of: active | suspended | blocked | expired | invalid | none.
     * Used by the admin banner to explain WHY the module is frozen without
     * removing the key (suspension/expiry/IP-block all keep the key in config).
     */
    public function getLicenseState(): string
    {
        $host = $this->getCurrentHost();
        $key  = $this->getConfiguredKey();
        if ($host === '' || $key === '') {
            return 'none';
        }
        $cacheKey = self::CACHE_PREFIX . 'state_' . md5($host . ':' . $key);
        $cached   = $this->cache->load($cacheKey);
        if ($cached !== false) {
            return (string) $cached;
        }

        $base  = rtrim(str_replace('/license/validate', '', $this->getPortalUrl()), '/');
        $url   = $base . '/license/status?platform=magento&module=' . self::MODULE_ID
            . '&domain=' . urlencode($host) . '&license_key=' . urlencode($key);
        $state = 'invalid';

        try {
            $this->curl->setTimeout(8);
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->get($url);
            if ((int) $this->curl->getStatus() === 200) {
                $d = json_decode((string) $this->curl->getBody(), true);
                if (is_array($d)) {
                    if (!empty($d['ip_blocked'])) {
                        $state = 'blocked';
                    } elseif (!empty($d['state'])) {
                        $state = (string) $d['state'];
                    } elseif (isset($d['valid'])) {
                        $state = $d['valid'] ? 'active' : 'invalid';
                    }
                }
            }
        } catch (\Throwable) {
            // Portal unreachable — leave 'invalid' (do not cache long).
        }

        $this->cache->save($state, $cacheKey, [self::CACHE_TAG], 60);
        return $state;
    }

    private function isDevelopmentHost(string $host): bool
    {
        if ($host === 'localhost' || str_starts_with($host, '127.')) {
            return true;
        }
        if (str_starts_with($host, '10.') || str_starts_with($host, '192.168.')) {
            return true;
        }
        if (preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $host)) {
            return true;
        }
        foreach (['.test', '.local', '.localhost', '.dev', '.example', '.invalid'] as $s) {
            if (str_ends_with($host, $s)) {
                return true;
            }
        }
        foreach (['staging.', 'stage.', 'dev.', 'qa.', 'uat.', 'test.', 'preview.', 'sandbox.'] as $p) {
            if (str_starts_with($host, $p)) {
                return true;
            }
        }
        // Hyphen-dev pattern intentionally omitted: production domains may contain '-dev'
        // (e.g. magento-dev.etechflow.com is a LIVE store, not a bypass host).
        foreach (['.magento.cloud', '.magentocloud.com', '.cloud.magento'] as $s) {
            if (str_ends_with($host, $s)) {
                return true;
            }
        }
        foreach (['.ngrok.io', '.ngrok-free.app', '.loca.lt', '.serveo.net', '.ngrok-free.dev'] as $s) {
            if (str_ends_with($host, $s)) {
                return true;
            }
        }
        return false;
    }
}
