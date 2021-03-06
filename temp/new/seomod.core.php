<?php
/*
 * Функция инициализации
 */
function smInit() {
    $_SERVER['SEOMOD_CONFIG'] = smLoadConfig();
    // Проверим что не 404
    if ($rule = smNotFoundMod()) {
        if (SEOMOD_BITRIX) {
            ob_end_clean();
        }
        if (isset($rule['@attributes']['redirect'])) {
            define('SEOMOD_REDIRECT', $rule['@attributes']['redirect']);
            if ($rule['@attributes']['code']) {
                smSendCode($rule['@attributes']['code']);
            } else {
                smSendCode(301);
            }
        } else {
            smSendCode(404);
            if (defined('SEOMOD_404_HANDLER')) {
                include(SEOMOD_404_HANDLER);
            } else {
                echo 'Not Found';
            }
        }
        die;
    }
    if ($_SERVER['SEOMOD_RULE'] = smGetRules()) {
        smCacheMod();
        smRedirectMod();
        smLastMod();
    } elseif(defined('SEOMOD_REDIRECT')) {
        smSendCode(301);
        die;
    }
}

/*
 * Функция проверяющая не имеется ли уже 404 заголовка.
 */
function already_404() {
    if( $head_res = curl_init() ) {
        curl_setopt($head_res,CURLOPT_URL,'http://'.SEOMOD_HOST.SEOMOD_URI);
        curl_setopt($head_res,CURLOPT_RETURNTRANSFER,true);
        curl_setopt($head_res,CURLOPT_NOBODY,true);
        curl_setopt($head_res,CURLOPT_HEADER,true);
        $res = curl_exec($head_res);
        curl_close($head_res);
        // если родноая 404 уже работает, не будем отрабатывать сеомод
        if(preg_match('/404 Not Found/',$res)) {
            return true;
        }
    }
    return false;
}

/*
 * Функция получает контент и обрабатывает его в соотвтетствии с правилами
 */
function smLoad() {
    $buffer = ob_get_contents();
    ob_end_clean();
    smProcess($buffer);
    smProcessHeaders();
    if (defined('SEOMOD_CACHE_UPDATE')) {
        smCreateCache($buffer);
    }
    die($buffer);
}

/*
 * Функция обрабатывает вывод
 */
function smProcess(&$buffer) {
    // Заменяем тайтл
    smProcessTitle($buffer);
    // Заменяем метатеги
    smProcessMeta($buffer);
    // Очищаем код от мусора
    smProcessStrip($buffer);
    // Заменяем части кода
    smProcessReplacement($buffer);
    // Заменяем ссылки
    smProcessLinks($buffer);
}

/*
 * Функция обрабатывает заголовки
 */
function smProcessHeaders() {
    // Правим заголовки только если правило обработки для этой страницы
    if ($_SERVER['SEOMOD_RULE']) {
        if (defined('SEOMOD_ERROR')) {
            smSendCode(SEOMOD_ERROR);
        }
        else {
            smSendCode(200);
        }
    }
}

//-----------------------------Модули обработки ----------------------------------------------------


/*
 * Модуль кеширования
 */
function smCacheMod() {
    $rule = $_SERVER['SEOMOD_RULE'];
    // Если включен кеш и расписание позволяет его использовать
    if (isset($rule['cache']) && isset($rule['cache']['@attributes']['schedule']) &&
        smCheckCron($rule['cache']['@attributes']['schedule'])
    ) {
        // Проверим есть ли файл кеша. Если нет, то ставим флаг на создание
        if  (!is_file(SEOMOD_CACHEDIR.SEOMOD_URI_HASH)) {
            define('SEOMOD_CACHE_UPDATE', 1);
        } else {
            // Проверим нужно ли обновить кеш
            if (isset($rule['cache']['@attributes']['lifetime']) &&
                smCheckCache($rule['cache']['@attributes']['lifetime'])
            ) {
                define('SEOMOD_CACHE_UPDATE', 1);
            }
            // Если кеш есть и не нуждается в обновлении, то ставим флаг об использовании кеша
            else {
                define('SEOMOD_USE_CACHE', 1);
            }
        }
    }
}

/*
 * Модуль редиректов
 */
