#!/usr/bin/env python3
import sys
import json
import os
import traceback

# Настраиваем прямой логгер в обход PHP
LOG_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'python_debug_fonttools.log')

def log_debug(msg):
    try:
        with open(LOG_FILE, 'a', encoding='utf-8') as f:
            f.write(msg + "\n")
    except:
        pass

try:
    sys.stdout.reconfigure(encoding='utf-8')
except Exception:
    pass

try:
    log_debug(f"--- Запуск Python ---")
    log_debug(f"Аргументы: {sys.argv}")
    
    from fontTools.ttLib import TTFont
    from fontTools.pens.svgPathPen import SVGPathPen
    from fontTools.pens.transformPen import TransformPen

    def convert_to_paths(json_path):
        log_debug(f"Читаем JSON: {json_path}")
        with open(json_path, 'r', encoding='utf-8') as f:
            data = json.load(f)

        fonts_cache = {}
        output = []

        for item in data:
            text = item.get('text', '')
            font_path = item.get('font', '')
            size = item.get('size', 12)
            x = item.get('x', 0)
            y = item.get('y', 0)
            color = item.get('color', '#000000')
            opacity = item.get('opacity', 1.0)
            angle = item.get('angle', 0)

            log_debug(f"Текст: '{text}', Шрифт: {font_path}")

            if not text or not font_path:
                log_debug("-> Пропуск: пустой текст или нет пути к шрифту")
                continue
            
            # ИСПРАВЛЕНИЕ: Проверяем, что это ИМЕННО ФАЙЛ, а не папка
            if not os.path.isfile(font_path):
                log_debug(f"-> Пропуск: файл шрифта НЕ НАЙДЕН или ЭТО ПАПКА: {font_path}")
                continue
            
            if font_path not in fonts_cache:
                log_debug(f"-> Загрузка шрифта в память: {font_path}")
                fonts_cache[font_path] = TTFont(font_path)
            
            font = fonts_cache[font_path]
            cmap = font.getBestCmap()
            glyphSet = font.getGlyphSet()
            hmtx = font['hmtx'].metrics
            upm = font['head'].unitsPerEm

            px_size = size * 1.3333333
            scale = px_size / upm

            fill_op = f' fill-opacity="{opacity}"' if opacity < 1.0 else ''
            transform = f' transform="rotate({-angle}, {x}, {y})"' if angle != 0 else ''
            
            output.append(f'<g fill="{color}"{fill_op}{transform}>')
            
            curr_x = x
            for char in text:
                char_code = ord(char)
                gname = cmap.get(char_code)
                
                if not gname and char == ' ':
                    gname = 'space'
                if not gname:
                    gname = cmap.get(0xFFFD) 
                    
                if not gname or gname not in hmtx:
                    continue
                
                adv = hmtx[gname][0] * scale
                
                if hasattr(glyphSet[gname], 'draw'):
                    pen = SVGPathPen(glyphSet)
                    tpen = TransformPen(pen, (scale, 0, 0, -scale, curr_x, y))
                    glyphSet[gname].draw(tpen)
                    commands = pen.getCommands()
                    if commands:
                        output.append(f'  <path d="{commands}"/>')
                
                curr_x += adv
                
            output.append('</g>')
            
        result = "\n".join(output)
        log_debug(f"-> Успешно переведено в кривые. Размер: {len(result)} байт")
        return result

    if __name__ == "__main__":
        if len(sys.argv) > 1:
            res = convert_to_paths(sys.argv[1])
            print(res)
            sys.stdout.flush()
        else:
            log_debug("-> Ошибка: не передан путь к JSON файлу.")
            print("")
            sys.exit(1)

except Exception as e:
    err_msg = traceback.format_exc()
    log_debug(f"ФАТАЛЬНАЯ ОШИБКА PYTHON:\n{err_msg}")
    print(f"")
    sys.exit(1)