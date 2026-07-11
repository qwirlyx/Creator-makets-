<?php
require_once __DIR__ . '/auth_check.php';
// create.php - Обработка генерации макетов и сборка ZIP

if (!extension_loaded('gd')) {
    die('GD extension is not loaded.');
}

require_once __DIR__ . '/functions.php';
include __DIR__.'/makets.php';

$outputDir = 'output/'; 
$zipFile = 'makety.zip'; 

if (!file_exists($outputDir)) {
    mkdir($outputDir, 0777, true);
}

// Узнаем, нажата ли кнопка SVG
$isSvgExport = isset($_POST['export_svg']) && $_POST['export_svg'] == '1';

$postData = getSanitizedPostData();
$replacements = prepareReplacements($postData);

$creativesStr = isset($_POST['creatives']) ? sanitizeInput($_POST['creatives'][0]) : '';
$selectedCreatives = array_filter(explode(',', $creativesStr));

if (empty($selectedCreatives)) {
    die('Нет выбранных макетов.');
}

$requiredFields = [];
foreach ($selectedCreatives as $creative) {
    if (isset($settings[$creative]['fields'])) {
        $requiredFields = array_merge($requiredFields, $settings[$creative]['fields']);
    }
}
$requiredFields = array_unique($requiredFields);

foreach ($requiredFields as $field) {
    if ($field === 'link' && empty($postData['group_url'])) die('Поле "Ссылка на группу" обязательно.');
    if ($field === 'city' && empty($postData['city'])) die('Поле "Город" обязательно.');
}

$generatedFiles = [];

foreach ($selectedCreatives as $creative) {
    if (!isset($settings[$creative])) continue;

    $section = $settings[$creative]['section'];
    $sectionDir = $outputDir . $section . '/';
    if (!file_exists($sectionDir)) {
        mkdir($sectionDir, 0777, true);
    }

    $locCount = 1;
    for ($i = 1; $i <= 4; $i++) {
        if (!empty($postData["address$i"]) || !empty($postData["sumAddress$i"]) || !empty($postData["h_sumAddress$i"]) || !empty($postData["springCity$i"])) {
            $locCount = $i;
        }
    }

    // --- ВЫБОР ШАБЛОНА В ЗАВИСИМОСТИ ОТ КОЛИЧЕСТВА ТОЧЕК ---
    $currentSettings = $settings[$creative];
    
    if ($locCount == 2 && isset($currentSettings['template_2'])) {
        $currentSettings['template'] = $currentSettings['template_2'];
    } elseif ($locCount >= 3 && isset($currentSettings['template_3'])) {
        $currentSettings['template'] = $currentSettings['template_3'];
    }

    $result = processSingleCreative($currentSettings, $replacements, $locCount, $isSvgExport);
    if (!$result) continue;

    if ($isSvgExport) {
        $outputImagePath = $sectionDir . $creative . '.svg';
        file_put_contents($outputImagePath, $result['svg']);
    } else {
        $outputImagePath = $sectionDir . $creative . '.png';
        imagewebp($result['image'], $outputImagePath, 90); 
    }
    
    imagedestroy($result['image']);
    $generatedFiles[] = $outputImagePath;
    
    if (isset($settings[$creative]['exportText']) && $settings[$creative]['exportText'] && isset($settings[$creative]['text']['template'])) {
        $textTemplate = $settings[$creative]['text']['template'];
        $generatedText = str_replace(array_keys($replacements), array_values($replacements), $textTemplate);
        $outputTextPath = $sectionDir . $creative . '.txt';
        file_put_contents($outputTextPath, $generatedText);
        $generatedFiles[] = $outputTextPath;
    }
}

if (!empty($generatedFiles)) {
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        foreach ($generatedFiles as $file) {
            $relativePath = str_replace($outputDir, '', $file);
            $zip->addFile($file, $relativePath);
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Transfer-Encoding: Binary');
        header('Content-Disposition: attachment; filename="' . $zipFile . '"');
        readfile($zipFile);

        unlink($zipFile);
        foreach ($generatedFiles as $file) {
            unlink($file);
        }
    } else {
        echo 'Ошибка создания архива.';
    }
} else {
    echo 'Нет выбранных макетов.';
}
?>