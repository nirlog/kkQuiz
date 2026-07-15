<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

global $APPLICATION, $USER;

if (!$USER || !$USER->IsAuthorized() || !$USER->IsAdmin()) {
    $APPLICATION->AuthForm('Доступ запрещён');
}

if (!Loader::includeModule('kk.quiz')) {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    echo 'Модуль kk.quiz не установлен';
    require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    return;
}

Loc::loadMessages(__FILE__);
$APPLICATION->SetTitle('KK Quiz — помощь');
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');

echo <<<'HTML'
<style>
.kk-quiz-help { max-width: 1100px; }
.kk-quiz-help h2 { margin-top: 28px; }
.kk-quiz-help h3 { margin-top: 18px; }
.kk-quiz-help pre {
    padding: 12px;
    background: #f5f7f9;
    border: 1px solid #dfe5ec;
    overflow: auto;
}
.kk-quiz-help code {
    background: #f5f7f9;
    padding: 2px 4px;
}
.kk-quiz-help .kk-note {
    padding: 12px;
    background: #fffbe6;
    border: 1px solid #e6d98c;
    margin: 12px 0;
}
.kk-quiz-help table { border-collapse: collapse; margin: 10px 0 18px; width: 100%; }
.kk-quiz-help th, .kk-quiz-help td { border: 1px solid #dfe5ec; padding: 8px 10px; vertical-align: top; }
.kk-quiz-help th { background: #f5f7f9; text-align: left; }
</style>

<div class="adm-info-message-wrap kk-quiz-help">
    <div class="adm-info-message">
        <b>KK Quiz — помощь и инструкция.</b><br>
        Эта страница описывает базовую установку квиза, работу с заявками, доставки и типовые ошибки.
    </div>
</div>

<div class="kk-quiz-help">
    <h2>Быстрый старт</h2>
    <ol>
        <li>Создайте квиз в инфоблоке “Квизы”.</li>
        <li>Добавьте вопросы.</li>
        <li>Добавьте результаты.</li>
        <li>Настройте стартовый вопрос.</li>
        <li>В разделе “Вставка на сайт” скопируйте код компонента или popup-ссылку.</li>
        <li>Проверьте отправку заявки.</li>
        <li>Настройте нужные каналы доставки: Email, Telegram, Webhook, Bitrix24, amoCRM.</li>
        <li>Проверьте статистику.</li>
    </ol>

    <h2>Вставка квиза на сайт</h2>
    <h3>Блок на странице</h3>
    <pre><code>&lt;?php
$APPLICATION-&gt;IncludeComponent(
    'kk:quiz',
    '',
    [
        'QUIZ_CODE' =&gt; 'pc_selector',
    ]
);
?&gt;</code></pre>

    <h3>Popup по кнопке</h3>
    <pre><code>&lt;a href=&quot;#&quot; data-kk-quiz-popup=&quot;pc_selector&quot;&gt;Пройти квиз&lt;/a&gt;</code></pre>

    <h3>Popup по URL</h3>
    <pre><code>?kkquiz=pc_selector</code></pre>

    <div class="kk-note">
        Для popup-режима на странице должен быть подключён компонент <code>kk:quiz</code> или универсальный loader, если он используется в проекте.
    </div>

    <h2>Заявки</h2>
    <ul>
        <li>Заявки сохраняются в инфоблок “Заявки квизов”.</li>
        <li>Основные данные клиента хранятся в свойствах.</li>
        <li>Человекочитаемые ответы сохраняются в <code>DETAIL_TEXT</code>.</li>
        <li>Внизу карточки заявки есть блок “KK Quiz — доставки”.</li>
        <li>Там видны Webhook, Bitrix24, amoCRM и история попыток.</li>
        <li>Из этого блока можно повторить отправку.</li>
    </ul>

    <h2>Видео на результате</h2>
    <p>В карточке результата можно указать URL видео. Поддерживаются YouTube, Rutube, VK Video и прямые ссылки на mp4/webm/ogg. Модуль сам преобразует ссылку в безопасный embed. Произвольный iframe-код не используется.</p>

    <h2>Дизайн публичной части</h2>
    <p>Публичный шаблон использует CSS-переменные. Их можно переопределить на уровне сайта, например для изменения акцентного цвета, скругления и фона. Основной файл стилей: <code>local/components/kk/quiz/templates/.default/style.css</code>.</p>
<pre><code>.kk-quiz {
    --kk-quiz-accent: #ff6a00;
    --kk-quiz-radius: 24px;
}</code></pre>

    <h2>Email</h2>
    <ol>
        <li>Откройте настройки модуля KK Quiz.</li>
        <li>Перейдите на вкладку Email.</li>
        <li>Включите Email-уведомления.</li>
        <li>Укажите получателей.</li>
        <li>Сохраните настройки.</li>
        <li>Отправьте тестовую заявку через квиз.</li>
    </ol>

    <h2>Telegram</h2>
    <ol>
        <li>Создайте Telegram-бота через BotFather.</li>
        <li>Получите Bot Token.</li>
        <li>Добавьте бота в чат или канал.</li>
        <li>Получите Chat ID.</li>
        <li>В настройках KK Quiz включите Telegram.</li>
        <li>Укажите Bot Token и Chat ID.</li>
        <li>Нажмите кнопку проверки отправки.</li>
    </ol>
    <div class="kk-note">
        Если Telegram возвращает HTTP 401 — проверьте Bot Token.<br>
        Если сообщение не приходит — проверьте Chat ID и права бота в чате.
    </div>

    <h2>Universal Webhook</h2>
    <p>Universal Webhook отправляет нормализованный JSON payload заявки на внешний URL.</p>
    <h3>Настройки</h3>
    <ul>
        <li>Включить webhook;</li>
        <li>Webhook URL;</li>
        <li>Secret;</li>
        <li>Timeout.</li>
    </ul>
    <p>Если Secret заполнен, внешний обработчик может проверять подпись запроса.</p>
    <pre><code>{
  "event": "kk_quiz_lead",
  "lead": {
    "id": 123,
    "quiz": {
      "code": "pc_selector",
      "name": "Подбор компьютера"
    },
    "client": {
      "name": "Иван",
      "phone": "+79990000000"
    }
  }
}</code></pre>

    <h2>Bitrix24 — создание входящего webhook</h2>
    <h3>Что создаёт интеграция</h3>
    <p>KK Quiz создаёт лид в Bitrix24 через REST-метод <code>crm.lead.add</code>.</p>

    <h3>Как создать webhook в Bitrix24</h3>
    <ol>
        <li>Войдите в Bitrix24 под пользователем, от имени которого будут создаваться лиды.</li>
        <li>Откройте раздел “Разработчикам”.</li>
        <li>Выберите “Другое” или “Входящий вебхук”.</li>
        <li>Создайте новый входящий вебхук.</li>
        <li>В правах доступа выберите CRM.</li>
        <li>Сохраните webhook.</li>
        <li>Скопируйте URL webhook.</li>
    </ol>
    <div class="kk-note">Пользователь, от имени которого создан webhook, должен иметь право создавать лиды в CRM.</div>

    <h3>Какой URL вставлять в KK Quiz</h3>
    <p>Можно вставлять URL в одном из двух видов:</p>
    <pre><code>https://example.bitrix24.ru/rest/1/xxxxxxxxxxxxxxxx/</code></pre>
    <p>или:</p>
    <pre><code>https://example.bitrix24.ru/rest/1/xxxxxxxxxxxxxxxx/crm.lead.add.json</code></pre>
    <p>Модуль сам добавит <code>crm.lead.add.json</code>, если метод не указан в URL.</p>

    <h3>Настройки KK Quiz для Bitrix24</h3>
    <ol>
        <li>Откройте настройки модуля KK Quiz.</li>
        <li>Перейдите на вкладку CRM.</li>
        <li>Включите Bitrix24.</li>
        <li>Вставьте webhook URL.</li>
        <li>При необходимости укажите ID ответственного и источник.</li>
        <li>Сохраните настройки.</li>
        <li>Нажмите “Проверить Bitrix24”.</li>
    </ol>

    <h3>Что проверить</h3>
    <ul>
        <li>В Bitrix24 появился тестовый лид.</li>
        <li>В карточке заявки KK Quiz сохранился Bitrix24 ID лида.</li>
        <li>В блоке “KK Quiz — доставки” канал <code>bitrix24</code> имеет <code>success=Y</code>.</li>
        <li>Webhook-токен не отображается в истории доставок.</li>
    </ul>

    <h3>Частые ошибки Bitrix24</h3>
    <table>
        <tr><th>Ошибка</th><th>Что проверить</th></tr>
        <tr><td>HTTP_401 / ACCESS_DENIED</td><td>Webhook URL и права пользователя.</td></tr>
        <tr><td>HTTP_400</td><td>Формат URL и обязательные поля.</td></tr>
        <tr><td>Лид не создаётся</td><td>Webhook имеет доступ к CRM.</td></tr>
    </table>

    <h2>amoCRM — настройка интеграции</h2>
    <h3>Что создаёт интеграция</h3>
    <p>KK Quiz создаёт сделку и контакт в amoCRM. Ответы квиза, страница, UTM и данные клиента добавляются в примечание к сделке.</p>

    <h3>Рекомендуемый способ — долгосрочный токен</h3>
    <p>Для одного сайта и одного аккаунта amoCRM рекомендуется использовать долгосрочный токен. В этом режиме не нужны Client ID, Client Secret, Redirect URI и Refresh Token.</p>

    <h3>Как создать интеграцию в amoCRM</h3>
    <ol>
        <li>Войдите в amoCRM под администратором.</li>
        <li>Откройте amoМаркет / Интеграции.</li>
        <li>Создайте новую интеграцию.</li>
        <li>Укажите название, например KK Quiz.</li>
        <li>Выберите доступы для работы со сделками, контактами и примечаниями.</li>
        <li>Сохраните интеграцию.</li>
        <li>Откройте вкладку “Ключи”.</li>
        <li>Нажмите “Сгенерировать токен”.</li>
        <li>Выберите срок действия токена.</li>
        <li>Скопируйте токен сразу после создания.</li>
    </ol>
    <div class="kk-note">amoCRM показывает долгосрочный токен только один раз. Сразу скопируйте его и сохраните в безопасном месте.</div>

    <h3>Настройки KK Quiz для amoCRM</h3>
    <ol>
        <li>Откройте настройки модуля KK Quiz.</li>
        <li>Перейдите на вкладку amoCRM.</li>
        <li>Включите amoCRM.</li>
        <li>Включите “Использовать долгосрочный токен без refresh token”.</li>
        <li>Укажите домен аккаунта, например <code>example.amocrm.ru</code>.</li>
        <li>Вставьте Access Token.</li>
        <li>Укажите теги, например KK Quiz.</li>
        <li>Остальные поля можно оставить пустыми.</li>
        <li>Сохраните настройки.</li>
        <li>Нажмите “Проверить amoCRM”.</li>
    </ol>

    <h3>Минимальная рабочая конфигурация</h3>
    <pre><code>Включить amoCRM = Да
Использовать долгосрочный токен без refresh token = Да
Домен аккаунта = example.amocrm.ru
Access Token = долгосрочный токен
Теги = KK Quiz</code></pre>

    <h3>Дополнительные поля</h3>
    <p>ID воронки, ID этапа, ID ответственного и бюджет сделки можно оставить пустыми. Если они заполнены, модуль передаст их в amoCRM. Если указан неверный ID, amoCRM может вернуть ошибку HTTP_400.</p>

    <h3>Что проверить</h3>
    <ul>
        <li>Появилась тестовая сделка.</li>
        <li>Появился контакт.</li>
        <li>В сделке есть примечание с данными квиза.</li>
        <li>В карточке заявки KK Quiz сохранён amoCRM ID сделки.</li>
        <li>В карточке заявки сохранён amoCRM ID контакта.</li>
        <li>В delivery log канал <code>amocrm</code> имеет <code>success=Y</code>.</li>
    </ul>

    <h3>Частые ошибки amoCRM</h3>
    <table>
        <tr><th>Ошибка</th><th>Что проверить</th></tr>
        <tr><td>AMOCRM_SETTINGS_INCOMPLETE</td><td>Не заполнен домен аккаунта или Access Token.</td></tr>
        <tr><td>HTTP_401</td><td>Токен неверный, истёк или отозван. Сгенерируйте новый долгосрочный токен.</td></tr>
        <tr><td>HTTP_400 Request validation failed</td><td>Проверьте ID воронки, ID этапа, ID ответственного и бюджет. Для первого теста оставьте эти поля пустыми.</td></tr>
        <tr><td>Сделка создана, но примечание не появилось</td><td>Проверьте права токена на работу с примечаниями.</td></tr>
    </table>

    <h2>Статистика и аналитика</h2>
    <ul>
        <li>Статистика доступна в разделе KK Quiz → Статистика.</li>
        <li>Можно выбрать период.</li>
        <li>Можно фильтровать по квизу.</li>
        <li>Видны просмотры, старты, ответы, результаты, формы и заявки.</li>
        <li>Есть экспорт CSV/XLS.</li>
        <li>Служебная ссылка <code>kkquiz_nostat=Y</code> отключает внутреннюю статистику для тестов администратора.</li>
    </ul>

    <h2>Яндекс.Метрика и GA4</h2>
    <ul>
        <li>Цели отправляются при первом ответе.</li>
        <li>При достижении результата.</li>
        <li>При клике по CTA результата.</li>
        <li>При клике по рекомендованному товару.</li>
        <li>При успешной заявке.</li>
    </ul>
    <p>Проверять отправку целей лучше через DevTools → Network → запросы к <code>mc.yandex.ru</code> или через отладку GA4.</p>

    <h2>Troubleshooting</h2>
    <table>
        <tr><th>Ситуация</th><th>Что проверить</th></tr>
        <tr><td>Заявка не создаётся</td><td>Обязательные поля, privacy agreement и антиспам.</td></tr>
        <tr><td>Заявка создаётся, но не уходит в CRM</td><td>Блок “KK Quiz — доставки” внизу карточки заявки.</td></tr>
        <tr><td>Ошибка Too many tables</td><td>Обновите модуль до версии после hotfix getLeadDataById.</td></tr>
        <tr><td>Telegram HTTP 401</td><td>Неверный Bot Token.</td></tr>
        <tr><td>Bitrix24 ACCESS_DENIED</td><td>Webhook создан пользователем без прав на CRM.</td></tr>
        <tr><td>amoCRM HTTP_401</td><td>Токен неверный или отозван.</td></tr>
        <tr><td>amoCRM HTTP_400</td><td>Дополнительные ID воронки/этапа/ответственного.</td></tr>
    </table>

    <h2>Безопасность</h2>
    <ul>
        <li>Не публикуйте webhook URL Bitrix24.</li>
        <li>Не отправляйте никому amoCRM Access Token.</li>
        <li>Не вставляйте токены в публичные страницы.</li>
        <li>После увольнения сотрудника проверьте токены и webhooks.</li>
        <li>Для amoCRM долгосрочный токен можно отозвать во вкладке “Выданные доступы”.</li>
        <li>Для Bitrix24 входящий webhook можно удалить в разделе “Разработчикам”.</li>
    </ul>
</div>
HTML;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
