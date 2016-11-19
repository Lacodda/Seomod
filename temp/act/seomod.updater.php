<?php
    ini_set('display_errors', 1);
    define('SEOMOD_DIR', $_SERVER['DOCUMENT_ROOT'].'/seomod/');
    define('SEOMOD_DEFAULT_CHARSET', 'utf-8');
    define('SEOMOD_HTTP_VERSION', '1.1');
    define('SEOMOD_UPDATE_CONFIG', 0);
    define('SEOMOD_UPDATE_CORE', 1);
    header('HTTP/'.SEOMOD_HTTP_VERSION.' 200 Ok');
    header('Content-type: text/html; charset='.SEOMOD_DEFAULT_CHARSET);
    // Подключаем оснвоной класс
    include SEOMOD_DIR.'seomod.core.php';
    // Читаем конфиг в utf
    $config = smLoadConfig(false);
    // Объявляем репозиторий
    if (isset($config['options']['repo'])) {
        define('SEOMOD_REPO', $config['options']['repo']);
    } else {
        die('SM: SEOMOD_REPO is not defined');
    }
    if (isset($_GET['update'])) {
        switch ($_GET['update']) {
            case SEOMOD_UPDATE_CONFIG:
                smCheckUpdate(SEOMOD_UPDATE_CONFIG);
            break;
            case SEOMOD_UPDATE_CORE:
                smCheckUpdate(SEOMOD_UPDATE_CORE);
            break;
        }
    }


?>
