<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var list<array{method: string, regex: string, handler: array{class-string, string}, shop: bool, admin: bool}> */
    private array $routes = [];

    /**
     * @param array{class-string, string} $handler
     * @param bool $shop true = route của một shop, phải xác định được shop (theo tên miền) trước khi vào controller
     * @param bool $admin true = route quản trị, phải đăng nhập đúng shop của tên miền
     */
    public function get(string $pattern, array $handler, bool $shop = false, bool $admin = false): void
    {
        $this->add('GET', $pattern, $handler, $shop, $admin);
    }

    /** @param array{class-string, string} $handler */
    public function post(string $pattern, array $handler, bool $shop = false, bool $admin = false): void
    {
        $this->add('POST', $pattern, $handler, $shop, $admin);
    }

    /** @param array{class-string, string} $handler */
    public function put(string $pattern, array $handler, bool $shop = false, bool $admin = false): void
    {
        $this->add('PUT', $pattern, $handler, $shop, $admin);
    }

    /** @param array{class-string, string} $handler */
    private function add(string $method, string $pattern, array $handler, bool $shop, bool $admin): void
    {
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#',
            'handler' => $handler,
            'shop' => $shop,
            'admin' => $admin,
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
            if ($route['shop'] || $route['admin']) {
                CurrentShop::resolve($req);
            }
            if ($route['admin']) {
                CurrentAdmin::resolve($req);
            }
            [$class, $action] = $route['handler'];
            (new $class())->$action($req, array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));

            return;
        }

        throw new HttpException($pathMatched ? 405 : 404, $pathMatched ? 'Phương thức không được hỗ trợ' : 'Không tìm thấy đường dẫn');
    }
}
