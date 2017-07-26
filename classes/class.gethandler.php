<?php 

class GetHandler {
    private $request = '';
    private $table = '';
    private $args = [];
    private $config = null;
    private $filter = false;
    private $ignored = [];
    private $search = false;
    private $realRequest = false;
    private $cacheTimeInSeconds = 300;
    private $translationTable = 'translations';
    private $translatable = [
        'conservation_status',
        'descriptor_an',
        'descriptor_icon',
        'descriptor_in',
        'formats',
        'person_title',
        'profession'
    ];

    public function __construct($request) {
        $this->request = $request;
        $this->realRequest = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

        if($this->validCache()) {
            $this->getFromCache();
            return;
        }

        // first parameter has to be the table, which you want do display.
        if(count($request) == 0) {
            $this->render(['unknownDataRequest' => 'noQuery']);
            return;
        }
        $this->table = $this->request[0];
        array_shift($this->request);

        if(! $this->tableExists()) {
            $this->render(['unknownDataRequest' => $this->table]);
            return;
        }
        $this->config = simplexml_load_file(DOC_ROOT.'database-config.xml');

        if(in_array("filter", $this->request)) {
            $this->prepareFilter();
        }
        if(!in_array("filter", $this->request) && array_key_exists('search', $_GET)) {
            $this->search = Database::instance()->escape($_GET['search']);
        }

        if(array_key_exists('hide', $_GET)) {
            $this->ignored = explode(',', $_GET['hide']);
            if( $idKey = array_search('id', $this->ignored) ) {
                unset($this->ignored[$idKey]);
            }
        }

        $this->prepareArgs();
        $this->render($this->getResults());

    }

    private function validCache() {
        $f = "cache/".urlencode($this->realRequest).".cache";

        if(file_exists($f)) {
            $filetime = filemtime($f);
            $timenow = time();
            $ageInSeconds = $timenow - $filetime;
            if($ageInSeconds <= $this->cacheTimeInSeconds) {
                return true;
            }
        }
        return false;
    }

    private function getFromCache() {
        $data = file_get_contents("cache/".urlencode($this->realRequest).".cache");
        header('Access-Control-Allow-Origin: *');
        header('Content-type:application/json;charset=utf-8');
        echo $data;
    }

    private function searchWhere() {
        $q = '';
        // strait columns
        $r = Database::instance()->q("SELECT DATA_TYPE, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '".$this->table."'");
        $whereQs = [];
        while($row = $r->fetch_assoc()) {
            if($row['DATA_TYPE'] == 'varchar' || $row['DATA_TYPE'] == 'text') {
                $whereQs[] =  $this->table.".".$row['COLUMN_NAME']. " LIKE '%".$this->search."%'";
            } else if($row['DATA_TYPE'] == 'int') {
                // we got int.. check if a according table exists to request...
                $target = $this->filterColumnTableName($row['COLUMN_NAME']);
                if($this->tableExists($target)) {

                    $q.= " LEFT JOIN ".$target." as __". $row['COLUMN_NAME'];
                    $q.= " ON ".$this->table.".".$row['COLUMN_NAME']." = __". $row['COLUMN_NAME'].".id ";

                    $getTable = "SELECT DATA_TYPE, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '".$target."'";
                    $rq = Database::instance()->q($getTable);
                    while($ruw = $rq->fetch_assoc()) {
                        if($ruw['DATA_TYPE'] == 'varchar' || $ruw['DATA_TYPE'] == 'text') {
                            $whereQs[] = '__'.$row['COLUMN_NAME'].'.'.$ruw['COLUMN_NAME']. ' LIKE \'%'.$this->search.'%\' ';
                        }
                    }

                }
            }
        }

        // get meta values on images query
        if($this->table == strval($this->config->Base->ImagesTable)) {
            // search for meta values with the image id..
            $whereQs[] = $this->table.'.id IN (SELECT fid from '.$this->config->Base->MetaTable.' WHERE value like "%'.$this->search.'%") ';

            // relation matchers...
            foreach($this->config->Connector->Connect->RelationMatcher as $matcher) {
                // inspiration: OR images.id IN (select iid FROM con_images_descriptor_an left join descriptor_an on con_images_descriptor_an.fid = descriptor_an.id where descriptor_an.name like '%Moustache%')

                $InJoinWhere = $this->table.'.id IN (SELECT iid from con_'.$this->table.'_'.strval($matcher);
                $InJoinWhere.= ' LEFT JOIN '.strval($matcher).' ON '.strval($matcher).'.id = con_'.$this->table.'_'.strval($matcher).'.fid';
                $InJoinWhere.= ' WHERE '.strval($matcher).'.name like "%'.$this->search.'%") ';

                $whereQs[] = $InJoinWhere;

                // translation tables... inspiration
                /*
                 OR images.id IN (
                    SELECT iid from con_images_descriptor_an 
                        LEFT JOIN descriptor_an ON descriptor_an.id = con_images_descriptor_an.fid
                        LEFT JOIN translations ON translations.fid = descriptor_an.id
                        WHERE translations.value like '%Schnurrbart%'
                )
                */
               $InJoinJoinWhere = $this->table.'.id IN (SELECT iid from con_'.$this->table.'_'.strval($matcher);
               $InJoinJoinWhere.= ' LEFT JOIN '.strval($matcher).' ON '.strval($matcher).'.id = con_'.$this->table.'_'.strval($matcher).'.fid';
               $InJoinJoinWhere.= ' LEFT JOIN translations ON translations.fid = '.strval($matcher).'.id ';
               $InJoinJoinWhere.= " WHERE translations.value like '%".$this->search."%' AND translations.type = '".strval($matcher)."')";

               $whereQs[] = $InJoinJoinWhere;
            }
        }

        // build the WHERE query to the end...
        $q.= ' WHERE ';
        $q.= implode(" OR ", $whereQs);
        return $q;
    }

