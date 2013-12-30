<?php

/**
 * @author _Tobias
 * @license See disclosed LICENSE.txt
 * @version 0.5-beta
 **/

class VBInterface {
	// Post data for logging in
	private $login = null;

	// Session headers
	private $session = null;
	
	// Timestamp after which the session has expired
	private $sessionExpire = 0;

	// Poll post data
	private $poll = null;

	// Number of poll options
	private $pollOptions = 0;

	// Thread post data
	private $thread = null;

	// ID of the last created post
	public $createdPost = 0;

	// ID of the last created thread
	public $createdThread = 0;

	// ID of the forum we're posting in
	public $forumID = 155;

	// Show signature underneath posts
	public $showSignature = true;

	// User-Agent header
	private $userAgent = 'User-Agent: Mozilla/1.22 (compatible; MSIE 2.0; Windows 3.1)';

	// Error object for connection error
	private $connFailed = array('error' => true, 'message' => 'Connection failed.');

	// If this is set to a stream, log will write to it
	public $logStream = null;

	public function __construct($username, $password) {
		$this->setLogin($username, $password);
	}

	// Sets login post data
	public function setLogin($username, $password) {
		$this->login = array(
			'vb_login_username' => $username,
			'vb_login_md5password' => md5($password),
			'do' => 'login');
	}

	// Sets poll post data
	public function setPoll($question, $options, $publicPoll = true) {
		$this->poll = array(
			'question' => $question,
			'polloptions' => strval(count($options)),
			'timeout' => 0,
			'cb_public' => $publicPoll,
			'do' => 'postpoll');

		foreach($options as $k => $option) {
			$this->pollOptions = $k+1;
			$this->poll['options['.$this->pollOptions.']'] = $option;
		}
	}

	// Set thread post data
	public function setThread($subject, $message) {
		$this->thread = array(
			'do' => 'postthread',
			'emailupdate' => '9999',
			'subject' => $subject,
			'message' => $message);
	}

	// Get session headers
	public function getSession() {
		$this->ensureSession();
		return $this->session;
	}

	// Make sure valid session data is available
	private function ensureSession() {
		$time = time();
		if($this->sessionExpire > $time) {
			$this->sessionExpire = $time+480;
		}
 		elseif($time > $this->sessionExpire) {
			$this->session = null;
			$res = $this->post('http://forums.di.fm/login.php?do=login', $this->login, array($this->userAgent), true);
			$found = false;
			if($res['headers']) {
				foreach($res['headers'] as $header) {
					$sr = 'Set-Cookie: vb_sessionhash=';
					if(strpos($header, $sr) === 0) {
						$header = substr($header, strlen($sr));
						$this->session = array($this->userAgent, 'Cookie: vb_sessionhash='.substr($header, 0, strpos($header, ';')));
						$this->sessionExpire = time()+480;
						$this->log("Session hash read from the headers. To expire on {$this->sessionExpire}.");
						$found = true;
					}
				}
				if(!$found) {
					$this->log("Session hash not found in the headers.");
					return false;
				}
			}
			else {
				$this->log($this->connFailed['message']);
				return false;
			}
		}
		return true;
	}

