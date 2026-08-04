<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AllowedEmailDomain implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $allowedDomains = config('atms.allowed_email_domains', ['ldc.com.ly']);
        $domain = strtolower((string) str($value)->after('@'));

        if (! in_array($domain, $allowedDomains, true)) {
            $fail('The :attribute must belong to an allowed domain ('.implode(', ', $allowedDomains).').');
        }
    }
}