    private function getResults() {
        $getTable = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '".$this->table."'";
        $columnsToQuery = Database::instance()->q($getTable);
        $columns = [];
        while($row = $columnsToQuery->fetch_assoc()) {
            $columns[] = $this->table.".".$row['COLUMN_NAME'];           
        }
        $q = 'SELECT '.implode(", ", $columns).' FROM '.$this->table;
        if(is_array($this->filter)) {
            // ['field', 'value', 'type'];
            $q.= ' WHERE '.$this->filter['field'].' '.str_replace("VALUE", $this->filter['value'], $this->filter['type']);
        } elseif($this->search) {
            $q.= $this->searchWhere();
        }

        $q.= ' ORDER BY '.$this->table.'.'.$this->args['order'].' '.$this->args['orderDir'];
        if($this->args['limit'] !== 'none') {
            $q.= ' LIMIT '.$this->args['limit'];
            $q.= ' OFFSET '.$this->args['offset'];
        }

        //var_dump($q);

        $r = Database::instance()->q($q);
        $data = [];
        while($row = $r->fetch_assoc()) {
            if($row['id'] === NULL) {
                continue;
            }
            $cells = [];
            if($this->table == strval($this->config->Base->ImagesTable)) {
                // add image url
                $ident = $row['identifier'];
                $ident = explode("/", $ident);
                $imageName = str_pad($ident[0], 4, '0', STR_PAD_LEFT).".jpg";
                $cells['image_url'] = Utils::getAbsoluteUrlRoot().'/'.$this->config->Base->ImagesDirectory.$imageName;
            }
            foreach($row as $cellName => $cellValue) {
                if(in_array($cellName, $this->ignored)) {
                    continue;
                }
                if($relationTable = $this->isRelationalContent($cellName)) {
                    if(array_key_exists('displayAll', $_GET)) {
                        if(! is_numeric($cellValue)) {
                            $cellValue = 0;
                        }
                        $cells[$cellName] = $this->getRelationalContent($cellValue, $relationTable);
                    }
                } else {
                    if(in_array($cellName, $this->translatable) || ($cellName == 'name' && in_array($this->table, $this->translatable))) {
                        $type = $cellName == 'name' ? $this->table : $cellName;
                        $cells[$cellName] = $this->getTranslationArray($cells['id'], $type, $cellValue);
                    } else {
                        $cells[$cellName] = $cellValue;
                    }
                }
            }
            $cells = array_merge($cells, $this->respectConnectors($row['id']));
            $data[] = $cells;
        }
        return $data;
    }

    private function getTranslationArray($id, $type, $original) {
        $translatedField = [];
        $translatedField['original'] = $original;
        foreach($this->config->Base->Languages->Lang as $lang) {
            $q = "SELECT value from translations WHERE fid = ".$id. " AND type = '".$type."' AND lang = '".strval($lang)."' LIMIT 1";
            $trans_r = Database::instance()->q($q);
            $found = false;
            while($trans_row = $trans_r->fetch_assoc()) {
                $found = true;
                $translatedField[strval($lang)] = $trans_row['value'];
            }
            if(! $found) {
                $translatedField[strval($lang)] = '';   
            }
        }
        return $translatedField;
    }