function smRedirectMod() {
    $rule = $_SERVER['SEOMOD_RULE'];
    // Если включен редирект
    if (isset($rule['@attributes']['redirect'])) {
        // Если есть набор get параметров то формируем на основе их
        if (isset($rule['@attributes']['query'])) {
            smFakeEnv(
                $rule['@attributes']['redirect'].'?'.
                $rule['@attributes']['query']
            );
        } else {
            // Если задан абсолютный url
            smFakeEnv($rule['@attributes']['redirect']);
        }
    }
}

/*
 * Модуль ластмода
 */
function smLastMod() {
    $rule = $_SERVER['SEOMOD_RULE'];
    if (isset($rule['lastmod'])) {
        smSendLastMod($rule['lastmod']['@attributes']['schedule']);
    }
}

/*
 * Модуль обработки 404
 */
function smNotFoundMod() {
    $config = $_SERVER['SEOMOD_CONFIG'];
    // Если указан скрипт обработки 404
    if (isset($config['notfound']) && isset($config['notfound']['@attributes']['script'])) {
        $script = $_SERVER['DOCUMENT_ROOT'].$config['notfound']['@attributes']['script'];
        // Если скрипт обработки существует
        if (is_file($script)) {
            define('SEOMOD_404_HANDLER', $script);
            $rules = $config['notfound'];
            // Если правило одно, то обернем в массив
            if (!isset($rules['rule'][0])) {
                $rules = array($rules['rule']);
            } else {
                $rules = $rules['rule'];
            }

            $url = @parse_url(SEOMOD_URI);
            // Найдем правила, применяемые для страницы
            if (!is_null($rules)) {
                foreach($rules as $rule) {
                    if (isset($rule['@attributes']['disabled']) && $rule['@attributes']['disabled'] == '1') {}
                    else {
                        // Если задан набор гет параметров и совпадает с текущим
                        if ($url['path'] == $rule['@attributes']['url'] && isset($rule['@attributes']['query']) &&
                            smCheckQuery($rule['@attributes']['query'])
                        ) {
                            return $rule;
                        }
                        // Если нашли для нашего url
                        if ($rule['@attributes']['url'] == SEOMOD_URI) {
                            return $rule;
                        }
                        // И наконец если есть выражение, то обработаем его
                        if (isset($rule['@attributes']['exp']) &&
                            @preg_match("~{$rule['@attributes']['exp']}~", SEOMOD_URI)
                        ) {
                            return $rule;
                        }
                    }
                }
            }
        }
    }
    return false;
}


//------------------------Работа с контетном-------------------------------------------------------

/*
 * Функция чистит код
 */
function smProcessStrip(&$buffer) {
    $strip = $_SERVER['SEOMOD_CONFIG']['options']['strip'];
    // Если в конфиге разрешен стрип
    if ($strip['@content'] == 1) {
        // Если можно стрипать лишние пробелы
        if ($strip['@attributes']['extraspaces'] == 1) {
            $buffer = preg_replace('~[\t ]+~', ' ', $buffer);
            $buffer = preg_replace('~^[\t ]+~m', '', $buffer);
            $buffer = preg_replace('~> <~', '><', $buffer);
        }
        // Если можно стрипать пустые строки
        if ($strip['@attributes']['extralines'] == 1) {
            $buffer = preg_replace('~[\n\r]{2,}~', "\n", $buffer);
        }
        // Если можно стрипать коменты
        if ($strip['@attributes']['comments'] == 1) {
            $buffer = preg_replace('~<!--[^>]+-->~', '', $buffer);
        }
        // Если можно склеить в одну строку
        if ($strip['@attributes']['oneline'] == 1) {
            $buffer = preg_replace('~[\r\n]+~', '', $buffer);
        }
    }
}

/*
 * Функция формирует метатеги
 */
