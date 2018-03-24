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
         'type' => 'optional',
         'description' => "The name of the original .osm (xml) input file",
         'default' => ''
         ),
      'outfile' => array(
         'short' => 'o',
         'type' => 'optional',
         'description' => "The name of the target .osm (xml) output file",
         'default' => 'database'
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

if (isset($options['outfile']) && $options['outfile']=='database') {
   $host = "127.0.0.1"; 
   $user = "grb-data"; 
   $pass = "str0ngDBp4ssw0rd"; 
   $db   = "grb_api"; 

   $con = pg_connect("host=$host dbname=$db user=$user password=$pass") or die ("Could not connect to server\n"); 

   $adcounter=0;

   foreach($osmtool->get_all_oidn_address() as $entity_oidn => $val) {
      list($entity, $oidn) = preg_split('/_/', $entity_oidn, -1, PREG_SPLIT_NO_EMPTY);

      $range_update=false;
      $adcounter++;
      // print_r($val);
      $update="";

      $blah = array();
      $tag='busnr';
      // $this->counters['counter_exist_addr']++
      if(key_exists($tag, $val) && strlen($val[$tag])) {
         //$osmtool->counters['counter_exist_flats']++;
         $blah['addr:flats']=$osmtool->number2range(trim($val[$tag]));
         //$osmtool->logtrace(5, sprintf("[%s] - address data: %s",__METHOD__, json_encode($adline)));
         // $osmtool->logtrace(3, sprintf("[%s] - Flats Range: %s ",__METHOD__, $blah['addr:flats']));
         $pos = strpos($blah['addr:flats'], ';');
         if ($pos !== false) {
            $range_update=true;
         }
      }

      $tag='huisnr';
      if(key_exists($tag, $val) && strlen($val[$tag])) {
         //$osmtool->counters['counter_exist_housenumber']++;
         $blah['addr:housenumber']=$osmtool->number2range(trim($val[$tag]));
         // $osmtool->logtrace(3, sprintf("[%s] - House Range: %s ",__METHOD__, $blah['addr:housenumber']));
         $pos = strpos($blah['addr:housenumber'], ';');
         if ($pos !== false) {
            $range_update=true;
         }
      }

      $tag='straatnm';
      if(key_exists($tag, $val) && strlen($val[$tag])) {
         //$osmtool->counters['counter_exist_street']++;
         $blah['addr:street']=trim($val[$tag]);
      }

      if($range_update || 1==1) {
         foreach ($blah as $key => $set) {
            $osmtool->logtrace(6, sprintf("[%s] blah[%s] = %s",__METHOD__,$key,$set));
             $set = ltrim($set, ';');
             $set = rtrim($set, ';');
            $update.=sprintf("\"%s\" = '%s' ,", pg_escape_string($key), pg_escape_string($set));
         }
         $update=$osmtool->mychomp($update);

         $query=sprintf("UPDATE planet_osm_polygon SET %s WHERE \"source:geometry:oidn\" = '%s' AND \"source:geometry:entity\" = '%s' ",$update,pg_escape_string($oidn),pg_escape_string($entity));
         //  UPDATE planet_osm_polygon SET "addr:housenumber" = 'Smissestraat'  WHERE "source:geometry:oidn" = '6433';
         //echo $query.PHP_EOL;
         $osmtool->counters['update_to_db']++;
         $result = pg_query($query); 
         if(pg_affected_rows ( $result )) {
            $osmtool->counters['updated_in_db']+=pg_affected_rows ( $result );
            if($adcounter % 500 === 0 ) {
               $osmtool->logtrace(3, sprintf("[%s] - Updated %d records in DB ...",__METHOD__, $osmtool->counters['updated_in_db']));
               $osmtool->logtrace(4, sprintf("[%s] - QRY: %s",__METHOD__, $query));
            }
         }
      }
   }
exit;
   // Fix up the DB (remove duplicate stuff)
   // select osm_id,"source:geometry:oidn","source:geometry:date" from planet_osm_polygon where "source:geometry:oidn" = '1000000';
   // Getting list of duplicate oidn's

   // select "source:geometry:oidn", count(*) from planet_osm_polygon group by "source:geometry:oidn" HAVING count(*)>1 limit 10;
   $query=sprintf("SELECT \"source:geometry:oidn\" from planet_osm_polygon group by \"source:geometry:oidn\" HAVING count(*)>1");
   $osmtool->logtrace(3, sprintf("[%s] - QRY: %s",__METHOD__, $query));
   $result = pg_query($query); 
   $numrows = pg_num_rows($result);
   $osmtool->logtrace(3, sprintf("[%s] - Found: %s",__METHOD__, $numrows));
   if($numrows) {
      while ($row = pg_fetch_assoc($result)) {
         $grb_dates = array();
         $qr=sprintf("SELECT osm_id,\"source:geometry:oidn\",\"source:geometry:date\" FROM planet_osm_polygon WHERE \"source:geometry:oidn\" = '%s'",pg_escape_string($row['source:geometry:oidn']));
         $osmtool->logtrace(3, sprintf("[%s] - QRY: %s",__METHOD__, $qr));
         $res = pg_query($qr); 
         $numrows = pg_num_rows($res);
         $osmtool->logtrace(3, sprintf("[%s] - Found: %s",__METHOD__, $numrows));

         while ($r = pg_fetch_assoc($res)) {
            $grb_dates[] = $r['source:geometry:date'];
            $osm_ids[] = $r['osm_id'];
         }
         //pg_free_result ($r);

         usort($grb_dates, 'cmp');
         usort($osm_ids, 'cmp');

         //print_r($grb_dates);
         //print_r($osm_ids);exit;

         $delqry=sprintf("DELETE FROM planet_osm_polygon WHERE \"source:geometry:oidn\" = '%s' AND \"source:geometry:date\" <> '%s'", pg_escape_string($row['source:geometry:oidn']), pg_escape_string(array_shift($grb_dates)));
         $osmtool->logtrace(4, sprintf("[%s] - QRY: %s",__METHOD__, $delqry));
         $delresult = pg_query($delqry); 
         if(pg_affected_rows ( $delresult )) {
            $osmtool->logtrace(3, sprintf("[%s] - Deleted: %s",__METHOD__, pg_affected_rows ( $delresult )));
            $osmtool->counters['deleted_in_db']+=pg_affected_rows ( $delresult );
         }
         if (pg_affected_rows ( $delresult ) == 0 ) {
            $delqry=sprintf("DELETE FROM planet_osm_polygon WHERE osm_id = '%s'", pg_escape_string(array_shift($osm_ids)));
            $osmtool->logtrace(4, sprintf("[%s] - QRY: %s",__METHOD__, $delqry));
            $delresult = pg_query($delqry); 
            if(pg_affected_rows ( $delresult )) {
               $osmtool->logtrace(3, sprintf("[%s] - Deleted: %s",__METHOD__, pg_affected_rows ( $delresult )));
               $osmtool->counters['deleted_in_db']+=pg_affected_rows ( $delresult );
            }
         }

      }
   }
   exit;
}

if (isset($options['outfile']) && $options['outfile']=='database') {
  // Load this stuff in the db
  exit;
}

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
                    $osmtool->logtrace(5, sprintf("[%s] - Adding useless verdieping to delete list.",__METHOD__));
                    $to_delete_list[] = $k;
                    continue;
                } 

                /* Delete this type of building from the set */
                $todelete='gebouw afgezoomd met virtuele gevels';
                if (($vv['attributes']['k'] == 'building') && ($vv['attributes']['v']==$todelete)) {
                    $osmtool->logtrace(5, sprintf("[%s] - Adding useless virtual building to delete list.",__METHOD__));
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
        $buffer=preg_replace('/visible="true"/', 'version="1" timestamp="1970-01-01T00:00:01Z" changeset="1" visible="true"', $newbuffer);

        fwrite($outhandle,$buffer);
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
    private $verbose=4;

    private $addresses=array();

    public $counters=array('matches' => 0,
            'misses' => 0 ,
            'gbg_addressrecords' => 0 , 
            'adp_addressrecords' => 0 , 
            'knw_addressrecords' => 0 ,
            'unknown_addressrecords' => 0 ,
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
            'update_to_db' => 0,
            'deleted_in_db' => 0,
            'updated_in_db' => 0,
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

    public function get_all_oidn_address() {
        if(!empty($this->addresses)) {
         return($this->addresses);
         //return($this->addresses[4559386]);
      }
    }

    private function open_db($database)  {
        $this->logtrace(3, sprintf("[%s] - Start",__METHOD__));
        $return = 0;

        $this->logtrace(2, sprintf("[%s] - Trying to open DBase DB %s",__METHOD__,$database));

        /* Extract the entity as oidn col turns out to be unique only within the same entity */
        $base = basename($database, ".dbf");
        if (preg_match('/^Tbl(\w{3})Adr.*/',$base,$matches)) {
            print_r($matches);
        }

        if (count($matches) == 2 ) {
            $entity=$matches[1];
        } else {
            $entity='NULL'; // If a file isn't recognised, we need to skip
            return array();
        }

        //$this->db = new Table(dirname(__FILE__). '/' . $database, null, 'CP1252');
        $this->db = new Table($database, null, 'CP1252');

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
                $addresses[$entity.'_'.$record->gbgoidn][] = array( 'huisnr' => $record->huisnr, 'busnr' => $record->busnr, 'appartnr'=> $record->appartnr, 'straatnm'=> $record->straatnm, 'hnrlabel' => $record->hnrlabel);
                $this->counters['gbg_addressrecords']++;
            } elseif(isset($cols['adpoidn'])) {
                $addresses[$entity.'_'.$record->adpoidn][] = array( 'huisnr' => $record->huisnr, 'busnr' => $record->busnr, 'appartnr'=> $record->appartnr, 'straatnm'=> $record->straatnm, 'hnrlabel' => $record->hnrlabel);
                $this->counters['adp_addressrecords']++;
            } elseif(isset($cols['knwoidn'])) {
                $addresses[$entity.'_'.$record->knwoidn][] = array( 'huisnr' => $record->huisnr, 'busnr' => $record->busnr, 'appartnr'=> $record->appartnr, 'straatnm'=> $record->straatnm, 'hnrlabel' => $record->hnrlabel);
                $this->counters['knw_addressrecords']++;
            } else {
                $this->counters['unknown_addressrecords']++;
            }
            $this->counters['address_records']++;
        }

        if ($this->counters['knw_addressrecords'] + $this->counters['adp_addressrecords'] + $this->counters['gbg_addressrecords'] <= 0 ) {
            $this->logtrace(3, sprintf("[%s] - No addresses loaded at all, pointless to continue.",__METHOD__));
            print_r($cols);
            $this->logtrace(3, sprintf("[%s] - tip: mod the code to find the correct colname from the list above.",__METHOD__));
            exit;
        } else {
            $this->logtrace(3, sprintf("[%s] - gbg_addressrecords %s.",__METHOD__,$this->counters['gbg_addressrecords']));
            $this->logtrace(3, sprintf("[%s] - adp_addressrecords %s.",__METHOD__,$this->counters['adp_addressrecords']));
            $this->logtrace(3, sprintf("[%s] - knw_addressrecords %s.",__METHOD__,$this->counters['knw_addressrecords']));
            $this->logtrace(3, sprintf("[%s] - unknown entity addressrecords %s.",__METHOD__,$this->counters['unknown_addressrecords']));
            $this->logtrace(3, sprintf("[%s] - Done. loaded (%d)",__METHOD__, count($addresses)));
        }

        $this->logtrace(3, sprintf("[%s] - Postprocessing addresses (%d).",__METHOD__,$this->counters['address_records']));
        /*      [huisnr] => 613A
                [busnr] => nvt
                [appartnr] => nvt
                [straatnm] => Tervuursesteenweg
                [hnrlabel] => 613A-621 
         */
        foreach ($addresses as $k => $v) {
            if (is_array($v) && count($v)> 1) {
                $this->logtrace(5, sprintf("[%s] - Multi records found for building.",__METHOD__));
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

         //print_r($addresses[4938126]);//exit;
         //print_r($addresses[4559386]);exit;
        //print_r($addresses);exit;
        //return($addresses[4559386]);
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

    static public function number2range($housenumber) {
       $numbers = preg_split('/;/', $housenumber, -1, PREG_SPLIT_NO_EMPTY);
       asort($numbers);
       natsort($numbers);
       $numbers=array_values($numbers);

       $hexes= array();
       $output = array();

       $alfa = array();
       $nume = array();

       $first = $last = null;

       foreach ($numbers as $this_number) {
          // filter out numbers with dot in
          $pos = strpos($this_number, '.');
          if ($pos !== false) {
             continue;
          }
          // filter out numbers with / in
          $pos = strpos($this_number, '/');
          if ($pos !== false) {
             continue;
          }

          // filter out leading zeros
          $this_number = ltrim($this_number, '0');

          if (ctype_digit($this_number)) {
             $nume[]=$this_number;
          } elseif(ctype_alnum($this_number)) {
             $alfa[]=$this_number;
          } else {
             // drop whatever else 
          }
       }

       foreach ($nume as $this_number) {
          if ($first === null) {
             $first = $last = $this_number;
          } 
          if ($last < $this_number - 1) {
             if(is_numeric($this_number)) {
                $output[] = $first == $last ? $first : $first . '-' . $last;
             }
             $first = $last = $this_number;
          } else {
             $last = $this_number;
          }
       }
       $output[] = $first == $last ? $first : $first . '-' . $last;

       foreach ($alfa as $this_number) {
          if (!is_integer($this_number)) {
             $this_number=trim($this_number);

             if (preg_match("/([0-9]+)([A-Z+])/ui", $this_number, $match)) {
                if(isset($match[1])) {
                   if(!isset($hexes[$match[1]],$hexes)) {
                      $hexes[$match[1]]=$match[2];
                   } else {
                      $hexes[$match[1]].=';'.$match[2];
                   }
                }
             }
          }
       }

       $res=array();

       foreach ($hexes as $k => $v) {
          $ranges=array();
          $chars = preg_split('/;/', $v, -1, PREG_SPLIT_NO_EMPTY);
          $first = $last = null;
          foreach ($chars as $char) {
             // echo "ORD : (".$char .  ") -> ". ordutf8(strtoupper($char)) . " / " . utf8chr(ordutf8(strtoupper($char)))  . PHP_EOL;
             $this_char=ordutf8(strtoupper($char));

             if ($first === null) {
                $first = $last = $this_char;
             } 
             if ($last < $this_char - 1) {
                $ranges[] = $first == $last ? $first : $first . '-' . $last;
                $first = $last = $this_char;
             } else {
                $last = $this_char;
             }
          }
          $ranges[] = $first == $last ? $first : $first . '-' . $last;
          $res[$k]=$ranges;
       }

       $numstr="";
       $nnum=array();
       foreach($res as $number => $ranges) {
          foreach($ranges as $k => $range) {
             $newnumbers=array();
             $srange = preg_split('/-/', $range, -1, PREG_SPLIT_NO_EMPTY);
             $str_range=array();
             foreach($srange as $kk => $vv) {
                $str_range[]=utf8chr($vv);
             }
             foreach($str_range as $val) {
                $newnumbers[]=sprintf('%s%s',$number, $val);
             }
             $num=join('-', $newnumbers);
             $nnum[]=$num;
          }
       }
       
       // echo sprintf("[%s] - %s\n",__METHOD__, print_r($nnum,true));
       if (is_array($nnum) && is_array($output)) {
          $nn=array_merge($output,$nnum);
          $numstr=join(';', $nn);
          return($numstr);
       } elseif (is_array($output)) {
          return($output);
       } else {
          return(array());
       }  
    }
}

function ordutf8($string, &$offset=0) {
   $code = ord(substr($string, $offset,1)); 
   if ($code >= 128) {        //otherwise 0xxxxxxx
      if ($code < 224) $bytesnumber = 2;                //110xxxxx
      else if ($code < 240) $bytesnumber = 3;        //1110xxxx
      else if ($code < 248) $bytesnumber = 4;    //11110xxx
      $codetemp = $code - 192 - ($bytesnumber > 2 ? 32 : 0) - ($bytesnumber > 3 ? 16 : 0);
      for ($i = 2; $i <= $bytesnumber; $i++) {
         $offset ++;
         $code2 = ord(substr($string, $offset, 1)) - 128;        //10xxxxxx
         $codetemp = $codetemp*64 + $code2;
      }
      $code = $codetemp;
   }
   $offset += 1;
   if ($offset >= strlen($string)) $offset = -1;
   return $code;
}

function utf8chr($u) {
   return mb_convert_encoding('&#' . intval($u) . ';', 'UTF-8', 'HTML-ENTITIES');
}


function cmp($a, $b)
{
    global $array;
    return strcmp($array[$a]['db'], $array[$b]['db']);
}
