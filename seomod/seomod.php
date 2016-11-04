<?php
    // Костанты ниже изменять на совй страх и риск))
    define('SEOMOD_DIR', $_SERVER['DOCUMENT_ROOT'].'/seomod/');
    define('SEOMOD_CACHEDIR', SEOMOD_DIR.'cache/');
    define('SEOMOD_HOST', $_SERVER['HTTP_HOST']);
    define('SEOMOD_URI', $_SERVER['REQUEST_URI']);
    define('SEOMOD_URI_HASH', md5($_SERVER['REQUEST_URI']));
    define('SEOMOD_DEFAULT_CHARSET', 'utf-8');
    define('SEOMOD_HTTP_VERSION', '1.1');
    define('SEOMOD_BITRIX', false);
    // Подключаем оснвоной класс
    include SEOMOD_DIR.'seomod.core.php';
    smInit();
    // Если bitrix, то контент уже в буфере. И кеш для битрикса не работает.
    if (SEOMOD_BITRIX) {
        smLoad();
    } else {
        if (defined('SEOMOD_USE_CACHE') && smGetCache()) {
            echo "\n<!--Seomod cache ".SEOMOD_CACHE_AGE." -->";
        } else {
            ob_start();
            include 'real_index.php';
            smLoad();
        }
    }
?>
