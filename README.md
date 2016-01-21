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

This data still has original GRB tags, so we need to use the tool to

1. `Alter the existing tags`
2. `Add some source tags`
3. `Delete useless ones`
4. `Match addresses from .dbf file`

### `grb2osm`

Ater importing the shape file, you need to save as OSM. Once you have this file, we will perform step 1,2,3 and 4 in one run.


### otherthing

This is a stub
* list 1
* list item 2

Questions?
----------

Send me an email or fork this and fix it(or suggest a fix) on [GitHub][2].


Made by
-------

This tool is being developed by [gplv2](http://byte-consult.be/). Drop us a line

[1]: http://getcomposer.org/
[2]: https://github.com/gplv2/grb2osm/issues/
