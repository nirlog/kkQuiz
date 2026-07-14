<?php

declare(strict_types=1);

namespace Kk\Quiz\Admin;

use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use Kk\Quiz\Iblock\Installer;
use Kk\Quiz\Repository\LeadRepository;
use Kk\Quiz\Service\LeadDeliveryLogService;

final class LeadFormAssets
{
    public static function onProlog(): void
    {
        if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true) {
            return;
        }

        if (!self::isAdminAllowed()) {
            return;
        }

        if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'iblock_element_edit.php') {
            return;
        }

        if (!Loader::includeModule('iblock')) {
            return;
        }

        $leadId = (int)($_REQUEST['ID'] ?? 0);
        $iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? 0);
        if ($leadId <= 0 || $iblockId <= 0 || !self::isLeadsIblock($iblockId)) {
            return;
        }

        $repository = new LeadRepository();
        $lead = $repository->getLeadDataById($leadId) ?? [];
        $logs = (new LeadDeliveryLogService())->getByLeadId($leadId, 20);

        Asset::getInstance()->addString(self::renderBlock($leadId, $lead, $logs));
    }

    private static function isAdminAllowed(): bool
    {
        global $USER;

        return is_object($USER)
            && method_exists($USER, 'IsAuthorized')
            && method_exists($USER, 'IsAdmin')
            && $USER->IsAuthorized()
            && $USER->IsAdmin();
    }

    private static function isLeadsIblock(int $iblockId): bool
    {
        $iblock = \CIBlock::GetList([], [
            'ID' => $iblockId,
            'TYPE' => Installer::IBLOCK_TYPE_ID,
            'CODE' => Installer::LEADS_IBLOCK_CODE,
        ])->Fetch();

        return is_array($iblock);
    }

    private static function renderBlock(int $leadId, array $lead, array $logs): string
    {
        $status = self::escape((string)($lead['webhook_status'] ?? ''));
        $sent = self::escape((string)($lead['webhook_sent'] ?? ''));
        $sentAt = self::escape((string)($lead['webhook_sent_at'] ?? ''));
        $error = self::escape((string)($lead['webhook_error'] ?? ''));
        $rows = self::renderRows($logs);

        if ($rows === '') {
            $rows = '<tr><td colspan="8">История webhook-отправок пока пуста.</td></tr>';
        }

        $panelHtml = '<h3>KK Quiz — webhook</h3>'
            . '<div class="kk-quiz-webhook-status">'
            . '<div><b>Текущий статус:</b> ' . $status . '</div>'
            . '<div><b>Отправлен:</b> ' . $sent . '</div>'
            . '<div><b>Дата отправки:</b> ' . $sentAt . '</div>'
            . '<div><b>Ошибка:</b> ' . $error . '</div>'
            . '</div>'
            . '<button type="button" class="adm-btn adm-btn-save" id="kk-quiz-webhook-retry">Повторить webhook</button>'
            . '<h3 style="margin-top:16px">Последние попытки</h3>'
            . '<table><thead><tr><th>Дата</th><th>Канал</th><th>Успех</th><th>Статус</th><th>Ошибка</th><th>Время, мс</th><th>Запрос</th><th>Ответ</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        $panelHtmlJson = json_encode($panelHtml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $leadIdJson = (string)$leadId;

        return <<<HTML
<style>
#kk-quiz-webhook-panel{margin:15px 0;padding:14px;border:1px solid #cfd6df;background:#f8fafc;border-radius:4px;color:#333}
#kk-quiz-webhook-panel h3{margin:0 0 10px;font-size:16px}
#kk-quiz-webhook-panel .kk-quiz-webhook-status{margin-bottom:10px;line-height:1.6}
#kk-quiz-webhook-panel table{width:100%;border-collapse:collapse;background:#fff}
#kk-quiz-webhook-panel th,#kk-quiz-webhook-panel td{border:1px solid #d6dce5;padding:6px 8px;vertical-align:top}
#kk-quiz-webhook-panel th{background:#eef2f7;text-align:left}
#kk-quiz-webhook-panel details{max-width:520px;white-space:pre-wrap;word-break:break-word}
#kk-quiz-webhook-panel .kk-quiz-webhook-success{color:#167a3a;font-weight:bold}
#kk-quiz-webhook-panel .kk-quiz-webhook-error{color:#a62323;font-weight:bold}
</style>
<script>
(function(){
var mount = function(){
if (document.getElementById('kk-quiz-webhook-panel')) return;
var form = document.querySelector('form[name="form_element_{$leadIdJson}"]') || document.querySelector('form[name="form_element"]') || document.querySelector('form');
if (!form || !form.parentNode) return;
var panel = document.createElement('div');
panel.id = 'kk-quiz-webhook-panel';
panel.innerHTML = {$panelHtmlJson};
form.parentNode.insertBefore(panel, form);
var button = document.getElementById('kk-quiz-webhook-retry');
if (!button) return;
button.addEventListener('click', function(){
var originalText = button.textContent;
button.disabled = true;
button.textContent = 'Отправка...';
var params = new URLSearchParams();
params.set('action', 'kk:quiz.api.retryLeadWebhook');
if (window.BX && BX.bitrix_sessid) params.set('sessid', BX.bitrix_sessid());
fetch('/bitrix/services/main/ajax.php?' + params.toString(), {
method: 'POST',
credentials: 'same-origin',
headers: {'Content-Type': 'application/json'},
body: JSON.stringify({lead_id: {$leadIdJson}})
}).then(function(response){ return response.json(); })
.then(function(response){
var data = response && response.data ? response.data : response;
if (!data || data.success !== true) {
var errors = data && data.errors ? data.errors.join(', ') : (data && data.error ? data.error : 'WEBHOOK_RETRY_FAILED');
throw new Error(errors);
}
alert('Webhook отправлен. HTTP ' + (data.status || 0));
window.location.reload();
}).catch(function(error){
alert('Не удалось отправить webhook: ' + (error && error.message ? error.message : 'WEBHOOK_RETRY_FAILED'));
}).finally(function(){
button.disabled = false;
button.textContent = originalText;
});
});
};
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();
</script>
HTML;
    }

    private static function renderRows(array $logs): string
    {
        $rows = [];
        foreach ($logs as $log) {
            $success = (string)($log['SUCCESS'] ?? '') === 'Y';
            $successText = $success ? 'Y' : 'N';
            $successClass = $success ? 'kk-quiz-webhook-success' : 'kk-quiz-webhook-error';
            $request = self::escape((string)($log['REQUEST_BODY'] ?? ''));
            $response = self::escape((string)($log['RESPONSE_BODY'] ?? ''));
            $date = $log['DATE_CREATE'] ?? '';
            if (is_object($date) && method_exists($date, 'toString')) {
                $date = $date->toString();
            }

            $rows[] = '<tr>'
                . '<td>' . self::escape((string)$date) . '</td>'
                . '<td>' . self::escape((string)($log['CHANNEL'] ?? '')) . '</td>'
                . '<td class="' . $successClass . '">' . $successText . '</td>'
                . '<td>' . self::escape((string)($log['STATUS'] ?? '')) . '</td>'
                . '<td>' . self::escape((string)($log['ERROR'] ?? '')) . '</td>'
                . '<td>' . (int)($log['DURATION_MS'] ?? 0) . '</td>'
                . '<td>' . ($request !== '' ? '<details><summary>Запрос</summary>' . $request . '</details>' : '') . '</td>'
                . '<td>' . ($response !== '' ? '<details><summary>Ответ</summary>' . $response . '</details>' : '') . '</td>'
                . '</tr>';
        }

        return implode('', $rows);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