	// Post a new thread
	public function run() {
		$this->log = array();
		$this->createdPost = 0;
		$this->createdThread = 0;

		if(!$this->ensureSession()) {
			return $this->connFailed;
		}

		if(!$this->thread) {
			$this->log("No thread specified.");
			return array('error' => true, 'message' => 'No thread specified.');
		}

		if($this->poll) {
			$this->log("Setting poll data.");
			$this->thread['postpoll'] = 'yes';
			$this->thread['polloptions'] = strval($this->pollOptions);
		}
		else {
			$this->log("Poll removed from pending thread POST data.");
			unset($this->thread['postpoll'], $this->thread['polloptions']);
		}


		$this->log("Starting thread posting request.");
		$this->thread['signature'] = $this->showSignature;
		$res = $this->post('http://forums.di.fm/newthread.php?do=postthread&f='.$this->forumID, $this->thread, $this->session);
		if(!$res['result']) {
			$this->log($this->connFailed['message']);
			return $this->connFailed;
		}

		/**
		 * Here we check for a redirect to the poll settings.
		 * If this regex fails, we're either on an error page or on the
		 * thread page. In case there's no poll to post, we don't care
		 * about this. Otherwise return an error.
		 *
		 * Excuse my coding. This is built for forums.di.fm only.
		 */

		preg_match('/<meta http-equiv="Refresh" content="2; URL=(.+?)" \/>/', $res['result'], $matches);
		if(!$matches && !$this->poll) {
			foreach($res['headers'] as $header) {
				$sr = 'Location: ';
				if(strpos($header, $sr) === 0) {
					$header = substr($header, strlen($sr));
					$log[] = "Forward: {$header}";
					preg_match('/#post([0-9]+)/', $header, $matches);
					if($matches) {
						$this->createdPost = $matches[1]*1;
					}
					preg_match('/-([0-9]+)\/#post[0-9]+$/', $header, $matches);
					if($matches) {
						$this->createdThread = $matches[1]*1;
					}
				}
			}
			return array('error' => $this->createdPost === 0 || $this->createdThread === 0, 'message' => 'Done.');
		}
		elseif(!$matches) {
			if(preg_match('/Please try again in ([0-9]+) seconds\./', $res['result'], $matches)) {
				$this->log("Post rate limit, ".$matches[1]." seconds left.");
				return array('error' => true, 'message' => "Post not yet allowed, retry in ".$matches[1]." seconds.", 'retry' => $matches[1]*1);
			}
			else {
				preg_match('/newpost_errormessage -->\n<li>(.+?)<\/li>/m', $res['result'], $matches);
				if(!$matches) {
					$this->log('Problem // Result: '.$res['result']);
					$this->log($res['headers']);
				}
				else {
					$this->log("Problem // {$matches[1]}");
				}
				return array('error' => true, 'message' => $matches ? $matches[1] : 'Mystery error!');
			}
		}
		elseif(!$this->poll) {
			preg_match('/<strong>(.+?)<\/strong>/', $res['result'], $matches);
			$msg = $matches ? $matches[1] : 'Mystery problem!';
			$this->log($msg.' // HTML Dump: '.$res['result']);
			$this->log($res['headers']);
			return array('error' => true, 'message' => $msg);
		}

		// Check if the forward goes to the thread, if not, return an error.

		preg_match('/t=([0-9]+)/', $matches[1], $matches);
		if(!$matches) {
			$this->log('Thread not created // HTML Dump: '.$res['result']);
			$this->log($res['headers']);
			return array('error' => true, 'message' => "Thread not created.");
		}

		// Set the thread's ID to the one we just received.
		$this->createdThread = $matches[1]*1;
		$this->poll['t'] = $matches[1];
		$res = $this->post('http://forums.di.fm/poll.php?do=postpoll&t='.$matches[1], $this->poll, $this->session);
		if(!$res['result']) {
			$this->log($this->connFailed['message']);
			return $this->connFailed;
		}
		else {
			preg_match('/#post([0-9]+)/', $res['result'], $matches);
			if($matches) {
				$this->createdPost = $matches[1]*1;
				$this->log("Request completed. Post ID: ".$matches[1]);
				return array('error' => false, 'message' => 'Done.');
			}
			else {
				$this->log('Poll not allowed // Result: '.$res['result']);
				$this->log($res['headers']);
				return array('error' => true, 'message' => 'Poll not allowed.');
			}
		}
	}


	// Reply to a thread
	public function reply($title = null, $message, $threadID = 0) {
		if(!$this->ensureSession()) {
			return $this->connFailed;
		}

		if($threadID === 0) {
			$threadID = $this->createdThread;
		}
		if($threadID === 0) {
			return array('error' => true, 'message' => 'No thread to reply to.');
		}

		$data = array(
			'do' => 'postreply',
			'emailupdate' => '9999',
			'message' => $message,
			'signature' => $this->showSignature,
			't' => $threadID
		);
		if($title != null) {
			$data['title'] = $title;
		}

		$res = $this->post('http://forums.di.fm/newreply.php?do=postreply&t='.$threadID, $data, $this->session);
		if(!$res['result']) {
			return $this->connFailed;
		}
		
		$createdPost = 0;
		foreach($res['headers'] as $header) {
			$sr = 'Location: ';
			if(strpos($header, $sr) === 0) {
				$header = substr($header, strlen($sr));
				preg_match('/\?p=([0-9]+)&posted=1/', $header, $matches);
				if($matches) {
					$createdPost = $matches[1]*1;
				}
			}
		}

		if($createdPost === 0) { // error
			preg_match('/newpost_errormessage -->\n<li>(.+?)<\/li>/m', $res['result'], $matches);
			return array('error' => true, 'message' => $matches ? $matches[1] : 'Mystery error!');
		}
		else {
			return array('error' => false, 'message' => 'Done.', 'postID' => $createdPost);
		}
	}

	private function log($obj) {
		if($this->logStream != null) {
			fwrite($this->logStream, (is_string($obj) ? $obj : (is_array($obj) ? json_encode($obj) : serialize($obj)))."\n");
		}
	}

