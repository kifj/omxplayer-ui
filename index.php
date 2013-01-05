<?php
#ini_set('display_errors', 'On');
#error_reporting(E_ALL);

mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
mb_http_input('UTF-8');
mb_language('uni');
mb_regex_encoding('UTF-8');
ob_start('mb_output_handler');
define('FIFO', '/tmp/omxplayer_fifo');
define('OMX_SETTINGS','./conf/settings.json');
define('PLAYLIST_CURRENT', './data/omxplayer_current.txt');
define('PLAYLIST_QUEUE', './data/omxplayer_playlist.m3u');
define('OMX_WATCHDOG', './data/omxplayer_watchdog');
setupFifo();
$settings = setupOptions();

require_once 'Slim/Slim.php';
require_once 'audioinfo.php';
\Slim\Slim::registerAutoloader();

$app = new \Slim\Slim();
$app->add(new \Slim\Middleware\ContentTypes());
$app->get('/servers', 'getServers');
$app->get('/playlist', 'getPlaylist');
$app->get('/servers/:id+',  'getServer');
$app->post('/servers/:id+',  'controlFile');
$app->post('/control',  'controlPlayer');
$app->get('/status', 'getStatus');
$app->get('/settings', 'getSettings');
$app->post('/settings',  'setSettings');
$app->get('/watchdog', 'watchdog');
$app->run();


function getOption($key) {
	global $settings;
	return $settings-> { $key };
}

function setupOptions() {
	if (!file_exists(OMX_SETTINGS)) {
		if ($settingsFile = fopen(OMX_SETTINGS, 'w')) {
			$settings = array(
				'root'=> '/media/upnp', 
				'id3' => true, 
				'passthrough' => true,
				'audio out' => 'hdmi',
				'deinterlacing' => false,
				'hw audio decoding' => false,
				'3d tv' => false,
				'boost volume' => false,
				'refresh' => false,
				'extensions' => array('mp3', 'm3u', 'mpg', 'avi', 'mov', 'mkv', 'jpg', 'JPG', 'png', 'flv', 'MP3', 'wav', 'ogg')
			);
			fwrite($settingsFile,json_encode($settings));
			fclose($settingsFile);
		}
	} else {
		$settings = json_decode(file_get_contents(OMX_SETTINGS));
	}
	return $settings;
}

function getOmxplayerOptions(){
	global $settings;
	$omxplayerOptions = '';
	foreach ($settings as $key=>$value) {
		switch ($key){
			case 'passthrough':
				if ($value) { $omxplayerOptions .= ' -p '; }
				break;
			case 'audio out':
				$omxplayerOptions .= ' -o '. $value;
				break;
			case 'deinterlacing':
				if ($value) { $omxplayerOptions .= ' -d '; }
				break;
			case 'hw audio decoding':
				if ($value) { $omxplayerOptions .= ' -w '; }
				break;
			case '3d tv':
				if ($value) { $omxplayerOptions .= ' -3 '; }
				break;
			case 'boost volume':
				if ($value) { $omxplayerOptions .= ' --boost-on-downmix '; }
				break;
			case 'refresh':
				if ($value) { $omxplayerOptions .= ' -r '; }
				break;
			default:
		}    
	}
	return $omxplayerOptions;
}

// ------------------------------------------------------------------------------------------------

function getServers() {
	$app = Slim\Slim::getInstance();
	$log = $app->getLog();
	$root_dir = getOption('root');
	$log->info("-> getServers: " . $root_dir);
	$app->contentType('application/json');
	$response = $app->response();
	if (is_dir($root_dir) && $handle = opendir($root_dir)) {
		$body = array();
		$items = array();
		while (false !== ($file = readdir($handle))) {
			if (!startsWith($file, '.')  && is_dir("$root_dir/$file")) {
				$item = array();
				$item['server'] = $file;
				$items[] = $item;
			}
		}
		closedir($handle);
		$body['servers'] = $items; 
		echo json_encode($body);
		$response["Cache-Control"] ="max-age=600"; 
	} else {
		$response->status(404);
	}
}

function getPlaylist() {
	$app = Slim\Slim::getInstance();
	$app->contentType('application/json');
	$playlist = readPlaylist();
	$body = array();
	$items = array();
	$root_dir = getOption('root');
	foreach ($playlist as $file) {
		$file = str_replace($root_dir . "/", "", trim($file));
		$item = array();
		$item["file"] = end(explode("/", $file));
		$item["link"] = $file;
		$items[] = $item;
	}
	$body['playlist'] = $items; 
	echo json_encode($body);
	$response = $app->response();
	$response["Cache-Control"] ="max-age=0"; 
}

