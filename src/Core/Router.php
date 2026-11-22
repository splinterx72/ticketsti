<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Enrutador ligero: rutas GET/POST con parámetros {nombre} numéricos o slug.
 *
 * Funciona tanto en la raíz del dominio (tickets.tudominio.com/) como en una
 * subcarpeta (tudominio.com/tickets/): el prefijo se obtiene de SCRIPT_NAME.
 */
final class Router
{
    /** @var array<string, list<array{pattern: string, handler: array{class-string, string}, params: list<string>}>> */
    private array $routes = ['GET' => [], 'POST' => []];

    /** @param array{class-string, string} $handler */
    public function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /** @param array{class-string, string} $handler */
    public function post(string $path, array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /** @param array{class-string, string} $handler */
    private function add(string $method, string $path, array $handler): void
    {
        $path = '/' . trim($path, '/');
        $params = [];
        $regex = preg_replace_callback(
            '#\{([a-z_]+)\}#',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return $m[1] === 'id' ? '(\d+)' : '([A-Za-z0-9_-]+)';
            },
            $path
        );
        $this->routes[$method][] = [
            'pattern' => '#^' . $regex . '$#',
            'handler' => $handler,
            'params'  => $params,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper($method);
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        $path = self::currentPath($uri);
        $allowedElsewhere = false;

        foreach ($this->routes as $routeMethod => $routes) {
            foreach ($routes as $route) {
                if (preg_match($route['pattern'], $path, $matches) !== 1) {
                    continue;
                }
                if ($routeMethod !== $method) {
                    $allowedElsewhere = true;
                    continue;
                }

                array_shift($matches);
                $args = [];
                foreach ($route['params'] as $i => $name) {
                    $value = $matches[$i] ?? '';
                    $args[$name] = $name === 'id' ? (int) $value : $value;
                }

                [$class, $action] = $route['handler'];
                $controller = new $class();
                $controller->{$action}(...array_values($args));
                return;
            }
        }

        if ($allowedElsewhere) {
            throw new HttpException(405, 'Método no permitido.');
        }
        throw new HttpException(404, 'La página solicitada no existe.');
    }

    /** Ruta relativa a la carpeta donde vive index.php, sin query string. */
    public static function currentPath(string $uri): string
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $path = rawurldecode($path);

        $base = self::basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php'));
        }

        $path = '/' . trim($path, '/');
        return $path;
    }

    /** Prefijo de subcarpeta (ej. "/tickets") o "" si está en la raíz. */
    public static function basePath(): string
    {
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        $dir = rtrim($dir, '/');
        return $dir === '.' ? '' : $dir;
    }
}
