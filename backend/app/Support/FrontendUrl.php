<?php

namespace App\Support;

class FrontendUrl
{
    /**
     * Build an absolute link to a Vue SPA route.
     *
     * Use this for any URL a person will open in a browser — email deep links,
     * activation and reset links. API URLs keep using `url()`, which is correctly
     * based on the API host.
     */
    public static function to(string $path): string
    {
        return rtrim((string) config('atms.frontend_url'), '/').'/'.ltrim($path, '/');
    }
}
