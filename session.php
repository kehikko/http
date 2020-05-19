<?php

function session_init()
{
    static $session = false;

    /* check if session has already been initialized for this http request */
    if ($session !== false) {
        return $session;
    }

    /* start session */
    $session = session_start();
    if (!$session) {
        /* throw internal server error */
        http_e500('Could not create session');
    }

    /* check timeout */
    /* TODO: this code has not been tested or even verified to work properly */
    $timeout = cfg(['session', 'timeout'], 0);
    if ($timeout > 0) {
        if (isset($_SESSION['LAST_ACTIVITY']) && ($_SESSION['LAST_ACTIVITY'] + $timeout) < time()) {
            /* restart session */
            emit('session.timeout');
            session_unset();
            session_destroy();
            $session = session_start();
            session_regenerate_id(true);
            return session_init();
        }
        $_SESSION['LAST_ACTIVITY'] = time();
    }

    return $session;
}
