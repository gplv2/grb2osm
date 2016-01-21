#!/usr/bin/php
<?php
require_once('vendor/autoload.php');

use XBase\Table;

ini_set("max_execution_time", "0");
ini_set("max_input_time", "0");
set_time_limit(0);
ob_implicit_flush();
declare(ticks = 5);

require_once('cliargs.php');

$cliargs = array(
      'file' => array(
         'short' => 'f',
         'type' => 'required',
         'description' => "The name of the .dbf file(s), separate multiple with comma's",
         'default' => ''
         ),
      'osmfile' => array(
         'short' => 'i',
         'type' => 'required',
         'description' => "The name of the original .osm (xml) input file",
         'default' => ''
         ),
      'outfile' => array(
         'short' => 'o',
         'type' => 'optional',
         'description' => "The name of the target .osm (xml) output file",
         'default' => ''
         ),
      'debug' => array(
         'short' => 'd',
         'type' => 'optional',
         'description' => "This is a the debug flag, which will output what it is doing according to verbose level.",
         'default' => '0'
         )
);

/* command line errors are thrown hereafter */
$options = cliargs_get_options($cliargs);

if (isset($options['file'])) { $target_file  = trim($options['file']); } else { unset($target_file); }
if (isset($options['osmfile'])) { $osm_file  = trim($options['osmfile']); } else { unset($osm_file); }
if (isset($options['outfile'])) { $out_file  = trim($options['outfile']); } else { unset($out_file); }

if (empty($target_file)) {
    cliargs_print_usage_and_exit($cliargs);
    exit;
}

$osmtool = new OsmTool($options);
$osmtool->init_dbf($target_file);

/* open the osm file */
if (file_exists($osm_file)) {
    $osmtool->logtrace(3, sprintf("[%s] - Loading OSM file ...",__METHOD__));
    $xml=file_get_contents($osm_file);
    $osmtool->logtrace(3, sprintf("[%s] - Done ...",__METHOD__));

    /*
       <tag k='LBLTYPE' v='hoofdgebouw' />     -> building=house , bijgebouw=shed
       <tag k='LENGTE' v='178.72' />           -> delete this
       <tag k='OIDN' v='718523' />             -> source:geometry:oidn
       <tag k='OPNDATUM' v='2008-05-06' />     -> source:geometry:date
       <tag k='OPPERVL' v='695.41' />          -> delete this
       <tag k='TYPE' v='1' />                  -> delete this
       <tag k='UIDN' v='760315' />             -> delete this
       <tag k='type' v='multipolygon' />       -> don't touch

Add :

source:geometry=GRB  (change to AGIV when using agiv picture to correct bad GRB data
source=GRB

     */
    $osmtool->logtrace(3, sprintf("[%s] - Renaming GRB keys for ways/relations to OSM keys..",__METHOD__));

    /*
afdak
ingezonken garagetoegang
loopbrug
trap
uitbreiding
verdieping
verheven garagetoegang
zichtbare onderkeldering

   */
    $search  = array('LBLTYPE', 'OIDN', 'OPNDATUM', 'hoofdgebouw','bijgebouw','afdak','ingezonken garagetoegang','verheven garagetoegang');
    $replace = array('building', 'source:geometry:oidn', 'source:geometry:date','house','shed','roof','garage1','garage2');
    $xml=str_replace($search, $replace, $xml);
    $osmtool->logtrace(3, sprintf("[%s] - Done.",__METHOD__));

    $osmtool->logtrace(3, sprintf("[%s] - Parsing XML ...",__METHOD__));
    $service = new Sabre\Xml\Service();
    $result = $service->parse($xml,'osm');
    $osmtool->logtrace(3, sprintf("[%s] - Done ...",__METHOD__));
} else {
    $osmtool->logtrace(3, sprintf("[%s] - File %s not found",__METHOD__, $osm_file));
    exit;
}

