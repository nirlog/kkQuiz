<?php

declare(strict_types=1);

namespace Kk\Quiz\Admin;

final class LeadStatusHelper
{
    public static function labels(): array
    {
        return [
            'new' => 'Новая',
            'in_progress' => 'В работе',
            'contacted' => 'Связались',
            'deal_created' => 'Сделка создана',
            'closed' => 'Закрыта',
            'done' => 'Обработана',
            'rejected' => 'Отказ',
            'spam' => 'Спам / мусор',
        ];
    }

    public static function classes(): array
    {
        return [
            'new' => 'new',
            'in_progress' => 'progress',
            'contacted' => 'contacted',
            'deal_created' => 'deal',
            'closed' => 'closed',
            'done' => 'closed',
            'rejected' => 'rejected',
            'spam' => 'spam',
        ];
    }

    public static function workflowActions(): array
    {
        return [
            'in_progress' => 'В работу',
            'contacted' => 'Связались',
            'deal_created' => 'Сделка создана',
            'closed' => 'Закрыта',
            'rejected' => 'Отказ',
            'spam' => 'Спам',
        ];
    }

    public static function isKnownStatus(string $xmlId): bool
    {
        return isset(self::labels()[$xmlId]);
    }

    public static function normalizeXmlId(string $xmlId, string $value = ''): string
    {
        $xmlId = trim($xmlId);
        if ($xmlId !== '' && isset(self::labels()[$xmlId])) {
            return $xmlId;
        }

        $value = trim($value);
        foreach (self::labels() as $code => $label) {
            if (($value !== '' && mb_strtolower($value) === mb_strtolower($label))
                || ($xmlId !== '' && mb_strtolower($xmlId) === mb_strtolower($label))) {
                return $code;
            }
        }

        return $xmlId;
    }

    public static function label(string $xmlId, string $fallback = ''): string
    {
        return self::labels()[$xmlId] ?? ($fallback !== '' ? $fallback : 'Без статуса');
    }

    public static function cssClass(string $xmlId): string
    {
        return 'kk-lead-status-badge--' . (self::classes()[$xmlId] ?? 'empty');
    }

    public static function renderBadge(string $xmlId, string $fallback = ''): string
    {
        return '<span class="kk-lead-status-badge ' . htmlspecialcharsbx(self::cssClass($xmlId)) . '">'
            . htmlspecialcharsbx(self::label($xmlId, $fallback)) . '</span>';
    }

    public static function renderCss(): string
    {
        return <<<'HTML'
<style>
.kk-lead-status-badge{display:inline-flex;align-items:center;min-height:20px;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600;line-height:1.4;white-space:nowrap;border:1px solid transparent}
.kk-lead-status-badge--new{background:#fff3cd;color:#7a5600;border-color:#f1d38a}
.kk-lead-status-badge--progress{background:#e8f1ff;color:#1d4f91;border-color:#b8d4ff}
.kk-lead-status-badge--contacted{background:#e9f7ef;color:#1f6f3c;border-color:#bce5cd}
.kk-lead-status-badge--deal{background:#edf7ff;color:#1f5f82;border-color:#bfe3f6}
.kk-lead-status-badge--closed{background:#e6f4ea;color:#24733f;border-color:#b7dfc2}
.kk-lead-status-badge--rejected{background:#fdeaea;color:#9b2525;border-color:#f4b6b6}
.kk-lead-status-badge--spam{background:#eee;color:#555;border-color:#d4d4d4}
.kk-lead-status-badge--empty{background:#f5f5f5;color:#777;border-color:#ddd}
</style>
HTML;
    }
}
