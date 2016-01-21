#!/usr/bin/php -q
<?php

require_once('cliargs.php');
require_once('class.colors.php');
require_once('GeoCalc.class.php');

// To get velo data

// curl 'https://www.velo-antwerpen.be/availability_map/getJsonObject' -X POST -H 'Pragma: no-cache' -H 'Origin: https://www.velo-antwerpen.be' -H 'Accept-Encoding: gzip, deflate' -H 'Accept-Language: en,en-US;q=0.8,nl;q=0.6,af;q=0.4,fr;q=0.2' -H 'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/43.0.2357.134 Safari/537.36' -H 'Accept: application/json, text/javascript, */*; q=0.01' -H 'Cache-Control: no-cache' -H 'X-Requested-With: XMLHttpRequest' -H 'Cookie: SSESS6abe6250285b8f401fa735a929f45c5b=e-MgGG-o9IPiwk4L_sjh0fIjR9W5rMS0fCZfgDlaSNY; _dc_gtm_UA-50166821-1=1; velo_cookie_bar=accepted; has_js=1; _ga=GA1.2.1155460175.1447336085' -H 'Connection: keep-alive' -H 'Referer: https://www.velo-antwerpen.be/nl/station-vinden' -H 'Content-Length: 0' --compressed

// Overpass xml export velo data
/*

{{key=amenity}}
{{val=bicycle_rental}}
<osm-script output="xml">
  <query type="node">
    <has-kv k="{{key}}" v="{{val}}"/>
    <bbox-query {{bbox}}/>
  </query>
    <print mode="meta"/>
  <recurse type="down"/>
  <print mode="meta"/>
</osm-script>

*/


// Test some basic printing with Colors class
//echo $colors->getColoredString("Testing Colors class, this is purple string on yellow background.", "purple", "yellow") . "\n";
//echo $colors->getColoredString("Testing Colors class, this is blue string on light gray background.", "blue", "light_gray") . "\n";
//echo $colors->getColoredString("Testing Colors class, this is red string on black background.", "red", "black") . "\n";
//echo $colors->getColoredString("Testing Colors class, this is cyan string on green background.", "cyan", "green") . "\n";
//echo $colors->getColoredString("Testing Colors class, this is cyan string on default background.", "cyan") . "\n";
//echo $colors->getColoredString("Testing Colors class, this is default string on cyan background.", null, "cyan") . "\n";

$verbose=4;
$new_counter=-347;

$cliargs= array(
      'file' => array(
         'short' => 'f',
         'type' => 'required',
         'description' => "The name of the velo file to parse the JSON from.",
         'default' => ''
         ),
      'osm' => array(
         'short' => 'o',
         'type' => 'required',
         'description' => "The name of the OSM file parse the XML from.",
         'default' => ''
         ),
      'format' => array(
         'short' => 't',
         'type' => 'optional',
         'description' => "The name of the output extension (json, geojson, osm).",
         'default' => ''
         )
      );
$osm_template=<<<EOD
<?xml version='1.0' encoding='UTF-8'?>
<osm version='0.6' upload='true' generator='JOSM'>
%s
</osm>
EOD;

$osm_obj_template=<<<EOD
\n<node %s>
%s</node>
EOD;

$osm_tag_template=<<<EOD
<tag k='%s' v='%s' />\n
EOD;

$geojson_template=<<<EOD
{
      "type":"FeatureCollection",
      "generator":"JOSM",
      "features":[
         %s
         ]
}
EOD;

$geojson_obj_template=<<<EOD
{
   "type":"Feature",
      "properties":{
         "ref":"%s",
         "amenity":"bicycle_rental",
         "name":"%s",
         "capacity":"%s",
         "network":"Velo"
      },
      "geometry":{
         "type":"Point",
         "coordinates":[
            %.7f,
            %.7f
            ]
      }
},
EOD;

/* command line errors are thrown hereafter */
$options = cliargs_get_options($cliargs);

if (isset($options['file'])) { $filename  = trim($options['file']); } else { unset($filename); }
if (isset($options['osm'])) {  $osmfile  = trim($options['osm']); } else { unset($osmfile); }
if (isset($options['format'])) { $output  = trim($options['format']); } else { unset($output); }

$cur_dir = realpath(".");

//$xml = simplexml_load_file('bicycle_antwerpen.osm');

