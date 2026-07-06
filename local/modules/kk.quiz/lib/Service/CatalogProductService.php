<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Bitrix\Main\Loader;

final class CatalogProductService
{
    public function getProducts(int|array $iblockIds, array $productIds, int $limit = 6): array
    {
        $iblockIds = $this->normalizeIds(is_array($iblockIds) ? $iblockIds : [$iblockIds]);
        $productIds = $this->normalizeIds($productIds);
        $limit = max(1, min(20, $limit));
        $productIds = array_slice($productIds, 0, $limit);

        if ($iblockIds === [] || $productIds === [] || !Loader::includeModule('iblock')) {
            return [];
        }

        $sortIndex = array_flip($productIds);
        $items = [];

        $rsElements = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            [
                'IBLOCK_ID' => $iblockIds,
                'ID' => $productIds,
                'ACTIVE' => 'Y',
                'ACTIVE_DATE' => 'Y',
            ],
            false,
            ['nTopCount' => $limit],
            [
                'ID',
                'IBLOCK_ID',
                'NAME',
                'CODE',
                'DETAIL_PAGE_URL',
                'PREVIEW_PICTURE',
                'DETAIL_PICTURE',
            ]
        );

        while ($element = $rsElements->GetNext()) {
            $id = (int)$element['ID'];

            $items[] = [
                'id' => $id,
                'name' => (string)($element['NAME'] ?? ''),
                'url' => (string)($element['DETAIL_PAGE_URL'] ?? ''),
                'picture_src' => $this->getPictureSrc($element),
                'sort_index' => $sortIndex[$id] ?? PHP_INT_MAX,
            ];
        }

        usort($items, static fn(array $a, array $b): int => ($a['sort_index'] <=> $b['sort_index']));

        return array_map(static function (array $item): array {
            unset($item['sort_index']);

            return $item;
        }, $items);
    }

    public function getProductsFromSection(
        int|array $iblockIds,
        int $sectionId,
        array $excludeIds = [],
        int $limit = 6
    ): array
    {
        $iblockIds = $this->normalizeIds(is_array($iblockIds) ? $iblockIds : [$iblockIds]);
        $sectionId = max(0, $sectionId);
        $excludeIds = $this->normalizeIds($excludeIds);
        $limit = max(1, min(20, $limit));

        if ($iblockIds === [] || $sectionId <= 0 || !Loader::includeModule('iblock')) {
            return [];
        }

        $filter = [
            'IBLOCK_ID' => $iblockIds,
            'SECTION_ID' => $sectionId,
            'INCLUDE_SUBSECTIONS' => 'Y',
            'ACTIVE' => 'Y',
            'ACTIVE_DATE' => 'Y',
        ];

        if ($excludeIds !== []) {
            $filter['!ID'] = $excludeIds;
        }

        $items = [];

        $rsElements = \CIBlockElement::GetList(
            [
                'SORT' => 'ASC',
                'ID' => 'ASC',
            ],
            $filter,
            false,
            ['nTopCount' => $limit],
            [
                'ID',
                'IBLOCK_ID',
                'NAME',
                'CODE',
                'DETAIL_PAGE_URL',
                'PREVIEW_PICTURE',
                'DETAIL_PICTURE',
            ]
        );

        while ($element = $rsElements->GetNext()) {
            $id = (int)$element['ID'];

            $items[] = [
                'id' => $id,
                'name' => (string)($element['NAME'] ?? ''),
                'url' => (string)($element['DETAIL_PAGE_URL'] ?? ''),
                'picture_src' => $this->getPictureSrc($element),
            ];
        }

        return $items;
    }

    private function normalizeIds(array $ids): array
    {
        $result = [];

        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $result[] = $id;
            }
        }

        return array_values(array_unique($result));
    }

    private function getPictureSrc(array $element): string
    {
        $pictureId = (int)($element['PREVIEW_PICTURE'] ?: $element['DETAIL_PICTURE']);
        if ($pictureId <= 0 || !class_exists('CFile')) {
            return '';
        }

        return (string)\CFile::GetPath($pictureId);
    }
}
