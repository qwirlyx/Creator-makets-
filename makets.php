<?php

$settings = [];

// Путь к папке с конфигурациями разделов
$configsDir = __DIR__ . '/configs/';

// Ищем все .php файлы в папке configs
$configFiles = glob($configsDir . '*.php');

if ($configFiles !== false) {
    foreach ($configFiles as $file) {
        $sectionSettings = include $file;
        
        // Если файл вернул массив, сливаем его с общим массивом настроек
        if (is_array($sectionSettings)) {
            $settings = array_merge($settings, $sectionSettings);
        }
    }
}