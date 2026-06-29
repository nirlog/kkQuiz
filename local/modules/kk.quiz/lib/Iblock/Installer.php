<?php

namespace Kk\Quiz\Iblock;

use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;

class Installer
{
    const IBLOCK_TYPE_ID = 'kk_quiz';
    const QUIZZES_IBLOCK_CODE = 'kk_quizzes';
    const LEADS_IBLOCK_CODE = 'kk_quiz_leads';

    public static function install()
    {
        if (!Loader::includeModule('iblock')) {
            throw new SystemException('Для установки инфоблоков модуля KK Quiz необходимо установить модуль iblock.');
        }

        self::installIblockType();

        $quizzesIblockId = self::installIblock(self::QUIZZES_IBLOCK_CODE, 'Квизы');
        $leadsIblockId = self::installIblock(self::LEADS_IBLOCK_CODE, 'Заявки квизов');

        self::installQuizSectionUserFields($quizzesIblockId);
        self::installQuizProperties($quizzesIblockId);
        self::installLeadProperties($leadsIblockId);
    }

    public static function uninstall()
    {
        // Инфоблоки и пользовательские данные намеренно не удаляются.
    }

    protected static function installIblockType()
    {
        $type = \CIBlockType::GetByID(self::IBLOCK_TYPE_ID)->Fetch();
        if ($type) {
            return;
        }

        $iblockType = new \CIBlockType();
        $result = $iblockType->Add(array(
            'ID' => self::IBLOCK_TYPE_ID,
            'SECTIONS' => 'Y',
            'IN_RSS' => 'N',
            'SORT' => 500,
            'LANG' => array(
                'ru' => array(
                    'NAME' => 'KK Quiz',
                    'SECTION_NAME' => 'Квизы',
                    'ELEMENT_NAME' => 'Элементы квиза',
                ),
            ),
        ));

        if (!$result) {
            throw new SystemException($iblockType->LAST_ERROR);
        }
    }

    protected static function installIblock($code, $name)
    {
        $exists = \CIBlock::GetList(array(), array('TYPE' => self::IBLOCK_TYPE_ID, '=CODE' => $code))->Fetch();
        if ($exists) {
            return (int)$exists['ID'];
        }

        $siteIds = self::getSiteIds();
        $iblock = new \CIBlock();
        $iblockId = $iblock->Add(array(
            'ACTIVE' => 'Y',
            'NAME' => $name,
            'CODE' => $code,
            'IBLOCK_TYPE_ID' => self::IBLOCK_TYPE_ID,
            'SITE_ID' => $siteIds,
            'SORT' => 500,
            'GROUP_ID' => array('2' => 'R'),
            'FIELDS' => array(
                'CODE' => array(
                    'IS_REQUIRED' => 'N',
                    'DEFAULT_VALUE' => array(
                        'UNIQUE' => 'Y',
                        'TRANSLITERATION' => 'Y',
                        'TRANS_LEN' => 100,
                        'TRANS_CASE' => 'L',
                        'TRANS_SPACE' => '_',
                        'TRANS_OTHER' => '_',
                        'TRANS_EAT' => 'Y',
                    ),
                ),
            ),
        ));

        if (!$iblockId) {
            throw new SystemException($iblock->LAST_ERROR);
        }

        return (int)$iblockId;
    }

    protected static function getSiteIds()
    {
        $siteIds = array();
        $sites = \CSite::GetList($by = 'sort', $order = 'asc', array('ACTIVE' => 'Y'));
        while ($site = $sites->Fetch()) {
            $siteIds[] = $site['LID'];
        }

        if (empty($siteIds)) {
            $siteIds[] = 's1';
        }

        return $siteIds;
    }

