<?php

declare(strict_types=1);

use App\Core\Env;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

require dirname(__DIR__) . '/src/bootstrap.php';

$router = new Router();
(require dirname(__DIR__) . '/src/routes.php')($router);

try {
    $router->dispatch(Request::capture());
} catch (HttpException $e) {
    Response::error($e->status, $e->getMessage(), $e->errors);
} catch (Throwable $e) {
    error_log((string) $e);
    // Chỉ lộ chi tiết lỗi ở máy dev; bản chạy thật có thể mang câu SQL và đường dẫn tệp.
    Response::error(500, Env::get('APP_DEBUG') === 'true' ? $e->getMessage() : 'Lỗi máy chủ, vui lòng thử lại');
}
