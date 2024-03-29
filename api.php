<?php

/*
 * Divided into sections: input validation, input write, output validation
 */

/*
 * API input validation
 */

function api_validate(array $cfg, $data, array $args = [])
{
    if (!isset($cfg['api']['fields']) || !is_array($cfg['api']['fields'])) {
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

    return api_validate_nodes($cfg['api']['fields'], $data, [], $mode);
}

function api_validate_nodes(array $nodes, $data, array $path, $mode)
{
    $return_data = [];
    foreach ($nodes as $name => $node) {
        array_push($path, $name);
        if (!is_array($node)) {
            log_error('Invalid api description, key: {0}, type: {1}, type should be array', [implode('.', $path), gettype($node)]);
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
        if (api_node_required($node, $mode, implode('.', $path))) {
            http_e400('Required api value missing, key: ' . implode('.', $path));
        }
        return false;
    }

    /* validate data */
    $value = $data[$name];
    if (!validate($node['type'], $value, true, $node, implode('.', $path))) {
        http_e400('Invalid value for key: ' . implode('.', $path));
    }

    /* write data into item */
    $return_data[$name] = $value;
}

/*
 * API input write
 */

function api_write(array $cfg, $data, array $args = [])
{
    if (!isset($cfg['api']['fields']) || !is_array($cfg['api']['fields'])) {
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

    return api_write_nodes($cfg['api']['fields'], $data, [], $mode, $args);
}

function api_write_nodes(array $nodes, $data, array $path, $mode, array $args)
{
    foreach ($nodes as $name => $node) {
        array_push($path, $name);
        if (!is_array($node)) {
            log_error('Invalid api description, key: {0}, type: {1}, type should be array', [implode('.', $path), gettype($node)]);
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
    if (isset($node['set'])) {
        array_unshift($args, $data[$name]);
        if (is_string($node['set'])) {
            $value = tool_call(['call' => $node['set']], $args);
        } else if (is_array($node['set']) && isset($node['set']['call'])) {
            $value = tool_call($node['set'], $args);
        } else {
            log_error('Invalid api node description, "set" must define a call, debug identifier: {0}', [implode('.', $path)]);
            throw new Exception('Failed calling dynamic function, see log for details');
        }
    }
}

/*
 * API output validation
 */

function api_read(array $cfg, array $args = [])
{
    /* check that at least fields have been defined */
    if (!isset($cfg['api']['fields']) || !is_array($cfg['api']['fields'])) {
        http_e500('Invalid api read request, api description is missing for given route');
    }

    /* if loop variable is defined, check that if it exists in args and is an array */
    $loop_var = isset($cfg['api']['loop']) && is_string($cfg['api']['loop']) ? $cfg['api']['loop'] : null;
    if ($loop_var && isset($args[$loop_var]) && is_array($args[$loop_var])) {
        /* furthermore check that loop variable is an indexed array and then loop if it is */
        if (empty(array_diff_key($args[$loop_var], array_keys(array_keys($args[$loop_var]))))) {
            $list = [];
            $loop = $args[$loop_var];
            foreach ($loop as $item) {
                $args[$loop_var] = $item;
                $list[] = api_read_nodes($cfg['api']['fields'], null, [], $args, $loop_var);
            }
            $args[$loop_var] = $loop;
            return $list;
        }
    }

    /* single item reading */
    return api_read_nodes($cfg['api']['fields'], null, [], $args);
}

function api_read_nodes(array $nodes, $data, array $path, array $args, string $loop_var = null)
{
    $return_data = [];
    foreach ($nodes as $name => $node) {
        array_push($path, $name);
        if (!is_array($node)) {
            log_error('Invalid api description, key: {0}, type: {1}, type should be array', [implode('.', $path), gettype($node)]);
        } else if (isset($node['type']) && is_string($node['type'])) {
            $return_data[$name] = api_read_node($name, $node, $data, $path, $args, $loop_var);
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

function api_read_node(
    string $name,
    array $node,
    $data,
    array $path,
    array $args,
    string $loop_var
) {
    /* retrieve data */
    $value = null;
    if (isset($node['get'])) {
        if (is_string($node['get'])) {
            $value = tool_call(['call' => $node['get']], $args);
        } else if (is_array($node['get']) && isset($node['get']['call'])) {
            $value = tool_call($node['get'], $args);
        } else {
            log_error('Invalid api node description, "get" must define a call, debug identifier: {0}', [implode('.', $path)]);
            throw new Exception('Failed calling dynamic function, see log for details');
        }
    } else if (is_string($loop_var) && is_object($args[$loop_var])) {
        $method_name = 'get' . ucfirst($name);
        if (method_exists($args[$loop_var], $method_name)) {
            return $args[$loop_var]->{$method_name}();
        } else if (property_exists($args[$loop_var], $name)) {
            return $args[$loop_var]->{$name};
        }
        http_e400('Required api value missing, key: ' . implode('.', $path));
    } else if (!is_array($data) || !array_key_exists($name, $data)) {
        if (api_node_required($node, 'r', implode('.', $path))) {
            http_e400('Required api value missing, key: ' . implode('.', $path));
        }
        return null;
    } else {
        $value = $data[$name];
    }

    /* validate value */
    if (!validate($node['type'], $value, true, $node, implode('.', $path))) {
        http_e400('Invalid value for key: ' . implode('.', $path));
    }

    /* format value only if it is a string or number */
    if (isset($node['format']) && is_string($node['format']) && (is_string($value) || is_numeric($value))) {
        /* format value with user specified modifier */
        $value = sprintf($node['format'], $value);
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
function api_node_required(array $node, $mode, $identifier)
{
    $required = tool_call_simple($node, 'required');
    /* default is to require data to be set */
    if ($required === null) {
        return true;
    }
    /* default for both, create and write */
    if (is_bool($required)) {
        return $required;
    } else if (!is_array($required)) {
        log_error('Invalid api node description, value for field "required" should be boolean or array, {0} received, debug identifier: {1}', [gettype($required), $identifier]);
        return true;
    }
    /* either or both defined separately */
    if ($mode == 'c' && isset($required['create']) && is_bool($required['create'])) {
        return $required['create'];
    } else if ($mode == 'w' && isset($required['update']) && is_bool($required['update'])) {
        return $required['update'];
    } else if ($mode == 'r' && isset($required['read']) && is_bool($required['read'])) {
        return $required['read'];
    }
    /* default is to require */
    return true;
}