function smProcessMeta(&$buffer) {
    $rule = $_SERVER['SEOMOD_RULE'];
    $config = $_SERVER['SEOMOD_CONFIG'];
    // Если XHTML, то надо валидно закрывать метатеги
    if (preg_match('~<!DOCTYPE.*XHTML[^>]*>~i', $buffer, $m)) {
        $close_tag = ' />';
    } else {
        $close_tag = '>';
    }
    $allowed_meta = $_SERVER['SEOMOD_CONFIG']['options']['allowedmeta']['meta'];
    if (count($allowed_meta) == 1) {
        $allowed_meta = array($allowed_meta);
    }
    $block_meta = array();
    // Если на странице уже есть метатеги
    if (preg_match_all('~<meta.*?name=[\'\"]+([^\'\"]+)[\'\"]+[^>]*>~', $buffer, $metatags)) {
        // Пройдемся по списку разрешенных мета из конфига и проверим есть ли они на странице
        foreach($allowed_meta as $meta) {

            // Добавил проверку тега с атрибутом отличным от name
            if(isset($meta['@attributes']['name'])) $tag_name = 'name';
            elseif(isset($meta['@attributes']['http-equiv'])) $tag_name = 'http-equiv';
            // Поищем есть ли нужный тег на странице
            $key = array_search($meta['@attributes']["$tag_name"], $metatags[1]);

            // Если есть замена
            if ($rule && isset($rule[$meta['@attributes']["$tag_name"]])) {
                $content = $rule[$meta['@attributes']["$tag_name"]];
            }
            // Если есть на странице
            elseif ($key !== false && preg_match('~content=[\'\"]+([^\'\"]+)[\'\"]+~i', $metatags[0][$key], $m)) {
                $content = $m[1];
            }
            // Если нигде не задан то берем из конфига общий
            else {
                $content = isset($meta['@content'])?$meta['@content']:'';
            }
            
            // если есть метка *!H1!*, то меняем ее на заголовок
            $content = smInsertH1 ( $buffer, $content );

            // Сформируем строчку meta
            $block_meta[] = '<meta name="'.$meta['@attributes']["$tag_name"].'" content="'.$content.'"'.$close_tag;           
        
        }
    }
    // Добавим кодировку
    if(SEOMOD_HTML_VERSION == 5) {
        $block_meta[] = '<meta charset='.SEOMOD_CHARSET.'"'.$close_tag;
    }
    else $block_meta[] = '<meta http-equiv="content-type" content="text/html; charset='.SEOMOD_CHARSET.'"'.$close_tag;
    // Старые метатеги нам не нужны
    $buffer = preg_replace('~<meta.*?(name|http-equiv|charset)=.?([\w]+).?[^>]*>~', '', $buffer);

    // Добавим блок мета к тайтлу
    preg_match('~<\/title>~i', $buffer, $title);
    if(isset($title[0]))
        $buffer = str_replace($title[0], "{$title[0]}\n".implode("\n", $block_meta), $buffer);

}





/*
 * Функция получения заголовка h1 (new)
 */
function smGetH1 ( $buf ) {
    $h1 = false;
        if ( preg_match( '~<h1 class="title">.*</h1>~i', $buf, $res) ) {
            $h1 = $res[0];
        }
    $beginPos = strpos( $h1, '<h1 class="title">')+strlen('<h1 class="title">');
    $endPos = strpos( $h1, '</h1>');

    $h1 = substr($h1, $beginPos, $endPos-$beginPos);

    // заменяем первый символ на строчную букву
    $first = mb_substr($h1, 0, 1, 'UTF-8');
    $first = mb_convert_case( $first, MB_CASE_LOWER );
    $h1 = substr($h1, 2, strlen( $h1 ) );
    $h1 = $first.$h1;


    return $h1; 
}

/*
 * Функция замены метки на значение заголовка h1 (new)
 */
function smInsertH1 ( $buf, $rul ) {
            // Если странице есть h1, берем его 
            if ( $h1 = smGetH1( $buf ) ){              
                // Если в правиле есть метка для h1, то меняем 
                if ( strpos($rul, '*!H1!*') ) {
                    $rul = str_replace( '*!H1!*', $h1, $rul );
                }
            } else {
                $rul = str_replace( '*!H1!*', '', $rul );
            }
    return $rul;        
}

/*
 * Функция заменяет или создает тайтл =)
 */
