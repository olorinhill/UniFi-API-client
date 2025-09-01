## Cursor Prompt — Build a Dockerized UniFi API (port 8181, LAN accessible, n8n reachable)

You are updating my fork:
`https://github.com/olorinhill/UniFi-API-client` (branch `develop`).

**Goal:** Add a production-quality, always-on **REST API** (PHP 8.2, Slim 4) that wraps the UniFi PHP client in this repo, with Dockerized deployment and a `.env` for local dev. The API must require **Authorization: Bearer <token>** on all routes except `/healthz`.

**Networking requirements (very important):**

* Publish the API via **port 8181** on the host (LAN-accessible): `http://<docker-host>:8181`.
* Do **not** use port 8080 or any of these occupied ports: `5000, 8000, 8001, 8002, 5432, 5678, 8080, 9000, 9443`.
* Ensure **n8n** (already running in Docker) can also reach the API by **service name** on a shared network.

  * If a bridge network named **`neuralnoc`** exists, join that network.
  * Otherwise create/use a named network **`sharednet`** and instruct that n8n should join it.

### High-level requirements

* Do **not** modify existing library code under `src/` beyond autoload wiring if needed.
* Create **`api/`** app (Slim 4) + Bearer auth middleware + service wrapper around the UniFi client.
* Use my **fork** (this repo) as the Composer VCS source for `art-of-wifi/unifi-api-client`, pegged to the `develop` branch.
* Provide Dockerfiles and a root **`docker-compose.yml`** that:

  * Builds and runs **php-fpm** API behind **nginx**.
  * Publishes `8181:80` so I can `curl` from the LAN.
  * Joins the **`neuralnoc`** network if present; otherwise create/use **`sharednet`** and note that n8n should also join it.
* Provide **`.env.example`** at repo root and load env via `vlucas/phpdotenv` in the API.
* Endpoints (JSON, `Content-Type: application/json`):

  * `GET /healthz` → `{ "ok": true }` (no auth)
  * `GET /clients` → list known clients (`list_users`)
  * `PUT /clients/{mac}/alias` with body `{"alias":"New Name"}` → update alias (`edit_client_name` or `set_sta_name`)
  * (Optional) `GET /sites` if available from client

### Project layout to add

```
api/
  composer.json
  composer.lock           (generated)
  Dockerfile
  public/
    index.php
  src/
    Middleware/
      BearerAuthMiddleware.php
    UniFi/
      UniFiService.php
  nginx/
    default.conf
docker-compose.yml        (root, publishes port 8181)
.env.example              (root)
```

### Composer wiring

In `api/composer.json`:

```json
{
  "require": {
    "php": "^8.2",
    "slim/slim": "^4.14",
    "slim/psr7": "^1.6",
    "vlucas/phpdotenv": "^5.6",
    "art-of-wifi/unifi-api-client": "dev-develop as 1.1.x-dev"
  },
  "repositories": [
    { "type": "vcs", "url": "https://github.com/olorinhill/UniFi-API-client" }
  ],
  "autoload": { "psr-4": { "App\\": "src/" } }
}
```

### API code specifics

**Bearer middleware** `api/src/Middleware/BearerAuthMiddleware.php`:

* Read `API_BEARER_TOKEN` from env.
* Allow route-level bypass via request attribute `skipAuth === true` (for `/healthz`).
* Enforce `Authorization: Bearer <token>` or return `401` with `WWW-Authenticate: Bearer`.

**Service wrapper** `api/src/UniFi/UniFiService.php`:

* `fromEnv()` reads:

  * `UNIFI_BASE`, `UNIFI_SITE` (`default`), `UNIFI_USER`, `UNIFI_PASS`
  * `UNIFI_VERSION` (`9.0.0` default)
  * `UNIFI_VERIFY_SSL` (`true` default; boolean)
* Methods:

  * `listClients(): array` → normalized keys: `id`, `mac`, `ip`, `name`, `hostname`, `note`
  * `setClientAliasByMac(string $mac, string $alias): bool`

**Front controller** `api/public/index.php`:

* Boot Slim, Dotenv, routing/body parsing.
* Add Bearer middleware globally; add a tiny per-route middleware to mark `/healthz` as `skipAuth`.
* Routes:

  * `GET /healthz` → `{ "ok": true }`
  * `GET /clients` → uses `UniFiService::listClients()`
  * `PUT /clients/{mac}/alias` → JSON `{ alias }`, calls `setClientAliasByMac`, returns `{ updated: true|false }`
