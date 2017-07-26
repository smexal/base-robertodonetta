<?php

class Import {
    private $baseDirectory = DOC_ROOT.'import-data/';
    private $xmlDirectory = DOC_ROOT.'import-data/';
    private $imagesTable = '';
    private $metaTable = '';
    private $language = 'en';
    private $config = null;
    private $currentId = null;
    private $currentXmlName = null;
    private $contentMatcherWarnings = [];

    public static function run() {
        if(! Auth::allowed()) {
            return;
        }

        $self = new self();
        $self->start();
    }

    public function flushDatabase() {
        // drop main tables
        $query = 'DROP TABLE `'.$this->imagesTable.'`, `'.$this->metaTable.'`;';
        Database::instance()->rawQuery($query);

        // delete relation tables
        $relationTables = [];
        foreach($this->config->RelationTables->Table as $table) {
            $relationTables[] = '`'.strval($table->attributes()->name).'`';
        }
        $tables = implode(", ", $relationTables);
        $query = 'DROP TABLE '.$tables.';';
        Database::instance()->rawQuery($query);

        Utils::msg('Database successfully flushed. All gone, sorry sir; starting to import.', 'warning');
    }

    public function start() {
        if(is_null($this->config)) {
            $this->config = simplexml_load_file(DOC_ROOT.'database-config.xml');
        }
        $this->imagesTable = strval($this->config->Base->ImagesTable);
        $this->metaTable = strval($this->config->Base->MetaTable);

        $time_start = microtime_float();
        Utils::outputStart();

        $disable = false;
        if($disable) {
            Utils::msg('The import has been disabled in terms of security. Contact a Developer.', 'success');
            Utils::msg('Fotobuero Bern, info@fotobuerobern.ch or smaechler@raptus.com', 'discreet');
            Utils::outputEnd();
            return;
        }

        // check for flush
        if(array_key_exists('flush', $_GET) && $_GET['flush'] == '007') {
            // drop tables
            $this->flushDatabase();
        } else if (array_key_exists('flush', $_GET) && $_GET['flush'] != '007') {
            Utils::msg('We don\'t let you do this like this...', 'error');
            exit();
        } else {
            Utils::msg('You have to flush the database.', 'error');
            Utils::msg('Be aware, when you do this, all your current data is lost.');
            // no check for existing, so currently only flush allowed.
            exit();
        }

        /*
            Creates all database tables if they are not existing.
         */
        $this->prepareDatabase();

        $dir = new \DirectoryIterator($this->xmlDirectory);

        Utils::msg('Importing about '.iterator_count($dir).' entities.', 'discreet');

        // limit to as many as you wish... set to false if you want to import all
        // this is just for debugging and development purposes..
        $count = 0;
        $limit = false;
        if($limit)
            Utils::msg('Your limit is set to '.$limit. ' entities', 'warning');

        foreach ($dir as $fileinfo) {
            if ($fileinfo->isDot()) {
                continue;
            }
            $count++;
            if (strstr(strtolower($fileinfo->getFilename()), ".xml")) {
                $this->currentXmlName = $fileinfo->getFilename();
                $this->importXML($fileinfo->getRealPath());
            }
            if($count % 200 == 0) {
                Utils::msg('Working... <small>'.$count.' Files</small>', 'discreet');
            }
            if($limit && $count >= $limit) {
                break;
            }
        }

        $time_end = microtime_float();
        $time = $time_end - $time_start;
        Utils::msg('Import done for '.$count.' files after '.$time.' seconds.', 'success');
        Utils::outputEnd();
    }

    public function importXML($file) {
        $xml = simplexml_load_file($file);

        $this->setLanguage($xml);

        $base = $this->config->Base->ObjectBase;
        $base = explode("/", $base);
        $data = $xml;
        foreach($base as $b) {
            $data = $data->$b;
        }
        $iteration = $this->config->Base->Iteration;
        // new empty image in database
        $this->currentId = $this->newImage();

        foreach($data->$iteration as $detail) {
            $this->contentMatchers($detail);
        }
    }

    private function newImage() {
        $query = 'INSERT INTO '.$this->imagesTable.' () VALUES ()';
        Database::instance()->rawQuery($query);
        return Database::instance()->lastInsertId();
    }
    private function getAllColumns() {
        // ContentMatcher Columns
        $columns = [];
        foreach($this->config->Matchers->ContentMatcher as $matcher) {
            $columns[] = strval($matcher->attributes()->to);
        }
        return implode(", ", $columns);
    }

