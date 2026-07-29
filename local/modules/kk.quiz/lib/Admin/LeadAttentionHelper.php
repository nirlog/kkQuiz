<?php

declare(strict_types=1);

namespace Kk\Quiz\Admin;

use Kk\Quiz\Service\ModuleSettingsService;

final class LeadAttentionHelper
{
    public static function attentionThresholdMinutes(): int
    {
        $minutes = (int)ModuleSettingsService::get('lead_attention_minutes');

        return min(1440, max(5, $minutes > 0 ? $minutes : 30));
    }

    public static function timestampFromBitrixDate(mixed $date): int
    {
        if (is_object($date) && method_exists($date, 'getTimestamp')) {
            return (int)$date->getTimestamp();
        }

        if (is_object($date) && method_exists($date, 'toString')) {
            $date = $date->toString();
        }

        $date = trim((string)$date);
        if ($date === '') {
            return 0;
        }

        if (function_exists('MakeTimeStamp')) {
            $timestamp = (int)MakeTimeStamp($date);
            if ($timestamp > 0) {
                return $timestamp;
            }
        }

        $timestamp = strtotime($date);

        return $timestamp !== false ? (int)$timestamp : 0;
    }

    public static function ageSeconds(mixed $dateCreate, ?int $now = null): int
    {
        $createdAt = self::timestampFromBitrixDate($dateCreate);
        if ($createdAt <= 0) {
            return 0;
        }

        return max(0, ($now ?? time()) - $createdAt);
    }

    public static function ageLabel(mixed $dateCreate, ?int $now = null): string
    {
        $seconds = self::ageSeconds($dateCreate, $now);
        if ($seconds <= 0) {
            return '—';
        }
        if ($seconds < 60) {
            return 'только что';
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes . ' мин назад';
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return $hours . ' ч назад';
        }

        return intdiv($hours, 24) . ' дн назад';
    }

    public static function requiresAttention(
        string $statusXmlId,
        mixed $dateCreate,
        ?int $now = null,
        ?int $thresholdMinutes = null
    ): bool {
        if ($statusXmlId !== 'new') {
            return false;
        }

        $thresholdMinutes = $thresholdMinutes ?? self::attentionThresholdMinutes();

        return self::ageSeconds($dateCreate, $now) >= ($thresholdMinutes * 60);
    }

    public static function renderAttentionBadge(): string
    {
        return '<span class="kk-lead-attention-badge">Требует внимания</span>';
    }

    public static function renderAge(string $label, bool $requiresAttention): string
    {
        $class = $requiresAttention ? 'kk-lead-age kk-lead-age--attention' : 'kk-lead-age';

        return '<span class="' . $class . '">' . htmlspecialcharsbx($label) . '</span>';
    }

    public static function renderCss(): string
    {
        return <<<'HTML'
<style>
.kk-lead-age{color:#555;white-space:nowrap}
.kk-lead-age--attention{color:#b42318;font-weight:700}
.kk-lead-attention-badge{display:inline-flex;align-items:center;min-height:20px;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:700;line-height:1.4;white-space:nowrap;background:#fdeaea;color:#9b2525;border:1px solid #f4b6b6}
.kk-lead-attention-filter{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0}
.kk-lead-attention-filter a{display:inline-flex;padding:5px 10px;border:1px solid #f4b6b6;border-radius:999px;background:#fff;color:#9b2525;text-decoration:none;font-weight:600}
.kk-lead-attention-filter a.is-active{background:#fdeaea;border-color:#e08a8a}
</style>
HTML;
    }
}
