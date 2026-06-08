<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserActivationToken extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'token',
        'token_lookup',
        'type',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function createForUser(User $user, string $type = 'activation'): string
    {
        $plain = Str::random(64);

        static::create([
            'user_id' => $user->id,
            'token' => Hash::make($plain),
            'token_lookup' => hash('sha256', $plain),
            'type' => $type,
            'created_at' => now(),
        ]);

        return $plain;
    }

    public static function findByPlainToken(string $plain, string $type): ?self
    {
        return static::where('type', $type)
            ->where('token_lookup', hash('sha256', $plain))
            ->first();
    }

    public function isExpired(int $hours): bool
    {
        return $this->created_at->addHours($hours)->isPast();
    }

    public function matches(string $plain): bool
    {
        return Hash::check($plain, $this->token);
    }
}