function getSettings() {
	$app = Slim\Slim::getInstance();
	$app->contentType('application/json');
	global $settings;
	echo json_encode($settings);
	$response = $app->response();
	$response["Cache-Control"] ="max-age=0"; 
}

function setSettings() {
	$app = Slim\Slim::getInstance();
	$app->contentType('application/json');
	$log = $app->getLog();
	$log->info("-> setSettings:" . OMX_SETTINGS);
	global $settings;
	$request = $app->request();
	$settings = $request->getBody();
	if ($settingsFile = fopen(OMX_SETTINGS, 'w')) {
		fwrite($settingsFile,json_encode($settings));
		fclose($settingsFile);
	}
	echo json_encode($settings);
	$response = $app->response();
}

function getServer($id) {
	$app = Slim\Slim::getInstance();
	$app->contentType('application/json');
	$response = $app->response();
	$server = $id[0];
	$path = implode("/",array_slice($id, 1));
	if (!$path) { 
		$path = "/"; 
	} else { 
		$path = "/" . $path; 
	}
	$id = implode("/", $id);
	$root_dir = getOption('root') . '/' . $id;
	$extensions = getOption('extensions');
	echo "{\n  \"server\": \"$server\",\n";
	echo "  \"path\": \"" . encodePath($path) . "\"";
	if (is_dir($root_dir) && $handle = opendir($root_dir)) {
		echo ",\n  \"content\": [";
		$is_first = true;
		$has_search = false;
		while (false !== ($file = readdir($handle))) {
			$path_parts = pathinfo($file);
			if (isset($path_parts['extension'])) {
				$extension = $path_parts['extension'];
			}
			if ($file == '_search') {
				$has_search = true;
			} else if (!startsWith($file, '.')  && is_dir("$root_dir/$file")) {
				if ($is_first) {
					$is_first = false;
				} else {
					echo ",";
				}
				echo "\n    { \"folder\": " . json_encode($file) . ", \"link\": \"" . encodePath("$id/$file") . "\" }";
			} else if (is_file("$root_dir/$file") && in_array($extension, $extensions)) {
				if ($is_first) {
					$is_first = false;
				} else {
					echo ",";
				}
				echo "\n    { \"file\": " . json_encode($file) . ", \"link\": \"" . encodePath("$id/$file") . "\", \"type\": \"" . $extension . "\" }";
			}
		}
		closedir($handle);
		echo "\n  ],";
		echo "\n  \"search\": " . boolString($has_search);
		$response["Cache-Control"] ="max-age=600"; 
	} else {
		$response->status(404);
	}
	echo "\n}\n";
}

function controlFile($id) {
	$app = Slim\Slim::getInstance();
	$app->contentType('application/json');
	$server = $id[0];
	$path = implode("/",array_slice($id,1));
	if (!$path) {
		$path = "/";
	} else {
		$path = "/" . $path;
	}
	$id = implode("/", $id);
	$file = getOption('root') . '/' . $id;

	$request = $app->request();
	$control = $request->getBody();
	$response = $app->response();
	$result = "error";
	if (is_file($file)) {
		switch ($control["command"]) {
			case "play":
				$result = play($file);
				break;
			case "add":				
				$result = addFile($file);
				break;
			case "remove":
				$result = removeFile($file);
				break;
			default:
				$result = "illegal command";
				$response->status(400);
				break;
		}
	} else if (is_dir($file)) {
		switch ($control["command"]) {
			case "play":
				$result = playFolder($file);;
				break;
			case "add":
				$result = addFolder($file);
				break;
			case "remove":
				$result = removeFolder($file);
				break;
			case "search":
				return search($server, "/_search/" . $control["value"]);
			default:
				$result = "illegal command";
				$response->status(400);
				break;
		}
	} else {
		$response->status(404);
	}
	$body = array();
	$body['command'] = $control["command"];
	$body['result'] = $result;
	echo json_encode($body);
}

