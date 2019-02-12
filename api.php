<?php

function api_validate(array $cfg, $data)
{
    if (!isset($cfg['api']) || !is_array($cfg['api'])) {
        return false;
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
            http_e400('Required value missing, key: ' . implode($path, ':'));
        }
        return false;
    }

    /* add extra params to validate in special cases */
    $extra = null;
    if ($node['type'] == 'datetime' && isset($node['timezone'])) {
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
    if (!tool_validate($node['type'], $value, true, $extra)) {
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
    }
    /* default is to require */
    return true;
}

function api_node_read(string $name, array $node, &$item, &$data, array $path)
{
    $value = null;
    if (is_array($item)) {
        array_pop($path);
        $from = $item;
        foreach ($path as $p) {
            if (!isset($from[$p]) || !is_array($from[$p])) {
                http_e500('Internal error, trying to read missing value from array, stopped at: ' . $p . ', full key: ' . implode($path, ':'));
            }
            $from = $from[$p];
        }
        $value = $from[$name];
    } else {
        http_e501('Not implemented');
    }
    $data[$name] = $value;
}
