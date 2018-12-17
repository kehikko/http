<?php

function http_render()
{
    return 'moi';
}

function http_method()
{
    if (!isset($_SERVER['REQUEST_METHOD'])) {
        log_debug('trying to get http method when http request not made, i.e. when using console, returning "none" as method');
        return 'none';
    }
    return strtolower($_SERVER['REQUEST_METHOD']);
}

function http_using_method(array $methods)
{
    return in_array(http_method(), $methods) ? true : false;
}

function http_exception(int $code, string $message, array $context = [])
{
    /* translate possible references */
    $message = tr($message, $context);
    /* always log >= 500 exceptions, they are internal errors */
    if ($code >= 500) {
        log_err('internal server error with http code ' . $code . ', message: ' . $message);
    } else {
        log_verbose('http exception with code ' . $code . ', message: ' . $message);
    }
    /* throw exception */
    throw new Exception($message, $code);
}

/**
 * Return http code 304 (Not Modified).
 */
function http_e304(string $message = 'Not Modified', array $context = [])
{
    http_exception(304, $message, $context);
}

/**
 * Return http code 400 (Bad Request).
 */
function http_e400(string $message = 'Bad Request', array $context = [])
{
    http_exception(400, $message, $context);
}

/**
 * Return http code 401 (Unauthorized).
 */
function http_e401(string $message = 'Unauthorized', array $context = [])
{
    http_exception(401, $message, $context);
}

/**
 * Return http code 403 (Forbidden).
 */
function http_e403(string $message = 'Forbidden', array $context = [])
{
    http_exception(403, $message, $context);
}

/**
 * Return http code 404 (Not Found).
 */
function http_e404(string $message = 'Not Found', array $context = [])
{
    http_exception(404, $message, $context);
}

/**
 * Return http code 405 (Method Not Allowed).
 */
function http_e405(string $message = 'Method Not Allowed', array $context = [])
{
    http_exception(405, $message, $context);
}

/**
 * Return http code 409 (Conflict).
 */
function http_e409(string $message = 'Conflict', array $context = [])
{
    http_exception(409, $message, $context);
}

/**
 * Return http code 410 (Gone).
 */
function http_e410(string $message = 'Gone', array $context = [])
{
    http_exception(410, $message, $context);
}

/**
 * Return http code 418 (I'm a teapot).
 */
function http_e418(string $message = "I'm a teapot", array $context = [])
{
    http_exception(418, $message, $context);
}

/**
 * Return http code 500 (Internal Server Error).
 */
function http_e500(string $message = 'Internal Server Error', array $context = [])
{
    http_exception(500, $message, $context);
}

/**
 * Return http code 501 (Not Implemented).
 */
function http_e501(string $message = 'Not Implemented', array $context = [])
{
    http_exception(501, $message, $context);
}
