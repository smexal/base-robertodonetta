<?php

class Database {
    protected static $_instance = null;
    private $db = null;

    public static function instance() {
        if (null === self::$_instance) {
            self::$_instance = new self;
        }
        return self::$_instance;
    }

    public function q($q) {
        return $this->rawQuery($q);
    }

    public function rawQuery($query) {
        return $this->db->query($query);
    }
    public function escape($str) {
        return $this->db->real_escape_string($str);
    } 

    public function error() {
        return $this->db->error;
    }

    public function lastInsertId() {
        return $this->db->insert_id;
    }
 
    protected function __clone() {}
    protected function __construct() {
        $this->db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    }
} 

?>