<?php

class Utils {
    public static function uriComponents() {
        preg_match_all("/(.*)(\?.+)/", $_SERVER["REQUEST_URI"], $uri, PREG_PATTERN_ORDER);
        if(count($uri[0]) > 0) {
            $uri = $uri[1][0];
        } else {
            $uri = $_SERVER['REQUEST_URI'];
        }
        if(WWW_ROOT != '/') {
            $uri = str_replace(WWW_ROOT, "",$uri);
        }
        $uri = explode("/", $uri);
        foreach($uri as $k => $v) {
            if($v == '') {
                unset($uri[$k]);
            }
        }
        return array_values($uri);
    }

    public static function outputStart() {
        echo '<html>';
        echo '<head>';
        echo '<link href="https://fonts.googleapis.com/css?family=Roboto+Mono" rel="stylesheet">';
        echo '<link rel="stylesheet" href="css/reset.css">';
        echo '<link rel="stylesheet" href="css/main.css">';
        echo '</head>';
        echo '<body>';
        echo '<div class="console-content">';
    }

    public static function outputEnd() {
        echo '</div>';
        echo '</body></html>';
    }

    public static function getAbsoluteUrlRoot() {
      $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
      $domainName = $_SERVER['HTTP_HOST'];
      return $protocol.$domainName;
    }

    public static function msg($msg, $type='') {
        echo '<span class="'.$type.'">'.$msg.'</span>';
    }

    public static function dump($var) {
        echo '<pre>';
        var_dump($var);
        echo '</pre>';
    }
}

function microtime_float() {
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}
