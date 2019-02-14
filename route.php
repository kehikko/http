<?php

function route()
{
    $routes = route_init();
    $path   = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
    if ($path[0] === '') {
        $path = [];
    }
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
    $content = null;
    $route   = route();
    $success = true;
    $msg     = '';
    $trace   = [];

    /* set code if missing */
    if (isset($route['code']) && is_int($route['code'])) {
        $code = $route['code'];
    }
    /* add empty headers array if not set */
    if (!isset($route['headers']) || !is_array($route['headers'])) {
        $route['headers'] = [];
    }
    /* force format as json for api requests */
    if (isset($route['_api']) && $route['_api'] === true) {
        $route['format'] = 'json';
    } else if (!isset($route['format']) || !is_string($route['format'])) {
        /* format missign, defaults to html */
        $route['format'] = 'html';
    }

    /* resolve where to get data */
    if (isset($route['call']) && is_string($route['call'])) {
        try {
            $content = tool_call($route);
        } catch (Throwable $e) {
            $code = $e->getCode();
            /* if return code is outside http codes, this is an internal error */
            if ($code < 100 || $code >= 600) {
                $code = 500;
                $msg  = cfg_debug() ? $e->getMessage() : 'Internal server error, see log for details.';
            } else {
                /* message should be already formatted so that it can be shown to user */
                $msg = $e->getMessage();
            }
            $success = false;
            if (cfg_debug()) {
                foreach ($e->getTrace() as $n => $t) {
                    $trace[] = array(
                        'file' => isset($t['file']) ? $t['file'] : false,
                        'line' => isset($t['line']) ? $t['line'] : false,
                    );
                }
            }
            /* write all internal errors to log */
            log_if_error($code >= 500, '{file}:{line}: route_execute() failed when calling {call}, request uri: "{uri}", error: {msg}', ['call' => $route['call'], 'file' => $e->getFile(), 'line' => $e->getLine(), 'msg' => $e->getMessage(), 'uri' => $_SERVER['REQUEST_URI']]);
        }
    } else if (isset($route['content'])) {
        $content = $route['content'];
    } else if (isset($route['redirect']) && is_string($route['redirect'])) {
        if (strpos($route['redirect'], 'http://') === 0 || strpos($route['redirect'], 'https://') === 0) {
            http_response_code($code >= 300 && $code < 400 ? $code : 302);
            header('Location: ' . $route['redirect']);
            return true;
        } else {
            $code    = 501;
            $success = false;
            $msg     = 'Internal redirect not implemented';
        }
    } else {
        $code    = 404;
        $success = false;
        $msg     = 'Not found.';
    }

    /* modify content */
    if ($route['format'] == 'json') {
        $route['headers']['Content-Type'] = 'application/json';
        if (!$success) {
            $content = ['msg' => ''];
            if (!empty($msg)) {
                $content['msg'] = $msg;
            }
            if (!empty($trace)) {
                $content['trace'] = $trace;
            }
        }
        $content = json_encode($content);
    } else if (!$success) {
        $content = '<h1>Error: ' . $code . '</h1><p>' . $msg . '</p>';
    } else if (!is_string($content)) {
        $code    = 500;
        $content = '<h1>Error: ' . $code . '</h1><p>' . (empty($content) ? 'No content.' : 'Trying to render invalid content.') . '</p>';
    }

    /* set response code */
    if (tool_is_http_request()) {
        http_response_code($code);
    } else {
        echo 'Response code: ' . $code . "\n";
    }

    /* add custom headers from route */
    foreach ($route['headers'] as $key => $val) {
        if (tool_is_http_request()) {
            header($key . ': ' . tr($val));
        } else {
            echo $key . ': ' . tr($val) . "\n";
        }
    }

    /* print content */
    if (tool_is_http_request()) {
        echo $content;
    } else {
        echo "\n" . $content . "\n";
    }
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

    $route_files = tool_system_find_files(['route.yml'], [cfg(['path', 'routes']), cfg(['path', 'modules']), cfg(['path', 'vendor'])]);
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
    /* load normal routes */
    $data       = tool_yaml_load([$route_file]);
    $data_local = tool_yaml_load([dirname($route_file) . '/' . basename($route_file, '.yml') . '-local.yml']);
    $data       = array_merge(array_reverse($data), array_reverse($data_local));

    /* load api route files */
    $data_api       = tool_yaml_load([dirname($route_file) . '/' . basename($route_file, '.yml') . '-api.yml']);
    $data_api_local = tool_yaml_load([dirname($route_file) . '/' . basename($route_file, '.yml') . '-api-local.yml']);
    $data_api       = array_merge(array_reverse($data_api), array_reverse($data_api_local));

    /* mark all routes in files with "-api" postfix as api */
    if (is_array($data_api)) {
        foreach ($data_api as $key => $val) {
            if (!is_array($val)) {
                continue;
            }
            $data_api[$key]['_api'] = true;
        }
        $data = array_merge($data, $data_api);
    }

    /* reverse whole array, we want it this way for routes to match in the correct order */
    $data = array_reverse($data);

    return $data;
}

