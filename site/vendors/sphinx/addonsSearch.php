<?php

/**
* AddonSearch
*/
class AddonSearch
{
    /**
     *  Search for addons using Sphinx
     */
    public function __construct($app_model) {
        $this->sphinx = new SphinxClient();
        $this->sphinx->SetServer(SPHINX_HOST, SPHINX_PORT);        
        $this->app_model = $app_model;
    }
     
    /**
     *  Actually preform the search
     */
    public function query($term) {
        // summon the sphinx api
        $sphinx = $this->sphinx;
        $sphinx->SetSelect("addon_id, app");
        $sphinx->SetFieldWeights(array('name'=> 4));
        $sphinx->SetSortMode ( SPH_SORT_EXPR, "@weight + IF(status=1, 0, 100)" );
        $sphinx->SetLimits(0, 60);
        $sphinx->SetFilter('inactive', array(0));
        
        // filter based on the app we're looking for e.g is this /firefox/ or /seamonkey/ etc
        $sphinx->SetFilter('app', array(APP_ID));
        
        // version filter
        // convert version to int
        // convert into to a thing to serach for
        if (preg_match('/\bversion:([0-9\.]+)/', $term, $matches)) {
            $term = str_replace($matches[0], '', $term);
            $version_int = self::convert_version($matches[1]);
            // using 10x version number since that should cover a significantly larger number since SetFilterRange requires
            // max and min
            if ($version_int) {
                $sphinx->SetFilterRange('max_ver', $version_int, 10*$version_int);
                $sphinx->SetFilterRange('min_ver', 0, $version_int);
            }
        }
        
        // type filter 
        if (preg_match('/\btype:(\w+)/', $term, $matches)) {
            $term = str_replace($matches[0], '', $term);
            $type = $this->convert_type($matches[1]);
            if ($type) {
                $sphinx->SetFilter('type', array($type));
            }
        }
        
        // platform
        if (preg_match('/\bplatform:(\w+)/', $term, $matches)) {
            $term = str_replace($matches[0], '', $term);
            $platform = $this->convert_platform($matches[1]);
            if ($platform) {
                $sphinx->SetFilter('platform', array($platform, PLATFORM_ALL));
            }
        }
        
        // date filter
        if (preg_match("{\bafter:([0-9-]+)\b}", $term, $matches)) {
            
            $term      = str_replace($matches[0], '', $term);
            $timestamp = strtotime($matches[1]);
            if ($timestamp) {
                $sphinx->SetFilterRange('modified', $timestamp, time());
            }
        }
        
        // category filter
        if (preg_match('/\bcategory:(\w+)/', $term, $matches)) {
            $term = str_replace($matches[0], '',$term);
            $category = $this->convert_category($matches[1]);
            $sphinx->setFilter('category', array($category));
        }
        
        if (preg_match('/\btag:(\w+)/', $term, $matches)) {
            $term = str_replace($matches[0], '',$term);
            $tag = $this->convert_tag($matches[1]);
            $sphinx->setFilter('tag', array($tag));
        }
        

        $result        = $sphinx->Query($term);
        $total_results = $result['total_found'];
        $matches       = array();

        if ($total_results) {
            foreach($result['matches'] AS $match) {
                $matches[] = $match['attrs']['addon_id'];
            }
        }

        return array($matches, $total_results);
    }
    
    public function validate_string($str, $regexp = '/\w+/')
    {
        if (!preg_match($regexp, $str))
        {
            throw Exception("String does not match requirement: ".$regexp);
        }
    }
    /**
     *  Takes a string and converts it to a category id.
     *  e.g. 'alerts' should return category 72
     */
    public function convert_category($str) {
        // right now we're just using a simple reverse lookup query that only searches using the english locale
        // query is safe since $str has to match \w+
        
        // prepared statements don't work
        $this->validate_string($str);
        
        $q  = "SELECT categories.id FROM categories, translations t "
            . "WHERE name = t.id and application_id = %s AND locale='en-US' AND localized_string LIKE '%s%%'";
        $q  = sprintf($q, APP_ID, $str);
        
        $results = $this->app_model->query($q);
        if (!empty($results[0]['categories']['id']))
        {
            return (int) $results[0]['categories']['id'];
        }
        return null;
    }

    /**
     *  Takes a string and converts it to a tag_id 
     *  TODO: in the future we want to use fulltext search but that requires a database change.
     *  e.g. 'alerts' should return category 72
     */
    public function convert_tag($str) {
        // right now we're just using a simple reverse lookup query that only searches using the english locale
        // query is safe since $str has to match \w+
        
        $this->validate_string($str);
            
        $q  = "SELECT id FROM tags WHERE tag_text = '%s'";
        $q  = sprintf($q, $str);
                 
        $results = $this->app_model->query($q);
        if (!empty($results[0]['tags']['id']))
        {
            return (int) $results[0]['tags']['id'];
        }
        return null;
    }
    
    
    /**
     *  Takes a string and converts it to a type_id 
     *  e.g. 'type:extension' should return type_id 1
     */
    public function convert_type($type) {
        switch($type)
        {
            case 'extensions':
            case 'extension':
                return ADDON_EXTENSION;
            case 'themes':
            case 'theme':
                return ADDON_THEME;
            case 'dicts':
            case 'dict':
                return ADDON_DICT;
            case 'languages':
            case 'language':
                return ADDON_LPAPP;
            case 'plugins':
            case 'plugin':
                return ADDON_PLUGIN;
        }
        return null;
    }
    
    /**
     * Takes a string and converts it to a platform id
     *  e.g. platform:linux => 2
     */
    public function convert_platform($str) {
        switch($str) {
            case 'all':
                return PLATFORM_ALL;
            case 'linux':
                return PLATFORM_LINUX;
            case 'mac':
                return PLATFORM_MAC;
            case 'bsd':
                return PLATFORM_BSD;
            case 'win':
                return PLATFORM_WIN;
            case 'sun':
                return PLATFORM_SUN;            
        }
    }
    
    public static function convert_version($ver) {
        $ver = str_replace('.x', '.99', $ver);
        $ver = str_replace('.*', '.99', $ver);
        
        if (preg_match('/(\d+)\+/', $ver, $matches)) {
            $pre = int($matches[1]) + 1;
            $ver = str_replace($matches[0], $pre, $ver);
        }
        
        if (preg_match('/(\d+)\.(\d+)\.?(\d+)?\.?(\d+)?([a|b]?)(\d*)(pre)?(\d)?()?/',$ver,$matches)) {
            list($full,$major,$minor1,$minor2,$minor3,$alpha,$alpha_n,$pre,$pre_n) = $matches;
            
            $minor2 = $minor2 ? $minor2 : 0;
            $minor3 = $minor3 ? $minor3 : 0;
            
            if ($alpha == 'a') {
                $alpha = 0;
            } elseif ($alpha == 'b') {
                $alpha = 1;
            } else {
                $alpha = 2;
            }
            
            $alpha_n = $alpha_n ? $alpha_n : 0;
            
            if ($pre == 'pre') {
                $pre = 0;
            } else {
                $pre = 1;
            }
            
            $pre_n = $pre_n ? $pre_n : 0;
            
            return sprintf("%02d%02d%02d%02d%d%02d%d%02d", $major,$minor1,$minor2,$minor3,$alpha,$alpha_n,$pre,$pre_n);

        }
        return 0;
    }

}