/*

   Ways look like this in the file:

   <way id='-14375' action='modify' visible='true'>
   <nd ref='-14369' />
   <nd ref='-14370' />
   <nd ref='-14371' />
   <nd ref='-14372' />
   <nd ref='-14373' />
   <nd ref='-14374' />
   <nd ref='-14369' />
   <tag k='TYPE' v='2' />
   <tag k='building' v='roof' />
   <tag k='source:geometry:date' v='2015-01-14' />
   <tag k='source:geometry:oidn' v='368065' />
   </way>

   tags look like this when parsed: 

   [7] => Array
   (
   [name] => {}tag
   [value] => 
   [attributes] => Array
   (
   [k] => TYPE
   [v] => 2
   )

   )
   $osmtool->logtrace(3, sprintf("[%s] - Done parsing keys.",__METHOD__));
 */

$osmtool->logtrace(3, sprintf("[%s] - Analysing ways/relations having the source:geometry:oidn tag present...",__METHOD__));

$to_delete_list=array();

foreach ($result as $k => $v){
    if ($v['name'] == '{}way' || $v['name'] == '{}relation') {
        $has_source = false;
        $has_source_geometry = false;
        $has_tags=false;
        // print_r($v['value']);exit;
        // 
        $current_tags=array();


        foreach($v['value'] as $kk=>$vv) {
            /* keep track of these so we can rerun the tool on the output again without messing up*/
            if ($vv['name'] == '{}tag') {
                $current_tags[$vv['attributes']['k']]=true;
            }
        }
        //print_r($current_tags);

        foreach($v['value'] as $kk=>$vv) {
            if ($vv['name'] == '{}tag') {
                /* Unset useless tags */
                if ($vv['attributes']['k'] == 'TYPE' || $vv['attributes']['k'] == 'UIDN' ||
                        $vv['attributes']['k'] == 'LENGTE' || $vv['attributes']['k'] == 'OPPERVL') {
                    $osmtool->logtrace(5, sprintf("[%s] - Deleting useless keys.",__METHOD__));
                    unset($result[$k]['value'][$kk]);
                    $osmtool->counters['counter_deleted_tags']++;
                }

                /* Delete this type of building from the set */
                $todelete='trap';
                if (($vv['attributes']['k'] == 'building') && ($vv['attributes']['v']==$todelete)) {
                    $osmtool->logtrace(5, sprintf("[%s] - Adding useless trap to delete list.",__METHOD__));
                    $to_delete_list[] = $k;
                    continue;
                } 

                $todelete='verdieping';
                if (($vv['attributes']['k'] == 'building') && ($vv['attributes']['v']==$todelete)) {
                    $osmtool->logtrace(5, sprintf("[%s] - Adding useless trap to delete list.",__METHOD__));
                    $to_delete_list[] = $k;
                    continue;
                } 

                /* Delete this type of building from the set */
                $todelete='trap';
                if (($vv['attributes']['k'] == 'building') && ($vv['attributes']['v']==$todelete)) {
                    $osmtool->logtrace(5, sprintf("[%s] - Adding useless building to delete list.",__METHOD__));
                    $to_delete_list[] = $k;
                    continue;
                } 

                /* Start addressing buildings */
                if ($vv['attributes']['k'] == 'source:geometry:oidn') {
                    $oidn=$vv['attributes']['v'];

                    $osmtool->logtrace(5, sprintf("[%s] - Searching for address data on %s",__METHOD__, $oidn));
                    $data = $osmtool->get_oidn_address($oidn);

                    if(is_array($data)) {
                        $osmtool->logtrace(5, sprintf("[%s] - Found entry for %s",__METHOD__, $oidn));

                        $tag='busnr';
                        // $this->counters['counter_exist_addr']++
                        if(key_exists('addr:flats', $current_tags)) {
                            $osmtool->counters['counter_exist_flats']++;
                        } else {
                            if(key_exists($tag, $data) && strlen($data[$tag])>0) {
                                //$osmtool->logtrace(3, sprintf("[%s] - key %s",__METHOD__, $tag, $data[$tag]));
                                $adline = array ( 'name' => '{}tag' , 'value' => '', 'attributes' => array ( 'k' => 'addr:flats', 'v' => $data[$tag]) );
                                $osmtool->logtrace(5, sprintf("[%s] - address data: %s",__METHOD__, json_encode($adline)));
                                $result[$k]['value'][]=$adline;
                            }
                        }

                        $tag='huisnr';
                        if(key_exists('addr:housenumber', $current_tags)) {
                            $osmtool->counters['counter_exist_housenumber']++;
                        } else {
                            if(key_exists($tag, $data) && strlen($data[$tag])>0) {
                                //$osmtool->logtrace(3, sprintf("[%s] - key %s",__METHOD__, $tag, $data[$tag]));
                                $adline = array ( 'name' => '{}tag' , 'value' => '', 'attributes' => array ( 'k' => 'addr:housenumber', 'v' => $data[$tag]) );
                                $osmtool->logtrace(5, sprintf("[%s] - address data: %s",__METHOD__, json_encode($adline)));
                                $result[$k]['value'][]=$adline;
                            }
                        }

                        $tag='straatnm';
                        if(key_exists('addr:street', $current_tags)) {
                            $osmtool->counters['counter_exist_street']++;
                        } else {
                            if(key_exists($tag, $data) && strlen($data[$tag])>0) {
                                //$osmtool->logtrace(3, sprintf("[%s] - key %s",__METHOD__, $tag, $data[$tag]));
                                $adline = array ( 'name' => '{}tag' , 'value' => '', 'attributes' => array ( 'k' => 'addr:street' ,'v' => $data[$tag]) );
                                $osmtool->logtrace(5, sprintf("[%s] - address data: %s",__METHOD__, json_encode($adline)));
                                $result[$k]['value'][]=$adline;
                            }
                        }
                        unset($adline);
                    }
                }
            }
        }
        /* Adding source keys when they don't exist yet
source:geometry=GRB     (change to AGIV when using agiv sat pictures to correct bad looking GRB data )
(change to AGIV when repositioning the geometry/building)
source=GRB
         */
        if(!key_exists('source:geometry', $current_tags)) {
            $osmtool->logtrace(5, sprintf("[%s] - Adding source keys for %s",__METHOD__, $k));
            $adline = array ( 'name' => '{}tag' , 'value' => '', 'attributes' => array ( 'k' => 'source:geometry' ,'v' =>  'GRB') );
            $result[$k]['value'][]=$adline;
        } else {
            $osmtool->counters['counter_exist_geometry']++;
        }
        if(!key_exists('source', $current_tags)) {
            $adline = array ( 'name' => '{}tag' , 'value' => '', 'attributes' => array ( 'k' => 'source' ,'v' => 'GRB' ) );
            $result[$k]['value'][]=$adline;
        } else {
            $osmtool->counters['counter_exist_source']++;
        }
    }
}

