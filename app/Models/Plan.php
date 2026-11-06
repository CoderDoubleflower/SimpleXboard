<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Transitional member configuration. Sales and billing are retired.
 * transfer_enable is stored in GiB here, and in bytes on User.
 */
class Plan extends Model
{
    use HasFactory;

    protected $table = 'v2_plan';
    protected $dateFormat = 'U';

    public const RESET_TRAFFIC_FOLLOW_SYSTEM = null;
    public const RESET_TRAFFIC_FIRST_DAY_MONTH = 0;
    public const RESET_TRAFFIC_MONTHLY = 1;
    public const RESET_TRAFFIC_NEVER = 2;
    public const RESET_TRAFFIC_FIRST_DAY_YEAR = 3;
    public const RESET_TRAFFIC_YEARLY = 4;

    public const INTERNAL_DEFAULT_NAME = '内部默认成员配置';
    public const INTERNAL_DEFAULT_GIB = 1024;

    protected $fillable = [
        'group_id', 'transfer_enable', 'name', 'speed_limit', 'sort',
        'reset_traffic_method', 'device_limit',
    ];

    // Historical sales data is retained in the database for a separate cleanup.
    protected $hidden = ['show', 'renew', 'sell', 'prices', 'capacity_limit', 'content', 'tags'];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'group_id' => 'integer',
        'transfer_enable' => 'integer',
        'reset_traffic_method' => 'integer',
        'is_internal' => 'boolean',
    ];

    public static function internalDefault(): self
    {
        return static::where('is_internal', true)->firstOrFail();
    }

    public static function getResetTrafficMethods(): array
    {
        return [
            self::RESET_TRAFFIC_FOLLOW_SYSTEM => '跟随系统设置',
            self::RESET_TRAFFIC_FIRST_DAY_MONTH => '每月1号',
            self::RESET_TRAFFIC_MONTHLY => '按月重置',
            self::RESET_TRAFFIC_NEVER => '不重置',
            self::RESET_TRAFFIC_FIRST_DAY_YEAR => '每年1月1日',
            self::RESET_TRAFFIC_YEARLY => '按年重置',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ServerGroup::class, 'group_id');
    }
}
