<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class CustomerPushSubscription extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'personal_access_token_id', 'endpoint_hash', 'subscription', 'revision', 'consented_at'];

    protected $hidden = ['subscription', 'endpoint_hash', 'personal_access_token_id'];

    protected static function booted(): void
    {
        static::creating(function (self $subscription): void {
            $subscription->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['subscription' => 'encrypted:array', 'consented_at' => 'immutable_datetime', 'revision' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }
}
