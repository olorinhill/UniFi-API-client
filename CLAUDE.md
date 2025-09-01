# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is the UniFi Controller API client class - a PHP library that provides access to Ubiquiti's UniFi Network Application API. It supports both classic software-based controllers and UniFi OS-based consoles.

## Core Architecture

### Main Components

- **`src/Client.php`** - The primary API client class containing all UniFi API methods (4600+ lines)
- **`src/Exceptions/`** - 11+ custom exception classes for granular error handling
- **`examples/`** - PHP usage examples demonstrating API functionality
- **`api/`** - Dockerized REST API wrapper (PHP 8.2 + Slim 4 + nginx) that exposes HTTP endpoints

### Key Design Patterns

- **PSR-4 Autoloading**: Namespace `UniFi_API\` maps to `src/` directory
- **Exception-based Error Handling**: Custom exceptions replace PHP's `trigger_error()`
- **Automatic UniFi OS Detection**: Runtime detection via HTTP 200 response during login
- **Session Management**: Handles cookies, CSRF tokens, and auto-login on 401 responses
- **Automatic Re-authentication**: Detects expired sessions and retries login once

### Client Class Implementation Details

The `Client` class (`src/Client.php`) contains:
- Constructor validation (base URL, site name, cURL extension)
- Login/logout methods with controller type detection
- 200+ API methods covering devices, users, sites, statistics, configuration
- Protected `exec_curl()` wrapper with retry logic
- UniFi OS detection during login (not configuration-based)
- CSRF token extraction from JWT cookies for UniFi OS
- Automatic URL path prefixing (`/proxy/network/`) for UniFi OS
- Cookie differentiation: `unifises` (classic) vs `TOKEN` (UniFi OS)

## Installation & Dependencies

### Composer Installation
```bash
composer require art-of-wifi/unifi-api-client
```

### Requirements
- PHP 7.4.0 or higher
- cURL and JSON PHP extensions
- Network access to UniFi Controller (port 8443 for classic, 443/11443 for UniFi OS)

## Development Commands

### Testing & Quality Tools
**Note**: This project uses minimal traditional PHP tooling:
- No PHPUnit test suite (only `examples/test_connection.php` for basic connectivity)
- No PHPCodeSniffer, Psalm, or static analysis configured
- No CI/CD pipelines or GitHub Actions
- Development is Docker-first for the REST API wrapper

### Running Examples
```bash
# Copy and configure credentials
cp examples/config.template.php examples/config.php
# Edit config.php with your controller details

# Run any example
php examples/list_alarms.php

# Test basic connectivity
php examples/test_connection.php
```

### Docker API Service (Production-Ready)
```bash
# Build and run dockerized REST API
docker compose build
docker compose up -d

# Service runs on port 8181 (nginx -> PHP-FPM on port 9000)
# Uses external 'neuralnoc' network or fallback to 'sharednet'

# Test endpoints
curl -s http://localhost:8181/healthz
curl -H "Authorization: Bearer <token>" http://localhost:8181/clients
curl -X PUT -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"alias":"Device Name"}' \
  http://localhost:8181/clients/{mac}/alias
```

## Configuration

### Basic Client Usage
```php
$client = new UniFi_API\Client(
    $controller_user,
    $controller_password, 
    $controller_url,  // https://controller:8443 or https://unifi-os:443
    $site_id,         // Short site name, usually 8 chars
    $version,         // Controller version
    $ssl_verify       // true for production
);
$client->login();
```

### Environment Variables (Docker API)
- `API_BEARER_TOKEN` - Authentication token for REST endpoints
- `UNIFI_BASE` - Controller URL
- `UNIFI_SITE` - Site ID (default: "default")  
- `UNIFI_USER` / `UNIFI_PASS` - Controller credentials
- `UNIFI_VERIFY_SSL` - SSL certificate validation

## API Patterns

### Exception Handling
The library includes 11+ specific exception types:
- `LoginFailedException` - Invalid credentials
- `LoginRequiredException` - API call made before login
- `CurlTimeoutException` - Network timeout
- `CurlGeneralErrorException` - General cURL errors
- `CurlExtensionNotLoadedException` - Missing PHP cURL extension
- `InvalidSiteNameException` - Invalid site parameter
- `InvalidBaseUrlException` - Malformed controller URL
- `InvalidCurlMethodException` - Invalid HTTP method
- `JsonDecodeException` - Malformed API response
- `EmailInvalidException` - Invalid email format
- `MethodDeprecatedException` - Using deprecated API methods
- `NotAUnifiOsConsoleException` - UniFi OS specific operation on classic controller

### Authentication Flow
1. **Controller Type Detection**: Automatic during login based on HTTP response
2. **Cookie Management**: `unifises` for classic, `TOKEN` for UniFi OS
3. **CSRF Token Handling**: Extracted from JWT for UniFi OS controllers
4. **Auto Re-authentication**: On 401 response, retries login once (`exec_retries` counter)
5. **Session Persistence**: Optional via `$_SESSION` storage

### UniFi OS vs Classic Controllers
- **Classic**: Port 8443, direct API paths, `unifises` cookie
- **UniFi OS**: Port 443/11443, `/proxy/network/` prefix, `TOKEN` cookie, CSRF tokens
- Detection happens at runtime during login (not configuration-based)
- URL paths automatically adjusted based on controller type

## Common Integration Points

### Adding New API Methods
1. Add method to `Client` class following existing patterns
2. Include proper PHPDoc with `@param`, `@return`, and `@throws` annotations
3. Use `exec_curl()` wrapper for HTTP requests (handles retries and auth)
4. Let the base class handle UniFi OS path prefixing automatically
5. Method may automatically switch HTTP method based on payload presence

### Deprecated Methods
The following methods throw `MethodDeprecatedException` and should not be used:
- `list_aps()` → use `list_devices()` instead
- `set_locate_ap()` / `unset_locate_ap()` → use `locate_ap()` with boolean
- `site_ledson()` / `site_ledsoff()` → use `site_leds()` with boolean
- `restart_ap()` → use `restart_device()` instead

### Custom Exception Types
Create in `src/Exceptions/` extending base `Exception` class following existing naming conventions (e.g., `ActionFailedException`).

## Security Considerations

- **SSL Verification**: Disabled by default, **must enable in production** via constructor parameter
- **Credentials**: Stored in client instance memory only (not persisted)
- **Session Management**: Cookies auto-managed with optional `$_SESSION` persistence
- **Debug Mode**: No credentials logged, but verbose cURL output includes headers
- **CSRF Protection**: Automatic token handling for UniFi OS controllers
- **Authentication**: Requires local admin account (no cloud accounts or MFA)
- **Network Security**: Direct network connectivity required to controller

## Docker API Wrapper Details

### Architecture
- **Stack**: PHP 8.2 + Slim 4 Framework + PHP-FPM + nginx
- **Authentication**: Bearer token middleware on all routes except `/healthz`
- **Network**: Supports external Docker networks for container communication
- **Port**: 8181 (nginx) → 9000 (PHP-FPM internal)

### Available REST Endpoints
- `GET /healthz` - Health check (no auth)
- `GET /clients` - List normalized clients
- `PUT /clients/{mac}/alias` - Update client alias

### Docker Compose Configuration
- Main library: PHP 7.4+ requirement
- API wrapper: PHP 8.2+ with Slim 4
- External network: `neuralnoc` (configurable)
- Environment-based configuration for all settings