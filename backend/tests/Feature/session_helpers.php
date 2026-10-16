<?php

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

/**
 * The next request on session $sessionId ('' for a new session), sent with
 * that session cookie as a browser would. JSON test requests only send cookies
 * withCredentials(), and the test app keeps one session store and its guards
 * between requests, so both are reset first (a real request starts with
 * neither). Use with config(['session.driver' => 'database']).
 */
function onSession(string $sessionId): TestCase
{
    Auth::forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');

    return test()->withCredentials()->withCookie(config('session.cookie'), $sessionId);
}

/** The session id the response set in its session cookie. */
function sessionIdFrom(TestResponse $response): string
{
    return $response->getCookie(config('session.cookie'))->getValue();
}
