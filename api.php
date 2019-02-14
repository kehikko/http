<?php

/*
 * Divided into section: input validation, input write, output validation
 */

/*
 * API input validation
 */

function api_validate(array $cfg, $data, array $args = [])
{
    if (!isset($cfg['api']) || !is_array($cfg['api'])) {
        http_e500('Invalid api validate request, api description is missing for given route');
    }

    $mode = null;
    if (http_using_method(['post'])) {
        $mode = 'c'; /* create */
    } else if (http_using_method(['put', 'patch'])) {
        $mode = 'w'; /* write (update etc) */
    } else {
        http_e500('Invalid api validate request, http method used: ' . http_method() . ', should be one of: post, put, patch');
    }

    return api_validate_nodes($cfg['api'], $data, [], $mode);
}

function api_validate_nodes(array $nodes, $data, array $path, $mode)
{
    $return_data = [];
    foreach ($nodes as $name => $node) {
        array_push($path, $name);
        if (!is_array($node)) {
            log_error('Invalid api description, key: {0}, type: {1}, type should be array', [implode($path, ':'), gettype($node)]);
        } else if (isset($node['type']) && is_string($node['type'])) {
            api_validate_node($name, $node, $data, $path, $mode, $return_data);
        } else if (!isset($data[$name]) || !is_array($data[$name])) {
            api_validate_nodes($node, null, $path, $mode);
        } else {
            $value = api_validate_nodes($node, $data[$name], $path, $mode);
            if (!empty($value)) {
                $return_data[$name] = $value;
            }
        }
        array_pop($path);
    }
    return $return_data;
}

function api_validate_node(string $name, array $node, $data, array $path, $mode, array &$return_data)
{
    /* check data existence and requirement */
    if (!is_array($data) || !array_key_exists($name, $data)) {
        if (api_node_required($node, $mode)) {
            http_e400('Required api value missing, key: ' . implode($path, ':'));
        }
        return false;
    }

    /* add extra params to validate in special cases */
    $extra = null;
    if (validate_has_type($node['type'], 'datetime') && isset($node['timezone'])) {
        if (is_string($node['timezone'])) {
            try {
                $extra = new DateTimeZone(tr($node['timezone']));
            } catch (Throwable $e) {
                log_error('Invalid api description, timezone is not valid for key: {0}, error: {1}', [implode($path, ':'), $e->getMessage()]);
            }
        } else {
            log_error('Invalid api description, timezone is not a string for key: ' . implode($path, ':'));
        }
    }

    /* validate data */
    $value = $data[$name];
    if (!validate($node['type'], $value, true, $extra)) {
        http_e400('Invalid value for key: ' . implode($path, ':'));
    }
    /* create args for possible calls */
    $args = ['_api_node' => ['name' => $name, 'data' => $node, 'path' => $path]];
    /* check min/max */
    if (isset($node['min'])) {
        $min = isset($node['min']['call']) ? tool_call($node['min'], $args) : $node['min'];
        $min = is_string($min) && !is_numeric($min) ? tr($min) : $min;
        if (!is_numeric($min)) {
            log_error('Invalid api description, guard "min" should be a number or number returned by call, it is not for key: {0}, it is type: {1}', [implode($path, ':'), gettype($min)]);
        } else if (!is_numeric($value) || $value < $min) {
            http_e400('Invalid number value (under minimum) for key: ' . implode($path, ':'));
        }
    }
    if (isset($node['max'])) {
        $max = isset($node['max']['call']) ? tool_call($node['max'], $args) : $node['max'];
        $max = is_string($max) && !is_numeric($max) ? tr($max) : $max;
        if (!is_numeric($max)) {
            log_error('Invalid api description, guard "max" should be a number or number returned by call, it is not for key: {0}, it is type: {1}', [implode($path, ':'), gettype($max)]);
        } else if (!is_numeric($value) || $value > $max) {
            http_e400('Invalid number value (over maximum) for key: ' . implode($path, ':'));
        }
    }
    /* check accepted values */
    if (isset($node['accept'])) {
        $accept = isset($node['accept']['call']) ? tool_call($node['accept'], $args) : $node['accept'];
        if (!is_array($accept)) {
            log_error('Invalid api description, guard "accept" should be an array or array returned by call, it is not for key: {0}, it is type: {1}', [implode($path, ':'), gettype($accept)]);
        } else if (!in_array($value, $accept, true)) {
            http_e400('Invalid value (not in accepted values) for key: ' . implode($path, ':'));
        }
    }

    /* write data into item */
    $return_data[$name] = $value;
}

/*
 * API input write
 */

function api_write(array $cfg, $data, array $args = [])
{
    if (!isset($cfg['api']) || !is_array($cfg['api'])) {
        http_e500('Invalid api write request, api description is missing for given route');
    }

    $mode = null;
    if (http_using_method(['post'])) {
        $mode = 'c'; /* create */
    } else if (http_using_method(['put', 'patch'])) {
        $mode = 'w'; /* write (update etc) */
    } else {
        http_e500('Invalid api write request, http method used: ' . http_method() . ', should be one of: post, put, patch');
    }

    return api_write_nodes($cfg['api'], $data, [], $mode, $args);
}

