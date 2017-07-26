<?php 

class SetHandler {
    private $data = [];
    private $config;

    public function __construct($request) {
        $this->request = $request;
        $this->config = simplexml_load_file(DOC_ROOT.'database-config.xml');

        $this->process();
        $this->render();
    }

    private function process() {
        // delete cache
        $this->deleteCache();        

        if(! $this->permission() ) {
            return;
        }

        if(! array_key_exists('id', $_GET)) {
            $this->data['no'] = 'id';
            return;
        }
        if(count($this->request) == 1) {
            $this->updateDirectValues();
            return;
        } else {
            if($this->request[1] == 'meta') {
                $this->updateMetaValues();
                return;
            }
            if($this->request[1] == 'connect') {
                $this->updateConnectValues();
                return;
            }
            if($this->request[1] == 'translation') {
                $this->updateTranslation();
                return;
            }
        }
    }

    private function updateTranslation() {
        $values = ['id', 'value', 'lang'];
        foreach($values as $v) {
            if(! array_key_exists($v, $_GET)) {
                $this->data['missing'] = $v;
            }
        }
        $id = $_GET['id'];
        $type = $this->request[0];
        $lang = $_GET['lang'];
        $value = $_GET['value'];

        // check if translation exists => update
        // else insert
        $q = "SELECT id FROM translations WHERE fid=".$id." AND lang='".$lang."' AND type = '".$type."' LIMIT 1";
        $r = Database::instance()->q($q);
        $found = false;
        while ( $row = $r->fetch_assoc() ) {
            $found = true;
            $updateQ = "UPDATE translations SET value='".Database::instance()->escape($value)."' WHERE id =".$row['id'];
            Database::instance()->q($updateQ);
            $this->data['update'] = 'done';
        }
        if(! $found ) {
            // insert
            $insertQ = "INSERT INTO translations (fid, lang, type, value) VALUES (".$id.", '".$lang."', '".$type."', '".Database::instance()->escape($value)."')";
            Database::instance()->q($insertQ);
            $this->data['insert'] = 'done';   
        }
    }

    private function updateMetaValues() {
        $id = 0;
        $field = false;
        $values = [];
        foreach($_GET as $key => $value) {
            if($key == 'key') {
                continue;
            }
            if($key == 'id') {
                $id = $value;
                continue;
            }
            if($key == 'field') {
                $field = $value;
                continue;
            }
            // make sure it is a two digits language key
            if(strlen($key) == 2) {
                if(strlen($value) > 0) {
                    $values[$key] = $value;
                }
            }
        }

        if(!$field) {
            $this->data['field'] = 'not set';
            return;
        }

        // delete existing meta values for the image
        // add the new meta values for the image
        $del = "DELETE FROM ".$this->config->Base->MetaTable.' WHERE fid='.$id.' AND keyy = \''.$field.'\'';
        Database::instance()->q($del);
        foreach($values as $lang => $val) {
            $ins = "INSERT INTO ".$this->config->Base->MetaTable.' (fid, keyy, value, lang)';
            $ins.= " VALUES (".$id.", '".$field."', '".Database::instance()->escape($val)."', '".$lang."')";
            Database::instance()->q($ins);
        }
        $this->data['update'] = 'complete';
    }

    private function updateConnectValues() {
        // http://base-robertodonetta.dev/set/connect?key=007007007&source=images&target=descriptor_an&id=1&values=1,2,3,4
        $source = $this->request[0];
        $target = false;
        $id = false;
        $values = false;
        foreach($_GET as $key => $value) {
            if($key == 'target') {
                $target = $value;
                continue;
            }
            if($key == 'id') {
                $id = $value;
            }
            if($key == 'values') {
                $values = explode(",", $value);
            }
        }
        if(! $source || ! $target || ! $id || ! $value) {
            $this->data['missing_data'] = 'source, target or value missing';
            return;
        }

        // delete current connections
        $tableName = 'con_'.$source.'_'.$target;
        $del = 'DELETE FROM '.$tableName.' WHERE iid='.$id;
        Database::instance()->q($del);

        // insert new values
        foreach($values as $value) {
            $ins = 'INSERT INTO '.$tableName.' (iid, fid)';
            $ins.= ' VALUES ('.$id.', '.Database::instance()->escape($value).')';
            Database::instance()->q($ins);
        }
        $this->data['update'] = 'complete';
    }

    private function updateDirectValues() {
        $id = 0;
        $toUpdate = [];
        foreach($_GET as $key => $value) {
            if($key == 'key') {
                continue;
            }
            if($key == 'id') {
                $id = $value;
                continue;
            }
            $toUpdate[$key] = $value;
        }
        if(count($toUpdate) == 0) {
            $this->data['nothing'] = 'toChange';
            return;
        }

        if(!is_numeric($id)) {
            $this->data['invalid'] = 'id';
            return;
        }

        $fields = implode(', ', array_map(
            function ($v, $k) { 
                if(is_numeric($v))
                    return sprintf("%s=%s", $k, Database::instance()->escape($v));
                return sprintf("%s='%s'", $k, Database::instance()->escape($v));
            },
            $toUpdate,
            array_keys($toUpdate)
        ));

        $q = "UPDATE ".$this->request[0]. ' SET ' .$fields.' WHERE id='.$id;
        Database::instance()->q($q);

        $this->data['update'] = 'complete';
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

    private function deleteCache() {
        $files = glob('cache/*'); // get all file names
        foreach($files as $file) { // iterate files
            if(is_file($file)) {
                unlink($file); // delete file
            }
        }
    }

    private function render() {
        header('Access-Control-Allow-Origin: *');
        header('Content-type:application/json;charset=utf-8');
        echo json_encode($this->data);
    }

}