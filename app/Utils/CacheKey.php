<?php

namespace App\Utils;

class CacheKey
{
    // 核心缓存键定义
    const CORE_KEYS = [
        'TEMP_TOKEN' => '临时令牌',
        'SCHEDULE_LAST_CHECK_AT' => '计划任务最后检查时间',
        'PASSWORD_ERROR_LIMIT' => '密码错误次数限制',
        'USER_SESSIONS' => '用户session'
    ];

    // 允许的缓存键模式（支持通配符）
    const ALLOWED_PATTERNS = [
        'SERVER_*_ONLINE_USER',        // 节点在线用户
        'MULTI_SERVER_*_ONLINE_USER',  // 多服务器在线用户
        'SERVER_*_LAST_CHECK_AT',      // 节点最后检查时间
        'SERVER_*_LAST_PUSH_AT',       // 节点最后推送时间
        'SERVER_*_LOAD_STATUS',        // 节点负载状态
        'SERVER_*_LAST_LOAD_AT',       // 节点最后负载提交时间
        'SERVER_*_METRICS',            // 节点指标数据
        'USER_ONLINE_CONN_*_*',        // 用户在线连接数 (特定节点类型_ID)
    ];

    /**
     * 生成缓存键
     */
    public static function get(string $key, mixed $uniqueValue = null): string
    {
        // 检查是否为核心键
        if (array_key_exists($key, self::CORE_KEYS)) {
            return $uniqueValue ? $key . '_' . $uniqueValue : $key;
        }

        // 检查是否匹配允许的模式
        if (self::matchesPattern($key)) {
            return $uniqueValue ? $key . '_' . $uniqueValue : $key;
        }

        // 开发环境下记录警告，生产环境允许通过
        if (app()->environment('local', 'development')) {
            logger()->warning("Unknown cache key used: {$key}");
        }

        return $uniqueValue ? $key . '_' . $uniqueValue : $key;
    }

    /**
     * 检查键名是否匹配允许的模式
     */
    private static function matchesPattern(string $key): bool
    {
        foreach (self::ALLOWED_PATTERNS as $pattern) {
            $regex = '/^' . str_replace('*', '[A-Za-z0-9_]+', $pattern) . '$/';
            if (preg_match($regex, $key)) {
                return true;
            }
        }
        return false;
    }
}
