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
setupFifo();

require_once 'Slim/Slim.php';
\Slim\Slim::registerAutoloader();

$app = new \Slim\Slim();
$app->add(new \Slim\Middleware\ContentTypes());
$app->get('/servers', 'getServers');
$app->get('/playlist', 'getPlaylist');
$app->get('/servers/:id+',  'getServer');
$app->post('/servers/:id+',  'controlFile');
$app->post('/control',  'controlPlayer');
$app->run();

function getRoot() {
	return '/media/upnp';
}

function getServers() {
	$app = Slim\Slim::getInstance();
	$app->contentType('application/json');
	$root_dir = getRoot();
	if (is_dir($root_dir) && $handle = opendir($root_dir)) {
		echo "{\n  \"servers\": [";
		$is_first = true;
		while (false !== ($file = readdir($handle))) {
			if (!startsWith($file, '.')  && is_dir("$root_dir/$file")) {
				if ($is_first) {
					$is_first = false;
				} else {
					echo ",";
				}
				echo "\n    { \"server\": " . json_encode($file) . " }";
			}
		}
		closedir($handle);
		echo "\n  ]\n}\n";
	} else {
		$app->response()->status(404);
	}
}

function getPlaylist() {
	$app = Slim\Slim::getInstance();
	$app->contentType('application/json');
	$playlist = read_playlist();
	echo "{\n  \"playlist\": [";
	$is_first = true;
	foreach ($playlist as $file) {
		if ($is_first) {
			$is_first = false;
		} else {
			echo ",";
		}
		echo "\n    { \"file\": " . json_encode(trim($file)) . " }";
	}
	echo "\n  ]\n}\n";
}


function getServer($id) {
	$app = Slim\Slim::getInstance();
	$app->contentType('application/json');
	$server = $id[0];
	$path = implode("/",array_slice($id, 1));
	if (!$path) { 
		$path = "/"; 
	} else { 
		$path = "/" . $path; 
	}
	$id = implode("/", $id);
	$root_dir = getRoot() . '/' . $id;
	$extensions = array("mp3", "mpg", "avi", "mov", "mkv", "jpg", "JPG", "png", "flv", "MP3", "wav", "ogg");

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
		$response = $app->response();
		$response["Cache-Control"] ="max-age=600"; 
	} else {
		$app->response()->status(404);
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
	$file = getRoot() . '/' . $id;

	$request = $app->request();
	$control = $request->getBody();
	$result = "error";
	if (is_file($file)) {
		switch ($control["command"]) {
			case "play":
				$result = play($file);
				break;
			case "add":				
				$result = add_file($file);
				break;
			case "remove":
				$result = remove_file($file);
				break;
			default:
				$result = "illegal command";
				$app->response()->status(400);
				break;
		}
	} else if (is_dir($file)) {
		switch ($control["command"]) {
			case "play":
				$result = "not implemented";
				break;
			case "add":
				$result = add_folder($file);
				break;
			case "remove":
				$result = remove_folder($file);
				break;
			case "search":
				return search($server, "/_search/" . $control["value"]);
			default:
				$result = "illegal command";
				$app->response()->status(400);
				break;
		}
	} else {
		$app->response()->status(404);
	}
	echo "{ \"command\": \"" . $control["command"] . "\", \"result\": \"" . $result . "\" }";
}

function controlPlayer() {
	$app = Slim\Slim::getInstance();
	$app->contentType('application/json');
	$request = $app->request();
	$control = $request->getBody();
	switch ($control["command"]) {
		case 'stop';
			$result = send('q');
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
			$file = getRoot() . '/' . urldecode($control["file"]);
			if (is_file($file)) {
				$result = play($file);
			} else {
				$result = "file not found";
			}
			break;
		default:
			$app->response()->status(400);
			$result = "undefined command";
			break;
	}
	echo "{ \"command\": \"" . $control["command"] . "\", \"result\": \"" . $result . "\" }";
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
	$url = urlencode($url);
	$url = str_replace("+", "%20", $url);
	$url = str_replace("%2F", "/", $url);
	$url = str_replace("%3A", ":", $url);
	return $url;
}

function play($file) {
	$out = '';
	$info = pathinfo($file);
	$picture_extensions = array("jpg", "JPG", "png");
	$extension = $info['extension'];
	$title =  basename($file, '.' . $extension);
	if (in_array($extension, $picture_extensions)) {
		//shell_exec ('cp ' . escapeshellarg($file) . ' /tmp/fim_current');		
		$out = 'Not implemented';
	} else {
		exec('pgrep omxplayer', $pids);
		if (empty($pids)) {
			@unlink (FIFO);
			posix_mkfifo(FIFO, 0777);
			chmod(FIFO, 0777);
			shell_exec ('/usr/local/bin/omx_runner.sh ' . escapeshellarg($file));
			$out = 'Now playing ' . basename($title);
		} else {
			$out = 'Player is already runnning';
		}
	}
	return $out;
}

function setupFifo() {
	if (!file_exists(FIFO)) {
		if (!posix_mkfifo(FIFO, 0777)) {
			echo 'can\'t create '.FIFO.' - please fix persmissions!<br>';
			die();
		}
		if (!chmod(FIFO,0777)) {
			echo 'can\'t change permissions for '.FIFO.' - please fix persmissions!<br>';
			die();
		}
	}
}

function send($command) {
	$out = 'error';
	exec('pgrep omxplayer', $pids);
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

function read_playlist() {
	$playlist_file = '/tmp/omxplayer_playlist.txt';
	if (file_exists($playlist_file)) {
		return file($playlist_file);
	} else {
		return array();
	}
}

function write_playlist($playlist) {
	$playlist_file = '/tmp/omxplayer_playlist.txt';
	return file_put_contents($playlist_file, $playlist);
}

function add_file($file) {
	$playlist = read_playlist();
	array_push($playlist, $file . "\n"); 
	if (!write_playlist($playlist)) {
		return "error";
	} else {
		return "ok"; 
	}
}

function remove_file($file) {
	$playlist = read_playlist();
	$playlist = array_diff($playlist, array($file . "\n"));
	$playlist = array_values($playlist);
	if (!write_playlist($playlist)) {
		return "error";
	} else {
		return "ok"; 
	}
}

function add_folder($folder) {
	$playlist = read_playlist();
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
	if (!write_playlist($playlist)) {
		return "error";
	} else {
		return "ok"; 
	}
}

function remove_folder($folder) {
	$playlist = read_playlist();
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
	if (!write_playlist($playlist)) {
		return "error";
	} else {
		return "ok"; 
	}
}


function search($server, $path) {
	$id = $server . $path;
	$app = Slim\Slim::getInstance();
	$app->contentType('application/json');
	$root_dir = getRoot() . "/" . $id;
	$extensions = array("mp3", "mpg", "avi", "mov", "mkv", "jpg", "JPG", "png", "flv", "MP3", "wav", "ogg");
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
	$response = $app->response();
	$response["Cache-Control"] ="max-age=600"; 
	echo "\n}\n";
}

?>
