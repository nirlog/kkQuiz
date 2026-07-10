<?php

declare(strict_types=1);

namespace Kk\Quiz\Iblock;

use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;

final class DemoDataInstaller
{
    public static function install(): void
    {
        if (!Loader::includeModule('iblock')) {
            throw new SystemException('Для установки демо-данных KK Quiz необходимо установить модуль iblock.');
        }

        $iblockId = self::getQuizzesIblockId();
        self::installPcQuiz($iblockId);
        self::installCleaningQuiz($iblockId);
    }

    private static function installPcQuiz(int $iblockId): void
    {
        if (self::findSectionIdByCode($iblockId, 'demo-pc-selection') > 0) {
            return;
        }

        $entityId = 'IBLOCK_' . $iblockId . '_SECTION';
        $ufEnums = self::getUserFieldEnumIds($entityId, 'UF_KK_FORM_FIELDS', ['name', 'phone', 'email', 'comment']);
        $requiredUfEnums = self::getUserFieldEnumIds($entityId, 'UF_KK_REQUIRED_FIELDS', ['name', 'phone']);
        $themeEnums = self::getUserFieldEnumIds($entityId, 'UF_KK_THEME', ['default']);
        $sectionId = self::createQuizSection($iblockId, [
            'NAME' => 'Демо: подбор компьютера', 'CODE' => 'demo-pc-selection',
            'DESCRIPTION' => 'Помогает подобрать тип компьютера под задачи и бюджет.',
            'UF_KK_TITLE' => 'Подбор компьютера',
            'UF_KK_SUBTITLE' => 'Ответьте на несколько вопросов — покажем подходящий вариант конфигурации.',
            'UF_KK_BUTTON_TEXT' => 'Начать подбор', 'UF_KK_FORM_TITLE' => 'Получить консультацию по сборке',
            'UF_KK_FORM_SUBTITLE' => 'Оставьте контакты, и менеджер поможет уточнить конфигурацию.',
            'UF_KK_FORM_BUTTON_TEXT' => 'Получить консультацию',
            'UF_KK_START_TEXT' => 'Быстрый демо-квиз для подбора компьютера под игры, работу или дом.',
            'UF_KK_SUCCESS_TEXT' => 'Спасибо! Мы получили заявку и скоро свяжемся с вами.',
            'UF_KK_USE_CATALOG' => 0, 'UF_KK_USE_METRIKA' => 0, 'UF_KK_THEME' => $themeEnums['default'] ?? null,
            'UF_KK_FORM_FIELDS' => array_values($ufEnums), 'UF_KK_REQUIRED_FIELDS' => array_values($requiredUfEnums),
            'UF_KK_REQUIRE_AGREEMENT' => 0,
        ]);

        $results = [
            ['pc-game-start','Игровой компьютер начального уровня','Подойдёт для популярных онлайн-игр и комфортной игры в Full HD.','Хороший вариант, если нужен игровой ПК без переплаты. Можно начать с базовой видеокарты и позже усилить конфигурацию.',100,'Игры','Смотреть рекомендации'],
            ['pc-game-power','Мощный игровой компьютер','Для современных игр на высоких настройках, стриминга и запаса на несколько лет.','Оптимальный вариант для требовательных игр, высокого FPS и будущего апгрейда.',90,'Игры','Подобрать конфигурацию'],
            ['pc-home','Домашний компьютер','Для интернета, фильмов, документов, учёбы и простых игр.','Тихая и недорогая конфигурация для повседневных задач.',120,'Дом','Получить подбор'],
            ['pc-universal','Универсальный компьютер','Баланс для учёбы, работы, мультимедиа и нетребовательных игр.','Подходит, если нужен один компьютер для разных задач без узкой специализации.',110,'Универсальный','Получить консультацию'],
            ['pc-workstation','Рабочая станция','Для монтажа, 3D, CAD, рендера, графики и тяжёлых рабочих задач.','Конфигурация с упором на процессор, память, быстрые накопители и стабильность под нагрузкой.',80,'Работа','Рассчитать сборку'],
            ['pc-office','Офисный компьютер','Для документов, CRM, браузера, почты и стабильной ежедневной работы.','Практичная конфигурация без лишней переплаты за игровую производительность.',130,'Офис','Обсудить задачу'],
        ];
        $resultIds = self::createResults($iblockId, $sectionId, $results);
        $questions = [['pc-purpose','Для чего нужен компьютер?',100],['pc-game-budget','Какой бюджет на игровой компьютер?',200],['pc-work-task','Какие рабочие задачи важнее всего?',300],['pc-home-task','Какой сценарий ближе?',400]];
        $questionIds = self::createQuestions($iblockId, $sectionId, $questions);
        self::updateStartQuestion($sectionId, (int)($questionIds['pc-purpose'] ?? 0));
        self::updateAnswers($iblockId, $questionIds['pc-purpose'], [self::answer('Игры','games','Для современных игр и высокого FPS',100,$questionIds['pc-game-budget']), self::answer('Работа','work','Для рабочих задач и профессиональных программ',200,$questionIds['pc-work-task']), self::answer('Дом и учёба','home-study','Для повседневных задач, учёбы и мультимедиа',300,$questionIds['pc-home-task'])]);
        self::updateAnswers($iblockId, $questionIds['pc-game-budget'], [self::answer('До 100 000 ₽','budget-under-100','Базовый игровой ПК без переплаты',100,null,$resultIds['pc-game-start']), self::answer('100 000–180 000 ₽','budget-100-180','Оптимальный запас для современных игр',200,null,$resultIds['pc-game-power']), self::answer('Больше 180 000 ₽','budget-over-180','Максимальный запас производительности',300,null,$resultIds['pc-game-power'])]);
        self::updateAnswers($iblockId, $questionIds['pc-work-task'], [self::answer('Документы, CRM, браузер','office','Стабильная ежедневная офисная работа',100,null,$resultIds['pc-office']), self::answer('Дизайн, монтаж, 3D, CAD','creative','Нужна высокая производительность под нагрузкой',200,null,$resultIds['pc-workstation']), self::answer('Программирование и многозадачность','development','Баланс процессора, памяти и накопителя',300,null,$resultIds['pc-universal'])]);
        self::updateAnswers($iblockId, $questionIds['pc-home-task'], [self::answer('Интернет, фильмы, документы','simple-home','Недорогой тихий компьютер для дома',100,null,$resultIds['pc-home']), self::answer('Учёба, работа и простые игры','study-games','Универсальная конфигурация для семьи',200,null,$resultIds['pc-universal']), self::answer('Нужен запас на будущее','future-proof','Больше универсальности для разных задач',300,null,$resultIds['pc-universal'])]);
    }

