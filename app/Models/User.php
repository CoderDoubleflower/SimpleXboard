<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Member identity and hard usage limit. Only administrators have login passwords.
 * transfer_enable: null = unlimited, 0 = unavailable, positive = bytes allowed.
 * u/d retain legacy quota accounting; legacy_u/d identify its pre-upgrade portion.
 */
class User extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'v2_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $attributes = [
        'access_mode' => 'all',
        'transfer_enable' => null,
        'expired_at' => null,
        'u' => 0,
        'd' => 0,
        'legacy_u' => 0,
        'legacy_d' => 0,
        'banned' => false,
        'is_admin' => false,
    ];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'banned' => 'boolean',
        'is_admin' => 'boolean',
        'expired_at' => 'integer',
        'transfer_enable' => 'integer',
        'u' => 'integer',
        'd' => 'integer',
        'legacy_u' => 'integer',
        'legacy_d' => 'integer',
        'speed_limit' => 'integer',
        'device_limit' => 'integer',
        'last_reset_at' => 'timestamp',
    ];
    protected $hidden = ['password', 'password_algo', 'password_salt'];

    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null || trim($value) === '' ? null : self::normalizeEmail($value),
        );
    }

    public static function normalizeEmail(string $email): string
    {
        $email = trim($email);
        $email = \Normalizer::normalize($email, \Normalizer::FORM_C) ?: $email;
        $at = strrpos($email, '@');
        if ($at === false) {
            return mb_strtolower($email, 'UTF-8');
        }
        $local = mb_strtolower(substr($email, 0, $at), 'UTF-8');
        $local = \Normalizer::normalize($local, \Normalizer::FORM_C) ?: $local;
        $domain = substr($email, $at + 1);
        $asciiDomain = idn_to_ascii(
            $domain,
            IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ | IDNA_NONTRANSITIONAL_TO_ASCII,
            INTL_IDNA_VARIANT_UTS46
        );
        return $local . '@' . ($asciiDomain === false ? mb_strtolower($domain, 'UTF-8') : strtolower($asciiDomain));
    }

    public function scopeByEmail(Builder $query, string $email): Builder
    {
        return $query->where('email', self::normalizeEmail($email));
    }

    /** The same eligibility predicate is used by subscriptions and every node sync. */
    public function scopeEligible(Builder $query, ?int $at = null): Builder
    {
        return $query->where('is_admin', false)->where('banned', false)
            ->where(fn (Builder $q) => $q->whereNull('expired_at')->orWhere('expired_at', '>', $at ?? time()))
            ->where(fn (Builder $q) => $q->whereNull('transfer_enable')->orWhere(function (Builder $q) {
                $q->where('transfer_enable', '>', 0)
                    ->whereRaw('COALESCE(u, 0) + COALESCE(d, 0) < transfer_enable');
            }));
    }

    public function scopeAuthorizedFor(Builder $query, Server $server): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('access_mode', 'all')
            ->orWhere(fn (Builder $q) => $q->where('access_mode', 'restricted')
                ->whereHas('servers', fn (Builder $nodes) => $nodes->whereKey($server->id))));
    }

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'v2_user_server', 'user_id', 'server_id');
    }

    public function stat(): HasMany
    {
        return $this->hasMany(StatUser::class, 'user_id', 'id');
    }

    public function trafficResetLogs(): HasMany
    {
        return $this->hasMany(TrafficResetLog::class, 'user_id', 'id');
    }

    public function isActive(): bool
    {
        return !$this->is_admin && !$this->banned && ($this->expired_at === null || $this->expired_at > time());
    }

    public function isAvailable(): bool
    {
        return $this->isActive() && ($this->transfer_enable === null
            || ($this->transfer_enable > 0 && $this->getTotalUsedTraffic() < $this->transfer_enable));
    }

    public function canAccessServer(Server $server): bool
    {
        return $this->access_mode === 'all' || ($this->access_mode === 'restricted'
            && $this->servers()->whereKey($server->id)->exists());
    }

    public function getTotalUsedTraffic(): int
    {
        return (int) ($this->u ?? 0) + (int) ($this->d ?? 0);
    }

    public function getRemainingTraffic(): ?int
    {
        return $this->transfer_enable === null ? null : max(0, $this->transfer_enable - $this->getTotalUsedTraffic());
    }

    public function getTrafficUsagePercentage(): ?float
    {
        if ($this->transfer_enable === null) return null;
        if ($this->transfer_enable <= 0) return 100.0;
        return min(100, $this->getTotalUsedTraffic() / $this->transfer_enable * 100);
    }

    public function getActualUpload(): int
    {
        return max(0, (int) $this->u - (int) $this->legacy_u);
    }

    public function getActualDownload(): int
    {
        return max(0, (int) $this->d - (int) $this->legacy_d);
    }
}
