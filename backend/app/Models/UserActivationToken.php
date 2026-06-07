<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class UserActivationToken extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'token',
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
        $plain = \Illuminate\Support\Str::random(64);

        static::create([
            'user_id' => $user->id,
            'token' => Hash::make($plain),
            'type' => $type,
            'created_at' => now(),
        ]);

        return $plain;
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
