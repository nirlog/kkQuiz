<?php

declare(strict_types=1);

namespace Kk\Quiz\Iblock;

use Bitrix\Main\Application;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Kk\Quiz\Admin\ElementFormAssets;
use Kk\Quiz\Admin\ElementListAssets;
use Kk\Quiz\Admin\LeadFormAssets;
use Kk\Quiz\Admin\LeadListAssets;
use Kk\Quiz\Admin\SectionFormAssets;
use Kk\Quiz\Analytics\LeadDeliveryLogTable;
use Kk\Quiz\Analytics\QuizEventTable;
use Kk\Quiz\Iblock\Property\QuizAnswersProperty;
use Kk\Quiz\Service\QuizEventMaintenanceService;

final class Installer
{
    public const IBLOCK_TYPE_ID = 'kk_quiz';
    public const QUIZZES_IBLOCK_CODE = 'kk_quizzes';
    public const LEADS_IBLOCK_CODE = 'kk_quiz_leads';

    public static function install(): void
    {
        if (!Loader::includeModule('iblock')) {
            throw new SystemException('Для установки инфоблоков модуля KK Quiz необходимо установить модуль iblock.');
        }

        self::registerEventHandlers();
        self::installAdminFiles();
        self::installAnalyticsTables();
        self::installLeadDeliveryLogTable();
        self::registerMaintenanceAgent();

        self::installIblockType();

        $quizzesIblockId = self::installIblock(self::QUIZZES_IBLOCK_CODE, 'Квизы');
        $leadsIblockId = self::installIblock(self::LEADS_IBLOCK_CODE, 'Заявки квизов');

        self::installQuizSectionUserFields($quizzesIblockId);
        self::installQuizProperties($quizzesIblockId);
        self::deleteObsoleteQuizProperties($quizzesIblockId);
        self::installLeadProperties($leadsIblockId);
        self::configureLeadElementAdminForm($leadsIblockId);
        self::installMailEvents();
    }

    public static function uninstall(): void
    {
        self::unregisterEventHandlers();
        self::unregisterMaintenanceAgent();
        self::uninstallAdminFiles();

        // Инфоблоки и пользовательские данные намеренно не удаляются.
    }

    public static function ensureEventHandlers(): void
    {
        self::installAdminFiles(false);
        self::installAnalyticsTables(false);
        self::installLeadDeliveryLogTable(false);
        self::registerMaintenanceAgent();
        self::ensureQuizProperties();
        self::ensureLeadProperties();

        self::registerEventHandlerIfMissing(
            'iblock',
            'OnIBlockPropertyBuildList',
            QuizAnswersProperty::class,
            'getUserTypeDescription'
        );

        self::registerEventHandlerIfMissing(
            'main',
            'OnProlog',
            ElementFormAssets::class,
            'onProlog'
        );

        self::registerEventHandlerIfMissing(
            'main',
            'OnProlog',
            ElementListAssets::class,
            'onProlog'
        );

        self::registerEventHandlerIfMissing(
            'main',
            'OnProlog',
            LeadListAssets::class,
            'onProlog'
        );

        self::registerEventHandlerIfMissing(
            'main',
            'OnProlog',
            LeadFormAssets::class,
            'onProlog'
        );

        self::registerEventHandlerIfMissing(
            'main',
            'OnProlog',
            SectionFormAssets::class,
            'onProlog'
        );
    }

    private static function ensureQuizProperties(): void
    {
        try {
            if (!Loader::includeModule('iblock')) {
                return;
            }

            $iblock = \CIBlock::GetList([], [
                'TYPE' => self::IBLOCK_TYPE_ID,
                'CODE' => self::QUIZZES_IBLOCK_CODE,
            ])->Fetch();
            if (is_array($iblock) && (int)($iblock['ID'] ?? 0) > 0) {
                self::installQuizSectionUserFields((int)$iblock['ID']);
                self::installQuizProperties((int)$iblock['ID']);
            }
        } catch (\Throwable) {
        }
    }


    private static function ensureLeadProperties(): void
    {
        try {
            if (!Loader::includeModule('iblock')) {
                return;
            }

            $iblock = \CIBlock::GetList([], [
                'TYPE' => self::IBLOCK_TYPE_ID,
                'CODE' => self::LEADS_IBLOCK_CODE,
            ])->Fetch();
            if (is_array($iblock) && (int)($iblock['ID'] ?? 0) > 0) {
                self::installLeadProperties((int)$iblock['ID']);
            }
        } catch (\Throwable) {
        }
    }

    private static function registerEventHandlerIfMissing(
        string $fromModuleId,
        string $eventType,
        string $class,
        string $method
    ): void {
        if (self::hasEventHandler($fromModuleId, $eventType, $class, $method)) {
            return;
        }

        EventManager::getInstance()->registerEventHandler(
            $fromModuleId,
            $eventType,
            'kk.quiz',
            $class,
            $method
        );
    }

    private static function hasEventHandler(string $fromModuleId, string $eventType, string $class, string $method): bool
    {
        $handlers = EventManager::getInstance()->findEventHandlers($fromModuleId, $eventType);
        $normalizedClass = ltrim($class, '\\');

        foreach ($handlers as $handler) {
            $handlerModule = (string)($handler['TO_MODULE_ID'] ?? '');
            $handlerClass = ltrim((string)($handler['TO_CLASS'] ?? ''), '\\');
            $handlerMethod = (string)($handler['TO_METHOD'] ?? '');

            if (
                $handlerModule === 'kk.quiz'
                && $handlerClass === $normalizedClass
                && $handlerMethod === $method
            ) {
                return true;
            }
        }

        return false;
    }

    private static function registerEventHandlers(): void
    {
        self::unregisterEventHandlers();

        EventManager::getInstance()->registerEventHandler(
            'iblock',
            'OnIBlockPropertyBuildList',
            'kk.quiz',
            QuizAnswersProperty::class,
            'getUserTypeDescription'
        );

        EventManager::getInstance()->registerEventHandler(
            'main',
            'OnProlog',
            'kk.quiz',
            ElementFormAssets::class,
            'onProlog'
        );

        EventManager::getInstance()->registerEventHandler(
            'main',
            'OnProlog',
            'kk.quiz',
            ElementListAssets::class,
            'onProlog'
        );

        EventManager::getInstance()->registerEventHandler(
            'main',
            'OnProlog',
            'kk.quiz',
            LeadListAssets::class,
            'onProlog'
        );

        EventManager::getInstance()->registerEventHandler(
            'main',
            'OnProlog',
            'kk.quiz',
            LeadFormAssets::class,
            'onProlog'
        );

        EventManager::getInstance()->registerEventHandler(
            'main',
            'OnProlog',
            'kk.quiz',
            SectionFormAssets::class,
            'onProlog'
        );
    }

    private static function unregisterEventHandlers(): void
    {
        EventManager::getInstance()->unRegisterEventHandler(
            'iblock',
            'OnIBlockPropertyBuildList',
            'kk.quiz',
            QuizAnswersProperty::class,
            'getUserTypeDescription'
        );

        EventManager::getInstance()->unRegisterEventHandler(
            'main',
            'OnProlog',
            'kk.quiz',
            ElementFormAssets::class,
            'onProlog'
        );

        EventManager::getInstance()->unRegisterEventHandler(
            'main',
            'OnProlog',
            'kk.quiz',
            ElementListAssets::class,
            'onProlog'
        );

        EventManager::getInstance()->unRegisterEventHandler(
            'main',
            'OnProlog',
            'kk.quiz',
            LeadListAssets::class,
            'onProlog'
        );

        EventManager::getInstance()->unRegisterEventHandler(
            'main',
            'OnProlog',
            'kk.quiz',
            LeadFormAssets::class,
            'onProlog'
        );

        EventManager::getInstance()->unRegisterEventHandler(
            'main',
            'OnProlog',
            'kk.quiz',
            SectionFormAssets::class,
            'onProlog'
        );
    }

