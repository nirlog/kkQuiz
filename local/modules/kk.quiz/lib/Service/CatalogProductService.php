<?php

declare(strict_types=1);

namespace Kk\Quiz\Service;

use Bitrix\Main\Loader;

final class CatalogProductService
{
    public function getProducts(int $iblockId, array $productIds, int $limit = 6): array
    {
        $iblockId = max(0, $iblockId);
        $productIds = $this->normalizeIds($productIds);
        $limit = max(1, min(20, $limit));
        $productIds = array_slice($productIds, 0, $limit);

        if ($iblockId <= 0 || $productIds === [] || !Loader::includeModule('iblock')) {
            return [];
        }

        $sortIndex = array_flip($productIds);
        $items = [];

        $rsElements = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            [
                'IBLOCK_ID' => $iblockId,
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