if (isset($out_file)) {
    $osmtool->logtrace(3, sprintf("[%s] - Deleting ways: %s...",__METHOD__,json_encode($to_delete_list,true)));
    //print_r($to_delete_list);exit;
    foreach(array_reverse($to_delete_list) as $k=>$v) {
        array_splice($result, $v, 1);
    }
    $osmtool->logtrace(3, sprintf("[%s] - Done.",__METHOD__));

    $osmtool->logtrace(3, sprintf("[%s] - Filtering deleted elements from XML ...",__METHOD__));
    $result=array_filter($result);
    $osmtool->logtrace(3, sprintf("[%s] - Done.",__METHOD__));

    $osmtool->logtrace(3, sprintf("[%s] - Generating new XML ...",__METHOD__));
    $new_xml=$service->write('osm', $result);
    // unset($result);
    $osmtool->logtrace(3, sprintf("[%s] - Done.",__METHOD__));

    if (!empty($out_file)) {
        $osmtool->logtrace(3, sprintf("[%s] - Writing XML ...",__METHOD__));
        file_put_contents ( $out_file , $new_xml );
        $osmtool->logtrace(3, sprintf("[%s] - Done.",__METHOD__));
    }

    $osmtool->logtrace(3, sprintf("[%s] - Postprocessing new OSM XML file.. ",__METHOD__));
    // Post-parsing XML
    $handle = fopen($out_file, "r");

    $first  = fgets($handle,2048); // pop first line.
    $second = fgets($handle,2048); // pop second line.

    $outfile="temp";
    $outhandle = fopen($outfile,"w");

    $osmtool->logtrace(3, sprintf("[%s] - Replacing OSM xml header ",__METHOD__));

    fputs($outhandle,"<?xml version='1.0' encoding='UTF-8'?>\n"); // set first line.
    fputs($outhandle,"<osm version='0.6' upload='false' generator='grb2osm'>\n"); // set 2nd line.

    $osmtool->logtrace(3, sprintf("[%s] - Filter namespace extras from OSM xml",__METHOD__));
    while (!feof($handle)) {
        $buffer = fgets($handle,2048);
        // Filter namespace from output
        $buffer=preg_replace('/^\s/', '  ', $buffer);
        $newbuffer=str_replace('xmlns="" ','',$buffer);
        fwrite($outhandle,$newbuffer);
    }
    fclose($handle);
    fclose($outhandle);
    $osmtool->logtrace(3, sprintf("[%s] - done writing.",__METHOD__));
    rename($outfile,$out_file);
}

