<?php

/**
 * @author _Tobias
 * @license wtfpl.net
 * @version 0.1
 **/

// This gets some geo ip info from a web service. Why not using a REST api you say? Well, that has a rate limit of 20 queries a day. A little hacking gets us around that.

function getgeo() {
	$html = @file_get_contents('http://tools.ip2location.com/ib1');
	if(!preg_match('/<br \/>(.+?)<\/a>/s', $html, $matches)) {
		return false;
	}
	$data = array();
	foreach(explode("\n", $matches[1]) as $k => $line) {
		$line = trim($line);
		if(!$line) {
			continue;
		}
		if($k === 1) {
			preg_match('/<b>(.+?)<\/b>/', $line, $ip);
			$data['ip'] = $ip[1];
		}
		elseif($k > 1) {
			preg_match('/([A-Za-z]+?): <b>(.+?)<\/b>/', $line, $field);
			$data[strtolower($field[1])] = strtolower($field[2]);
		}
	}
	return $data;
}