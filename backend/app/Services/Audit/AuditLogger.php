<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    /**
     * Keys that should always be redacted from audit logs.
     */
    protected array $denylist = [
        'password',
        'password_confirmation',
        'token',
        'api_key',
        'secret',
        'authorization',
        'cookie',
        'content',
        'session',
    ];

    /**
     * Log an audit event.
     *
     * @param string $event
     * @param Model|null $subject
     * @param array $before
     * @param array $after
     * @param array $metadata
     * @return AuditLog
     */
    public function log(string $event, ?Model $subject = null, array $before = [], array $after = [], array $metadata = []): AuditLog
    {
        $request = Request::instance();

        // Default metadata
        $contextMetadata = array_merge([
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'route' => $request->route() ? $request->route()->getName() : null,
        ], $metadata);

        return AuditLog::create([
            'user_id' => $request->user()?->id,
            'event' => $event,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject ? $subject->getKey() : null,
            'before_state' => empty($before) ? null : $this->redact($before),
            'after_state' => empty($after) ? null : $this->redact($after),
            'metadata' => empty($contextMetadata) ? null : $this->redact($contextMetadata),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => $request->attributes->get('request_id', (string) str()->uuid()),
        ]);
    }

    /**
     * Recursively sanitize an array, removing sensitive data.
     */
    protected function redact(array $data): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            // Apply Denylist
            if (is_string($key) && $this->shouldRedactKey($key)) {
                $redacted[$key] = '[REDACTED]';
                continue;
            }

            // Handle arrays recursively
            if (is_array($value)) {
                $redacted[$key] = $this->redact($value);
                continue;
            }

            // Apply Allowlist / Object handling
            // Do not store binary data, file objects, or unexpected closures
            if ($value instanceof UploadedFile) {
                $redacted[$key] = '[FILE UPLOAD]';
                continue;
            }
            
            if (is_object($value)) {
                if (method_exists($value, 'toArray')) {
                    $redacted[$key] = $this->redact($value->toArray());
                } elseif (method_exists($value, '__toString')) {
                    $redacted[$key] = (string) $value;
                } else {
                    $redacted[$key] = '[OBJECT]';
                }
                continue;
            }

            if (is_resource($value)) {
                $redacted[$key] = '[RESOURCE]';
                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    /**
     * Determine if a specific key should be redacted based on the denylist.
     */
    protected function shouldRedactKey(string $key): bool
    {
        $normalizedKey = strtolower($key);
        
        foreach ($this->denylist as $deniedKey) {
            if (str_contains($normalizedKey, $deniedKey)) {
                return true;
            }
        }

        return false;
    }
}
