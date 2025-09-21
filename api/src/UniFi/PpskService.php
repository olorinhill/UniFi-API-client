<?php

namespace App\UniFi;

use UniFi_API\Client as UniFiClient;

class PpskService
{
    private UniFiClient $client;

    public function __construct(UniFiClient $client)
    {
        $this->client = $client;
    }

    public static function fromEnv(): self
    {
        $base    = getenv('UNIFI_BASE') ?: '';
        $site    = getenv('UNIFI_SITE') ?: 'default';
        $user    = getenv('UNIFI_USER') ?: '';
        $pass    = getenv('UNIFI_PASS') ?: '';
        $version = getenv('UNIFI_VERSION') ?: '9.0.0';
        $verify  = filter_var(getenv('UNIFI_VERIFY_SSL') ?: 'true', FILTER_VALIDATE_BOOLEAN);

        $client = new UniFiClient($user, $pass, $base, $site, $version, $verify);
        return new self($client);
    }

    private function ensureLogin(): void
    {
        if (!$this->client->get_is_unifi_os() || !$this->client->get_cookie()) {
            $this->client->login();
        }
    }

    /**
     * List PPSKs for all WLANs or a specific WLAN/SSID.
     * @param string|null $wlanId Filter by WLAN _id
     * @param string|null $ssid Filter by SSID name
     * @return array
     */
    public function listPpsks(?string $wlanId = null, ?string $ssid = null): array
    {
        $this->ensureLogin();
        $wlans = $this->client->list_wlanconf();
        $out = [];
        foreach ((array)$wlans as $w) {
            if ($wlanId && (($w->_id ?? '') !== $wlanId)) {
                continue;
            }
            if ($ssid && (strcasecmp((string)($w->name ?? ''), $ssid) !== 0)) {
                continue;
            }
            $keys = isset($w->private_preshared_keys) ? (array)$w->private_preshared_keys : [];
            foreach ($keys as $k) {
                $out[] = [
                    'wlan_id'        => $w->_id ?? null,
                    'ssid'           => $w->name ?? null,
                    'password'       => $k->password ?? null,
                    'networkconf_id' => $k->networkconf_id ?? ($w->networkconf_id ?? null),
                ];
            }
        }
        return $out;
    }

    /**
     * Create a new PPSK entry on the given WLAN.
     * If $networkId is null, uses the WLAN's current networkconf_id.
     */
    public function createPpsk(string $wlanId, string $password, ?string $networkId = null): array
    {
        $password = trim($password);
        if (strlen($password) < 8 || strlen($password) > 63) {
            throw new \InvalidArgumentException('Password must be 8-63 characters.');
        }

        $this->ensureLogin();
        $wlans = $this->client->list_wlanconf();
        $target = null;
        foreach ((array)$wlans as $w) {
            if (($w->_id ?? '') === $wlanId) {
                $target = $w;
                break;
            }
        }
        if (!$target) {
            throw new \RuntimeException('WLAN not found: ' . $wlanId);
        }

        // Ensure array exists
        if (!isset($target->private_preshared_keys) || !is_array($target->private_preshared_keys)) {
            $target->private_preshared_keys = [];
        }

        // Ensure unique per SSID
        foreach ($target->private_preshared_keys as $k) {
            if (($k->password ?? '') === $password) {
                throw new \RuntimeException('PPSK password already exists on this WLAN.');
            }
        }

        $useNetworkId = $networkId ?: ($target->networkconf_id ?? '');
        if (!$useNetworkId) {
            throw new \RuntimeException('networkconf_id must be provided or present on WLAN.');
        }

        $new = (object) [
            'password'       => $password,
            'networkconf_id' => $useNetworkId,
        ];
        $target->private_preshared_keys[] = $new;

        // Persist
        $this->client->set_wlansettings_base($wlanId, $target);

        return [
            'wlan_id'        => $wlanId,
            'ssid'           => $target->name ?? null,
            'password'       => $password,
            'networkconf_id' => $useNetworkId,
            'created'        => true,
        ];
    }

    /**
     * Remove a PPSK by password on the given WLAN.
     */
    public function removePpsk(string $wlanId, string $password): array
    {
        $this->ensureLogin();
        $wlans = $this->client->list_wlanconf();
        $target = null;
        foreach ((array)$wlans as $w) {
            if (($w->_id ?? '') === $wlanId) {
                $target = $w;
                break;
            }
        }
        if (!$target) {
            throw new \RuntimeException('WLAN not found: ' . $wlanId);
        }

        $keys = isset($target->private_preshared_keys) ? (array)$target->private_preshared_keys : [];
        $before = count($keys);
        $filtered = [];
        foreach ($keys as $k) {
            if (($k->password ?? '') !== $password) {
                $filtered[] = $k;
            }
        }
        if (count($filtered) === $before) {
            return [
                'wlan_id'  => $wlanId,
                'removed'  => 0,
                'message'  => 'No PPSK matched the provided password',
            ];
        }

        $target->private_preshared_keys = array_values($filtered);
        $this->client->set_wlansettings_base($wlanId, $target);

        return [
            'wlan_id' => $wlanId,
            'removed' => $before - count($filtered),
        ];
    }
}