function controlPlayer() {
	$app = Slim\Slim::getInstance();
	$app->contentType('application/json');
	$request = $app->request();
	$control = $request->getBody();
	$response = $app->response();
	switch ($control["command"]) {
		case 'stop';
			$result = send('q');
			setWatchdog('STOP');
			break;
		case 'pause';
			$result = send('p');
			break;
		case 'volup';
			$result = send('+');
			break;
		case 'voldown';
			$result = send('-');
			break;
		case 'seek-30';
			$result = send(pack('n',0x5b44));
			break;
		case 'seek30';
			$result = send(pack('n',0x5b43));
			break;
		case 'seek-600';
			$result = send(pack('n',0x5b42));
			break;
		case 'seek600';
			$result = send(pack('n',0x5b41));
			break;
		case 'speedup';
			$result = send('1');
			break;
		case 'speeddown';
			$result = send('2');
			break;
		case 'nextchapter';
			$result = send('o');
			break;
		case 'prevchapter';
			$result = send('i');
			break;
		case 'nextaudio';
			$result = send('k');
			break;
		case 'prevaudio';
			$result = send('j');
			break;
		case 'togglesubtitles';
			$result = send('s');
			break;
		case 'nextsubtitles';
			$result = send('m');
			break;
		case 'prevsubtitles';
			$result = send('n');
			break;
		case 'play':
			$file = getOption('root') . '/' . urldecode($control["file"]);
			if (is_file($file)) {
				$result = play($file);
			} else {
				$result = "file not found";
			}
			break;
		case 'clear':
			if (writePlaylist(array()) === false) {
				$result = "error";
			} else {
				$result = "ok"; 
			}
			break;
		default:
			$response->status(400);
			$result = "undefined command";
			break;
	}
	$body = array();
	$body['command'] = $control["command"];
	$body['result'] = $result;
	echo json_encode($body);
}

function startsWith($haystack,$needle,$case=false) {
		if($case){return (strcmp(substr($haystack, 0, strlen($needle)),$needle)===0);}
		return (strcasecmp(substr($haystack, 0, strlen($needle)),$needle)===0);
}

function endsWith($haystack,$needle,$case=false) {
		if($case){return (strcmp(substr($haystack, strlen($haystack) - strlen($needle)),$needle)===0);}
		return (strcasecmp(substr($haystack, strlen($haystack) - strlen($needle)),$needle)===0);
}

function boolString($bValue = false) {
	return ($bValue ? 'true' : 'false');
}

function encodePath($url) {
	if (!$url) return null;
	$url = str_replace("//", "/", $url);
	$url = urlencode($url);
	$url = str_replace("+", "%20", $url);
	$url = str_replace("%2F", "/", $url);
	$url = str_replace("%3A", ":", $url);
	return $url;
}

function play($file) {
	$app = Slim\Slim::getInstance();
	$log = $app->getLog();
	$log->info("-> playFile: $file");
	$out = '';
	$info = pathinfo($file);
	$picture_extensions = array("jpg", "JPG", "png");
	$extension = $info['extension'];
	$title =  basename($file, '.' . $extension);
	if (in_array($extension, $picture_extensions)) {
		//shell_exec ('cp ' . escapeshellarg($file) . ' /tmp/fim_current');		
		$out = 'Not implemented';
	} else {
		exec('pgrep omxplayer.bin', $pids);
		if (empty($pids)) {
			@unlink (FIFO);
			posix_mkfifo(FIFO, 0777);
			chmod(FIFO, 0777);
			shell_exec('./etc/omx_runner.sh ' . escapeshellarg($file) . ' ' . getOmxplayerOptions());
			setCurrent($file);
			removeFile($file);
			setWatchdog('PLAY');
			$out = 'Now playing: ' . basename($title);
		} else {
			send('q');
			$out = play($file);
		}
	}
	return $out;
}

function setupFifo() {
	if (!file_exists(FIFO)) {
		if (!posix_mkfifo(FIFO, 0777)) {
			echo 'can\'t create ' . FIFO . ' - please fix permissions!';
			die();
		}
		if (!chmod(FIFO, 0777)) {
			echo 'can\'t change permissions for ' . FIFO . ' - please fix permissions!';
			die();
		}
	}
}

function send($command) {
	$out = 'error';
	exec('pgrep omxplayer.bin', $pids);
	if (!empty($pids)) {
		if (is_writable(FIFO)) {
			if ($fifo = fopen(FIFO, 'w')) {
				stream_set_blocking($fifo, false);
				fwrite($fifo, $command);
				fclose($fifo);
				if ($command == 'q') {
					sleep (1);
					@unlink(FIFO);
					$out = 'The player has stopped';
				} else {
					$out = 'ok';
				}
			}
		}
	} else {
		$out = 'The player is not running';
	}
	return $out;
}

function readPlaylist() {
	if (file_exists(PLAYLIST_QUEUE)) {
		return file(PLAYLIST_QUEUE);
	} else {
		return array();
	}
}

function getCurrent() {
	if (file_exists(PLAYLIST_CURRENT)) {
		return file_get_contents(PLAYLIST_CURRENT);
	} else {
		return null;
	}
}

