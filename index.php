<?php

require_once('config.php');
require_once('loader.php');

// determine requested view
$uri = Utils::uriComponents();

// this page only has custom views.
if(count($uri) == 0) {
    $uri = ['empty'];
}
switch($uri[0]) {
    case 'import':
        Import::run();
        break;
    case 'get':
        array_shift($uri);
        new GetHandler($uri);
        break;
    case 'set':
        array_shift($uri);
        new SetHandler($uri);
        break;
    case 'create':
        array_shift($uri);
        new CreateHandler($uri);
        break;
    case 'delete':
        array_shift($uri);
        new DeleteHandler($uri);
        break;
    default:
        echo 'NULL';
}

?>