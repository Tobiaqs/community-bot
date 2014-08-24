<?php

/**
 * @author _Tobias
 * @license See disclosed LICENSE.txt
 * @version 0.8
 **/

// In case we're starting the script from a directory other than where the script is located, we chdir into our own.
chdir(dirname(__FILE__));

// Make sure these are created.
@mkdir('data');
@mkdir('log');

// Libraries
require_once('credentials.php');
require_once('lib/vbinterface.inc.php');
require_once('lib/tmplparse.inc.php');
require_once('lib/geo.inc.php');

// Timezone information comes with the time strings from the API. This is required for suppressing PHP warnings.
date_default_timezone_set('UTC');

// Interface with the forums
$i = new VBInterface($vb_user, $vb_pass);

// Optionally set a writable stream here. The interface class will write debug messages to it.
// $i->logStream = fopen('log/vbinterface.log', 'w');

$postReplacements = array(array('{?size,height,width,quality}', ''));

// We want our signature to be customized according to our geolocation.
$geo = getgeo();
$sig = "This bot is not managed by Digitally Imported.";
if($geo) {
	$country = ucwords($geo['country']);
	$region = ucwords(str_replace('-', ' ', $geo['region']));
	$city = ucwords(str_replace('-', ' ', $geo['city']));
	if($country == 'Netherlands') {
		$country = 'The '.$country;
	}
	if($city == 'Eindhoven') {
		$city = 'Best';
	}
	$sig .= "\nI'm currently located in {$city}, {$region}, {$country} on server ".gethostname();
}
$i->setSignature($sig);

// The queue
$queue = array();

// The timestamp the last event batch was downloaded, we only need to download it every 2 hours to see if there are new events that should be posted about
$lastEventCheck = 0;

// Md5 hash of the shows.json file. This allows hot-editing the file
$showsMd5 = null;

// The array with show names
$shows = null;

// File with show name
$showsFile = 'data/shows.txt';

// Poll options and title
$pollOptFile = 'data/poll.json';

// -> See setPosted()
$postedFile = 'data/posted.txt';


// Create all the files.
touch($showsFile);
touch($postedFile);
$posted = explode(',', file_get_contents($postedFile));
if(count($posted) === 1 && $posted[0] === '') {
	$posted = array();
}

// This prevents CPU abuse
$loopTimeout = 3;

