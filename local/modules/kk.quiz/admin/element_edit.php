<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Kk\Quiz\Iblock\Installer;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
global $APPLICATION, $USER;
if (!is_object($USER) || !$USER->IsAuthorized() || !$USER->IsAdmin()) { $APPLICATION->AuthForm('Доступ запрещён'); }
if (!Loader::includeModule('kk.quiz') || !Loader::includeModule('iblock')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    CAdminMessage::ShowMessage('Модуль kk.quiz или iblock не установлен.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; return;
}
$lang = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
$id = (int)($_REQUEST['ID'] ?? 0); $sectionId = (int)($_REQUEST['SECTION_ID'] ?? 0);
$back = in_array((string)($_REQUEST['back'] ?? 'list'), ['schema','list','edit'], true) ? (string)$_REQUEST['back'] : 'list';
$iblock = CIBlock::GetList([], ['TYPE'=>Installer::IBLOCK_TYPE_ID,'CODE'=>Installer::QUIZZES_IBLOCK_CODE])->Fetch();
$iblockId = (int)($iblock['ID'] ?? 0);
$elementObject = $id && $sectionId ? CIBlockElement::GetList([], ['ID'=>$id,'IBLOCK_ID'=>$iblockId,'SECTION_ID'=>$sectionId,'INCLUDE_SUBSECTIONS'=>'N'], false, false, ['*'])->GetNextElement() : false;
$fields = $elementObject ? $elementObject->GetFields() : []; $properties = $elementObject ? $elementObject->GetProperties() : [];
$type = strtoupper((string)($properties['KK_ENTITY_TYPE']['VALUE_XML_ID'] ?? $properties['KK_ENTITY_TYPE']['VALUE'] ?? ''));
$valid = $elementObject && in_array($type, ['QUESTION','RESULT'], true);
$schemaUrl = 'kk_quiz_schema.php?' . http_build_query(['ID'=>$sectionId,'lang'=>$lang]);
$listUrl = 'kk_quiz_quizzes.php?' . http_build_query(['lang'=>$lang]);
$returnUrl = $back === 'schema' ? $schemaUrl : $listUrl;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel']) && check_bitrix_sessid()) { LocalRedirect($returnUrl); }
$APPLICATION->SetTitle($type === 'RESULT' ? 'KK Quiz — редактирование результата' : 'KK Quiz — редактирование вопроса');
$errors = [];
$getPropertyRawValue = static function (array $properties, string $code): mixed {
    return $properties[$code]['VALUE'] ?? null;
};
$getPropertyScalarValue = static function (array $properties, string $code) use ($getPropertyRawValue): string {
    $value = $getPropertyRawValue($properties, $code);
    if (is_array($value)) {
        $value = reset($value);
    }
    return is_scalar($value) ? (string)$value : '';
};
$getPropertyEnumXmlId = static function (array $properties, string $code): string {
    $value = $properties[$code]['VALUE_XML_ID'] ?? '';
    if (is_array($value)) {
        $value = reset($value);
    }
    return is_scalar($value) ? (string)$value : '';
};
$getPropertyEnumId = static function (array $properties, string $code): int {
    $value = $properties[$code]['VALUE_ENUM_ID'] ?? 0;
    if (is_array($value)) {
        $value = reset($value);
    }
    return (int)$value;
};
$getPropertyMultipleValues = static function (array $properties, string $code) use ($getPropertyRawValue): array {
    $value = $getPropertyRawValue($properties, $code);
    if ($value === null || $value === '') {
        return [];
    }
    return array_values(array_filter(is_array($value) ? $value : [$value], static fn (mixed $item): bool => $item !== null && $item !== ''));
};
$getIblockPropertyEnumOptions = static function (int $iblockId, string $propertyCode): array {
    $options = [];
    $enumResult = CIBlockPropertyEnum::GetList(
        ['SORT' => 'ASC', 'ID' => 'ASC'],
        ['IBLOCK_ID' => $iblockId, 'CODE' => $propertyCode]
    );
    while ($enum = $enumResult->Fetch()) {
        $xmlId = (string)($enum['XML_ID'] ?? '');
        if ($xmlId === '') {
            $xmlId = (string)$enum['ID'];
        }
        $options[$xmlId] = [
            'id' => (int)$enum['ID'],
            'xml_id' => $xmlId,
            'value' => (string)$enum['VALUE'],
        ];
    }
    return $options;
};
$enumFields = $type === 'QUESTION'
    ? ['KK_QUESTION_TYPE', 'KK_DISPLAY_TEMPLATE', 'KK_IMAGE_RATIO', 'KK_IMAGE_FIT', 'KK_IS_REQUIRED', 'KK_ALLOW_CUSTOM_ANSWER']
    : ['KK_RESULT_CTA_TARGET', 'KK_RESULT_SECONDARY_CTA_TARGET', 'KK_RESULT_SHOW_FORM', 'KK_RESULT_VIDEO_POSITION'];
$enumOptions = [];
$enumLoadFailed = false;
foreach ($enumFields as $enumField) {
    $enumOptions[$enumField] = $getIblockPropertyEnumOptions($iblockId, $enumField);
    if ($enumOptions[$enumField] === []) {
        $enumLoadFailed = true;
    }
}
$getCurrentEnumXmlId = static function (string $code) use ($properties, $enumOptions, $getPropertyEnumXmlId, $getPropertyEnumId): string {
    $xmlId = $getPropertyEnumXmlId($properties, $code);
    if ($xmlId !== '') {
        return $xmlId;
    }
    $enumId = $getPropertyEnumId($properties, $code);
    foreach ($enumOptions[$code] ?? [] as $option) {
        if ($option['id'] === $enumId) {
            return $option['xml_id'];
        }
    }
    return '';
};
$normalizePostedPropertyValue = static function (string $code, mixed $value) use ($properties, $enumOptions): mixed {
    if (($properties[$code]['PROPERTY_TYPE'] ?? '') === 'L') {
        foreach ($enumOptions[$code] ?? [] as $option) {
            if ((string)$value === $option['xml_id'] || (string)$value === (string)$option['id']) {
                return $option['id'];
            }
        }
        return null;
    }
    if (($properties[$code]['MULTIPLE'] ?? 'N') === 'Y') {
        if (is_string($value)) {
            $value = preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }
        return array_values(array_filter(array_map('intval', (array)$value)));
    }
    return is_string($value) ? trim($value) : $value;
};
$urlValid = static fn (string $url): bool => $url === '' || str_starts_with($url, '/') || preg_match('~^https?://[^\s]+$~i', $url) === 1;
$rawAnswers = $getPropertyScalarValue($properties, 'KK_ANSWERS');
$answerRows = $rawAnswers === '' ? [] : json_decode($rawAnswers, true);
if (!is_array($answerRows)) {
    $answerRows = [];
}
$answerRows = $answerRows['rows'] ?? $answerRows;
$answerKey = static function (array $answer, string $key, mixed $default = ''): mixed {
    return $answer[$key] ?? $answer[strtoupper($key)] ?? $default;
};
$normalizedAnswers = [];
foreach ($answerRows as $answer) {
    if (!is_array($answer)) {
        continue;
    }
    $normalizedAnswers[] = [
        'active' => in_array($answerKey($answer, 'active', false), [true, 1, '1', 'Y'], true),
        'sort' => (int)$answerKey($answer, 'sort', 500),
        'text' => (string)$answerKey($answer, 'text'),
        'code' => (string)$answerKey($answer, 'code'),
        'description' => (string)$answerKey($answer, 'description'),
        'image_id' => (int)$answerKey($answer, 'image_id', 0),
        'image_src' => (string)$answerKey($answer, 'image_src'),
        'next_question_id' => (int)$answerKey($answer, 'next_question_id', 0),
        'result_id' => (int)$answerKey($answer, 'result_id', 0),
        'score_result_id' => (int)$answerKey($answer, 'score_result_id', 0),
        'score_value' => (int)$answerKey($answer, 'score_value', 0),
    ];
}
$answerRows = $normalizedAnswers;
$questionCodes = ['KK_QUESTION_TYPE', 'KK_DISPLAY_TEMPLATE', 'KK_IMAGE_RATIO', 'KK_IMAGE_FIT', 'KK_IS_REQUIRED', 'KK_PLACEHOLDER', 'KK_ALLOW_CUSTOM_ANSWER', 'KK_DEFAULT_NEXT_QUESTION', 'KK_DEFAULT_RESULT'];
$resultCodes = ['KK_RESULT_BADGE', 'KK_RESULT_SUMMARY', 'KK_RESULT_WHY_TEXT', 'KK_RESULT_FIT_TEXT', 'KK_RESULT_SPECS_TEXT', 'KK_RESULT_BUDGET_TEXT', 'KK_RESULT_NOTE_TEXT', 'KK_RESULT_CTA_TEXT', 'KK_RESULT_CTA_LINK', 'KK_RESULT_CTA_TARGET', 'KK_RESULT_SECONDARY_CTA_TEXT', 'KK_RESULT_SECONDARY_CTA_LINK', 'KK_RESULT_SECONDARY_CTA_TARGET', 'KK_RESULT_FORM_TITLE', 'KK_RESULT_FORM_INTRO', 'KK_RESULT_FORM_BUTTON_TEXT', 'KK_RESULT_SHOW_FORM', 'KK_RESULT_VIDEO_URL', 'KK_RESULT_VIDEO_TITLE', 'KK_RESULT_VIDEO_POSITION', 'KK_RESULT_CATALOG_SECTION', 'KK_RESULT_CATALOG_PRODUCTS', 'KK_RESULT_MIN_SCORE', 'KK_RESULT_MAX_SCORE', 'KK_RESULT_PRIORITY'];
$isSave = $_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && (isset($_POST['save']) || isset($_POST['apply']));
if ($isSave && $valid) {
    if (($_POST['kk_quiz_custom_editor_loaded'] ?? '') !== 'Y') {
        $errors[] = 'Форма загружена некорректно. Сохранение отменено, чтобы не затереть данные.';
    }
    if ($enumLoadFailed) {
        $errors[] = 'Не удалось загрузить варианты свойств инфоблока. Сохранение отключено, чтобы не затереть данные.';
    }
    $name = trim((string)($_POST['NAME'] ?? ''));
    $code = trim((string)($_POST['CODE'] ?? ''));
    $sort = (string)($_POST['SORT'] ?? '');
    if ($name === '') $errors[] = 'Название обязательно.';
    if ($code === '' || preg_match('/^[a-zA-Z0-9_-]+$/', $code) !== 1) $errors[] = 'Код обязателен и может содержать только латиницу, цифры, дефис и подчёркивание.';
    if (preg_match('/^\d+$/', $sort) !== 1) $errors[] = 'Сортировка должна быть числом не меньше нуля.';
    foreach (['KK_RESULT_CTA_LINK', 'KK_RESULT_SECONDARY_CTA_LINK', 'KK_RESULT_VIDEO_URL'] as $urlField) {
        if (array_key_exists($urlField, $_POST) && !$urlValid(trim((string)$_POST[$urlField]))) {
            $errors[] = 'Некорректная ссылка в поле ' . $urlField . '.';
        }
    }
    $props = [];
    if (array_key_exists('KK_PUBLIC_TITLE', $_POST)) {
        $props['KK_PUBLIC_TITLE'] = trim((string)$_POST['KK_PUBLIC_TITLE']);
    }
    foreach ($type === 'QUESTION' ? $questionCodes : $resultCodes as $codeName) {
        if (!array_key_exists($codeName, $_POST)) {
            continue;
        }
        $normalized = $normalizePostedPropertyValue($codeName, $_POST[$codeName]);
        if ($normalized === null && ($properties[$codeName]['PROPERTY_TYPE'] ?? '') === 'L') {
            $errors[] = 'Недопустимое значение свойства ' . $codeName . '.';
            continue;
        }
        $props[$codeName] = $normalized;
    }
    if ($type === 'QUESTION' && array_key_exists('answers', $_POST)) {
        $rows = [];
        foreach ((array)$_POST['answers'] as $key => $row) {
            if (!is_array($row)) continue;
            $imageId = (int)($row['image_id'] ?? 0);
            if (!empty($row['delete_image'])) $imageId = 0;
            $file = $_FILES['answer_images'] ?? [];
            if (($file['error'][$key] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $upload = ['name'=>$file['name'][$key], 'type'=>$file['type'][$key], 'tmp_name'=>$file['tmp_name'][$key], 'error'=>$file['error'][$key], 'size'=>$file['size'][$key]];
                $extension = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
                if ($upload['size'] > 10 * 1024 * 1024 || !in_array($extension, ['jpg','jpeg','png','webp','gif'], true)) {
                    $errors[] = 'Картинка ответа должна быть JPG, PNG, WEBP или GIF размером до 10 МБ.';
                } else {
                    $savedImageId = (int)CFile::SaveFile($upload, 'kk.quiz/answers');
                    if ($savedImageId > 0) $imageId = $savedImageId; else $errors[] = 'Не удалось сохранить картинку ответа.';
                }
            }
            $rows[] = ['active'=>isset($row['active']), 'sort'=>max(0,(int)($row['sort']??0)), 'text'=>trim((string)($row['text']??'')), 'code'=>trim((string)($row['code']??'')), 'description'=>trim((string)($row['description']??'')), 'image_id'=>$imageId?:null, 'image_src'=>$imageId?(string)CFile::GetPath($imageId):'', 'next_question_id'=>max(0,(int)($row['next_question_id']??0)), 'result_id'=>max(0,(int)($row['result_id']??0)), 'score_result_id'=>max(0,(int)($row['score_result_id']??0)), 'score_value'=>(int)($row['score_value']??0)];
        }
        $props['KK_ANSWERS'] = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($errors === []) {
        $updater = new CIBlockElement();
        if ($updater->Update($id, ['NAME'=>$name, 'CODE'=>$code, 'SORT'=>(int)$sort, 'ACTIVE'=>isset($_POST['ACTIVE'])?'Y':'N', 'PREVIEW_TEXT'=>(string)($_POST['PREVIEW_TEXT']??$fields['PREVIEW_TEXT']??''), 'DETAIL_TEXT'=>(string)($_POST['DETAIL_TEXT']??$fields['DETAIL_TEXT']??'')])) {
            if ($props !== []) CIBlockElement::SetPropertyValuesEx($id, $iblockId, $props);
            $target = isset($_POST['apply']) ? 'kk_quiz_element_edit.php?' . http_build_query(['ID'=>$id,'SECTION_ID'=>$sectionId,'back'=>$back,'saved'=>'Y','lang'=>$lang]) : $returnUrl . '&saved=Y';
            LocalRedirect($target);
        } else {
            $errors[] = $updater->LAST_ERROR ?: 'Не удалось сохранить элемент.';
        }
    }
    $fields = array_merge($fields, $_POST);
    foreach ($props as $propertyCode => $propertyValue) $properties[$propertyCode]['VALUE'] = $propertyValue;
    $answerRows = $rows ?? $answerRows;
}
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
if (!$valid) { CAdminMessage::ShowMessage('Элемент не найден в указанном квизе или имеет некорректный тип.'); echo '<a class="adm-btn" href="'.htmlspecialcharsbx($returnUrl).'">Вернуться</a>'; require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php'; return; }
if (($_GET['saved'] ?? '') === 'Y') CAdminMessage::ShowMessage(['MESSAGE'=>'Изменения сохранены.','TYPE'=>'OK']);
if ($enumLoadFailed) CAdminMessage::ShowMessage('Не удалось загрузить варианты свойств инфоблока. Сохранение отключено, чтобы не затереть данные.');
foreach ($errors as $error) CAdminMessage::ShowMessage($error);
$technical = 'iblock_element_edit.php?' . http_build_query(['IBLOCK_ID'=>$iblockId,'type'=>Installer::IBLOCK_TYPE_ID,'ID'=>$id,'SECTION_ID'=>$sectionId,'find_section_section'=>$sectionId,'lang'=>$lang]);
$items = ['questions'=>[], 'results'=>[]];
$rs = CIBlockElement::GetList(['SORT'=>'ASC'], ['IBLOCK_ID'=>$iblockId,'SECTION_ID'=>$sectionId,'INCLUDE_SUBSECTIONS'=>'N'], false, false, ['ID','NAME']);
while ($object = $rs->GetNextElement()) {
    $itemFields = $object->GetFields(); $itemProperties = $object->GetProperties();
    $itemType = strtoupper((string)($itemProperties['KK_ENTITY_TYPE']['VALUE_XML_ID'] ?? $itemProperties['KK_ENTITY_TYPE']['VALUE'] ?? ''));
    if ($itemType === 'QUESTION' || $itemType === 'RESULT') {
        $publicTitle = $getPropertyScalarValue($itemProperties, 'KK_PUBLIC_TITLE');
        $items[$itemType === 'QUESTION' ? 'questions' : 'results'][(int)$itemFields['ID']] = $publicTitle !== '' ? $publicTitle : (string)$itemFields['NAME'];
    }
}
$esc = static fn (mixed $value): string => htmlspecialcharsbx((string)$value);
$select = static function (string $name, mixed $value, array $options, bool $empty = true) use ($esc): string {
    $html = '<select name="'.$esc($name).'">'.($empty?'<option value="">—</option>':'');
    foreach ($options as $key => $label) $html .= '<option value="'.$esc($key).'"'.((string)$key === (string)$value?' selected':'').'>'.$esc($label).'</option>';
    return $html.'</select>';
};
$enumSelectOptions = static function (array $options): array {
    $result = [];
    foreach ($options as $xmlId => $option) $result[$xmlId] = $option['value'];
    return $result;
};
$val = static function (string $key) use ($fields, $properties, $getPropertyScalarValue, $getPropertyMultipleValues): mixed {
    if (array_key_exists($key, $fields)) return $fields[$key];
    if (($properties[$key]['MULTIPLE'] ?? 'N') === 'Y') return $getPropertyMultipleValues($properties, $key);
    return $getPropertyScalarValue($properties, $key);
};
$tabs=$type==='QUESTION'?[['DIV'=>'main','TAB'=>'Основное'],['DIV'=>'answers','TAB'=>'Ответы'],['DIV'=>'transitions','TAB'=>'Переходы'],['DIV'=>'display','TAB'=>'Отображение'],['DIV'=>'tech','TAB'=>'Техническое']]:[['DIV'=>'main','TAB'=>'Основное'],['DIV'=>'content','TAB'=>'Контент результата'],['DIV'=>'cta','TAB'=>'CTA и форма'],['DIV'=>'video','TAB'=>'Видео и каталог'],['DIV'=>'score','TAB'=>'Scoring'],['DIV'=>'tech','TAB'=>'Техническое']];
$tab=new CAdminTabControl('kkQuizElementTabs',$tabs); ?>
<form method="post" enctype="multipart/form-data"><?php echo bitrix_sessid_post(); ?><input type="hidden" name="kk_quiz_custom_editor_loaded" value="Y"><?php $tab->Begin(); $tab->BeginNextTab(); ?>
<tr><td width="35%">Активность</td><td><input type="checkbox" name="ACTIVE" value="Y" <?=($val('ACTIVE')==='Y'||isset($_POST['ACTIVE']))?'checked':''?>></td></tr>
<tr class="adm-detail-required-field"><td>Административное название</td><td><input size="55" name="NAME" value="<?=$esc($val('NAME'))?>"></td></tr>
<tr><td>Публичный заголовок</td><td><input size="55" name="KK_PUBLIC_TITLE" value="<?=$esc($val('KK_PUBLIC_TITLE'))?>"></td></tr>
<tr><td>Краткий текст</td><td><textarea name="PREVIEW_TEXT" rows="4" cols="70"><?=$esc($val('PREVIEW_TEXT'))?></textarea></td></tr>
<tr><td>Детальный текст</td><td><textarea name="DETAIL_TEXT" rows="7" cols="70"><?=$esc($val('DETAIL_TEXT'))?></textarea></td></tr>
<?php if($type==='QUESTION'): $tab->BeginNextTab(); ?>
<tr><td colspan="2"><table class="adm-list-table" id="answers"><thead><tr><th>Вкл.</th><th>Сорт.</th><th>Текст / код / описание</th><th>Картинка</th><th>Следующий вопрос</th><th>Результат</th><th>Scoring</th><th></th></tr></thead><tbody><?php foreach($answerRows as $i=>$a): ?><tr><td><input type="checkbox" name="answers[<?=$i?>][active]" <?=!empty($a['active'])?'checked':''?>></td><td><input size="4" name="answers[<?=$i?>][sort]" value="<?=$esc($a['sort']??500)?>"></td><td><input name="answers[<?=$i?>][text]" placeholder="Текст" value="<?=$esc($a['text']??'')?>"><br><input name="answers[<?=$i?>][code]" placeholder="Код" value="<?=$esc($a['code']??'')?>"><br><textarea name="answers[<?=$i?>][description]" placeholder="Описание"><?=$esc($a['description']??'')?></textarea></td><td><?php if($a['image_id']??0):?><img src="<?=$esc(CFile::GetPath((int)$a['image_id']))?>" style="max-width:90px;max-height:70px"><br><?php endif?><input type="hidden" name="answers[<?=$i?>][image_id]" value="<?=(int)($a['image_id']??0)?>"><input type="file" accept="image/jpeg,image/png,image/webp,image/gif" name="answer_images[<?=$i?>]"><label><input type="checkbox" name="answers[<?=$i?>][delete_image]"> удалить</label></td><td><?=$select("answers[$i][next_question_id]",$a['next_question_id']??0,$items['questions'])?><?php if((int)($a['next_question_id']??0)===$id):?><br><small>Возможен цикл</small><?php endif?></td><td><?=$select("answers[$i][result_id]",$a['result_id']??0,$items['results'])?></td><td><?=$select("answers[$i][score_result_id]",$a['score_result_id']??0,$items['results'])?><br><input type="number" name="answers[<?=$i?>][score_value]" value="<?=$esc($a['score_value']??0)?>"></td><td><button type="button" onclick="this.closest('tr').remove()">Удалить</button></td></tr><?php endforeach?></tbody></table><button type="button" class="adm-btn" id="add-answer">Добавить ответ</button></td></tr>
<?php $tab->BeginNextTab(); ?><tr><td>Следующий вопрос по умолчанию</td><td><?=$select('KK_DEFAULT_NEXT_QUESTION',$val('KK_DEFAULT_NEXT_QUESTION'),$items['questions'])?></td></tr><tr><td>Результат по умолчанию</td><td><?=$select('KK_DEFAULT_RESULT',$val('KK_DEFAULT_RESULT'),$items['results'])?></td></tr>
<?php $tab->BeginNextTab(); foreach(['KK_QUESTION_TYPE'=>'Тип вопроса','KK_DISPLAY_TEMPLATE'=>'Шаблон отображения','KK_IMAGE_RATIO'=>'Соотношение картинки','KK_IMAGE_FIT'=>'Режим картинки','KK_IS_REQUIRED'=>'Обязательный вопрос','KK_ALLOW_CUSTOM_ANSWER'=>'Свой вариант'] as $k=>$label): ?><tr><td><?=$label?></td><td><?=$select($k,$getCurrentEnumXmlId($k),$enumSelectOptions($enumOptions[$k] ?? []),false)?></td></tr><?php endforeach?><tr><td>Placeholder</td><td><input name="KK_PLACEHOLDER" value="<?=$esc($val('KK_PLACEHOLDER'))?>"></td></tr>
<?php else: $labels=['KK_RESULT_BADGE'=>'Бейдж результата','KK_RESULT_SUMMARY'=>'Краткое описание','KK_RESULT_WHY_TEXT'=>'Почему мы рекомендуем этот вариант','KK_RESULT_FIT_TEXT'=>'Кому подойдёт','KK_RESULT_SPECS_TEXT'=>'Что будет внутри','KK_RESULT_BUDGET_TEXT'=>'Ориентир по бюджету','KK_RESULT_NOTE_TEXT'=>'Что важно учесть']; $tab->BeginNextTab(); foreach($labels as $k=>$label):?><tr><td><?=$label?><?php if($k==='KK_RESULT_SPECS_TEXT'):?><br><small>Например: видеокарта RTX 4060 Ti; процессор Core i5 / Ryzen 5; память 32 ГБ; SSD NVMe 1–2 ТБ.</small><?php endif?></td><td><textarea rows="5" cols="70" name="<?=$k?>"><?=$esc($val($k))?></textarea></td></tr><?php endforeach; $tab->BeginNextTab(); foreach(['KK_RESULT_CTA_TEXT'=>'Текст основной кнопки','KK_RESULT_CTA_LINK'=>'Ссылка основной кнопки','KK_RESULT_SECONDARY_CTA_TEXT'=>'Текст второй кнопки','KK_RESULT_SECONDARY_CTA_LINK'=>'Ссылка второй кнопки','KK_RESULT_FORM_TITLE'=>'Заголовок формы','KK_RESULT_FORM_BUTTON_TEXT'=>'Текст кнопки формы'] as$k=>$label):?><tr><td><?=$label?></td><td><input size="60" name="<?=$k?>" value="<?=$esc($val($k))?>"></td></tr><?php endforeach?><tr><td>Текст перед формой</td><td><textarea rows="4" cols="70" name="KK_RESULT_FORM_INTRO"><?=$esc($val('KK_RESULT_FORM_INTRO'))?></textarea></td></tr><?php foreach(['KK_RESULT_CTA_TARGET','KK_RESULT_SECONDARY_CTA_TARGET','KK_RESULT_SHOW_FORM'] as$k):?><tr><td><?=$esc($properties[$k]['NAME']??$k)?></td><td><?=$select($k,$getCurrentEnumXmlId($k),$enumSelectOptions($enumOptions[$k] ?? []),false)?></td></tr><?php endforeach; $tab->BeginNextTab(); foreach(['KK_RESULT_VIDEO_URL','KK_RESULT_VIDEO_TITLE','KK_RESULT_CATALOG_SECTION','KK_RESULT_CATALOG_PRODUCTS'] as$k):?><tr><td><?=$esc($properties[$k]['NAME']??$k)?></td><td><input size="60" name="<?=$k?>" value="<?=$esc(is_array($val($k))?implode(',',$val($k)):$val($k))?>"></td></tr><?php endforeach?><tr><td>Позиция видео</td><td><?=$select('KK_RESULT_VIDEO_POSITION',$getCurrentEnumXmlId('KK_RESULT_VIDEO_POSITION'),$enumSelectOptions($enumOptions['KK_RESULT_VIDEO_POSITION'] ?? []),false)?></td></tr><?php $tab->BeginNextTab(); foreach(['KK_RESULT_MIN_SCORE','KK_RESULT_MAX_SCORE','KK_RESULT_PRIORITY'] as$k):?><tr><td><?=$esc($properties[$k]['NAME']??$k)?></td><td><input type="number" name="<?=$k?>" value="<?=$esc($val($k))?>"></td></tr><?php endforeach; endif; $tab->BeginNextTab(); ?>
<tr><td>Символьный код</td><td><input name="CODE" value="<?=$esc($val('CODE'))?>"></td></tr><tr><td>Сортировка</td><td><input type="number" min="0" name="SORT" value="<?=$esc($val('SORT'))?>"></td></tr><tr><td>Тип сущности</td><td><strong><?=$esc($type)?></strong></td></tr><tr><td>ID</td><td><?=$id?></td></tr>
<?php $tab->Buttons(); ?><input type="submit" name="save" class="adm-btn-save" value="Сохранить" <?=$enumLoadFailed?'disabled':''?>> <input type="submit" name="apply" value="Применить" <?=$enumLoadFailed?'disabled':''?>> <input type="submit" name="cancel" value="Отмена"> <a class="adm-btn" href="<?=$esc($schemaUrl)?>">Вернуться к схеме</a> <a class="adm-btn" href="<?=$esc($technical)?>">Техническое редактирование в Bitrix</a><?php $tab->End(); ?></form>
<script>document.getElementById('add-answer')?.addEventListener('click',()=>{const b=document.querySelector('#answers tbody'),i=Date.now(),sample=b.querySelector('tr');if(sample){const row=sample.cloneNode(true);row.querySelectorAll('input,textarea,select').forEach(el=>{el.name=el.name.replace(/answers\[[^\]]+\]/,`answers[${i}]`);if(el.type==='checkbox')el.checked=el.name.endsWith('[active]');else if(el.type!=='hidden'&&el.type!=='number')el.value='';else if(el.type==='hidden')el.value='0'});row.querySelector('img')?.remove();b.appendChild(row);return}b.insertAdjacentHTML('beforeend',`<tr><td><input type="checkbox" name="answers[${i}][active]" checked></td><td><input size="4" name="answers[${i}][sort]" value="500"></td><td><input name="answers[${i}][text]" placeholder="Текст"><br><input name="answers[${i}][code]" placeholder="Код"><br><textarea name="answers[${i}][description]" placeholder="Описание"></textarea></td><td><input type="file" name="answer_images[${i}]" accept="image/jpeg,image/png,image/webp,image/gif"></td><td></td><td></td><td><input type="number" name="answers[${i}][score_value]" value="0"></td><td><button type="button" onclick="this.closest('tr').remove()">Удалить</button></td></tr>`)});</script>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
