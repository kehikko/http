<?php

function route()
{
    $routes = route_init();
    $path   = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
    return route_find($routes['base'], $path, false);
}

function route_render()
{
    $route = route();
    if (isset($route['call'])) {
        return tool_call($route);
    }
    return false;
}

function route_execute()
{
    $code    = 200;
    $format  = 'html';
    $content = null;
    $route   = route();
    $success = true;
    $msg     = '';
    $trace   = [];

    if (isset($route['options']['code']) && is_int($route['options']['code'])) {
        $code = intval($route['options']['code']);
    }
    if (isset($route['options']['format']) && is_string($route['options']['format'])) {
        $format = $route['options']['format'];
    }

    if (isset($route['call']) && is_string($route['call'])) {
        try {
            $content = tool_call($route);
        } catch (Throwable $e) {
            $code    = $e->getCode() < 100 ? 500 : $e->getCode();
            $success = false;
            $msg     = $e->getMessage();
            if (cfg_debug()) {
                foreach ($e->getTrace() as $n => $t) {
                    $trace[] = array(
                        'file' => isset($t['file']) ? $t['file'] : false,
                        'line' => isset($t['line']) ? $t['line'] : false,
                    );
                }
            }
            log_err('{file}:{line}: route_execute() failed when calling {call}: {msg} ', ['call' => $route['call'], 'file' => $e->getFile(), 'line' => $e->getLine(), 'msg' => $e->getMessage()]);
        }
    } else if (isset($route['redirect']) && is_string($route['redirect'])) {
        if (strpos($route['redirect'], 'http://') === 0 || strpos($route['redirect'], 'https://') === 0) {
            http_response_code($code >= 300 && $code < 400 ? $code : 302);
            header('Location: ' . $route['redirect']);
            return true;
        } else {
            throw new Exception('internal redirect not implemented');
        }
    } else {
        $code    = 404;
        $success = false;
        $msg     = 'Not found.';
    }

    if ($format == 'json') {
        header('Content-Type: application/json');
        $json = ['success' => $success, 'data' => $success ? $content : null];
        if (!empty($msg)) {
            $json['msg'] = $msg;
        }
        if (!empty($trace)) {
            $json['trace'] = $trace;
        }
        $content = json_encode($json);
    } else if (!$success) {
        $content = '<h1>Error: ' . $code . '</h1><p>' . $msg . '</p>';
    } else if (!is_string($content)) {
        $code    = 500;
        $content = '<h1>Error: ' . $code . '</h1><p>' . (empty($content) ? 'No content.' : 'Trying to render invalid content.') . '</p>';
    }

    http_response_code($code);
    if (isset($route['options']['headers']) && is_array($route['options']['headers'])) {
        foreach ($route['options']['headers'] as $key => $val) {
            header($key . ': ' . $val);
        }
    }
    echo $content;
}

function route_init(string $route_file = null)
{
    static $routes = null;

    if ($routes !== null) {
        return $routes;
    }

    if (!$route_file) {
        $route_file = __DIR__ . '/../../../config/route.yml';
    }

    $routes_base = route_load($route_file);
    if (empty($routes_base)) {
        throw new Exception('base route file is invalid, path: ' . $route_file);
    }
    $routes = ['base' => $routes_base, 'sub' => []];

    $route_files = tool_system_find_files(['route.yml'], [cfg(['paths', 'routes']), cfg(['paths', 'modules']), cfg(['paths', 'vendor'])]);
    foreach ($route_files as $file) {
        $content = route_load($file);
        if (!empty($content)) {
            $routes['sub'][basename(dirname($file))] = $content;
        }
    }

    return $routes;
}

function route_load(string $route_file)
{
    return tool_yaml_load([$route_file, dirname($route_file) . '/' . basename($route_file, '.yml') . '-local.yml']);
}

function route_find($routes, $path, $final)
{
    foreach ($routes as $name => $route) {
        if (!isset($route['pattern'])) {
            continue;
        }

        /* try to match route */
        $pattern = explode('/', trim(parse_url($route['pattern'], PHP_URL_PATH), '/'));
        $values  = route_match($pattern, $path);
        if (!$values) {
            continue;
        }

        /* this was a match, check what kind of match */
        $route = array_replace_recursive($route, $values);
        if ($route['_final']) {
            if (isset($route['redirect']) || isset($route['call'])) {
                return $route;
            }
        } else if (!$final) {
            $subr = route_init();
            if (isset($subr['sub'][$name])) {
                $route = route_find($subr['sub'][$name], $route['_path'], true);
                if ($route) {
                    return $route;
                }
            }
        }
    }

    return false;
}

function route_match($pattern, $path)
{
    $values = ['args' => [], '_final' => true];
    foreach ($pattern as $i => $part) {
        $static   = true;
        $optional = false;
        if (substr($part, 0, 1) === '{' && substr($part, -1) === '}') {
            $static   = false;
            $optional = substr($part, 1, 1) === '*';
            $part     = substr($part, $optional ? 2 : 1, -1);
        }

        /* if not enough parts */
        if (!isset($path[$i])) {
            return $optional ? $values : false;
        }

        /* check for static parts */
        if ($static) {
            if ($path[$i] !== $part) {
                return false;
            }
            continue;
        }

        /* more complex slug parsing */
        $validations = explode(',', $part);
        $value       = $path[$i];
        foreach ($validations as $validate) {
            if (strpos($validate, 'call=') === 0) {
                try {
                    $value = tool_call(['call' => substr($validate, 5)], [$value]);
                    if ($value === false || $value === null) {
                        return false;
                    }
                } catch (Exception $e) {
                    return false;
                }
            } else if (strpos($validate, 'object=') === 0) {
                try {
                    $class = substr($validate, 7);
                    $value = new $class($value);
                } catch (Exception $e) {
                    return false;
                }
            } else if ($validate === 'rest') {
                /* todo */
            } else if (!tool_validate($validate, $value)) {
                return false;
            }
        }
        $values['args'][] = $value;
    }

    if (($i + 1) != count($path)) {
        $values['_final'] = false;
        $values['_path']  = array_slice($path, $i + 1);
    }

    return $values;
}