function smProcessTitle(&$buffer) {
    $rule = $_SERVER['SEOMOD_RULE'];
    if ($rule && isset($rule['title'])) {

            // вставка h1
            $rule['title'] = smInsertH1 ( $buffer, $rule['title'] );        

        // Если title есть уже, то просто заменяем его
        if (preg_match('~<title>.*</title>~i', $buffer, $title)) {
            $buffer = str_replace($title[0], "<title>{$rule['title']}</title>", $buffer);
        }
        // Если нет, то приписывает к head
        else {
            preg_match('~<head[^>]*>~i', $buffer, $head);
            $buffer = str_replace($head[0], "{$head[0]}\n<title>{$rule['title']}</title>", $buffer);
        }
    }
}


// /*
//  * Функция заменяет или создает тайтл =)
//  */
// function smProcessTitle(&$buffer) {
//     $rule = $_SERVER['SEOMOD_RULE'];
//     if ($rule && isset($rule['title'])) {
//         // Если title есть уже, то просто заменяем его
//         if (preg_match('~<title>.*</title>~i', $buffer, $title)) {

//             $buffer = str_replace($title[0], "<title>{$rule['title']}</title>", $buffer);
//         }
//         // Если нет, то приписывает к head
//         else {
//             preg_match('~<head[^>]*>~i', $buffer, $head);
//             $buffer = str_replace($head[0], "{$head[0]}\n<title>{$rule['title']}</title>", $buffer);
//         }
//     }
// }

/*
 * Функция заменяет код
 */
function smReplace(&$buffer, &$rule) {
    if (isset($rule['@attributes']['regexp']) && $rule['@attributes']['regexp'] == '1') {        
        // Декодируем html символы в строках замены, которые перекодировались при импорте из .xml
        if (is_array($rule['replace'])){
          foreach ($rule['replace'] as $key => $value) {
           $rule['replace'][$key] = html_entity_decode(stripslashes($value)); 
          }
        }else{
          $rule['replace'] = html_entity_decode(stripslashes($rule['replace']));
        }
        
        if(is_array($rule['find'])) {
            foreach ($rule['find'] as $key => $f) {
                //$rule['find'][$key] = '~'.addslashes($f).'~is';
                $rule['find'][$key] = '~'.$f.'~is';
            }
		}
        elseif(is_string($rule['find'])) {
            //$rule['find'] = '~'.addslashes($rule['find']).'~is';
            $rule['find'] = '~'.$rule['find'].'~is';
		}
        $buffer = preg_replace($rule['find'], $rule['replace'], $buffer);
    } else {
        $buffer = str_replace($rule['find'], $rule['replace'], $buffer);
    }
}


/*
 * Функция получает правила замены кода
 */
function smProcessReplacement(&$buffer) {
    if (isset($_SERVER['SEOMOD_CONFIG']['replacement'])) {
        $rules = $_SERVER['SEOMOD_CONFIG']['replacement'];
        // Если правило одно, то обернем в массив
        if (!isset($rules['rule'][0])) {
            $rules = array($rules['rule']);
        } else {
            $rules = $rules['rule'];
        }
        $url = parse_url(SEOMOD_URI);
        // Найдем правила, применяемые для страницы
        foreach($rules as $rule) {
            if (isset($rule['@attributes']['disabled']) && $rule['@attributes']['disabled'] == '1') {}
            else {
                // Если задан набор гет параметров и совпадает с текущим
                if (isset($rule['@attributes']['url']) && $url['path'] == $rule['@attributes']['url'] && isset($rule['@attributes']['query']) &&
                    smCheckQuery($rule['@attributes']['query'])
                ) {
                    smReplace($buffer, $rule);
                }
                // Если нашли для нашего url
                if (isset($rule['@attributes']['url']) && $rule['@attributes']['url'] == SEOMOD_URI) {
                    smReplace($buffer, $rule);
                }
                // И наконец если есть выражение, то обработаем его
                if (isset($rule['@attributes']['exp']) &&
                    @preg_match("~{$rule['@attributes']['exp']}~", SEOMOD_URI)
                ) {
                    smReplace($buffer, $rule);
                }
            }
        }
    }
    return false;
}


/*
 * Функция заменяет ссылки
 */