function setCurrent($file) {
	$root_dir = getOption('root');
	return file_put_contents(PLAYLIST_CURRENT, str_replace($root_dir . "/", "", $file));	
}

function writePlaylist($playlist) {
	return file_put_contents(PLAYLIST_QUEUE, $playlist);
}

function getWatchdog() {
	$watchdog = file_get_contents(OMX_WATCHDOG);	
	if (!$watchdog) $watchdog = 'STOPPED';
	return $watchdog;
}

function setWatchdog($status) {
	return file_put_contents(OMX_WATCHDOG, $status);	
}

function addFile($file) {
	$playlist = readPlaylist();
	array_push($playlist, $file . "\n"); 
	if (writePlaylist($playlist) === false) {
		return "error";
	} else {
		return "ok"; 
	}
}

function removeFile($file) {
	$playlist = readPlaylist();
	$playlist = array_diff($playlist, array($file . "\n"));
	//$playlist = array_values($playlist);
	if (writePlaylist($playlist) === false) {
		return "error";
	} else {
		return "ok"; 
	}
}

function addFolder($folder) {
	$playlist = readPlaylist();
	if ($handle = opendir($folder)) {
		while (false !== ($file = readdir($handle))) {
			if (!startsWith($file, '.')  && is_file("$folder/$file")) {
				array_push($playlist, "$folder/$file\n"); 
			}
		}
		closedir($handle);
	} else {
		return "error";
	}
	if (writePlaylist($playlist) === false) {
		return "error";
	} else {
		return "ok"; 
	}
}

function playFolder($folder) {
	$app = Slim\Slim::getInstance();
	$log = $app->getLog();
	$log->info("-> playFolder: $folder");
	$playlist = array();
	$play_file = null;
	if ($handle = opendir($folder)) {
		while (false !== ($file = readdir($handle))) {
			if (!startsWith($file, '.')  && is_file("$folder/$file")) {
				if ($play_file == null) {
					$play_file = "$folder/$file";
				} 
				array_push($playlist, "$folder/$file\n"); 
			}
		}
		closedir($handle);
	} else {
		return "error";
	}
	if (writePlaylist($playlist) === false) {
		return "error";
	} else {
		if ($play_file != null) {
			return play($play_file);
		}
		return "ok"; 
	}
}

function removeFolder($folder) {
	$playlist = readPlaylist();
	if ($handle = opendir($folder)) {
		while (false !== ($file = readdir($handle))) {
			if (!startsWith($file, '.')  && is_file("$folder/$file")) {
				$playlist = array_diff($playlist, array("$folder/$file\n"));
			}
		}
		closedir($handle);
	} else {
		return "error";
	}
	$playlist = array_values($playlist);
	if (writePlaylist($playlist) === false) {
		return "error";
	} else {
		return "ok"; 
	}
}

function search($server, $path) {
	$id = $server . $path;
	$app = Slim\Slim::getInstance();
	$app->contentType('application/json');
	$root_dir = getOption('root') . "/" . $id;
	$extensions = getOption('extensions');
	echo "{\n  \"server\": \"$server\",\n";
	echo "  \"path\": \"" . encodePath($path) . "\"";

	exec("ls -1 " . escapeshellarg($root_dir), $output);

	echo ",\n  \"content\": [";
	$is_first = true;
	foreach ($output as &$file){
		$path_parts = pathinfo($file);
		if (isset($path_parts['extension'])) {
			$extension = $path_parts['extension'];
		}
		if (!startsWith($file, '.')  && is_dir("$root_dir/$file")) {
			if ($is_first) {
				$is_first = false;
			} else {
				echo ",";
			}
			echo "\n    { \"folder\": " . json_encode($file) . ", \"link\": \"" . encodePath("$id/$file") . "\" }";
		} else if (is_file("$root_dir/$file") && in_array($extension, $extensions)) {
			if ($is_first) {
				$is_first = false;
			} else {
				echo ",";
			}
			echo "\n    { \"file\": " . json_encode($file) . ", \"link\": \"" . encodePath("$id/$file") . "\", \"type\": \"" . $extension . "\" }";
		}
	}
	echo "\n  ],";
	echo "\n  \"search\": false";
	echo "\n}\n";
	$response = $app->response();
	$response["Cache-Control"] ="max-age=600"; 
}

