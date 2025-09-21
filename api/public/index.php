<?php

use App\Middleware\BearerAuthMiddleware;
use App\UniFi\UniFiService;
use App\UniFi\PpskService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// Load env from repo root if present, else from api/ if present
if (file_exists(__DIR__ . '/../../.env')) {
    (Dotenv\Dotenv::createImmutable(__DIR__ . '/../../'))->safeLoad();
} elseif (file_exists(__DIR__ . '/../.env')) {
    (Dotenv\Dotenv::createImmutable(__DIR__ . '/..'))->safeLoad();
}

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$displayErrorDetails = (getenv('APP_ENV') !== 'prod');
$app->addErrorMiddleware($displayErrorDetails, true, true);

$bearerToken = getenv('API_BEARER_TOKEN') ?: '';
$app->add(new BearerAuthMiddleware($bearerToken));

$skipAuth = function (Request $request, $handler) {
    return $handler->handle($request->withAttribute('skipAuth', true));
};

$json = function (Response $response, $data, int $status = 200): Response {
    $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
};

$app->get('/healthz', function (Request $request, Response $response) use ($json) {
    return $json($response, ['ok' => true]);
})->add($skipAuth);

$app->get('/clients', function (Request $request, Response $response) use ($json) {
    try {
        $svc = UniFiService::fromEnv();
        $data = $svc->listClients();
        return $json($response, $data);
    } catch (Throwable $e) {
        error_log('GET /clients error: ' . $e->getMessage());
        return $json($response, ['error' => 'Internal Server Error'], 500);
    }
});

$app->put('/clients/{mac}/alias', function (Request $request, Response $response, array $args) use ($json) {
    $mac = $args['mac'] ?? '';
    $payload = (array)$request->getParsedBody();
    $alias = $payload['alias'] ?? '';
    if ($alias === '') {
        return $json($response, ['error' => 'alias is required'], 400);
    }
    try {
        $svc = UniFiService::fromEnv();
        $updated = $svc->setClientAliasByMac($mac, $alias);
        return $json($response, ['updated' => (bool)$updated]);
    } catch (Throwable $e) {
        error_log('PUT /clients/{mac}/alias error: ' . $e->getMessage());
        return $json($response, ['error' => 'Internal Server Error'], 500);
    }
});

// PPSK routes
$app->get('/ppsk', function (Request $request, Response $response) use ($json) {
    try {
        $svc = PpskService::fromEnv();
        $params = $request->getQueryParams();
        $wlanId = $params['wlan_id'] ?? null;
        $ssid   = $params['ssid'] ?? null;
        $data = $svc->listPpsks($wlanId ?: null, $ssid ?: null);
        return $json($response, $data);
    } catch (Throwable $e) {
        error_log('GET /ppsk error: ' . $e->getMessage());
        return $json($response, ['error' => 'Internal Server Error'], 500);
    }
});

$app->post('/ppsk/create', function (Request $request, Response $response) use ($json) {
    $payload = (array)$request->getParsedBody();
    $wlanId = trim((string)($payload['wlan_id'] ?? ''));
    $password = trim((string)($payload['password'] ?? ''));
    $networkId = isset($payload['networkconf_id']) ? trim((string)$payload['networkconf_id']) : null;
    if ($wlanId === '' || $password === '') {
        return $json($response, ['error' => 'wlan_id and password are required'], 400);
    }
    try {
        $svc = PpskService::fromEnv();
        $created = $svc->createPpsk($wlanId, $password, $networkId);
        return $json($response, $created, 201);
    } catch (Throwable $e) {
        $code = ($e instanceof InvalidArgumentException) ? 400 : 500;
        error_log('POST /ppsk/create error: ' . $e->getMessage());
        return $json($response, ['error' => $e->getMessage()], $code);
    }
});

$app->post('/ppsk/revoke', function (Request $request, Response $response) use ($json) {
    $payload = (array)$request->getParsedBody();
    $wlanId = trim((string)($payload['wlan_id'] ?? ''));
    $password = trim((string)($payload['password'] ?? ''));
    if ($wlanId === '' || $password === '') {
        return $json($response, ['error' => 'wlan_id and password are required'], 400);
    }
    try {
        $svc = PpskService::fromEnv();
        $result = $svc->removePpsk($wlanId, $password);
        return $json($response, $result);
    } catch (Throwable $e) {
        error_log('POST /ppsk/revoke error: ' . $e->getMessage());
        return $json($response, ['error' => 'Internal Server Error'], 500);
    }
});

$app->run();