// print_r($result);

/* DBase Handler (dbf)
 *    handles dbase resource
 */

error_reporting(E_ALL);

class OsmTool {
    /* Settings storage */
    private $settings= null;

    private $configuration=null;
    private $target_file=null;


    /* db handle */
    private $db=false;
    private $last_error="";

    private $debug=1;
    private $verbose=3;

    private $addresses=array();

    public $counters=array('matches' => 0,
            'misses' => 0 ,
            'gbg_addressrecords' => 0 , 
            'adp_addressrecords' => 0 , 
            'knw_addressrecords' => 0 ,
            'merged_huisnr' => 0 ,
            'merged_busnr' => 0 ,
            'merged_appartnr' => 0 ,
            'purge_single_nvt' => 0 ,
            'address_records' => 0 ,
            'counter_deleted_tags' => 0 ,
            'multi_street_deleted' => 0 ,
            'empty_street_deleted' => 0 ,
            'counter_exist_addr' => 0,
            'counter_exist_street' => 0,
            'counter_exist_flats' => 0,
            'counter_exist_geometry' => 0,
            'counter_exist_source' => 0,
            'counter_exist_housenumber' => 0,
            'double_street_oids' => array() 
            );

    public function __construct($settings=null) {
        if(!empty($settings)) {
            $this->settings=$settings;
        }
        if (defined('STDIN')) {
            $this->eol="\n";
        } else {
            $this->eol="<BR/>";
        }
    }

    public function init_dbf() {
        $target_files = explode(',', $this->settings['file']);
        foreach($target_files as $k => $file) {
            //$this->addresses=$this->open_db($file);
            $this->addresses=array_replace($this->open_db($file), $this->addresses);
            // break;
        }
        // print_r($this->addresses); exit;

    }

    public function get_oidn_address($oidn) {

        if(!empty($oidn)) {
            if (key_exists($oidn, $this->addresses)) {
                $this->logtrace(5, sprintf("[%s] - found address data",__METHOD__));
                $this->counters['matches']++;
                return ($this->addresses[$oidn]);
            } else {
                $this->logtrace(5, sprintf("[%s] - oidn %s key not found ",__METHOD__,$oidn));
                $this->counters['misses']++;
                return (false);
            }
        } else {
            return false; 
        }
    }

