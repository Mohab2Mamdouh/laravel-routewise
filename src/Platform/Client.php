<?php

namespace Routewise\Platform;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** The connection to the control plane: on only when a URL and a project token are set. */
final class Client
{
    public function enabled(): bool
    {
        return (string) config('routewise.platform.token') !== '' && (string) config('routewise.platform.url') !== '';
    }

    public function http(int $timeout): PendingRequest
    {
        // The token is a credential: never follow a redirect with it.
        return Http::baseUrl(rtrim((string) config('routewise.platform.url'), '/'))
            ->withToken((string) config('routewise.platform.token'))
            ->acceptJson()
            ->withoutRedirecting()
            ->timeout($timeout);
    }

    /** A cache key that changes with the token, so two projects never share cached rules. */
    public function key(string $name): string
    {
        return 'routewise:'.substr(hash('sha256', (string) config('routewise.platform.token')), 0, 16).':'.$name;
    }
}