    private static function installAdminFiles(bool $throwOnError = true): void
    {
        $documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        if ($documentRoot === '') {
            if ($throwOnError) {
                throw new SystemException('DOCUMENT_ROOT is empty.');
            }

            return;
        }

        $targetDir = $documentRoot . '/bitrix/admin';
        if (!is_dir($targetDir)) {
            if ($throwOnError) {
                throw new SystemException('/bitrix/admin directory not found.');
            }

            return;
        }

        $files = [
            [
                'source' => dirname(__DIR__, 2) . '/admin/kk_quiz_statistics.php',
                'target' => $documentRoot . '/bitrix/admin/kk_quiz_statistics.php',
                'missing' => 'KK Quiz admin statistics stub source not found.',
                'write' => 'Cannot write /bitrix/admin/kk_quiz_statistics.php.',
            ],
            [
                'source' => dirname(__DIR__, 2) . '/install/admin/kk_quiz_help.php',
                'target' => $documentRoot . '/bitrix/admin/kk_quiz_help.php',
                'missing' => 'KK Quiz admin help stub source not found.',
                'write' => 'Cannot write /bitrix/admin/kk_quiz_help.php.',
            ],
        ];

        foreach ($files as $file) {
            if (!is_file($file['source'])) {
                if ($throwOnError) {
                    throw new SystemException($file['missing']);
                }

                continue;
            }

            $sourceContent = (string)file_get_contents($file['source']);
            $targetContent = is_file($file['target']) ? (string)file_get_contents($file['target']) : '';

            if ($targetContent === $sourceContent) {
                continue;
            }

            if (@file_put_contents($file['target'], $sourceContent) === false && $throwOnError) {
                throw new SystemException($file['write']);
            }
        }
    }

    private static function uninstallAdminFiles(): void
    {
        $documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        if ($documentRoot === '') {
            return;
        }

        $files = [
            [
                'target' => $documentRoot . '/bitrix/admin/kk_quiz_statistics.php',
                'marker' => 'kk.quiz/admin/statistics.php',
            ],
            [
                'target' => $documentRoot . '/bitrix/admin/kk_quiz_help.php',
                'marker' => 'kk.quiz/admin/help.php',
            ],
        ];

        foreach ($files as $file) {
            if (!is_file($file['target'])) {
                continue;
            }

            $content = (string)file_get_contents($file['target']);

            if (strpos($content, $file['marker']) !== false) {
                @unlink($file['target']);
            }
        }
    }

    private static function installAnalyticsTables(bool $throwOnError = true): void
    {
        try {
            $connection = Application::getConnection();
            $tableName = QuizEventTable::getTableName();

            if (!$connection->isTableExists($tableName)) {
                QuizEventTable::getEntity()->createDbTable();
            } else {
                self::addAnalyticsColumnIfMissing($tableName, 'STEP_INDEX', 'int');
                self::addAnalyticsColumnIfMissing($tableName, 'LEAD_ID', 'int');
                self::dropAnalyticsColumns($tableName, [
                    'SESSION_ID',
                    'PAGE_URL',
                    'REFERER',
                    'UTM_SOURCE',
                    'UTM_MEDIUM',
                    'UTM_CAMPAIGN',
                    'UTM_CONTENT',
                    'UTM_TERM',
                    'USER_AGENT',
                    'IP_HASH',
                ]);
            }

            self::createAnalyticsIndex($tableName, 'IX_KK_QUIZ_EVENTS_DATE', ['DATE_CREATE']);
            self::createAnalyticsIndex($tableName, 'IX_KK_QUIZ_EVENTS_QUIZ_DATE', ['QUIZ_CODE', 'DATE_CREATE']);
            self::createAnalyticsIndex($tableName, 'IX_KK_QUIZ_EVENTS_RUN', ['RUN_ID']);
            self::createAnalyticsIndex($tableName, 'IX_KK_QUIZ_EVENTS_TYPE_DATE', ['EVENT_TYPE', 'DATE_CREATE']);
            self::createAnalyticsIndex($tableName, 'IX_KK_QUIZ_EVENTS_QUESTION', ['QUIZ_CODE', 'QUESTION_CODE', 'EVENT_TYPE']);
            self::createAnalyticsIndex($tableName, 'IX_KK_QUIZ_EVENTS_RESULT', ['QUIZ_CODE', 'RESULT_CODE', 'EVENT_TYPE']);
        } catch (\Throwable $exception) {
            if ($throwOnError) {
                throw new SystemException($exception->getMessage());
            }
        }
    }


    public static function installLeadDeliveryLogTable(bool $throwOnError = true): void
    {
        try {
            $connection = Application::getConnection();
            $tableName = LeadDeliveryLogTable::getTableName();

            if (!$connection->isTableExists($tableName)) {
                LeadDeliveryLogTable::getEntity()->createDbTable();
            }

            self::createAnalyticsIndex($tableName, 'IX_KK_QUIZ_DELIVERY_LEAD', ['LEAD_ID']);
            self::createAnalyticsIndex($tableName, 'IX_KK_QUIZ_DELIVERY_CHANNEL_DATE', ['CHANNEL', 'DATE_CREATE']);
            self::createAnalyticsIndex($tableName, 'IX_KK_QUIZ_DELIVERY_SUCCESS_DATE', ['SUCCESS', 'DATE_CREATE']);
        } catch (\Throwable $exception) {
            if ($throwOnError) {
                throw new SystemException($exception->getMessage());
            }
        }
    }

    private static function addAnalyticsColumnIfMissing(string $tableName, string $columnName, string $type): void
    {
        if (self::analyticsColumnExists($tableName, $columnName)) {
            return;
        }

        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();

        try {
            $connection->queryExecute(sprintf(
                'ALTER TABLE %s ADD %s %s NULL',
                $helper->quote($tableName),
                $helper->quote($columnName),
                $type
            ));
        } catch (\Throwable) {
        }
    }

    private static function dropAnalyticsColumns(string $tableName, array $columnNames): void
    {
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();

        foreach ($columnNames as $columnName) {
            if (!self::analyticsColumnExists($tableName, (string)$columnName)) {
                continue;
            }

            try {
                $connection->queryExecute(sprintf(
                    'ALTER TABLE %s DROP COLUMN %s',
                    $helper->quote($tableName),
                    $helper->quote((string)$columnName)
                ));
            } catch (\Throwable) {
            }
        }
    }

    private static function analyticsColumnExists(string $tableName, string $columnName): bool
    {
        try {
            $fields = Application::getConnection()->getTableFields($tableName);
        } catch (\Throwable) {
            return false;
        }

        return array_key_exists($columnName, $fields) || array_key_exists(strtolower($columnName), $fields);
    }

    private static function createAnalyticsIndex(string $tableName, string $indexName, array $columns): void
    {
        $connection = Application::getConnection();
        if (method_exists($connection, 'isIndexExists') && $connection->isIndexExists($tableName, $columns)) {
            return;
        }

        $helper = $connection->getSqlHelper();
        $quotedColumns = [];
        foreach ($columns as $column) {
            $quotedColumns[] = $helper->quote((string)$column);
        }

        try {
            $connection->queryExecute(sprintf(
                'CREATE INDEX %s ON %s (%s)',
                $helper->quote($indexName),
                $helper->quote($tableName),
                implode(', ', $quotedColumns)
            ));
        } catch (\Throwable) {
            // Index may already exist under the requested name after a previous module version.
        }
    }

    private static function uninstallAnalyticsTables(): void
    {
        // Analytics events are user data and are intentionally preserved on module uninstall.
    }


    private static function registerMaintenanceAgent(): void
    {
        if (!class_exists('CAgent')) {
            return;
        }

        $agentName = '\\Kk\\Quiz\\Service\\QuizEventMaintenanceService::runCleanupAgent();';

        try {
            $existingAgent = \CAgent::GetList([], [
                'MODULE_ID' => 'kk.quiz',
                'NAME' => $agentName,
            ])->Fetch();

            if (is_array($existingAgent)) {
                return;
            }

            \CAgent::AddAgent($agentName, 'kk.quiz', 'N', 86400);
        } catch (\Throwable) {
        }
    }


