gplv2/grb2osm
=========

Tool to help import GRB data Belgium in OSM
/

Installation
------------

Make sure you have [composer][1] installed, and then run:

    composer install


Usage
-----

This package will need the shape files indirectly. You have to import with [JOSM][3] first and save as OSM xml.
Make sure all the files from GRB are there e.g:

* Gbg23096B500.dbf
* Gbg23096B500.lyr
* Gbg23096B500.prj
* Gbg23096B500.shp
* Gbg23096B500.shx
* Gbg23096B500.WOR

Josm needs some of them to determine the coordinate system used.  Just the shape file alone will not work.
Open up the .shp file in OSM, you should not see any warnings when all files are in place.

![Shape after import](/screenshots/importedshapes.png?raw=true "Imported shapes")

save this to a file, for example `saved_imported.osm`.

This data still has original GRB tags, so we need to use the tool to

1. `Alter the existing tags`
2. `Add some source tags`
3. `Delete useless ones`
4. `Match addresses from .dbf file`

### `grb2osm`

Ater importing the shape file, you need to save as OSM. Once you have this file, we will perform step 1,2,3 and 4 in one run.

    ./grb2osm.php -i examples/saved_imported.osm -f examples/TblGbgAdr23096B500.dbf -o exported.osm -d 3

### Open the exported file in JOSM

![Shape after import](/screenshots/parsed_exported.png?raw=true "Parsed shapes")

In the screenshot you see the result of custom mapCSS colouring styles, visually comfirming me that the address data is present in tags JOSM understands. Now you have a source layer you can start migrating buildings from.  You will need a few plugins/tools to do this:

* Utils plugin [replace geometry tool][4] and probably depenancies
* [Terracer plugin][5]


How to migrate in a nuttshell
-----------------------------

Now you have this source data layer, you want to start downloading (Small) area's in JOSM from the target area.  Don't work too big as you want to keep track of what's migrated. I do it like this:

* validate the OSM layer before even beginning so you have an idea what's already wrong and not the importers fault.
* make sure tags are ok. (change with search/replace functionality for example buildings (ways+relations) smaller than 15m2.  Make them all shed's (this is 99% accurate).  Unless there is GRB adressing data, then it's possibly not a 'shed'
* work methodically, go to an area, download from OSM, switch to the GRB layer and do a search for type way having building key. ex. `"building"=* type:way areasize:-15` , change them all to shed.  Make sure you do this on the OSM layer in particular, the new layer is probably already ok but it doesn't hurt matching these before migrating
* now do a search for all buildings (type way, make sure to skip relations, they are tricky to copy/paste between layers, some complex buildings will become relations, treat them differently) in view using `inview` search attribute.
* now copy and delete these from the new layer, paste them in the OSM layer and save both files.
* you now need to run the validator and correct crossing buildings, overlapping buildings.   In general, now you'll spend much time using the validator as a tool.
* merge buildings using replace geometry hotkey (standard CTRL+SHIFT+G , you want to remap this to a more 'closer combo')
* fix the warnings first, then the errors.  Most errors have automated fixes which will make you have to cleanup much more later on.  So by experience, fixing the warnings will usually also fix the errors
* give decent comments on the changesets, add GRB as a changeset source as well.


Questions?
----------

Send me an email or fork this and fix it(or suggest a fix) on [GitHub][2].


Made by
-------

This tool is being developed by [gplv2](http://byte-consult.be/). Drop us a line

[1]: http://getcomposer.org/
[2]: https://github.com/gplv2/grb2osm/issues/
[3]: http://josm.org
[4]: http://wiki.openstreetmap.org/wiki/JOSM/Plugins/utilsplugin2
[5]: http://wiki.openstreetmap.org/wiki/JOSM/Plugins/Terracer