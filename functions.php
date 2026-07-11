<?php
// functions.php - Общее ядро для генерации изображений

define('TEMPLATES_DIR', __DIR__ . '/templates/');
define('FONTS_DIR', __DIR__ . '/fonts/');

// Точный путь к Python
$appConfig = file_exists(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : [];
define('PYTHON_EXECUTABLE', $appConfig['python_executable'] ?? 'python3');

require_once __DIR__ . '/morphos/src/Cases.php';
require_once __DIR__ . '/morphos/src/Russian/Cases.php';
require_once __DIR__ . '/morphos/src/S.php';
require_once __DIR__ . '/morphos/src/CasesHelper.php';
require_once __DIR__ . '/morphos/src/Russian/RussianCasesHelper.php';
require_once __DIR__ . '/morphos/src/Russian/RussianLanguage.php';
require_once __DIR__ . '/morphos/src/BaseInflection.php';
require_once __DIR__ . '/morphos/src/Russian/GeographicalNamesInflection.php';

use morphos\Russian\GeographicalNamesInflection;

// --- ФУНКЦИИ БЕЗОПАСНОСТИ И ОЧИСТКИ ---
function sanitizeInput($input) {
    if (is_array($input)) return array_map('sanitizeInput', $input);
    return htmlspecialchars(trim(strip_tags($input ?? '')), ENT_QUOTES, 'UTF-8');
}

function getSanitizedPostData() {
    $data = [];
    $data['city'] = sanitizeInput($_POST['city'] ?? '');
    $data['phone'] = sanitizeInput($_POST['phone'] ?? '');
    $data['group_url'] = sanitizeInput($_POST['link'] ?? '');
    $data['date'] = sanitizeInput($_POST['date'] ?? '');
    $data['address'] = sanitizeInput($_POST['address'] ?? '');
    $data['main_header'] = sanitizeInput($_POST['main_header'] ?? '');
    
    $data['name'] = sanitizeInput($_POST['name'] ?? '');
    $data['topic'] = sanitizeInput($_POST['topic'] ?? '');

    $data['other_date1'] = sanitizeInput($_POST['other_date1'] ?? '');
    $data['other_date2'] = sanitizeInput($_POST['other_date2'] ?? '');
    $data['other_time1'] = sanitizeInput($_POST['other_time1'] ?? '');
    $data['other_time2'] = sanitizeInput($_POST['other_time2'] ?? '');
    $data['h_showOpeningBadge'] = isset($_POST['h_showOpeningBadge']) ? 'on' : 'off';

    foreach (['weekDays', 'time'] as $key) {
        for ($i = 1; $i <= 8; $i++) {
            $data[$key . $i] = sanitizeInput($_POST[$key . $i] ?? '');
        }
    }

    foreach (['address', 'locCity'] as $key) {
        for ($i = 1; $i <= 4; $i++) {
            $data[$key . $i] = sanitizeInput($_POST[$key . $i] ?? '');
        }
    }
    
    for ($i = 1; $i <= 10; $i++) {
        $data["h_springCity$i"] = sanitizeInput($_POST["h_springCity$i"] ?? '');
        $data["h_springSide$i"] = sanitizeInput($_POST["h_springSide$i"] ?? 'left');
    }
    
    for ($i = 1; $i <= 4; $i++) {
        $data["h_sumCity$i"]    = sanitizeInput($_POST["h_sumCity$i"] ?? '');
        $data["h_sumAddress$i"] = sanitizeInput($_POST["h_sumAddress$i"] ?? '');
        $data["h_sumPhone$i"]   = sanitizeInput($_POST["h_sumPhone$i"] ?? '');
        $data["sumAddress$i"] = sanitizeInput($_POST["sumAddress$i"] ?? '');
        $data["sumPhone$i"]   = sanitizeInput($_POST["sumPhone$i"] ?? '');
        $data["soon$i"]       = isset($_POST["soon$i"]) ? 'on' : 'off';
        $data["springCity$i"] = sanitizeInput($_POST["springCity$i"] ?? '');
        for ($j = 1; $j <= 10; $j++) {
            $data["springAddr{$i}_{$j}"] = sanitizeInput($_POST["springAddr{$i}_{$j}"] ?? '');
        }
        for ($j = 1; $j <= 20; $j++) { // Тут лимит 20
            $data["h_springAddr{$i}_{$j}"] = sanitizeInput($_POST["h_springAddr{$i}_{$j}"] ?? '');
            $data["h_springPhone{$i}_{$j}"] = sanitizeInput($_POST["h_springPhone{$i}_{$j}"] ?? '');
        }
    }
    return $data;
}

// --- SVG ХЕЛПЕРЫ ---
function getSvgColorAttrs($colorArray, $alpha127 = 0) {
    if (!isset($colorArray[3])) $colorArray[3] = $alpha127;
    $a = round(1 - ($colorArray[3] / 127), 2);
    $hex = sprintf("#%02x%02x%02x", $colorArray[0], $colorArray[1], $colorArray[2]);
    if ($a < 1) return sprintf('fill="%s" fill-opacity="%.2f"', $hex, $a);
    return sprintf('fill="%s"', $hex);
}

function getSvgStrokeAttrs($colorArray, $thickness, $alpha127 = 0) {
    if (!isset($colorArray[3])) $colorArray[3] = $alpha127;
    $a = round(1 - ($colorArray[3] / 127), 2);
    $hex = sprintf("#%02x%02x%02x", $colorArray[0], $colorArray[1], $colorArray[2]);
    $attrs = sprintf('stroke="%s" stroke-width="%d"', $hex, $thickness);
    if ($a < 1) $attrs .= sprintf(' stroke-opacity="%.2f"', $a);
    return $attrs;
}

function addSvgText(&$svg, $x, $y, $text, $fontPath, $size, $colorArray, $angle = 0) {
    if ($svg === null) return;
    $alpha = isset($colorArray[3]) ? $colorArray[3] : 0;
    $opacity = round(1 - ($alpha / 127), 2);
    $hex = sprintf("#%02x%02x%02x", $colorArray[0], $colorArray[1], $colorArray[2]);
    
    $svg[] = [
        'type' => 'text',
        'text' => $text,
        'font' => realpath($fontPath) ?: $fontPath,
        'size' => $size,
        'x' => $x,
        'y' => $y,
        'color' => $hex,
        'opacity' => $opacity,
        'angle' => $angle
    ];
}

function addSvgRect(&$svg, $x1, $y1, $x2, $y2, $radius, $fillColorArray, $strokeColorArray = null, $thickness = 1) {
    if ($svg === null) return;
    $w = $x2 - $x1;
    $h = $y2 - $y1;
    $fillAttrs = $fillColorArray ? getSvgColorAttrs($fillColorArray) : 'fill="none"';
    $strokeAttrs = $strokeColorArray ? getSvgStrokeAttrs($strokeColorArray, $thickness) : '';
    
    $svg[] = sprintf('<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="%.2f" ry="%.2f" %s %s />',
        $x1, $y1, $w, $h, $radius, $radius, $fillAttrs, $strokeAttrs);
}

function addSvgCircle(&$svg, $cx, $cy, $r, $fillColorArray) {
    if ($svg === null) return;
    $fillAttrs = getSvgColorAttrs($fillColorArray);
    $svg[] = sprintf('<circle cx="%.2f" cy="%.2f" r="%.2f" %s />', $cx, $cy, $r, $fillAttrs);
}

function calculateFitFontSize($text, $fontPath, $startSize, $maxWidth) {
    if (empty(trim($text))) return $startSize;
    $fontSize = $startSize;
    do {
        $bbox = imagettfbbox($fontSize, 0, $fontPath, $text);
        $textWidth = abs($bbox[2] - $bbox[0]);
        if ($textWidth > $maxWidth && $fontSize > 15) {
            $fontSize -= 2;
        } else {
            break;
        }
    } while ($fontSize > 15);
    return $fontSize;
}

function extractCity(string $city): string {
    $pattern = '/(?:г\.|г\s|город\s)([^\,]+?)(?:,|$)/iu';
    if (preg_match($pattern, $city, $matches)) {
        return trim($matches[1]);
    }
    return '';
}

function convertDateFormat($date) {
    $months = ['01' => 'января','02' => 'февраля','03' => 'марта','04' => 'апреля','05' => 'мая','06' => 'июня','07' => 'июля','08' => 'августа','09' => 'сентября','10' => 'октября','11' => 'ноября','12' => 'декабря'];
    if (preg_match('/^(\d{1,2})\.(\d{2})$/', $date, $m)) return $m[1] . ' ' . $months[$m[2]];
    if (preg_match('/^(\d{1,2})\s+([а-яё]+)$/iu', $date, $m)) {
        $monthName = mb_strtolower($m[2]);
        $monthNum = array_search($monthName, $months);
        if ($monthNum !== false) return sprintf('%02d.%02d', $m[1], $monthNum);
    }
    return $date;
}

function prepareReplacements($data) {
    // Декодируем обратно — данные пойдут в GD/Python для рендеринга, не в HTML
    $data = array_map(function($v) {
        return is_string($v) ? html_entity_decode($v, ENT_QUOTES, 'UTF-8') : $v;
    }, $data);

    $city = $data['city'] ?? '';
    $group_url = $data['group_url'] ?? '';
    $date = $data['date'] ?? '';
    
    $city_big = mb_strtoupper($city);
    $group_id_clean = preg_replace('#^https?://#', '', $group_url);
    $vk_id_clean = preg_replace('#^https?://vk\.(com|ru)/#', '', $group_url);
    $street = preg_replace('/^\S+\s+\S+\s+/', '', $city);

    if (mb_strlen($city, 'UTF-8') >= 25) {
        $textCenter = "center";
        $paddingText = 0;
    } else {
        $textCenter = "left";
        $paddingText = 170;
    }

    // Логика даты
    $date_text = ''; $date_number = '';
    if (preg_match('/^(\d{1,2})\.(\d{2})$/', $date, $m)) {
        $date_text = convertDateFormat($date); $date_number = $date;
    } elseif (preg_match('/^(\d{1,2})\s+([а-яё]+)$/iu', $date, $m)) {
        $date_number = convertDateFormat($date); $date_text = $date;
    } else {
        $date_text = $date; $date_number = $date;
    }
    $date_big_text = mb_strtoupper($date_text);

    $cityOnly = extractCity($city);
    $city_incline = '';
    if ($cityOnly) {
        try { $city_incline = GeographicalNamesInflection::getCase($cityOnly, 'dative'); } 
        catch (Exception $e) { $city_incline = $cityOnly; }
    }

    $group_id = preg_replace('#^https?://[^/]+/#i', '@', $group_url);
    if (!empty($group_id) && strpos($group_id, '@') !== 0) $group_id = '@' . $group_id;
    
    // Основные поля
    $res = [
        '{city}' => $city, 
        '{phone}' => $data['phone'] ?? '', 
        '{group_url}' => $group_url, 
        '{group_id}' => $group_id,
        '{date}' => $date, 
        '{date_text}' => $date_text, 
        '{date_number}' => $date_number, 
        '{date_big_text}' => $date_big_text,
        '{city_big}' => $city_big, 
        '{group_id_clean}' => $group_id_clean, 
        '{vk_id_clean}' => $vk_id_clean,
        '{street}' => $street, 
        '{city_incline}' => $city_incline, 
        '{cityOnly}' => $cityOnly, 
        '{address}' => $data['address'] ?? '',
        '{main_header}' => $data['main_header'] ?? '',
        '{name}' => $data['name'] ?? '',
        '{topic}' => $data['topic'] ?? '',
        '{other_date1}' => $data['other_date1'] ?? '',
        '{other_date2}' => $data['other_date2'] ?? '',
        '{other_time1}' => $data['other_time1'] ?? '',
        '{other_time2}' => $data['other_time2'] ?? '',
        '{textCenter}' => $textCenter, 
        '{paddingText}' => $paddingText,
        '{h_showOpeningBadge}' => $data['h_showOpeningBadge'] ?? 'off',
    ];

    // Массив для цикличных полей (до 8 штук для обычных локаций)
    for ($i = 1; $i <= 8; $i++) {
        $res["{weekDays$i}"] = $data["weekDays$i"] ?? '';
        $res["{address$i}"] = $data["address$i"] ?? '';
        $res["{time$i}"] = $data["time$i"] ?? '';
        $res["{locCity$i}"] = $data["locCity$i"] ?? '';
    }

    // Цикл для списков (Города 1-4)
    for ($i = 1; $i <= 4; $i++) {
        // Обычные списки
        $res["{h_sumCity$i}"] = $data["h_sumCity$i"] ?? '';
        $res["{h_sumAddress$i}"] = $data["h_sumAddress$i"] ?? '';
        $res["{h_sumPhone$i}"] = $data["h_sumPhone$i"] ?? '';
        $res["{sumAddress$i}"] = $data["sumAddress$i"] ?? '';
        $res["{sumPhone$i}"] = $data["sumPhone$i"] ?? '';
        $res["{soon$i}"] = $data["soon$i"] ?? '';
        $res["{springCity$i}"] = $data["springCity$i"] ?? '';
        $res["{h_springCity$i}"] = $data["h_springCity$i"] ?? '';
        $res["{h_springSide$i}"] = $data["h_springSide$i"] ?? '';
        
        for ($j = 1; $j <= 10; $j++) {
            $res["{springAddr{$i}_{$j}}"] = $data["springAddr{$i}_{$j}"] ?? '';
        }
        
        for ($j = 1; $j <= 20; $j++) {
            $res["{h_springAddr{$i}_{$j}}"]  = $data["h_springAddr{$i}_{$j}"] ?? '';
            $res["{h_springPhone{$i}_{$j}}"] = $data["h_springPhone{$i}_{$j}"] ?? '';
        }
    }

    return $res;
}

// --- СУПЕРСЕМПЛИНГ ДЛЯ ИДЕАЛЬНО ГЛАДКИХ ПЛАШЕК ---
function imagefillroundedrect($im, $x1, $y1, $x2, $y2, $rad, $bg_color, $border_color = null, $thickness = 1) {
    // 4x масштаб для идеального сглаживания
    $scale = 4;
    
    // Защита от перепутанных координат
    $minX = min($x1, $x2); $maxX = max($x1, $x2);
    $minY = min($y1, $y2); $maxY = max($y1, $y2);
    
    $w = ($maxX - $minX) + 2; 
    $h = ($maxY - $minY) + 2;
    
    $sw = $w * $scale;
    $sh = $h * $scale;
    $sRadius = $rad * $scale;
    $sThickness = $thickness * $scale;

    // Временный огромный холст
    $temp = imagecreatetruecolor($sw, $sh);
    imagealphablending($temp, false);
    imagesavealpha($temp, true);
    
    // Полностью прозрачный фон
    $trans = imagecolorallocatealpha($temp, 0, 0, 0, 127);
    imagefill($temp, 0, 0, $trans);

    // Внутренние координаты с отступом от краев, чтобы не обрезалось при ресайзе
    $tx1 = 1 * $scale;
    $ty1 = 1 * $scale;
    $tx2 = $sw - 1 * $scale;
    $ty2 = $sh - 1 * $scale;

    // Извлечение реальных RGBA компонентов для переноса на новый холст
    $extractColor = function($c) use ($temp) {
        if ($c === null) return null;
        $r = ($c >> 16) & 0xFF;
        $g = ($c >> 8) & 0xFF;
        $b = $c & 0xFF;
        $alpha = ($c >> 24) & 0x7F;
        return imagecolorallocatealpha($temp, $r, $g, $b, $alpha);
    };

    $tBgColor = $extractColor($bg_color);
    $tBorderColor = $extractColor($border_color);

    // Если есть рамка - рисуем ее как большую фигуру, а внутрь заливаем фон
    if ($tBorderColor !== null) {
        // Отрисовка внешней рамки (сплошная заливка)
        imagefilledrectangle($temp, $tx1, $ty1 + $sRadius, $tx2, $ty2 - $sRadius, $tBorderColor);
        imagefilledrectangle($temp, $tx1 + $sRadius, $ty1, $tx2 - $sRadius, $ty2, $tBorderColor);
        imagefilledarc($temp, $tx1 + $sRadius, $ty1 + $sRadius, $sRadius*2, $sRadius*2, 180, 270, $tBorderColor, IMG_ARC_PIE);
        imagefilledarc($temp, $tx2 - $sRadius, $ty1 + $sRadius, $sRadius*2, $sRadius*2, 270, 360, $tBorderColor, IMG_ARC_PIE);
        imagefilledarc($temp, $tx1 + $sRadius, $ty2 - $sRadius, $sRadius*2, $sRadius*2, 90, 180, $tBorderColor, IMG_ARC_PIE);
        imagefilledarc($temp, $tx2 - $sRadius, $ty2 - $sRadius, $sRadius*2, $sRadius*2, 0, 90, $tBorderColor, IMG_ARC_PIE);

        // Отрисовка внутренностей
        $inx1 = $tx1 + $sThickness;
        $iny1 = $ty1 + $sThickness;
        $inx2 = $tx2 - $sThickness;
        $iny2 = $ty2 - $sThickness;
        $inRadius = max(0, $sRadius - $sThickness);
        
        // Если фон полностью прозрачный - используем дыру (trans), иначе цвет
        $fillCol = ((($bg_color >> 24) & 0x7F) == 127) ? $trans : $tBgColor;

        imagefilledrectangle($temp, $inx1, $iny1 + $inRadius, $inx2, $iny2 - $inRadius, $fillCol);
        imagefilledrectangle($temp, $inx1 + $inRadius, $iny1, $inx2 - $inRadius, $iny2, $fillCol);
        if ($inRadius > 0) {
            imagefilledarc($temp, $inx1 + $inRadius, $iny1 + $inRadius, $inRadius*2, $inRadius*2, 180, 270, $fillCol, IMG_ARC_PIE);
            imagefilledarc($temp, $inx2 - $inRadius, $iny1 + $inRadius, $inRadius*2, $inRadius*2, 270, 360, $fillCol, IMG_ARC_PIE);
            imagefilledarc($temp, $inx1 + $inRadius, $iny2 - $inRadius, $inRadius*2, $inRadius*2, 90, 180, $fillCol, IMG_ARC_PIE);
            imagefilledarc($temp, $inx2 - $inRadius, $iny2 - $inRadius, $inRadius*2, $inRadius*2, 0, 90, $fillCol, IMG_ARC_PIE);
        }
    } else {
        // Рамки нет, просто сплошная фигура
        if ((($bg_color >> 24) & 0x7F) < 127) { // Если не прозрачная
            imagefilledrectangle($temp, $tx1, $ty1 + $sRadius, $tx2, $ty2 - $sRadius, $tBgColor);
            imagefilledrectangle($temp, $tx1 + $sRadius, $ty1, $tx2 - $sRadius, $ty2, $tBgColor);
            imagefilledarc($temp, $tx1 + $sRadius, $ty1 + $sRadius, $sRadius*2, $sRadius*2, 180, 270, $tBgColor, IMG_ARC_PIE);
            imagefilledarc($temp, $tx2 - $sRadius, $ty1 + $sRadius, $sRadius*2, $sRadius*2, 270, 360, $tBgColor, IMG_ARC_PIE);
            imagefilledarc($temp, $tx1 + $sRadius, $ty2 - $sRadius, $sRadius*2, $sRadius*2, 90, 180, $tBgColor, IMG_ARC_PIE);
            imagefilledarc($temp, $tx2 - $sRadius, $ty2 - $sRadius, $sRadius*2, $sRadius*2, 0, 90, $tBgColor, IMG_ARC_PIE);
        }
    }

    // Ресемплинг с включенным смешиванием (накладываем на основной макет)
    imagealphablending($im, true);
    imagecopyresampled($im, $temp, $minX - 1, $minY - 1, 0, 0, $w, $h, $sw, $sh);
    
    imagedestroy($temp);
}

function drawMainSchedule($image, $settings, $replacements, &$svg = null) {
    $blocks = [];
    
    // Определяем, одиночный макет или двойной
    $isSingle = empty(trim($replacements['{weekDays5}'] ?? ''));

    // 1. ПЕРВАЯ ПАРА
    $valDays1 = trim($replacements['{weekDays1}'] ?? '');
    if (!empty($valDays1)) {
        $blocks[] = ['text' => $valDays1, 'type' => 'days', 'gap' => 55]; 
    }
    
    $valTime1 = trim($replacements['{time1}'] ?? '');
    if (!empty($valTime1)) {
        // Если макет одиночный, делаем большой отступ до адреса
        // Если двойной — стандартный отступ до следующей пары дней
        $gapAfterTime1 = $isSingle ? 150 : 60; 
        $blocks[] = ['text' => $valTime1, 'type' => 'time', 'gap' => $gapAfterTime1];
    }

    // 2. ВТОРАЯ ПАРА (только если не single)
    if (!$isSingle) {
        $valDays2 = trim($replacements['{weekDays5}'] ?? '');
        $blocks[] = ['text' => $valDays2, 'type' => 'days', 'gap' => 55]; 
        
        $valTime2 = trim($replacements['{time5}'] ?? '');
        if (!empty($valTime2)) {
            $blocks[] = ['text' => $valTime2, 'type' => 'time', 'gap' => 80]; 
        }
    }

    // 3. ГОРОД И АДРЕС
    $valCity = trim($replacements['{locCity1}'] ?? '');
    if (!empty($valCity)) {
        $blocks[] = ['text' => $valCity, 'type' => 'city_bottom', 'gap' => 10];
    }
    
    $valAddr = trim($replacements['{address1}'] ?? '');
    if (!empty($valAddr)) {
        $blocks[] = ['text' => $valAddr, 'type' => 'address_bottom', 'gap' => 0];
    }

    // --- ЛОГИКА ВЫБОРА СТАРТОВОЙ ТОЧКИ ---
    if ($isSingle) {
        // Точка старта для одной записи (поднимаем повыше)
        $currentY = $settings['startY_single'] ?? 850; 
    } else {
        // Точка старта для двух записей (чуть ниже, чтобы не упереться в заголовок)
        $currentY = $settings['startY_double'] ?? 820; 
    }

    $xCenter = 540; 

    foreach ($blocks as $block) {
        $type = $block['type'];
        if (!isset($settings['fonts'][$type])) continue;

        $f = $settings['fonts'][$type];
        $fontPath = FONTS_DIR . $f['file'];
        $colorArr = $settings['colors'][$type];
        $color = imagecolorallocate($image, $colorArr[0], $colorArr[1], $colorArr[2]);

        $bbox = imagettfbbox($f['size'], 0, $fontPath, $block['text']);
        $th = abs($bbox[5] - $bbox[1]);
        $tw = abs($bbox[4] - $bbox[0]);
        
        $tx = $xCenter - ($tw / 2);
        $ty = $currentY + $th;

        imagettftext($image, $f['size'], 0, $tx, $ty, $color, $fontPath, $block['text']);
        addSvgText($svg, $tx, $ty, $block['text'], $fontPath, $f['size'], $colorArr);
        $currentY += $th + $block['gap'];
    }
}

function drawLocationCard($image, $settings, $replacements, &$svg = null) {
    // 1. Подготовка текста
    $address = str_replace(array_keys($replacements), array_values($replacements), $settings['address_template'] ?? '');
    $cityText = str_replace(array_keys($replacements), array_values($replacements), $settings['city_template'] ?? '');
    
    $days1 = str_replace(array_keys($replacements), array_values($replacements), $settings['days1_template'] ?? '');
    $time1 = str_replace(array_keys($replacements), array_values($replacements), $settings['time1_template'] ?? '');
    $days2 = str_replace(array_keys($replacements), array_values($replacements), $settings['days2_template'] ?? '');
    $time2 = str_replace(array_keys($replacements), array_values($replacements), $settings['time2_template'] ?? '');

    if (empty(trim($address)) && empty(trim($cityText)) && empty(trim($time1))) return false;

    // 2. Базовые настройки
    $startY  = $settings['startY'] ?? 100;
    $xCenter = $settings['xCenter'] ?? 540;
    $width   = $settings['width'] ?? 800;
    $radius  = $settings['radius'] ?? 30;

    $baseGap = $settings['gap'] ?? 15;                       // Общий отступ
    $gapAddrToDays = $settings['gap_addr_to_days'] ?? 20;    // От адреса до дней (увеличил до 25)
    $gapDaysToTime = $settings['gap_days_to_time'] ?? 30;     // От дней до времени (сделал поближе)
    $gapTimeToNext = $settings['gap_time_to_next'] ?? 30;    // Между разными блоками (разрыв больше)

    // 3. Отрисовка города
    if (!empty(trim($cityText)) && isset($settings['fonts']['city'])) {
        $fontCity = FONTS_DIR . $settings['fonts']['city']['file'];
        $sizeCity = $settings['fonts']['city']['size'];
        $colorCityArr = $settings['colors']['city'];
        $colorCity = imagecolorallocatealpha($image, $colorCityArr[0], $colorCityArr[1], $colorCityArr[2], $colorCityArr[3] ?? 0);

        $bboxCity = imagettfbbox($sizeCity, 0, $fontCity, $cityText);
        $tx = $xCenter - (abs($bboxCity[4] - $bboxCity[0]) / 2);
        $ty = $startY + abs($bboxCity[5] - $bboxCity[1]); 

        imagettftext($image, $sizeCity, 0, $tx, $ty, $colorCity, $fontCity, $cityText);
        addSvgText($svg, $tx, $ty, $cityText, $fontCity, $sizeCity, $colorCityArr);

        $startY = $ty + ($settings['cityGap'] ?? ($baseGap + 10)); 
    }

    $blocks = [];

    // Блок АДРЕСА
    if (!empty(trim($address)) && isset($settings['fonts']['address'])) {
        $fontAddr = FONTS_DIR . $settings['fonts']['address']['file'];
        $sizeAddr = calculateFitFontSize($address, $fontAddr, $settings['fonts']['address']['size'], $width - 40);
        $colorAddrArr = $settings['colors']['address'];
        $colorAddr = imagecolorallocatealpha($image, $colorAddrArr[0], $colorAddrArr[1], $colorAddrArr[2], $colorAddrArr[3] ?? 0);
        
        $bboxAddr = imagettfbbox($sizeAddr, 0, $fontAddr, $address);
        $blocks[] = [
            'text' => $address, 
            'h' => abs($bboxAddr[5] - $bboxAddr[1]), 
            'font' => $fontAddr, 'size' => $sizeAddr, 'color' => $colorAddr, 'colorArr' => $colorAddrArr,
            'next_gap' => $gapAddrToDays // Сдвиг ПОСЛЕ адреса
        ];
    }

    // Блоки ДНЕЙ и ВРЕМЕНИ
    foreach ([[trim($days1), trim($time1)], [trim($days2), trim($time2)]] as $dt) {
        if (!empty($dt[0]) && !empty($dt[1])) {
            $fontDays = FONTS_DIR . $settings['fonts']['days']['file'];
            $fontTime = FONTS_DIR . $settings['fonts']['time']['file'];
            $colorDaysArr = $settings['colors']['days'];
            $colorTimeArr = $settings['colors']['time'];
            $colorDays = imagecolorallocatealpha($image, $colorDaysArr[0], $colorDaysArr[1], $colorDaysArr[2], $colorDaysArr[3] ?? 0);
            $colorTime = imagecolorallocatealpha($image, $colorTimeArr[0], $colorTimeArr[1], $colorTimeArr[2], $colorTimeArr[3] ?? 0);
            
            // Дни
            $b1 = imagettfbbox($settings['fonts']['days']['size'], 0, $fontDays, $dt[0]);
            $blocks[] = [
                'text' => $dt[0], 'h' => abs($b1[5] - $b1[1]), 
                'font' => $fontDays, 'size' => $settings['fonts']['days']['size'], 'color' => $colorDays, 'colorArr' => $colorDaysArr,
                'next_gap' => $gapDaysToTime // Сдвиг ПОСЛЕ дней (к времени)
            ];
            
            // Время
            $b2 = imagettfbbox($settings['fonts']['time']['size'], 0, $fontTime, $dt[1]);
            $blocks[] = [
                'text' => $dt[1], 'h' => abs($b2[5] - $b2[1]), 
                'font' => $fontTime, 'size' => $settings['fonts']['time']['size'], 'color' => $colorTime, 'colorArr' => $colorTimeArr,
                'next_gap' => $gapTimeToNext // Сдвиг ПОСЛЕ времени (к следующему адресу)
            ];
        }
    }

    // 5. Отрисовка
    if (count($blocks) > 0) {
        $bgArr = $settings['colors']['background'] ?? [255, 255, 255, 0];
        $bgColor = imagecolorallocatealpha($image, $bgArr[0], $bgArr[1], $bgArr[2], $bgArr[3]);
        $borderArr = $settings['colors']['border'] ?? null;
        $borderColor = $borderArr ? imagecolorallocatealpha($image, $borderArr[0], $borderArr[1], $borderArr[2], $borderArr[3]) : null;

        $paddingY = $settings['paddingY'] ?? 40;
        
        // Считаем высоту плашки
        $totalHeight = 0;
        foreach ($blocks as $idx => $block) {
            $totalHeight += $block['h'];
            if ($idx < count($blocks) - 1) {
                $totalHeight += $block['next_gap'];
            }
        }
        $rectHeight = $totalHeight + ($paddingY * 2);
        
        $rectX1 = $xCenter - ($width / 2); $rectY1 = $startY;
        $rectX2 = $xCenter + ($width / 2); $rectY2 = $startY + $rectHeight;

        imagefillroundedrect($image, $rectX1, $rectY1, $rectX2, $rectY2, $radius, $bgColor, $borderColor, 3);
        addSvgRect($svg, $rectX1, $rectY1, $rectX2, $rectY2, $radius, $bgArr, $borderArr, 3);

        $currentY = $rectY1 + $paddingY;
        foreach ($blocks as $idx => $block) {
            $bbox = imagettfbbox($block['size'], 0, $block['font'], $block['text']);
            $th = abs($bbox[5] - $bbox[1]);
            $tx = $xCenter - (abs($bbox[4] - $bbox[0]) / 2);
            $ty = $currentY + $th;

            imagettftext($image, $block['size'], 0, $tx, $ty, $block['color'], $block['font'], $block['text']);
            addSvgText($svg, $tx, $ty, $block['text'], $block['font'], $block['size'], $block['colorArr']);
            
            $currentY += $th + ($block['next_gap'] ?? $baseGap);
        }
        return $rectY2 + ($settings['marginBottom'] ?? 30);
    }
    return $startY + ($settings['marginBottom'] ?? 30);
}

function drawTextOnImage($image, $textTemplate, $settings, $position, $replacements, &$svg = null) {
    if (!isset($settings['font']) || !isset($settings['fontSize'])) return;

    $text = str_replace(array_keys($replacements), array_values($replacements), $textTemplate);
    if (empty(trim($text))) return;
    
    $fontPath = FONTS_DIR . $settings['font'];
    if (!is_file($fontPath)) return;

    $fontSize = $settings['fontSize'];
    $width = $settings['width'];
    $lineHeight = $settings['lineHeight'] ?? 1.2;
    $angle = $settings['angle'] ?? 0;
    
    $colorArr = $settings['color'];
    $alpha = isset($colorArr[3]) ? $colorArr[3] : 0;
    $color = imagecolorallocatealpha($image, $colorArr[0], $colorArr[1], $colorArr[2], $alpha);
    
    $maxHeight = $settings['maxHeight'] ?? 1000;
    $align = isset($settings['align']) && in_array($settings['align'], ['left', 'center', 'right']) ? $settings['align'] : 'center';

    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $explicitLines = explode("\n", $text);

    $getWrappedLines = function($size) use ($explicitLines, $angle, $fontPath, $width) {
        $wrapped = [];
        foreach ($explicitLines as $eLine) {
            if ($eLine === '') {
                $wrapped[] = ''; 
                continue;
            }
            $words = explode(' ', $eLine);
            $currentLine = '';
            foreach ($words as $word) {
                $testLine = $currentLine ? $currentLine . ' ' . $word : $word;
                $bbox = imagettfbbox($size, $angle, $fontPath, $testLine);
                if ((abs($bbox[2] - $bbox[0])) > $width && $currentLine !== '') { 
                    $wrapped[] = $currentLine; 
                    $currentLine = $word; 
                } else { 
                    $currentLine = $testLine; 
                }
            }
            if ($currentLine !== '') {
                $wrapped[] = $currentLine;
            }
        }
        return $wrapped;
    };

    $lines = $getWrappedLines($fontSize);

    while (count($lines) * $fontSize * $lineHeight > $maxHeight && $fontSize > 10) {
        $fontSize -= 2;
        $lines = $getWrappedLines($fontSize);
    }

    $y = $position[1];
    foreach ($lines as $line) {
        if ($line === '') {
            $y += $fontSize * $lineHeight;
            continue;
        }

        $bbox = imagettfbbox($fontSize, $angle, $fontPath, $line);
        $textWidth = abs($bbox[2] - $bbox[0]);
        
        if ($align === 'left') $x = $position[0];
        elseif ($align === 'right') $x = $position[0] + $width - $textWidth;
        else $x = $position[0] + ($width - $textWidth) / 2;
        
        if (isset($settings['shadow']) && !empty($settings['shadow']['enabled'])) {
            drawTextShadow($image, $fontSize, $angle, $x, $y, $settings['shadow'], $fontPath, $line);
        }
        
        imagettftext($image, $fontSize, $angle, $x, $y, $color, $fontPath, $line);
        addSvgText($svg, $x, $y, $line, $fontPath, $fontSize, $colorArr, $angle);
        
        $y += $fontSize * $lineHeight;
    }
}

function drawObjectOnImage($image, $settings, &$svg = null) {
    $type = $settings['type'] ?? 'rectangle';
    $width = $settings['width'] ?? 100;
    $height = $settings['height'] ?? 100;
    $pos = $settings['position'] ?? [0, 0];
    $angle = $settings['angle'] ?? 0;
    $colorArr = $settings['color'];
    $alpha = isset($colorArr[3]) ? $colorArr[3] : 0;

    if ($svg !== null) {
        $transform = "";
        if ($angle != 0) {
            $cx = $pos[0] + $width / 2; $cy = $pos[1] + $height / 2;
            $svgAngle = -$angle; 
            $transform = " transform=\"rotate($svgAngle, $cx, $cy)\"";
        }
        $fillAttrs = getSvgColorAttrs($colorArr);
        if ($type === 'ellipse' || $type === 'circle') {
            $cx = $pos[0] + $width / 2; $cy = $pos[1] + $height / 2;
            $rx = $width / 2; $ry = $height / 2;
            $svg[] = sprintf('<ellipse cx="%.2f" cy="%.2f" rx="%.2f" ry="%.2f" %s%s />', $cx, $cy, $rx, $ry, $fillAttrs, $transform);
        } else {
            $svg[] = sprintf('<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" %s%s />', $pos[0], $pos[1], $width, $height, $fillAttrs, $transform);
        }
    }

    $tempImg = imagecreatetruecolor($width, $height);
    imagealphablending($tempImg, false); imagesavealpha($tempImg, true);
    $transparentColor = imagecolorallocatealpha($tempImg, $colorArr[0], $colorArr[1], $colorArr[2], 127);
    imagefill($tempImg, 0, 0, $transparentColor);
    $objColor = imagecolorallocatealpha($tempImg, $colorArr[0], $colorArr[1], $colorArr[2], $alpha);

    if ($type === 'ellipse' || $type === 'circle') imagefilledellipse($tempImg, $width / 2, $height / 2, $width, $height, $objColor);
    else imagefilledrectangle($tempImg, 0, 0, $width, $height, $objColor);
    imagealphablending($image, true);

    if ($angle != 0) {
        $rotatedImg = imagerotate($tempImg, $angle, $transparentColor);
        imagealphablending($rotatedImg, false); imagesavealpha($rotatedImg, true);
        $newWidth = imagesx($rotatedImg); $newHeight = imagesy($rotatedImg);
        $dx = ($newWidth - $width) / 2; $dy = ($newHeight - $height) / 2;
        imagecopy($image, $rotatedImg, $pos[0] - $dx, $pos[1] - $dy, 0, 0, $newWidth, $newHeight);
        imagedestroy($rotatedImg);
    } else {
        imagecopy($image, $tempImg, $pos[0], $pos[1], 0, 0, $width, $height);
    }
    imagedestroy($tempImg);
}

function drawCitySummary($image, $settings, $replacements, $startY, &$svg = null) {
    $canvasHeight = 1920; $canvasWidth = 1080; $xCenter = $canvasWidth / 2;
    $fontCity = FONTS_DIR . 'Brog-Semibold.otf'; $fontPath = FONTS_DIR . 'Polyplast-Regular.ttf';
    $conf = $settings['city_summary_settings'] ?? [];
    
    $fontSizeCity = $conf['fontSizeCity'] ?? 67; $fontSizeAddr = $conf['fontSizeAddr'] ?? 50; 
    $baseFontSizePhone = $conf['fontSizePhone'] ?? 50; $fontSizeSoon = $conf['fontSizeSoon'] ?? 50; 
    $dotsSize = $conf['dotsSize'] ?? 25; 
    
    // Устанавливаем твой точный отступ здесь
    $gapCityToContent = 88; 
    $lineGap = $conf['gap'] ?? 70; 

    $colorArr = $settings['color'] ?? [255, 255, 255];
    $fullColor = imagecolorallocate($image, $colorArr[0], $colorArr[1], $colorArr[2]);

    $validIndices = [];
    for ($i = 1; $i <= 4; $i++) { if (!empty(trim($replacements["{sumAddress$i}"] ?? ''))) $validIndices[] = $i; }
    $validIndices = array_slice($validIndices, 0, $settings['max_locations_limit'] ?? 4);
    if (empty($validIndices)) return $startY;

    // --- 1. РАСЧЕТ ВЫСОТЫ ---
    $totalHeight = 0;
    $cityText = $replacements['{city}'] ?? '';
    if (!empty($cityText)) {
        $bboxC = imagettfbbox($fontSizeCity, 0, $fontCity, $cityText);
        // Высота города + твой отступ 88px
        $totalHeight += abs($bboxC[5] - $bboxC[1]) + $gapCityToContent; 
    }

    foreach ($validIndices as $idx => $i) {
        if (($replacements["{soon$i}"] ?? 'off') === 'on') $totalHeight += 85; 
        $totalHeight += 61; 
        if (!empty(trim($replacements["{sumPhone$i}"] ?? ''))) $totalHeight += 145; 
        if ($idx < count($validIndices) - 1) $totalHeight += $lineGap + 30 + 110; 
    }

    // --- 2. ЦЕНТРИРОВАНИЕ ---
    $visualCenterY = $conf['visualCenterY'] ?? 960;
    $currentY = (int)($visualCenterY - ($totalHeight / 2));

    // --- 3. ОТРИСОВКА ---
    if (!empty($cityText)) {
        $bboxC = imagettfbbox($fontSizeCity, 0, $fontCity, $cityText);
        $hC = abs($bboxC[5] - $bboxC[1]);
        $tx = $xCenter - (abs($bboxC[2] - $bboxC[0]) / 2);
        // Рисуем город
        imagettftext($image, $fontSizeCity, 0, $tx, $currentY + $hC, $fullColor, $fontCity, $cityText);
        addSvgText($svg, $tx, $currentY + $hC, $cityText, $fontCity, $fontSizeCity, $colorArr);
        // Смещаем Y ровно на высоту города + 88 пикселей
        $currentY += $hC + $gapCityToContent; 
    }

    foreach ($validIndices as $idx => $i) {
        $address = trim($replacements["{sumAddress$i}"] ?? '');
        $phone = trim($replacements["{sumPhone$i}"] ?? '');
        $isSoon = ($replacements["{soon$i}"] ?? 'off') === 'on';
        $mainColorArr = $isSoon ? [$colorArr[0], $colorArr[1], $colorArr[2], 64] : $colorArr;
        $mainColor = imagecolorallocatealpha($image, $mainColorArr[0], $mainColorArr[1], $mainColorArr[2], $mainColorArr[3] ?? 0);

        if ($isSoon) {
            $soonText = "Скоро открытие";
            $bboxS = imagettfbbox($fontSizeSoon, 0, $fontPath, $soonText);
            imagettftext($image, $fontSizeSoon, 0, $xCenter - (abs($bboxS[2]-$bboxS[0])/2), $currentY + 50, $fullColor, $fontPath, $soonText);
            addSvgText($svg, $xCenter - (abs($bboxS[2]-$bboxS[0])/2), $currentY + 50, $soonText, $fontPath, $fontSizeSoon, $colorArr);
            $currentY += 85; 
        }

        $currentFontSizeAddr = calculateFitFontSize($address, $fontPath, $fontSizeAddr, 900);
        $bboxA = imagettfbbox($currentFontSizeAddr, 0, $fontPath, $address);
        $hA = abs($bboxA[5] - $bboxA[1]);
        $txAddr = $xCenter - (abs($bboxA[2]-$bboxA[0])/2);
        imagettftext($image, $currentFontSizeAddr, 0, $txAddr, $currentY + $hA, $mainColor, $fontPath, $address);
        addSvgText($svg, $txAddr, $currentY + $hA, $address, $fontPath, $currentFontSizeAddr, $mainColorArr);
        $currentY += 61; 
        
        if (!empty($phone)) {
            $rectW = 534; $rectH = 100;
            $rectTopY = $currentY + 45;
            imagefillroundedrect($image, $xCenter - ($rectW/2), $rectTopY, $xCenter + ($rectW/2), $rectTopY + $rectH, 45, imagecolorallocatealpha($image, 0, 0, 0, 127), $mainColor, 4);
            addSvgRect($svg, $xCenter - ($rectW/2), $rectTopY, $xCenter + ($rectW/2), $rectTopY + $rectH, 45, null, $mainColorArr, 4);

            $currentFontSizePhone = $baseFontSizePhone;
            do {
                $bboxP = imagettfbbox($currentFontSizePhone, 0, $fontPath, $phone);
                $pW = abs($bboxP[2] - $bboxP[0]);
                if ($pW > ($rectW - 60) && $currentFontSizePhone > 20) $currentFontSizePhone -= 2; else break;
            } while ($currentFontSizePhone > 20);

            $bboxH = imagettfbbox($currentFontSizePhone, 0, $fontPath, "8");
            $phoneX = $xCenter - ($pW/2);
            $phoneY = $rectTopY + ($rectH/2) + (abs($bboxH[5])/2);
            imagettftext($image, $currentFontSizePhone, 0, $phoneX, $phoneY, $mainColor, $fontPath, $phone);
            addSvgText($svg, $phoneX, $phoneY, $phone, $fontPath, $currentFontSizePhone, $mainColorArr);
            $currentY = $rectTopY + $rectH; 
        }

        if ($idx < count($validIndices) - 1) {
            $currentY += $lineGap; 
            $step = ($canvasWidth - (183 * 2)) / 25; 
            for ($d = 0; $d < 26; $d++) {
                $dotX = 183 + ($d * $step) - 5;
                imagettftext($image, $dotsSize, 0, $dotX, $currentY + 30, $mainColor, $fontCity, ".");
                addSvgText($svg, $dotX, $currentY + 30, ".", $fontCity, $dotsSize, $mainColorArr);
            }
            $currentY += 110; 
        }
    }
    return $currentY;
}

function drawCitySummarySoon($image, $settings, $replacements, $startY, &$svg = null) {
    $canvasHeight = 1920; $canvasWidth = 1080; $xCenter = $canvasWidth / 2;
    $fontCity = FONTS_DIR . 'Brog-Semibold.otf'; $fontPath = FONTS_DIR . 'Polyplast-Regular.ttf';
    
    $conf = $settings['city_summary_soon_settings'] ?? [];
    $colorArr = $settings['color'] ?? [255, 255, 255];
    $fullColor = imagecolorallocate($image, $colorArr[0], $colorArr[1], $colorArr[2]);

    $validIndices = [];
    for ($i = 1; $i <= 4; $i++) { if (!empty(trim($replacements["{sumAddress$i}"] ?? ''))) $validIndices[] = $i; }
    $validIndices = array_slice($validIndices, 0, $settings['max_locations_limit'] ?? 1);
    if (empty($validIndices)) return $startY;

    $isGlobalSoon = ($replacements["{soon" . $validIndices[0] . "}"] ?? 'off') === 'on';
    $shrink = $isGlobalSoon ? 5 : 0; 
    $rectW = $isGlobalSoon ? 414 : 534; $rectH = $isGlobalSoon ? 87 : 100;
    $currentY = $conf['startY'] ?? 841;

    if ($isGlobalSoon) {
        $soonText = "Скоро открытие!";
        $fontSizeSoon = ($conf['fontSizeSoonHeader'] ?? 65); 
        $bboxS = imagettfbbox($fontSizeSoon, 0, $fontCity, $soonText);
        $tx = $xCenter - (abs($bboxS[2]-$bboxS[0])/2);
        imagettftext($image, $fontSizeSoon, 0, $tx, $currentY, $fullColor, $fontCity, $soonText);
        addSvgText($svg, $tx, $currentY, $soonText, $fontCity, $fontSizeSoon, $colorArr);
        $currentY += 174; 
    }

    $itemColorArr = $isGlobalSoon ? [$colorArr[0], $colorArr[1], $colorArr[2], 64] : $colorArr;
    $itemColor = imagecolorallocatealpha($image, $itemColorArr[0], $itemColorArr[1], $itemColorArr[2], $itemColorArr[3] ?? 0);

    if (!empty($replacements['{city}'] ?? '')) {
        $cityText = $replacements['{city}'];
        $fontSizeCity = ($conf['fontSizeCity'] ?? 80) - $shrink;
        $bboxC = imagettfbbox($fontSizeCity, 0, $fontCity, $cityText);
        $tx = $xCenter - (abs($bboxC[2]-$bboxC[0])/2);
        imagettftext($image, $fontSizeCity, 0, $tx, $currentY, $itemColor, $fontCity, $cityText);
        addSvgText($svg, $tx, $currentY, $cityText, $fontCity, $fontSizeCity, $itemColorArr);
        $currentY += 60; 
    }

    foreach ($validIndices as $idx => $i) {
        $address = trim($replacements["{sumAddress$i}"] ?? '');
        $phone = trim($replacements["{sumPhone$i}"] ?? '');
        
        $dotsSize = $conf['dotsSize'] ?? 25;
        $step = ($canvasWidth - (183 * 2)) / 25; 
        for ($d = 0; $d < 26; $d++) {
            $tx = 183 + ($d * $step) - 5;
            imagettftext($image, $dotsSize, 0, $tx, $currentY, $itemColor, $fontCity, ".");
            addSvgText($svg, $tx, $currentY, ".", $fontCity, $dotsSize, $itemColorArr);
        }
        $currentY += 100;

        $fontSizeAddr = calculateFitFontSize($address, $fontPath, ($conf['fontSizeAddr'] ?? 55) - $shrink, 900);
        $bboxA = imagettfbbox($fontSizeAddr, 0, $fontPath, $address);
        $tx = $xCenter - (abs($bboxA[2]-$bboxA[0])/2);
        imagettftext($image, $fontSizeAddr, 0, $tx, $currentY, $itemColor, $fontPath, $address);
        addSvgText($svg, $tx, $currentY, $address, $fontPath, $fontSizeAddr, $itemColorArr);
        
        if (!empty($phone)) {
            $currentY += 45; 
            $rectX1 = $xCenter - ($rectW / 2); $rectY1 = $currentY;
            $rectX2 = $rectX1 + $rectW; $rectY2 = $rectY1 + $rectH;
            $borderRadius = $isGlobalSoon ? 38 : 45; 

            // ИДЕАЛЬНО ГЛАДКАЯ РАМКА СУПЕРСЕМПЛИНГОМ
            $trans = imagecolorallocatealpha($image, 0, 0, 0, 127);
            imagefillroundedrect($image, $rectX1, $rectY1, $rectX2, $rectY2, $borderRadius, $trans, $itemColor, 2);
            addSvgRect($svg, $rectX1, $rectY1, $rectX2, $rectY2, $borderRadius, null, $itemColorArr, 2);

            $fontSizePhone = ($conf['fontSizePhone'] ?? 45) - ($isGlobalSoon ? ($shrink + 3) : 0);
            $bboxP = imagettfbbox($fontSizePhone, 0, $fontPath, $phone);
            $textW = abs($bboxP[2] - $bboxP[0]); $textH = abs($bboxP[5] - $bboxP[1]);
            $textY = $rectY1 + ($rectH / 2) + ($textH / 2) - 2; 
            
            imagettftext($image, $fontSizePhone, 0, $xCenter - ($textW / 2), $textY, $itemColor, $fontPath, $phone);
            addSvgText($svg, $xCenter - ($textW / 2), $textY, $phone, $fontPath, $fontSizePhone, $itemColorArr);

            $currentY += $rectH + 40;
        }
    }
    return $currentY;
}

function drawCityListWithHeader($image, $settings, $replacements, $startY, &$svg = null) {
    $canvasWidth = 1080; 
    $canvasHeight = imagesy($image); 
    $xCenter = $canvasWidth / 2;
    
    $fontCity = FONTS_DIR . 'Brog-Semibold.otf'; 
    $fontPath = FONTS_DIR . 'Polyplast-Regular.ttf';
    
    $colorArr = $settings['color'] ?? [255, 255, 255];
    $fullColor = imagecolorallocate($image, $colorArr[0], $colorArr[1], $colorArr[2]);
    $alphaColor = imagecolorallocatealpha($image, $colorArr[0], $colorArr[1], $colorArr[2], 64);

    imageantialias($image, true);

    // --- 1. ТОЧНЫЕ КОНСТАНТЫ ОТСТУПОВ ---
    $hMainHeader = 140; // Высота главного заголовка с зазором
    $hCityName   = 60;  // Высота названия города
    $hDots       = 40;  // Зазор под точки
    $hAddrMargin = 30;  // Зазор под адресом
    $hPhoneRect  = 85;  // Высота рамки телефона
    $hBetweenCities = 80; // Расстояние между блоками разных городов

    // --- 2. РАСЧЕТ ОБЩЕЙ ВЫСОТЫ ---
    $totalHeight = 0;
    $mainHeader = $replacements['{main_header}'] ?? '';
    if (!empty($mainHeader)) $totalHeight += $hMainHeader;

    $lastCityCalc = "";
    $limit = $settings['max_locations_limit'] ?? 4;
    $items = [];

    for ($i = 1; $i <= $limit; $i++) {
        $addr = trim($replacements["{h_sumAddress$i}"] ?? '');
        if (empty($addr)) continue;
        
        $city = trim($replacements["{h_sumCity$i}"] ?? '');
        $phone = trim($replacements["{h_sumPhone$i}"] ?? '');
        $items[] = ['city' => $city, 'address' => $addr, 'phone' => $phone];

        $currentCityName = (!empty($city)) ? $city : $lastCityCalc;

        if ($currentCityName !== $lastCityCalc && !empty($currentCityName)) {
            if ($lastCityCalc !== "") $totalHeight += $hBetweenCities;
            $totalHeight += $hCityName + ($hDots * 2); 
            $lastCityCalc = $currentCityName;
        } else if ($i > 1) {
            $totalHeight += $hDots * 2; 
        }

        $fontSizeAddr = calculateFitFontSize($addr, $fontPath, 45, 950);
        $bboxA = imagettfbbox($fontSizeAddr, 0, $fontPath, $addr);
        $totalHeight += abs($bboxA[5] - $bboxA[1]) + $hAddrMargin;

        if (!empty($phone)) $totalHeight += $hPhoneRect;
    }

    // --- 3. СТАРТОВАЯ ТОЧКА ---
    $visualCenterY = $settings['city_list_header_settings']['visualCenterY'] ?? 960;
    $currentY = (int)($visualCenterY - ($totalHeight / 2));

    // --- 4. ОТРИСОВКА ---
    if (!empty($mainHeader)) {
        $fontSizeMain = 60;
        $bboxM = imagettfbbox($fontSizeMain, 0, $fontCity, $mainHeader);
        $txH = $xCenter - (abs($bboxM[2]-$bboxM[0])/2);
        imagettftext($image, $fontSizeMain, 0, $txH, $currentY + 50, $fullColor, $fontCity, $mainHeader);
        addSvgText($svg, $txH, $currentY + 50, $mainHeader, $fontCity, $fontSizeMain, $colorArr);
        $currentY += $hMainHeader;
    }

    $lastCity = "";
    foreach ($items as $idx => $item) {
        $isNewCity = (!empty($item['city']) && $item['city'] !== $lastCity);
        
        if ($isNewCity) {
            if ($lastCity !== "") $currentY += $hBetweenCities;
            
            // Название города
            $fontSizeCity = 50;
            $bboxC = imagettfbbox($fontSizeCity, 0, $fontCity, $item['city']);
            $txC = $xCenter - (abs($bboxC[2]-$bboxC[0])/2);
            imagettftext($image, $fontSizeCity, 0, $txC, $currentY + 45, $fullColor, $fontCity, $item['city']);
            addSvgText($svg, $txC, $currentY + 45, $item['city'], $fontCity, $fontSizeCity, $colorArr);
            $currentY += $hCityName + $hDots;
            
            // Точки
            $step = ($canvasWidth - (183 * 2)) / 25;
            for ($d = 0; $d < 26; $d++) {
                $dotX = 183 + ($d * $step) - 5;
                imagettftext($image, 25, 0, $dotX, $currentY, $alphaColor, $fontCity, ".");
                addSvgText($svg, $dotX, $currentY, ".", $fontCity, 25, [$colorArr[0], $colorArr[1], $colorArr[2], 64]);
            }
            $currentY += $hDots;
            $lastCity = $item['city'];
        } else if ($idx > 0) {
            // Точки между адресами одного города
            $currentY += $hDots;
            $step = ($canvasWidth - (183 * 2)) / 25;
            for ($d = 0; $d < 26; $d++) {
                $dotX = 183 + ($d * $step) - 5;
                imagettftext($image, 25, 0, $dotX, $currentY, $alphaColor, $fontCity, ".");
                addSvgText($svg, $dotX, $currentY, ".", $fontCity, 25, [$colorArr[0], $colorArr[1], $colorArr[2], 64]);
            }
            $currentY += $hDots;
        }

        // Адрес
        $fontSizeAddr = calculateFitFontSize($item['address'], $fontPath, 45, 950);
        $bboxA = imagettfbbox($fontSizeAddr, 0, $fontPath, $item['address']);
        $hA = abs($bboxA[5] - $bboxA[1]);
        $txA = $xCenter - (abs($bboxA[2]-$bboxA[0])/2);
        imagettftext($image, $fontSizeAddr, 0, $txA, $currentY + $hA, $fullColor, $fontPath, $item['address']);
        addSvgText($svg, $txA, $currentY + $hA, $item['address'], $fontPath, $fontSizeAddr, $colorArr);
        $currentY += $hA + $hAddrMargin;

        // Телефон
        if (!empty($item['phone'])) {
            $bboxP = imagettfbbox(38, 0, $fontPath, $item['phone']);
            $rectW = abs($bboxP[2] - $bboxP[0]) + 90;
            imagefillroundedrect($image, $xCenter - ($rectW/2), $currentY, $xCenter + ($rectW/2), $currentY + $hPhoneRect, $hPhoneRect/2, imagecolorallocatealpha($image, 0, 0, 0, 127), $fullColor, 2);
            addSvgRect($svg, $xCenter - ($rectW/2), $currentY, $xCenter + ($rectW/2), $currentY + $hPhoneRect, $hPhoneRect/2, null, $colorArr, 2);
            
            $phoneY = $currentY + ($hPhoneRect / 2) + (abs(imagettfbbox(38, 0, $fontPath, "8")[5]) / 2);
            $phoneX = $xCenter - (abs($bboxP[2]-$bboxP[0])/2);
            imagettftext($image, 38, 0, $phoneX, $phoneY, $fullColor, $fontPath, $item['phone']);
            addSvgText($svg, $phoneX, $phoneY, $item['phone'], $fontPath, 38, $colorArr);
            $currentY += $hPhoneRect;
        }
    }
    return $currentY;
}

function drawSpringCityList($image, $settings, $replacements, &$svg = null) {
    $fontCity = FONTS_DIR . ($settings['fontCity'] ?? 'Brog-Semibold.otf');
    $fontAddr = FONTS_DIR . ($settings['fontAddr'] ?? 'Polyplast-Regular.ttf');
    $fontSizeCity = $settings['fontSizeCity'] ?? 55;
    $fontSizeAddr = $settings['fontSizeAddr'] ?? 35; 
    
    $colorArr = $settings['color'] ?? [255, 255, 255];
    $mainColor = imagecolorallocate($image, $colorArr[0], $colorArr[1], $colorArr[2]);
    
    $fixedStartX = $settings['fixedStartX'] ?? 150; 
    $maxWidth = $settings['maxWidth'] ?? 700; 
    $bulletRadius = $settings['bulletRadius'] ?? 6; 
    $bulletDiameter = $bulletRadius * 2;
    $bulletMargin = $settings['bulletMargin'] ?? 47; 
    $rightStartX = $settings['rightStartX'] ?? null;

    // ТВОИ ФИКСИРОВАННЫЕ ОТСТУПЫ
    $gapCityToAddr = 74;          // от города до первого адреса
    $gapBetweenAddr = 47;         // от адреса до другого адреса
    $lineSpacingInside = 37;      // от адреса до переноса
    $bulletVerticalOffset = 8;    // точка выше текста на 8 пикселей
    $gapBetweenBlocks = $settings['gapBetweenBlocks'] ?? 149;

    // Замеры высоты шрифтов
    $bboxC = imagettfbbox($fontSizeCity, 0, $fontCity, "Ay");
    $textHeightCity = abs($bboxC[5] - $bboxC[1]);
    $bboxA = imagettfbbox($fontSizeAddr, 0, $fontAddr, "Ay");
    $textHeightAddr = abs($bboxA[5] - $bboxA[1]);

    imageantialias($image, true);

    // --- 1. РАСЧЕТ ВЫСОТЫ ---
    $leftH = 0; $rightH = 0; $cityData = [];
    for ($i = 1; $i <= 4; $i++) {
        $cityName = trim($replacements["{springCity$i}"] ?? '');
        $rawAddrs = [];
        for ($j = 1; $j <= 10; $j++) {
            $v = trim($replacements["{springAddr{$i}_{$j}}"] ?? '');
            if (!empty($v)) $rawAddrs[] = $v;
        }
        if (empty($cityName) && empty($rawAddrs)) continue;

        $blockH = 0;
        if (!empty($cityName)) $blockH += $textHeightCity + $gapCityToAddr;

        $parsedBlock = [];
        foreach ($rawAddrs as $idx => $addr) {
            $words = explode(' ', $addr);
            $lines = []; $currentL = '';
            foreach ($words as $w) {
                $test = $currentL . ($currentL ? ' ' : '') . $w;
                $bbox = imagettfbbox($fontSizeAddr, 0, $fontAddr, $test);
                if (abs($bbox[2] - $bbox[0]) > $maxWidth && $currentL !== '') {
                    $lines[] = $currentL; $currentL = $w;
                } else { $currentL = $test; }
            }
            $lines[] = $currentL;
            $parsedBlock[] = $lines;
            
            $blockH += $textHeightAddr + (count($lines) - 1) * ($textHeightAddr + $lineSpacingInside);
            if ($idx < count($rawAddrs) - 1) $blockH += $gapBetweenAddr;
        }
        $cityData[$i] = ['name' => $cityName, 'addrs' => $parsedBlock, 'h' => $blockH];
        
        if ($rightStartX !== null) {
            if ($i == 1 || $i == 4) $leftH += $blockH + ($i == 1 ? $gapBetweenBlocks : 0);
            else $rightH += $blockH + ($i == 2 ? $gapBetweenBlocks : 0);
        } else { $leftH += $blockH + $gapBetweenBlocks; }
    }

    // --- 2. ЦЕНТРИРОВАНИЕ (СПУСТИЛИ НИЖЕ) ---
    // Сдвинул центр на 1020, чтобы визуально опустить блок
    $visualCenterY = $settings['visualCenterY'] ?? 1020; 
    $baseY = (int)($visualCenterY - (max($leftH, $rightH) / 2));
    $curYLeft = $baseY; $curYRight = $baseY;

    // --- 3. ОТРИСОВКА ---
    foreach ($cityData as $i => $item) {
        $activeX = $fixedStartX;
        if ($rightStartX !== null) {
            if ($i == 1 || $i == 4) { $cY = &$curYLeft; }
            else { $activeX = $rightStartX; $cY = &$curYRight; }
        } else { $cY = &$curYLeft; }

        if (!empty($item['name'])) {
            imagettftext($image, $fontSizeCity, 0, $activeX, $cY + $textHeightCity, $mainColor, $fontCity, $item['name']);
            addSvgText($svg, $activeX, $cY + $textHeightCity, $item['name'], $fontCity, $fontSizeCity, $colorArr);
            $cY += $textHeightCity + $gapCityToAddr; 
        }

        foreach ($item['addrs'] as $idx => $lines) {
            foreach ($lines as $lIdx => $lineTxt) {
                if ($lIdx === 0) {
                    // Точка выше базовой линии
                    $bulletY = $cY + ($textHeightAddr / 2) - $bulletVerticalOffset; 
                    imagefilledarc($image, $activeX + $bulletRadius, $bulletY, $bulletDiameter, $bulletDiameter, 0, 360, $mainColor, IMG_ARC_PIE);
                    addSvgCircle($svg, $activeX + $bulletRadius, $bulletY, $bulletRadius, $colorArr);
                }

                imagettftext($image, $fontSizeAddr, 0, $activeX + $bulletDiameter + $bulletMargin, $cY + $textHeightAddr, $mainColor, $fontAddr, $lineTxt);
                addSvgText($svg, $activeX + $bulletDiameter + $bulletMargin, $cY + $textHeightAddr, $lineTxt, $fontAddr, $fontSizeAddr, $colorArr);
                
                if (isset($lines[$lIdx + 1])) {
                    $cY += $textHeightAddr + $lineSpacingInside;
                }
            }
            if ($idx < count($item['addrs']) - 1) {
                $cY += $textHeightAddr + $gapBetweenAddr;
            }
        }
        $cY += $gapBetweenBlocks;
    }

    return max($curYLeft, $curYRight);
}

function drawTextWithCapsule($image, $settings, $replacements, &$svg = null) {
    if (!isset($settings['font'])) return;

    $text = $settings['template'];
    foreach ($replacements as $placeholder => $value) $text = str_replace($placeholder, $value, $text);
    if (empty(trim($text))) return;

    $font = FONTS_DIR . $settings['font'];
    if (!is_file($font)) return;

    $fontSize = $settings['fontSize'] ?? 40;
    $yCenter = $settings['position'][1] ?? 1225;
    $imgWidth = imagesx($image);
    
    $textColor = imagecolorallocate($image, 0, 0, 0);
    $white = imagecolorallocate($image, 255, 255, 255);

    $bbox = imagettfbbox($fontSize, 0, $font, $text);
    $textWidth = abs($bbox[2] - $bbox[0]);
    $textHeight = abs($bbox[5] - $bbox[1]);

    $minWidth = 768; $paddingX = 65; 
    $calculatedWidth = $textWidth + ($paddingX * 2);
    $finalWidth = ($calculatedWidth < $minWidth) ? $minWidth : $calculatedWidth;

    $x1 = ($imgWidth / 2) - ($finalWidth / 2);
    $x2 = ($imgWidth / 2) + ($finalWidth / 2);
    $fixedHeight = 99;
    $y1 = $yCenter - ($fixedHeight / 2);
    $y2 = $yCenter + ($fixedHeight / 2);
    $radius = $fixedHeight / 2;

    // ИДЕАЛЬНО ГЛАДКАЯ РАМКА СУПЕРСЕМПЛИНГОМ
    imagefillroundedrect($image, $x1, $y1, $x2, $y2, $radius, $white);
    addSvgRect($svg, $x1, $y1, $x2, $y2, $radius, [255, 255, 255, 0]);

    $textX = ($imgWidth / 2) - ($textWidth / 2);
    $textY = $yCenter + ($textHeight / 2) - 4; 

    imagettftext($image, $fontSize, 0, $textX, $textY, $textColor, $font, $text);
    addSvgText($svg, $textX, $textY, $text, $font, $fontSize, [0, 0, 0, 0]);
}

/**
 * Отрисовка многослойной тени для имитации размытия (blur)
 */
function drawTextShadow($image, $fontSize, $angle, $x, $y, $shadowSettings, $fontPath, $line) {
    if (empty($shadowSettings['enabled'])) return;

    $sConf = $shadowSettings;
    // Настройка прозрачности: 0 - непрозрачно, 127 - прозрачно
    $sAlpha = isset($sConf['alpha']) ? $sConf['alpha'] : 90; 
    $sRGB = isset($sConf['color']) ? $sConf['color'] : [0, 0, 0];
    
    $shadowColor = imagecolorallocatealpha($image, $sRGB[0], $sRGB[1], $sRGB[2], $sAlpha);
    $offset = isset($sConf['offset']) ? $sConf['offset'] : [3, 3];
    $blur = isset($sConf['blur_strength']) ? $sConf['blur_strength'] : 0;

    $sx = $x + $offset[0];
    $sy = $y + $offset[1];

    // 1. Основной слой тени
    imagettftext($image, $fontSize, $angle, $sx, $sy, $shadowColor, $fontPath, $line);

    // 2. Дополнительные слои для имитации размытия
    if ($blur > 0) {
        for ($i = 1; $i <= $blur; $i++) {
            imagettftext($image, $fontSize, $angle, $sx + $i, $sy + $i, $shadowColor, $fontPath, $line);
            imagettftext($image, $fontSize, $angle, $sx - $i, $sy - $i, $shadowColor, $fontPath, $line);
            imagettftext($image, $fontSize, $angle, $sx + $i, $sy - $i, $shadowColor, $fontPath, $line);
            imagettftext($image, $fontSize, $angle, $sx - $i, $sy + $i, $shadowColor, $fontPath, $line);
            
            imagettftext($image, $fontSize, $angle, $sx + $i, $sy, $shadowColor, $fontPath, $line);
            imagettftext($image, $fontSize, $angle, $sx - $i, $sy, $shadowColor, $fontPath, $line);
            imagettftext($image, $fontSize, $angle, $sx, $sy + $i, $shadowColor, $fontPath, $line);
            imagettftext($image, $fontSize, $angle, $sx, $sy - $i, $shadowColor, $fontPath, $line);
        }
    }
}

function drawHorizontalSpringList($image, $settings, $replacements, &$svg = null) {
    // --- 0. ЛИМИТЫ И БАЗОВЫЕ ФЛАГИ ---
    $maxLeft      = $settings['maxCitiesLeft']  ?? 99;
    $maxRight     = $settings['maxCitiesRight'] ?? 99;
    $disableRight = ($settings['disableRightColumn'] ?? false) || ($maxRight === 0);

    // --- 1. ЗАГРУЗКА ШРИФТОВ ---
    $fontCity  = FONTS_DIR . ($settings['fontCity']  ?? 'Brog-Semibold.otf');
    $fontAddr  = FONTS_DIR . ($settings['fontAddr']  ?? 'Polyplast-Regular.ttf');
    $fontPhone = FONTS_DIR . ($settings['fontPhone'] ?? $settings['fontAddr'] ?? 'Polyplast-Regular.ttf');

    // --- 2. НАСТРОЙКИ КООРДИНАТ И РАЗМЕРОВ ---
    $leftX   = $settings['leftStartX']  ?? $settings['fixedStartX'] ?? 150;
    $rightX  = $settings['rightStartX'] ?? $settings['fixedStartX'] ?? 1000;
    
    $centerYLeft  = $settings['centerYLeft']  ?? $settings['startY'] ?? 540;
    $centerYRight = $settings['centerYRight'] ?? $settings['startY'] ?? 540;

    $maxWLeft  = $settings['maxWidthLeft']  ?? $settings['maxWidth']  ?? 700;
    $maxWRight = $settings['maxWidthRight'] ?? $settings['maxWidth']  ?? 700;

    $cityWLeft  = $settings['cityWidthLeft']  ?? $settings['cityWidth'] ?? 150;
    $cityWRight = $settings['cityWidthRight'] ?? $settings['cityWidth'] ?? 150;
    $cityMaxW   = $settings['cityMaxWidth']   ?? 400; 

    $fontSizeCity  = $settings['fontSizeCity']  ?? 55;
    $fontSizeAddr  = $settings['fontSizeAddr']  ?? 35;
    $fontSizePhone = $settings['fontSizePhone'] ?? 28;

    $colorArr  = $settings['color'] ?? [255, 255, 255];
    $mainColor = imagecolorallocate($image, $colorArr[0], $colorArr[1], $colorArr[2]);

    // --- 3. ОТСТУПЫ ---
    $gapCityToAddr      = $settings['gapCityToAddr']      ?? 22;
    $gapBetweenAddr     = $settings['gapBetweenAddr']     ?? 7;
    $gapAddrToPhone     = $settings['gapAddrToPhone']     ?? 4;
    $gapPhoneToNextAddr = $settings['gapPhoneToNextAddr'] ?? 8;
    $lineSpacingInside  = $settings['lineSpacingInside']  ?? 7;
    $gapBetweenBlocks   = $settings['gapBetweenBlocks']   ?? 51;
    
    $bulletRadius   = $settings['bulletRadius']   ?? 6;
    $bulletDiameter = $bulletRadius * 2;
    $bulletMargin   = $settings['bulletMargin']   ?? 27;
    $bulletVerticalOffset = $settings['bulletVerticalOffset'] ?? 9;

    $bboxC = imagettfbbox($fontSizeCity, 0, $fontCity, "Ay");
    $textHeightCity = abs($bboxC[5] - $bboxC[1]);
    $bboxA = imagettfbbox($fontSizeAddr, 0, $fontAddr, "Ay");
    $textHeightAddr = abs($bboxA[5] - $bboxA[1]);
    $bboxP = imagettfbbox($fontSizePhone, 0, $fontPhone, "Ay");
    $textHeightPhone = abs($bboxP[5] - $bboxP[1]);

    // --- 4. СБОР И ПОДГОТОВКА ДАННЫХ ---
    $allCities = [];
    $counts = ['left' => 0, 'right' => 0];

    for ($i = 1; $i <= 10; $i++) {
        $side = $replacements["{h_springSide$i}"] ?? 'left';
        if (($disableRight && $side === 'right') || ($counts[$side] >= ($side === 'left' ? $maxLeft : $maxRight))) continue;

        $cityName = trim($replacements["{h_springCity$i}"] ?? '');
        
        // КЛЮЧЕВОЕ ИСПРАВЛЕНИЕ: принудительно соединяем "г." с названием неразрывным пробелом
        // Это не даст алгоритму разделить их при explode(' ')
        $cityName = preg_replace('/^г\.\s+/iu', 'г.&nbsp;', $cityName);

        $currentMaxW = ($side === 'left') ? $maxWLeft : $maxWRight;

        $rawAddrs = [];
        for ($j = 1; $j <= 20; $j++) {
            $v = trim($replacements["{h_springAddr{$i}_{$j}}"] ?? '');
            if (!empty($v)) {
                $rawAddrs[] = ['addr' => $v, 'phone' => trim($replacements["{h_springPhone{$i}_{$j}}"] ?? '')];
            }
        }
        if (empty($cityName) && empty($rawAddrs)) continue;
        $counts[$side]++;

        $parsedBlock = [];
        foreach ($rawAddrs as $entry) {
            $words = explode(' ', $entry['addr']);
            $lines = []; $currentL = '';
            foreach ($words as $w) {
                $test = $currentL . ($currentL ? ' ' : '') . $w;
                $bbox = imagettfbbox($fontSizeAddr, 0, $fontAddr, $test);
                if (abs($bbox[2] - $bbox[0]) > ($currentMaxW - $bulletMargin - $bulletDiameter) && $currentL !== '') {
                    $lines[] = $currentL; $currentL = $w;
                } else { $currentL = $test; }
            }
            $lines[] = $currentL;
            $parsedBlock[] = ['lines' => $lines, 'phone' => $entry['phone']];
        }
        $allCities[] = ['name' => $cityName, 'side' => $side, 'addrs' => $parsedBlock];
    }

    $columns = ['left' => [], 'right' => []];
    $leftNames = [];
    foreach($allCities as $c) if($c['side']=='left' && !empty($c['name'])) $leftNames[] = mb_strtolower($c['name']);
    foreach ($allCities as $city) {
        $isCont = ($city['side'] === 'right' && !empty($city['name']) && in_array(mb_strtolower($city['name']), $leftNames));
        $columns[$city['side']][] = ['name' => $city['name'], 'addrs' => $city['addrs'], 'continuation' => $isCont];
    }

    // --- 5. РАСЧЕТ ВЫСОТ КОЛОНОК ---
    $colHeights = ['left' => 0, 'right' => 0];
    $processedBlocks = ['left' => [], 'right' => []];

    foreach ($columns as $side => $blocks) {
        $hTotal = 0;
        foreach ($blocks as $bIdx => $block) {
            $cityLines = [];
            if (!empty($block['name']) && !$block['continuation']) {
                // Обработка дефисов, но сохранение связки "г. Название"
                $tempCity = str_replace('-', '- ', $block['name']);
                $words = explode(' ', $tempCity);
                $currentLine = '';
                foreach ($words as $word) {
                    // Возвращаем неразрывную связку и дефис
                    $testWord = str_replace(['- ', '&nbsp;'], ['-', ' '], $word);
                    $testLine = $currentLine ? $currentLine . ' ' . $testWord : $testWord;
                    
                    // Чистим для замера ширины
                    $cleanTestLine = str_replace('&nbsp;', ' ', $testLine);
                    $bbox = imagettfbbox($fontSizeCity, 0, $fontCity, $cleanTestLine);
                    
                    if (abs($bbox[2] - $bbox[0]) > $cityMaxW && $currentLine !== '') {
                        $cityLines[] = trim(str_replace('&nbsp;', ' ', $currentLine));
                        $currentLine = $testWord;
                    } else {
                        $currentLine = $testLine;
                    }
                }
                $cityLines[] = trim(str_replace('&nbsp;', ' ', $currentLine));
                $hTotal += ($textHeightCity * count($cityLines)) + (5 * (count($cityLines) - 1)) + $gapCityToAddr;
            }

            foreach ($block['addrs'] as $aIdx => $entry) {
                $lc = count($entry['lines']);
                $hTotal += ($textHeightAddr * $lc) + ($lineSpacingInside * ($lc - 1));
                if (!empty($entry['phone'])) $hTotal += $gapAddrToPhone + $textHeightPhone + $gapPhoneToNextAddr;
                if ($aIdx < count($block['addrs']) - 1) $hTotal += $gapBetweenAddr;
            }
            if ($bIdx < count($blocks) - 1) $hTotal += $gapBetweenBlocks;
            $processedBlocks[$side][] = array_merge($block, ['cityLines' => $cityLines]);
        }
        $colHeights[$side] = $hTotal;
    }

    // --- 6. ОТРИСОВКА ---
    foreach ($processedBlocks as $sideName => $blocks) {
        if ($disableRight && $sideName === 'right') continue;
        $baseX = ($sideName === 'left') ? $leftX : $rightX;
        $limitCityW = ($sideName === 'left') ? $cityWLeft : $cityWRight;
        $cY = (($sideName === 'left') ? $centerYLeft : $centerYRight) - ($colHeights[$sideName] / 2);

        foreach ($blocks as $block) {
            $maxActualCityW = 0;
            foreach ($block['cityLines'] as $line) {
                $bbox = imagettfbbox($fontSizeCity, 0, $fontCity, $line);
                $lw = abs($bbox[2] - $bbox[0]);
                if ($lw > $maxActualCityW) $maxActualCityW = $lw;
            }
            $offset = ($maxActualCityW > $limitCityW) ? ($maxActualCityW - $limitCityW) : 0;
            $activeX = $baseX - $offset;

            if (!empty($block['cityLines']) && !$block['continuation']) {
                foreach ($block['cityLines'] as $clIdx => $clText) {
                    imagettftext($image, $fontSizeCity, 0, $activeX, $cY + $textHeightCity, $mainColor, $fontCity, $clText);
                    addSvgText($svg, $activeX, $cY + $textHeightCity, $clText, $fontCity, $fontSizeCity, $colorArr);
                    if ($clIdx < count($block['cityLines']) - 1) $cY += $textHeightCity + 5;
                }
                $cY += $textHeightCity + $gapCityToAddr;
            }

            $useBullet = (count($block['addrs']) > 1);
            $textOffsetX = $useBullet ? ($bulletDiameter + $bulletMargin) : 0;

            foreach ($block['addrs'] as $idx => $entry) {
                foreach ($entry['lines'] as $lIdx => $lineTxt) {
                    if ($lIdx === 0 && $useBullet) {
                        $bulletY = $cY + ($textHeightAddr / 2) - $bulletVerticalOffset;
                        imagefilledarc($image, $activeX + $bulletRadius, $bulletY, $bulletDiameter, $bulletDiameter, 0, 360, $mainColor, IMG_ARC_PIE);
                        addSvgCircle($svg, $activeX + $bulletRadius, $bulletY, $bulletRadius, $colorArr);
                    }
                    imagettftext($image, $fontSizeAddr, 0, $activeX + $textOffsetX, $cY + $textHeightAddr, $mainColor, $fontAddr, $lineTxt);
                    addSvgText($svg, $activeX + $textOffsetX, $cY + $textHeightAddr, $lineTxt, $fontAddr, $fontSizeAddr, $colorArr);
                    $cY += $textHeightAddr + (isset($entry['lines'][$lIdx + 1]) ? $lineSpacingInside : 0);
                }
                if (!empty($entry['phone'])) {
                    $cY += $gapAddrToPhone;
                    imagettftext($image, $fontSizePhone, 0, $activeX + $textOffsetX, $cY + $textHeightPhone, $mainColor, $fontPhone, $entry['phone']);
                    addSvgText($svg, $activeX + $textOffsetX, $cY + $textHeightPhone, $entry['phone'], $fontPhone, $fontSizePhone, $colorArr);
                    $cY += $textHeightPhone + $gapPhoneToNextAddr;
                }
                if ($idx < count($block['addrs']) - 1) $cY += $gapBetweenAddr;
            }
            $cY += $gapBetweenBlocks;
        }
    }
}

function drawFilledRoundedRectangle($image, $x1, $y1, $x2, $y2, $radius, $color) {
    // Рисуем центральный крест (два прямоугольника)
    imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
    imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
    
    // Рисуем 4 круга по углам
    imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
}

function drawSimpleOpeningBadge($image, $settings, $replacements, &$svg = null) {
    // Твои координаты по умолчанию для Поста (как на скриншоте)
    $x = $settings['x'] ?? 748;
    $y = $settings['y'] ?? 600;

    $totalW = 422; 
    $hOrange = 57; 
    $hWhite = 102; 
    $totalH = $hOrange + $hWhite;
    // Радиус 15, как ты указал
    $radius = $settings['radius'] ?? 15;

    $colorOrange = imagecolorallocate($image, 243, 181, 65);
    $colorWhite = imagecolorallocate($image, 255, 255, 255);

    // 1. Рисуем подложки (GD - для превью)
    drawFilledRoundedRectangle($image, $x, $y, $x + $totalW, $y + $totalH, $radius, $colorWhite);
    
    // Оранжевая шапка (GD)
    drawFilledRoundedRectangle($image, $x, $y, $x + $totalW, $y + $hOrange, $radius, $colorOrange);
    // "Заплатка", чтобы убрать скругление снизу оранжевой части в GD
    imagefilledrectangle($image, $x, $y + $hOrange - $radius, $x + $totalW, $y + $hOrange, $colorOrange);

    // 2. SVG (Исправлено для ровного низа шапки и ПРАВИЛЬНЫХ ПРАВЫХ УГЛОВ)
    if (is_array($svg)) {
        // Общий белый фон
        $svg[] = sprintf('<rect x="%d" y="%d" width="%d" height="%d" rx="%d" fill="#ffffff" />', $x, $y, $totalW, $totalH, $radius);
        
        // Оранжевая шапка через PATH (скругление только сверху)
        $r = $radius;
        // Мы прорисовываем контур вручную, чтобы низ был прямым
        $d = sprintf(
            "M %d %d V %d Q %d %d %d %d H %d Q %d %d %d %d V %d Z",
            $x, $y + $hOrange,              // Старт: лево-низ шапки (Move to)
            $y + $r,                         // Вертикальная линия вверх до угла (Vertical)
            $x, $y, $x + $r, $y,             // Дуга левого угла (Quadratic Bezier)
            $x + $totalW - $r,               // Горизонтальная линия вправо (Horizontal)
            $x + $totalW, $y, $x + $totalW, $y + $r, // Дуга правого угла (Quad Bezier - ИСПРАВЛЕНО)
            $y + $hOrange                    // Вертикальная линия вниз до низа шапки (Vertical)
        );
        $svg[] = sprintf('<path d="%s" fill="#F3B541" />', $d);
    }

    // 3. ТЕКСТ 1: СКОРО ОТКРЫТИЕ
    $orangeTextSettings = [
        'font' => 'Brog-Semibold.otf',
        'fontSize' => 25,
        'width' => $totalW,
        'align' => 'center',
        'color' => [255, 255, 255, 0],
        'lineHeight' => 1.0,
        'position' => [$x, $y + 42] 
    ];
    drawTextOnImage($image, "СКОРО ОТКРЫТИЕ!", $orangeTextSettings, $orangeTextSettings['position'], $replacements, $svg);

    // 4. ТЕКСТ 2: Адрес
    // Твоя функция принудительно в капс не переводит, поэтому делаем тут
    $city = $replacements['{city}'] ?? '';
    $address = $replacements['{address}'] ?? '';
    $fullAddress = $city . ", " . $address;

    $whiteTextSettings = [
        'font' => 'Polyplast-Regular.ttf',
        'fontSize' => 25,
        'width' => $totalW - 40, 
        'align' => 'center',
        'color' => [0, 0, 0, 0],
        'lineHeight' => 1.2,
        'maxHeight' => $hWhite - 20,
        'position' => [$x + 20, $y + $hOrange + 42] 
    ];
    drawTextOnImage($image, $fullAddress, $whiteTextSettings, $whiteTextSettings['position'], $replacements, $svg);
}

function processSingleCreative($creativeSettings, $replacements, $locCount = 1, $generateSvg = false) {
    $templatePath = TEMPLATES_DIR . $creativeSettings['template'];
    if (!file_exists($templatePath)) return false;

    $image = imagecreatefromwebp($templatePath);
    if (!$image) return false;

    imagealphablending($image, true);
    imagesavealpha($image, true);

    $width = imagesx($image);
    $height = imagesy($image);

    $svg = $generateSvg ? [] : null;
    $dynamicY = null;

    // --- ТРЕТИЙ ВАРИАНТ: ПРЕДВАРИТЕЛЬНЫЙ РАСЧЕТ ГРУППЫ ---
    $totalGroupHeight = 0;
    $locationCards = [];

    // Собираем все заполненные карточки в отдельный массив
    for ($i = 1; $i <= 4; $i++) {
        $key = "location_card_$i";
        if (isset($creativeSettings[$key])) {
            $addr = str_replace(array_keys($replacements), array_values($replacements), $creativeSettings[$key]['address_template'] ?? '');
            if (!empty(trim($addr))) {
                $locationCards[] = $key;
            }
        }
    }

    // Если есть параметр autoCenter и больше 1 карточки — считаем их общую высоту
    if (count($locationCards) > 0 && isset($creativeSettings['location_card_1']['autoCenter'])) {
        foreach ($locationCards as $index => $key) {
            $settings = $creativeSettings[$key];
            
            // Считаем высоту текущей карточки (заголовок + плашка)
            $cardH = 0;
            if (!empty(trim($settings['city_template'] ?? ''))) {
                $cardH += ($settings['fonts']['city']['size'] ?? 45) + ($settings['cityGap'] ?? 80);
            }
            
            $paddingY = $settings['paddingY'] ?? 40;
            $gap = $settings['gap'] ?? 15;
            
            // Считаем блоки внутри (адрес + время)
            $internalBlocks = 1; // Минимум адрес
            if (!empty(trim($settings['time1_template'] ?? ''))) $internalBlocks += 2; // Дни + Время
            if (!empty(trim($settings['time2_template'] ?? ''))) $internalBlocks += 2; // Вторые Дни + Время
            
            // Примерная высота контента (35 - средняя высота строки)
            $rectHeight = ($internalBlocks * 40) + (($internalBlocks - 1) * $gap) + ($paddingY * 2);
            $cardH += $rectHeight;

            $totalGroupHeight += $cardH;
            
            // Добавляем отступ между карточками (marginBottom), кроме последней
            if ($index < count($locationCards) - 1) {
                $totalGroupHeight += ($settings['marginBottom'] ?? 30);
            }
        }

        // Смещаем старт первой карточки так, чтобы центр всей группы был в заданной точке startY
        $baseStartY = $creativeSettings['location_card_1']['startY'];
        $creativeSettings['location_card_1']['startY'] = $baseStartY - ($totalGroupHeight / 2);
    }

    foreach ($creativeSettings as $key => $elementSettings) {
        
        // ПРОПУСКАЕМ ТЕКСТ ДЛЯ СОЦСЕТЕЙ (чтобы он не ломал графику)
        if ($key === 'text' && !isset($elementSettings['position']) && !isset($elementSettings['font'])) {
            continue;
        }

        if (strpos($key, 'text') === 0 && is_array($elementSettings) && isset($elementSettings['template'])) {
            if (isset($elementSettings['isCapsule']) && $elementSettings['isCapsule'] === true) {
                drawTextWithCapsule($image, $elementSettings, $replacements, $svg);
            } else {
                drawTextOnImage($image, $elementSettings['template'], $elementSettings, $elementSettings['position'], $replacements, $svg);
            }
        }
        
        if ($key === 'opening_badge_settings' && is_array($elementSettings)) {
            
            // Проверяем, нажал ли пользователь тумблер (on/off)
            $showBadge = ($replacements['{h_showOpeningBadge}'] ?? 'off') === 'on';
    
            if ($showBadge) {
                // Рисуем плашку, передавая её настройки ($elementSettings)
                drawSimpleOpeningBadge(
                    $image, 
                    $elementSettings, 
                    $replacements, 
                    $svg
                );
            }
        }
        elseif ($key === 'contact_group' && is_array($elementSettings)) {
            // Вызываем нашу новую функцию для пачки контактов
            drawContactGroup($image, $elementSettings, $replacements, $svg);
        }
        elseif ($key === 'spring_h_list_settings' && is_array($elementSettings)) {
            // Отрисовка нового горизонтального списка
            $newY = drawHorizontalSpringList($image, $elementSettings, $replacements, $svg);
            if ($newY) $dynamicY = $newY;
        }
        elseif ($key === 'spring_city_list_settings' && is_array($elementSettings)) {
            $dynamicY = drawSpringCityList($image, $elementSettings, $replacements, $svg);
        }
        elseif ($key === 'city_list_header_settings') {
            $startY = $elementSettings['startY'] ?? 200;
            $newY = drawCityListWithHeader($image, $creativeSettings, $replacements, $startY, $svg);
            if ($newY) $dynamicY = $newY;
        }
        elseif (strpos($key, 'city_summary_soon') === 0 && is_array($elementSettings)) {
            $startY = (isset($dynamicY) && (!isset($elementSettings['startY']) || $elementSettings['startY'] === 'auto')) ? $dynamicY : ($elementSettings['startY'] ?? 900);
            $newY = drawCitySummarySoon($image, $elementSettings, $replacements, $startY, $svg);
            if ($newY) $dynamicY = $newY;
        }  
        elseif (strpos($key, 'city_summary') === 0 && is_array($elementSettings)) {
            $startY = (isset($dynamicY) && (!isset($elementSettings['startY']) || $elementSettings['startY'] === 'auto')) ? $dynamicY : ($elementSettings['startY'] ?? 900);
            $newY = drawCitySummary($image, $elementSettings, $replacements, $startY, $svg);
            if ($newY) $dynamicY = $newY;
        } 
        elseif (strpos($key, 'object') === 0 && is_array($elementSettings)) {
            drawObjectOnImage($image, $elementSettings, $svg);
        }
        elseif ($key === 'handler' && !empty($elementSettings)) {
            // Если в макете указана функция (например, drawMainSchedule), вызываем её
            if (function_exists($elementSettings)) {
                $newY = $elementSettings($image, $creativeSettings, $replacements, $svg);
                if ($newY) $dynamicY = $newY;
            }
        }
        elseif (strpos($key, 'location_card') === 0 && is_array($elementSettings)) {
            if (isset($dynamicY) && (!isset($elementSettings['startY']) || $elementSettings['startY'] === 'auto')) {
                $elementSettings['startY'] = $dynamicY;
            }
            $newY = drawLocationCard($image, $elementSettings, $replacements, $svg);
            if ($newY) $dynamicY = $newY;
        }
    }

    $finalSvgStr = "";

    if ($generateSvg) {
        $bgImg = imagecreatefromwebp($templatePath);
        ob_start();
        imagepng($bgImg);
        $bgPngData = ob_get_clean();
        $bgBase64 = base64_encode($bgPngData);
        imagedestroy($bgImg);
        
        $finalSvgLines = [];
        $finalSvgLines[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $finalSvgLines[] = '<svg width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">';
        
        $finalSvgLines[] = '  <image x="0" y="0" width="'.$width.'" height="'.$height.'" xlink:href="data:image/png;base64,'.$bgBase64.'"/>';

        // --- Разбиваем $svg на последовательные группы, сохраняя порядок слоёв ---
        // Каждая группа: ['type' => 'shape'|'text', 'items' => [...]]
        $runs = [];
        $currentRun = ['type' => null, 'items' => []];
        foreach ($svg as $item) {
            if (is_string($item)) {
                $type = 'shape';
            } elseif (is_array($item) && isset($item['type']) && $item['type'] === 'text') {
                $type = 'text';
            } else {
                continue;
            }
            if ($currentRun['type'] !== $type && $currentRun['type'] !== null) {
                $runs[] = $currentRun;
                $currentRun = ['type' => $type, 'items' => []];
            }
            $currentRun['type'] = $type;
            $currentRun['items'][] = $item;
        }
        if (!empty($currentRun['items'])) {
            $runs[] = $currentRun;
        }

        // Шрифты для fallback — встраиваем один раз
        $fontsEmbedded = false;
        $fallbackFontDefs = [];
        $fonts = array_merge(glob(FONTS_DIR . '*.ttf') ?: [], glob(FONTS_DIR . '*.otf') ?: []);
        if ($fonts) {
            $fallbackFontDefs[] = '  <defs><style>';
            foreach ($fonts as $fontFile) {
                $ext = strtolower(pathinfo($fontFile, PATHINFO_EXTENSION));
                $fontName = pathinfo($fontFile, PATHINFO_FILENAME);
                $base64 = base64_encode(file_get_contents($fontFile));
                $format = ($ext === 'otf') ? 'opentype' : 'truetype';
                $mime = ($ext === 'otf') ? 'font/otf' : 'font/ttf';
                $fallbackFontDefs[] = "    @font-face { font-family: '{$fontName}'; src: url('data:{$mime};base64,{$base64}') format('{$format}'); }";
            }
            $fallbackFontDefs[] = '  </style></defs>';
        }

        $pyScript = __DIR__ . '/text_to_path.py';

        // --- Обрабатываем каждую группу по порядку ---
        foreach ($runs as $run) {
            if ($run['type'] === 'shape') {
                // Фигуры вставляем напрямую
                foreach ($run['items'] as $shapeStr) {
                    $finalSvgLines[] = $shapeStr;
                }
            } elseif ($run['type'] === 'text') {
                $textBatch = $run['items'];
                $batchSuccess = false;

                // Пробуем Python для этой группы текстов
                $tmpJson = __DIR__ . '/temp_svg_' . uniqid() . '.json';
                if (file_put_contents($tmpJson, json_encode($textBatch, JSON_UNESCAPED_UNICODE)) !== false) {
                    if (file_exists($pyScript)) {
                        putenv('PYTHONIOENCODING=utf8');
                        $cmd = escapeshellcmd(PYTHON_EXECUTABLE) . " " . escapeshellarg($pyScript) . " " . escapeshellarg($tmpJson) . " 2>&1";
                        $outputArr = [];
                        $returnCode = 0;
                        exec($cmd, $outputArr, $returnCode);
                        $pathsSvg = implode("\n", $outputArr);

                        $debugLog = "=== " . date('Y-m-d H:i:s') . " ===\n";
                        $debugLog .= "CMD: " . $cmd . "\n";
                        $debugLog .= "CODE: " . $returnCode . "\n";
                        $debugLog .= "OUTPUT:\n" . $pathsSvg . "\n\n";
                        file_put_contents(__DIR__ . '/svg_debug.log', $debugLog, FILE_APPEND);

                        if ($returnCode === 0 && strpos($pathsSvg, '<g') !== false) {
                            $finalSvgLines[] = trim($pathsSvg);
                            $batchSuccess = true;
                        }
                    } else {
                        file_put_contents(__DIR__ . '/svg_debug.log', date('Y-m-d H:i:s') . " ОШИБКА: Файл $pyScript не найден\n", FILE_APPEND);
                    }
                    @unlink($tmpJson);
                } else {
                    file_put_contents(__DIR__ . '/svg_debug.log', date('Y-m-d H:i:s') . " ОШИБКА: Не удалось создать JSON файл\n", FILE_APPEND);
                }

                // Fallback — SVG text с встроенными шрифтами
                if (!$batchSuccess) {
                    if (!$fontsEmbedded && !empty($fallbackFontDefs)) {
                        foreach ($fallbackFontDefs as $def) {
                            $finalSvgLines[] = $def;
                        }
                        $fontsEmbedded = true;
                    }
                    foreach ($textBatch as $item) {
                        $fontName = pathinfo($item['font'], PATHINFO_FILENAME);
                        $pxSize = $item['size'] * 1.333333;
                        $transform = "";
                        if ($item['angle'] != 0) {
                            $svgAngle = -$item['angle'];
                            $transform = " transform=\"rotate($svgAngle, {$item['x']}, {$item['y']})\"";
                        }
                        $escapedText = htmlspecialchars(html_entity_decode($item['text'], ENT_QUOTES, 'UTF-8'), ENT_XML1, 'UTF-8');
                        $fillAttrs = 'fill="' . $item['color'] . '"';
                        if ($item['opacity'] < 1) $fillAttrs .= ' fill-opacity="' . $item['opacity'] . '"';
                        $finalSvgLines[] = sprintf('  <text x="%.2f" y="%.2f" font-family="%s" font-size="%.2fpx" %s%s>%s</text>',
                            $item['x'], $item['y'], $fontName, $pxSize, $fillAttrs, $transform, $escapedText);
                    }
                }
            }
        }

        $finalSvgLines[] = '</svg>';
        $finalSvgStr = implode("\n", $finalSvgLines);
    }

    return [
        'image' => $image,
        'svg' => $finalSvgStr
    ];
}
?>