    private function open_db($database)  {
        $this->logtrace(3, sprintf("[%s] - Start",__METHOD__));
        $return = 0;

        $this->logtrace(2, sprintf("[%s] - Trying to open DBase DB %s",__METHOD__,$database));

        $this->db = new Table(dirname(__FILE__). '/' . $database, null, 'CP1252');

        if (!$this->db) {
            $this->logtrace(0, sprintf("[%s] - Problem opening DB %s",__METHOD__,$database));
            exit;
        } else {
            $this->logtrace(2, sprintf("[%s] - Opened %s",__METHOD__,$database));
        }

        $addresses=array();

        // print_r($this->db);exit;
        /* find the column we need */
        $cols = $this->db->columns;

        $this->logtrace(3, sprintf("[%s] - Reading records...",__METHOD__));
        while ($record = $this->db->nextRecord()) {
            /*  [uidn] =>         3158854
                [oidn] =>         3139967
                [adpoidn] =>          301595  -> this renames depending on which file we open
                [huisnr] => 55         
                [busnr] => nvt       
                [appartnr] => nvt       
                [straatnmid] =>           30044
                [straatnm] => Kluisweg                                                                        
                [niscode] => 23096
                [gemeente] => Zemst                                   
                [postcode] => 1980
                [hnrlabel] => 55                 
             */
            if(isset($cols['gbgoidn'])) {
                $addresses[$record->gbgoidn][] = array( 'huisnr' => $record->huisnr, 'busnr' => $record->busnr, 'appartnr'=> $record->appartnr, 'straatnm'=> $record->straatnm, 'hnrlabel' => $record->hnrlabel);
                $this->counters['gbg_addressrecords']++;
            } elseif(isset($cols['adpoidn'])) {
                $addresses[$record->adpoidn][] = array( 'huisnr' => $record->huisnr, 'busnr' => $record->busnr, 'appartnr'=> $record->appartnr, 'straatnm'=> $record->straatnm, 'hnrlabel' => $record->hnrlabel);
                $this->counters['adp_addressrecords']++;
            } elseif(isset($cols['knwoidn'])) {
                $addresses[$record->knwoidn][] = array( 'huisnr' => $record->huisnr, 'busnr' => $record->busnr, 'appartnr'=> $record->appartnr, 'straatnm'=> $record->straatnm, 'hnrlabel' => $record->hnrlabel);
                $this->counters['knw_addressrecords']++;
            }
            $this->counters['address_records']++;
        }
        if ($this->counters['knw_addressrecords'] + $this->counters['adp_addressrecords'] + $this->counters['gbg_addressrecords'] <= 0 ) {
            $this->logtrace(3, sprintf("[%s] - No addresses loaded at all, pointless to continue.",__METHOD__));
            print_r($cols);
            $this->logtrace(3, sprintf("[%s] - tip: mod the code to find the correct colname from the list above.",__METHOD__));
            exit;
        } else {
            $this->logtrace(3, sprintf("[%s] - Done. loaded (%d)",__METHOD__, count($addresses)));
        }

        $this->logtrace(3, sprintf("[%s] - Postprocessing addresses.",__METHOD__));
        /*      [huisnr] => 613A
                [busnr] => nvt
                [appartnr] => nvt
                [straatnm] => Tervuursesteenweg
                [hnrlabel] => 613A-621 
         */
        foreach ($addresses as $k => $v) {
            if (is_array($v) && count($v)> 1) {
                $this->logtrace(4, sprintf("[%s] - Multi records found for building.",__METHOD__));
                $streetname = null;

                $hse = array (
                        'huisnr' => '',
                        'busnr' => '',
                        'appartnr' => '',
                        'straatnm' => ''
                        );

                foreach ($v as $building => $address) {
                    if (empty($streetname)) {
                        if (empty($address['straatnm'])) {
                            unset($addresses[$k]);
                            unset($hse);
                            $this->counters['empty_street_deleted']++;
                            break;
                        } else {
                            $streetname = $address['straatnm'];
                            $hse['straatnm'].=trim($address['straatnm']);
                        }
                    } elseif ( strcmp($streetname, $address['straatnm']) !== 0) {
                        // We have 2 streetnames for the same building, this is hard to fix on the building 
                        // ( entrances could be a solution), we should skip doing these automatically
                        unset($addresses[$k]);
                        unset($hse);
                        $this->counters['double_street_oids'][]=$k;
                        $this->counters['multi_street_deleted']++;
                        break;
                    }
                    if (!empty($address['huisnr']) && $address['huisnr']!=='nvt') {
                        $hse['huisnr'].=$address['huisnr'].';';
                        $this->counters['merged_huisnr']++;
                    }
                    if (!empty($address['busnr']) && $address['busnr']!=='nvt') {
                        $hse['busnr'].=$address['busnr'].';';
                        $this->counters['merged_busnr']++;
                    }
                    if (!empty($address['appartnr']) && $address['appartnr']!=='nvt') {
                        $hse['appartnr'].=$address['appartnr'].';';
                        $this->counters['merged_appartnr']++;
                    }
                }
                if(!empty($hse)) {
                    // print_r($hse);

                    $this->logtrace(5, sprintf("[%s] - Cleaning trailing ';'.",__METHOD__));
                    if(strlen($hse['huisnr'])) {
                        $hse['huisnr']=$this->mychomp($hse['huisnr']);
                    }
                    if(strlen($hse['busnr'])) {
                        $hse['busnr']=$this->mychomp($hse['busnr']);
                    }
                    if(strlen($hse['appartnr'])) {
                        $hse['appartnr']=$this->mychomp($hse['appartnr']);
                    }
                    $this->logtrace(5, sprintf("[%s] - Natural sorting housenumbers.",__METHOD__));
                    $this->logtrace(5, sprintf("[%s] - Prune identical multi value.",__METHOD__));

                    foreach($hse as $item => $numbers) {
                        $temp_array = explode(";", $numbers);
                        natsort($temp_array);
                        $temp_array=$this->superfast_array_unique($temp_array);
                        if (count($temp_array)>1) {
                            if (!isset($this->counters['counter_flattened_'.$item])){
                                $this->counters['counter_flattened_'.$item]=0;
                            }
                            $this->counters['counter_flattened_'.$item]++;
                        }
                        $hse[$item] = implode(";", $temp_array);
                    }
                    // $this->logtrace(3, sprintf("[%s] - Printing debug.",__METHOD__));
                    // print_r($hse);exit;
                    if (count($temp_array) >= 7) {
                        print_r($hse);exit;
                    }

                    $addresses[$k] = $hse;
                }
            } else {
                $addresses[$k] = array_pop($v);
            }
        }
        $this->logtrace(3, sprintf("[%s] - Flattened multiple records on oid.",__METHOD__));

        $this->logtrace(3, sprintf("[%s] - Cleaning up...",__METHOD__));
        foreach ($addresses as $oid => $value) {
            $keys = array_keys($value);

            foreach ($keys as $kk => $key) {
                if ($value[$key] == 'nvt') {
                    unset($addresses[$oid][$key]);
                    $this->counters['purge_single_nvt']++;
                }
            }
        }
        $this->logtrace(3, sprintf("[%s] - Purged 'nvt' values .",__METHOD__));

        //print_r($addresses);exit;
        return($addresses);
    }