    private static function installCleaningQuiz(int $iblockId): void
    {
        if (self::findSectionIdByCode($iblockId, 'demo-cleaning-services') > 0) { return; }
        $entityId = 'IBLOCK_' . $iblockId . '_SECTION';
        $ufEnums = self::getUserFieldEnumIds($entityId, 'UF_KK_FORM_FIELDS', ['name', 'phone', 'email', 'comment']);
        $requiredUfEnums = self::getUserFieldEnumIds($entityId, 'UF_KK_REQUIRED_FIELDS', ['name', 'phone']);
        $themeEnums = self::getUserFieldEnumIds($entityId, 'UF_KK_THEME', ['light']);
        $sectionId = self::createQuizSection($iblockId, [
            'NAME' => 'Демо: подбор клининг-услуги', 'CODE' => 'demo-cleaning-services', 'DESCRIPTION' => 'Помогает подобрать подходящий формат уборки.',
            'UF_KK_TITLE' => 'Подбор клининг-услуги', 'UF_KK_SUBTITLE' => 'Ответьте на несколько вопросов — подскажем подходящий формат уборки.',
            'UF_KK_BUTTON_TEXT' => 'Начать подбор', 'UF_KK_FORM_TITLE' => 'Получить расчёт уборки', 'UF_KK_FORM_SUBTITLE' => 'Оставьте контакты, и менеджер уточнит детали.',
            'UF_KK_FORM_BUTTON_TEXT' => 'Получить расчёт', 'UF_KK_START_TEXT' => 'Демо-квиз для подбора услуги клининга для квартиры, офиса или помещения после ремонта.',
            'UF_KK_SUCCESS_TEXT' => 'Спасибо! Заявка отправлена. Мы скоро свяжемся с вами.', 'UF_KK_USE_CATALOG' => 0, 'UF_KK_USE_METRIKA' => 0,
            'UF_KK_THEME' => $themeEnums['light'] ?? null, 'UF_KK_FORM_FIELDS' => array_values($ufEnums), 'UF_KK_REQUIRED_FIELDS' => array_values($requiredUfEnums), 'UF_KK_REQUIRE_AGREEMENT' => 0,
        ]);
        $results = [
            ['cleaning-regular','Поддерживающая уборка квартиры','Регулярная уборка для поддержания чистоты дома.','Подходит для еженедельной или разовой лёгкой уборки: пыль, полы, кухня, санузел и основные поверхности.',100,'Квартира','Получить расчёт'],
            ['cleaning-general','Генеральная уборка','Глубокая уборка квартиры или дома с проработкой труднодоступных зон.','Подходит, если нужна более тщательная уборка: кухня, санузлы, мебель, плинтусы, двери, светильники и детали интерьера.',90,'Глубокая уборка','Рассчитать стоимость'],
            ['cleaning-after-renovation','Уборка после ремонта','Удаление строительной пыли, следов ремонта и подготовка помещения к использованию.','Подходит после ремонта, отделки или переезда. Требует больше времени, инвентаря и профессиональной химии.',80,'После ремонта','Заказать оценку'],
            ['cleaning-office','Офисный клининг','Регулярная уборка офисов, шоурумов, кабинетов и коммерческих помещений.','Подходит для ежедневного или еженедельного обслуживания рабочих пространств.',110,'Офис','Обсудить график'],
            ['cleaning-windows','Мойка окон','Отдельная услуга для окон, витрин, балконов и остекления.','Подходит как самостоятельная услуга или дополнение к генеральной уборке.',120,'Окна','Получить расчёт'],
        ];
        $resultIds = self::createResults($iblockId, $sectionId, $results);
        $questionIds = self::createQuestions($iblockId, $sectionId, [['cleaning-object','Где нужна уборка?',100],['cleaning-apartment-type','Какой формат уборки нужен?',200],['cleaning-office-area','Какой формат обслуживания нужен?',300]]);
        self::updateStartQuestion($sectionId, (int)($questionIds['cleaning-object'] ?? 0));
        self::updateAnswers($iblockId, $questionIds['cleaning-object'], [self::answer('Квартира или дом','apartment-house','Подберём формат уборки для жилого помещения',100,$questionIds['cleaning-apartment-type']), self::answer('Офис или коммерческое помещение','office-commercial','Подберём обслуживание рабочего пространства',200,$questionIds['cleaning-office-area']), self::answer('После ремонта','after-renovation','Нужна уборка строительной пыли и следов ремонта',300,null,$resultIds['cleaning-after-renovation']), self::answer('Нужна мойка окон','windows','Отдельная услуга для окон и остекления',400,null,$resultIds['cleaning-windows'])]);
        self::updateAnswers($iblockId, $questionIds['cleaning-apartment-type'], [self::answer('Поддерживающая уборка','regular','Для поддержания чистоты дома',100,null,$resultIds['cleaning-regular']), self::answer('Генеральная уборка','general','Глубокая уборка с деталями',200,null,$resultIds['cleaning-general']), self::answer('Не уверен, нужна консультация','consultation','Поможем выбрать оптимальный формат',300,null,$resultIds['cleaning-general'])]);
        self::updateAnswers($iblockId, $questionIds['cleaning-office-area'], [self::answer('Разовая уборка офиса','one-time-office','Разовое наведение порядка',100,null,$resultIds['cleaning-office']), self::answer('Регулярная уборка по графику','scheduled-office','Постоянное обслуживание офиса',200,null,$resultIds['cleaning-office']), self::answer('Уборка перед мероприятием или после него','event-cleaning','Интенсивная уборка перед событием или после него',300,null,$resultIds['cleaning-general'])]);
    }

