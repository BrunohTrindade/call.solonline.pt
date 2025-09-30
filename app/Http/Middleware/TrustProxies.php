<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     * You can set a comma-separated list in TRUSTED_PROXIES env.
     * Example: 127.0.0.1,10.0.0.0/8
     * Use "*" to trust all (only if you are behind a CDN/ELB that you trust).
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    public function __construct()
    {
        $value = env('TRUSTED_PROXIES');
        if (is_string($value) && trim($value) !== '') {
            $this->proxies = array_map('trim', explode(',', $value));
        } else {
            $this->proxies = env('TRUSTED_PROXIES', null);
        }
    }
}
