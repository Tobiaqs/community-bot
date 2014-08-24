<?php

/**
 * @author _Tobias
 * @license See disclosed LICENSE.txt
 * @version 0.2
 **/

// This parses templates in tobiastemplate-format or something, whatever

function tmplparse($file, $input) {

	// Read out the template file
	$tmpl = file_get_contents($file);

	// If there are elements to be replaced..
	if(preg_match_all('/{(.+?)}/', $tmpl, $matches)) {

		// Loop through all elements
		foreach($matches[1] as $k => $a) {

			$w = null; // This is the new value of the element.

			// Loop through all possibilities. Break the loop when the first non-null variable is found.
			foreach(explode('|', $a) as $query) {

				// For comfrot I copy the JSON input in here. Every step we'll set this variable to one level up.
				$w = $input;

				// Loop through the array tree
				foreach(explode(',', $query) as $v) {
					// If the current step is specified as a number, try to get an element by a numeric key.
					if(preg_match('/<([0-9]+)>/', $v, $num)) {
						// If it's available, set our working variable to the value of the key.
						if(isset($w[$num[1]*1])) {
							$w = $w[$num[1]*1];
						}
						// If not, set it to null.
						else {
							$w = null;
						}
					}
					// If the current step is specified as a string, try to get it by string key.
					else {
						// If set, filter to the next step.
						if(isset($w[$v])) {
							$w = $w[$v];
						}
						// If not, set it to null.
						else {
							$w = null;
						}
					}
				}
				// Did we find a valid value? No need to search further.
				if($w) {
					$w = str_replace('{?size,height,width,quality}', '', $w);
					break;
				}
			}
			// Replace the element with the value. If it wasn't found in the array, replace with null.
			$tmpl = str_replace($matches[0][$k], $w, $tmpl);
		}
	}

	return $tmpl;
}