    private static function createResults(int $iblockId, int $sectionId, array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            [$code, $publicTitle, $preview, $detail, $priority, $badge, $cta] = $row;
            $technicalName = sprintf('%02d. R: %s', (int)$priority, $badge !== '' ? $badge : $publicTitle);
            $ids[$code] = self::createElement($iblockId, $sectionId, ['CODE' => $code, 'NAME' => $technicalName, 'PREVIEW_TEXT' => $preview, 'DETAIL_TEXT' => $detail], ['KK_ENTITY_TYPE' => self::getPropertyEnumId($iblockId, 'KK_ENTITY_TYPE', 'RESULT'), 'KK_PUBLIC_TITLE' => $publicTitle, 'KK_RESULT_PRIORITY' => $priority, 'KK_RESULT_BADGE' => $badge, 'KK_RESULT_SHOW_FORM' => self::getPropertyEnumId($iblockId, 'KK_RESULT_SHOW_FORM', 'Y'), 'KK_RESULT_CTA_TEXT' => $cta, 'KK_RESULT_CTA_LINK' => '#']);
        }
        return $ids;
    }

    private static function createQuestions(int $iblockId, int $sectionId, array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            [$code, $publicTitle, $sort] = $row;
            $technicalName = sprintf('%02d. Q: %s', (int)($sort / 100), self::stripQuestionMark($publicTitle));
            $ids[$code] = self::createElement($iblockId, $sectionId, ['CODE' => $code, 'NAME' => $technicalName, 'SORT' => $sort], ['KK_ENTITY_TYPE' => self::getPropertyEnumId($iblockId, 'KK_ENTITY_TYPE', 'QUESTION'), 'KK_PUBLIC_TITLE' => $publicTitle, 'KK_QUESTION_TYPE' => self::getPropertyEnumId($iblockId, 'KK_QUESTION_TYPE', 'radio'), 'KK_DISPLAY_TEMPLATE' => self::getPropertyEnumId($iblockId, 'KK_DISPLAY_TEMPLATE', 'cards'), 'KK_IS_REQUIRED' => self::getPropertyEnumId($iblockId, 'KK_IS_REQUIRED', 'Y')]);
        }
        return $ids;
    }

    private static function stripQuestionMark(string $title): string
    {
        return rtrim($title, "?？ ");
    }

    private static function answer(string $text, string $code, string $description, int $sort, ?int $nextQuestionId = null, ?int $resultId = null): array
    {
        return ['active' => 'Y', 'sort' => $sort, 'text' => $text, 'code' => $code, 'image_id' => null, 'description' => $description, 'next_question_id' => $nextQuestionId, 'result_id' => $resultId, 'score_result_id' => null, 'score_value' => 0];
    }

    private static function getQuizzesIblockId(): int
    {
        $iblock = \CIBlock::GetList([], ['TYPE' => Installer::IBLOCK_TYPE_ID, 'CODE' => Installer::QUIZZES_IBLOCK_CODE])->Fetch();
        if (!$iblock) { throw new SystemException('Инфоблок квизов KK Quiz не найден.'); }
        return (int)$iblock['ID'];
    }

    private static function findSectionIdByCode(int $iblockId, string $code): int
    {
        $section = \CIBlockSection::GetList([], ['IBLOCK_ID' => $iblockId, '=CODE' => $code], false, ['ID'])->Fetch();
        return $section ? (int)$section['ID'] : 0;
    }

    private static function createQuizSection(int $iblockId, array $fields): int
    {
        $section = new \CIBlockSection();
        $id = (int)$section->Add(array_merge(['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y', 'SORT' => 500], $fields));
        if ($id <= 0) { throw new SystemException((string)$section->LAST_ERROR); }
        return $id;
    }

    private static function createElement(int $iblockId, int $sectionId, array $fields, array $properties): int
    {
        $element = new \CIBlockElement();
        $id = (int)$element->Add(array_merge(['IBLOCK_ID' => $iblockId, 'IBLOCK_SECTION_ID' => $sectionId, 'ACTIVE' => 'Y', 'SORT' => 500, 'PROPERTY_VALUES' => $properties], $fields));
        if ($id <= 0) { throw new SystemException((string)$element->LAST_ERROR); }
        return $id;
    }

    private static function updateStartQuestion(int $sectionId, int $questionId): void
    {
        if ($sectionId <= 0 || $questionId <= 0) {
            return;
        }

        $section = new \CIBlockSection();
        $section->Update($sectionId, ['UF_KK_START_QUESTION' => $questionId]);
    }

    private static function updateAnswers(int $iblockId, int $questionId, array $answers): void
    {
        \CIBlockElement::SetPropertyValuesEx($questionId, $iblockId, ['KK_ANSWERS' => json_encode($answers, JSON_UNESCAPED_UNICODE)]);
    }

    private static function getPropertyEnumId(int $iblockId, string $propertyCode, string $xmlId): int
    {
        $enum = \CIBlockPropertyEnum::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $propertyCode, 'XML_ID' => $xmlId])->Fetch();
        if (!$enum) { throw new SystemException('Не найдено значение списка ' . $propertyCode . ': ' . $xmlId); }
        return (int)$enum['ID'];
    }

    private static function getUserFieldEnumIds(string $entityId, string $fieldName, array $xmlIds): array
    {
        $field = \CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId, 'FIELD_NAME' => $fieldName])->Fetch();
        if (!$field) { throw new SystemException('Не найдено пользовательское поле ' . $fieldName); }
        $ids = [];
        $enumResult = \CUserFieldEnum::GetList([], ['USER_FIELD_ID' => (int)$field['ID']]);
        while ($enum = $enumResult->Fetch()) {
            if (in_array((string)$enum['XML_ID'], $xmlIds, true)) { $ids[(string)$enum['XML_ID']] = (int)$enum['ID']; }
        }
        foreach ($xmlIds as $xmlId) { if (!isset($ids[$xmlId])) { throw new SystemException('Не найдено значение пользовательского поля ' . $fieldName . ': ' . $xmlId); } }
        return $ids;
    }
}