function api_write_nodes(array $nodes, $data, array $path, $mode, array $args)
{
    foreach ($nodes as $name => $node) {
        array_push($path, $name);
        if (!is_array($node)) {
            log_error('Invalid api description, key: {0}, type: {1}, type should be array', [implode($path, ':'), gettype($node)]);
        } else if (isset($node['type']) && is_string($node['type'])) {
            api_write_node($name, $node, $data, $path, $args);
        } else if (isset($data[$name]) && is_array($data[$name])) {
            api_write_nodes($node, $data[$name], $path, $mode, $args);
        }
        array_pop($path);
    }
}

function api_write_node(string $name, array $node, $data, array $path, array $args)
{
    /* write data */
    if (isset($node['set']) && is_string($node['set'])) {
        array_unshift($args, $data[$name]);
        tool_call(['call' => $node['set']], $args);
    }
}

/*
 * API output validation
 */

function api_read(array $cfg, $data, array $args = [])
{
    if (!isset($cfg['api']) || !is_array($cfg['api'])) {
        http_e500('Invalid api read request, api description is missing for given route');
    }
    return api_read_nodes($cfg['api'], $data, [], $args);
}

function api_read_nodes(array $nodes, $data, array $path, array $args)
{
    $return_data = [];
    foreach ($nodes as $name => $node) {
        array_push($path, $name);
        if (!is_array($node)) {
            log_error('Invalid api description, key: {0}, type: {1}, type should be array', [implode($path, ':'), gettype($node)]);
        } else if (isset($node['type']) && is_string($node['type'])) {
            $return_data[$name] = api_read_node($name, $node, $data, $path, $args);
        } else if (!isset($data[$name]) || !is_array($data[$name])) {
            api_read_nodes($node, null, $path, $args);
        } else {
            $value = api_read_nodes($node, $data[$name], $path, $args);
            if (!empty($value)) {
                $return_data[$name] = $value;
            }
        }
        array_pop($path);
    }
    return $return_data;
}

function api_read_node(string $name, array $node, $data, array $path, array $args)
{
    /* retrieve data */
    $value = null;
    if (isset($node['get']) && is_string($node['get'])) {
        $value = tool_call(['call' => $node['get']], $args);
    } else if (!is_array($data) || !array_key_exists($name, $data)) {
        if (api_node_required($node, 'r')) {
            http_e400('Required api value missing, key: ' . implode($path, ':'));
        }
        return null;
    } else {
        $value = $data[$name];
    }

    /* add extra params to validate in special cases */
    $extra = null;
    if (validate_has_type($node['type'], 'datetime') && isset($node['timezone'])) {
        if (is_string($node['timezone'])) {
            try {
                $extra = new DateTimeZone(tr($node['timezone']));
            } catch (Throwable $e) {
                log_error('Invalid api description, timezone is not valid for key: {0}, error: {1}', [implode($path, ':'), $e->getMessage()]);
            }
        } else {
            log_error('Invalid api description, timezone is not a string for key: ' . implode($path, ':'));
        }
    }

    /* format value only if it is a string or number */
    if (isset($node['format']) && is_string($node['format']) && (is_string($value) || is_numeric($value))) {
        /* format value with user specified modifier */
        $value = sprintf($node['format'], $value);
    }

    /* validate value */
    if (!validate($node['type'], $value, true, $extra)) {
        http_e400('Invalid value for key: ' . implode($path, ':'));
    }

    /* conditionally modify output value */
    if (is_object($value)) {
        /* convert object to string */
        if (is_a($value, 'DateTime')) {
            /* default to ISO 8601 */
            $format = isset($node['format']) && is_string($node['format']) ? $node['format'] : 'c';
            $value  = $value->format($format);
        } else if (!method_exists($value, '__toString')) {
            $value = '<object>';
        }
    }

    return $value;
}

/* check if node is required in given mode */
function api_node_required(array $node, $mode)
{
    /* default is to require data to be set */
    if (!isset($node['required']) || (!is_bool($node['required']) && !is_array($node['required']))) {
        return true;
    }
    /* default for both, create and write */
    if (is_bool($node['required'])) {
        return $node['required'];
    }
    /* either or both defined separately */
    if ($mode == 'c' && isset($node['required']['create']) && is_bool($node['required']['create'])) {
        return $node['required']['create'];
    } else if ($mode == 'w' && isset($node['required']['update']) && is_bool($node['required']['update'])) {
        return $node['required']['update'];
    } else if ($mode == 'r' && isset($node['required']['read']) && is_bool($node['required']['read'])) {
        return $node['required']['read'];
    }
    /* default is to require */
    return true;
}