if (!file_exists($osmfile)) { die("File $osmfile not found"); }
if (!file_exists($filename)) { die("File $filename not found"); }

// Load up JOSM / Overpass xml
$xml = simplexml_load_file($osmfile);
$marray=(json_decode(json_encode((array) $xml), 1));
$marra=$marray['node'];

$new_nodes=array();

// Extract OSM node information and build information array
foreach ($marra as $knode => $node) {
    $node_info=$node['@attributes'];
    $key= array_value_recursive('k', $node['tag']);
    $val= array_value_recursive('v', $node['tag']);
    $node_tags=array_combine($key, $val);
    //print_r($node_info);
    //print_r($node_tags);
    $new_nodes[$node_info['id']]['tags']=$node_tags;
    $new_nodes[$node_info['id']]['info']=$node_info;
}

//print_r($new_nodes);

// Extract JSON node information 
$crc=null;
$arr=array();
if (file_exists($filename)) {
    if($content=@file_get_contents($filename)) {
        $crc=md5($content);
        echo $filename . " \tsize: " . filesize($filename) . " \tcrc: " . $crc . "\n";
        $arr=json_decode($content,true);
        if (isset($output) && $output=="geojson") {
            $features="";
            foreach($arr as $k => $feature) {
                $features.=sprintf($geojson_obj_template, $feature['id'], $feature['name'], $feature['slots'], $feature['lon'], $feature['lat']);
            }
            my_chomp($features);
            echo sprintf($geojson_template,$features);
            exit;
        }
    } 
}
/*
Array
(
    [type] => FeatureCollection
    [generator] => JOSM
    [features] => Array
        (
            [0] => Array
*/

//print_r($arr); exit;
/*
Array
(
    [type] => Feature
    [properties] => Array
        (
            [ref] => 150
            [amenity] => bicycle_rental
            [name] => Waterhoenlaan
            [capacity] => 24
            [network] => Velo
        )

    [geometry] => Array
        (
            [type] => Point
            [coordinates] => Array
                (
                    [0] => 4.3817159
                    [1] => 51.2200208
                )

        )

)

    [146] => Array
        (
            [id] => 151
            [district] => 
            [lon] => 4.378407092993419000
            [lat] => 51.22031300886957000
            [bikes] => 19
            [slots] => 17
            [zip] => 2050
            [address] => Blancefloerlaan/Halewijnlaan
            [addressNumber] => 
            [nearbyStations] => 149,150,152,153
            [status] => OPN
            [name] => 151- Van Cauwelaert
        )


*/

$mod_nodes=array();
foreach($arr as $k => $node) {
    echo PHP_EOL;
    if(!isset($node['id']) && isset($node['name'])) { 
        logtrace(4,sprintf("[%s] - Parsing features ref '%s'",__METHOD__,$node['name']));
    } else {
        logtrace(4,sprintf("[%s] - Parsing features ref '%s'",__METHOD__,$node['id']));
        $osm_info = search_node($node['id'], $new_nodes);
        //print_r($new_nodes);
        if (count($osm_info)) {
            logtrace(3,sprintf("[%s] - Found OSM information tags count: %d",__METHOD__,count($osm_info['tags'])));
            //print_r($osm_info);
            //print_r($node);exit;
            scan_node($osm_info, $node);
            $mod_nodes[]=$osm_info;
/*
            if(is_array($new_node)) {
                $arr[$k]=$new_node;
            }
*/
        } else {
            logtrace(3,sprintf("[%s] - Missing OSM information on %s",__METHOD__,print_r($node,true)),'red');
            // Add this node to OSM
            $new_node=create_node($node);
            if(is_array($new_node)) {
                logtrace(3,sprintf("[%s] - Adding node to OSM %s",__METHOD__,print_r($new_node,true)),'green');
                //print_r($new_node);exit;
                $mod_nodes[]=$new_node;
                //$arr[]=$new_node;
            }
        }
    }
}