    private function setLanguage($xml) {
        $identifier = $this->config->Base->LanguageIdentifier;
        $identifier = explode("/", $identifier);
        // search for attribute delimiter
        $attribute = explode(":", $identifier[count($identifier)-1]);
        if(count($attribute) > 0) {
            $identifier[count($identifier)-1] = $attribute[0];
            $attribute = $attribute[1];
        } else {
            $attribute = false;
        }
        if($attribute) {
            $data = $xml;
            foreach($identifier as $i) {
                $data = $data->$i;
            }
            $this->language = strval($data->attributes()->$attribute);
            Utils::msg('Language set to "'.$this->language.'".', 'discreet debug');
        }
    }

    private function contentMatchers($detail) {
        $matcherTag = $this->config->Base->ContentMatcherTag;
        $valueTag = $this->config->Base->ContentMatcherValueTag;
        $db = Database::instance();
        
        $this->missingContentMatcherWarnings($detail);

        foreach($this->config->Matchers->ContentMatcher as $matcher) {
            if(strval($detail->$matcherTag) == strval($matcher->attributes()->from)) {
                if(strval($matcher->attributes()->multilang) === 'true') {
                    // insert value to meta database
                    $metaQuery = 'INSERT INTO '.$this->metaTable.' (fid, keyy, value, lang) '; 
                    $metaQuery.= 'VALUES ('.$this->currentId.',
                                         "'.strval($matcher->attributes()->to).'",
                                         "'.$detail->$valueTag.'",
                                         "'.$this->language.'");';
                    $db->rawQuery($metaQuery);
                } else if (strlen(strval($matcher->attributes()->relation)) > 0) {
                    $this->setRelationValue(
                        $this->currentId, 
                        strval($matcher->attributes()->relation), 
                        strval($detail->$valueTag),
                        strval($matcher->attributes()->to)
                    );
                } else if (strlen(strval($matcher->attributes()->relationMultiple)) > 0) {
                    /*Utils::dump("To Image ". $this->currentId . " add ". strval($detail->$valueTag) . ' as '.strval($matcher->attributes()->valueExtractor).' | '.strval($matcher->attributes()->relationMultiple));
                    Utils::dump($this->currentXmlName);*/
                    $this->setMultipleRelationValue(
                        $this->currentId, // image id
                        strval($matcher->attributes()->relationMultiple), // table identifier
                        strval($detail->$valueTag),
                        strval($matcher->attributes()->valueExtractor)
                    );
                } else {
                    $this->updateImageValue(
                        $this->currentId, 
                        strval($matcher->attributes()->to),
                        '"'.$detail->$valueTag.'"'
                    );
                }
            }
        }
    }

    private function setRelationValue($entity, $relation, $value, $field, $noImageUpdate = false) {
        foreach($this->config->RelationTables->Table as $t) {
            if(strval($t->attributes()->name) == $relation) {
                $identifier = strval($t->attributes()->identifier);
            }
        }
        if(strlen($identifier) == 0) {
            Utils::msg('No identifier set on relation `'.$relation.'`; set as attribute. Can not set relation.', 'error');
            return;
        }
        // special people handling...
        $splitVal = explode(",", $value);
        if($relation == 'people' && count($splitVal) > 1) {
            $db = Database::instance();
            // check if the current value has to be extracted into the people table.
            $name = $this->normalizeName($splitVal[0]);
            $forename = trim($splitVal[1]);

            // INSERT NAME INTO people table if not exists
            $checkQuery = "SELECT * FROM people WHERE name ='".$db->escape($name)."' AND forename='".$db->escape($forename)."'";
            $result = $db->rawQuery($checkQuery);
            $relationId = 0;
            if($result->num_rows > 0) {
                // update
                while ($row = $result->fetch_object()) {
                    $relationId = $row->id;
                    // break after first object; just to be sure
                    break;
                }
            } else {
                $q = "INSERT INTO people (name, forename) VALUES ('".$db->escape($name)."', '".$db->escape($forename)."')";
                $db->q($q);
                $relationId = $db->lastInsertId();
            }
            $updateQuery = "UPDATE ".$this->imagesTable." SET ".$field."='".$relationId."' WHERE id=".$entity;
            $db->q($updateQuery);

        } else {
            $checkQuery = 'SELECT * FROM `'.$relation.'` WHERE '.$identifier.'="'.$value.'"';
            $db = Database::instance();
            $result = $db->rawQuery($checkQuery);
            $relationId = 0;
            if($result->num_rows > 0) {
                // update
                while ($row = $result->fetch_object()) {
                    $relationId = $row->id;
                    // break after first object; just to be sure
                    break;
                }
            } else {
                // insert
                $insertQuery = 'INSERT INTO `'.$relation.'` ('.$identifier.') VALUES ("'.$value.'")';
                $db->rawQuery($insertQuery);
                $relationId = $db->lastInsertId();
            }
            if($noImageUpdate)
                return $relationId;
            $updateQuery = "UPDATE ".$this->imagesTable." SET ".$field."='".$relationId."' WHERE id=".$entity;
            $db->rawQuery($updateQuery);
        }
    }

    private function setMultipleRelationValue($imageId, $tableIdentifier, $value, $valueExtractor) {

        $splitVal = explode(",", $value);
        if($valueExtractor == 'people' && count($splitVal) > 1) {
            $db = Database::instance();
            // check if the current value has to be extracted into the people table.
            $name = $this->normalizeName($splitVal[0]);
            $forename = trim($splitVal[1]);

            // INSERT NAME INTO people table if not exists
            $checkQuery = "SELECT * FROM people WHERE name ='".$db->escape($name)."' AND forename='".$db->escape($forename)."'";
            $result = $db->rawQuery($checkQuery);
            $relationId = 0;
            if($result->num_rows > 0) {
                // update
                while ($row = $result->fetch_object()) {
                    $relationId = $row->id;
                    // break after first object; just to be sure
                    break;
                }
            } else {
                $q = "INSERT INTO people (name, forename) VALUES ('".$db->escape($name)."', '".$db->escape($forename)."')";
                $db->q($q);
                $relationId = $db->lastInsertId();
            }

            // add connection
            $q = "INSERT INTO con_images_people (iid, fid) VALUES (".$imageId.", ".$relationId.")";
            $db->q($q);

        } else {
            // add the new value to the according meta field without updating the images table.
            $metaId = $this->setRelationValue($imageId, $tableIdentifier, $value, $tableIdentifier, true);

            // add a new connection to the connection table.
            $sql = "INSERT INTO con_".$this->imagesTable."_".$tableIdentifier." (iid, fid) VALUES (".$imageId.", ".$metaId.")";
            Database::instance()->q($sql);
        }
    }


    private function missingContentMatcherWarnings($detail) {
        $matcherTag = $this->config->Base->ContentMatcherTag;
        $valueTag = $this->config->Base->ContentMatcherValueTag;

        foreach($detail->$matcherTag as $contentToMatch) {
            $contentToMatch = strval($contentToMatch);
            $found = false;
            foreach($this->config->Matchers->ContentMatcher as $matcher) {
                if($contentToMatch == strval($matcher->attributes()->from)) {
                    $found = true;
                }
            }
            if($found) {
                Utils::msg('Found: '.$contentToMatch, 'debug');
            } elseif (! in_array($contentToMatch, $this->contentMatcherWarnings)) {
                $this->contentMatcherWarnings[] = $contentToMatch;
                $small = '<small>Value: `'.$detail->$valueTag.'` ('.$this->currentXmlName.')</small>';
                Utils::msg('No Configuration for: "<strong>'.$contentToMatch.'</strong>" '.$small, 'discreet');
            }
        }
    }

    private function updateImageValue($imageId, $field, $value) {
        $query = 'UPDATE '.$this->imagesTable.' SET '.$field.'='.$value.' WHERE id='.$imageId;
        Database::instance()->rawQuery($query);
    }

    /**
     * creates all database tables, if they do not exist.
     * @return null
     */
    private function prepareDatabase() {
        $db = Database::instance();
        $errors = false;

        // create the images table if it is not existing
        $query = "CREATE TABLE IF NOT EXISTS `".$this->imagesTable."` ( `id` INT NOT NULL AUTO_INCREMENT, `author` int(11) DEFAULT 0, PRIMARY KEY (`id`)) ENGINE = InnoDB;";
        if ($db->rawQuery($query) === TRUE) {
            Utils::msg('Images Table created or existing.', 'discreet');
        } else {
            $errors = true;
            Utils::msg('Could not create images table. The following error occured:', 'error');
            Utils::msg($db->error());
        }

        // create meta table, if not existing
        $query = 'CREATE TABLE IF NOT EXISTS `'.$this->metaTable.'` (
                  `id` int(11) NOT NULL,
                  `fid` int(11) NOT NULL,
                  `keyy` varchar(300) NOT NULL,
                  `value` text NOT NULL,
                  `lang` varchar(10) NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        ';

        if ($db->rawQuery($query) === TRUE) {
            Utils::msg('Meta Table created or existing.', 'discreet');
            $db->rawQuery('ALTER TABLE `'.$this->metaTable.'` ADD PRIMARY KEY (`id`);');
            $db->rawQuery('ALTER TABLE `'.$this->metaTable.'` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;');
        } else {
            $errors = true;
            Utils::msg('Could not create meta table. The following error occured:', 'error');
            Utils::msg($db->error());
        }

        // check the config file for all fields and add them if not existing.
        foreach( $this->config->Matchers->ContentMatcher as $matcher ) {
            $to = strval($matcher['to']);
            $type = strval($matcher['type']);
            $multilang = strval($matcher['multilang']);
            $relation = strval($matcher['relation']);
            if($this->typeExists($type)) {
                if(strlen(strval($matcher['relationMultiple'])) == 0)
                    $this->createField($to, $type, $multilang, $relation);
            } else {
                $errors = true;
                Utils::msg('The given type "'.$type.'" does not exist in the import-config.xml', 'warning');
            }
        }

        $this->createRelationTables();

        if(!$errors) {
            Utils::msg('Database preparation successfully finished. Starting XML Import...', 'success');
        } else {
            Utils::msg('Errors while preparing database. Import has been stopped.', 'error');
            exit();
        }
    }

    private function createRelationTables() {
        /** EXAMPLE QUERY **/
        /*
        CREATE TABLE `robertodonetta-base`.`test` ( `id` INT NOT NULL AUTO_INCREMENT , `name` VARCHAR(300) NOT NULL , PRIMARY KEY (`id`)) ENGINE = InnoDB;
         */

        foreach( $this->config->RelationTables->Table as $xmlTable) {
            $attributes = $xmlTable->attributes();
            $tableName = strval($attributes->name);

            $query = 'CREATE TABLE IF NOT EXISTS `'.$tableName.'` ( `id` INT NOT NULL AUTO_INCREMENT';
            foreach($xmlTable->Field as $xmlField) {
                $fieldName = strval($xmlField);
                $xmlFieldType = strval($xmlField->attributes()->type);
                $fieldType = strval($this->config->Types->$xmlFieldType);
                $query.=', `'.$fieldName.'` '.$fieldType.' NULL';
            }
            $query.= ', PRIMARY KEY (`id`)) ENGINE = InnoDB;';
            $db = Database::instance();
            $db->rawQuery($query);

            // check if it is multiple, then create a connection table
            if(strval($attributes->type) == 'multiple') {
                $tableName = 'con_'.$this->imagesTable.'_'.$tableName;
                $query = 'CREATE TABLE IF NOT EXISTS `'.$tableName.'` ( `id` INT NOT NULL AUTO_INCREMENT';
                $query.= ', iid INT NULL';
                $query.= ', fid INT NULL';
                $query.= ', PRIMARY KEY (`id`)) ENGINE = InnoDB;';
                $db->rawQuery($query);
            }
        }

    }

    private function createField($name, $type, $multilang, $relation) {
        $sqlType = strval($this->config->Types->$type);
        if(strlen($relation) > 0) {
            $sqlType = 'INT';
        }
        if($multilang !== 'true') {
            // if it's a multilang field, set the type to INT for foreign meta key value.
            $query = 'ALTER TABLE `'.$this->imagesTable.'` ADD `'.$name.'` '.$sqlType.' NULL;';
            $db = Database::instance();
            if( $db->rawQuery($query) == TRUE ) {
                Utils::msg('Added the field "'.$name.'" as '.$sqlType.' [use as: '.$type.'].', 'discreet');
            }
        } else {
            Utils::msg('The field "'.$name.'" will be written into the meta table.', 'discreet');
        }
    }

    private function typeExists($type) {
        if(strlen(strval($this->config->Types->$type)) == 0) {
            return false;
        }
        return true;
    }

    private function normalizeName($n) {
        $name = trim(ucfirst(strtolower($n)));
        if(strlen(strstr($name, "-")) > 0) {
            //Utils::dump($name);
            $exName = explode("-", $name);
            for($index = 0; $index <= count($name); $index++) {
                $exName[$index] = ucfirst($exName[$index]);
            }
            $name = implode("-", $exName);
        }
        return $name;
    }

}

?>