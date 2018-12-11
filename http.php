<?php

function http_render()
{
    return 'moi';
}

function http_is()
{
    if (isset($_SERVER['REMOTE_ADDR'])) {
        return true;
    }
    return false;
}

function http_using_method(array $methods)
{
    if (!isset($_SERVER['REQUEST_METHOD'])) {
        log_warn('trying to check http method when http request not made, i.e. when using console, returning false');
        return false;
    }
    return in_array(strtolower($_SERVER['REQUEST_METHOD']), $methods) ? true : false;
}
