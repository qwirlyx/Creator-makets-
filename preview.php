<?php
require_once __DIR__ . '/auth_check.php';
// preview.php - Генерация превью на лету

if (!extension_loaded('gd')) {
    header("HTTP/1.1 500 Internal Server Error");
    die('GD extension is not loaded.');
} 

require_once __DIR__ . '/functions.php';
include __DIR__ . '/makets.php';

$creativeName = $_POST['creative_preview'] ?? '';

if (empty($creativeName) || !isset($settings[$creativeName])) {
    header("HTTP/1.1 400 Bad Request");
    die('Invalid creative.');
}

$postData = getSanitizedPostData();
$replacements = prepareReplacements($postData);

$locCount = 1;
for ($i = 1; $i <= 4; $i++) {
    if (!empty($postData["address$i"]) || !empty($postData["sumAddress$i"]) || !empty($postData["h_sumAddress$i"]) || !empty($postData["springCity$i"])) {
        $locCount = $i;
    }
}

// ПРЕДОТВРАЩАЕМ ПАДЕНИЕ: Передаем false, чтобы не вызывать Python на каждый чих
// --- ВЫБОР ШАБЛОНА ДЛЯ ПРЕВЬЮ ---
$currentSettings = $settings[$creativeName];

// Проверяем количество точек и подменяем фон, если есть template_2 или template_3
if ($locCount == 2 && isset($currentSettings['template_2'])) {
    $currentSettings['template'] = $currentSettings['template_2'];
} elseif ($locCount >= 3 && isset($currentSettings['template_3'])) {
    $currentSettings['template'] = $currentSettings['template_3'];
}
// --------------------------------

// Передаем обновленные настройки $currentSettings
$result = processSingleCreative($currentSettings, $replacements, $locCount, false);

if ($result && isset($result['image'])) {
    header('Content-Type: image/webp');
    imagewebp($result['image'], null, 80);
    imagedestroy($result['image']);
} else {
    header("HTTP/1.1 500 Internal Server Error");
    die('Failed to generate image.');
}
?>