<?php

class Auth {

    public static function allowed() {
        // TODO : Check permissions with api keys...
        return true;
    }

    public static function keyValid($key) {
        if($key === '007007007') {
            return true;
        }
        return false;
    }

}

?>