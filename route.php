<?php

function route(string $uri = null)
{
    if ($uri === null) {
        $uri = $_SERVER['REQUEST_URI'];
    }
    $routes   = route_init();
    $url_path = explode('/', trim(parse_url($uri, PHP_URL_PATH), '/'));
    if ($url_path[0] === '') {
        $url_path = [];
    }
    return route_find($routes['base'], $url_path);
}

function route_render(string $uri = null)
{
    $route = route($uri);
    if (!empty($route)) {
        return route_get_content($route);
    }
    return null;
}

function route_execute(string $uri = null)
{
    /**
     * @todo rewrite and divide this horrible function into smaller pieces
     */

    $code    = 200;
    $content = null;
    $route   = route($uri);
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

    try {
        if (isset($route['_api']) && $route['_api'] === true) {
            /* api request */
            if (http_using_method(['post', 'put', 'patch'])) {
                /* validate incoming api data */
                $data = api_validate($route, http_request_payload_json(), $route['args']);
                api_write($route, $data, $route['args']);
            }
            /* outgoing api data parsing */
            $content = api_read($route, $route['args']);
        } else {
            /* ordinary http request */
            $content = route_get_content($route);
            if ($content !== null) {
                /* we are fine, content was delivered */
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
        }
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
        log_if_error($code >= 500, '{file}:{line}: route_execute() failed, request uri: "{uri}", error: {msg}', ['file' => $e->getFile(), 'line' => $e->getLine(), 'msg' => $e->getMessage(), 'uri' => $_SERVER['REQUEST_URI']]);
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

function route_get_content(array $route)
{
    /* resolve where to get data */
    if (isset($route['call']) && is_string($route['call'])) {
        return tool_call($route);
    } else if (isset($route['content'])) {
        return $route['content'];
    }
    return null;
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

    /* auto-expand strings: expansion result can be other than string if only singular value is pointed at and it is not a string */
    $expand = function (&$c) use (&$expand) {
        if (is_array($c)) {
            foreach ($c as &$subc) {
                $expand($subc);
            }
        } else if (is_string($c)) {
            /* do auto-expansion at most five(5) times */
            for ($i = 0; $i < 5 && preg_match_all('/{[a-zA-Z0-9.\\\\]+}/', $c, $matches, PREG_OFFSET_CAPTURE) > 0; $i++) {
                $replaced = 0;
                $parts    = [];
                $left     = 0;
                foreach ($matches[0] as $match) {
                    $key     = trim($match[0], '{}');
                    $parts[] = substr($c, $left, $match[1] - $left);
                    $parts[] = cfg($key, $match[0]);
                    $left    = $match[1] + strlen($match[0]);
                    $replaced++;
                }
                $left = substr($c, $left);
                if ($replaced == 1 && $left == '' && $parts[0] == '' && !is_string($parts[1])) {
                    /* only singlular replacement and it pointed to non-string value, set directly */
                    $c = $parts[1];
                    break;
                } else {
                    /* string value replacement */
                    $c = implode('', $parts) . $left;
                }
            }
        }
    };
    $expand($data);

    /* reverse whole array, we want it this way for routes to match in the correct order */
    $data = array_reverse($data);

    /* add path */
    foreach ($data as &$route) {
        $route['_path'] = dirname($route_file);
    }

    return $data;
}

function route_find($routes, $url_path)
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

        /* parse get args */
        $get_args = route_parse_get($route);

        /* try to match route */
        $values = route_match($route, $pattern, $url_path, $get_args);
        if (!$values) {
            continue;
        }
        $values['args'] = array_merge($values['args'], $get_args);

        /* this was a match, check what kind of match */
        $route = array_replace_recursive($route, $values);
        if ($route['_final'] && !isset($route['route'])) {
            if (isset($route['redirect']) || isset($route['call']) || isset($route['content']) || isset($route['api'])) {
                /* return route */
                return $route;
            }
        } else if (isset($route['route'])) {
            /* if route is not absolute path, prepend it with root path */
            $route_path = $route['route'];
            if (substr($route_path, 0, 1) !== '/') {
                $route_path = cfg(['path', 'root']) . $route['route'];
            }

            /* load and try to match subroute */
            $subroutes = route_load($route_path . '/route.yml');
            $subroute  = route_find($subroutes, $route['_url_path']);
            if (!empty($subroute)) {
                /* return subroute */
                return $subroute;
            }
        }
    }

    return [];
}

function route_match($route, $pattern, $url_path, $get_args)
{
    $i              = -1;
    $values         = ['args' => [], '_final' => true, '_url_path' => []];
    $after_optional = false;

    /* now loop through all parts in pattern and check them against path */
    foreach ($pattern as $i => $part) {
        if (substr($part, 0, 1) === '%' && substr($part, -1) === '%') {
            /* remove % characters from start and end */
            $part = substr($part, 1, -1);

            /* get variable name and optional filters/calls */
            list($name, $filters) = route_parse_var($route, $part);
            if (empty($name)) {
                http_e500('Variable name missing');
            }

            /* get filters/calls */
            $optional = false;
            $subparts = explode('|', $filters, 2);
            if (count($subparts) == 2) {
                $optional       = true;
                $after_optional = true;
                /* if path is missing for slug, use secondary option for it */
                $filters = isset($url_path[$i]) ? $subparts[0] : $subparts[1];
            }

            /* if not enough parts */
            if (!$optional && !isset($url_path[$i])) {
                return false;
            }

            /* more complex slug parsing */
            $validations = explode(';', $filters);
            $value       = isset($url_path[$i]) ? $url_path[$i] : null;
            foreach ($validations as $validate) {
                if ($validate == '') {
                    /* empty string, not really an invalid thing, just skip */
                    continue;
                } else if (strpos($validate, 'call:') === 0) {
                    try {
                        if ($value !== null) {
                            $value = tool_call(['call' => substr($validate, 5)], array_merge([$name => $value], $get_args));
                        } else {
                            $value = tool_call(['call' => substr($validate, 5)], $get_args);
                        }
                    } catch (Exception $e) {
                        return false;
                    }
                } else if ($validate === 'rest') {
                    $values['args'][$name] = implode('/', array_slice($url_path, $i));
                    return $values;
                } else if (!validate($validate, $value)) {
                    return false;
                }
            }

            $values['args'][$name] = $value;
        } else {
            /* check for static parts */
            if ($after_optional && !isset($url_path[$i])) {
                /* do nothing, static part not present after optional */
            } else if (!isset($url_path[$i]) || $url_path[$i] !== $part) {
                /* static part does not match */
                return false;
            }
        }
    }

    if (($i + 1) < count($url_path)) {
        $values['_final']    = false;
        $values['_url_path'] = array_slice($url_path, $i + 1);
    }

    return $values;
}

function route_parse_get($route)
{
    /* check if there are any GET definitions */
    $gets = explode('&', parse_url($route['pattern'], PHP_URL_QUERY));
    if (empty($gets[0])) {
        /* no GET parameters defined */
        return [];
    }

    /* loop through and check each */
    $args = [];
    foreach ($gets as $get) {
        list($name, $filters) = route_parse_var($route, $get);
        if (empty($name)) {
            continue;
        }
        $args[$name] = null;
        if (!isset($_GET[$name])) {
            continue;
        }

        $validations = explode(';', $filters);
        $value       = $_GET[$name];
        $valid       = true;
        foreach ($validations as $validate) {
            if ($validate == '') {
                /* empty string, not really an invalid thing, just skip */
                continue;
            } else if (strpos($validate, 'call:') === 0) {
                try {
                    $value = tool_call(['call' => substr($validate, 5)], [$value]);
                } catch (Exception $e) {
                    $valid = false;
                    break;
                }
            } else if (!validate($validate, $value)) {
                $valid = false;
                break;
            }
        }

        $args[$name] = $valid ? $value : null;
    }

    return $args;
}

function route_parse_var($route, $var)
{
    /* parse name and get filters after it if any */
    list($name, $filters) = array_pad(explode('=', $var, 2), 2, '');
    if (empty($name)) {
        log_error('Route definition is missing variable name, pattern: {0}', [$route['pattern']]);
        return [null, null];
    }

    /* if route has no separate filter definitions for this variable (they override any defined after variable) */
    if (!isset($route['filters'][$name])) {
        return [$name, $filters];
    }

    /* if defined as string */
    if (is_string($route['filters'][$name])) {
        return [$name, $route['filters'][$name]];
    }

    /* more complex filter definition */
    $filters = '';

    /* if defined with non-optional and optional parts separate */
    if (isset($route['filters'][$name]['defined'])) {
        if (is_string($route['filters'][$name]['defined'])) {
            $filters .= $route['filters'][$name]['defined'];
        } else if (is_array($route['filters'][$name]['defined'])) {
            $filters .= implode($route['filters'][$name]['defined'], ';');
        }
    }
    if (isset($route['filters'][$name]['undefined'])) {
        if (is_string($route['filters'][$name]['undefined'])) {
            $filters .= '|' . $route['filters'][$name]['undefined'];
        } else if (is_array($route['filters'][$name]['undefined'])) {
            $filters .= '|' . implode($route['filters'][$name]['undefined'], ';');
        }
    }

    if (!empty($filters)) {
        return [$name, $filters];
    }

    /* invalid definition */
    log_error('Route variable has invalid filters section, variable: {0}, pattern: {1}', [$var, $route['pattern']]);
    return [null, null];
}

function route_test_request_cmd($cmd, $args, $options)
{
    $_SERVER['REQUEST_METHOD'] = strtoupper($args['method']);
    $_SERVER['REQUEST_URI']    = $args['url'];
    if ($args['payload']) {
        $GLOBALS['__kehikko_term_payload__'] = $args['payload'];
    } else if ($options['file']) {
        $GLOBALS['__kehikko_term_payload__'] = @file_get_contents($options['file']);
        if ($GLOBALS['__kehikko_term_payload__'] === false) {
            log_error('Unable to read request payload from file {0}', [$options['file']]);
            return false;
        }
    }
    route_execute();
    return true;
}