    private static function unregisterMaintenanceAgent(): void
    {
        if (!class_exists('CAgent')) {
            return;
        }

        $agentName = '\\Kk\\Quiz\\Service\\QuizEventMaintenanceService::runCleanupAgent();';

        try {
            \CAgent::RemoveAgent($agentName, 'kk.quiz');
        } catch (\Throwable) {
        }
    }

    private static function installIblockType(): void
    {
        $type = \CIBlockType::GetByID(self::IBLOCK_TYPE_ID)->Fetch();
        if ($type) {
            return;
        }

        $iblockType = new \CIBlockType();
        $result = $iblockType->Add([
            'ID' => self::IBLOCK_TYPE_ID,
            'SECTIONS' => 'Y',
            'IN_RSS' => 'N',
            'SORT' => 500,
            'LANG' => self::getIblockTypeLang(),
        ]);

        if (!$result) {
            throw new SystemException((string)$iblockType->LAST_ERROR);
        }
    }

    private static function getIblockTypeLang(): array
    {
        $lang = [];
        $by = 'sort';
        $order = 'asc';
        $languages = \CLanguage::GetList($by, $order, ['ACTIVE' => 'Y']);

        if (!is_object($languages)) {
            return [
                'ru' => [
                    'NAME' => 'KK Quiz',
                    'SECTION_NAME' => 'Квизы',
                    'ELEMENT_NAME' => 'Элементы квиза',
                ],
            ];
        }

        while ($language = $languages->Fetch()) {
            $languageId = (string)($language['LID'] ?? '');
            if ($languageId === '') {
                continue;
            }

            $lang[$languageId] = $languageId === 'ru'
                ? [
                    'NAME' => 'KK Quiz',
                    'SECTION_NAME' => 'Квизы',
                    'ELEMENT_NAME' => 'Элементы квиза',
                ]
                : [
                    'NAME' => 'KK Quiz',
                    'SECTION_NAME' => 'Quizzes',
                    'ELEMENT_NAME' => 'Quiz items',
                ];
        }

        if (empty($lang)) {
            $lang['ru'] = [
                'NAME' => 'KK Quiz',
                'SECTION_NAME' => 'Квизы',
                'ELEMENT_NAME' => 'Элементы квиза',
            ];
        }

        return $lang;
    }

    private static function installIblock(string $code, string $name): int
    {
        $exists = \CIBlock::GetList([], ['TYPE' => self::IBLOCK_TYPE_ID, 'CODE' => $code])->Fetch();
        if ($exists) {
            return (int)$exists['ID'];
        }

        $siteIds = self::getSiteIds();
        $iblock = new \CIBlock();
        $iblockId = $iblock->Add([
            'ACTIVE' => 'Y',
            'NAME' => $name,
            'CODE' => $code,
            'IBLOCK_TYPE_ID' => self::IBLOCK_TYPE_ID,
            'SITE_ID' => $siteIds,
            'SORT' => 500,
            'GROUP_ID' => ['2' => 'R'],
            'FIELDS' => [
                'CODE' => [
                    'IS_REQUIRED' => 'N',
                    'DEFAULT_VALUE' => [
                        'UNIQUE' => 'Y',
                        'TRANSLITERATION' => 'Y',
                        'TRANS_LEN' => 100,
                        'TRANS_CASE' => 'L',
                        'TRANS_SPACE' => '_',
                        'TRANS_OTHER' => '_',
                        'TRANS_EAT' => 'Y',
                    ],
                ],
            ],
        ]);

        if (!$iblockId) {
            throw new SystemException((string)$iblock->LAST_ERROR);
        }

        return (int)$iblockId;
    }

    private static function getSiteIds(): array
    {
        $siteIds = [];
        $by = 'sort';
        $order = 'asc';
        $sites = \CSite::GetList($by, $order, ['ACTIVE' => 'Y']);
        while ($site = $sites->Fetch()) {
            $siteIds[] = $site['LID'];
        }

        if (empty($siteIds)) {
            $siteIds[] = 's1';
        }

        return $siteIds;
    }


    private static function installMailEvents(): void
    {
        self::installMailEventType();
        self::installMailEventMessage();
    }

    private static function installMailEventType(): void
    {
        $eventName = 'KK_QUIZ_LEAD';
        $languages = [];
        $by = 'sort';
        $order = 'asc';
        $rsLanguages = \CLanguage::GetList($by, $order, ['ACTIVE' => 'Y']);

        while ($language = $rsLanguages->Fetch()) {
            $lid = (string)($language['LID'] ?? '');
            if ($lid !== '') {
                $languages[] = $lid;
            }
        }

        if ($languages === []) {
            $languages = ['ru'];
        }

        foreach ($languages as $lid) {
            $exists = \CEventType::GetList([
                'TYPE_ID' => $eventName,
                'LID' => $lid,
            ])->Fetch();

            if ($exists) {
                continue;
            }

            $eventType = new \CEventType();
            $eventType->Add([
                'LID' => $lid,
                'EVENT_NAME' => $eventName,
                'NAME' => $lid === 'ru' ? 'KK Quiz: новая заявка' : 'KK Quiz: new lead',
                'DESCRIPTION' => implode("\n", [
                    '#EMAIL_TO# - Email получателя',
                    '#LEAD_ID# - ID заявки',
                    '#LEAD_ADMIN_URL# - URL заявки в админке',
                    '#LEAD_ADMIN_BLOCK# - Блок со ссылкой на заявку в админке',
                    '#QUIZ_NAME# - Название квиза',
                    '#QUIZ_CODE# - Код квиза',
                    '#RESULT_TITLE# - Результат',
                    '#CLIENT_NAME# - Имя клиента',
                    '#CLIENT_PHONE# - Телефон клиента',
                    '#CLIENT_EMAIL# - Email клиента',
                    '#CLIENT_MESSENGER# - Мессенджер клиента',
                    '#CLIENT_COMMENT# - Комментарий клиента',
                    '#ANSWERS_TEXT# - Ответы текстом',
                    '#PAGE_URL# - URL страницы',
                    '#UTM_TEXT# - UTM-метки',
                ]),
            ]);
        }
    }

    private static function installMailEventMessage(): void
    {
        $eventName = 'KK_QUIZ_LEAD';
        $by = 'id';
        $order = 'asc';
        $exists = \CEventMessage::GetList($by, $order, [
            'TYPE_ID' => $eventName,
        ])->Fetch();

        if ($exists) {
            self::updateLeadMailMessageIfDefault((int)$exists['ID']);

            return;
        }

        $message = new \CEventMessage();
        $message->Add([
            'ACTIVE' => 'Y',
            'EVENT_NAME' => $eventName,
            'LID' => self::getSiteIds(),
            'EMAIL_FROM' => '#DEFAULT_EMAIL_FROM#',
            'EMAIL_TO' => '#EMAIL_TO#',
            'SUBJECT' => 'Новая заявка квиза: #QUIZ_NAME#',
            'BODY_TYPE' => 'text',
            'MESSAGE' => self::getLeadMailMessageTemplate(),
        ]);
    }


    private static function getLeadMailMessageTemplate(): string
    {
        return implode("\n", [
            'Новая заявка квиза ##LEAD_ID#',
            '',
            'Квиз: #QUIZ_NAME#',
            'Код квиза: #QUIZ_CODE#',
            'Результат: #RESULT_TITLE#',
            '',
            'Клиент:',
            'Имя: #CLIENT_NAME#',
            'Телефон: #CLIENT_PHONE#',
            'Email: #CLIENT_EMAIL#',
            'Мессенджер: #CLIENT_MESSENGER#',
            '',
            'Комментарий:',
            '#CLIENT_COMMENT#',
            '',
            'Ответы:',
            '#ANSWERS_TEXT#',
            '',
            'Страница:',
            '#PAGE_URL#',
            '',
            'UTM:',
            '#UTM_TEXT#',
            '',
            '#LEAD_ADMIN_BLOCK#',
        ]);
    }

