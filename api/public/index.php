<?php

use App\Middleware\BearerAuthMiddleware;
use App\UniFi\UniFiService;
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

$app->run();


