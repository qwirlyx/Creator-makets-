<?php
session_name('CREATOR_SESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => (file_exists(__DIR__ . '/config.local.php') ? (require __DIR__ . '/config.local.php')['session_path'] ?? '/' : '/'),
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// === БЫСТРЫЙ ПРИЕМ SSO ПРЯМО В INDEX.PHP ===
$appConfig = file_exists(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : [];
define('SSO_SECRET', $appConfig['sso_secret'] ?? 'change_me');

$sso_allowed_users = $appConfig['sso_allowed_users'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['sso_sign'])) {
    $sso_user    = $_POST['sso_user'] ?? '';
    $sso_time    = $_POST['sso_time'] ?? '0';
    $sso_service = $_POST['sso_service'] ?? '';
    $sso_sign    = $_POST['sso_sign'];

    if (abs(time() - (int)($sso_time / 1000)) > 60) {
        die("Ошибка SSO: Срок действия токена перехода истек.");
    }

    $dataToSign = $sso_user . "|" . $sso_time . "|" . $sso_service;
    $expectedSign = hash_hmac('sha256', $dataToSign, SSO_SECRET);

    if (!hash_equals($expectedSign, $sso_sign)) {
        die("Ошибка SSO: Недействительная или подделанная подпись.");
    }

    if (!isset($sso_allowed_users[$sso_user])) {
        die('Доступ в данный сервис через корпоративное зеркало запрещен для сотрудника: ' . htmlspecialchars($sso_user));
    }

    $_SESSION['bm_logged_in'] = true;
    $_SESSION['bm_user'] = $sso_user;
    $_SESSION['role'] = $sso_allowed_users[$sso_user];

    session_regenerate_id(true);

    header("Location: index.php");
    exit;
}

require_once __DIR__ . '/auth_check.php';
include 'makets.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Креатор</title>
    <link rel="stylesheet" href="styles.css?v=1.6">
    <link rel="icon" type="image/x-icon" href="../icon.ico">
</head>
<body>
    <div class="container">
        <a href="logout.php" class="logout-btn-fixed">Выйти</a>
        <h1>Креатор макетов</h1>
        
        <?php
            $sections = array_unique(array_column($settings, 'section'));
            $firstSection = reset($sections);
        ?>

        <div class="options-panel" id="category-tabs">
            <?php foreach($sections as $section): ?>
                <button type="button" class="toggle-btn <?= ($section === $firstSection) ? 'active' : '' ?>" data-target="<?= htmlspecialchars($section) ?>">
                    <?= htmlspecialchars($section) ?>
                </button>
            <?php endforeach; ?>
        </div>

        <form class="search-form" id="creator-form" method="post" action="create.php">
            
            <div class="layout-sections-wrapper">
                <?php foreach($sections as $section): ?>
                    <div class="layout-section full-width section-content" id="section-<?= htmlspecialchars($section) ?>" style="<?= ($section === $firstSection) ? '' : 'display:none;' ?>">
                        
                        <?php 
                        $groups = [
                            'posts' => ['items' => []],
                            'stories' => ['items' => []]
                        ];

                        foreach($settings as $name => $data) {
                            if($data['section'] !== $section) continue;
                            
                            $templatePath = 'templates/' . $data['template'];
                            $isStory = false;
                            
                            if (file_exists($templatePath)) {
                                $size = @getimagesize($templatePath);
                                if ($size) {
                                    $ratio = $size[1] / $size[0];
                                    if ($ratio > 1.3) $isStory = true;
                                }
                            } else {
                                if (stripos($name, 'сторис') !== false || stripos($data['template'], 'сторис') !== false) {
                                    $isStory = true;
                                }
                            }

                            $targetKey = $isStory ? 'stories' : 'posts';

                            if (isset($data['group'])) {
                                $groupName = $data['group'];
                                if (!isset($groups[$targetKey]['items'][$groupName])) {
                                    $groups[$targetKey]['items'][$groupName] = [
                                        'is_group' => true,
                                        'name' => $groupName,
                                        'variants' => []
                                    ];
                                }
                                $groups[$targetKey]['items'][$groupName]['variants'][$name] = $data;
                            } else {
                                $groups[$targetKey]['items'][$name] = $data;
                            }
                        }

                        $groupCounter = 0;
                        foreach ($groups as $groupKey => $groupData):
                            if (empty($groupData['items'])) continue;
                            $groupCounter++;
                            
                            if ($groupCounter > 1) {
                                echo '<hr class="group-separator">';
                            }

                            if ($groupCounter === 1) {
                                ?>
                                <div class="group-header-row">
                                    <button type="button" class="select-all-btn" data-section="<?= htmlspecialchars($section) ?>">Выбрать все</button>
                                </div>
                                <?php
                            }
                        ?>
                            <div class="layouts-grid">
                                <?php 
                                foreach($groupData['items'] as $name => $item):
                                    if (isset($item['is_group']) && $item['is_group']) {
                                        $firstVariantName = array_key_first($item['variants']);
                                        $firstVariantData = $item['variants'][$firstVariantName];
                                        
                                        $fieldsJson = json_encode($firstVariantData['fields'] ?? ['city','phone','link','date']);
                                        $fieldsSafe = htmlspecialchars($fieldsJson, ENT_QUOTES, 'UTF-8');
                                        $maxLimit = isset($firstVariantData['max_locations_limit']) ? (int)$firstVariantData['max_locations_limit'] : 4;
                                        $templatePath = 'templates/' . $firstVariantData['template'];
                                        
                                        // Кодируем все варианты в JSON для JS
                                        $variantsJson = htmlspecialchars(json_encode($item['variants']), ENT_QUOTES, 'UTF-8');
                                        ?>
                                        <div class="layout-card btn-toggle group-card" 
                                             data-creative="<?= htmlspecialchars($firstVariantName) ?>" 
                                             data-fields="<?= $fieldsSafe ?>" 
                                             data-limit="<?= $maxLimit ?>"
                                             data-variants="<?= $variantsJson ?>">
                                            <div class="preview-wrapper">
                                                <img src="<?= $templatePath ?>" data-original-src="<?= $templatePath ?>" alt="<?= htmlspecialchars($item['name']) ?>" style="transition: opacity 0.3s;">
                                            </div>
                                            <div class="layout-name"><?= htmlspecialchars($item['name']) ?></div>
                                        </div>
                                        <?php
                                    } else {
                                        $data = $item;
                                        $fieldsJson = json_encode($data['fields'] ?? ['city','phone','link','date']);
                                        $fieldsSafe = htmlspecialchars($fieldsJson, ENT_QUOTES, 'UTF-8');
                                        
                                        $maxLimit = isset($data['max_locations_limit']) ? (int)$data['max_locations_limit'] : 4;
                                        $templatePath = 'templates/' . $data['template']; 
                                        ?>
                                        <div class="layout-card btn-toggle" 
                                             data-creative="<?= htmlspecialchars($name) ?>" 
                                             data-fields="<?= $fieldsSafe ?>" 
                                             data-limit="<?= $maxLimit ?>"> 
                                            <div class="preview-wrapper">
                                                <img src="<?= $templatePath ?>" data-original-src="<?= $templatePath ?>" alt="<?= htmlspecialchars($name) ?>" style="transition: opacity 0.3s;">
                                            </div>
                                            <div class="layout-name"><?= htmlspecialchars($name) ?></div>
                                        </div>
                                        <?php
                                    }
                                endforeach; 
                                ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="top-panel form-inputs-row" style="margin-top: 20px;">

                <div class="form-group field-group" id="group-city" style="display: none;">
                    <label for="city">Город:</label>
                    <input type="text" id="city" name="city" placeholder="г. Омск или г. Омск, ул. Шаляпина 4">
                </div>
                <div class="form-group field-group" id="group-address" style="display: none;">
                    <label for="address">Адрес:</label>
                    <input type="text" id="address" name="address" placeholder="ул. Ленина 10">
                </div>
                <div class="form-group field-group" id="group-phone" style="display: none;">
                    <label for="phone">Номер телефона:</label>
                    <input type="text" id="phone" name="phone" class="phone-mask" placeholder="+7 999 000 00 00">
                </div>
                <div class="form-group field-group" id="group-link" style="display: none;">
                    <label for="link">Ссылка на группу:</label>
                    <input type="text" id="link" name="link" placeholder="https://vk.ru/starikhinkalych73">
                </div>
                <div class="form-group field-group" id="group-date" style="display: none;">
                    <label for="date">Дата открытия:</label>
                    <input type="text" id="date" name="date" placeholder="16.09 или 16 сентября">
                </div>
                <div class="form-group field-group" id="group-name" style="display: none;">
                    <label for="name">Имя:</label>
                    <input type="text" id="name" name="name" placeholder="Введите имя">
                </div>
                
                <div class="form-group field-group" id="group-topic" style="display: none;">
                    <label for="topic">Тема:</label>
                    <input type="text" id="topic" name="topic" placeholder="Тема мероприятия">
                </div>
                
                <div class="form-group field-group" id="group-other_date1" style="display: none;">
                    <label for="date1">Дата 1:</label>
                    <input type="text" id="other_Date1" name="other_date1" placeholder="25 мая">
                </div>
                <div class="form-group field-group" id="group-other_date2" style="display: none;">
                    <label for="date2">Дата 2:</label>
                    <input type="text" id="other_Date2" name="other_date2" placeholder="26 мая">
                </div>
                
                <div class="form-group field-group" id="group-other_time1" style="display: none;">
                    <label for="time1">Время 1:</label>
                    <input type="text" id="other_Time1" name="other_time1" placeholder="18:00 - 20:00">
                </div>
                <div class="form-group field-group" id="group-other_time2" style="display: none;">
                    <label for="time2">Время 2:</label>
                    <input type="text" id="other_Time2" name="other_time2" placeholder="10:00 - 12:00">
                </div>
                <div class="form-group field-group" id="group-noticebadge" style="display: none; grid-column: 1 / -1; width: 100%;">
                    <div style="background: #252525; padding: 15px; border-radius: 8px; border: 1px dashed #4a90e2; display: flex; align-items: center; gap: 15px;">
                        <div class="switch-container">
                            <input type="checkbox" name="h_showOpeningBadge" id="h_showOpeningBadge" class="badge-checkbox" tabindex="-1">
                            <label for="h_showOpeningBadge" class="badge-label"></label>
                        </div>
                        <span style="color: #4a90e2; font-weight: bold; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; cursor: pointer;" onclick="document.getElementById('h_showOpeningBadge').click();">
                            Включить плашку "Скоро открытие"
                        </span>
                    </div>
                </div>
                
                <div class="form-group field-group" id="group-variant-select" style="display: none; grid-column: 1 / -1; width: 100%;">
                    <div style="background: #252525; padding: 15px; border-radius: 8px; border: 1px solid #4a90e2;">
                        <label for="global-variant-select" style="color: #4a90e2; font-weight: bold; margin-bottom: 10px; display: block; font-size: 16px;">Выберите вариант макета:</label>
                        <select id="global-variant-select" style="width: 100%; padding: 10px; background: #1a1a1a; color: white; border: 1px solid #333; border-radius: 4px; font-size: 14px; outline: none; cursor: pointer; transition: border-color 0.3s;"></select>
                    </div>
                </div>

                <?php
                $placeholders = [
                    1 => ['city' => 'г. Екатеринбург', 'addr' => 'ул. Малышева, 36',    'days' => 'Пн Вт Ср Чт Пт', 'time' => '10:00 - 22:00'],
                    2 => ['city' => 'г. Нижний Тагил', 'addr' => 'пр-т Ленина, 57',    'days' => 'Сб Вс',          'time' => '11:00 - 19:00'],
                    3 => ['city' => 'г. Верхняя Пышма', 'addr' => 'пр-д Кольцевой, 4', 'days' => 'Ср Чт Пт',     'time' => '09:00 - 21:00'],
                    4 => ['city' => 'г. Симферополь',  'addr' => 'наб. Речная, 12а',   'days' => 'Пн Пт',          'time' => '10:00 - 16:00'],
                ];
                ?>
                
                <div class="form-group field-group" id="group-locations" style="display: none; grid-column: 1 / -1; width: 100%; box-sizing: border-box;">
                    <div style="background: #252525; padding: 15px; border-radius: 8px; border: 1px solid #444; width: 100%; box-sizing: border-box;">
                        <h3 style="margin: 0 0 15px 0; border: none; text-align: left;">Точки и расписание</h3>
                        
                        <div id="locations-list" style="display: flex; flex-direction: column; gap: 15px; width: 100%;">
                            <?php for($i=1; $i<=4; $i++): 
                                $p = $placeholders[$i];
                            ?>
                            <div class="loc-block" id="loc-block-<?= $i ?>" style="<?= $i > 1 ? 'display: none; border-top: 1px solid #444; padding-top: 15px;' : '' ?> width: 100%;">
                                <div style="color: #4a90e2; font-weight: bold; margin-bottom: 10px;">Точка <?= $i ?></div>
                                
                                <div style="display: flex; flex-wrap: wrap; gap: 10px; width: 100%;">
                                    <div class="form-group" style="flex: 1 1 150px;">
                                        <label>Город:</label>
                                        <input type="text" id="locCity<?= $i ?>" name="locCity<?= $i ?>" placeholder="<?= $p['city'] ?>">
                                    </div>
                
                                    <div class="form-group" style="flex: 1 1 200px;">
                                        <label>Адрес:</label>
                                        <input type="text" id="address<?= $i ?>" name="address<?= $i ?>" placeholder="<?= $p['addr'] ?>">
                                    </div>
                
                                    <div class="form-group" style="flex: 1 1 150px;">
                                        <label>Дни:</label>
                                        <input type="text" id="weekDays<?= $i ?>" name="weekDays<?= $i ?>" placeholder="<?= $p['days'] ?>">
                                    </div>
                
                                    <div class="form-group" style="flex: 1 1 150px;">
                                        <label>Время 1:</label>
                                        <input type="text" id="time<?= $i ?>" name="time<?= $i ?>" placeholder="<?= $p['time'] ?>">
                                    </div>
                
                                    <?php if($i <= 4): ?>
                                        <div class="form-group" style="flex: 1 1 150px;">
                                            <label style="color: #888;">Дни доп:</label>
                                            <input type="text" id="weekDays<?= $i+4 ?>" name="weekDays<?= $i+4 ?>" placeholder="Сб Вс">
                                        </div>
                                        <div class="form-group" style="flex: 1 1 150px;">
                                            <label style="color: #888;">Время 2:</label>
                                            <input type="text" id="time<?= $i+4 ?>" name="time<?= $i+4 ?>" placeholder="10:00 - 18:00">
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                
                        <div style="display: flex; gap: 10px; margin-top: 15px;">
                            <button type="button" id="btn-add-loc" class="load-btn" style="width: max-content;">+ Добавить точку</button>
                            <button type="button" id="btn-remove-loc" class="load-btn" style="width: max-content; background: #941b1b; display: none;">- Убрать точку</button>
                        </div>
                    </div>
                </div>

               <?php
                $sumPlaceholders = [
                    1 => ['addr' => 'ул. Ленина, 101',      'phone' => '+7 912 145 87 26'],
                    2 => ['addr' => 'пр-т Мира, 12',        'phone' => '+7 900 555 35 35'],
                    3 => ['addr' => 'б-р Энтузиастов, 3',   'phone' => '+7 922 111 22 33'],
                    4 => ['addr' => 'пер. Газетный, 5а',    'phone' => '+7 950 444 00 11'],
                ];
                ?>
                
                <div class="form-group field-group" id="group-city-summary" style="display: none; grid-column: 1 / -1; width: 100%; box-sizing: border-box;">
                    <div style="background: #252525; padding: 15px; border-radius: 8px; border: 1px solid #444; width: 100%; box-sizing: border-box;">
                        <h3 style="margin: 0 0 15px 0; border: none; text-align: left; color: #fff;">Список локаций на карточках</h3>
                        <div id="city-summary-list" style="display: flex; flex-direction: column; gap: 15px; width: 100%;">
                            <?php for ($i = 1; $i <= 4; $i++): 
                                $sp = $sumPlaceholders[$i];
                            ?>
                            <div class="city-summary-block" id="city-summary-block-<?= $i ?>" style="<?= $i > 1 ? 'display: none;' : '' ?> width: 100%; border-bottom: 1px solid #333; padding-bottom: 15px;">
                                <div style="color: #4a90e2; font-weight: bold; margin-bottom: 10px;">Локация №<?= $i ?></div>
                                <div style="display: flex; flex-wrap: wrap; gap: 15px; width: 100%; align-items: flex-end;">
                                    
                                    <div class="form-group" style="flex: 2 1 300px;">
                                        <label style="color: #ccc; font-size: 12px;">Адрес:</label>
                                        <input type="text" name="sumAddress<?= $i ?>" id="sumAddress<?= $i ?>" placeholder="<?= $sp['addr'] ?>">
                                    </div>
                
                                    <div class="form-group" style="flex: 1 1 200px;">
                                        <label style="color: #ccc; font-size: 12px;">Телефон:</label>
                                        <input type="text" name="sumPhone<?= $i ?>" id="sumPhone<?= $i ?>" class="phone-mask" placeholder="<?= $sp['phone'] ?>">
                                    </div>
                
                                    <div class="soon-wrapper">
                                        <input type="checkbox" name="soon<?= $i ?>" id="soon<?= $i ?>" class="soon-checkbox">
                                        <label for="soon<?= $i ?>" class="soon-label">Скоро открытие</label>
                                    </div>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                        <div style="display: flex; gap: 10px; margin-top: 15px;">
                            <button type="button" id="btn-add-sum" class="load-btn" style="width: max-content; background: #2e7d32;">+ Добавить адрес</button>
                            <button type="button" id="btn-remove-sum" class="load-btn" style="width: max-content; background: #941b1b; display: none;">- Убрать последний</button>
                        </div>
                    </div>
                </div>

                <?php
                $headerStreetPlaceholders = [
                    1 => 'ул. Мира, 10',
                    2 => 'ш. Куркинское, 2',
                    3 => 'пр-т Октябрьский, 112',
                    4 => 'пер. Газетный, 5'
                ];
                ?>
                
                <div class="form-group field-group" id="group-city-list-header" style="display: none; grid-column: 1 / -1; width: 100%; box-sizing: border-box;">
                    <div style="background: #252525; padding: 15px; border-radius: 8px; border: 1px solid #444; width: 100%; box-sizing: border-box;">
                        <h3 style="margin: 0 0 15px 0; border: none; text-align: left; color: #fff;">Список городов и адресов</h3>
                        
                        <div class="form-group" style="margin-bottom: 20px; width: 100%;">
                            <label style="color: #4a90e2; font-weight: bold;">Главный заголовок (Регион):</label>
                            <input type="text" name="main_header" id="main_header" placeholder="Московская область">
                        </div>
                
                        <div id="city-list-header-container" style="display: flex; flex-direction: column; gap: 15px; width: 100%;">
                            <?php for ($i = 1; $i <= 4; $i++): ?>
                            <div class="city-header-block" id="city-header-block-<?= $i ?>" style="<?= $i > 1 ? 'display: none;' : '' ?> width: 100%; border-top: 1px solid #333; padding-top: 15px;">
                                <div style="color: #ccc; font-weight: bold; margin-bottom: 10px;">Локация №<?= $i ?></div>
                                <div style="display: flex; flex-wrap: wrap; gap: 10px; width: 100%;">
                                    
                                    <div class="form-group" style="flex: 1 1 200px;">
                                        <label style="font-size: 12px;">Город:</label>
                                        <input type="text" name="h_sumCity<?= $i ?>" id="h_sumCity<?= $i ?>" placeholder="г. Химки">
                                    </div>
                
                                    <div class="form-group" style="flex: 2 1 300px;">
                                        <label style="font-size: 12px;">Адрес:</label>
                                        <input type="text" name="h_sumAddress<?= $i ?>" id="h_sumAddress<?= $i ?>" placeholder="<?= $headerStreetPlaceholders[$i] ?>">
                                    </div>
                
                                    <div class="form-group" style="flex: 1 1 200px;">
                                        <label style="font-size: 12px;">Телефон:</label>
                                        <input type="text" name="h_sumPhone<?= $i ?>" id="h_sumPhone<?= $i ?>" class="phone-mask" placeholder="+7 978 543 25 64">
                                    </div>
                
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                
                        <div style="display: flex; gap: 10px; margin-top: 15px;">
                            <button type="button" id="btn-add-header-city" class="load-btn" style="width: max-content; background: #2e7d32;">+ Добавить город</button>
                            <button type="button" id="btn-remove-header-city" class="load-btn" style="width: max-content; background: #941b1b; display: none;">- Убрать последний</button>
                        </div>
                
                        <div style="margin-top: 15px; padding: 10px; border-left: 3px solid #007bff; background: rgba(0, 123, 255, 0.1); color: #ccc; font-size: 0.85rem; line-height: 1.4;">
                            <strong>Примечание!</strong><br>
                            Если в поле «Город» указано то же название, что и в ячейке выше, эти адреса автоматически объединятся в одну группу под общим заголовком города.
                        </div>
                    </div>
                </div>
                
                <?php
                $springStreetPlaceholders = [
                    'ул. Ленина, 10', 'пр-т Мира, 25', 'ш. Энтузиастов, 5', 'б-р Славы, 14', 'пер. Дачный, 3',
                    'ул. Гагарина, 8', 'пр-т Победы, 101', 'ул. Московская, 2', 'наб. Речная, 15', 'ул. Южная, 44'
                ];
                ?>
                
                <div class="form-group field-group" id="group-spring-list" style="display: none; grid-column: 1 / -1; width: 100%; box-sizing: border-box;">
                    <div style="background: #252525; padding: 20px; border-radius: 12px; border: 1px solid #4a90e2; width: 100%; box-sizing: border-box;">
                        <h3 style="margin: 0 0 20px 0; border: none; text-align: left; color: #4a90e2; font-size: 20px;">Настройка весеннего списка</h3>
                        
                        <div id="spring-cities-container" style="display: flex; flex-direction: column; gap: 25px;">
                            <?php for ($i = 1; $i <= 4; $i++): ?>
                            <div class="spring-city-block" id="spring-city-<?= $i ?>" style="<?= $i > 1 ? 'display: none;' : '' ?> background: #1e1e1e; padding: 15px; border-radius: 8px; border: 1px solid #333;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                    <div style="font-weight: bold; color: #fff; font-size: 16px;">Город №<?= $i ?></div>
                                </div>
                                
                                <div class="form-group" style="margin-bottom: 15px;">
                                    <label style="font-size: 13px; color: #888;">Название города (белым цветом):</label>
                                    <input type="text" name="springCity<?= $i ?>" placeholder="Москва">
                                </div>
                
                                <div class="spring-addresses-list" id="spring-addresses-<?= $i ?>" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 10px;">
                                    <?php for ($j = 1; $j <= 10; $j++): 
                                        $placeholder = $springStreetPlaceholders[$j - 1] ?? 'ул. Центральная, 1';
                                    ?>
                                    <div class="spring-addr-item" id="spring-addr-<?= $i ?>-<?= $j ?>" style="<?= $j > 1 ? 'display: none;' : '' ?>">
                                        <label style="font-size: 11px; color: #666;">Адрес <?= $j ?>:</label>
                                        <input type="text" name="springAddr<?= $i ?>_<?= $j ?>" placeholder="<?= $placeholder ?>">
                                    </div>
                                    <?php endfor; ?>
                                </div>
                
                                <div style="margin-top: 15px; display: flex; gap: 10px;">
                                    <button type="button" class="load-btn btn-add-addr" data-city="<?= $i ?>" style="display: inline-flex; align-items: center; justify-content: center; padding: 5px 12px; font-size: 12px; background: #388e3c; height: 30px; line-height: 1;">+ Добавить адрес</button>
                                    <button type="button" class="load-btn btn-remove-addr" data-city="<?= $i ?>" style="display: inline-flex; align-items: center; justify-content: center; padding: 5px 12px; font-size: 12px; background: #d32f2f; display: none; height: 30px; line-height: 1;">- Убрать адрес</button>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                
                        <div style="display: flex; gap: 15px; margin-top: 20px; border-top: 1px solid #333; padding-top: 20px;">
                            <button type="button" id="btn-add-spring-city" class="load-btn" style="background: #4a90e2;">+ Добавить город</button>
                            <button type="button" id="btn-remove-spring-city" class="load-btn" style="background: #941b1b; display: none;">- Убрать город</button>
                        </div>
                    </div>
                </div>
                
                <div class="form-group field-group" id="group-spring-h-list" style="display: none; grid-column: 1 / -1; width: 100%; box-sizing: border-box;">
                    <div style="background: #252525; padding: 20px; border-radius: 12px; border: 1px solid #4a90e2; width: 100%; box-sizing: border-box;">
                        <h3 style="margin: 0 0 20px 0; border: none; text-align: left; color: #4a90e2; font-size: 20px;">Горизонтальный список (2 колонки)</h3>
                        
                        <div id="spring-h-cities-container" style="display: flex; flex-direction: column; gap: 20px;">
                            <?php for ($i = 1; $i <= 10; $i++): ?>
                            <div class="spring-h-city-block" id="spring-h-city-<?= $i ?>" style="<?= $i > 1 ? 'display: none;' : '' ?> background: #1e1e1e; padding: 15px; border-radius: 8px; border: 1px solid #333;">
                                
                                <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; margin-bottom: 15px;">
                                    <div style="flex: 2 1 300px;">
                                        <label style="font-size: 13px; color: #888;">Название города <?= $i ?>:</label>
                                        <input type="text" name="h_springCity<?= $i ?>" placeholder="Напр: Москва">
                                    </div>
                                    
                                    <div style="flex: 1 1 200px;">
                                        <label style="font-size: 13px; color: #888;">Сторона:</label>
                                        <div class="side-selector">
                                            <input type="radio" name="h_springSide<?= $i ?>" id="sideL<?= $i ?>" value="left" checked>
                                            <label for="sideL<?= $i ?>">Слева</label>
                                            <input type="radio" name="h_springSide<?= $i ?>" id="sideR<?= $i ?>" value="right">
                                            <label for="sideR<?= $i ?>">Справа</label>
                                        </div>
                                    </div>
                                </div>
                
                                <div class="spring-h-addresses-grid" id="spring-h-addresses-<?= $i ?>" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 10px 20px;">
                                    <?php 
                                    // Список разнообразных плейсхолдеров
                                    $placeholders = [
                                        'ТРЦ «Глобус», эт. 1', 
                                        'пр-т Ленина, 42', 
                                        'Шоссе Энтузиастов, 12', 
                                        'Парк Победы, пав. 3', 
                                        'ул. Мира, 7 (вход со двора)', 
                                        'БЦ «Маяк», офис 101',
                                        'пл. Свободы, 2',
                                        'Набережная, 15к2',
                                        'м. Сокольники (выход 2)',
                                        'ул. Большая Садовая, 10'
                                    ];
                                    ?>
                                    <?php for ($j = 1; $j <= 20; $j++): ?>
                                    <?php 
                                        // Выбираем плейсхолдер циклично из массива
                                        $ph = $placeholders[($j - 1) % count($placeholders)]; 
                                    ?>
                                    <div class="spring-h-addr-item" id="spring-h-addr-<?= $i ?>-<?= $j ?>" style="<?= $j > 1 ? 'display: none;' : 'display: flex;' ?> flex-direction: column; gap: 4px; margin-bottom: 5px;">
                                        
                                        <div style="width: 100%;">
                                            <label style="font-size: 11px; color: #666;">Адрес <?= $j ?>:</label>
                                            <input type="text" name="h_springAddr<?= $i ?>_<?= $j ?>" placeholder="<?= $ph ?>" style="height: 32px;">
                                        </div>
                
                                        <div style="width: 100%;">
                                            <input type="text" 
                                                   name="h_springPhone<?= $i ?>_<?= $j ?>" 
                                                   id="h_springPhone<?= $i ?>_<?= $j ?>" 
                                                   class="phone-mask" 
                                                   placeholder="+7 999 000 00 00"
                                                   style="height: 32px; font-size: 12px; border-style: dashed; border-color: #444; background: transparent;">
                                        </div>
                                    </div>
                                    <?php endfor; ?>
                                </div>
                
                                <div style="margin-top: 15px; display: flex; gap: 10px;">
                                    <button type="button" class="load-btn btn-add-h-addr" data-city="<?= $i ?>" 
                                        style="background: #388e3c; height: 30px; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; padding: 0 12px; line-height: 1;">
                                        + Добавить адрес
                                    </button>
                                    <button type="button" class="load-btn btn-remove-h-addr" data-city="<?= $i ?>" 
                                        style="background: #d32f2f; height: 30px; font-size: 12px; display: none; align-items: center; justify-content: center; padding: 0 12px; line-height: 1;">
                                        - Убрать адрес
                                    </button>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                
                        <div style="display: flex; gap: 15px; margin-top: 20px; border-top: 1px solid #333; padding-top: 20px;">
                            <button type="button" id="btn-add-h-city" class="load-btn" style="background: #4a90e2; padding: 0 20px; min-height: 35px;">
                                + Добавить город
                            </button>
                            <button type="button" id="btn-remove-h-city" class="load-btn" style="background: #941b1b; display: none; padding: 0 20px; min-height: 35px;">
                                - Убрать город
                            </button>
                        </div>
                    </div>
                </div>

            </div>

            <div class="submit-group">
                <button type="submit" class="generate-btn">Сгенерировать</button>
                <button type="submit" name="export_svg" value="1" class="generate-btn svg-btn" title="Сгенерировать в формате SVG">SVG</button>
            </div>
            <input type="hidden" id="selected-creatives" name="creatives[]" value="">
        </form>
    </div>

    <script>
        function maskPhone(e) {
            let value = e.target.value.replace(/\D/g, ''); 
            if (!value || value.length === 0) { e.target.value = ''; return; }
            if (value.startsWith('7') || value.startsWith('8')) value = value.slice(1);
            if (value.length === 0) { e.target.value = ''; return; }
            value = value.slice(0, 10);
            let result = '+7';
            if (value.length > 0) result += ' ' + value.slice(0, 3);
            if (value.length > 3) result += ' ' + value.slice(3, 6);
            if (value.length > 6) result += ' ' + value.slice(6, 8);
            if (value.length > 8) result += ' ' + value.slice(8, 10);
            e.target.value = result;
        }
        
        document.addEventListener('focusin', function(e) {
            if (e.target.classList.contains('phone-mask')) {
                if (e.target.value === '') e.target.value = '+7 ';
            }
        });
        
        document.addEventListener('focusout', function(e) {
            if (e.target.classList.contains('phone-mask')) {
                if (e.target.value === '+7' || e.target.value === '+7 ') e.target.value = '';
            }
        });

        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('phone-mask')) {
                maskPhone(e);
                refreshPreviews();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.target.classList.contains('phone-mask')) {
                if (e.target.selectionStart < 3 && (e.keyCode === 8 || e.keyCode === 46)) e.preventDefault();
            }
        });

        // --- Навигация по табам ---
        const tabBtns = document.querySelectorAll('#category-tabs .toggle-btn');
        const sectionEls = document.querySelectorAll('.section-content');
        tabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                tabBtns.forEach(b => b.classList.remove('active'));
                sectionEls.forEach(s => s.style.display = 'none');
                btn.classList.add('active');
                const target = document.getElementById('section-' + btn.dataset.target);
                if(target) target.style.display = 'block';
            });
        });
    
        // --- Переменные состояния ---
        const toggles = document.querySelectorAll('.btn-toggle');
        const selectAllBtns = document.querySelectorAll('.select-all-btn');
        const hiddenInput = document.getElementById('selected-creatives');
        const form = document.getElementById('creator-form');
        const globalVariantSelect = document.getElementById('global-variant-select');
    
        let visibleLocs = 1;
        let visibleSums = 1;
        let visibleHeaderCities = 1;
        let visibleSpringCities = 1;
        let currentMaxLocs = 4;
    
        const fieldGroups = {
            city: document.getElementById('group-city'),
            phone: document.getElementById('group-phone'),
            link: document.getElementById('group-link'),
            date: document.getElementById('group-date'),
            address: document.getElementById('group-address'),
            name: document.getElementById('group-name'),
            topic: document.getElementById('group-topic'),
            other_date1: document.getElementById('group-other_date1'),
            other_date2: document.getElementById('group-other_date2'),
            other_time1: document.getElementById('group-other_time1'),
            other_time2: document.getElementById('group-other_time2'),
            noticebadge: document.getElementById('group-noticebadge')
        };
        
        const inputs = Array.from(form.querySelectorAll('input'));
    
        // --- Логика Весеннего списка ---
        document.getElementById('btn-add-spring-city').addEventListener('click', () => {
            if (visibleSpringCities < 4) {
                visibleSpringCities++;
                document.getElementById('spring-city-' + visibleSpringCities).style.display = 'block';
                document.getElementById('btn-remove-spring-city').style.display = 'block';
            }
            if (visibleSpringCities === 4) document.getElementById('btn-add-spring-city').style.display = 'none';
        });
    
        document.getElementById('btn-remove-spring-city').addEventListener('click', () => {
            if (visibleSpringCities > 1) {
                const block = document.getElementById('spring-city-' + visibleSpringCities);
                block.style.display = 'none';
                block.querySelectorAll('input').forEach(i => i.value = '');
                visibleSpringCities--;
                document.getElementById('btn-add-spring-city').style.display = 'block';
                refreshPreviews();
            }
            if (visibleSpringCities === 1) document.getElementById('btn-remove-spring-city').style.display = 'none';
        });
    
        document.querySelectorAll('.btn-add-addr').forEach(btn => {
            btn.addEventListener('click', function() {
                const cityIdx = this.dataset.city;
                const container = document.getElementById('spring-addresses-' + cityIdx);
                const visibleAddrs = Array.from(container.querySelectorAll('.spring-addr-item')).filter(i => i.style.display !== 'none').length;
                if (visibleAddrs < 10) {
                    const nextAddr = visibleAddrs + 1;
                    document.getElementById(`spring-addr-${cityIdx}-${nextAddr}`).style.display = 'block';
                    container.parentElement.querySelector('.btn-remove-addr').style.display = 'inline-flex';
                }
                if (visibleAddrs + 1 === 10) this.style.display = 'none';
            });
        });
    
        document.querySelectorAll('.btn-remove-addr').forEach(btn => {
            btn.addEventListener('click', function() {
                const cityIdx = this.dataset.city;
                const container = document.getElementById('spring-addresses-' + cityIdx);
                const visibleAddrs = Array.from(container.querySelectorAll('.spring-addr-item')).filter(i => i.style.display !== 'none').length;
                if (visibleAddrs > 1) {
                    const block = document.getElementById(`spring-addr-${cityIdx}-${visibleAddrs}`);
                    block.style.display = 'none';
                    block.querySelector('input').value = '';
                    container.parentElement.querySelector('.btn-add-addr').style.display = 'inline-flex';
                    refreshPreviews();
                }
                if (visibleAddrs - 1 === 1) this.style.display = 'none';
            });
        });
    
        // --- Управление стандартными точками ---
        function updateControlsVisibility() {
            const btnAddLoc = document.getElementById('btn-add-loc');
            const btnRemoveLoc = document.getElementById('btn-remove-loc');
            if(btnAddLoc) btnAddLoc.style.display = (visibleLocs >= currentMaxLocs) ? 'none' : 'block';
            if(btnRemoveLoc) btnRemoveLoc.style.display = (visibleLocs > 1) ? 'block' : 'none';
            const btnAddSum = document.getElementById('btn-add-sum');
            const btnRemoveSum = document.getElementById('btn-remove-sum');
            if(btnAddSum) btnAddSum.style.display = (visibleSums >= currentMaxLocs) ? 'none' : 'block';
            if(btnRemoveSum) btnRemoveSum.style.display = (visibleSums > 1) ? 'block' : 'none';
            const btnAddHeaderCity = document.getElementById('btn-add-header-city');
            const btnRemoveHeaderCity = document.getElementById('btn-remove-header-city');
            if(btnAddHeaderCity) btnAddHeaderCity.style.display = (visibleHeaderCities >= currentMaxLocs) ? 'none' : 'block';
            if(btnRemoveHeaderCity) btnRemoveHeaderCity.style.display = (visibleHeaderCities > 1) ? 'block' : 'none';
        }
    
        document.getElementById('btn-add-loc')?.addEventListener('click', () => {
            if (visibleLocs < currentMaxLocs) {
                visibleLocs++;
                document.getElementById('loc-block-' + visibleLocs).style.display = 'block';
            }
            updateControlsVisibility();
        });
        document.getElementById('btn-remove-loc')?.addEventListener('click', () => {
            if (visibleLocs > 1) {
                document.getElementById('loc-block-' + visibleLocs).style.display = 'none';
                document.getElementById('loc-block-' + visibleLocs).querySelectorAll('input').forEach(i => i.value = '');
                visibleLocs--;
                refreshPreviews();
            }
            updateControlsVisibility();
        });
    
        document.getElementById('btn-add-sum')?.addEventListener('click', () => {
            if (visibleSums < currentMaxLocs) {
                visibleSums++;
                document.getElementById('city-summary-block-' + visibleSums).style.display = 'block';
            }
            updateControlsVisibility();
        });
        document.getElementById('btn-remove-sum')?.addEventListener('click', () => {
            if (visibleSums > 1) {
                const block = document.getElementById('city-summary-block-' + visibleSums);
                block.style.display = 'none';
                block.querySelectorAll('input').forEach(i => { if(i.type === 'checkbox') i.checked = false; else i.value = ''; });
                visibleSums--;
                refreshPreviews();
            }
            updateControlsVisibility();
        });
    
        document.getElementById('btn-add-header-city')?.addEventListener('click', () => {
            if (visibleHeaderCities < currentMaxLocs) {
                visibleHeaderCities++;
                document.getElementById('city-header-block-' + visibleHeaderCities).style.display = 'block';
            }
            updateControlsVisibility();
        });
        document.getElementById('btn-remove-header-city')?.addEventListener('click', () => {
            if (visibleHeaderCities > 1) {
                document.getElementById('city-header-block-' + visibleHeaderCities).style.display = 'none';
                document.getElementById('city-header-block-' + visibleHeaderCities).querySelectorAll('input').forEach(i => i.value = '');
                visibleHeaderCities--;
                refreshPreviews();
            }
            updateControlsVisibility();
        });
    
        // --- Превью ---
        let previewDebounceTimer;
        function fetchPreview(creativeName, imgElement) {
            const formData = new FormData(form);
            formData.append('creative_preview', creativeName);
            imgElement.style.opacity = '0.4';
            fetch('preview.php', { method: 'POST', body: formData })
            .then(r => r.ok ? r.blob() : Promise.reject())
            .then(blob => {
                const url = URL.createObjectURL(blob);
                if (imgElement.dataset.blobUrl) URL.revokeObjectURL(imgElement.dataset.blobUrl);
                imgElement.dataset.blobUrl = url;
                imgElement.src = url;
                imgElement.style.opacity = '1';
            }).catch(() => imgElement.style.opacity = '1');
        }
    
        function refreshPreviews() {
            clearTimeout(previewDebounceTimer);
            previewDebounceTimer = setTimeout(() => {
                let hasData = inputs.some(i => i.value.trim() !== '' && i.type !== 'checkbox' && i.id !== 'selected-creatives');
                document.querySelectorAll('.btn-toggle').forEach(card => {
                    const img = card.querySelector('img');
                    if (card.classList.contains('active') && hasData) {
                        fetchPreview(card.dataset.creative, img);
                    } else {
                        if(img.src !== img.dataset.originalSrc) img.src = img.dataset.originalSrc;
                    }
                });
            }, 600);
        }
    
        inputs.forEach(i => i.addEventListener('input', refreshPreviews));
        
        // --- Логика Нового Горизонтального Списка ---
        let visibleHSpringCities = 1;
        
        document.getElementById('btn-add-h-city').addEventListener('click', () => {
            if (visibleHSpringCities < 10) {
                visibleHSpringCities++;
                document.getElementById('spring-h-city-' + visibleHSpringCities).style.display = 'block';
                document.getElementById('btn-remove-h-city').style.display = 'block';
            }
            if (visibleHSpringCities === 10) document.getElementById('btn-add-h-city').style.display = 'none';
        });
        
        document.getElementById('btn-remove-h-city').addEventListener('click', () => {
            if (visibleHSpringCities > 1) {
                const block = document.getElementById('spring-h-city-' + visibleHSpringCities);
                block.style.display = 'none';
                block.querySelectorAll('input:not([type="radio"])').forEach(i => i.value = '');
                visibleHSpringCities--;
                document.getElementById('btn-add-h-city').style.display = 'block';
                refreshPreviews();
            }
            if (visibleHSpringCities === 1) document.getElementById('btn-remove-h-city').style.display = 'none';
        });
        
        document.querySelectorAll('.btn-add-h-addr').forEach(btn => {
            btn.addEventListener('click', function() {
                const cityIdx = this.dataset.city;
                const container = document.getElementById('spring-h-addresses-' + cityIdx);
                const visible = Array.from(container.querySelectorAll('.spring-h-addr-item')).filter(i => i.style.display !== 'none').length;
                if (visible < 20) {
                    document.getElementById(`spring-h-addr-${cityIdx}-${visible + 1}`).style.display = 'block';
                    this.parentElement.querySelector('.btn-remove-h-addr').style.display = 'inline-flex';
                }
                if (visible + 1 === 20) this.style.display = 'none';
            });
        });
        
        document.querySelectorAll('.btn-remove-h-addr').forEach(btn => {
            btn.addEventListener('click', function() {
                const cityIdx = this.dataset.city;
                const container = document.getElementById('spring-h-addresses-' + cityIdx);
                const visible = Array.from(container.querySelectorAll('.spring-h-addr-item')).filter(i => i.style.display !== 'none').length;
                if (visible > 1) {
                    const block = document.getElementById(`spring-h-addr-${cityIdx}-${visible}`);
                    block.style.display = 'none';
                    block.querySelector('input').value = '';
                    this.parentElement.querySelector('.btn-add-h-addr').style.display = 'inline-flex';
                    refreshPreviews();
                }
                if (visible - 1 === 1) this.style.display = 'none';
            });
        });
        
    
        // --- Обновление состояния полей ---
        function updateState() {
            const activeCards = document.querySelectorAll('.btn-toggle.active');
            const selected = Array.from(activeCards).map(c => c.dataset.creative);
            hiddenInput.value = selected.join(',');

            Object.values(fieldGroups).forEach(g => { if(g) g.style.display = 'none'; });
            
            const containers = ['group-locations', 'group-city-summary', 'group-city-list-header', 'group-spring-list', 'group-spring-h-list'];
            containers.forEach(id => {
                const el = document.getElementById(id);
                if(el) el.style.display = 'none';
            });

            // Логика выпадающего списка вариантов
            const variantGroup = document.getElementById('group-variant-select');
            
            // Если активна ровно ОДНА карточка и она групповая
            if (activeCards.length === 1 && activeCards[0].classList.contains('group-card')) {
                variantGroup.style.display = 'block';
                
                // Наполняем селект вариантами, если он еще не наполнен для этой карточки
                const card = activeCards[0];
                const variants = JSON.parse(card.dataset.variants || '{}');
                
                // Чтобы не перерисовывать постоянно, проверяем текущее состояние
                if (globalVariantSelect.dataset.currentOwner !== card.dataset.creative) {
                    globalVariantSelect.innerHTML = '';
                    Object.entries(variants).forEach(([vName, vData]) => {
                        const opt = document.createElement('option');
                        opt.value = vName;
                        opt.textContent = vData.variant_name || vName;
                        opt.dataset.fields = JSON.stringify(vData.fields || []);
                        opt.dataset.limit = vData.max_locations_limit || 4;
                        opt.dataset.img = 'templates/' + vData.template;
                        if (vName === card.dataset.creative) opt.selected = true;
                        globalVariantSelect.appendChild(opt);
                    });
                    globalVariantSelect.dataset.currentOwner = card.dataset.creative;
                }
            } else {
                variantGroup.style.display = 'none';
                globalVariantSelect.dataset.currentOwner = '';
            }

            let activeFields = new Set();
            let absoluteLimit = 4;

            activeCards.forEach(card => {
                const fields = JSON.parse(card.getAttribute('data-fields') || '[]');
                fields.forEach(f => activeFields.add(f));
                const limit = parseInt(card.getAttribute('data-limit')) || 4;
                if (limit < absoluteLimit) absoluteLimit = limit;
            });

            currentMaxLocs = absoluteLimit;

            let needsCitySummary = false, needsLocations = false, needsCityListHeader = false, needsSpringList = false, needsHSpringList = false;

            activeFields.forEach(f => {
                if (fieldGroups[f]) {
                    if (f === 'noticebadge') {
                        const badgeCheckbox = document.getElementById('h_showOpeningBadge');
                        fieldGroups[f].style.display = 'flex';
                        if (badgeCheckbox && badgeCheckbox.checked) {
                            if (fieldGroups['city']) fieldGroups['city'].style.display = 'flex';
                            if (fieldGroups['address']) fieldGroups['address'].style.display = 'flex';
                        }
                    } else {
                        fieldGroups[f].style.display = 'flex';
                    }
                }
                
                if (f === 'city_summary') needsCitySummary = true;
                if (f === 'main_header' || f === 'city_list_header') needsCityListHeader = true;
                if (f === 'spring_list') needsSpringList = true;
                if (f === 'spring_h_list') needsHSpringList = true;
                if (/^(address|time|weekDays|locCity)\d+/.test(f) || f.startsWith('time') || f.startsWith('weekDays') || f.startsWith('locCity')) {
                    needsLocations = true;
                }
            });

            if (needsLocations) document.getElementById('group-locations').style.display = 'block';
            if (needsCitySummary) document.getElementById('group-city-summary').style.display = 'block';
            if (needsCityListHeader) document.getElementById('group-city-list-header').style.display = 'block';
            if (needsSpringList) document.getElementById('group-spring-list').style.display = 'block';
            if (needsHSpringList) document.getElementById('group-spring-h-list').style.display = 'block';
            
            const isSpecialLayout = Array.from(activeCards).some(c => c.dataset.creative === 'Режим Работы Главная');
            const timeInput1 = document.getElementById('time1'); 
            const timeInput5 = document.getElementById('time5'); 
            if (timeInput1 && timeInput5) {
                if (isSpecialLayout) {
                    timeInput1.placeholder = "10:00-22:00";
                    timeInput5.placeholder = "11:00-19:00";
                } else {
                    timeInput1.placeholder = "10:00 - 22:00";
                    timeInput5.placeholder = "10:00 - 18:00";
                }
            }

            updateControlsVisibility();
            refreshPreviews();
        }

        // Слушатель для глобального селектора вариантов
        globalVariantSelect.addEventListener('change', function() {
            const activeCard = document.querySelector('.btn-toggle.active.group-card');
            if (!activeCard) return;

            const selectedOption = this.options[this.selectedIndex];
            
            // Обновляем данные в карточке
            activeCard.dataset.creative = this.value;
            activeCard.dataset.fields = selectedOption.dataset.fields;
            activeCard.dataset.limit = selectedOption.dataset.limit;
            
            // Обновляем картинку-превью в карточке
            const img = activeCard.querySelector('img');
            img.dataset.originalSrc = selectedOption.dataset.img;
            
            // Если полей ввода нет, просто меняем src. Если есть - updateState сам подгрузит превью
            img.src = selectedOption.dataset.img;

            updateState();
        });
        
        toggles.forEach(t => t.addEventListener('click', function() {
            // Если мы кликаем по карточке, а она уже активна — это выключение.
            // Если кликаем по другой — выключаем остальные (для групп удобно работать с одной за раз)
            if (this.classList.contains('group-card')) {
                const wasActive = this.classList.contains('active');
                // Опционально: если хотите, чтобы одновременно была только одна группа
                // toggles.forEach(el => el.classList.remove('active'));
                // if (!wasActive) this.classList.add('active');
            }
            this.classList.toggle('active');
            updateState();
        }));
    
        selectAllBtns.forEach(b => b.addEventListener('click', function() {
            const section = this.dataset.section;
            const sectionToggles = document.getElementById('section-' + section).querySelectorAll('.btn-toggle');
            const allSelected = Array.from(sectionToggles).every(t => t.classList.contains('active'));
            sectionToggles.forEach(t => t.classList.toggle('active', !allSelected));
            updateState();
        }));
    
        form.addEventListener('submit', e => {
            if (!hiddenInput.value) {
                e.preventDefault();
                alert('Выберите макет');
            }
        });
        
        document.getElementById('h_showOpeningBadge')?.addEventListener('change', () => updateState());

    </script>
</body>
</html>