* On exceptions, return `500` `{ "error": "..." }`, log to stderr.

### Docker & Compose (network + port 8181)

**`api/Dockerfile`** (multi-stage):

* Stage 1: `composer:2` install deps.
* Stage 2: `php:8.2-fpm-alpine`, install `ca-certificates`, enable opcache, copy vendor + app, `CMD ["php-fpm","-F"]`.

**`api/nginx/default.conf`**:

* Serve `public/index.php` docroot.
* Proxy `.php` to `api:9000` (php-fpm in the same service).
* Expose port `80`.

**Root `docker-compose.yml`** (publish 8181; share network):

```yaml
version: "3.9"
services:
  api:
    build: ./api
    environment:
      APP_ENV: prod
      API_BEARER_TOKEN: ${API_BEARER_TOKEN}
      UNIFI_BASE: ${UNIFI_BASE}
      UNIFI_SITE: ${UNIFI_SITE:-default}
      UNIFI_VERSION: ${UNIFI_VERSION:-9.0.0}
      UNIFI_USER: ${UNIFI_USER}
      UNIFI_PASS: ${UNIFI_PASS}
      UNIFI_VERIFY_SSL: ${UNIFI_VERIFY_SSL:-true}
    networks:
      - neuralnoc  # if this network exists; else comment this line and use sharednet below
      # - sharednet
    restart: unless-stopped

  nginx:
    image: nginx:1.27-alpine
    volumes:
      - ./api/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    ports:
      - "8181:80"         # published for curl / other hosts (avoid 5000,8000,8001,8002,5432,5678,8080,9000,9443)
    depends_on:
      - api
    networks:
      - neuralnoc  # same note as above
      # - sharednet
    restart: unless-stopped

# If the 'neuralnoc' network doesn't exist in this compose, uncomment the block below
# and attach n8n to sharednet as well.
# networks:
#   sharednet:
#     driver: bridge

# If 'neuralnoc' is an external pre-existing network, declare it like this:
networks:
  neuralnoc:
    external: true
```

> Note: If your environment does **not** have an external `neuralnoc` network, switch both services to `sharednet` (create it here) **and** make sure your n8n stack also joins `sharednet`. From n8n you can call `http://nginx:80/...` (same network) or `http://<docker-host>:8181/...` (published port).

### `.env.example` (root)

```
# API auth
API_BEARER_TOKEN=changeme-dev-token

# UniFi controller
UNIFI_BASE=https://unifi.example.com
UNIFI_SITE=default
UNIFI_VERSION=9.0.0
UNIFI_USER=api_service
UNIFI_PASS=supersecret
UNIFI_VERIFY_SSL=true
```

### Usage & verification

**Run:**

```bash
cp .env.example .env
docker compose build
docker compose up -d
curl -s http://localhost:8181/healthz
```

**From another machine on the LAN (port 8181 is published):**

```bash
curl -s http://<docker-host>:8181/healthz
```

**Auth header (non-health routes):**

```
Authorization: Bearer ${API_BEARER_TOKEN}
```

**List clients (from LAN or host):**

```bash
curl -s -H "Authorization: Bearer $API_BEARER_TOKEN" \
  http://<docker-host>:8181/clients | jq .
```

**Update alias by MAC:**

```bash
curl -s -X PUT -H "Authorization: Bearer $API_BEARER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"alias":"Kitchen Echo"}' \
  http://<docker-host>:8181/clients/aa:bb:cc:dd:ee:ff/alias
```

**n8n (inside Docker):**

* If n8n shares the **neuralnoc** (or **sharednet**) network, call **`http://nginx/clients`**.
* Otherwise, call **`http://<docker-host>:8181/clients`**.

### Acceptance criteria

* `docker compose up -d` starts `api` (php-fpm) and `nginx`.
* `/healthz` returns `{"ok":true}` locally and from another host via `http://<docker-host>:8181/healthz`.
* Non-health endpoints require Bearer and return `401` without it.
* `GET /clients` returns normalized JSON data.
* `PUT /clients/{mac}/alias` updates aliases and returns `{ "updated": true }` on success.
* n8n can access the API either by `http://nginx/...` (same network) or `http://<docker-host>:8181/...` (published port).
* The API builds against **my fork’s `develop` branch** via Composer VCS config.

### Nice-to-haves (optional)

* Add `GET /sites`.
* Add nginx rate-limiting comments.
* Add APCu cache (30–60s TTL) gated by `CACHE_TTL` env.