<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class BearerAuthMiddleware implements MiddlewareInterface
{
    private string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $skipAuth = $request->getAttribute('skipAuth');
        if ($skipAuth === true) {
            return $handler->handle($request);
        }

        $authHeader = $request->getHeaderLine('Authorization');
        if (empty($this->token)) {
            return $this->unauthorized();
        }

        if (preg_match('/^Bearer\s+(.*)$/i', $authHeader, $m)) {
            $provided = trim($m[1]);
            if (hash_equals($this->token, $provided)) {
                return $handler->handle($request);
            }
        }

        return $this->unauthorized();
    }

    private function unauthorized(): Response
    {
        $response = new \Slim\Psr7\Response(401);
        $response = $response->withHeader('WWW-Authenticate', 'Bearer');
        $response->getBody()->write(json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}