$output="";
// output
foreach($mod_nodes as $k => $node) {
/*  
   [148] => Array
        (
            [tags] => Array
                (
                    [amenity] => bicycle_rental
                    [capacity] => 36
                    [name] => Beatrijslaan
                    [network] => Velo
                    [ref] => 153
                )

            [info] => Array
                (
                    [id] => 2346308570
                    [timestamp] => 2013-06-15T18:14:52Z
                    [uid] => 343052
                    [user] => HenningS
                    [visible] => true
                    [version] => 1
                    [changeset] => 16566499
                    [lat] => 51.2185286
                    [lon] => 4.3857064
                    [action] => modify
                )

        )
    print_r($mod_nodes);exit;
*/
    $nn="";
    $mm="";
    foreach($node['info'] as $kk =>$vv) {
        $nn.=sprintf (" %s='%s'", $kk, (string)$vv);
    }
    //print_r($node['tags']);exit;
    foreach($node['tags'] as $kk =>$vv) {
        $mm.=sprintf($osm_tag_template,$kk,$vv);
    }

    $output.=sprintf($osm_obj_template, $nn, $mm);

    $nn="";
    $mm="";
}
    echo sprintf($osm_template,$output);exit;


function array_value_recursive($key, array $arr){
    $val = array();
    array_walk_recursive($arr, function($v, $k) use($key, &$val){
        if($k == $key) array_push($val, $v);
    });
    return count($val) > 1 ? $val : array_pop($val);
}

function search_node($key, array $arr){
    logtrace(5,sprintf("[%s] - Searching for ref data in OSM.. ",__METHOD__,$key));

    foreach($arr as $nodeid => $info) {
        if (isset($info['tags']['ref'])) {
            if ($info['tags']['ref']==$key) {
                return($info); 
            }
        }
    }
    return array();
}
/*
Array
(
    [tags] => Array
        (
            [amenity] => bicycle_rental
            [capacity] => 24
            [name] => Oude Kerk Berchem
            [network] => Velo
            [ref] => 103
        )

    [info] => Array
        (
            [id] => 1330951823
            [timestamp] => 2011-06-19T15:22:39Z
            [uid] => 343052
            [user] => HenningS
            [visible] => true
            [version] => 1
            [changeset] => 8485179
            [lat] => 51.1929087
            [lon] => 4.420915
        )

)

    [id] => 73
    [district] => 
    [lon] => 4.403718000000000000
    [lat] => 51.21230900000000000
    [bikes] => 11
    [slots] => 25
    [zip] => 2000
    [address] => Maarschalk Gerardstraat/schermersstraat
    [addressNumber] => 
    [nearbyStations] => 72,77,79,84
    [status] => OPN
    [name] => 073- Maarschalk Gérard

Array
(
    [tags] => Array
        (
            [amenity] => bicycle_rental
            [name] => Centraal Station - Astrid
            [network] => Velo
            [ref] => 001
        )

    [info] => Array
        (
            [id] => 248777652
            [timestamp] => 2012-11-27T18:17:46Z
            [uid] => 6072
            [user] => Eimai
            [visible] => true
            [version] => 7
            [changeset] => 14062248
            [lat] => 51.2176977
            [lon] => 4.4206246
        )

)


*/
function change_node(array &$osm_node, array $changes){
    if (is_array($changes) and count($changes)) {
        $changes['action']='modify';
        //print_r($changes);exit;
        logtrace(4,sprintf("[%s] - Applying changes to OSM.. %d",__METHOD__,count($changes)));
        //print_r($changes);exit;
        foreach ($changes as $k=>$v) {
            if (in_array($k,array('lat','lon','action'))) {
                $osm_node['info'][$k]=$v;
                //$osm_node;
            }
            if (in_array($k,array('ref','name','network','capacity'))) {
                $osm_node['tags'][$k]=$v;
            }
        }
        print_r($changes);
    }
}

function create_node(array $node){

   global $new_counter;
/* new node

<node id='-347' action='modify' visible='true' lat='51.21380841952' lon='4.39336913854'>
    <tag k='amenity' v='bicycle_rental' />
    <tag k='capacity' v='33' />
    <tag k='name' v='glenn' />
    <tag k='network' v='Velo' />
    <tag k='ref' v='999' />
  </node>

*/
    $n=array();
    $name_arr=explode('-',$node['name']);
    if (!strlen($name_arr[1])) {
        $name_arr[1]=trim($node['name']);
    }

    if (!isset($name_arr[0])) { print_r($node);exit; }
    $node['lat']=sprintf("%.7f",$node['lat']);
    $node['lon']=sprintf("%.7f",$node['lon']);

    $n['tags']= array ('amenity' => 'bicycle_rental', 'capacity' => ($node['slots'] + $node['bikes']), 'name' => trim($name_arr[1]), 'network' => 'Velo', 'ref' => trim($name_arr[0]));
    $n['info']= array ('id' => $new_counter, 'action' => 'modify', 'visible' => 'true', 'lat' => $node['lat'], 'lon' => $node['lon']);
    $new_counter--;
   
    return($n);
}

