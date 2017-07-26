<?php

// FIND SERVER ROOT PATH EXTENSION
$root = str_replace("\\", "/", $_SERVER['DOCUMENT_ROOT']);
$dir = str_replace("\\", "/", dirname($_SERVER['SCRIPT_NAME']));
$ext = str_replace($root, '', $dir);
if(substr($ext, strlen($ext)-1) != '/') {
    $ext.="/";
}

if(file_exists('config-env.php')) {
    require_once('config-env.php');
}

// GETTING PLACES
define('DOC_ROOT', $_SERVER['DOCUMENT_ROOT'].$ext);
define('WWW_ROOT', $ext);

// DATABAZZE
@define('DB_HOST', "localhost");
@define('DB_USER', "root");
@define('DB_PASSWORD', "root");
@define('DB_NAME', "robertodonetta-base");

?>