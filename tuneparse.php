<?php

/* 
 * Copyright (C) 2018 k4
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

$results = getAllSongData();
$songdata = parseAllSongData($results);

$count = sizeof($songdata);

outline("Parsed $count songs");

//neato
save_array_as_report(dataCounter($songdata, 'Genre'), 'genre_report_' . time());
save_array_as_report(dataCounter($songdata, 'Artist'), 'artist_report_' . time());

save_array_as_report(buildArtistStats($songdata), 'artist_full_' . time());

/**
 * Load the simpleXML object up with data from the file we want...
 * @staticvar type $xml
 * @return loaded simpleXML
 */
function getSimpleXML() {
    static $xml = null;

    if (is_null($xml)) {
	outline("Loading XML library...");
	//TODO: Be nice to people who aren't you. Later.
	$xml = simplexml_load_file('./exported_xml/Library_Backup_20181001.xml');
	outline("XML library loaded");
    }

    return $xml;
}

/**
 * Do an xpath query, and return the results
 * @param string $query The xpath query to run
 * @return xpath query results
 */
function doXpath($query) {
    $xml = getSimpleXML();
    //TODO: Some error handling in here.
    return $xml->xpath($query);
}

function getAllSongIDs() {
    return doXpath('/plist/dict/dict/key');
}

function getAllSongData() {
    $ret = doXpath('/plist/dict/dict/key/following-sibling::dict[1]');
    outline("Found all the songs.");
    return $ret;
}

function parseSingleSong($data) {

    //stuff we currently care about enough to pull
    $pull_me = array(
	'Track ID' => 'integer',
	'Name' => 'string',
	'Artist' => 'string',
	'Album' => 'string',
	'Genre' => 'string',
	'Year' => 'integer',
	'Play Count' => 'integer',
	'Skip Count' => 'integer',
	'Rating' => 'integer',
    );

    $return_data = array();

    //This is all stored in a key/value structure in the xml, with the nodes 
    //adjacent to eachother.
    //...Dicks.
    $grab_next = false;
    foreach ($data as $key => $value) {
	$value = (string) $value;
	if ($key === 'key') { //phase 1
	    $grab_next = false;
	    if (array_key_exists($value, $pull_me)) {
		$grab_next = $value;
	    }
	} else {    //phase 2
	    if ($grab_next !== false) {
		if ($key === $pull_me[$grab_next]) {
		    //success!
		    $return_data[$grab_next] = $value;
		} else {
		    //complain!
		    outline("OMG, wrong node type. Expecting $pull_me[$grab_next], got $key");
		    outline($data);
		    die();
		}
		$grab_next = false;
	    }
	}
    }
    outline(".", false);
    return $return_data;
}

function parseAllSongData($songs) {
    $ret = array();
    foreach ($songs as $song_data) {
	$ret[] = parseSingleSong($song_data);
    }
    outline("Finished parsing all song data");
    return $ret;
}

function outline($thing, $newline = true) {
    if ($newline) {
	$newline = "\n";
    } else {
	$newline = '';
    }
    if (is_array($thing)) {
	echo print_r($thing);
	echo $newline;
    } else {
	echo "$thing$newline";
    }
}

function dataCounter($data, $key) {
    $out = array();
    foreach ($data as $stuff) {
	if (array_key_exists($key, $stuff)) {
	    $genre = $stuff[$key];
	} else {
	    $genre = '[unset]';
	}

	if (array_key_exists($genre, $out)) {
	    $out[$genre] += 1;
	} else {
	    $out[$genre] = 1;
	}
    }

    return $out;
}

function addToArrayTally(&$data, $key, $count) {
    if (array_key_exists($key, $data)) {
	$data[$key] += $count;
    } else {
	$data[$key] = $count;
    }
    return $data;
}

function buildArtistStats($data) {
    $out = array();
    $sum_keys = array(
	'Play Count',
	'Skip Count',
    );
    foreach ($data as $stuff) {
	$artist = "[unset]";
	if (array_key_exists('Artist', $stuff) && $stuff['Artist'] !== '') {
	    $artist = $stuff['Artist'];
	}
	if (!array_key_exists($artist, $out)) {
	    $out[$artist] = array();
	    //this seems redundant, but it's better for file generation...
	    $out[$artist]['Artist'] = $artist;
	}
	addToArrayTally($out[$artist], 'Track Count', 1);

	foreach ($stuff as $column => $value) {
	    if (in_array($column, $sum_keys)) {
		//add to the count.
		addToArrayTally($out[$artist], $column, $value);
	    }
	}

	//oooh, count ratings per artist
	if (array_key_exists('Rating', $stuff)) {
	    $key = "Rated_" . $stuff['Rating'] / 20;
	    addToArrayTally($out[$artist], $key, 1);
	}

	//and something about a genre spread...
	if (array_key_exists("Genre", $stuff) && $stuff['Genre'] !== "") {
	    if (!array_key_exists('Genres', $out[$artist])) {
		$out[$artist]['Genres'] = array();
	    }
	    addToArrayTally($out[$artist]['Genres'], $stuff['Genre'], 1);
	}
    }

    //calc genre spread now that we're all built.
    foreach ($out as $artist => $data) {
	if (array_key_exists('Genres', $out[$artist])) {
	    $out[$artist]['Genre Count'] = count($out[$artist]['Genres']);
	    asort($out[$artist]['Genres']);
	    $all_genres = '';
	    foreach ($out[$artist]['Genres'] as $genre => $count) {
		$all_genres .= "$genre|";
	    }
	    $out[$artist]['Genres'] = $all_genres;
	}
    }

    return $out;
}

function save_array_as_report($array, $filename) {
    $file = fopen(__DIR__ . "/reports/$filename.tsv", 'w');

    $simple = true;
    foreach ($array as $key => $value) {
	if (is_array($value)) {
	    $simple = false;
	}
	break;
    }

    if ($simple) {
	foreach ($array as $key => $value) {
	    $line = "$key\t$value\n";
	    fwrite($file, $line);
	}
    } else { //complex! heh
	foreach ($array as $key => $value) {
	    //one time through to build the headers
	    $columns = array();
	    foreach ($array as $key => $values) {
		foreach ($values as $heading => $whatev) {
		    if (!in_array($heading, $columns)) {
			$columns[] = $heading;
		    }
		}
	    }
	}
	//we can care about order maybe someday. I don't rn.
	$line = '';
	foreach ($columns as $column) {
	    $line .= "$column\t";
	}
	$line .= "\n";
	fwrite($file, $line);

	//now it gets stupid, because holes in the data / order / whatever
	foreach ($array as $key => $value) {
	    $line = '';
	    foreach ($columns as $column) {
		if (array_key_exists($column, $value)) {
		    $line .= $value[$column];
		}
		$line .= "\t";
	    }
	    $line .= "\n";
	    fwrite($file, $line);
	}
    }
    fclose($file);
}
