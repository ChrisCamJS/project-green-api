<?php
namespace App;

class Router {
    protected $routes = [];

    public function add($method, $route, $controller, $action) {
        $this->routes[] = [
            'method' => $method,
            'route' => $route,
            'controller' => $controller,
            'action' => $action
        ];
    }

    public function dispatch($uri, $method) {
        // Strip trailing slashes so /login and /login/ both resolve correctly
        $uri = rtrim($uri, '/');
        if (empty($uri)) {
            $uri = '/';
        }

        foreach ($this->routes as $route) {
            $registeredRoute = rtrim($route['route'], '/');
            if (empty($registeredRoute)) {
                $registeredRoute = '/';
            }

            if ($route['method'] === $method && $registeredRoute === $uri) {
                $controllerName = "App\\Controllers\\" . $route['controller'];
                $controller = new $controllerName();
                $action = $route['action'];
                return $controller->$action();
            }
        }
        http_response_code(404);
        echo json_encode(["message" => "Endpoint not found"]);
    }
}