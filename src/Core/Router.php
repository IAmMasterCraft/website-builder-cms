<?php
declare(strict_types=1);

namespace Core;

/**
 * Tiny pattern router. Patterns use {name} (one path segment) or {name:regex};
 * the regex may contain quantifiers such as {64} or {6,40}.
 * Routes are matched in the order they were added.
 */
final class Router
{
    /** @var list<array{method:string, pattern:string, regex:string, handler:mixed}> */
    private array $routes = [];

    /** @return list<array{method:string, pattern:string, handler:mixed}> every route, in order (tests enumerate them) */
    public function routes(): array
    {
        return array_map(static fn (array $r): array => ['method' => $r['method'], 'pattern' => $r['pattern'], 'handler' => $r['handler']], $this->routes);
    }

    public function get(string $pattern, mixed $handler): self
    {
        return $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, mixed $handler): self
    {
        return $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, mixed $handler): self
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $pattern,
            'regex'   => self::compile($pattern),
            'handler' => $handler,
        ];
        return $this;
    }

    public static function compile(string $pattern): string
    {
        $out = '';
        $offset = 0;
        if (preg_match_all('/\{([a-z_][a-z0-9_]*)(?::((?:[^{}]|\{\d+(?:,\d*)?\})+))?\}/i', $pattern, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $out .= preg_quote(substr($pattern, $offset, $match[0][1] - $offset), '#');
                $regex = isset($match[2]) && $match[2][0] !== '' ? $match[2][0] : '[^/]+';
                $out .= '(?P<' . $match[1][0] . '>' . $regex . ')';
                $offset = $match[0][1] + strlen($match[0][0]);
            }
        }
        $out .= preg_quote(substr($pattern, $offset), '#');
        return '#^' . $out . '$#u';
    }

    /**
     * @return array{handler:mixed, params:array<string,string>}
     * @throws HttpException 404 when nothing matches, 405 when only the method differs
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method);
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        $path = self::normalise($path);
        $methodMismatch = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $methodMismatch = true;
                continue;
            }
            $params = [];
            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $params[$k] = rawurldecode($v);
                }
            }
            return ['handler' => $route['handler'], 'params' => $params];
        }
        throw new HttpException($methodMismatch ? 405 : 404);
    }

    public static function normalise(string $path): string
    {
        $path = '/' . trim($path, '/');
        return preg_replace('#/+#', '/', $path) ?? '/';
    }
}
