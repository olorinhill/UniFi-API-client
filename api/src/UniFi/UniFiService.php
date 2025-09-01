<?php

namespace App\UniFi;

use UniFi_API\Client as UniFiClient;

class UniFiService
{
    private UniFiClient $client;

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

    public function __construct(UniFiClient $client)
    {
        $this->client = $client;
    }

    public function ensureLogin(): void
    {
        if (!$this->client->get_is_unifi_os() || !$this->client->get_cookie()) {
            $this->client->login();
        }
    }

    public function listClients(): array
    {
        $this->ensureLogin();
        $users = $this->client->list_users();
        $normalized = [];
        foreach ((array)$users as $u) {
            $normalized[] = [
                'id'       => $u->_id ?? null,
                'mac'      => $u->mac ?? null,
                'ip'       => $u->last_ip ?? ($u->ip ?? null),
                'name'     => $u->name ?? null,
                'hostname' => $u->hostname ?? null,
                'note'     => $u->note ?? null,
            ];
        }
        return $normalized;
    }

    public function setClientAliasByMac(string $mac, string $alias): bool
    {
        $this->ensureLogin();
        $mac = strtolower(trim($mac));
        $users = $this->client->list_users();
        foreach ((array)$users as $u) {
            if (isset($u->mac) && strtolower($u->mac) === $mac && isset($u->_id)) {
                $id = (string)$u->_id;
                $result = $this->client->edit_client_name($id, $alias);
                return $result !== false;
            }
        }
        return false;
    }
}