    private static function updateLeadMailMessageIfDefault(int $messageId): void
    {
        if ($messageId <= 0) {
            return;
        }

        $by = 'id';
        $order = 'asc';
        $message = \CEventMessage::GetList($by, $order, ['ID' => $messageId])->Fetch();
        if (!is_array($message)) {
            return;
        }

        $currentMessage = trim((string)($message['MESSAGE'] ?? ''));
        $oldDefaultMessages = [
            trim(implode("\n", [
                'Поступила новая заявка квиза.',
                '',
                'ID заявки: #LEAD_ID#',
                'Ссылка в админке: #LEAD_ADMIN_URL#',
                'Квиз: #QUIZ_NAME#',
                'Код квиза: #QUIZ_CODE#',
                'Результат: #RESULT_TITLE#',
                '',
                'Данные клиента:',
                'Имя: #CLIENT_NAME#',
                'Телефон: #CLIENT_PHONE#',
                'Email: #CLIENT_EMAIL#',
                'Мессенджер: #CLIENT_MESSENGER#',
                'Комментарий: #CLIENT_COMMENT#',
                '',
                'Ответы:',
                '#ANSWERS_TEXT#',
                '',
                'Страница:',
                '#PAGE_URL#',
                '',
                'UTM:',
                '#UTM_TEXT#',
            ])),
            trim(implode("\n", [
                'Новая заявка квиза ##LEAD_ID#',
                '',
                'Квиз: #QUIZ_NAME#',
                'Код квиза: #QUIZ_CODE#',
                'Результат: #RESULT_TITLE#',
                '',
                'Клиент:',
                'Имя: #CLIENT_NAME#',
                'Телефон: #CLIENT_PHONE#',
                'Email: #CLIENT_EMAIL#',
                'Мессенджер: #CLIENT_MESSENGER#',
                '',
                'Комментарий:',
                '#CLIENT_COMMENT#',
                '',
                'Ответы:',
                '#ANSWERS_TEXT#',
                '',
                'Страница:',
                '#PAGE_URL#',
                '',
                'UTM:',
                '#UTM_TEXT#',
                '',
                'Заявка в админке:',
                '#LEAD_ADMIN_URL#',
            ])),
        ];

        if (!in_array($currentMessage, $oldDefaultMessages, true)) {
            return;
        }

        $eventMessage = new \CEventMessage();
        $eventMessage->Update($messageId, [
            'BODY_TYPE' => 'text',
            'MESSAGE' => self::getLeadMailMessageTemplate(),
        ]);
    }