function getStatus() {
	$app = Slim\Slim::getInstance();
	$app->contentType('application/json');
	$playing = getCurrent();
	//$log = $app->getLog();
	//$log->info("-> getStatus " + $playing);
	$root_dir = getOption('root');
	exec('pgrep omxplayer.bin', $pids);
	$body = array();
	$body['running'] = (empty($pids) ? false : true);
	if ($playing && !empty($pids)) {
		$playing = trim($playing);
		$file = str_replace($root_dir . "/", "", $playing);
		if (getOption("id3")) {
			$au = new AudioInfo();
			$audioinfo = $au->Info($root_dir . "/" . $playing);
			//print_r($audioinfo);
			if (isset($audioinfo['comments']['artist'])) {
				$body['artist'] = $audioinfo['comments']['artist'][0];
			}
			if (isset($audioinfo['comments']['title'])) {
				$body['title'] = $audioinfo['comments']['title'][0];
			}
			if (isset($audioinfo['comments']['album'])) {
				$body['album'] = $audioinfo['comments']['album'][0];
			}
			if (isset($audioinfo['comments']['genre'])) {
				$body['genre'] = $audioinfo['comments']['genre'][0];
			}
			if (isset($audioinfo['comments']['track'])) {
				$body['track'] = $audioinfo['comments']['track'][0];
			}
			if (isset($audioinfo['comments']['year'])) {
				$body['year'] = $audioinfo['comments']['year'][0];
			}
			if (isset($audioinfo["playtime_string"])) {
				$body['playtime'] = $audioinfo["playtime_string"];
			}
			if (isset($audioinfo["format_name"])) {
				$body['format'] = $audioinfo["format_name"];
			}
			if (isset($audioinfo["bitrate_mode"])) {
				$body['bitrate_mode'] = $audioinfo["bitrate_mode"];
			}
			if (isset($audioinfo["bitrate"])) {
				$body['bitrate'] = $audioinfo["bitrate"];
			}
			if (isset($audioinfo['video']['resolution_x']) && isset($audioinfo['video']['resolution_y'])) {
				$body['resolution'] = $audioinfo['video']['resolution_x'] . "x" . $audioinfo['video']['resolution_y'];
			}
		}
		$file = str_replace($root_dir . "/", "", $playing);
		$body['file'] = end(explode("/", $playing));
		$body['link'] = $file;
	}
	echo json_encode($body);
	$response = $app->response();
	$response["Cache-Control"] ="max-age=5"; 
}

function watchdog() {
	$app = Slim\Slim::getInstance();
	$app->contentType('application/json');
	$log = $app->getLog();
	$watchdog = getWatchdog();
	$newWatchdog = $watchdog;
	$body = array();
	switch ($watchdog) {
		case 'PLAY':
			$playlist = readPlaylist();
			exec('pgrep omxplayer.bin', $pids);
			if (sizeof($playlist) > 0) {
				if (empty($pids)) {
					// play next from playlist
					$file = trim($playlist[0]);
					$log->info("-> watchdog stopped, play " . $file);
					$playlist = array_slice($playlist, 1);
					if (writePlaylist($playlist) !== false) {
						$out = play($file);
						$body['message'] = $out;
					}
				} else {
					$log->info("-> watchdog playing, wait for end");
				}
			} else {
				if (empty($pids)) {
					// out of playlist and player stopped
					$newWatchdog = 'STOPPED';
					$log->info("-> watchdog stopped, no more tracks queued");
				} else {
					$log->info("-> watchdog still playing, no more tracks queued");
				}
			}
			break;
		case 'STOP':
			$newWatchdog = 'STOPPED';
			break;
		default:
			break;
	}
	if ($watchdog != $newWatchdog) {
		setWatchdog($newWatchdog);
	}
	$body['watchdog'] = $newWatchdog;
	echo json_encode($body);
	$response = $app->response();
	$response["Cache-Control"] ="max-age=5"; 	
	$log->info("-> watchdog, status " . $newWatchdog);
	/*
	//polling takes too much CPU time
	if ($newWatchdog == 'PLAY') {
		usleep(1000000);
		wakeWatchdog('http://localhost/omxplayer-ui/watchdog');
	}
	*/
}

function wakeWatchdog($url) {
    $parts=parse_url($url);

    $fp = fsockopen($parts['host'], 
        isset($parts['port'])?$parts['port']:80, 
        $errno, $errstr, 30);

    if ($fp == 0 ) {
		$app = Slim\Slim::getInstance();
		$log = $app->getLog();
		$log->warn("Couldn't open a socket to " . $url);
		return;
	}

    $out = "GET ".$parts['path']." HTTP/1.1\r\n";
    $out.= "Host: ".$parts['host']."\r\n";
	$out.= "Content-Type: application/json\r\n";
    $out.= "Connection: Close\r\n\r\n";
	//$log->info("Sending " . $out);
    fwrite($fp, $out);
    fclose($fp);
}

?>
