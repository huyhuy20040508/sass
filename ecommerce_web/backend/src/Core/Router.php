<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var list<array{method: string, regex: string, handler: array{class-string, string}}> */
    private array $routes = [];

    /** @param array{class-string, string} $handler */
    public function get(string $pattern, array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    /** @param array{class-string, string} $handler */
    public function post(string $pattern, array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /** @param array{class-string, string} $handler */
    public function put(string $pattern, array $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    /** @param array{class-string, string} $handler */
    public function delete(string $pattern, array $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    /** @param array{class-string, string} $handler */
    private function add(string $method, string $pattern, array $handler): void
    {
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#',
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $req): void
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $req->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $req->method) {
                continue;
            }
            [$class, $action] = $route['handler'];
            (new $class())->$action($req, array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));

            return;
        }

        throw new HttpException($pathMatched ? 405 : 404, $pathMatched ? 'Phương thức không được hỗ trợ' : 'Không tìm thấy đường dẫn');
    }
}