    private static function installQuizSectionUserFields(int $iblockId): void
    {
        $entityId = 'IBLOCK_' . (int)$iblockId . '_SECTION';

        $fields = [
            ['FIELD_NAME' => 'UF_KK_TITLE', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Заголовок'],
            ['FIELD_NAME' => 'UF_KK_SUBTITLE', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Подзаголовок', 'SETTINGS' => ['ROWS' => 3]],
            ['FIELD_NAME' => 'UF_KK_BUTTON_TEXT', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Текст кнопки'],
            ['FIELD_NAME' => 'UF_KK_FORM_BUTTON_TEXT', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Текст кнопки финальной формы'],
            ['FIELD_NAME' => 'UF_KK_FORM_TITLE', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Заголовок финальной формы'],
            ['FIELD_NAME' => 'UF_KK_FORM_SUBTITLE', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Подзаголовок финальной формы', 'SETTINGS' => ['ROWS' => 3]],
            ['FIELD_NAME' => 'UF_KK_START_TEXT', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Стартовый текст', 'SETTINGS' => ['ROWS' => 6]],
            ['FIELD_NAME' => 'UF_KK_START_QUESTION', 'USER_TYPE_ID' => 'integer', 'EDIT_FORM_LABEL' => 'Стартовый вопрос'],
            ['FIELD_NAME' => 'UF_KK_PROGRESS_TOTAL', 'USER_TYPE_ID' => 'integer', 'EDIT_FORM_LABEL' => 'Количество шагов в прогрессе', 'SETTINGS' => ['DEFAULT_VALUE' => 0]],
            ['FIELD_NAME' => 'UF_KK_SUCCESS_TEXT', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Текст успешного завершения', 'SETTINGS' => ['ROWS' => 6]],
            ['FIELD_NAME' => 'UF_KK_EMAIL_TO', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Email получателя'],
            ['FIELD_NAME' => 'UF_KK_FORM_FIELDS', 'USER_TYPE_ID' => 'enumeration', 'EDIT_FORM_LABEL' => 'Поля формы', 'MULTIPLE' => 'Y', 'VALUES' => self::getFormFieldEnumValues()],
            ['FIELD_NAME' => 'UF_KK_REQUIRED_FIELDS', 'USER_TYPE_ID' => 'enumeration', 'EDIT_FORM_LABEL' => 'Обязательные поля формы', 'MULTIPLE' => 'Y', 'VALUES' => self::getFormFieldEnumValues()],
            ['FIELD_NAME' => 'UF_KK_METRIKA_COUNTER_ID', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'ID счётчика Метрики'],
            ['FIELD_NAME' => 'UF_KK_METRIKA_GOAL', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Цель Метрики'],
            ['FIELD_NAME' => 'UF_KK_USE_METRIKA', 'USER_TYPE_ID' => 'boolean', 'EDIT_FORM_LABEL' => 'Использовать Метрику'],
            ['FIELD_NAME' => 'UF_KK_USE_CATALOG', 'USER_TYPE_ID' => 'boolean', 'EDIT_FORM_LABEL' => 'Показывать рекомендации'],
            ['FIELD_NAME' => 'UF_KK_CATALOG_IBLOCK_ID', 'USER_TYPE_ID' => 'integer', 'EDIT_FORM_LABEL' => 'ID инфоблока рекомендаций'],
            ['FIELD_NAME' => 'UF_KK_CATALOG_IBLOCK_IDS', 'USER_TYPE_ID' => 'enumeration', 'EDIT_FORM_LABEL' => 'Инфоблоки рекомендаций', 'MULTIPLE' => 'Y', 'VALUES' => self::getCatalogIblockEnumValues()],
            ['FIELD_NAME' => 'UF_KK_THEME', 'USER_TYPE_ID' => 'enumeration', 'EDIT_FORM_LABEL' => 'Тема оформления', 'VALUES' => self::getThemeEnumValues()],
            ['FIELD_NAME' => 'UF_KK_ACCENT_COLOR', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Акцентный цвет (HEX)'],
            ['FIELD_NAME' => 'UF_KK_ACCENT_HOVER', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Акцентный цвет при наведении (HEX)'],
            ['FIELD_NAME' => 'UF_KK_ACTIVE_COLOR', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Цвет активного элемента (HEX)'],
            ['FIELD_NAME' => 'UF_KK_PROGRESS_COLOR', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Цвет прогресс-бара (HEX)'],
            ['FIELD_NAME' => 'UF_KK_BORDER_RADIUS', 'USER_TYPE_ID' => 'integer', 'EDIT_FORM_LABEL' => 'Скругление элементов, px'],
            ['FIELD_NAME' => 'UF_KK_CONTAINER_RADIUS', 'USER_TYPE_ID' => 'integer', 'EDIT_FORM_LABEL' => 'Скругление контейнера квиза, px'],
            ['FIELD_NAME' => 'UF_KK_CARD_RADIUS', 'USER_TYPE_ID' => 'integer', 'EDIT_FORM_LABEL' => 'Скругление карточек ответов, px'],
            ['FIELD_NAME' => 'UF_KK_BUTTON_RADIUS', 'USER_TYPE_ID' => 'integer', 'EDIT_FORM_LABEL' => 'Скругление кнопок, px'],
            ['FIELD_NAME' => 'UF_KK_INPUT_RADIUS', 'USER_TYPE_ID' => 'integer', 'EDIT_FORM_LABEL' => 'Скругление полей формы, px'],
            ['FIELD_NAME' => 'UF_KK_IMAGE_RADIUS', 'USER_TYPE_ID' => 'integer', 'EDIT_FORM_LABEL' => 'Скругление изображений ответов, px'],
            ['FIELD_NAME' => 'UF_KK_IMAGE_RATIO', 'USER_TYPE_ID' => 'enumeration', 'EDIT_FORM_LABEL' => 'Соотношение сторон изображений ответов', 'VALUES' => self::getImageRatioEnumValues()],
            ['FIELD_NAME' => 'UF_KK_IMAGE_FIT', 'USER_TYPE_ID' => 'enumeration', 'EDIT_FORM_LABEL' => 'Режим изображений ответов', 'VALUES' => self::getImageFitEnumValues()],
            ['FIELD_NAME' => 'UF_KK_ALLOW_POPUP_URL', 'USER_TYPE_ID' => 'boolean', 'EDIT_FORM_LABEL' => 'Разрешить URL для попапа'],
            ['FIELD_NAME' => 'UF_KK_PRIVACY_TEXT', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Текст политики'],
            ['FIELD_NAME' => 'UF_KK_PRIVACY_URL', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Ссылка на политику'],
            ['FIELD_NAME' => 'UF_KK_REQUIRE_AGREEMENT', 'USER_TYPE_ID' => 'boolean', 'EDIT_FORM_LABEL' => 'Требовать согласие'],
        ];

        foreach ($fields as $field) {
            self::addUserField($entityId, $field);
        }

        self::syncCatalogIblockUserFieldEnums($entityId);
    }

    private static function updateExistingUserFieldLabels(int $fieldId, array $field): void
    {
        if ($fieldId <= 0 || empty($field['EDIT_FORM_LABEL'])) {
            return;
        }

        $label = ['ru' => (string)$field['EDIT_FORM_LABEL']];

        $userTypeEntity = new \CUserTypeEntity();
        $userTypeEntity->Update($fieldId, [
            'EDIT_FORM_LABEL' => $label,
            'LIST_COLUMN_LABEL' => $label,
            'LIST_FILTER_LABEL' => $label,
        ]);
    }

    private static function addUserField(string $entityId, array $field): void
    {
        $fieldName = $field['FIELD_NAME'];
        $values = isset($field['VALUES']) ? $field['VALUES'] : [];
        unset($field['VALUES']);
        $exists = \CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId, 'FIELD_NAME' => $fieldName])->Fetch();
        if ($exists) {
            self::updateExistingUserFieldLabels((int)$exists['ID'], $field);
            if ($values !== []) {
                self::syncUserFieldEnumValues((int)$exists['ID'], $values);
            }

            return;
        }

        $field = array_merge([
            'ENTITY_ID' => $entityId,
            'MULTIPLE' => 'N',
            'MANDATORY' => 'N',
            'SHOW_FILTER' => 'N',
            'SHOW_IN_LIST' => 'Y',
            'EDIT_IN_LIST' => 'Y',
            'IS_SEARCHABLE' => 'N',
        ], $field);
        $field['EDIT_FORM_LABEL'] = ['ru' => $field['EDIT_FORM_LABEL']];
        $field['LIST_COLUMN_LABEL'] = $field['EDIT_FORM_LABEL'];
        $field['LIST_FILTER_LABEL'] = $field['EDIT_FORM_LABEL'];

        $userTypeEntity = new \CUserTypeEntity();
        $userFieldId = $userTypeEntity->Add($field);
        if (!$userFieldId) {
            global $APPLICATION;
            $exception = is_object($APPLICATION) ? $APPLICATION->GetException() : null;
            $message = $exception ? $exception->GetString() : 'Не удалось создать пользовательское поле ' . $fieldName;
            throw new SystemException((string)$message);
        }

        if (!empty($values)) {
            $enum = new \CUserFieldEnum();
            $enum->SetEnumValues($userFieldId, self::formatUserFieldEnumValues($values));
        }
    }

    private static function syncUserFieldEnumValues(int $fieldId, array $desiredValues): void
    {
        $existing = [];
        $result = \CUserFieldEnum::GetList(['SORT' => 'ASC'], ['USER_FIELD_ID' => $fieldId]);
        while ($item = $result->Fetch()) {
            $existing[(string)($item['XML_ID'] ?? '')] = (int)$item['ID'];
        }

        $values = [];
        $sort = 100;
        foreach ($desiredValues as $xmlId => $label) {
            $key = isset($existing[(string)$xmlId]) ? (string)$existing[(string)$xmlId] : 'n' . $sort;
            $values[$key] = ['VALUE' => $label, 'XML_ID' => (string)$xmlId, 'SORT' => $sort, 'DEF' => 'N'];
            $sort += 100;
        }

        (new \CUserFieldEnum())->SetEnumValues($fieldId, $values);
    }

    private static function installQuizProperties(int $iblockId): void
    {
        $properties = [
            ['CODE' => 'KK_ENTITY_TYPE', 'NAME' => 'Тип сущности', 'SORT' => 100, 'PROPERTY_TYPE' => 'L', 'VALUES' => ['QUESTION' => 'QUESTION', 'RESULT' => 'RESULT'], 'SHOW_IN_LIST' => 'Y', 'FILTRABLE' => 'Y', 'LIST_COLUMN_LABEL' => 'Тип сущности', 'LIST_FILTER_LABEL' => 'Тип сущности'],
            ['CODE' => 'KK_PUBLIC_TITLE', 'NAME' => 'Заголовок на сайте', 'SORT' => 105, 'PROPERTY_TYPE' => 'S', 'SHOW_IN_LIST' => 'Y', 'LIST_COLUMN_LABEL' => 'Заголовок на сайте', 'LIST_FILTER_LABEL' => 'Заголовок на сайте'],
            ['CODE' => 'KK_ADMIN_NOTE', 'NAME' => 'Комментарий администратора', 'SORT' => 900, 'PROPERTY_TYPE' => 'S', 'ROW_COUNT' => 5],
            ['CODE' => 'KK_QUESTION_TYPE', 'NAME' => 'Тип вопроса', 'SORT' => 200, 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getQuestionTypeValues(), 'SHOW_IN_LIST' => 'Y', 'FILTRABLE' => 'Y', 'LIST_COLUMN_LABEL' => 'Тип вопроса', 'LIST_FILTER_LABEL' => 'Тип вопроса'],
            ['CODE' => 'KK_DISPLAY_TEMPLATE', 'NAME' => 'Шаблон отображения', 'SORT' => 220, 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getDisplayTemplateValues()],
            ['CODE' => 'KK_IMAGE_RATIO', 'NAME' => 'Соотношение сторон изображений ответов', 'SORT' => 225, 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getQuestionImageRatioValues()],
            ['CODE' => 'KK_IMAGE_FIT', 'NAME' => 'Режим отображения изображений ответов', 'SORT' => 226, 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getQuestionImageFitValues()],
            ['CODE' => 'KK_IS_REQUIRED', 'NAME' => 'Обязательный вопрос', 'SORT' => 230, 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getYesNoValues()],
            ['CODE' => 'KK_PLACEHOLDER', 'NAME' => 'Placeholder', 'SORT' => 240, 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_DEFAULT_NEXT_QUESTION', 'NAME' => 'Следующий вопрос по умолчанию', 'SORT' => 250, 'PROPERTY_TYPE' => 'E', 'LINK_IBLOCK_ID' => $iblockId],
            ['CODE' => 'KK_DEFAULT_RESULT', 'NAME' => 'Финальный результат по умолчанию', 'SORT' => 260, 'PROPERTY_TYPE' => 'E', 'LINK_IBLOCK_ID' => $iblockId],
            ['CODE' => 'KK_ALLOW_CUSTOM_ANSWER', 'NAME' => 'Разрешить ввести свой вариант', 'SORT' => 270, 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getYesNoValues()],
            ['CODE' => 'KK_ANSWERS', 'NAME' => 'Ответы квиза', 'SORT' => 210, 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => QuizAnswersProperty::USER_TYPE, 'ROW_COUNT' => 10],
            ['CODE' => 'KK_RESULT_MIN_SCORE', 'NAME' => 'Минимальный балл результата', 'SORT' => 310, 'PROPERTY_TYPE' => 'N'],
            ['CODE' => 'KK_RESULT_MAX_SCORE', 'NAME' => 'Максимальный балл результата', 'SORT' => 320, 'PROPERTY_TYPE' => 'N'],
            ['CODE' => 'KK_RESULT_PRIORITY', 'NAME' => 'Приоритет результата', 'SORT' => 300, 'PROPERTY_TYPE' => 'N'],
            ['CODE' => 'KK_RESULT_SUMMARY', 'NAME' => 'Краткий вывод результата', 'SORT' => 332, 'PROPERTY_TYPE' => 'S', 'ROW_COUNT' => 4],
            ['CODE' => 'KK_RESULT_WHY_TEXT', 'NAME' => 'Почему подходит', 'SORT' => 334, 'PROPERTY_TYPE' => 'S', 'ROW_COUNT' => 5],
            ['CODE' => 'KK_RESULT_SPECS_TEXT', 'NAME' => 'Ориентир по комплектующим', 'SORT' => 336, 'PROPERTY_TYPE' => 'S', 'ROW_COUNT' => 6],
            ['CODE' => 'KK_RESULT_NOTE_TEXT', 'NAME' => 'Что важно учесть', 'SORT' => 338, 'PROPERTY_TYPE' => 'S', 'ROW_COUNT' => 4],
            ['CODE' => 'KK_RESULT_CTA_TEXT', 'NAME' => 'Текст CTA', 'SORT' => 340, 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_RESULT_CTA_LINK', 'NAME' => 'Ссылка CTA', 'SORT' => 350, 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_RESULT_VIDEO_URL', 'NAME' => 'Видео результата — URL', 'SORT' => 352, 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_RESULT_VIDEO_TITLE', 'NAME' => 'Видео результата — заголовок', 'SORT' => 354, 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_RESULT_VIDEO_POSITION', 'NAME' => 'Позиция видео результата', 'SORT' => 356, 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getResultVideoPositionValues()],
            ['CODE' => 'KK_RESULT_SHOW_FORM', 'NAME' => 'Показывать форму', 'SORT' => 360, 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getYesNoValues()],
            ['CODE' => 'KK_RESULT_FORM_TITLE', 'NAME' => 'Заголовок блока формы результата', 'SORT' => 361, 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_RESULT_FORM_INTRO', 'NAME' => 'Текст перед формой результата', 'SORT' => 362, 'PROPERTY_TYPE' => 'S', 'ROW_COUNT' => 4],
            ['CODE' => 'KK_RESULT_FORM_BUTTON_TEXT', 'NAME' => 'Текст кнопки открытия формы', 'SORT' => 364, 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_RESULT_CATALOG_SECTION', 'NAME' => 'Раздел рекомендаций', 'SORT' => 380, 'PROPERTY_TYPE' => 'G'],
            ['CODE' => 'KK_RESULT_CATALOG_PRODUCTS', 'NAME' => 'Рекомендуемые элементы', 'SORT' => 390, 'PROPERTY_TYPE' => 'E', 'MULTIPLE' => 'Y'],
            ['CODE' => 'KK_RESULT_BADGE', 'NAME' => 'Бейдж результата', 'SORT' => 330, 'PROPERTY_TYPE' => 'S'],
        ];

        self::addIblockProperties($iblockId, $properties);
    }

    private static function deleteObsoleteQuizProperties(int $iblockId): void
    {
        $obsoleteCodes = [
            'KK_' . 'CODE',
        ];

        foreach ($obsoleteCodes as $code) {
            $property = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $code])->Fetch();
            if (!$property) {
                continue;
            }

            \CIBlockProperty::Delete((int)$property['ID']);
        }
    }

    private static function installLeadProperties(int $iblockId): void
    {
        $properties = [
            ['CODE' => 'KK_LEAD_QUIZ_SECTION_ID', 'NAME' => 'ID раздела квиза', 'PROPERTY_TYPE' => 'N'],
            ['CODE' => 'KK_LEAD_QUIZ_CODE', 'NAME' => 'Код квиза', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_QUIZ_NAME', 'NAME' => 'Название квиза', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_RESULT_ID', 'NAME' => 'ID результата', 'PROPERTY_TYPE' => 'N'],
            ['CODE' => 'KK_LEAD_RESULT_CODE', 'NAME' => 'Код результата', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_RESULT_TITLE', 'NAME' => 'Заголовок результата', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_STATUS', 'NAME' => 'Статус обработки', 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getLeadStatusEnumValues()],
            ['CODE' => 'KK_LEAD_CLIENT_NAME', 'NAME' => 'Имя клиента', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_CLIENT_PHONE', 'NAME' => 'Телефон клиента', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_CLIENT_EMAIL', 'NAME' => 'Email клиента', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_CLIENT_MESSENGER', 'NAME' => 'Мессенджер клиента', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_CLIENT_COMMENT', 'NAME' => 'Комментарий клиента', 'PROPERTY_TYPE' => 'S', 'ROW_COUNT' => 5],
            ['CODE' => 'KK_LEAD_MANAGER_NOTE', 'NAME' => 'Комментарий менеджера', 'PROPERTY_TYPE' => 'S', 'ROW_COUNT' => 5],
            ['CODE' => 'KK_LEAD_PAGE_URL', 'NAME' => 'URL страницы', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_REFERER', 'NAME' => 'Referer', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_UTM_SOURCE', 'NAME' => 'UTM Source', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_UTM_MEDIUM', 'NAME' => 'UTM Medium', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_UTM_CAMPAIGN', 'NAME' => 'UTM Campaign', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_UTM_CONTENT', 'NAME' => 'UTM Content', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_UTM_TERM', 'NAME' => 'UTM Term', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_USER_AGENT', 'NAME' => 'User Agent', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_IP', 'NAME' => 'IP', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_SESSION_ID', 'NAME' => 'ID сессии', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_ANSWERS_DATA', 'NAME' => 'Данные ответов', 'PROPERTY_TYPE' => 'S', 'ROW_COUNT' => 10],
            ['CODE' => 'KK_LEAD_AGREEMENT_ACCEPTED', 'NAME' => 'Согласие с политикой', 'PROPERTY_TYPE' => 'L', 'LIST_TYPE' => 'C', 'VALUES' => self::getYesNoValues()],
            ['CODE' => 'KK_LEAD_PRIVACY_URL', 'NAME' => 'URL политики', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_EMAIL_SENT', 'NAME' => 'Email отправлен', 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getYesNoValues()],
            ['CODE' => 'KK_LEAD_EMAIL_SENT_AT', 'NAME' => 'Дата отправки email', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => 'DateTime'],
            ['CODE' => 'KK_LEAD_TELEGRAM_SENT', 'NAME' => 'Telegram отправлен', 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getYesNoValues()],
            ['CODE' => 'KK_LEAD_TELEGRAM_SENT_AT', 'NAME' => 'Дата отправки Telegram', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => 'DateTime'],
            ['CODE' => 'KK_LEAD_TELEGRAM_ERROR', 'NAME' => 'Ошибка Telegram', 'PROPERTY_TYPE' => 'S', 'ROW_COUNT' => 5],
            ['CODE' => 'KK_LEAD_WEBHOOK_SENT', 'NAME' => 'Webhook отправлен', 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getYesNoValues()],
            ['CODE' => 'KK_LEAD_WEBHOOK_SENT_AT', 'NAME' => 'Webhook отправлен в', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => 'DateTime'],
            ['CODE' => 'KK_LEAD_WEBHOOK_STATUS', 'NAME' => 'Webhook статус', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_WEBHOOK_ERROR', 'NAME' => 'Webhook ошибка', 'PROPERTY_TYPE' => 'S', 'ROW_COUNT' => 5],
            ['CODE' => 'KK_LEAD_BITRIX24_SENT', 'NAME' => 'Bitrix24 отправлен', 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getYesNoValues()],
            ['CODE' => 'KK_LEAD_BITRIX24_SENT_AT', 'NAME' => 'Bitrix24 отправлен в', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => 'DateTime'],
            ['CODE' => 'KK_LEAD_BITRIX24_STATUS', 'NAME' => 'Bitrix24 статус', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_BITRIX24_ERROR', 'NAME' => 'Bitrix24 ошибка', 'PROPERTY_TYPE' => 'S', 'ROW_COUNT' => 5],
            ['CODE' => 'KK_LEAD_BITRIX24_LEAD_ID', 'NAME' => 'Bitrix24 ID лида', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_AMOCRM_SENT', 'NAME' => 'amoCRM отправлен', 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getYesNoValues()],
            ['CODE' => 'KK_LEAD_AMOCRM_SENT_AT', 'NAME' => 'amoCRM отправлен в', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => 'DateTime'],
            ['CODE' => 'KK_LEAD_AMOCRM_STATUS', 'NAME' => 'amoCRM статус', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_AMOCRM_ERROR', 'NAME' => 'amoCRM ошибка', 'PROPERTY_TYPE' => 'S', 'ROW_COUNT' => 5],
            ['CODE' => 'KK_LEAD_AMOCRM_LEAD_ID', 'NAME' => 'amoCRM ID сделки', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_AMOCRM_CONTACT_ID', 'NAME' => 'amoCRM ID контакта', 'PROPERTY_TYPE' => 'S'],
        ];

        self::addIblockProperties($iblockId, $properties);
    }

    private static function addIblockProperties(int $iblockId, array $properties): void
    {
        foreach ($properties as $property) {
            $exists = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $property['CODE']])->Fetch();
            if ($exists) {
                self::updateExistingIblockProperty((int)$exists['ID'], $property);
                if (!empty($property['VALUES'])) {
                    self::syncIblockPropertyEnumValues((int)$exists['ID'], $property['VALUES']);
                }
                continue;
            }

            $values = isset($property['VALUES']) ? $property['VALUES'] : [];
            unset($property['VALUES']);

            $property = array_merge([
                'IBLOCK_ID' => $iblockId,
                'ACTIVE' => 'Y',
                'SORT' => 500,
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
            ], $property);

            if (!empty($values)) {
                $property['LIST_TYPE'] = 'L';
                $property['VALUES'] = self::formatPropertyEnumValues($values);
            }

            $iblockProperty = new \CIBlockProperty();
            $propertyId = $iblockProperty->Add($property);
            if (!$propertyId) {
                throw new SystemException((string)$iblockProperty->LAST_ERROR);
            }
        }
    }

    private static function syncIblockPropertyEnumValues(int $propertyId, array $desiredValues): void
    {
        $existing = [];
        $result = \CIBlockPropertyEnum::GetList(['SORT' => 'ASC'], ['PROPERTY_ID' => $propertyId]);
        while ($item = $result->Fetch()) {
            $existing[(string)($item['XML_ID'] ?? '')] = (int)$item['ID'];
        }

        $enum = new \CIBlockPropertyEnum();
        $sort = 100;
        foreach ($desiredValues as $xmlId => $value) {
            $xmlId = (string)$xmlId;
            $fields = self::normalizeIblockEnumValue($xmlId, $value, $sort);
            if (isset($existing[$xmlId])) {
                $enum->Update($existing[$xmlId], $fields);
            } else {
                $fields['PROPERTY_ID'] = $propertyId;
                $enum->Add($fields);
            }
            $sort += 100;
        }
    }

    private static function normalizeIblockEnumValue(string $xmlId, mixed $value, int $sort): array
    {
        $default = 'N';
        if (is_array($value)) {
            $default = (string)($value['DEF'] ?? 'N') === 'Y' ? 'Y' : 'N';
            $value = (string)($value['VALUE'] ?? '');
        } else {
            $value = (string)$value;
        }

        return [
            'VALUE' => $value,
            'XML_ID' => $xmlId,
            'SORT' => $sort,
            'DEF' => $default,
        ];
    }


    private static function updateExistingIblockProperty(int $propertyId, array $property): void
    {
        $fields = [];

        if (isset($property['SORT'])) {
            $fields['SORT'] = $property['SORT'];
        }

        if (isset($property['NAME'])) {
            $fields['NAME'] = $property['NAME'];
        }

        foreach (['SHOW_IN_LIST', 'FILTRABLE', 'LIST_COLUMN_LABEL', 'LIST_FILTER_LABEL'] as $fieldName) {
            if (isset($property[$fieldName])) {
                $fields[$fieldName] = $property[$fieldName];
            }
        }

        if (($property['CODE'] ?? '') === 'KK_ANSWERS') {
            $fields = array_merge($fields, [
                'NAME' => $property['NAME'],
                'PROPERTY_TYPE' => $property['PROPERTY_TYPE'],
                'USER_TYPE' => $property['USER_TYPE'],
                'ROW_COUNT' => $property['ROW_COUNT'],
            ]);
        }

        if ($fields === []) {
            return;
        }

        $iblockProperty = new \CIBlockProperty();
        if (!$iblockProperty->Update($propertyId, $fields)) {
            throw new SystemException((string)$iblockProperty->LAST_ERROR);
        }
    }


    private static function configureLeadElementAdminForm(int $iblockId): void
    {
        if ($iblockId <= 0 || !class_exists('CUserOptions')) {
            return;
        }

        $propertyIds = self::getIblockPropertyIdsByCode($iblockId);
        $tabs = [
            [
                'id' => 'edit1',
                'name' => 'Заявка',
                'fields' => [
                    ['NAME', 'Название'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_STATUS'), 'Статус обработки'],
                    ['DETAIL_TEXT', 'Ответы'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_CLIENT_NAME'), 'Имя клиента'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_CLIENT_PHONE'), 'Телефон клиента'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_CLIENT_EMAIL'), 'Email клиента'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_CLIENT_MESSENGER'), 'Мессенджер клиента'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_CLIENT_COMMENT'), 'Комментарий клиента'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_MANAGER_NOTE'), 'Комментарий менеджера'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_QUIZ_NAME'), 'Название квиза'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_RESULT_TITLE'), 'Результат'],
                ],
            ],
            [
                'id' => 'edit2',
                'name' => 'Метрика',
                'fields' => [
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_PAGE_URL'), 'URL страницы'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_REFERER'), 'Referer'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_UTM_SOURCE'), 'UTM Source'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_UTM_MEDIUM'), 'UTM Medium'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_UTM_CAMPAIGN'), 'UTM Campaign'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_UTM_CONTENT'), 'UTM Content'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_UTM_TERM'), 'UTM Term'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_USER_AGENT'), 'User Agent'],
                ],
            ],
            [
                'id' => 'edit3',
                'name' => 'Технические данные',
                'fields' => [
                    ['ACTIVE', 'Активность'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_QUIZ_SECTION_ID'), 'ID раздела квиза'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_QUIZ_CODE'), 'Код квиза'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_RESULT_ID'), 'ID результата'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_RESULT_CODE'), 'Код результата'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_IP'), 'IP'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_SESSION_ID'), 'ID сессии'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_ANSWERS_DATA'), 'Данные ответов JSON'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_AGREEMENT_ACCEPTED'), 'Согласие с политикой'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_PRIVACY_URL'), 'URL политики'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_EMAIL_SENT'), 'Email отправлен'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_EMAIL_SENT_AT'), 'Дата отправки email'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_TELEGRAM_SENT'), 'Telegram отправлен'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_TELEGRAM_SENT_AT'), 'Дата отправки Telegram'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_TELEGRAM_ERROR'), 'Ошибка Telegram'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_WEBHOOK_SENT'), 'Webhook отправлен'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_WEBHOOK_SENT_AT'), 'Webhook отправлен в'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_WEBHOOK_STATUS'), 'Webhook статус'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_WEBHOOK_ERROR'), 'Webhook ошибка'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_BITRIX24_SENT'), 'Bitrix24 отправлен'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_BITRIX24_SENT_AT'), 'Bitrix24 отправлен в'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_BITRIX24_STATUS'), 'Bitrix24 статус'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_BITRIX24_ERROR'), 'Bitrix24 ошибка'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_BITRIX24_LEAD_ID'), 'Bitrix24 ID лида'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_AMOCRM_SENT'), 'amoCRM отправлен'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_AMOCRM_SENT_AT'), 'amoCRM отправлен в'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_AMOCRM_STATUS'), 'amoCRM статус'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_AMOCRM_ERROR'), 'amoCRM ошибка'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_AMOCRM_LEAD_ID'), 'amoCRM ID сделки'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_AMOCRM_CONTACT_ID'), 'amoCRM ID контакта'],
                ],
            ],
        ];

        $tabsString = self::buildAdminFormTabsString($tabs);
        if ($tabsString === '') {
            return;
        }

        \CUserOptions::SetOption(
            'form',
            'form_element_' . $iblockId,
            ['tabs' => $tabsString],
            true
        );
    }

    private static function getIblockPropertyIdsByCode(int $iblockId): array
    {
        $ids = [];
        $properties = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId]);
        while ($property = $properties->Fetch()) {
            $code = (string)($property['CODE'] ?? '');
            $id = (int)($property['ID'] ?? 0);
            if ($code !== '' && $id > 0) {
                $ids[$code] = $id;
            }
        }

        return $ids;
    }

    private static function getPropertyFormField(array $propertyIds, string $code): ?string
    {
        $id = (int)($propertyIds[$code] ?? 0);

        return $id > 0 ? 'PROPERTY_' . $id : null;
    }

    private static function buildAdminFormTabsString(array $tabs): string
    {
        $parts = [];
        foreach ($tabs as $tab) {
            $fields = [];
            foreach ((array)($tab['fields'] ?? []) as $field) {
                $fieldId = $field[0] ?? null;
                $fieldTitle = $field[1] ?? '';
                if (!is_string($fieldId) || $fieldId === '') {
                    continue;
                }

                $fields[] = $fieldId . '--#--' . $fieldTitle;
            }

            if ($fields === []) {
                continue;
            }

            $parts[] = $tab['id'] . '--#--' . $tab['name'] . '--,--' . implode('--,--', $fields);
        }

        return implode('--;--', $parts);
    }

    private static function syncCatalogIblockUserFieldEnums(string $entityId): void
    {
        $field = \CUserTypeEntity::GetList([], [
            'ENTITY_ID' => $entityId,
            'FIELD_NAME' => 'UF_KK_CATALOG_IBLOCK_IDS',
        ])->Fetch();

        $fieldId = (int)($field['ID'] ?? 0);
        if (!is_array($field) || $fieldId <= 0) {
            return;
        }

        $existingByXmlId = [];
        $existingValues = [];

        $rsEnum = \CUserFieldEnum::GetList([], ['USER_FIELD_ID' => $fieldId]);
        while ($enumItem = $rsEnum->Fetch()) {
            $enumId = (int)($enumItem['ID'] ?? 0);
            $xmlId = (string)($enumItem['XML_ID'] ?? '');

            if ($enumId <= 0 || $xmlId === '') {
                continue;
            }

            $existingByXmlId[$xmlId] = $enumId;
            $existingValues[$enumId] = [
                'VALUE' => (string)($enumItem['VALUE'] ?? ''),
                'XML_ID' => $xmlId,
                'DEF' => (string)($enumItem['DEF'] ?? 'N'),
                'SORT' => (int)($enumItem['SORT'] ?? 500),
            ];
        }

        $values = [];
        $currentXmlIds = [];
        $sort = 100;

        foreach (self::getCatalogIblockEnumValues() as $xmlId => $label) {
            $xmlId = (string)$xmlId;
            $currentXmlIds[$xmlId] = true;
            $key = isset($existingByXmlId[$xmlId])
                ? (string)$existingByXmlId[$xmlId]
                : 'n' . $sort;

            $values[$key] = [
                'VALUE' => $label,
                'XML_ID' => $xmlId,
                'DEF' => 'N',
                'SORT' => $sort,
            ];

            $sort += 100;
        }

        foreach ($existingValues as $enumId => $enumValue) {
            $xmlId = (string)($enumValue['XML_ID'] ?? '');
            if ($xmlId === '' || isset($currentXmlIds[$xmlId])) {
                continue;
            }

            $values[(string)$enumId] = $enumValue;
        }

        $enum = new \CUserFieldEnum();
        $enum->SetEnumValues($fieldId, $values);
    }

    private static function getCatalogIblockEnumValues(): array
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return [];
        }

        $values = [];
        $iblocks = \CIBlock::GetList(
            ['IBLOCK_TYPE' => 'ASC', 'SORT' => 'ASC', 'NAME' => 'ASC'],
            ['ACTIVE' => 'Y']
        );

        while ($iblock = $iblocks->Fetch()) {
            $id = (int)$iblock['ID'];
            if ($id <= 0) {
                continue;
            }

            $type = (string)($iblock['IBLOCK_TYPE_ID'] ?? '');
            $name = (string)($iblock['NAME'] ?? '');

            $values[(string)$id] = '[' . $type . '] ' . $name . ' [' . $id . ']';
        }

        return $values;
    }

    private static function getFormFieldEnumValues(): array
    {
        return [
            'name' => 'Имя',
            'phone' => 'Телефон',
            'email' => 'Email',
            'messenger' => 'Мессенджер',
            'comment' => 'Комментарий',
        ];
    }

    private static function getThemeEnumValues(): array
    {
        return [
            'default' => 'Стандартная',
            'light' => 'Светлая',
            'dark' => 'Тёмная',
            'compact' => 'Компактная',
        ];
    }

    private static function getImageRatioEnumValues(): array
    {
        return ['1:1' => '1:1', '3:4' => '3:4', '4:3' => '4:3', '9:16' => '9:16', '16:9' => '16:9'];
    }

    private static function getQuestionImageRatioValues(): array
    {
        return ['inherit' => 'Как у квиза', '1:1' => '1:1', '3:4' => '3:4', '4:3' => '4:3', '9:16' => '9:16', '16:9' => '16:9'];
    }

    private static function getImageFitEnumValues(): array
    {
        return ['cover' => 'Cover (с обрезкой)', 'contain' => 'Contain (целиком)'];
    }

    private static function getQuestionImageFitValues(): array
    {
        return ['inherit' => 'Как у квиза', 'cover' => 'Cover, с обрезкой', 'contain' => 'Contain, целиком'];
    }

    private static function getQuestionTypeValues(): array
    {
        return [
            'radio' => 'Один вариант ответа',
            'checkbox' => 'Несколько вариантов ответа',
            'text' => 'Текстовое поле',
            'textarea' => 'Большое текстовое поле',
            'phone' => 'Телефон',
            'email' => 'Email',
        ];
    }

    private static function getDisplayTemplateValues(): array
    {
        return [
            'list' => 'Обычный список',
            'cards' => 'Карточки без изображений',
            'image_cards' => 'Карточки с изображениями',
            'select' => 'Выпадающий список',
        ];
    }


    private static function getLeadStatusEnumValues(): array
    {
        return [
            'new' => 'Новая',
            'in_progress' => 'В работе',
            'done' => 'Обработана',
            'spam' => 'Спам / мусор',
        ];
    }

    private static function getResultVideoPositionValues(): array
    {
        return [
            'after_text' => ['VALUE' => 'После текста результата', 'DEF' => 'Y'],
            'before_form' => 'Перед формой заявки',
            'after_form' => 'После формы заявки',
            'before_products' => 'Перед рекомендациями',
        ];
    }

    private static function getYesNoValues(): array
    {
        return [
            'Y' => 'Да',
            'N' => 'Нет',
        ];
    }

    private static function formatPropertyEnumValues(array $values): array
    {
        $formatted = [];
        $sort = 100;
        foreach ($values as $xmlId => $value) {
            $default = 'N';
            if (is_array($value)) {
                $default = (string)($value['DEF'] ?? 'N') === 'Y' ? 'Y' : 'N';
                $value = (string)($value['VALUE'] ?? '');
            }

            $formatted[] = [
                'VALUE' => $value,
                'XML_ID' => $xmlId,
                'DEF' => $default,
                'SORT' => $sort,
            ];
            $sort += 100;
        }

        return $formatted;
    }

    private static function formatUserFieldEnumValues(array $values): array
    {
        $formatted = [];
        $sort = 100;
        foreach ($values as $xmlId => $value) {
            $formatted['n' . $sort] = [
                'VALUE' => $value,
                'XML_ID' => $xmlId,
                'DEF' => 'N',
                'SORT' => $sort,
            ];
            $sort += 100;
        }

        return $formatted;
    }
}