    private function respectConnectors($id) {
        $return = [];
        foreach($this->config->Connector->Connect as $connect) {
            // if the connector counts for this table
            if(strval($connect->attributes()->table) == $this->table) {
                foreach($connect->ContentMatcher as $matcher) {
                    if(in_array(strval($matcher), $this->ignored)) {
                        continue;
                    }
                    $data = [];
                    $q = 'SELECT lang, value FROM '.$this->config->Base->MetaTable.' ';
                    $q.= 'WHERE fid='.$id.' AND keyy = "'.strval($matcher).'"';
                    $r = Database::instance()->q($q);
                    if($r->num_rows > 1) {
                        while($row = $r->fetch_assoc()) {
                            if(!array_key_exists($row['lang'], $data)) {
                                $data[$row['lang']] = [];
                            }
                            $data[$row['lang']][] = $row['value'];
                        }
                    } else {
                        while($row = $r->fetch_assoc()) {
                            $data[$row['lang']] = $row['value'];
                        }
                    }
                    // check all languages, and add empty strings for not defined translations
                    foreach($this->config->Base->Languages->Lang as $lang) {
                        if(! array_key_exists(strval($lang), $data)) {
                            $data[strval($lang)] = '';
                        }
                    }
                    $return[strval($matcher)] = $data;
                }

                // Relation Connector
                foreach($connect->RelationMatcher as $relation) {
                    $table = strval($relation);
                    if(in_array($table, $this->ignored)) {
                        continue;
                    }
                    // base query
                    // SELECT * FROM con_images_descriptor_an INNER JOIN descriptor_an on con_images_descriptor_an.fid = descriptor_an.id WHERE con_images_descriptor_an.iid = 36
                    $conTable = 'con_'.$this->config->Base->ImagesTable.'_'.$table;
                    $q = 'SELECT * FROM '.$conTable;
                    $q.= ' INNER JOIN '.$table.' ON '.$conTable.'.fid = '.$table.'.id';
                    $q.= ' WHERE '.$conTable.'.iid = '.$id;
                    $r = Database::instance()->q($q);
                    $data = [];
                    while($row = $r->fetch_assoc()) {
                        $innerId = $row['id'];
                        unset($row['id']);
                        unset($row['iid']);
                        if(in_array($table, $this->translatable) && array_key_exists('name', $row)) {
                            $row['name'] = $this->getTranslationArray($innerId, $table, $row['name']);
                        }
                        $data[] = $row;
                    }
                    $return[$table] = $data;
                }
            }
        }
        return $return;
    }

    private function getRelationalContent($id, $table) {
        $q = 'SELECT * FROM '.$table.' WHERE id='.$id;
        $r = Database::instance()->q($q);
        $data = [];
        while($row = $r->fetch_assoc()) {
            if(in_array($table, $this->translatable) && array_key_exists('name', $row)) {
                $row['name'] = $this->getTranslationArray($id, $table, $row['name']);
            }
            $data[] = $row;
        }
        return $data;
    }

    private function isRelationalContent($cellName) {
        foreach( $this->config->Matchers->ContentMatcher as $matcher) {
            $attributes = $matcher->attributes();
            if(strval($attributes->to) != $cellName)
                continue;

            if(strlen(strval($attributes->relation)) > 0)
                return strval($attributes->relation);
        } 
    }

    private function prepareFilter() {
        $this->filter = [];
        $this->filter['type'] = 'EQUALS'; // EQUALS, LIKE or FUZZY (LIKE %%)
        $checkArgs = ['field', 'value', 'type'];

        $allFound = true;
        foreach($checkArgs as $arg) {
            if(array_key_exists($arg, $_GET)) {
                $this->filter[$arg] = urldecode($_GET[$arg]);
            } else {
                $allFound = false;
            }
        }
        switch($this->filter['type']) {
            case 'EQUALS':
                $this->filter['type'] = '= VALUE';
                break;
            case 'LIKE':
                $this->filter['type'] = "LIKE 'VALUE'";
                break;
            case 'FUZZY':
                $this->filter['type'] = "LIKE '%VALUE%'";
                break;
            default:
                $this->filter['type'] = "LIKE '%VALUE'";
                break;
        }
        if(!$allFound) {
            $this->filter = false;
        }
    }

    private function prepareArgs() {
        $this->args['limit'] = 30;
        $this->args['offset'] = 0;
        $this->args['order'] = 'id';
        $this->args['orderDir'] = 'ASC';
        $this->args['search'] = false;

        $checkArgs = ['limit', 'offset', 'search', 'order', 'orderDir'];

        foreach($checkArgs as $arg) {
            if(array_key_exists($arg, $_GET)) {
                $this->args[$arg] = $_GET[$arg];
            }
        }
    }

    private function tableExists($t=null) {
        if(is_null($t)) {
            $t = $this->table;
        }
        $q = "SELECT * FROM information_schema.tables ";
        $q.= "WHERE table_schema = '".DB_NAME."' ";
        $q.= "AND table_name = '".$t."' LIMIT 1";
        $r = Database::instance()->q($q);
        if($r->num_rows > 0) {
            return true;
        } else {
            return false;
        }
    }

    public function filterColumnTableName($in) {
        foreach( $this->config->ColumnTableRelations->rel as $relation) {
            if($in == strval($relation->attributes()->src)) {
                return strval($relation->attributes()->target);
            }
        } 
        return $in;
    }


    private function render($data) {
        header('Access-Control-Allow-Origin: *');
        header('Content-type:application/json;charset=utf-8');
        $this->fillCache($data);
        echo json_encode($data);
    }

    private function fillCache($data) {
        $cname = urlencode($this->realRequest).'.cache';
        if(! is_dir("cache")) {
            mkdir('cache');
        }
        file_put_contents( "cache/".$cname , json_encode($data) );
    }

}