function scan_node(array &$osm, array $node){
    logtrace(4,sprintf("[%s] - Matching tag data to OSM.. ",__METHOD__));

    $info=$osm['tags'];
    $changes=array();

    if (!isset($info['ref'])) {
        logtrace(3,sprintf("[%s] - \tMissing Ref.",__METHOD__),'red' );
        exit;
    }
    
    if (isset($info['name'])) {
       logtrace(4,sprintf("[%s] - Matching velo name to OSM name.. ",__METHOD__),'dark_gray');
       // Replace slashes
       $node['name']=preg_replace("/\//", "-", $node['name'], -1);
       if (strcmp($info['name'],$node['name'])!==0) {
          //Different name. 'Paleisstraat' - '094- Paleisstraat'
          if (strcmp(sprintf("%s- %s",$info['ref'],$info['name']),trim($node['name']))!==0) {
             logtrace(4,sprintf("[%s] - \tDifferent name. '%s' - '%s'",__METHOD__, $info['name'],$node['name']),'red');
             // try fuzzy matching
             $testname= preg_replace('/(\d+)-\s(\w+)-(\w+)/', '\1- \2 - \3', trim($node['name']), -1);
             //$testname=$node['name'];
             logtrace(4,sprintf("[%s] - Try fuzzy matching to OSM name.. ",__METHOD__),'dark_gray');
             if (strcmp(sprintf("%s- %s",$info['ref'],$info['name']),trim($testname))!==0) {
                logtrace(4,sprintf("[%s] - \tDifferent fuzzy name. '%s' - '%s'",__METHOD__, $info['name'],$testname),'red');
                $name = preg_match('/(\d+)-\s(.+)/', trim($testname), $matches, null, 0);
                print_r($matches);
                if(isset($matches[2])) {
                   $nname=$matches[2];
                } else {
                   $nname=trim($node['name']);
                }
                logtrace(4,sprintf("[%s] - \tNew name: '%s' - '%s'",__METHOD__, $info['name'],$nname),'red');
                // exclude some nodes from receiving bad name
                if(!in_array((int)$node['id'],array(26, 44,117,130))) {
                  $changes['name'] = sprintf("%s",$nname);
                } else {
                  logtrace(4,sprintf("[%s] - \tOverride for id %d , Using OSM name: '%s'",__METHOD__, $node['id'], $info['name']),'red');
                  // $changes[]= array( 'name' => sprintf("%s",$info['name']));
                }
             } else {
                logtrace(4,sprintf("[%s] - \tRef + fuzzy name matches. '%s' - '%s'",__METHOD__, $info['name'],$node['name']),'green');
             }
             // $changes[]= array( 'name' => sprintf("%s",trim($info['name'])));
          } else {
             logtrace(4,sprintf("[%s] - \tRef + name matches. '%s' - '%s'",__METHOD__, $info['name'],$node['name']),'green');
          }
       } else {
          logtrace(4,sprintf("[%s] - \tEqual name.  OK",__METHOD__),'green');
       }
    }

    // Capacity = slots + bikes
    if (isset($info['capacity'])) {
        logtrace(4,sprintf("[%s] - Matching velo capacity to OSM capacity.. ",__METHOD__),'dark_gray');
        if ((int)$info['capacity']!==(int)($node['slots']+$node['bikes'])) {
            logtrace(4,sprintf("[%s] - \tDifferent capacity. %s != %s + %s",__METHOD__, $info['capacity'],$node['slots'], $node['bikes']),'red');
            $changes['capacity']=sprintf("%d",(int)($node['slots']+$node['bikes']));
        } else {
            logtrace(4,sprintf("[%s] - \tEqual capacity.  OK:  %s+%s=%s",__METHOD__,$node['slots'], $node['bikes'],$info['capacity']),'green');
        }
    } else {
        if(isset($node['slots'])) {
            logtrace(4,sprintf("[%s] - \tMissing OSM capacity.  %d",__METHOD__, ($node['slots']+ $node['bikes'])));
            $changes['capacity'] = sprintf("%d",(int)($node['slots']+$node['bikes']));
        }
    }
    // network
    if (isset($info['network']) && isset($node['network'])) {
        logtrace(4,sprintf("[%s] - Matching network to OSM network.. ",__METHOD__),'dark_gray');
        if ($info['network']!==$node['network']) {
            logtrace(4,sprintf("[%s] - \tDifferent network. '%s' - '%s'",__METHOD__, $info['network'],$node['network']),'red');
            $changes['network'] = sprintf("%s",trim($node['network']));
        } else {
            logtrace(4,sprintf("[%s] - \tEqual network.  OK",__METHOD__),'green');
        }
    } else {
        if(isset($node['network'])) {
            logtrace(4,sprintf("[%s] - \tMissing OSM network.  %s",__METHOD__, $node['network']),'orange');
            $changes['network'] = sprintf("%s",trim($node['network']));
        }
        if(!isset($info['network'])) {
            logtrace(4,sprintf("[%s] - \tMissing network.  %s",__METHOD__, 'Velo'),'orange');
            $changes['network'] = 'Velo';
        }
    }

    $info=$osm['info'];
    $node['lat']=sprintf("%.7f",$node['lat']);
    $node['lon']=sprintf("%.7f",$node['lon']);

    // lat
    if (isset($info['lat']) && isset($node['lat'])) {
        logtrace(4,sprintf("[%s] - Matching lat to OSM lat.. ",__METHOD__),'dark_gray');
        if ($info['lat']!==$node['lat']) {
            logtrace(4,sprintf("[%s] - \tDifferent lat. '%s' - '%s'",__METHOD__, $info['lat'],$node['lat']),'red');
            $changes['lat'] = sprintf("%.7f",$node['lat']);
        } else {
            logtrace(4,sprintf("[%s] - \tEqual lat.  OK",__METHOD__),'green');
        }
    } else {
        logtrace(4,sprintf("[%s] - \tThis should not happen...",__METHOD__),'red');
    }

    // lon
    if (isset($info['lon']) && isset($node['lon'])) {
        logtrace(4,sprintf("[%s] - Matching lon to OSM lon.. ",__METHOD__),'dark_gray');
        if ($info['lon']!==$node['lon']) {
            logtrace(4,sprintf("[%s] - \tDifferent lon. '%s' - '%s'",__METHOD__, $info['lon'],$node['lon']),'red');
            $changes['lon'] = sprintf("%.7f",$node['lon']);
        } else {
            logtrace(4,sprintf("[%s] - \tEqual lon.  OK",__METHOD__),'green');
        }
    } else {
        logtrace(4,sprintf("[%s] - \tThis should not happen...",__METHOD__),'red');
    }

    if ((isset($info['lon']) && isset($info['lat'])) && (isset($node['lon']) && isset($node['lat']))) {
        $oGC = new GeoCalc();
        $dDist_to_point = ($oGC->EllipsoidDistance( $info['lat'] , $info['lon'], $node['lat'], $node['lon']) * 1000);
        logtrace(4,sprintf("[%s] - Calculating distance .. %d meters apart",__METHOD__, $dDist_to_point),'cyan');
    }

    // Apply changes
    change_node($osm, $changes);
}

/* I just miss working with perl */
function my_chomp(&$string) {
   //$this->debug(__METHOD__, "call",5);
   if (is_array($string)) {
      foreach($string as $i => $val) {
         $endchar = chomp($string[$i]);
      }
   } else {
      $endchar = substr("$string", strlen("$string") - 1, 1);
      $string = substr("$string", 0, -1);
   }
   return $endchar;
}

function logtrace($level,$msg, $fg=null, $bg=null ) {
    //logtrace(0,sprintf("[%s] - Missing create query for table %s.",__METHOD__,$table));
    global $verbose;
    

    $DateTime=@date('Y-m-d H:i:s', time());

    if ( $level <= $verbose ) {
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

        if (isset($fg) or isset($bg)){
            echo $content;
            //$colors = new Colors();
            //echo $colors->getColoredString($content,$fg , $bg);
        } else {
            echo $content;
        }
        // "purple", "yellow" 
        // "red", "black"
        // "cyan"
        /* called with -d to skip deamonizing , don't write to log cos process ID's are the same*/
        $ok=0;
    }
}

?>
