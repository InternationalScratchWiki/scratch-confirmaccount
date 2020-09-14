<?php
define('SWIKI_VERIFICATION_INTERVAL', 1800);

function scratchwiki_verification_code($cycles = 0) {
	//generate the verification code, it uses the floor of the time / 1800, so it changes every 30 minutes (the next page also adds some fault tolerance if the code is entered on the border)
	$code = chunk_split(sha1(floor((time() - $cycles * SWIKI_VERIFICATION_INTERVAL) / SWIKI_VERIFICATION_INTERVAL) . $_SERVER['HTTP_USER_AGENT'] . $_SERVER['REMOTE_ADDR']), 5, ',');
	
	return substr($code, 0, strlen($code) - 1); //strip the last comma from the code 
}

define('SCRATCH_API_FAILURE', -1);
define('SCRATCH_COMMENT_NOTFOUND', 0);
define('SCRATCH_COMMENT_FOUND', 1);

function vercode_recently_commented_by_user($project_link, $commenting_user, $max_cycles = 2) {
	preg_match('%(\d+)%', $project_link, $matches);
	$data = file_get_contents('http://scratch.mit.edu/site-api/comments/project/' . $matches[1] . '/?page=1&salt=' . md5(time())); //add the salt so it doesn't cache
	if (!$data) {
	   return SCRATCH_COMMENT_NOTFOUND;
	}
	
	$codes = array();
	for ($i = 0; $i <= $max_cycles; $i++) { //have a "fault-tolerance" of two, so if the code was generated and the time changed between entering the code and checking it, it still works
		$codes[] = scratchwiki_verification_code($i);
	}
	
	$comment_found = false;
	preg_match_all('%<div id="comments-\d+" class="comment +" data-comment-id="\d+">.*?<div class="actions-wrap">.*?<div class="name">\s+<a href="/users/(([a-zA-Z0-9]|-|_)+)">(([a-zA-Z0-9]|-|_|\*)+)</a>\s+</div>\s+<div class="content">(.*?)</div>%ms', $data, $matches);
	unset($matches[2]);
	unset($matches[3]);
	unset($matches[4]);
	foreach ($matches[5] as $key => $val) {
		$user = $matches[1][$key];
		$comment = trim($val);
		if (strtolower($user) == strtolower(htmlspecialchars($commenting_user))) {
			foreach ($codes as $code) {
			   if (strstr($comment, $code)) {
					$comment_found = true;
					break;
			   }
			}
		}
		if ($comment_found) {
			break;
		}
	}
	
	return $comment_found ? SCRATCH_COMMENT_FOUND : SCRATCH_COMMENT_NOTFOUND;
}