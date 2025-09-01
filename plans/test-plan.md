## Test Plan — Dockerized UniFi REST API (Slim 4, nginx, port 8181)

### Scope
- Validate the new REST API under `api/` that wraps `art-of-wifi/unifi-api-client`.
- Verify functional behavior, security (Bearer auth), Docker networking, and integration with a live UniFi controller.
- Ensure LAN reachability on port 8181 and service discovery by n8n via shared Docker network.

### Environments
- Local dev: Docker Desktop or Linux Docker Engine.
- UniFi controller: reachable from the API container (classic or UniFi OS). Use local admin account (no MFA/SSO).

### Configuration (matrix)
- UNIFI_VERIFY_SSL: true (prod) and false (dev)
- Controller types: classic (e.g., https://host:8443) and UniFi OS (https://host[:443|11443])
- Network: external `neuralnoc` available vs. fallback `sharednet`

### Prerequisites
- Docker and docker compose installed.
- Env vars set (via shell export, compose `environment`, or `.env` loaded at runtime):
  - API_BEARER_TOKEN, UNIFI_BASE, UNIFI_USER, UNIFI_PASS, UNIFI_SITE (default), UNIFI_VERSION (default 9.0.0), UNIFI_VERIFY_SSL
- Port 8181 available on host.

### Start/Stop
```bash
docker compose build
docker compose up -d
docker compose ps

# shutdown
docker compose down
```

### Smoke Tests
1) Health
```bash
curl -s http://localhost:8181/healthz | jq .
```
Expected: `{ "ok": true }` (HTTP 200)

2) Auth required on non-health endpoints
```bash
curl -i http://localhost:8181/clients | head -n 20
```
Expected: HTTP 401, `WWW-Authenticate: Bearer`

### Functional Tests
1) List clients
```bash
curl -s -H "Authorization: Bearer $API_BEARER_TOKEN" \
  http://localhost:8181/clients | jq '.[0]'
```
Expect: HTTP 200, JSON array of objects with keys: `id, mac, ip, name, hostname, note`.

2) Update client alias by MAC
```bash
MAC=aa:bb:cc:dd:ee:ff
curl -s -X PUT -H "Authorization: Bearer $API_BEARER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"alias":"Kitchen Echo"}' \
  http://localhost:8181/clients/$MAC/alias | jq .
```
Expect: `{ "updated": true }` for a known client; `{ "updated": false }` if not found.

3) Input validation
- Missing body or `alias` field → HTTP 400 `{ "error": "alias is required" }`.
- Invalid MAC format → HTTP 200 with `{ "updated": false }` (no matching user found).

### Security Tests (Bearer)
1) Wrong token
```bash
curl -i -H "Authorization: Bearer WRONG" http://localhost:8181/clients | head -n 20
```
Expect: HTTP 401, `WWW-Authenticate: Bearer`.

2) Missing header → HTTP 401.

3) Header casing / prefix variations
- Ensure `Authorization: Bearer <token>` works with typical casing; others rejected.

### UniFi Connectivity & Error Handling
1) Valid controller & credentials
- `GET /clients` returns 200 with data.

2) Invalid credentials
- Expect HTTP 500 `{ "error": "Internal Server Error" }` and server logs showing login failure.

3) Expired cookie / 401 re-login path
- After prolonged idle, first request should trigger automatic re-login inside client; endpoint still returns 200.

4) Controller unreachable (network/DNS failure)
- Expect HTTP 500; check logs for cURL connection error.

5) SSL validation
- With `UNIFI_VERIFY_SSL=true` and invalid cert/hostname → expect failure (HTTP 500) and logs referencing SSL error.
- With `UNIFI_VERIFY_SSL=false` same target should work (only for testing).

### Docker Networking
1) External `neuralnoc` present
- `docker network ls | grep neuralnoc` shows network.
- Both `api` and `nginx` are attached to it: `docker inspect <service> | jq '.[0].NetworkSettings.Networks'`.

2) Fallback to `sharednet`
- Comment `neuralnoc` and enable `sharednet` in `docker-compose.yml`.
- Rebuild/Up; confirm both services share the same bridge network.

3) LAN reachability
- From another machine: `curl -s http://<docker-host>:8181/healthz` → `{ "ok": true }`.

4) n8n reachability (same Docker network)
- From n8n container (or any container on the network): `curl -s http://nginx/healthz` → `{ "ok": true }`.

### Logging & Observability
- Use `docker compose logs -f api nginx`.
- On errors (5xx), the API logs to stderr with route and exception message.

### Performance (smoke)
Run a light load to ensure stability:
```bash
for i in $(seq 1 50); do \
  curl -s -H "Authorization: Bearer $API_BEARER_TOKEN" http://localhost:8181/clients >/dev/null &
done; wait
```
Expect: No container crashes; consistent 200s.

### Security (general)
- Headers: Ensure only required headers are exposed; content type is `application/json`.
- Rate limiting (optional): document nginx config if added later.
- Secrets: do not log tokens or passwords.

### Compatibility Matrix
- Classic controller (8443): pass
- UniFi OS (443 or 11443): pass (CSRF header auto-injection and `/proxy/network` pathing handled by client)
- PHP 8.2 runtime: pass

### Negative/Edge Cases
- No clients connected: `GET /clients` returns empty array.
- Large client lists: verify response time < 2s in local env.
- Special characters in alias: UTF-8 strings accepted and returned.

### Acceptance Criteria
- `/healthz` returns `{ "ok": true }` from host and LAN.
- Non-health endpoints require Bearer; respond 401 without correct token.
- `GET /clients` returns normalized data.
- `PUT /clients/{mac}/alias` updates alias; returns `{ "updated": true }` on success.
- Works against both classic and UniFi OS controllers.
- LAN and inter-container access verified (port 8181 published; shared network).

### Rollback / Cleanup
```bash
docker compose down -v
```
Optionally remove external network usage changes if you switched from `neuralnoc` to `sharednet` during tests.