function smProcessLinks(&$buffer) {
    $rules = $_SERVER['SEOMOD_CONFIG']['rules'];
    // Если правило одно, то обернем в массив
    if (count($rules['rule']) == 1) {
        $rules = array($rules['rule']);
    } else {
        $rules = $rules['rule'];
    }
    if (!is_null($rules)) {
        $result = array();
        // Облагораживаемый хост
        $host = ltrim(stripslashes(SEOMOD_HOST), 'www.');
        // Если вообще на странице есть ссылки, то проверим нужно ли чтото заменять
        if (preg_match_all('~(href|src)=([\'\"]){1}(.*?)[\'\"]{1}~mi', $buffer, $match_links)) {
            // Пройдемся по всем ссылкам на сайте
            foreach ($match_links[3] as $key => $link) {
                $delim = $match_links[2][$key];
                if (trim($link) != '' && smCheckExcept($link)) {
                    // Найдем правила, применяемые для страницы
                    foreach($rules as $rule) {
                        // Если есть редирект, значит надо делать замену ссылок
                        if (isset($rule['@attributes']['disabled']) &&
                            $rule['@attributes']['disabled'] == '1') {}
                        else {
                            if (isset($rule['@attributes']['redirect'])) {
                                if (isset($rule['@attributes']['link'])) {
                                    $tmp_url = strtolower($rule['@attributes']['link']);
                                } else {
                                    $tmp_url = strtolower($rule['@attributes']['redirect']);
                                    if (isset($rule['@attributes']['query'])) {
                                        $tmp_url .= '?'.$rule['@attributes']['query'];
                                    }
                                }
                                $url = parse_url($tmp_url);
                                // Парсим заменяемый url
                                if (isset($url['query'])) {
                                    $url_get = array();
                                    parse_str($url['query'], $url_get);
                                }
                                $replace = true;
                                // Парсим найденный урл
                                $target = parse_url(strtolower(html_entity_decode($link)));
                                if (isset($target['host'])) {
                                    $target['host'] = ltrim($target['host'], 'www.');
                                }
                                // Проверим host если есть
                                if (isset($target['host']) && $target['host'] != "" && $target['host'] != $host) {
                                    $replace = false;
                                }
                                if (!isset($target['path'])) {
                                    $replace = false;
                                }
                                // Проверим path
                                elseif (isset($target['path']) && $target['path'] != $url['path']) {
                                    $replace = false;
                                }
                                // Проверим query
                                if (isset($target['query']) && isset($url['query'])) {
                                    // Получим набор переменных из query
                                    $target_get = array();
                                    parse_str($target['query'], $target_get);
                                    // Смотрим разницу между массивом переменных
                                    $diff = array_diff_assoc($url_get, $target_get);
                                    if (count($diff) != 0) {
                                        $replace = false;
                                    }
                                    $diff = array_diff_assoc($target_get, $url_get);
                                    if (count($diff) > count($target_get)) {
                                        $replace = false;
                                    }
                                } elseif (isset($url['query']) && $url['query'] != '') {
                                    $replace = false;
                                }
                                // Если ни одно условие не противоречит, меняем ссылку
                                if ($replace) {
                                    $buffer = str_replace("$link$delim", "{$rule['@attributes']['url']}$delim", $buffer);
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

//------------------------Работа с конфигом-------------------------------------------------------

/*
 * Функция загружает xml конфиг и возвращает SimpleXML объект
 */
function smLoadConfig($fixcharset = true) {
    if (is_file(SEOMOD_DIR.'config.xml') && is_readable(SEOMOD_DIR.'config.xml')) {
        include SEOMOD_DIR.'simplexml.class.php';
        $sxml = new simplexml;
    } else {
        die('SM: Error loading config');
    }
    if ($conf = $sxml->xml_load_file(SEOMOD_DIR.'config.xml', 'array')) {
        // Опредлим сразу кодировку
        if (isset($conf['options']['charset'])) {
            define('SEOMOD_CHARSET', $conf['options']['charset']);
        }
        else {
            define('SEOMOD_CHARSET', SEOMOD_DEFAULT_CHARSET);
        }
        // Определяем версию HTML
        if (isset($conf['options']['html_version'])) {
            define('SEOMOD_HTML_VERSION', $conf['options']['html_version']);
        }
        else {
            define('SEOMOD_HTML_VERSION', SEOMOD_DEFAULT_HTML_VERSION);
        }
        // Если кодировка не юникод, то надо перекодировать конфиг
        if ($fixcharset && strtolower(SEOMOD_CHARSET) != 'utf-8') {
            $conf = smCharsetFix($conf);
        }
        return $conf;
    } else {
        die('SM: Invalid config');
    }
}

/*
 * Функция получает правила  конфига
 */
function smGetRules() {
    $rules = $_SERVER['SEOMOD_CONFIG']['rules'];
    // Если правило одно, то обернем в массив
    if (count($rules['rule']) == 1) {
        $rules = array($rules['rule']);
    } else {
        $rules = $rules['rule'];
    }
    if (!is_null($rules)) {
        // Найдем правила, применяемые для страницы
        foreach($rules as $rule) {
            if (isset($rule['@attributes']['disabled']) && $rule['@attributes']['disabled'] == '1') {}
            else {
                // Проверим на обратный редирект
                if (isset($rule['@attributes']['redirect'])) {
                    // Если задан абсолютный редирект или набор get параметров
                    if (strtolower($rule['@attributes']['redirect']) == strtolower(SEOMOD_URI) ||
                        (isset($rule['@attributes']['query']) &&
                        smCheckQuery($rule['@attributes']['query']) && smCheckExcept(SEOMOD_URI))
                    ) {
                        define('SEOMOD_REDIRECT', $rule['@attributes']['url']);
                        return false;
                    }
                }
                // Если нашли правило для нашего SEOMOD_URI, возвращаем его
                if ($rule['@attributes']['url'] == SEOMOD_URI && smCheckExcept(SEOMOD_URI)) {
                    return $rule;
                }
                // И наконец если есть выражение, то обработаем его
                if (isset($rule['@attributes']['exp']) &&
                    @preg_match("~{$rule['@attributes']['exp']}~", SEOMOD_URI) &&
                    smCheckExcept(SEOMOD_URI)
                ) {
                    return $rule;
                }
            }
        }
    }
    return false;
}

//--------------------------Работа с кешем----------------------------------------------------------

/*
 * Функция формирует кеш
 */
function smCreateCache($buffer) {
    $cache_file = SEOMOD_CACHEDIR.SEOMOD_URI_HASH;
    if (is_file($cache_file)) {
        unlink($cache_file);
    }
    $cache = fopen($cache_file, 'w+');
    fwrite($cache, $buffer);
    fclose($cache);
    return true;
}

/*
 * Функция получает кеш
 */
function smGetCache() {
    $cache_file = SEOMOD_CACHEDIR.SEOMOD_URI_HASH;
    if (is_file($cache_file)) {
        include $cache_file;
        return true;
    }
    return false;
}

/*
 * Функция проверяет не вышел ли срок жизни кеша
 */
function smCheckCache($lifetime) {
    $cache_file = SEOMOD_CACHEDIR.SEOMOD_URI_HASH;
    $stat = stat($cache_file);
    // Получаем возраст кеша в минутах
    $age = (time() - $stat['mtime']);
    // Запомним сколько секунд назад создан кеш
    define('SEOMOD_CACHE_AGE', $age);
    // Переведем в минуты
    $age /= 60;
    // Если стар, если супер стар, то false
    if ($lifetime >= $age){
        return false;
    }
    return true;
}
//----------------------------Обработка заголовков--------------------------------------------------
/*
 * Last Modified
 */
function smSendLastMod($sch) {
    header('Last-Modified: '.date('D, d M Y H:i:s', smLastСron($sch)).' GMT');
}

/*
 * Заголовки в зависимости от кода
 */
function smSendCode($code) {
    switch($code) {
        case 200:
            header('HTTP/'.SEOMOD_HTTP_VERSION.' 200 Ok');
        break;
        case 404:
            header('HTTP/'.SEOMOD_HTTP_VERSION.' 404 Not Found');
        break;
        case 302:
            header('Location: '.SEOMOD_REDIRECT, true, 302);
        break;
        case 301:
            header('Location: '.SEOMOD_REDIRECT, true, 301);
        break;
    }
    header('Content-type: text/html; charset='.SEOMOD_CHARSET);
}

//-----------------------------Вспомогательные функции----------------------------------------------

/*
 * Функция проверяет соответствие unix-timestamp - шаблону в формате cron
 */
function smCheckCron($sch, $time=false) {
    // Если нет правки по времени
    if (!$time) {$time = time();}
    // Шаблон крон
    $cron_map = @explode(' ', $sch);
    // Разобьем текущую дату в соответствии с шаблоном крон
    $now = explode(' ', date('i H d m w Y', $time));
    // Проверим каждое правило. Далее поток сознания
    foreach ($cron_map as $key => $bit) {
        // Если в шаблоне *
        if ($bit == '*') {}
        // Если список
        elseif ((strpos($bit, ',') !== false ||
        strpos($bit, '-') !== false) && !strpos($bit, '/') !== false) {
            $a = explode(',', $bit);
            foreach ($a as $k => $v) {
                if (strpos($v, '-') !== false) {
                    $r = explode('-', $v);
                    $a[$k] = implode(',', range($r[0], $r[1]));
                }
            }
            if (!in_array($now[$key], explode(',', implode(',', $a)))) {
                return false;
            }
        }
        // Если просто число
        elseif (is_numeric($bit)) {
            if ($bit != $now[$key]) {
                return false;
            }
        }
        // Если задана кратность
        elseif (strpos($bit, '/') !== false) {
            $a = explode('/', $bit);
            if (strpos($a[0], '*') !== false) {
                if ($now[$key] % $a[1] != 0) {
                    return false;
                }
            } else {
                if ($now[$key] % $a[1] == 0) {
                    $r = explode('-', $a[0]);
                    if (!in_array($now[$key], explode(',', implode(',', range($r[0], $r[1]))))) {
                        return false;
                    }
                } else {
                    return false;
                }
            }
            $a = explode('/', $bit);
            if ($a[0] == '*') {
                if ($now[$key] % $a[1] != 0) {
                    return false;
                }
            }
        }
        else {
            die('SM: Error in cron format');
        }
    }
    return true;
}

/*
 * Функция возвращает timestamp последнего события по шаблону cron
 * Поддерживаются только простые правила, типо "10 21 * * 1 *"
 */
function smLastСron($schedule) {
    $time = time();
    // Разобьем крон строку на составляющие
    $cron = explode(' ', $schedule);
    // Разобьем текущую дату в соответствии с шаблоном крон
    $now = explode(' ', date('i H d m w Y'));
    // Колличество единиц в каждом временном классе
    $map = array(60, 24, date('t'), 12, 7, -1);
    // Вес единицы класса
    $t = array(60, 3600, 86400, 86400*date('t'), 86400, 0);
    $diff_timestamp = 0;
    // Сравним текущую дату с шаблоном крон
    foreach($cron as $key => $value) {
        $diff = 0;
        // Если в шаблоне не * и значения классов различны
        if ($value != '*' && $value != $now[$key]) {
            // Если значение в шаблоне меньше текущего, то просто найдем разницу
            if ($value < $now[$key]) {
                $diff = $now[$key] - $value;
            }
            // Иначе вычислим по формуле
            else {
                $diff = $now[$key] - $value + $map[$key];
            }
        }
        // Вычисляем вес временного различия
        $diff_timestamp += $t[$key] * $diff;
    }
    // Если минуты по шаблону убегают вперед, притормозим их
    if ($cron[0] > $now[0])
        $diff_timestamp -= 3600;
    // Возвращаем timestamp, обнулив секунды
    return $time - $diff_timestamp - $time % 60;
}

/*
 * Функция сверяет набор get параметров, заданные в query с текущими
 */
function smCheckQuery($query) {
    $get = array();
    parse_str($query, $get);
    foreach ($get as $key => $value) {
        if (isset($_GET[$key]) && $_GET[$key] == $value) {}
        else {
            return false;
        }
    }
    return true;
}

/*
 * Функция проверяет наличие запрещенных переменных
 */
function smCheckExcept($url) {
    $url = str_replace('&amp;', '&', $url);
    $options = $_SERVER['SEOMOD_CONFIG']['options'];
    if (isset($options['except'])) {
        $get = array();
        $url = explode('?', $url);
        if (isset($url[1])) {
            parse_str($url[1], $get);
            $get = array_keys($get);
            $excepts = explode(',', $options['except']);
            foreach($excepts as $except) {
                if (in_array($except, $get)) {
                    return false;
                }
            }
        }
    }
    return true;
}

/*
 * Функция подменяет окружение для виртуального ЧПУ
 */
function smFakeEnv($uri) {
    $url = @parse_url($uri);
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['REDIRECT_URL'] = $url['path'];
    if (!isset($url['query'])) {
        $url['query'] = '';
    }
    $_SERVER['REDIRECT_QUERY_STRING'] = $url['query'];
    $_SERVER['QUERY_STRING'] = $url['query'];
    parse_str($url['query'], $_GET);
    $_REQUEST = array_merge($_GET, $_POST);
}

/*
 * Функция перекодирует данные конфига в нужную кодировку
 */
function smCharsetFix(&$conf) {
    if (is_array($conf)) {
        foreach ($conf as $key => $element) {
            if (is_array($element)) {
                $conf[$key] = smCharsetFix($conf[$key]);
            } else {
                $conf[$key] = iconv("UTF-8", SEOMOD_CHARSET, $conf[$key]);
            }
        }
    } else {
        $conf[$key] = iconv("UTF-8", SEOMOD_CHARSET, $conf[$key]);
    }
    return $conf;
}

/*
 * Функция проверяет обновления
 */
function smCheckUpdate($mode) {
    echo "<pre>Check update...\n";
    $hash = trim(smHttpRequest(SEOMOD_REPO, "/repo/?host={$_SERVER['HTTP_HOST']}&mode=$mode"));
    switch ($mode) {
        case SEOMOD_UPDATE_CONFIG:
            $chash = md5_file('config.xml');
            echo "Need to update the config?\n";
            var_dump($hash !== $chash);
            if ($hash !== $chash) {
                smUpdateConfig();
                echo 'Updated';
            }
        break;
        case SEOMOD_UPDATE_CORE:
            $chash =  md5_file('seomod.core.php');
            echo "Need to upgrade core?\n";
            var_dump($hash !== $chash);
            if ($hash !== $chash) {
                smUpdateCore();
                echo 'Updated';
            }
        break;
    }
}

/*
 * Функция обновляет конфиг
 */
function smUpdateConfig() {
    if (!is_dir(SEOMOD_DIR.'backup')) {
        mkdir(SEOMOD_DIR.'backup');
        chmod(SEOMOD_DIR.'backup', 0755);
    }
    $backup =  SEOMOD_DIR.'backup/'.date('Y-m-d-h-i-s', time()).'_config.xml';
    copy(SEOMOD_DIR.'config.xml', $backup);
    chmod($backup, 0755);
    $file = fopen(SEOMOD_DIR.'config.xml', 'w');
    $update = smHttpRequest(SEOMOD_REPO,
        "/repo/?host={$_SERVER['HTTP_HOST']}&update=".SEOMOD_UPDATE_CONFIG
    );
    fwrite($file, $update);
    fclose($file);
}

/*
 * Функция обновляет ядро
 */
function smUpdateCore() {
    $file = fopen(SEOMOD_DIR.'seomod.core.php', 'w');
    $update = smHttpRequest(SEOMOD_REPO,
        "/repo/?host={$_SERVER['HTTP_HOST']}&update=".SEOMOD_UPDATE_CORE
    );
    fwrite($file, $update);
    fclose($file);
}

/*
 * Функция выполняет get запрос
 */
function smHttpRequest($host, $path) {
    $socket = fsockopen($host, 80);
    $header = "GET $path HTTP/1.0\n";
    $header.= "HOST: $host\n\n";
    fwrite($socket, $header);
    $raw = "";
    while (!preg_match("/\n\n/", $raw)) {
        $char = fgetc($socket);
        if ($char != "\r")
            $raw.= $char;
    }
    $headers = $raw;
    $content = "";
    while ($raw = fread($socket, 512)) {
        $content .= $raw;
    }
    fclose($socket);
    return $content;
}
?>
