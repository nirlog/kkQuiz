<?php

declare(strict_types=1);

namespace Kk\Quiz\Repository;

use Bitrix\Main\Loader;
use Kk\Quiz\Iblock\Installer;

final class QuizRepository
{
    public function getQuizByCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '' || !Loader::includeModule('iblock')) {
            return null;
        }

        $iblockId = $this->getQuizIblockId();
        if ($iblockId === null) {
            return null;
        }

        $section = $this->getSection($iblockId, ['CODE' => $code]);
        if ($section === null) {
            return null;
        }

        return $this->buildQuiz($iblockId, $section);
    }

    public function getQuizById(int $sectionId): ?array
    {
        if ($sectionId <= 0 || !Loader::includeModule('iblock')) {
            return null;
        }

        $iblockId = $this->getQuizIblockId();
        if ($iblockId === null) {
            return null;
        }

        $section = $this->getSection($iblockId, ['ID' => $sectionId]);
        if ($section === null) {
            return null;
        }

        return $this->buildQuiz($iblockId, $section);
    }

    private function getQuizIblockId(): ?int
    {
        $iblock = \CIBlock::GetList(
            [],
            [
                'TYPE' => Installer::IBLOCK_TYPE_ID,
                'CODE' => Installer::QUIZZES_IBLOCK_CODE,
                'ACTIVE' => 'Y',
            ]
        )->Fetch();

        return is_array($iblock) ? (int)$iblock['ID'] : null;
    }

    private function getSection(int $iblockId, array $filter): ?array
    {
        $sections = \CIBlockSection::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            array_merge(
                [
                    'IBLOCK_ID' => $iblockId,
                    'ACTIVE' => 'Y',
                ],
                $filter
            ),
            false,
            [
                'ID',
                'IBLOCK_ID',
                'CODE',
                'NAME',
                'DESCRIPTION',
                'SORT',
                'UF_KK_TITLE',
                'UF_KK_SUBTITLE',
                'UF_KK_BUTTON_TEXT',
                'UF_KK_FORM_BUTTON_TEXT',
                'UF_KK_FORM_TITLE',
                'UF_KK_FORM_SUBTITLE',
                'UF_KK_START_TEXT',
                'UF_KK_START_QUESTION',
                'UF_KK_PROGRESS_TOTAL',
                'UF_KK_SUCCESS_TEXT',
                'UF_KK_EMAIL_TO',
                'UF_KK_FORM_FIELDS',
                'UF_KK_REQUIRED_FIELDS',
                'UF_KK_METRIKA_COUNTER_ID',
                'UF_KK_METRIKA_GOAL',
                'UF_KK_USE_METRIKA',
                'UF_KK_USE_CATALOG',
                'UF_KK_CATALOG_IBLOCK_ID',
                'UF_KK_CATALOG_IBLOCK_IDS',
                'UF_KK_THEME',
                'UF_KK_ACCENT_COLOR',
                'UF_KK_ACCENT_HOVER',
                'UF_KK_ACTIVE_COLOR',
                'UF_KK_PROGRESS_COLOR',
                'UF_KK_BORDER_RADIUS',
                'UF_KK_CONTAINER_RADIUS',
                'UF_KK_CARD_RADIUS',
                'UF_KK_BUTTON_RADIUS',
                'UF_KK_INPUT_RADIUS',
                'UF_KK_IMAGE_RADIUS',
                'UF_KK_IMAGE_RATIO',
                'UF_KK_IMAGE_FIT',
                'UF_KK_ALLOW_POPUP_URL',
                'UF_KK_PRIVACY_TEXT',
                'UF_KK_PRIVACY_URL',
                'UF_KK_REQUIRE_AGREEMENT',
            ]
        );
        $section = $sections->Fetch();

        return is_array($section) ? $section : null;
    }

    private function buildQuiz(int $iblockId, array $section): array
    {
        $items = $this->getSectionItems($iblockId, (int)$section['ID']);
        $catalogIblockIds = $this->normalizeIntList(
            $this->normalizeUserFieldEnumList($section['UF_KK_CATALOG_IBLOCK_IDS'] ?? [])
        );
        $legacyCatalogIblockId = $this->toNullableInt($section['UF_KK_CATALOG_IBLOCK_ID'] ?? null);

        if ($catalogIblockIds === [] && $legacyCatalogIblockId !== null) {
            $catalogIblockIds = [$legacyCatalogIblockId];
        }

        $legacyRadius = $this->normalizeOptionalRadius($section['UF_KK_BORDER_RADIUS'] ?? null);
        $accentColor = $this->normalizeHexColor($section['UF_KK_ACCENT_COLOR'] ?? '', '#2563eb');
        $accentHoverColor = $this->normalizeHexColor($section['UF_KK_ACCENT_HOVER'] ?? '', '#1d4ed8');

        return [
            'id' => (int)$section['ID'],
            'code' => (string)$section['CODE'],
            'name' => (string)$section['NAME'],
            'description' => (string)($section['DESCRIPTION'] ?? ''),
            'sort' => (int)$section['SORT'],
            'title' => (string)($section['UF_KK_TITLE'] ?? ''),
            'subtitle' => (string)($section['UF_KK_SUBTITLE'] ?? ''),
            'button_text' => (string)($section['UF_KK_BUTTON_TEXT'] ?? ''),
            'form_button_text' => (string)($section['UF_KK_FORM_BUTTON_TEXT'] ?? ''),
            'form_title' => (string)($section['UF_KK_FORM_TITLE'] ?? ''),
            'form_subtitle' => (string)($section['UF_KK_FORM_SUBTITLE'] ?? ''),
            'start_text' => (string)($section['UF_KK_START_TEXT'] ?? ''),
            'start_question_id' => $this->toNullableInt($section['UF_KK_START_QUESTION'] ?? null),
            'progress_total' => max(0, (int)($section['UF_KK_PROGRESS_TOTAL'] ?? 0)),
            'success_text' => (string)($section['UF_KK_SUCCESS_TEXT'] ?? ''),
            'email_to' => (string)($section['UF_KK_EMAIL_TO'] ?? ''),
            'form_fields' => $this->normalizeUserFieldEnumList($section['UF_KK_FORM_FIELDS'] ?? []),
            'required_fields' => $this->normalizeUserFieldEnumList($section['UF_KK_REQUIRED_FIELDS'] ?? []),
            'metrika_counter_id' => (string)($section['UF_KK_METRIKA_COUNTER_ID'] ?? ''),
            'metrika_goal' => $this->normalizeMetrikaGoal($section['UF_KK_METRIKA_GOAL'] ?? ''),
            'use_metrika' => $this->toBool($section['UF_KK_USE_METRIKA'] ?? null),
            'use_catalog' => $this->toBool($section['UF_KK_USE_CATALOG'] ?? null),
            'catalog_iblock_id' => $legacyCatalogIblockId,
            'catalog_iblock_ids' => $catalogIblockIds,
            'theme' => $this->normalizeUserFieldEnumValue($section['UF_KK_THEME'] ?? 'default') ?: 'default',
            'accent_color' => $accentColor,
            'accent_hover_color' => $accentHoverColor,
            'active_color' => $this->normalizeHexColor($section['UF_KK_ACTIVE_COLOR'] ?? '', $accentColor),
            'progress_color' => $this->normalizeHexColor($section['UF_KK_PROGRESS_COLOR'] ?? '', $accentColor),
            'border_radius' => $legacyRadius,
            'container_radius' => $this->resolveRadius($section['UF_KK_CONTAINER_RADIUS'] ?? null, $legacyRadius, 24),
            'card_radius' => $this->resolveRadius($section['UF_KK_CARD_RADIUS'] ?? null, $legacyRadius, 16),
            'button_radius' => $this->resolveRadius($section['UF_KK_BUTTON_RADIUS'] ?? null, $legacyRadius, 12),
            'input_radius' => $this->resolveRadius($section['UF_KK_INPUT_RADIUS'] ?? null, $legacyRadius, 10),
            'image_radius' => $this->resolveRadius($section['UF_KK_IMAGE_RADIUS'] ?? null, $legacyRadius, 12),
            'answer_image_ratio' => $this->normalizeImageRatio($this->normalizeUserFieldEnumValue($section['UF_KK_IMAGE_RATIO'] ?? '4:3'), '4:3'),
            'answer_image_fit' => $this->normalizeImageFit($this->normalizeUserFieldEnumValue($section['UF_KK_IMAGE_FIT'] ?? 'cover')),
            'allow_popup_url' => $this->toBool($section['UF_KK_ALLOW_POPUP_URL'] ?? null),
            'privacy_text' => (string)($section['UF_KK_PRIVACY_TEXT'] ?? ''),
            'privacy_url' => (string)($section['UF_KK_PRIVACY_URL'] ?? ''),
            'require_agreement' => $this->toBool($section['UF_KK_REQUIRE_AGREEMENT'] ?? null),
            'questions' => $items['questions'],
            'results' => $items['results'],
        ];
    }

    private function getSectionItems(int $iblockId, int $sectionId): array
    {
        $items = [
            'questions' => [],
            'results' => [],
        ];

        $elements = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            [
                'IBLOCK_ID' => $iblockId,
                'SECTION_ID' => $sectionId,
                'ACTIVE' => 'Y',
                'INCLUDE_SUBSECTIONS' => 'N',
            ],
            false,
            false,
            [
                'ID',
                'IBLOCK_ID',
                'CODE',
                'NAME',
                'SORT',
                'PREVIEW_TEXT',
                'DETAIL_TEXT',
                'PREVIEW_PICTURE',
                'DETAIL_PICTURE',
            ]
        );

        while ($elementObject = $elements->GetNextElement()) {
            $element = $elementObject->GetFields();
            $properties = $elementObject->GetProperties();
            $entityType = strtoupper($this->getElementPropertyEnumXmlId($properties, 'KK_ENTITY_TYPE'));

            if ($entityType === '') {
                $entityType = strtoupper((string)$this->getElementPropertyValue($properties, 'KK_ENTITY_TYPE'));
            }

            if ($entityType === 'QUESTION') {
                $items['questions'][] = $this->mapQuestion($element, $properties);
            }

            if ($entityType === 'RESULT') {
                $items['results'][] = $this->mapResult($element, $properties);
            }
        }

        return $items;
    }

    private function mapQuestion(array $element, array $properties): array
    {
        $rawQuestionType = $this->getElementPropertyEnumXmlId($properties, 'KK_QUESTION_TYPE');
        $questionType = $this->normalizeQuestionType($rawQuestionType);
        $displayTemplate = $this->normalizeDisplayTemplate(
            $this->getElementPropertyEnumXmlId($properties, 'KK_DISPLAY_TEMPLATE'),
            $questionType,
            $rawQuestionType
        );

        $adminName = (string)($element['NAME'] ?? '');
        $publicTitle = trim((string)$this->getElementPropertyValue($properties, 'KK_PUBLIC_TITLE'));
        $title = $publicTitle !== '' ? $publicTitle : $adminName;

        return [
            'id' => (int)$element['ID'],
            'code' => (string)($element['CODE'] ?? ''),
            'name' => $title,
            'public_title' => $publicTitle,
            'admin_name' => $adminName,
            'hint' => (string)($element['PREVIEW_TEXT'] ?? ''),
            'sort' => (int)($element['SORT'] ?? 0),
            'question_type' => $questionType,
            'display_template' => $displayTemplate,
            'answer_image_ratio' => $this->normalizeImageRatio($this->getElementPropertyEnumXmlId($properties, 'KK_IMAGE_RATIO'), ''),
            'answer_image_fit' => $this->normalizeQuestionImageFit($this->getElementPropertyEnumXmlId($properties, 'KK_IMAGE_FIT')),
            'is_required' => $this->toBool($this->getElementPropertyEnumXmlId($properties, 'KK_IS_REQUIRED')),
            'placeholder' => (string)$this->getElementPropertyValue($properties, 'KK_PLACEHOLDER'),
            'default_next_question_id' => $this->toNullableInt($this->getElementPropertyValue($properties, 'KK_DEFAULT_NEXT_QUESTION')),
            'default_result_id' => $this->toNullableInt($this->getElementPropertyValue($properties, 'KK_DEFAULT_RESULT')),
            'allow_custom_answer' => $this->toBool($this->getElementPropertyEnumXmlId($properties, 'KK_ALLOW_CUSTOM_ANSWER')),
            'answers' => $this->normalizeAnswers($this->getElementPropertyValue($properties, 'KK_ANSWERS')),
        ];
    }

    private function normalizeHexColor(mixed $value, string $default): string
    {
        $value = trim((string)$value);

        return preg_match('/^#[0-9a-f]{6}$/i', $value) === 1 ? strtolower($value) : $default;
    }

    private function normalizeImageRatio(mixed $value, string $default): string
    {
        $value = strtolower(trim((string)$value));

        return in_array($value, ['1:1', '3:4', '4:3', '9:16', '16:9'], true) ? $value : $default;
    }

    private function normalizeImageFit(mixed $value): string
    {
        $value = strtolower(trim((string)$value));

        return in_array($value, ['cover', 'contain'], true) ? $value : 'cover';
    }

    private function normalizeQuestionImageFit(mixed $value): string
    {
        $value = strtolower(trim((string)$value));

        return in_array($value, ['cover', 'contain'], true) ? $value : '';
    }

    private function normalizeOptionalRadius(mixed $value): ?int
    {
        return $value === null || trim((string)$value) === '' ? null : min(64, max(0, (int)$value));
    }

    private function resolveRadius(mixed $value, ?int $legacyRadius, int $default): int
    {
        return $this->normalizeOptionalRadius($value) ?? $legacyRadius ?? $default;
    }

    private function normalizeQuestionType(?string $questionType): string
    {
        $questionType = strtolower(trim((string)$questionType));

        return in_array($questionType, ['radio', 'checkbox', 'text', 'textarea', 'phone', 'email'], true)
            ? $questionType
            : 'radio';
    }

    private function normalizeDisplayTemplate(?string $displayTemplate, string $questionType, ?string $rawQuestionType = null): string
    {
        $displayTemplate = strtolower(trim((string)$displayTemplate));
        $rawQuestionType = strtolower(trim((string)$rawQuestionType));

        if ($rawQuestionType === 'select') {
            return 'select';
        }

        if ($questionType === 'radio') {
            return in_array($displayTemplate, ['list', 'cards', 'image_cards', 'select'], true) ? $displayTemplate : 'list';
        }

        if ($questionType === 'checkbox') {
            return in_array($displayTemplate, ['list', 'cards', 'image_cards'], true) ? $displayTemplate : 'list';
        }

        return '';
    }

    private function mapResult(array $element, array $properties): array
    {
        $pictureId = $this->toNullableInt($element['PREVIEW_PICTURE'] ?? null) ?? $this->toNullableInt($element['DETAIL_PICTURE'] ?? null);

        $adminName = (string)($element['NAME'] ?? '');
        $publicTitle = trim((string)$this->getElementPropertyValue($properties, 'KK_PUBLIC_TITLE'));
        $title = $publicTitle !== '' ? $publicTitle : $adminName;
        $summary = (string)$this->getElementPropertyValue($properties, 'KK_RESULT_SUMMARY');
        $whyText = (string)$this->getElementPropertyValue($properties, 'KK_RESULT_WHY_TEXT');
        $specsText = (string)$this->getElementPropertyValue($properties, 'KK_RESULT_SPECS_TEXT');

        return [
            'id' => (int)$element['ID'],
            'code' => (string)($element['CODE'] ?? ''),
            'name' => $title,
            'public_title' => $publicTitle,
            'admin_name' => $adminName,
            'preview_text' => (string)($element['PREVIEW_TEXT'] ?? ''),
            'summary' => $summary,
            'why_text' => $whyText,
            'why_items' => $this->normalizeTextLines($whyText),
            'specs_text' => $specsText,
            'specs_items' => $this->normalizeTextLines($specsText),
            'note_text' => (string)$this->getElementPropertyValue($properties, 'KK_RESULT_NOTE_TEXT'),
            'form_title' => (string)$this->getElementPropertyValue($properties, 'KK_RESULT_FORM_TITLE'),
            'form_intro' => (string)$this->getElementPropertyValue($properties, 'KK_RESULT_FORM_INTRO'),
            'form_button_text' => (string)$this->getElementPropertyValue($properties, 'KK_RESULT_FORM_BUTTON_TEXT'),
            'detail_text' => (string)($element['DETAIL_TEXT'] ?? ''),
            'picture_id' => $pictureId,
            'picture_src' => $this->getFilePath($pictureId),
            'sort' => (int)($element['SORT'] ?? 0),
            'min_score' => $this->toNullableInt($this->getElementPropertyValue($properties, 'KK_RESULT_MIN_SCORE')),
            'max_score' => $this->toNullableInt($this->getElementPropertyValue($properties, 'KK_RESULT_MAX_SCORE')),
            'priority' => (int)$this->getElementPropertyValue($properties, 'KK_RESULT_PRIORITY'),
            'cta_text' => (string)$this->getElementPropertyValue($properties, 'KK_RESULT_CTA_TEXT'),
            'cta_link' => (string)$this->getElementPropertyValue($properties, 'KK_RESULT_CTA_LINK'),
            'video' => $this->buildResultVideo($properties),
            'show_form' => $this->toBool($this->getElementPropertyEnumXmlId($properties, 'KK_RESULT_SHOW_FORM')),
            'catalog_section_id' => $this->toNullableInt($this->getElementPropertyValue($properties, 'KK_RESULT_CATALOG_SECTION')),
            'catalog_product_ids' => $this->normalizeIntList($this->getElementPropertyValues($properties, 'KK_RESULT_CATALOG_PRODUCTS')),
            'badge' => (string)$this->getElementPropertyValue($properties, 'KK_RESULT_BADGE'),
        ];
    }

    private function buildResultVideo(array $properties): ?array
    {
        $url = trim((string)$this->getElementPropertyValue($properties, 'KK_RESULT_VIDEO_URL'));
        if ($url === '') {
            return null;
        }

        $parsed = $this->parseVideoUrl($url);
        if ($parsed === null) {
            return null;
        }

        $position = $this->normalizeVideoPosition(
            $this->getElementPropertyEnumXmlId($properties, 'KK_RESULT_VIDEO_POSITION')
                ?: (string)$this->getElementPropertyValue($properties, 'KK_RESULT_VIDEO_POSITION')
        );

        return [
            'url' => $parsed['url'],
            'title' => trim((string)$this->getElementPropertyValue($properties, 'KK_RESULT_VIDEO_TITLE')),
            'position' => $position,
            'provider' => $parsed['provider'],
            'embedUrl' => $parsed['embedUrl'],
            'type' => $parsed['type'],
        ];
    }

    private function parseVideoUrl(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');
        $safeUrl = $this->buildSafeVideoUrl($parts);
        if ($safeUrl === '') {
            return null;
        }

        if (preg_match('/\.(mp4|webm|ogg)$/i', $path) === 1) {
            return [
                'url' => $safeUrl,
                'provider' => 'file',
                'embedUrl' => '',
                'type' => 'video',
            ];
        }

        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            $query = [];
            parse_str((string)($parts['query'] ?? ''), $query);
            $videoId = (string)($query['v'] ?? '');
            if ($videoId === '' && preg_match('~^/shorts/([^/?#]+)~', $path, $matches) === 1) {
                $videoId = $matches[1];
            }
            $videoId = $this->normalizeExternalVideoId($videoId);
            if ($videoId !== '' && $scheme === 'https') {
                return [
                    'url' => $safeUrl,
                    'provider' => 'youtube',
                    'embedUrl' => 'https://www.youtube.com/embed/' . rawurlencode($videoId),
                    'type' => 'iframe',
                ];
            }
        }

        if ($host === 'youtu.be' && preg_match('~^/([^/?#]+)~', $path, $matches) === 1) {
            $videoId = $this->normalizeExternalVideoId($matches[1]);
            if ($videoId !== '' && $scheme === 'https') {
                return [
                    'url' => $safeUrl,
                    'provider' => 'youtube',
                    'embedUrl' => 'https://www.youtube.com/embed/' . rawurlencode($videoId),
                    'type' => 'iframe',
                ];
            }
        }

        if ($host === 'rutube.ru') {
            $videoId = '';
            if (preg_match('#^/video/(?:private/)?([a-zA-Z0-9_-]+)#', $path, $matches) === 1) {
                $videoId = $matches[1];
            }
            $videoId = $this->normalizeExternalVideoId($videoId);
            if ($videoId !== '' && $scheme === 'https') {
                return [
                    'url' => $safeUrl,
                    'provider' => 'rutube',
                    'embedUrl' => 'https://rutube.ru/play/embed/' . rawurlencode($videoId),
                    'type' => 'iframe',
                ];
            }
        }

        if (in_array($host, ['vk.com', 'www.vk.com', 'vkvideo.ru', 'www.vkvideo.ru'], true)) {
            if (
                preg_match('#/video(-?\d+)_(\d+)#', $path, $matches) === 1
                && $scheme === 'https'
            ) {
                return [
                    'url' => $safeUrl,
                    'provider' => 'vk',
                    'embedUrl' => 'https://vk.com/video_ext.php?oid=' . rawurlencode($matches[1]) . '&id=' . rawurlencode($matches[2]),
                    'type' => 'iframe',
                ];
            }

            return [
                'url' => $safeUrl,
                'provider' => 'vk',
                'embedUrl' => '',
                'type' => 'link',
            ];
        }

        return [
            'url' => $safeUrl,
            'provider' => 'link',
            'embedUrl' => '',
            'type' => 'link',
        ];
    }

    private function buildSafeVideoUrl(array $parts): string
    {
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }

        $url = $scheme . '://' . $host;
        if (isset($parts['port']) && (int)$parts['port'] > 0) {
            $url .= ':' . (int)$parts['port'];
        }
        $url .= (string)($parts['path'] ?? '');
        if (isset($parts['query']) && (string)$parts['query'] !== '') {
            $url .= '?' . (string)$parts['query'];
        }

        return $url;
    }

    private function normalizeExternalVideoId(string $value): string
    {
        $value = trim($value);

        return preg_match('/^[a-zA-Z0-9_-]{3,128}$/', $value) === 1 ? $value : '';
    }

    private function normalizeVideoPosition(string $position): string
    {
        $position = trim($position);

        return in_array($position, ['after_text', 'before_form', 'after_form', 'before_products'], true)
            ? $position
            : 'after_text';
    }

    private function getElementPropertyValue(array $properties, string $code): mixed
    {
        if (!isset($properties[$code]) || !is_array($properties[$code])) {
            return null;
        }

        return $properties[$code]['VALUE'] ?? null;
    }

    private function getElementPropertyValues(array $properties, string $code): array
    {
        $value = $this->getElementPropertyValue($properties, $code);

        return is_array($value) ? $value : [$value];
    }

    private function getElementPropertyEnumXmlId(array $properties, string $code): string
    {
        if (!isset($properties[$code]) || !is_array($properties[$code])) {
            return '';
        }

        $property = $properties[$code];
        $xmlId = $property['VALUE_XML_ID'] ?? null;
        if (is_array($xmlId)) {
            $xmlId = reset($xmlId);
        }

        if (is_string($xmlId) && $xmlId !== '') {
            return $xmlId;
        }

        $enumId = $property['VALUE_ENUM_ID'] ?? null;
        if (is_array($enumId)) {
            $enumId = reset($enumId);
        }

        $enumXmlId = $this->getPropertyEnumXmlId($this->toNullableInt($enumId));
        if ($enumXmlId !== '') {
            return $enumXmlId;
        }

        $value = $property['VALUE'] ?? '';
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? (string)$value : '';
    }

    private function getElementPropertyEnumXmlIds(array $properties, string $code): array
    {
        if (!isset($properties[$code]) || !is_array($properties[$code])) {
            return [];
        }

        $property = $properties[$code];
        $xmlIds = $property['VALUE_XML_ID'] ?? [];
        $xmlIds = is_array($xmlIds) ? $xmlIds : [$xmlIds];
        $enumIds = $property['VALUE_ENUM_ID'] ?? [];
        $enumIds = is_array($enumIds) ? $enumIds : [$enumIds];
        $values = $property['VALUE'] ?? [];
        $values = is_array($values) ? $values : [$values];
        $result = [];

        foreach ($values as $index => $value) {
            $xmlId = (string)($xmlIds[$index] ?? '');
            if ($xmlId === '') {
                $xmlId = $this->getPropertyEnumXmlId($this->toNullableInt($enumIds[$index] ?? null));
            }
            if ($xmlId === '' && is_scalar($value)) {
                $xmlId = (string)$value;
            }
            if ($xmlId !== '' && !in_array($xmlId, $result, true)) {
                $result[] = $xmlId;
            }
        }

        return $result;
    }

    private function getPropertyEnumXmlId(?int $enumId): string
    {
        if ($enumId === null) {
            return '';
        }

        $enum = \CIBlockPropertyEnum::GetByID($enumId);
        if (!is_array($enum)) {
            return '';
        }

        $xmlId = (string)($enum['XML_ID'] ?? '');

        return $xmlId !== '' ? $xmlId : (string)($enum['VALUE'] ?? '');
    }


    private function normalizeMetrikaGoal(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string)$value) : '';
        if ($value === '') {
            return '';
        }

        return preg_match('/^[a-zA-Z0-9_-]+$/', $value) === 1 ? $value : '';
    }

    private function normalizeAnswers(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $answers = [];
        foreach ($value as $answer) {
            if (!is_array($answer) || (string)($answer['active'] ?? 'N') !== 'Y') {
                continue;
            }

            $imageId = $this->toNullableInt($answer['image_id'] ?? null);
            $answers[] = [
                'active' => 'Y',
                'sort' => (int)($answer['sort'] ?? 100),
                'text' => (string)($answer['text'] ?? ''),
                'code' => (string)($answer['code'] ?? ''),
                'image_id' => $imageId,
                'image_src' => $this->getFilePath($imageId),
                'description' => (string)($answer['description'] ?? ''),
                'next_question_id' => $this->toNullableInt($answer['next_question_id'] ?? null),
                'result_id' => $this->toNullableInt($answer['result_id'] ?? null),
                'score_result_id' => $this->toNullableInt($answer['score_result_id'] ?? null),
                'score_value' => (int)($answer['score_value'] ?? 0),
            ];
        }

        usort($answers, static fn (array $left, array $right): int => ($left['sort'] <=> $right['sort']));

        return $answers;
    }

    private function normalizeUserFieldEnumList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $result = [];

        foreach ($values as $item) {
            $xmlId = $this->normalizeUserFieldEnumValue($item);
            if ($xmlId !== '' && !in_array($xmlId, $result, true)) {
                $result[] = $xmlId;
            }
        }

        return $result;
    }

    private function normalizeUserFieldEnumValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null || $value === false || $value === '') {
            return '';
        }

        if (is_numeric($value) && class_exists('CUserFieldEnum')) {
            $enum = \CUserFieldEnum::GetList([], ['ID' => (int)$value])->Fetch();
            if (is_array($enum)) {
                $xmlId = (string)($enum['XML_ID'] ?? '');

                return $xmlId !== '' ? $xmlId : (string)($enum['VALUE'] ?? '');
            }
        }

        return is_scalar($value) ? (string)$value : '';
    }

    private function normalizeTextLines(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $result = [];

        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $result[] = $line;
            }
        }

        return $result;
    }

    private function normalizeIntList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $result = [];
        foreach ($values as $item) {
            $item = (int)$item;
            if ($item > 0 && !in_array($item, $result, true)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function toBool(mixed $value): bool
    {
        return $value === true || $value === 'Y' || $value === '1' || $value === 1 || $value === 'Да';
    }

    private function toNullableInt(mixed $value): ?int
    {
        $value = (int)$value;

        return $value > 0 ? $value : null;
    }

    private function getFilePath(?int $fileId): ?string
    {
        if ($fileId === null || !class_exists('CFile')) {
            return null;
        }

        $path = \CFile::GetPath($fileId);

        return is_string($path) && $path !== '' ? $path : null;
    }
}