    protected static function installQuizSectionUserFields($iblockId)
    {
        $entityId = 'IBLOCK_' . (int)$iblockId . '_SECTION';

        $fields = array(
            array('FIELD_NAME' => 'UF_KK_TITLE', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Заголовок'),
            array('FIELD_NAME' => 'UF_KK_SUBTITLE', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Подзаголовок', 'SETTINGS' => array('ROWS' => 3)),
            array('FIELD_NAME' => 'UF_KK_BUTTON_TEXT', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Текст кнопки'),
            array('FIELD_NAME' => 'UF_KK_START_TEXT', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Стартовый текст', 'SETTINGS' => array('ROWS' => 6)),
            array('FIELD_NAME' => 'UF_KK_SUCCESS_TEXT', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Текст успешного завершения', 'SETTINGS' => array('ROWS' => 6)),
            array('FIELD_NAME' => 'UF_KK_EMAIL_TO', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Email получателя'),
            array('FIELD_NAME' => 'UF_KK_FORM_FIELDS', 'USER_TYPE_ID' => 'enumeration', 'EDIT_FORM_LABEL' => 'Поля формы', 'MULTIPLE' => 'Y', 'VALUES' => self::getFormFieldEnumValues()),
            array('FIELD_NAME' => 'UF_KK_REQUIRED_FIELDS', 'USER_TYPE_ID' => 'enumeration', 'EDIT_FORM_LABEL' => 'Обязательные поля формы', 'MULTIPLE' => 'Y', 'VALUES' => self::getFormFieldEnumValues()),
            array('FIELD_NAME' => 'UF_KK_METRIKA_COUNTER_ID', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'ID счётчика Метрики'),
            array('FIELD_NAME' => 'UF_KK_USE_METRIKA', 'USER_TYPE_ID' => 'boolean', 'EDIT_FORM_LABEL' => 'Использовать Метрику'),
            array('FIELD_NAME' => 'UF_KK_USE_CATALOG', 'USER_TYPE_ID' => 'boolean', 'EDIT_FORM_LABEL' => 'Использовать каталог'),
            array('FIELD_NAME' => 'UF_KK_CATALOG_IBLOCK_ID', 'USER_TYPE_ID' => 'integer', 'EDIT_FORM_LABEL' => 'ID инфоблока каталога'),
            array('FIELD_NAME' => 'UF_KK_THEME', 'USER_TYPE_ID' => 'enumeration', 'EDIT_FORM_LABEL' => 'Тема оформления', 'VALUES' => self::getThemeEnumValues()),
            array('FIELD_NAME' => 'UF_KK_ALLOW_POPUP_URL', 'USER_TYPE_ID' => 'boolean', 'EDIT_FORM_LABEL' => 'Разрешить URL для попапа'),
            array('FIELD_NAME' => 'UF_KK_PRIVACY_TEXT', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Текст политики'),
            array('FIELD_NAME' => 'UF_KK_PRIVACY_URL', 'USER_TYPE_ID' => 'string', 'EDIT_FORM_LABEL' => 'Ссылка на политику'),
            array('FIELD_NAME' => 'UF_KK_REQUIRE_AGREEMENT', 'USER_TYPE_ID' => 'boolean', 'EDIT_FORM_LABEL' => 'Требовать согласие'),
        );

        foreach ($fields as $field) {
            self::addUserField($entityId, $field);
        }
    }

    protected static function addUserField($entityId, $field)
    {
        $fieldName = $field['FIELD_NAME'];
        $exists = \CUserTypeEntity::GetList(array(), array('ENTITY_ID' => $entityId, 'FIELD_NAME' => $fieldName))->Fetch();
        if ($exists) {
            return;
        }

        $values = isset($field['VALUES']) ? $field['VALUES'] : array();
        unset($field['VALUES']);

        $field = array_merge(array(
            'ENTITY_ID' => $entityId,
            'MULTIPLE' => 'N',
            'MANDATORY' => 'N',
            'SHOW_FILTER' => 'N',
            'SHOW_IN_LIST' => 'Y',
            'EDIT_IN_LIST' => 'Y',
            'IS_SEARCHABLE' => 'N',
        ), $field);
        $field['EDIT_FORM_LABEL'] = array('ru' => $field['EDIT_FORM_LABEL']);
        $field['LIST_COLUMN_LABEL'] = $field['EDIT_FORM_LABEL'];
        $field['LIST_FILTER_LABEL'] = $field['EDIT_FORM_LABEL'];

        $userTypeEntity = new \CUserTypeEntity();
        $userFieldId = $userTypeEntity->Add($field);
        if (!$userFieldId) {
            global $APPLICATION;
            $exception = is_object($APPLICATION) ? $APPLICATION->GetException() : null;
            $message = $exception ? $exception->GetString() : 'Не удалось создать пользовательское поле ' . $fieldName;
            throw new SystemException($message);
        }

        if (!empty($values)) {
            $enum = new \CUserFieldEnum();
            $enum->SetEnumValues($userFieldId, self::formatUserFieldEnumValues($values));
        }
    }

    protected static function installQuizProperties($iblockId)
    {
        $properties = array(
            array('CODE' => 'KK_ENTITY_TYPE', 'NAME' => 'Тип сущности', 'PROPERTY_TYPE' => 'L', 'VALUES' => array('QUESTION' => 'QUESTION', 'RESULT' => 'RESULT')),
            array('CODE' => 'KK_CODE', 'NAME' => 'Код', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_ADMIN_NOTE', 'NAME' => 'Комментарий администратора', 'PROPERTY_TYPE' => 'S', 'ROW_COUNT' => 5),
            array('CODE' => 'KK_QUESTION_TYPE', 'NAME' => 'Тип вопроса', 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getQuestionTypeValues()),
            array('CODE' => 'KK_DISPLAY_TEMPLATE', 'NAME' => 'Шаблон отображения', 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getDisplayTemplateValues()),
            array('CODE' => 'KK_IS_REQUIRED', 'NAME' => 'Обязательный вопрос', 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getYesNoValues()),
            array('CODE' => 'KK_PLACEHOLDER', 'NAME' => 'Placeholder', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_DEFAULT_NEXT_QUESTION', 'NAME' => 'Следующий вопрос по умолчанию', 'PROPERTY_TYPE' => 'E', 'LINK_IBLOCK_ID' => $iblockId),
            array('CODE' => 'KK_RESULT_MIN_SCORE', 'NAME' => 'Минимальный балл результата', 'PROPERTY_TYPE' => 'N'),
            array('CODE' => 'KK_RESULT_MAX_SCORE', 'NAME' => 'Максимальный балл результата', 'PROPERTY_TYPE' => 'N'),
            array('CODE' => 'KK_RESULT_PRIORITY', 'NAME' => 'Приоритет результата', 'PROPERTY_TYPE' => 'N'),
            array('CODE' => 'KK_RESULT_CTA_TEXT', 'NAME' => 'Текст CTA', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_RESULT_CTA_LINK', 'NAME' => 'Ссылка CTA', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_RESULT_SHOW_FORM', 'NAME' => 'Показывать форму', 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getYesNoValues()),
            array('CODE' => 'KK_RESULT_CATALOG_SECTION', 'NAME' => 'Раздел каталога', 'PROPERTY_TYPE' => 'G'),
            array('CODE' => 'KK_RESULT_CATALOG_PRODUCTS', 'NAME' => 'Товары каталога', 'PROPERTY_TYPE' => 'E', 'MULTIPLE' => 'Y'),
            array('CODE' => 'KK_RESULT_BADGE', 'NAME' => 'Бейдж результата', 'PROPERTY_TYPE' => 'S'),
        );

        self::addIblockProperties($iblockId, $properties);
    }

    protected static function installLeadProperties($iblockId)
    {
        $properties = array(
            array('CODE' => 'KK_LEAD_QUIZ_SECTION_ID', 'NAME' => 'ID раздела квиза', 'PROPERTY_TYPE' => 'N'),
            array('CODE' => 'KK_LEAD_QUIZ_CODE', 'NAME' => 'Код квиза', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_LEAD_QUIZ_NAME', 'NAME' => 'Название квиза', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_LEAD_RESULT_ID', 'NAME' => 'ID результата', 'PROPERTY_TYPE' => 'N'),
            array('CODE' => 'KK_LEAD_RESULT_CODE', 'NAME' => 'Код результата', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_LEAD_RESULT_TITLE', 'NAME' => 'Заголовок результата', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_LEAD_CLIENT_NAME', 'NAME' => 'Имя клиента', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_LEAD_CLIENT_PHONE', 'NAME' => 'Телефон клиента', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_LEAD_CLIENT_EMAIL', 'NAME' => 'Email клиента', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_LEAD_CLIENT_MESSENGER', 'NAME' => 'Мессенджер клиента', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_LEAD_CLIENT_COMMENT', 'NAME' => 'Комментарий клиента', 'PROPERTY_TYPE' => 'S', 'ROW_COUNT' => 5),
            array('CODE' => 'KK_LEAD_PAGE_URL', 'NAME' => 'URL страницы', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_LEAD_REFERER', 'NAME' => 'Referer', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_LEAD_UTM_SOURCE', 'NAME' => 'UTM Source', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_LEAD_UTM_MEDIUM', 'NAME' => 'UTM Medium', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_LEAD_UTM_CAMPAIGN', 'NAME' => 'UTM Campaign', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_LEAD_UTM_CONTENT', 'NAME' => 'UTM Content', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_LEAD_UTM_TERM', 'NAME' => 'UTM Term', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_LEAD_USER_AGENT', 'NAME' => 'User Agent', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_LEAD_IP', 'NAME' => 'IP', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_LEAD_SESSION_ID', 'NAME' => 'ID сессии', 'PROPERTY_TYPE' => 'S'),
            array('CODE' => 'KK_LEAD_ANSWERS_DATA', 'NAME' => 'Данные ответов', 'PROPERTY_TYPE' => 'S', 'ROW_COUNT' => 10),
            array('CODE' => 'KK_LEAD_EMAIL_SENT', 'NAME' => 'Email отправлен', 'PROPERTY_TYPE' => 'L', 'VALUES' => self::getYesNoValues()),
            array('CODE' => 'KK_LEAD_EMAIL_SENT_AT', 'NAME' => 'Дата отправки email', 'PROPERTY_TYPE' => 'S', 'USER_TYPE' => 'DateTime'),
        );

        self::addIblockProperties($iblockId, $properties);
    }

    protected static function addIblockProperties($iblockId, $properties)
    {
        foreach ($properties as $property) {
            $exists = \CIBlockProperty::GetList(array(), array('IBLOCK_ID' => $iblockId, '=CODE' => $property['CODE']))->Fetch();
            if ($exists) {
                continue;
            }

            $values = isset($property['VALUES']) ? $property['VALUES'] : array();
            unset($property['VALUES']);

            $property = array_merge(array(
                'IBLOCK_ID' => $iblockId,
                'ACTIVE' => 'Y',
                'SORT' => 500,
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
            ), $property);

            if (!empty($values)) {
                $property['LIST_TYPE'] = 'L';
                $property['VALUES'] = self::formatPropertyEnumValues($values);
            }

            $iblockProperty = new \CIBlockProperty();
            $propertyId = $iblockProperty->Add($property);
            if (!$propertyId) {
                throw new SystemException($iblockProperty->LAST_ERROR);
            }
        }
    }

    protected static function getFormFieldEnumValues()
    {
        return array(
            'name' => 'Имя',
            'phone' => 'Телефон',
            'email' => 'Email',
            'messenger' => 'Мессенджер',
            'comment' => 'Комментарий',
        );
    }

    protected static function getThemeEnumValues()
    {
        return array(
            'default' => 'Стандартная',
            'light' => 'Светлая',
            'dark' => 'Тёмная',
            'compact' => 'Компактная',
        );
    }

    protected static function getQuestionTypeValues()
    {
        return array(
            'radio' => 'Один вариант ответа',
            'checkbox' => 'Несколько вариантов ответа',
            'select' => 'Выпадающий список',
            'text' => 'Текстовое поле',
            'textarea' => 'Большое текстовое поле',
            'phone' => 'Телефон',
            'email' => 'Email',
        );
    }

    protected static function getDisplayTemplateValues()
    {
        return array(
            'list' => 'Обычный список',
            'cards' => 'Карточки без изображений',
            'image_cards' => 'Карточки с изображениями',
            'select' => 'Выпадающий список',
            'input' => 'Поле ввода',
        );
    }

    protected static function getYesNoValues()
    {
        return array(
            'Y' => 'Да',
            'N' => 'Нет',
        );
    }

    protected static function formatPropertyEnumValues($values)
    {
        $formatted = array();
        $sort = 100;
        foreach ($values as $xmlId => $value) {
            $formatted[] = array(
                'VALUE' => $value,
                'XML_ID' => $xmlId,
                'DEF' => 'N',
                'SORT' => $sort,
            );
            $sort += 100;
        }

        return $formatted;
    }

    protected static function formatUserFieldEnumValues($values)
    {
        $formatted = array();
        $sort = 100;
        foreach ($values as $xmlId => $value) {
            $formatted['n' . $sort] = array(
                'VALUE' => $value,
                'XML_ID' => $xmlId,
                'DEF' => 'N',
                'SORT' => $sort,
            );
            $sort += 100;
        }

        return $formatted;
    }
}
