<?php

declare(strict_types=1);

namespace Kk\Quiz\Iblock;

use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Kk\Quiz\Admin\ElementFormAssets;
use Kk\Quiz\Iblock\Property\QuizAnswersProperty;

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

        // Инфоблоки и пользовательские данные намеренно не удаляются.
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
            'MESSAGE' => implode("\n", [
                'Поступила новая заявка квиза.',
                '',
                'ID заявки: #LEAD_ID#',
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
            ]),
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
            ['FIELD_NAME' => 'UF_KK_START_TEXT', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Стартовый текст', 'SETTINGS' => ['ROWS' => 6]],
            ['FIELD_NAME' => 'UF_KK_SUCCESS_TEXT', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Текст успешного завершения', 'SETTINGS' => ['ROWS' => 6]],
            ['FIELD_NAME' => 'UF_KK_EMAIL_TO', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Email получателя'],
            ['FIELD_NAME' => 'UF_KK_FORM_FIELDS', 'USER_TYPE_ID' => 'enumeration', 'EDIT_FORM_LABEL' => 'Поля формы', 'MULTIPLE' => 'Y', 'VALUES' => self::getFormFieldEnumValues()],
            ['FIELD_NAME' => 'UF_KK_REQUIRED_FIELDS', 'USER_TYPE_ID' => 'enumeration', 'EDIT_FORM_LABEL' => 'Обязательные поля формы', 'MULTIPLE' => 'Y', 'VALUES' => self::getFormFieldEnumValues()],
            ['FIELD_NAME' => 'UF_KK_METRIKA_COUNTER_ID', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'ID счётчика Метрики'],
            ['FIELD_NAME' => 'UF_KK_METRIKA_GOAL', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Цель Метрики'],
            ['FIELD_NAME' => 'UF_KK_USE_METRIKA', 'USER_TYPE_ID' => 'boolean', 'EDIT_FORM_LABEL' => 'Использовать Метрику'],
            ['FIELD_NAME' => 'UF_KK_USE_CATALOG', 'USER_TYPE_ID' => 'boolean', 'EDIT_FORM_LABEL' => 'Использовать каталог'],
            ['FIELD_NAME' => 'UF_KK_CATALOG_IBLOCK_ID', 'USER_TYPE_ID' => 'integer', 'EDIT_FORM_LABEL' => 'ID инфоблока каталога'],
            ['FIELD_NAME' => 'UF_KK_THEME', 'USER_TYPE_ID' => 'enumeration', 'EDIT_FORM_LABEL' => 'Тема оформления', 'VALUES' => self::getThemeEnumValues()],
            ['FIELD_NAME' => 'UF_KK_ALLOW_POPUP_URL', 'USER_TYPE_ID' => 'boolean', 'EDIT_FORM_LABEL' => 'Разрешить URL для попапа'],
            ['FIELD_NAME' => 'UF_KK_PRIVACY_TEXT', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Текст политики'],
            ['FIELD_NAME' => 'UF_KK_PRIVACY_URL', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Ссылка на политику'],
            ['FIELD_NAME' => 'UF_KK_REQUIRE_AGREEMENT', 'USER_TYPE_ID' => 'boolean', 'EDIT_FORM_LABEL' => 'Требовать согласие'],
        ];

        foreach ($fields as $field) {
            self::addUserField($entityId, $field);
        }
    }

    private static function addUserField(string $entityId, array $field): void
    {
        $fieldName = $field['FIELD_NAME'];
        $exists = \CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId, 'FIELD_NAME' => $fieldName])->Fetch();
        if ($exists) {
            return;
        }

        $values = isset($field['VALUES']) ? $field['VALUES'] : [];
        unset($field['VALUES']);

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

    private static function installQuizProperties(int $iblockId): void
    {
        $properties = [
            ['CODE' => 'KK_ENTITY_TYPE', 'NAME' => 'Тип сущности', 'SORT' => 100, 'PROPERTY_TYPE' => 'L', 'VALUES' => ['QUESTION' => 'QUESTION', 'RESULT' => 'RESULT']],
            ['CODE' => 'KK_ADMIN_NOTE', 'NAME' => 'Комментарий администратора', 'SORT' => 900, 'PROPERTY_TYPE' => 'S', 'ROW_COUNT' => 5],
            ['CODE' => 'KK_QUESTION_TYPE', 'NAME' => 'Тип вопроса', 'SORT' => 200, 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getQuestionTypeValues()],
            ['CODE' => 'KK_DISPLAY_TEMPLATE', 'NAME' => 'Шаблон отображения', 'SORT' => 220, 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getDisplayTemplateValues()],
            ['CODE' => 'KK_IS_REQUIRED', 'NAME' => 'Обязательный вопрос', 'SORT' => 230, 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getYesNoValues()],
            ['CODE' => 'KK_PLACEHOLDER', 'NAME' => 'Placeholder', 'SORT' => 240, 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_DEFAULT_NEXT_QUESTION', 'NAME' => 'Следующий вопрос по умолчанию', 'SORT' => 250, 'PROPERTY_TYPE' => 'E', 'LINK_IBLOCK_ID' => $iblockId],
            ['CODE' => 'KK_ANSWERS', 'NAME' => 'Ответы квиза', 'SORT' => 210, 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => QuizAnswersProperty::USER_TYPE, 'ROW_COUNT' => 10],
            ['CODE' => 'KK_RESULT_MIN_SCORE', 'NAME' => 'Минимальный балл результата', 'SORT' => 310, 'PROPERTY_TYPE' => 'N'],
            ['CODE' => 'KK_RESULT_MAX_SCORE', 'NAME' => 'Максимальный балл результата', 'SORT' => 320, 'PROPERTY_TYPE' => 'N'],
            ['CODE' => 'KK_RESULT_PRIORITY', 'NAME' => 'Приоритет результата', 'SORT' => 300, 'PROPERTY_TYPE' => 'N'],
            ['CODE' => 'KK_RESULT_CTA_TEXT', 'NAME' => 'Текст CTA', 'SORT' => 340, 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_RESULT_CTA_LINK', 'NAME' => 'Ссылка CTA', 'SORT' => 350, 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_RESULT_SHOW_FORM', 'NAME' => 'Показывать форму', 'SORT' => 360, 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getYesNoValues()],
            ['CODE' => 'KK_RESULT_CATALOG_SECTION', 'NAME' => 'Раздел каталога', 'SORT' => 370, 'PROPERTY_TYPE' => 'G'],
            ['CODE' => 'KK_RESULT_CATALOG_PRODUCTS', 'NAME' => 'Товары каталога', 'SORT' => 380, 'PROPERTY_TYPE' => 'E', 'MULTIPLE' => 'Y'],
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
            ['CODE' => 'KK_LEAD_CLIENT_NAME', 'NAME' => 'Имя клиента', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_CLIENT_PHONE', 'NAME' => 'Телефон клиента', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_CLIENT_EMAIL', 'NAME' => 'Email клиента', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_CLIENT_MESSENGER', 'NAME' => 'Мессенджер клиента', 'PROPERTY_TYPE' => 'S'],
            ['CODE' => 'KK_LEAD_CLIENT_COMMENT', 'NAME' => 'Комментарий клиента', 'PROPERTY_TYPE' => 'S', 'ROW_COUNT' => 5],
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
        ];

        self::addIblockProperties($iblockId, $properties);
    }

    private static function addIblockProperties(int $iblockId, array $properties): void
    {
        foreach ($properties as $property) {
            $exists = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $property['CODE']])->Fetch();
            if ($exists) {
                self::updateExistingIblockProperty((int)$exists['ID'], $property);
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


    private static function updateExistingIblockProperty(int $propertyId, array $property): void
    {
        $fields = [];

        if (isset($property['SORT'])) {
            $fields['SORT'] = $property['SORT'];
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
                    ['DETAIL_TEXT', 'Ответы'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_CLIENT_NAME'), 'Имя клиента'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_CLIENT_PHONE'), 'Телефон клиента'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_CLIENT_EMAIL'), 'Email клиента'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_CLIENT_MESSENGER'), 'Мессенджер клиента'],
                    [self::getPropertyFormField($propertyIds, 'KK_LEAD_CLIENT_COMMENT'), 'Комментарий клиента'],
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

    private static function getQuestionTypeValues(): array
    {
        return [
            'radio' => 'Один вариант ответа',
            'checkbox' => 'Несколько вариантов ответа',
            'select' => 'Выпадающий список',
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
            'input' => 'Поле ввода',
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
            $formatted[] = [
                'VALUE' => $value,
                'XML_ID' => $xmlId,
                'DEF' => 'N',
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