function route_find($routes, $path, $final)
{
    foreach ($routes as $name => $route) {
        if (!isset($route['pattern'])) {
            continue;
        }
        if (isset($route['method']) && (is_array($route['method']) || is_string($route['method']))) {
            if (!http_using_method(is_array($route['method']) ? $route['method'] : [$route['method']])) {
                continue;
            }
        }

        /* try to match route */
        $pattern = explode('/', trim(parse_url($route['pattern'], PHP_URL_PATH), '/'));
        if ($pattern[0] === '') {
            $pattern = [];
        }

        $values = route_match($pattern, $path);
        if (!$values) {
            continue;
        }

        /* this was a match, check what kind of match */
        $route = array_replace_recursive($route, $values);
        if ($route['_final']) {
            if (isset($route['redirect']) || isset($route['call']) || isset($route['content'])) {
                return $route;
            }
        } else if (!$final) {
            $subr = route_init();
            if (isset($subr['sub'][$name])) {
                $route = route_find($subr['sub'][$name], $route['_path'], true);
                if (!empty($route)) {
                    return $route;
                }
            }
        }
    }

    return [];
}

function route_match($pattern, $path)
{
    $i              = -1;
    $values         = ['args' => [], '_final' => true];
    $after_optional = false;

    foreach ($pattern as $i => $part) {
        $static   = true;
        $optional = false;
        $name     = null;
        if (substr($part, 0, 1) === '{' && substr($part, -1) === '}') {
            $part     = substr($part, 1, -1);
            $static   = false;
            $subparts = explode('|', $part, 2);
            if (count($subparts) == 2) {
                $optional       = true;
                $after_optional = true;
                /* if path is missing for slug, use secondary option for it */
                $part = isset($path[$i]) ? $subparts[0] : $subparts[1];
            }
            $part = explode('=', $part, 2);
            $name = empty($part[0]) ? null : $part[0];
            $part = count($part) == 2 ? $part[1] : '';
        }

        /* if not enough parts */
        if (!$optional && !isset($path[$i])) {
            return false;
        }

        /* check for static parts */
        if ($static && !$after_optional) {
            if ($path[$i] !== $part) {
                return false;
            }
            continue;
        }

        /* more complex slug parsing */
        $validations = explode(',', $part);
        $value       = isset($path[$i]) ? $path[$i] : null;
        foreach ($validations as $validate) {
            if ($validate == '') {
                /* empty string, not really and invalid thing, just skip */
                continue;
            } else if (strpos($validate, 'call:') === 0) {
                try {
                    if ($value !== null) {
                        $value = tool_call(['call' => substr($validate, 5)], [$value]);
                    } else {
                        $value = tool_call(['call' => substr($validate, 5)]);
                    }
                } catch (Exception $e) {
                    return false;
                }
            } else if ($validate === 'rest') {
                if ($name !== null) {
                    $values['args'][$name] = implode(array_slice($path, $i), '/');
                } else {
                    $values['args'][] = implode(array_slice($path, $i), '/');
                }
                return $values;
            } else if (!validate($validate, $value)) {
                return false;
            }
        }

        if ($name !== null) {
            $values['args'][$name] = $value;
        } else {
            $values['args'][] = $value;
        }
    }

    if (($i + 1) < count($path)) {
        $values['_final'] = false;
        $values['_path']  = array_slice($path, $i + 1);
    }

    return $values;
}

function route_test_request_cmd($cmd, $args, $options)
{
    $_SERVER['REQUEST_METHOD'] = strtoupper($args['method']);
    $_SERVER['REQUEST_URI']    = $args['url'];
    route_execute();
    return true;
}
