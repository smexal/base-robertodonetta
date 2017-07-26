<?php
/* Load required directories */
Loader::loadDirectory(DOC_ROOT."classes/");

/*
 */
class Loader {

    public static function loadDirectory($directory, $inquery=false, $filefilter=false, $namepattern = false) {
        if (file_exists($directory)) {
            $dir = new \DirectoryIterator($directory);
            foreach ($dir as $fileinfo) {
                if ($fileinfo->isDot()) {
                    continue;
                }

                if (strstr($fileinfo->getFilename(), ".php")) {
                    if (! $filefilter || $filefilter == $fileinfo->getFilename()) {
                        if (!$namepattern) {
                            $f = $directory.$fileinfo->getFilename();
                            require_once($f);
                        } else {
                            foreach ($namepattern as $pattern) {
                                $fileparts = explode(".", $fileinfo->getFilename());
                                if (in_array($pattern, $fileparts)) {
                                    require_once($directory.$fileinfo->getFilename());
                                    break;
                                }
                            }
                        }
                    }
                } elseif ($fileinfo->isDir()) {
                    // check if the subdirectory is part of the queried url. (no manage views without manage queried)
                    if ($inquery) {
                        if (in_array($fileinfo->getFilename(), Utils::getUriComponents())) {
                            $this->loadDirectory($directory.$fileinfo->getFilename()."/", false, $filefilter, $namepattern);
                        }
                    } else {
                        $this->loadDirectory($directory.$fileinfo->getFilename()."/", false, $filefilter, $namepattern);
                    }
                }
            }
        }
    }
}

?>