while(1) {

	// Here we check if the event data from the API we have in store is outdated or if the shows file has been updated.

	if($lastEventCheck+7200 < time() || $showsMd5 != md5_file($showsFile)) { // Event data expired (after 2 hours / 60*60*2=7200 seconds) or shows file has changed

		// MD5 hash of the shows file
		$showsMd5 = md5_file($showsFile);

		// Using expnl($str) instead of explode(PHP_EOL, $str) because I don't want the usual Windows/Linux line ending conflicts.
		$shows = expnl(file_get_contents($showsFile));

		// Download the events JSON string from the API
		$events = @file_get_contents('http://api.audioaddict.com/v1/di/events');

		// Succeeded?
		if($events) {

			// Parse
			$events = json_decode($events, true);

			// Set the last check timestamp to now.
			$lastEventCheck = time();

			// Empty the queue.
			$queue = array();
			echo "\n\nList updated:\n\n";

			// Looping through all events.
			foreach($events as $event) {
				// Inside of the loop we check if the event's name is in our shows file.
				foreach($shows as $srch) {

					/**
					 * Entries in the shows file have a syntax that allows them to change event variables.
					 * Example: A State Of Trance||show,name|'A State Of Trance Episode 5'||show,tagline|'With Armis van burren'
					 */
					$parts = explode('||', $srch);
					$srch = $parts[0];
					if(stripos($event['show']['name'], $srch) === 0 && strlen($event['show']['name']) === strlen($srch) && substr($event['show']['name'], 0, 2) != '//') {
						// Update the event data with modifications from the shows file. Also make sure stuff is posted to the forum in the correct encoding.
						$event = fixfields(fixutf($event), array_slice($parts, 1));

						/**
						 * Special: if the $event['e'] is set (this can only be done in the shows file), the show's name is
						 * appended with the episode number, calculated by the information given in that field.
						 * Not yet ready for use.
						 */

						/*
						if(isset($event['e'])) {
							$event['show']['name'] .= ' '.calcepisode($event['e']);
						}*/

						// Timestamp start
						$ts = strtotime($event['start_at']);

						// Timestamp end
						$end = strtotime($event['end_at']);

						// Show object
						$show = array(
							'start' => $ts,
							'duration' => $end-$ts,
							'id' => $event['id'],
							'show' => $event['show']
							);

						// Check if this event should be added to the queue.
						$delay = $ts-time();
						if($delay > -($show['duration']/20)) {
							echo "+ {$show['show']['name']} - posting in {$delay} secs\n";
							$queue[] = $show;
						}
					}
				}
			}
			echo "\n";
		}
		else {
			echo "Can't read events\n";
		}
	}

	foreach($queue as $item) {
		// Item shouldn't be posted yet and it should match the checkTime criteria
		if(!in_array($item['id'], $posted) && checkTime($item)) {

			// Read and set poll information
			call_user_func_array(array($i, 'setPoll'), json_decode(file_get_contents($pollOptFile), true));

			/**
			 * We're using my template format here. ViRUS created both title and post templates. ty :)
			 * The format allows variables to be embedded in text. See tmplparse in lib
			 */

			$title = tmplparse('data/title.tmpl', $item);

			echo "Posting {$title}.. ";
			$i->setThread(
				$title,
				tmplparse('data/post.tmpl', $item, $postReplacements)); // Fill out virus's other mighty template with event data

			// Post!
			$res = $i->run();

			// Error? We have an exception for 'This thread is a duplicate', that means the thread has already been posted by another instance, if not manually.
			$duplicate = $res['error'] && strpos($res['message'], 'This thread is a duplicate') !== false;

			// If it's a duplicate post, flag it as posted. Otherwise if there's no error set as posted.
			if(!$res['error'] || $duplicate) {
				setPosted($item['id']);
				echo $duplicate ? "DUP\n" : "OK\n";
			}
			// An error occurred :( Output the error message.
			else {
				echo "Error - {$res['message']}\n";
			}
		}
	}
	sleep($loopTimeout);
}

// Misc functions

// checks whether we should post about an event RIGHT NOW
function checkTime($item) {
	$delay = $item['start']-time();
	return $delay < 70 && $delay > -($item['duration']/20);
}

// Saves the event ID of events about which has been posted.
function setPosted($eventID) {
	global $posted, $postedFile;
	$posted[] = $eventID;
	file_put_contents($postedFile, implode(',', $posted));
}

// Split a string by newline. Linux/windows safe!
function expnl($str) {
	return preg_split('/\r\n|\r|\n/', $str);
}

// utf8_decode relevant fields so they appear correctly on the forum. Don't ask me how it works but it works.
function fixutf($event) {
	$event['show']['name'] = utf8_decode($event['show']['name']);
	$event['show']['tagline'] = utf8_decode($event['show']['tagline']);
	return $event;
}

// Set fields according to shows file syntax.
function fixfields($event, $parts) {
	foreach($parts as $part) {
		$pair = explode('|', $part, 2);

		$w = $event;
		$tree = explode(',', $pair[0]);
		if(count($tree) === 1) {
			$tree[0] = "'{$tree[0]}'";
			$w[$pair[0]] = $pair[1];
		}
		else {
			foreach($tree as $k => $child) {
				if(isset($w[$child])) {
					$w = $w[$child];
					$tree[$k] = "'{$child}'";
				}
				else {
					$w = null;
					break;
				}
			}
		}

		if($w) {
			$tree = '['.implode('][', $tree).']';

			// Eval = Evil; I know
			eval('$event'.$tree.'='.$pair[1].';');
		}
	}
	return $event;
}

/*function calcepisode($data) {
	$data = explode(' ', $data);
	$d = $data[3]*60; // Duration of the show in seconds
	$ft = is_numeric($data[0]) ? ($data[0]*1)+$d : strtotime($data[0])+$d;
	$fe = $data[1]*1;
	$r = $data[2]*86400; // in seconds
	$t = time();

	while($t > $ft) {
		$ft += $r;
		$fe++;
	}
	return $fe;
}*/