	// Count posts in a thread
	public static function countPosts($threadID = 0, $accurate = false) {
		if($threadID === 0) {
			$threadID = $this->createdThread;
		}
		if($threadID === 0) {
			return array('error' => true, 'message' => 'No thread to reply to.');
		}

		$url = self::getRedirect('http://forums.di.fm/showthread.php?t='.$threadID);

		if(!$url) {
			return $this->connFailed;
		}

		$pp = 100;

		$res = self::post($url.'?pp='.$pp);
		if(!$res['result']) {
			return $this->connFailed;
		}

		$search = '<!-- message -->';
		preg_match('/<td class="vbmenu_control" style="font-weight:normal">Page 1 of ([0-9]+)<\/td>/', $res['result'], $matches);
		if($matches) { // more than 1 page
			$lastPage = $matches[1]*1;
			if($accurate) {
				$res = self::post($url.'index'.$lastPage.'.html?pp='.$pp);
				if(!$res['result']) {
					return $this->connFailed;
				}
				return array('error' => false, 'count' => substr_count($res['result'], $search)+(($lastPage-1)*$pp), 'accurate' => false);
			}
			else {
				return array('error' => false, 'count' => $lastPage*$pp, 'accurate' => false);
			}
		}
		else {
			return array('error' => false, 'count' => substr_count($res['result'], $search), 'accurate' => true);
		}
	}

	// Delete the last post
	public function delete() {
		return $this->deleteID($this->createdPost);
	}

	// Delete a post by ID
	public function deleteID($postID) {
		if(!$this->ensureSession()) {
			return $this->connFailed;
		}

		if($postID === 0) {
			return false;
		}

		$res = $this->post('http://forums.di.fm/editpost.php', array(
			'do' => 'deletepost',
			'deletepost' => 'delete',
			'postid' => $postID), $this->session, true);

		return !!$res['result'];
	}

	// Set the signature. This does not set whether or not to show it underneath a post!
	public function setSignature($text) {
		if(!$this->ensureSession()) {
			return $this->connFailed;
		}

		$res = $this->post('http://forums.di.fm/profile.php', array(
			'message' => $text,
			'do' => 'updatesignature',
			'url' => 'avatars/0-0.gif'), $this->session, true);
	}

	// Get the BB code from a post by ID
	public function getBB($postID) {
		if(!$this->ensureSession()) {
			return $this->connFailed;
		}

		$res = self::post('http://forums.di.fm/ajax.php', array(
			'do' => 'quickedit',
			'p' => $postID
			), $this->session);
		if($res['result']) {
			if(strpos($res['result'], '<error>invalidid</error>') === false) {
				preg_match('/<textarea .+?>(.+?)<\/textarea>/s', $res['result'], $matches);
				return $matches ? html_entity_decode($matches[1]) : false;
			}
			else {
				return false;
			}
		}
		else {
			return false;
		}
	}

	// Set the BB code of a post
	public function setBB($postID, $message) {
		if(!$this->ensureSession()) {
			return $this->connFailed;
		}

		$res = self::post('http://forums.di.fm/editpost.php', array(
			'do' => 'updatepost',
			'postid' => $postID,
			'message' => $message,
			'signature' => $this->showSignature,
			), $this->session, true);
		
		return $res['headers'] !== null && $res['headers'][0] == 'HTTP/1.1 302 Found';
	}

	// Helper function for doing POST requests
	public static function post($url, $post = null, $headers = null, $noData = false, $fiddler = false) {
		$opts = array(
			'method'  => 'POST',
			'header'  => "Content-type: application/x-www-form-urlencoded\r\n".($headers ? implode("\r\n", $headers)."\r\n" : null),
			'content' => $post ? http_build_query($post) : null,
			'follow_location' => !$noData
		);

		if($fiddler) {
			$opts['proxy'] = 'tcp://127.0.0.1:8888';
			$opts['request_fulluri'] = true;
		}

		$c = stream_context_create(array('http' => $opts));

		if($noData) {
			$res = @file_get_contents($url, false, $c, -1, 0);
		}
		else {
			$res = @file_get_contents($url, false, $c);
		}
		return array('result' => $res !== false ? $res : null, 'headers' => ($res !== false ? $http_response_header : null));
	}

	// Get the URL where $url forwards to
	public static function getRedirect($url, $headers = null) {
		$c = stream_context_create(array('http' =>
			array(
				'method'  => 'HEAD',
				'header'  => $headers ? implode("\r\n", $headers)."\r\n" : null
			)
		));
		// I spose this doesn't require request capturing, if it does, just set the proxy/request_fulluri fields in the options array above. See (post) for more info

		try {
			file_get_contents($url, false, $c, -1, 0);
		}
		catch(Exception $e) {
			return null;
		}
		$redir = null;
		$sr = 'Location: ';
		foreach($http_response_header as $header) {
			if(strpos($header, $sr) === 0) {
				$redir = substr($header, strlen($sr));
			}
		}
		return $redir;
	}
}