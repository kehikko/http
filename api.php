<?php

function api_single(array $cfg, $item, $data)
{
    if (!isset($cfg['values']) || !is_array($cfg['values'])) {
        return false;
    }

    $mode = 'w';
    if (!is_array($data)) {
        $mode = 'r';
        $data = [];
    }

    api_find_nodes($cfg['values'], $item, $data, [], $mode);
    var_dump($data);
}

function api_find_nodes(array $nodes, &$item, &$data, array $path, $mode)
{
    foreach ($nodes as $name => $node) {
        array_push($path, $name);
        if (!is_array($node)) {
            log_debug('invalid node in api, name: {name}, node type: {type}', ['name' => $name, 'type' => gettype($node)]);
        } else if (isset($node['type']) && is_string($node['type'])) {
            api_node($name, $node, $item, $data, $path, $mode);
        } else if (is_array($node)) {
            if ($mode == 'r') {
                $data[$name] = [];
            }
            if (!isset($data[$name]) || !is_array($data[$name])) {
                echo " ! $name is null\n";
                $null = null;
                api_find_nodes($node, $item, $null, $path, $mode);
            } else {
                api_find_nodes($node, $item, $data[$name], $path, $mode);
            }
        }
        array_pop($path);
    }
}

function api_node(string $name, array $node, &$item, &$data, array $path, $mode)
{
    /* read data from item */
    if ($mode == 'r') {
        api_node_read($name, $node, $item, $data, $path);
        return true;
    }

    /* check data existence and requirement */
    if (!is_array($data) || !array_key_exists($name, $data)) {
        if (api_node_required($node, $mode)) {
            throw new Exception('required value missing, key: ' . implode($path, ':'));
        }
        return false;
    }

    /* validate data */
    if (!tool_validate($node['type'], $data[$name])) {
        throw new Exception('invalid value for key: ' . implode($path, ':'));
    }

    /* write data into item */
    $item[$name] = $data[$name];
}

function api_node_read(string $name, array $node, &$item, &$data, array $path)
{
    $value = null;
    if (is_array($item)) {
        array_pop($path);
        $from = $item;
        foreach ($path as $p) {
            if (!isset($from[$p]) || !is_array($from[$p])) {
                throw new Exception('internal error, trying to read missing value from array, stopped at: ' . $p . ', full key: ' . implode($path, ':'));
            }
            $from = $from[$p];
        }
        $value = $from[$name];
    } else {
        throw new Exception('not implemented');
    }
    $data[$name] = $value;
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
    } else if ($mode == 'w' && isset($node['required']['exists']) && is_bool($node['required']['exists'])) {
        return $node['required']['exists'];
    }
    /* default is to require */
    return true;
}