    public function logtrace($level,$msg) {

        $DateTime=@date('Y-m-d H:i:s', time());

        if ( $level <= $this->verbose ) {
            $mylvl=NULL;
            switch($level) {
                case 0:
                    $mylvl ="error";
                    break;
                case 1:
                    $mylvl ="core ";
                    break;
                case 2:
                    $mylvl ="info ";
                    break;
                case 3:
                    $mylvl ="notic";
                    break;
                case 4:
                    $mylvl ="verbs";
                    break;
                case 5:
                    $mylvl ="dtail";
                    break;
                default :
                    $mylvl ="exec ";
                    break;
            }
            // 2008-12-08 15:13:06 [31796] - [1] core    - Changing ID
            //"posix_getpid()=" . posix_getpid() . ", posix_getppid()=" . posix_getppid();
            $content = $DateTime. " [" .  posix_getpid() ."]:[" . $level . "]" . $mylvl . " - " . $msg . "\n";
            echo $content;
            /* called with -d to skip deamonizing , don't write to log cos process ID's are the same*/
            $ok=0;
        }
    }

    public function superfast_array_unique($array) { 
        return array_keys(array_flip($array)); 
    } 

    public function __destruct() {
        foreach ($this->counters as $k => $v){
            if(is_array($v)) { 
                $this->logtrace(1, sprintf("[%s] %s list",__METHOD__,$k));
                /*
                   foreach($v as $kk => $vv) {
                   $this->logtrace(1, sprintf("[%s] oidn = %s",__METHOD__,$vv));
                   }
                 */
            } else{
                $this->logtrace(1, sprintf("[%s] %s = %s",__METHOD__,$k,$v));
            }
        }
    }

    public function mychomp($string) {
        /* I just miss working with perl */
        if (is_array($string)) {
            foreach($string as $i => $val) {
                $endchar = chomp($string[$i]);
            }
        } else {
            $endchar = substr("$string", strlen("$string") - 1, 1);
            $string = substr("$string", 0, -1);
        }
        return $string;
    }
}

?>
