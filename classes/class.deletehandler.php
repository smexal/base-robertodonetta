<?php 

class DeleteHandler {
    private $request;
    private $data = [];
    private $config;

    public function __construct($request) {
        $this->request = $request[0];
        $this->config = simplexml_load_file(DOC_ROOT.'database-config.xml');
        $this->create();
        $this->render();
    }

    private function create() {
        if(! $this->permission() ) {
            return;
        }
        if(strlen($this->request) == 0) {
            $this->data['unknownDataRequest'] = 'noQuery';
            return;
        }
        if(! $this->tableExists()) {
            $this->data['unknownDataRequest'] = $this->request;
            return;
        }
        if(! array_key_exists('id', $_GET) || ! is_numeric($_GET['id'])) {
            $this->data['no'] = 'id';
            return;
        }

        $csql = "DELETE FROM ".$this->request." WHERE id=".$_GET['id'];
        Database::instance()->q($csql);
        $this->data['done'] = Database::instance()->lastInsertId();
    }

    private function tableExists() {
        $q = "SELECT * FROM information_schema.tables ";
        $q.= "WHERE table_schema = '".DB_NAME."' ";
        $q.= "AND table_name = '".$this->request."' LIMIT 1";
        $r = Database::instance()->q($q);
        if($r->num_rows > 0) {
            return true;
        } else {
            return false;
        }
    }

    private function permission() {
        if(! array_key_exists('key', $_GET)) {
            $this->data['no'] = 'key';
            return;
        }
        if(! Auth::keyValid($_GET['key']) ) {
            $this->data['key'] = 'invalid';
            return;
        }
        return true;
    }

    private function render() {
        header('Access-Control-Allow-Origin: *');
        header('Content-type:application/json;charset=utf-8');
        echo json_encode($this->data);
    }

}