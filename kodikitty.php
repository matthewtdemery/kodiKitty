<?php

// pn - probably can remove since no large db pulls will be used
ini_set('memory_limit', '1G');

// Allow different global variables based on the filename allowing for easy transition between development
// and production settings and operations. Eg. verbose error outputting for dev debugging versus quiet output
// for production operation

$invocation_magic = __FILE__; // Make sure nothing else is going to overwrite
// This file could just be included in this one, however, I have it external for security
include 'kcs.php';

// Set a base timezone, however, the timezone will adjust based on the DID user's preferences
// Will be used in future features such as watch party scheduling
date_default_timezone_set('UTC'); 

// `composer` library integration
include __DIR__.'/vendor/autoload.php';

use Discord\Discord;
use Discord\Parts\User\User;
use Discord\Parts\User\Client;
use Discord\Parts\User\Member;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Channel\Reaction;
use Discord\Parts\WebSockets\MessageReaction;
use Discord\WebSockets\Intents;
use Discord\WebSockets\Event;
use Discord\Builders\MessageBuilder;
use Discord\Builders\Components;
use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\SelectMenu;
use Discord\Builders\Components\Option;

use YouTube\YouTubeDownloader;
use YouTube\Exception\YouTubeException;

// pn - move to functions script
if (!function_exists('str_contains')) {
  function str_contains($haystack, $needle) {
    return $needle !== '' && mb_strpos($haystack, $needle) !== false;
  }
}

// Send a message to a discord user. 
function sendMsg($id, $msg, $type = 'user', $server = 788607168228229160) {
	$embed = null;
	// If $msg is an array, it contains an embed. 
	if (is_array($msg)) {
		// Split the array. 0 being the message and 1 being the embed
		$embed = $msg[1];
		$msg = $msg[0];
	}
	// Attach the app signature to the end of the message to be sent
	$msg .= "\n".tacoGen();
	
	// bring the current discord loop in
	global $discord;
	
	// adjust destination based on function argument input
	if ( $type == 'channel' ) { 
		$guild = $discord->guilds->get('id', $server);
		$message = $guild->channels->get('id', $id);
	}
	if ( $type == 'user' ) {
		$message = $discord->factory(\Discord\Parts\User\User::class, [
			'id' => $id, //'380675774794956800',
		]);
	}
	if (!$msg) { $msg = "Message is null?"; } 
	$message->sendMessage($msg,false,$embed)->then(function(Message $message) {
		echo "\nMessage sent!\n";
		var_dump($message['id']);
	});
}

// Check to see if a message is a workspace
function checkWorkspace($rdata,$name = '') {
	global $wsLines;

	$wid = $rdata['message_id'];
	$cid = $rdata['channel_id'];
	var_dump('checkWS',$wid,$cid,$name);

	if (in_array($cid,array_keys($wsLines))) {
		var_dump('$wsLines[$cid][$name]');
		var_dump($wsLines[$cid][$name]);
		$name = $wsLines['wsnames'][$cid][$wid];
		if ($wsLines[$cid][$name]['wid'] == $wid) { if ($name == '') { $name =  true; } return $name; }
	}

	include('db.php');
	$data = ['wid' => $wid,'cid' => $cid];
	$pre = $GLOBALS['filePrefix'];
	$query = "SELECT * FROM `".$pre."workspaces` WHERE cid=:cid AND wid=:wid"; // $andgid";

	var_dump($data);
	$res = $dbconn->prepare($query);
	$res->execute($data);
	if ($results = $res->fetch(PDO::FETCH_ASSOC)) {
		var_dump('$results');
		var_dump($results);
		$dbcid = $results['cid'];
		$dbwid = $results['wid'];
		$dbname = $results['name'];
		if ($wid == $dbwid && $cid == $dbcid) { if ($dbname == '') { $dbname =  true; } return $dbname; }
	} else {
		var_dump('FALSE $results');
		var_dump($results);
	}
	return false;
}

// Check if a workspace exists for current channel
function findWorkspace($data,$name = '') {
	$cid = $data['channel_id'];
	$gid = $data['guild_id'];

	$andgid = '';
	$data = ['cid' => $cid,'name' => $name];

	$pre = $GLOBALS['filePrefix'];
	$query = "SELECT wid,name FROM `".$pre."workspaces` WHERE cid=:cid AND name=:name"; // $andgid";

	var_dump($data);
	include('db.php');
	$res = $dbconn->prepare($query);
	$res->execute($data);
	$dbname = false;
	$wid = false;
	if ($results = $res->fetch(PDO::FETCH_ASSOC)) {
		var_dump($results);
		$wid = $results['wid'];
		$dbname = $results['name'];
	}
	return $wid;
}

// Create or reset a workspace and populate with $output
function initWorkspace($data, $wid = null, $new = false, $output = null, $name = '') {
	$ooutput = $output;
	var_dump('$output 0',$output);

	if ($name == 'player') {
		var_dump('--------------------$data 0',$data);
	}
	
	$reset = false;
	$curwid = null;
	if ($wid == 'reset') {
		$reset = true;
		$wid = null;
	}
	if ($wid == null && !$new) {
		$wid = findWorkspace($data,$name);
		if (!$wid) {
			echo "workspace id not found for channel\n";
			$new = true;
		} else {
			echo "workspace id found! $wid\n";
			$curwid = $wid;
		}
	}

	$channel = getChannel($data);
	if (!$channel) {
		return "ERR84389743";
	}
	if ($wid) {
		$message = $channel->messages->get('id', $wid);
	}
	var_dump('$reset');
	var_dump($reset);
	var_dump('$output 1',$output);
	if ($reset && !$new) {
		$curwid = $reset = $wid;
	}
	if ($reset && !$new && $output == null) {
		$reset = $wid;
		$message = $channel->messages->get('id', $wid);
		var_dump('$message->content');
		if ($output == NULL && $message !== NULL) { $output = $message->content; }
		if ($output == NULL) { $output = print_r($ooutput,true)." | $reset $wid | msg null?"; }
		$new = true;
	}
		var_dump('$output 2',$output);
	if ($output == null) { $output = "Base Template for Workspace Modules\n".date('Y-m-d H:i:s'); }
	if ($new || $reset) {
		var_dump('$new $reset',$new, $reset);

		$channel->sendMessage(splitMsg($output)[0])->then(function (Message $message) use ($data,$output,$reset,$curwid,$name) {
			$wid = $message['id'];
			$cid = $message['channel_id'];
			$gid = $message['guild_id'];

			include('db.php');
			$pre = $GLOBALS['filePrefix'];
			$query = "INSERT INTO `".$pre."workspaces` (`wid`,`cid`,`gid`,`name`) VALUES (:wid,:cid,:gid,:name) ON DUPLICATE KEY UPDATE wid=:wid";
			echo "saving workspace data\n";
			$res = $dbconn->prepare($query);
				echo "s98fd7s09d8yf0s8yf0s98ssssssssssssssssssssssssssssssssssssssss";
			$err = 0;
			var_dump("===================== WID $curwid | $reset | $wid =================================");

			$wsarrname = 'playlist';
			if ($name == 'player') {
				global $lastStatusData;
				var_dump('6666666666666666666666666666666666666666666',$lastStatusData,$data);
				$lastStatusData = $data;
				$wsarrname = 'player';
			}
			foreach ($GLOBALS[$wsarrname.'Array'] AS $emotename => $emote) {
				$message->react($emote);
			}
			if (!$status = $res->execute(['cid' => $cid,'gid' => $gid,'wid' => $wid,'name'=>$name])) {
			echo "88888888888888888888888888888888888888888888888eeeeer66";
			
			$err = 1;
				
			} else if ($reset) {
				$channel = getChannel($data);
				var_dump("WID $wid =================================");
				
				$channel->messages->fetch($reset)->then(function (Message $oldMsg) use ($reset) {
					var_dump("delete $reset =================================");
					$oldMsg->delete()->then(function () {
						var_dump("delete 0000000000000000=================================");
					});
				});
			}
			if ($output !== null) { outputWorkspace($data,$output,$name); }
		});
	} else {
		$output = "UPDATED!!!!!! $output";
		$channel = getChannel($data);
		outputWorkspace($data,$output,$name);
	}
}

$wsLines = [];
$wsLines['wsnames'] = [];

// Pagination action for workspaces
function wsPages($data,$lines = '',$pag = 0,$name = '') {
	global $wsLines;
	var_dump('$wspages9099999999999999999',$pag);
	$channel = getChannel($data);
	$cid = $data['channel_id'];

	$crc = $total = null;
	$wid = findWorkspace($data,$name);

	if ($lines !== NULL && $lines !== false) {
		if (!$wid) {
			initWorkspace($data,null, true, $lines,$name);
			return;
		}
		if (!is_array($lines) && is_string($lines)) {
			$lines = splitMsg($lines);
		}		
		$crc = crc32(json_encode($lines));
		$total = count($lines);
	}
	
	$wsLines['wsnames'][$cid][$wid] = $name;
	
	if (isset($wsLines[$cid][$name]) && ($lines === NULL || $wsLines[$cid][$name]['crc'] == $crc)) {
		$lines = $wsLines[$cid][$name]['lines'];
		$wid = $wsLines[$cid][$name]['wid'];	
		$total = $wsLines[$cid][$name]['total'];
		// $wsLines[$cid][$name]['page'] = $page;
		if (isset($wsLines[$cid][$name]['page'])) {
			$page = intval($wsLines[$cid][$name]['page']);
		} else {
			$page = 1;
		}
	}

	if (!is_numeric($pag)) {
		if ($pag == 'n') { $page++; }
		if ($pag == 'b') { $page--; }
		if ($pag == 's') { $page = 1; }
		if ($pag == 'e') { $page = $total; }
	} else {
		$page = intval($pag);
	}	
	
	if ($page > $total) {
		$page = $total;
	}
	if ($page < 1) {
		$page = 1;
	}
	
	if (!isset($wsLines[$cid][$name]) || ($lines !== NULL && $wsLines[$cid][$name]['crc'] != $crc)) {
		$wsLines[$cid][$name]['crc'] = $crc;
		$wsLines[$cid][$name]['lines'] = $lines;
		$wsLines[$cid][$name]['total'] = $total;
		$wsLines[$cid][$name]['wid'] = $wid;
	// } else if (isset($wsLines[$cid][$name]) && ($lines === NULL || $wsLines[$cid][$name]['crc'] == $crc)) {
		// $lines = $wsLines[$cid][$name]['lines'];
		// $wid = $wsLines[$cid][$name]['wid'];
	}
	$wsLines[$cid][$name]['page'] = $page;

	file_put_contents('wslines.json',json_encode($wsLines, JSON_PRETTY_PRINT));

	if ($lines === NULL) {
		echo "\n NULL LINES IN WS OUTPUT \n"; return;
	}

	if ($lines === false) {
		echo "\n FALSE value LINES IN WS OUTPUT \n"; return;
	}

	$pageInfo = "\n Page $page of $total\n";
	
	if ($total == 1 || $name == 'player') {
		$pageInfo = '';
	}
	global $kodi;
	$pageInfo = "\n Mode: [".$kodi['plmode']."] $pageInfo";
	$output = $lines[$page-1].$pageInfo;

	$channel->messages->fetch($wid)->then(function (Message $Msg) use ($output) {
		$Msg->edit(MessageBuilder::new()->setContent($output)); //->then(function (Message $message) {
	});
}

// Send output to workspace, paginate as necessary
function outputWorkspace($data,$output,$name = "") {
	if (!isset($data['channel_id'])) {
		var_dump('$data,$output,$name');
		var_dump($data,$output,$name,'AAAAAAAAAAAA');
		return;
	}
	$cid = $data['channel_id'];
	$wid = findWorkspace($data,$name);
	if (!$wid) {
		initWorkspace($data,null,true, $output,$name);
		return;
	}
	wsPages($data,$output,0,$name);
}

// Good spelling means good communication!
function spellCheck($word,$good = false) {
	$pspell = pspell_new("en");
	$ret = false;
	if (!pspell_check($pspell, $word)) {
    $suggestions = pspell_suggest($pspell, $word);
		if (count($suggestions)) { $bsuggestions = $suggestions; $bsuggestions[0] = '**'.$bsuggestions[0].'**'; }
		$ret = "Did you mean ".niceList($bsuggestions,'','or')."?";
		if ($good == 'array' && count($suggestions) == 1) {
			$ret = $suggestions[0];
		}
	} else if ($good) {
		$ret = "**$word** is spelled correctly!";
	}		
	return $ret;
}

// Message signature
function tacoGen() {
	$tmews = rand(1,3);
	$mews = "";
	for ($k = 0 ; $k < $tmews; $k++){ $mews .='meow '; }
	return "\n*".ucfirst(trim($mews))."!*";
}

// Parse and prepare a channel object from almost any breadcrumb
function getChannel($data) {
	global $discord;
	if(is_numeric($data)) {
		$channel = $discord->factory(\Discord\Parts\User\User::class, [
			'id' => preg_replace("/[^0-9]/", "", $data), //'380675774794956800',
		]);
	} else if($data->channel->guild_id === NULL) { 
		$channel = $discord->getChannel($data['channel_id']);
	} else {
		$guild = $discord->guilds->get('id', $data['guild_id']);
	  $channel = $guild->channels->get('id', $data['channel_id']); 
	}
	return $channel;
}

// Parse and prepare a guild object from almost any breadcrumb
function getGuild($data) {
	global $discord;
	$guild = null;
	if(is_numeric($data) || $data->channel->guild_id === NULL) {
		return $guild;
	} else {
		$guild = $discord->guilds->get('id', $data['guild_id']);
	}
	return $guild;
}

// Patch a message
function updateMsg($data, $message = '', $embed = NULL) {
	var_dump($embed);
	
	if (!$message && !$embed) { return;}
	global $discord;

	//$msg = $message;
	$channel = getChannel($data);
	
			$channel->sendMessage($message, false, $embed);
// $channel->sendMessage($message)->done(function (Message $message) {
    // ...
// });	
}

// Prepare messages for discord character limit
function splitMsg($message) {
	if (is_array($message)) {
		return $message;
	}
	$lines = null;
	if (strlen($message) > 2000) {
		$x = 1990;
		$message = str_replace(" ","===SPACE===",$message);
		$message = str_replace("\n"," ",$message);
		$message = wordwrap($message, $x,'===LINEBREAK===');
		$message = str_replace(" ","\n",$message);
		$message = str_replace("===SPACE===",' ',$message);
		$lines = explode('===LINEBREAK===', $message);
	} else {
		$lines = [$message];
	}
	return $lines;
}

// Reply to received message
function sendReply($data, $message = '', $embed = NULL) {
	if (!$message && !$embed) { return;}

	if (filter_var($message, FILTER_VALIDATE_URL) === false) {
		$message .= " ".tacoGen();
	}
	$lines = splitMsg($message);
	global $discord;
	$channel = getChannel($data);
	if ($channel == NULL) {
		return;
	}
	$index = 0;
	if (!is_array($lines)) {
		$lines = [$lines];
	}
	foreach ($lines AS $message) {
		$index++;
		if ($index == count($lines)) {
			$channel->sendMessage($message, false, $embed)->then(function(Message $message) {
				echo "\nMessage sent!\n";
				return;
			});
		} else {
			$channel->sendMessage($message)->then(function(Message $message) {
				echo "\nMessage sent!\n";
				return;
			});
		}
	}
	unset($embed);
}

// Cheap AI chatbot. So so cheap....
if (!$chatbot = json_decode(file_get_contents('chatbot.json'),true)) {
	$chatbot = ['uid'=> '887d79e5bbec1ee2','sid'=> []];
	file_put_contents('chatbot.json',json_encode($chatbot));
}

function chatBot($arg,$did) {
	global $chatbot;
	$return = [];
	if (!isset($chatbot['sid'][$did]) || !$chatbot['sid'][$did] ) {
		$chatbot['sid'][$did] = false;
		file_put_contents('chatbot.json',json_encode($chatbot));
		$post = [
			'uid' => '887d79e5bbec1ee2',
			'intro' => true
		];
		$response = curl('https://kuli.kuki.ai/cptalk', http_build_query($post));

		$json = false;
		if (isset($response['content'])) {
			$json = json_decode($response['content'],true);
		}
		if (!$json || !isset($json['responses']) || 0 === count($json['responses'])) {
			var_dump($response,$json,$chatbot);
			return "Error 56";
		}

		$chatbot['sid'][$did] = $json['sessionid'];
		file_put_contents('chatbot.json',json_encode($chatbot));
		var_dump($response,$json,$chatbot);
		$return = array_merge($return, array_map('strip_tags_content',    $json['responses']));
	}
	$sid = $chatbot['sid'][$did];
	$uid = $chatbot['uid'];
	$post = [		'uid'=>$uid,		'input'=>$arg,		'sessionid'=>$sid	];
	$response = curl('https://kuli.kuki.ai/cptalk', http_build_query($post));
	if (isset($response['content'])) {
		$json = json_decode($response['content'],true);
	}
	if (!$json ||!isset($json['responses']) || !count($json['responses'])) {
		var_dump($response,$json,$chatbot);
		return "Error 57";
	}
	$return = array_merge($return, array_map('strip_tags_content',    $json['responses']));
	array_walk($return, function(&$value, $key) use($did) { $value = "<@$did>, ".$value; } );
	return $return;
}

// Prepare output for discord
function strip_tags_content($text) {
  return preg_replace('@<(\w+)\b.*?>.*?</\1>@si', '', $text);
}

function validVideoId($id) {
	return !!(getimagesize("http://img.youtube.com/vi/$id/mqdefault.jpg")[0]);
}

function curl( $url,$curl_data = null,$username = false ) {
  $options = array(
    CURLOPT_RETURNTRANSFER => true,         // return web page
    CURLOPT_HEADER         => false,        // don't return headers
    CURLOPT_FOLLOWLOCATION => true,         // follow redirects
    CURLOPT_ENCODING       => "",           // handle all encodings
    CURLOPT_USERAGENT      => "Mozilla/5.0 (Windows NT 6.3; Win64; x64; rv:109.0) Gecko/20100101 Firefox/111.0",     // who am i
    CURLOPT_AUTOREFERER    => true,         // set referer on redirect
    CURLOPT_CONNECTTIMEOUT => 120,          // timeout on connect
    CURLOPT_TIMEOUT        => 120,          // timeout on response
    CURLOPT_MAXREDIRS      => 10,           // stop after 10 redirects
    CURLOPT_SSL_VERIFYHOST => 2,            // don't verify ssl
    CURLOPT_SSL_VERIFYPEER => true,        //
    CURLOPT_VERBOSE        => 0                //
  );
  $ch = curl_init($url);
  curl_setopt_array($ch,$options);
	if ($curl_data != null) {
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $curl_data);
	}

  $ret['content'] = curl_exec($ch);
  $ret['err']     = curl_errno($ch);
  $ret['errmsg']  = curl_error($ch) ;
  $ret['header']  = curl_getinfo($ch);
  curl_close($ch);
  return $ret;
}

function getLineWithString($lines, $str) {
    if (!is_array($lines)) {
			$lines = explode("\n",$lines);
		}
    foreach ($lines as $lineNumber => $line) {
        if (strpos($line, $str) !== false) {
            return $line;
        }
    }
    return -1;
}

function percentage($partialValue, $totalValue, $round = true) {
  $val = ($partialValue / $totalValue)*100; //*100);
	$rval = $val;
	if ($round) { $rval = round($val); }
	if ($rval == 100 && $val != 100 && $round) {
		return $val;
	} else if ($round) {
		return $rval;
	} else {
		return $val;
	}
}

$acct = [];
$timezone = "UTC";

function duration($odate, $cdate) {
	$extraday = 0;
	if ($odate > $cdate) {$extraday = 1; }
	$start_date = new DateTime($odate);
	$end_date = new DateTime($cdate);
	$end_date->modify("+$extraday day");
	$since_start = $start_date->diff($end_date);
	$minutes = $since_start->h * 60;
	$minutes += $since_start->i;
	return $minutes;
}

function human_time_diff($time,$now = '',$cprefix = false) {
		global $timezone;
		$return = [];
		date_default_timezone_set($timezone);
		if ($now == '') { 
			$now = new \DateTime();
		} else if (is_string($now)) { 
			$now = new \DateTime($now); 
		}
		if (is_string($time)) { 
			$time = new \DateTime($time); 
		}
		$when = $time->format('Y-m-d');
		$interval = $now->diff($time);

		$yesterday = new \DateTime();
		$yesterday = $yesterday->sub(new DateInterval('P1D'))->format('Y-m-d');
		$tomorrow = new \DateTime();
		$tomorrow = $tomorrow->add(new DateInterval('P1D'))->format('Y-m-d');
		$yestermorrow = '';
		if ($yesterday == $when) {$yestermorrow = 'yesterday '; }
		if ($tomorrow == $when) {$yestermorrow = 'tomorrow '; }

		$prefix = '';
		$suffix = '';
			// $interval = date_create('now')->diff( $datetime );
			if ($cprefix) { $prefix = $cprefix; } else 
			{
				$suffix = ( $interval->invert ? ' ago' : '' );
				$prefix = ( $interval->invert ? '' : 'in ' );
			}
			if ( $v = $interval->y >= 1 ) $return[] = pluralize( $interval->y, 'year' );
			if ( $v = $interval->m >= 1 ) $return[] = pluralize( $interval->m, 'month' );
			if ( $v = $interval->d >= 1 ) $return[] = pluralize( $interval->d, 'day' );
			if ( $v = $interval->h >= 1 ) $return[] = pluralize( $interval->h, 'hour' );
			if ( $v = $interval->i >= 1 || count($return) == 0 ) $return[] = pluralize( $interval->i, 'minute' );
			//$return[] = pluralize( $interval->i, 'minute' );
			// $return[] = pluralize( $interval->s, 'second' );
			return $yestermorrow.$prefix.niceList($return).$suffix;
	}

function pluralize( $count, $text ) {
		return $count . ( ( $count == 1 ) ? ( " $text" ) : ( " ${text}s" ) );
	}

function reducearray($array) {
	foreach ($array AS $data => $index) {
		$index = array_intersect_key($index, array_unique(array_map('serialize', $index)));
		$narray[$data] = $index;
	}
	return $narray;
}

$edataset = [];

require("phpKodi-api.php");

function cacheYTNames($dir) {
	if ($kerr = kodiError($dir)) { return $kerr; }

	file_put_contents('ytnames.json',json_encode($dir, JSON_PRETTY_PRINT));
	global $_Kodi;
	global $ytmap;
	$ndir = ['ytparsed'=>true];
	$page = 1;
	$nextpage = false;
	while ($page < 4 && !isset($dir['error'])) {
 		$files = $dir;
		if (isset($dir['result'])) {
			$files = $dir['result']['files'];
		} else	if (isset($dir['files'])) {
			$files = $dir['files'];
		}
		foreach ($files AS $key => $item) {
			$filename = $item['file'];
			$type = $item['filetype'];
			$file = false;
			if (isset($item['mediapath'])) {
				$file = $item['mediapath'];
			}
			if (!$file) { $file = $filename; }
			$file = stripslashes($file);
			$artist = [];
			if (isset($item['artist'])) {
				$artist = $item['artist'];
			}
			$label = $item['label'];
			$title = $item['title'];
			$name = $title;
			if (!$name) { $name = $label; }
			if (!$name) { $name = $filename; }
			$name = kodiTitle($name,$artist,$file);
	
			if ($type == 'directory' && startsWith($item['title'],"Next page") && startsWith($file,'plugin://plugin.video.youtube/kodion/search/query')) {
				$nextpage = $file;
			} else if ($type == 'directory') {
				$ndir[] = $item;
			}
			if (startsWith($file,'plugin://plugin.video.youtube/play/?video_id=')) {
				$ndir[] = $item;
				if ($type == 'file') {
					$vid = explode('=',$file)[1];
					$ytmap[$vid] = $name;
				}
			}
		}
		$page++;
		if (!$nextpage) {
			break;
		}
		$npath = $nextpage;
		$json = '{"jsonrpc":"2.0","method":"Files.GetDirectory","id":"1743603938944","params":{"directory":"'.$npath.'","media":"video","properties":["title","file","artist","duration","runtime","playcount","mimetype","thumbnail","dateadded"],"sort":{"method":"none","order":"ascending"}}}';
		$dir = $_Kodi->sendJson($json);
		var_dump($page);
	}
	file_put_contents('ndir.json',json_encode($ndir, JSON_PRETTY_PRINT));
	file_put_contents('ytmap.json',json_encode($ytmap, JSON_PRETTY_PRINT));
	var_dump($ytmap);
	return $ndir;

}

$kodi = [];
$kodi['paths'] = null;
$kodi['sources'] = null;
$kodi['hist'] = ['sources'];
$kodi['playrandom'] = false;
$kodi['queuerandom'] = false;
$kodi['playing'] = null;
$kodi['playfile'] = null;
$kodi['playfilename'] = null;
$kodi['playpic'] = null;

function kodiTitle($name,$artist = [],$file = false) {
	if (is_array($artist) && count($artist)) {
		$artist = preg_replace("/ - Topic$/",'',$artist[0]);
		if ($artist) {
			$name = str_replace($artist,'',$name);
		} else {
			$artist = false;
		}
	} else {
		$artist = false;
	}
	$name = str_replace(['[B]','[/B]'],'**',$name);

	$vid = false;
	if ($file && startsWith($file,'plugin://plugin.video.youtube/play/?video_id=')) {
		$vid = explode('=',$file)[1];
	}
	if ($vid) { 
		preg_match("/\\\u[0-f].../",trim(json_encode($name),'"'),$matches);
		if (count($matches) || strpos($name,':')) {
			$name = " $name [$vid](<https://www.youtube.com/watch?v=$vid>)";
		} else {
			$name = " [$name](<https://www.youtube.com/watch?v=$vid>)";
		}
	}

	if ($artist) {
		$name = '['.$artist.']'.$name;
	}
	return $name;	
}

function secsToTimeArray($secs,$assoc = false) {
	$newtime = explode(':',gmdate("H:i:s", $secs));
	if ($assoc) { return ['hours'=>intval($newtime[0]),'minutes'=>intval($newtime[1]),'seconds' => intval($newtime[2]),'milliseconds'=>00]; }
	return $newtime;
}

function usortLabel($a, $b) { return (preg_replace('/[^a-z\d ]/i','',$a['filetype'].$a['label']) <=> preg_replace('/[^a-z\d ]/i','',$b['filetype'].$b['label'])); }

function renderDir($dirs,$curpath,$curitem = null,$paginate = false) {
	if ($curitem !== null) { $curitem = stripslashes($curitem); }
	if ($kerr = kodiError($dirs)) { return $kerr; }

	$files = $dirs;
	if (isset($dirs['result'])) {
		$files = $dirs['result']['files'];
	} else	if (isset($dirs['files'])) {
		$files = $dirs['files'];
	}
	global $kodi;
	$kodi['plmode'] = "files";

	if (startsWith($curpath,'plugin://plugin.video.youtube/')) {
		$kodi['plmode'] = "yt";
		if (!isset($files['ytparsed'])) {
			if (!$files = cacheYTNames($files)) { return "E4744"; }
			unset($files['ytparsed']);
		}
	}
	if (isset($files['ytparsed'])) {
		unset($files['ytparsed']);
	}

	$dirs = $files;
	if ($curpath == 'bookmarks') {
		$kodi['plmode'] = "bookmarks";
	}

	if (	$kodi['plmode'] == 'files') {
		var_dump(usort($dirs,'usortLabel'),$dirs);		
		file_put_contents('dirfiles.json',json_encode($dirs, JSON_PRETTY_PRINT));
	}

	global $nums;
	$play = '▶';
	$pause = '⏸';
	
	$kodi['path'] = $curpath;

	if (!count($kodi['hist']) || $kodi['hist'][array_key_last($kodi['hist'])] !== $curpath) {
		$kodi['hist'][] = $curpath;
	}
	if ($curpath == "sources" && $dirs[0]['label'] == "Video add-ons") { $paginate = true;array_shift($dirs); }
	$kodi['menu'] = [];
	$page = 0;
	if ($paginate) {
			$output[$page] = "";
	} else {
		$output = '';
	}

	if (!count($dirs)) {
		$output = '0 results';
	}
	
	$ic = 1;
	foreach ($dirs AS $key => $item) {
		$filename = $item['file'];
		$file = false;
		if (isset($item['mediapath'])) {
			$file = $item['mediapath'];
		}
		if (!$file) { $file = $filename; }
		$artist = [];
		if (isset($item['artist'])) {
			$artist = $item['artist'];
		}
		$title = $item['title'];
		$name = $item['label'];
		if (!$name) { $name = $title; }
		if (!$name) { $name = $filename; }
		$vid = false;
		$file = stripslashes($file);
		
		$name = kodiTitle($name,$artist,$file);

		$type = ($curpath == 'sources')?'directory':$item['filetype'];
		$path = stripslashes($file);
		$pic = $item['thumbnail'];
		if (!$name) { $name = $path; }
		$watchedbool = false;
		$watched = '🔲';
		if (isset($item['playcount']) && $item['playcount'] == 1) {
			$watchedbool = true;
			$watched = "✅";
		}
		$totaltime = '{}';
		if (isset($item['totaltime']))	{
			$totaltime = json_encode($item['totaltime']);
		}
		$dur = '';
		if (isset($item['runtime']))	{
			$totaltime = secsToTimeArray($item['runtime']);
			$dur = gmdate("H:i:s", $item['runtime']);
		}
		$resume = 0;
		if (isset($item['resume']) && is_numeric($item['resume']['position']) && $item['resume']['position'] > 0)	{
			var_dump('777777777777777777',$item['resume']['position']);
			$watched = $pause;
			if (!$dur && isset($item['resume']['total'])) {
				$totaltime = secsToTimeArray($item['resume']['total']);
				$dur = gmdate("H:i:s", $item['resume']['total']);
			}
			$resume = $item['resume']['position'];
			if ($dur) { 
				$dur = gmdate("H:i:s", $resume)." / ".$dur; 
			}	else { $dur = gmdate("H:i:s", $resume); }
		}
		if ($type == 'directory') {
			$watched = '📁';
		}
		if ($path == $curitem) {
			$watchedbool = 'playing';
			$watched = $play;
		}
		
		$code = '';
		if ($paginate) {
		}
		
		if ($paginate) {
			if ($ic >= 11) { $page++; $ic = 1;
				$code = $nums[$ic];
				$output[$page] = "$code $watched: $name $dur\n";
			} else {
				$code = $nums[$ic];
				$output[$page] .= "$code $watched: $name $dur\n";
			}
		} else {
			$output .= "$watched$key: $name $dur\n";
		}
		if (is_array($totaltime)) {$totaltime = json_encode($totaltime); }
		$kodi['menu'][$key] = [$type,$path,$watchedbool,$name,intval($resume),$totaltime,$code,[$page,$ic]];
		$ic++;
	}
	file_put_contents('menu.json',$curitem."\n\n".json_encode($kodi['menu'], JSON_PRETTY_PRINT));
	return $output;
}

$nums = ["0️⃣","1️⃣","2️⃣","3️⃣","4️⃣","5️⃣","6️⃣","7️⃣","8️⃣","9️⃣","🔟"];

$ssaveron = false;

function renderQueue($items,$curitem = null) {
	$play = '▶';
	global $ssaveron;
	global $ytmap;
	global $kodi;
	$kodi['plmode'] = "queue";
	$ssaveron = false;
	if (!isset($items['result'])) {
		var_dump($items,'queue render ERROR');
		return false;
	}
	$items = $items['result']['items'];
	
	$kodi['hist'][] = "queue";
	$kodi['queue'] = [];

	$output = "            **__Queue List__** \n";

	if (!count($items)) {
		$output .= '0 results';
	}

	var_dump($ytmap);
	foreach ($items AS $key => $item) {
		if (isset($item['mediapath']) && $item['mediapath']) {
			$path = $item['mediapath'];
		} else {
			$path = $item['file'];
		}
		$path = stripslashes($path);

		var_dump('AAAAAAAAAAAAAAAAAAAAA',$key,$curitem,$path);

		$name = $item['title'];
		$label = $item['label'];
		if (!$name) {	$name = $item['label'];	}
		if (!$name) {	$name = $item['file'];	}
		if (startsWith($path,'plugin://plugin.video.youtube/play/?video_id=') ) {
			$vid = explode('=',$path)[1];
			if (isset($ytmap[$vid])) {
				$name = $ytmap[$vid];
			}
		}
		$pic = $item['thumbnail'];
		$watchedbool = false;
		$watched = '🔲';
		if (isset($item['playcount']) && $item['playcount'] == 1) {
			$watchedbool = true;
			$watched = "✅";
		}
		if ($path == $curitem) {
			$watchedbool = 'playing';
			$watched = $play;
		}
		$kodi['queue'][$key] = ['file',$path,$watchedbool,$name,$pic];
		
		$output .= " $watched$key: $name \n";
	}
	file_put_contents('queuemenu.json',$curitem."\n\n".json_encode($kodi['queue'], JSON_PRETTY_PRINT));
	file_put_contents('queueitems.json',json_encode($items, JSON_PRETTY_PRINT));
	return $output;
}

function filterArrayByKeyValue($array, $key, $keyValue) {
  return array_filter($array, function($value) use ($key, $keyValue) {
    return $value[$key] == $keyValue; 
  });
}

$curpath = '';

function padInt($val) {
	return str_pad($val,2,'0',STR_PAD_LEFT);
}

function connectKodi($verbose = false) {
	global $_Kodi;
	$IP = 'localhost:8088';
	sendMsg('380675774794956800',  "Connecting to $IP...");
	$_Kodi = new Kodi($IP);
	if (isset($_Kodi->error)) { 
		sendMsg('380675774794956800',  print_r($_Kodi->error,true));
	} else {
		sendMsg('380675774794956800',  "Connected to $IP");
	}
}

function fixKodiAudio() {
	global $kodi;
	if ((isset($kodi['playing']) && startsWith($kodi['playing'],'plugin://plugin.video.youtube/')) || (isset($kodi['path']) && startsWith($kodi['path'],'plugin://plugin.video.youtube/play/?video_id=') )) {
		var_dump("I sense youtube is afoot. dipping");
		return;
	}
	
	global $_Kodi;
	$json = '{"jsonrpc":"2.0","method":"Player.GetProperties","params":[1,["audiostreams","currentaudiostream"]],"id":9}';
	$props = $_Kodi->sendJson($json)['result'];
	if (!$props) { return "props error";	}
	if (isset($props['currentaudiostream']['language']) && $props['currentaudiostream']['language'] !== 'eng') {
		$engstreams = filterArrayByKeyValue($props['audiostreams'],'language','eng');
		if (!count($engstreams)) { return json_encode($props,JSON_PRETTY_PRINT)."\nno english streams found"	;	}
		$aindex = $engstreams[array_keys($engstreams)[0]]['index'];
		$json = '{"jsonrpc":"2.0","method":"Player.SetAudioStream","params":[1,'.intval($aindex).'],"id":10}';
		$props = $_Kodi->sendJson($json)['result'];
	}
	return $props;
}

$bmmap = [];
$fmap = [];
$ytmap = [];

function timeArrayToSecs($time) {
	if (is_string($time) && trim($time) == "") { return 0; }
	if (is_array($time)) {
		if (isset($time['hours'])) {
			unset($time['milliseconds']);
		}
		$time = implode(':',array_map('padInt',$time));
	}
	$sec = 0;
	var_dump($time);
	if (is_string($time) && trim($time) == "") { return 0; }
	foreach (array_reverse(explode(':', $time)) as $k => $v) $sec += pow(60, $k) * $v;
	return $sec;
}

function renderBMS($did = false) {
	if (!$did) { return "ERR:84-DID"; }
	return renderDir(popBMMap($did,true),"bookmarks",kodiCurItem(),true);
}

function popBMMap($did,$array = false) {
	global $bmmap;
	$query = "SELECT * FROM bookmarks WHERE did=:did";
	include('db.php');
	$stmt = $dbconn->prepare($query);                
	if (!$stmt->execute(['did' => $did])) {
		var_dump("$did Data error 42");
		return "Data error 42";
	}

	$bms = $stmt->fetchAll(PDO::FETCH_ASSOC);

	if (!$bms) {
		var_dump("$did has no bookmarks yet");
		return "You have no bookmarks saved yet!";
	}

	$results = "__**Bookmarks**__\n";

	$ikey = 0;
	$bmmap[$did] = [];
	$dirs = [];
	file_put_contents('bmsitems.json',json_encode($bms, JSON_PRETTY_PRINT));
	foreach ($bms AS $key => $bm) {
		$ikey++;
		$id = $bm['id'];
		$type = $bm['type'];
		if (!$type) { $type = "file"; }
		$n = $bm['name'];
		$f = $bm['file'];
		$dirTemplate = [];

		$bmmap[$did][$ikey] = [$id,$f];

		$time = $ttime = '';
		$position = $total = false;
		if ($bm['time']) {
			$t = json_decode($bm['time'],true);
			unset($t['milliseconds']);
			$time = implode(':',array_map('padInt',$t));
			$position = timeArrayToSecs($t);
		}
		if ($bm['totaltime']) {
			$tt = json_decode($bm['totaltime'],true);
			unset($tt['milliseconds']);
			$ttime = implode(':',array_map('padInt',$tt));
			$total = timeArrayToSecs($tt);
		}
		if ($time && $ttime) {
			$time = "$time / $ttime";
		} else if ($ttime) {
			$time = "00:00:00 / $ttime";
		}
		$bmmap[$did][$ikey] = [$id,$f];
		$results .= "[$ikey]: $n $time \n";


	$dirTemplate = [
		"title"=> "",
		"thumbnail"=> "",
		"file"=> $f,
		"filetype"=> $type,
		"label"=> $n
	];

		if ($total) {
			$dirTemplate['runtime'] = $total;
			$dirTemplate['resume']['total'] = $position;
		}
		if ($position) {
			$dirTemplate['resume']['position'] = $position;
		}

		$dirs[] = $dirTemplate;
	}
	file_put_contents('bmsdirs.json',json_encode($dirs, JSON_PRETTY_PRINT));
	var_dump($bmmap[$did]);
	if ($array) { return $dirs; }
	return $results;	
}

function renderFAVS($did = false) {
	if (!$did) { return "ERR:F84-DID"; }
	return renderDir(popFMap($did,true),"favs",kodiCurItem(),true);
}

function popFMap($did,$array = false) {
	global $fmap;
	$query = "SELECT * FROM favs WHERE did=:did";
	include('db.php');
	$stmt = $dbconn->prepare($query);                
	if (!$stmt->execute(['did' => $did])) {
		var_dump("$did Data error f42");
		return "Data error f42";
	}

	$fvs = $stmt->fetchAll(PDO::FETCH_ASSOC);

	if (!$fvs) {
		var_dump("$did has no favorites yet");
		return "You have no favourites saved yet!";
	}
	$results = "__**Favourites**__\n";

	$ikey = 0;
	$bmmap[$did] = [];
	$dirs = [];
	file_put_contents('fmsitems.json',json_encode($fms, JSON_PRETTY_PRINT));
	foreach ($fms AS $key => $fm) {
		$ikey++;
		$id = $fm['id'];
		$type = $fm['type'];
		if (!$type) { $type = "file"; }
		$n = $fm['name'];
		$f = $fm['file'];
		$dirTemplate = [];
		$fmap[$did][$ikey] = [$id,$f];
		$time = $ttime = '';
		$position = $total = false;
		if ($fm['time']) {
			$t = json_decode($fm['time'],true);
			unset($t['milliseconds']);
			$time = implode(':',array_map('padInt',$t));
			$position = timeArrayToSecs($t);
		}
		if ($fm['totaltime']) {
			$tt = json_decode($bm['totaltime'],true);
			unset($tt['milliseconds']);
			$ttime = implode(':',array_map('padInt',$tt));
			$total = timeArrayToSecs($tt);
		}
		if ($time && $ttime) {
			$time = "$time / $ttime";
		} else if ($ttime) {
			$time = "00:00:00 / $ttime";
		}
		$bmmap[$did][$ikey] = [$id,$f];
		$results .= "[$ikey]: $n $time \n";

		$dirTemplate = [
			"title"=> "",
			"thumbnail"=> "",
			"file"=> $f,
			"filetype"=> $type,
			"label"=> $n
		];

		if ($total) {
			$dirTemplate['runtime'] = $total;
			$dirTemplate['resume']['total'] = $position;
		}
		if ($position) {
			$dirTemplate['resume']['position'] = $position;
		}
		$dirs[] = $dirTemplate;
	}
	file_put_contents('fsdirs.json',json_encode($dirs, JSON_PRETTY_PRINT));
	var_dump($fmap[$did]);
	if ($array) { return $dirs; }
	return $results;	
}

function kodiCurItem($gettitle = false) {
	global $_Kodi;
	$json = '{"jsonrpc":"2.0","method":"Player.GetItem","params":[1,["title","thumbnail","mediapath","file","artist","genre","year","rating","album","track","duration","playcount","dateadded","episode","artistid","albumid","tvshowid","fanart"]],"id":10}';
	$ftitle = $curitem = null;
	$res = $_Kodi->sendJson($json);
	var_dump($json,$res);
	if ($res['result']) {
		if ($gettitle) {
			$item = $res['result']['item'];
			$filename = $item['file'];
			$file = false;
			if (isset($item['mediapath'])) {
				$file = $item['mediapath'];
			}
			if (!$file) { $file = $filename; }
			$artist = [];
			if (isset($item['artist'])) {
				$artist = $item['artist'];
			}
			$title = $item['title'];
			$name = $item['label'];
			if (!$name) { $name = $title; }
			if (!$name) { $name = $filename; }
			$ftitle = kodiTitle($name,$artist,$file);
		}
		if ($res['result']['item']['mediapath']) {
			$curitem = $res['result']['item']['mediapath'];
		} else {
			$curitem = $res['result']['item']['file'];
		}
	}
	if ($gettitle) {
		$curitem = [$curitem,$ftitle];
	}
	return $curitem;
}

$lastStatusData = [];
$lastStatusPlayer = ["Stopped","","00:00:00","00:00:00",0,"",""];
function playerStatus($status = false,$data = false) {
	global $lastStatusData;
	global $lastStatusPlayer;
	var_dump('1 $lastStatusPlayer',$lastStatusPlayer,$data);
	var_dump('1 $lastStatusData',$lastStatusData,$data);
	if (!$data){
		$data = $lastStatusData;
	} else {
		$lastStatusData =	$data;		
	}
	if (!$status) {
		$status = kodi('seek',null);
	} else if (is_array($status)) {
		list($state,$play,$pcnt,$curtime,$endtime,$message) = $status;
		// $state = $status[0];
		// $play = $status[1];
		// $pcnt = $status[2];
		// $curtime = $status[3];
		// $endtime = $status[4];
		// $message = $status[5];
		if ($message && !preg_match("/^(\s?)\n/",$message,$m)) { $message = "\n".$message; }
		$lastStatusPlayer = $status;
		// $status = "$play \n $pcnt% $curtime / $endtime $message";
		//$lastStatusPlayer = [$state,$play,$pcnt,$curtime,$endtime];
		$status = "[$state] $play \n $curtime / $endtime $pcnt% $message";
		$lastStatusPlayer[6] = $status;
	} else if (is_string($status) && $status == 'useArray') {
		list($state,$play,$curtime,$endtime,$pcnt,$message) = $lastStatusPlayer;
		if ($state !== "Stopped" && !$play) { $play = kodiCurItem(true)[1]; }
		var_dump($state,$play);
		if ($message && !preg_match("/^(\s?)\n/",$message,$m)) { $message = "\n".$message; }
		$status = "[$state] $play \n $curtime / $endtime $pcnt% $message";
	} else if (!is_string($status)) {
		return "Status type error";
	}
	//function outputWorkspace($data,$output,$name = "") {
	var_dump('2 $lastStatusPlayer',$lastStatusPlayer);
	outputWorkspace($data,$status,'player');
	return $lastStatusPlayer;
}

$resumeData = false;
function kodi($action = "playPause",$arg = null,$data = false) {
	global $_Kodi;
	global $kodi;
	global $lastStatusData;
	global $curpath;
	$array = $return = false;
	$playlistMode = false;
	$queuecmd = false;
	if (is_string($data) && $data == 'return') {
		$data = false;
		$return = true;
	} else if (is_string($data) && $data == 'returnarray') {
		$array = true;
		$data = false;
		$return = true;
	}
	
	if (is_array($data) && isset($data['channel_id']) && (!is_array($lastStatusData) || !isset($lastStatusData['channel_id'])) ) {
		$lastStatusData = $data;
	}
	
	if ($data !== false) {
		file_put_contents('kodidata.json',json_encode($data, JSON_PRETTY_PRINT));		
	}

	$playlistMode = false;
	if (is_string($action) && in_array($action,['next','prev','previous'])) {
		$json = '{"jsonrpc":"2.0","method":"Playlist.GetItems","id":"1742821847813","params":{"playlistid":1,"properties":["title","thumbnail","file","artist","genre","year","rating","album","track","duration","playcount","dateadded","episode","artistid","albumid","tvshowid"],"limits":{"start":0}}}';
		$items = $_Kodi->sendJson($json);
		var_dump('78243784784638956356934',$json,$items);
		if (isset($_Kodi->error)) { 
			return print_r($_Kodi->error,true);
		}
		
		if (!isset($items['result']) || !isset($items['result']['items'])) {
			var_dump($items,'queue render ERROR');
			return "Query error 48".intval(!isset($items['result'])).intval(!isset($items['result']['items']));
		}
		if ($playlistMode = (count($items['result']['items']) > 1)) {
			$json = '{"jsonrpc":"2.0","method":"Player.GetProperties","params":[1,["playlistid","position"]],"id":11}'; //,{"jsonrpc":"2.0","method":"Player.GetItem","params":[1,["title","thumbnail","file","artist","genre","year","rating","album","track","duration","playcount","dateadded","episode","artistid","albumid","tvshowid","fanart"]],"id":12}';
			$qindex = $_Kodi->sendJson($json)['result']['position'];
			var_dump($qindex,"FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF");

		}
		
	}

	var_dump("KODI",$action,$arg);

	if ($kodi['paths'] == null) {
		// $json = '{"jsonrpc":"2.0","method":"Files.GetSources","params":["video"],"id":8},{"jsonrpc":"2.0","method":"Files.GetSources","params":["music"],"id":9}';
		//$json = '{"jsonrpc":"2.0","method":"Files.GetSources","params":["video"],"id":8},{"jsonrpc":"2.0","method":"Files.GetSources","params":["music"],"id":9}';
		$json = '{"jsonrpc":"2.0","method":"Files.GetSources","params":["video"],"id":1}'; //,{"jsonrpc":"2.0","method":"Files.GetSources","params":["music"],"id":2},{"jsonrpc":"2.0","method":"Addons.GetAddons","params":["xbmc.addon.audio","unknown",true,["path","name"]],"id":3},{"jsonrpc":"2.0","method":"Addons.GetAddons","params":["xbmc.addon.video","unknown",true,["path","name"]],"id":4}]';
		$srcs = $_Kodi->sendJson($json);
		var_dump($srcs);
		
		if (!isset($srcs['result'])) {
			return "data error 1";
		}
		// $srcs = $_Kodi->sendJson(addslashes($json));
		$kodi['sources'] = $srcs['result']['sources'];
		$kodi['paths'] = [$srcs['result']['sources'][0]['file'],$srcs['result']['sources'][1]['file']];
		// var_dump($srcs,$kodi['paths']);
		// $kodi['paths'] = [];
			// $path = $kodi['paths'][0];

	}

	$playcmd = false;
	$output = '';
	$addq = '';

	switch ($action) {
		case "audiostream":
			$output = json_encode(fixKodiAudio());
			$data = false;
			$return = true;
		break;


		case "resume":
			if (!$arg[2]) {
				return "You must be in the tv room channel to do this!";
			}
			// return "Not yet";

			$key = $arg[0];
			$did = $arg[1];
			global $bmmap;
			if (!isset($bmmap[$did])) {
				popBMMap($did);
			}
			
			$id = $bmmap[$did][$key][0];

			$query = "SELECT name,file,time,type FROM bookmarks WHERE did=:did AND id=:id";

			include('db.php');
			$stmt = $dbconn->prepare($query);                
			if (!$stmt->execute(['id' => $id,'did' => $did])) {
				return "Data error 42";
			}

			$bms = $stmt->fetch(PDO::FETCH_ASSOC);

			if (!$bms) {
				return "Bookmark for $key not found!";
			}
			$f = $bms['file'];
			$type = $bms['type'];
			if ($type == 'directory') {
				$path = $f;
				// $kodi['hist'][] = $path;
				// $output = "\n$path\n";
				$output = "\n";
				// $dirs = $_Kodi->getDirectory(addcslashes($path,'\'), 1);
				// $dirs = getDir($path)['result']['files'];
				$json = '{"jsonrpc":"2.0","id":"1","method":"Files.GetDirectory","params":{"directory":"'.addcslashes($path,'\\').'","media":"video","properties":["title","file","playcount","runtime","resume","lastplayed","mimetype","thumbnail","dateadded"],"sort":{"method":"none","order":"ascending"}}}';
				$dirs = $_Kodi->sendJson($json);
				file_put_contents('bmrdirs.json',json_encode($dirs, JSON_PRETTY_PRINT));
				var_dump('$path $dirs',$path,$dirs,$json);

				// $curitem = null;
				// $json = '{"jsonrpc":"2.0","method":"Player.GetItem","params":[1,["title","thumbnail","mediapath","file","artist","genre","year","rating","album","track","runtime","duration","playcount","dateadded","episode","artistid","albumid","tvshowid","fanart"]],"id":10}';
				// $res = $_Kodi->sendJson($json);
				// if ($res['result'] && $res['result']['item']['file']) {
					// $curitem = $res['result']['item']['file'];
				// }

				$output = renderDir($dirs,$path,kodiCurItem(),($data));


			} else {

				$_Kodi->stop();
				usleep(1000000);
				//usleep(2000000);

				$play = $_Kodi->openFile(addcslashes($f,'\\'));
				var_dump($play);
				//usleep(1000000);
				
				$t = false;
				if ($bms['time']) {
					$t = json_decode($bms['time'],true);
					// if ($t['minutes'] > 3) { $t['minutes'] = $t['minutes']-3; }

					// $json = ["id"=>0,"jsonrpc"=>"2.0","method"=>"Player.Seek","params"=>[1]];
					// $json["params"][1] = ["time" => $t];
					// var_dump($json);
					// $seek = $_Kodi->sendJson(json_encode($json));
					// var_dump($seek);
					// usleep(1000000);
				}
				global $gseek;
				$gseek = $t;
			var_dump($bms,'00000000000000',$gseek);
				$n = $bms['name'];
				setVoiceStatus("Playing $n");



				return false;
	}
		break;
		case "bookmark":
			$did = $arg[1];
			global $bmmap;
			if (!isset($bmmap[$did])) {
				popBMMap($did);
			}
			if (count($bmmap[$did]) > 9) {
				return "Maximum number of bookmarks saved. Please remove one before adding another";
			}
			
			$key = $arg[0];
			if (!$key) {
				if (!$arg[2]) {	return "You must be in the tv room channel to do this!"; }
				$type = 'file';
				$json = '{"jsonrpc":"2.0","method":"Player.GetItem","params":[1,["title","thumbnail","mediapath","resume","file","artist","genre","year","rating","album","track","duration","playcount","dateadded","episode","artistid","albumid","tvshowid","fanart"]],"id":10}';
				$res = $_Kodi->sendJson($json);
				file_put_contents('bookmark5435.json',json_encode($res, JSON_PRETTY_PRINT));
				if ($res['result'] && isset($res['result']['item']['file'])) {
					$filename = $res['result']['item']['file'];
					$file = $res['result']['item']['mediapath'];
					$artist = $res['result']['item']['artist'];
					$title = $res['result']['item']['title'];
					$name = $res['result']['item']['label'];
					if (!$name) { $name = $title; }
					if (!$name) { $name = $filename; }
					if (count($artist)) {
						$artist = $artist[0];
						if ($artist) {
							$name = $artist.$name;
						}
					}
				} else {
					return "Data error 420";
				}
				if (!isset($res['result']['item']['file'])) {
					return "Nothing is currently playing";
				}
				
				$json = '{"jsonrpc":"2.0","method":"Player.GetProperties","params":[1,["playlistid","speed","position","totaltime","time","percentage","shuffled","repeat","canrepeat","canshuffle","canseek","partymode"]],"id":11}'; //,{"jsonrpc":"2.0","method":"Player.GetItem","params":[1,["title","thumbnail","file","artist","genre","year","rating","album","track","duration","playcount","dateadded","episode","artistid","albumid","tvshowid","fanart"]],"id":12}';
				$props = $_Kodi->sendJson($json)['result'];
				if (!isset($props['time'])) {
					return "Data error 960";
				}
				$time = json_encode($props['time']);
				$totaltime = json_encode($props['totaltime']);
			} else {
				if (!isset($kodi['menu'][$key])) {
					return "7890f80: Invalid selection: $arg";			
				}
				$sel = $kodi['menu'][$key];
				$name = $sel[3];
				$file = $sel[1];
				$type = $sel[0];
				$totaltime = $sel[5];
				if (count(array_filter($bmmap[$did], fn($val) => $val[1] == $file ))) {
					return "$name already in your bookmarks!";
				}
				$time = false;
			}
				
			include('db.php');

			$query = "INSERT INTO `bookmarks` (`did`,`name`,`file`,`time`,`totaltime`,`type`) VALUES (:did,:name,:file,:time,:totaltime,:type) RETURNING id;";

			$qd = [
			'did' => $did,
			'name' => $name,
			'file' => $file,
			'time' => $time,
			'totaltime' => $totaltime,
			'type' => $type
			];
			
			$stmt = $dbconn->prepare($query);                
			if ($stmt->execute($qd)) {
				$id = $stmt->fetch(PDO::FETCH_ASSOC)['id'];
				var_dump("-----------ID-----------",$id);
				$key = intval(array_key_last($bmmap[$did]));
				while (isset($bmmap[$did][$key]) && $key < 200) {	$key++;	}
				$bmmap[$did][$key] = [$id,$file];
				var_dump($bmmap[$did]);
				return "Bookmark added!";
			} else {
				return "Data error 4096";
			}
		break;
		case "unbookmark":

			//$id = array_search(56,$blah);
			$key = $arg[0];
			$did = $arg[1];
			global $bmmap;
			if (!isset($bmmap[$did])) {
				popBMMap($did);
			}

			if (!isset($bmmap[$did][$key])) {
				return "Bookmark for $key not found";
			}

			$id = $bmmap[$did][$key][0];

			include('db.php');
			$stmt = $dbconn->prepare("DELETE FROM bookmarks WHERE id=:id AND `did`=:did");
			if ($stmt->execute(['did' => $did,'id'=>$id])) {
				$arr = array_filter($bmmap[$did], fn($val) => $val[0] !== $id);
				array_unshift($arr,"");
				$bmmap[$did] = array_values($arr);
				unset($bmmap[$did][0]);
				return "Bookmark removed!";
			} else {
				return "Data error 8192";
			}
		break;
		case "favs":
			$fav=true;
		case "bookmarks":
			list($table,$fname) = ($fav)?['favs','favorites']:['bookmarks','bookmarks'];
			$query = "SELECT * FROM $table WHERE did=:did";
			$did = $arg[1];

			include('db.php');
			$stmt = $dbconn->prepare($query);                
			if (!$stmt->execute(['did' => $did])) {
				return "Data error 42 $table";
			}

			$bms = $stmt->fetchAll(PDO::FETCH_ASSOC);

			if (!$bms) {
				return "You have no $fname yet";
			}
			var_dump($bms,'00000000000000');

			$dirs = popBMMap($did,true);
			$output = renderDir($dirs,$table,kodiCurItem(),($data || $array));
			// var_dump($results,$did);
			var_dump($dirs,$output,$did);
			// return $results;
		break;
		case "btn":
			// $json = '[{"jsonrpc":"2.0","method":"Playlist.Remove","params":[1,'.intval($arg).'],"id":112}]';
			$btnaction = '';
			if (is_string($arg) && in_array($arg,['play','pause'])) {
				$btnaction = $arg;
				global $resumeData;
				if ($btnaction == "play" && $resumeData && getVidTimes()[0] == "Playing") {
					global $lastStatusPlayer;
					kodi('seek',['abs',$resumeData]);
					$lastStatusPlayer[5] = "";
					$resumeData = false;
					playerStatus('useArray');
					return;
				}
				$json = '[{"jsonrpc":"2.0","method":"Input.ExecuteAction","params":["'.$btnaction.'"],"id":31}]';
				$output = $_Kodi->sendJson($json);
				var_dump($output);
			}
			// $arg = null;
		break;
		case "osd":
			$json = '[{"jsonrpc":"2.0","method":"Input.ExecuteAction","params":["osd"],"id":31}]';
			$output = $_Kodi->sendJson($json);
			var_dump($output);
		break;
		case "unqueue":
			if ($arg == 'all') {
				$arg = 'clear';
			} else {
				$json = '[{"jsonrpc":"2.0","method":"Playlist.Remove","params":[1,'.intval($arg).'],"id":112}]';
				$unq = $_Kodi->sendJson($json);
				var_dump($unq);
				$arg = null;
			// break;
			}
		case "queuefrom":
			if (is_numeric($arg)) {
				$json = '{"jsonrpc":"2.0","method":"Playlist.GetItems","id":"1742821847813","params":{"playlistid":1,"properties":["title","thumbnail","file","artist","genre","year","rating","album","track","duration","playcount","dateadded","episode","artistid","albumid","tvshowid"],"limits":{"start":0}}}';
				$inum = count($output = $_Kodi->sendJson($json)['result']['items']);
				$start = intval($arg);
				foreach ($kodi['menu'] AS $arg => $selection) {
					if (intval($arg) < $start) { continue; }
					
					$selection = $kodi['menu'][$arg];
					if ($selection[0] == 'file') {
						$file = $selection[1];
					} else {
						var_dump("selection is not a file",$arg,$selection);
						continue;
					}
					var_dump('FOOOOOOOOOOOO FROMM          00000000000000000',$inum,$json,$output);


					$json = '[{"jsonrpc":"2.0","method":"Playlist.Insert","params":[1,'.intval($inum).',{"file":"'.addcslashes($file,'\\').'"}],"id":2209}]';
					$inum++;
					var_dump('FOOOOOOOOOOOO13333333333333333333333',$inum,$json);
					$addq = $_Kodi->sendJson($json);
					var_dump($addq);
				}
				$arg = null;
			// } else {
				// return "Invalid selection";
			}
		case "queue":
		
			if ($arg !== null) {
				// if (count($res = filterArrayByKeyValue($kodi['menu'],2,false))) {
					// $arg = array_rand($res);
				// } else {
					// $arg = rand(0,count($kodi['menu'])-1);
				// }
				
				// if ($arg == 'remove') {
				if ($arg == 'clear') {
						$json = '{"jsonrpc":"2.0","method":"Playlist.Clear","params":[1],"id":16}';
						$clearq = $_Kodi->sendJson($json);
						var_dump($clearq);
						if ($data === null) {
							return;
						}

						if (!count($kodi['hist'])) {
							$path = $kodi['paths'][0];
						} else {
							$path = array_pop($kodi['hist']);
						}
						$json = '{"jsonrpc":"2.0","id":"1","method":"Files.GetDirectory","params":{"directory":"'.addcslashes($path,'\\').'","media":"video","properties":["title","file","playcount","lastplayed","mimetype","thumbnail","dateadded"],"sort":{"method":"none","order":"ascending"}}}';
						$dirs = $_Kodi->sendJson($json);
						var_dump($dirs);

						$curitem = null;
						$json = '{"jsonrpc":"2.0","method":"Player.GetItem","params":[1,["title","thumbnail","file","mediapath","artist","genre","year","rating","album","track","duration","playcount","dateadded","episode","artistid","albumid","tvshowid","fanart"]],"id":10}';
						$res = $_Kodi->sendJson($json);
						if ($res['result']) {
							$curitem = $res['result']['item']['file'];
						}

						$output = renderDir($dirs,$path,$curitem,($data));
						break;
					
				} else if ($arg == 'all') {
					
					$json = '{"jsonrpc":"2.0","method":"Playlist.GetItems","id":"1742821847813","params":{"playlistid":1,"properties":["title","thumbnail","file","artist","genre","year","rating","album","track","duration","playcount","dateadded","episode","artistid","albumid","tvshowid"],"limits":{"start":0}}}';
					$inum = count($output = $_Kodi->sendJson($json)['result']['items']);
					foreach ($kodi['menu'] AS $arg => $selection) {
						
						
						$selection = $kodi['menu'][$arg];
						if ($selection[0] == 'file') {
							$file = $selection[1];
						} else {
							var_dump("selection is not a file",$arg,$selection);
							continue;
						}
						var_dump('FOOOOOOOOOOOO ALLL00000000000000000',$inum,$json,$output);


						$json = '[{"jsonrpc":"2.0","method":"Playlist.Insert","params":[1,'.intval($inum).',{"file":"'.addcslashes($file,'\\').'"}],"id":2209}]';
						$inum++;
						var_dump('FOOOOOOOOOOOO111111111111111111111',$inum,$json);
						$addq = $_Kodi->sendJson($json);
						var_dump($addq);
					}
					
					
				} else {
					if (!isset($kodi['menu'][$arg])) {
						return "9fa78: Invalid selection: $arg";			
					}
					$selection = $kodi['menu'][$arg];
					if ($selection[0] == 'file') {
						$file = $selection[1];
					} else {
						return "selection is not a file";
					}
					$json = '{"jsonrpc":"2.0","method":"Playlist.GetItems","id":"1742821847813","params":{"playlistid":1,"properties":["title","thumbnail","file","artist","genre","year","rating","album","track","duration","playcount","dateadded","episode","artistid","albumid","tvshowid"],"limits":{"start":0}}}';
					$inum = count($output = $_Kodi->sendJson($json)['result']['items']);
					var_dump('FOOOOOOOOOOOO',$inum,$json,$output);


					$json = '[{"jsonrpc":"2.0","method":"Playlist.Insert","params":[1,'.intval($inum).',{"file":"'.addcslashes($file,'\\').'"}],"id":2209}]';
					var_dump('FOOOOOOOOOOOO111111111111111111111',$inum,$json);
					$addq = $_Kodi->sendJson($json);
					var_dump($addq);
				}
			}
		case "getplaylist":
			$curitem = kodiCurItem();
			
			$json = '{"jsonrpc":"2.0","method":"Playlist.GetItems","id":"1742821847813","params":{"playlistid":1,"properties":["title","showtitle","thumbnail","mediapath","file","resume","artist","genre","year","rating","album","track","runtime","duration","playcount","dateadded","episode","artistid","albumid","tvshowid"]}}';
			$output = $_Kodi->sendJson($json);
			$output = renderQueue($output,$curitem);
			var_dump('3333333333333333333333333333333',$output);
		break;
		case 'playPause':
			$playpause = '[
				{
					"id": 2240,
					"jsonrpc": "2.0",
					"method": "Player.PlayPause",
					"params": [
						1,
						"toggle"
					]
				}
			]';

			$output = $_Kodi->sendJson($playpause);
			$output = "Toggle Play/Pause";
		break;
		case "movies":
			$path = $kodi['paths'][1];
			$json = '{"jsonrpc":"2.0","id":"1","method":"Files.GetDirectory","params":{"directory":"'.$path.'","media":"video","properties":["title","file","playcount","lastplayed","mimetype","thumbnail","dateadded"],"sort":{"method":"none","order":"ascending"}}}';
			$dirs = $_Kodi->sendJson($json);
			var_dump($dirs);
			$output = renderDir($dirs,$path,kodiCurItem(),($data));
		break;
		case "seek":
			$playfile = "unknown";
			list($curitem,$playfile) = kodiCurItem(true);

			$json = '{"jsonrpc":"2.0","method":"Player.GetProperties","params":[1,["playlistid","speed","position","totaltime","time","percentage","shuffled","repeat","canrepeat","canshuffle","canseek","partymode"]],"id":11}'; //,{"jsonrpc":"2.0","method":"Player.GetItem","params":[1,["title","thumbnail","file","artist","genre","year","rating","album","track","duration","playcount","dateadded","episode","artistid","albumid","tvshowid","fanart"]],"id":12}';
			$props = $_Kodi->sendJson($json)['result'];
			file_put_contents('seekprops.json',json_encode($props));
			global $lastStatusPlayer;
			var_dump('TIME000000000000000000000000',$lastStatusPlayer,$props);
			if ($props == NULL) { return "props null"; }
			
			if ($arg == NULL || $arg[0] == 'show') {
				
				$time = $props['time'];
				$time = array_map('padInt',$time);
				$curtime = implode(':',[$time['hours'],$time['minutes'],$time['seconds']]);
				$time = $props['totaltime'];
				$time = array_map('padInt',$time);
				$endtime = implode(':',[$time['hours'],$time['minutes'],$time['seconds']]);

				$pcnt = round($props['percentage'],2);
				
				if (is_array($arg) && $arg[0] == 'show') {
					$json = '[{"jsonrpc":"2.0","method":"Input.ExecuteAction","params":["osd"],"id":31}]';
					$osd = $_Kodi->sendJson($json);
				}
				
				$rplayfile=$playfile;

				$spd = $props['speed'];
				$pos = $props['position'];
				$pcntr = round($props['percentage']);
				$pcnt = round($props['percentage'],2);

				$state = ($spd)?(($pos == -1 || !$pcntr )?"Stopped":"Playing"):"Paused";

				if (!$playfile) { $rplayfile = ''; }
				$play = $rplayfile;

				$lastStatusPlayer[0] = $state;
				$lastStatusPlayer[1] = $play;
				$lastStatusPlayer[2] = $pcnt;
				$lastStatusPlayer[3] = $curtime;
				$lastStatusPlayer[4] = $endtime;
				
				return "[$state] $play \n".$curtime." / ".$endtime. " $pcnt%";
			} else {
				if ($arg[0] == 'pcnt') {
					$percent = $arg[1];
					$json = '{"jsonrpc":"2.0","method":"Player.Seek","params":[1,{"percentage":'.$percent.'}],"id":8}';
					// $dirs = $_Kodi->sendJson(json_encode($json));
					$dirs = json_encode($_Kodi->sendJson($json));
					var_dump($dirs);
					// return $dirs;	
					// return false;	
				} else {
					if ($arg[0] == 'time') {
						$time = $props['time'];
						$curtime = implode(':',[$time['hours'],$time['minutes'],$time['seconds']]);
						var_dump('ARG1',$arg[1][0]);
						
						$newtime = explode(':',date('H:i:s',strtotime("$curtime ".$arg[1][0])));
						
						
						$json = ["id"=>0,"jsonrpc"=>"2.0","method"=>"Player.Seek","params"=>[1]];
						$json["params"][1] = ["time" => ['hours'=>intval($newtime[0]),'minutes'=>intval($newtime[1]),'seconds' => intval($newtime[2]),'milliseconds'=>00]];
						var_dump($json);
						$dirs = $_Kodi->sendJson(json_encode($json));
						var_dump($dirs);
					} else if ($arg[0] == 'abs') {
						$json = ["id"=>0,"jsonrpc"=>"2.0","method"=>"Player.Seek","params"=>[1]];
						if (is_numeric($arg[1])) {
							$newtime = secsToTimeArray($arg[1]);
							$json["params"][1] = ["time" => ['hours'=>intval($newtime[0]),'minutes'=>intval($newtime[1]),'seconds' => intval($newtime[2]),'milliseconds'=>00]];
						} else if(is_array($arg[1])) {
							$json["params"][1] = ["time" => $arg[1]];
						}
						// $json["params"][1] = ["time" => ['hours'=>intval($newtime[0]),'minutes'=>intval($newtime[1]),'seconds' => intval($newtime[2]),'milliseconds'=>00]];
						var_dump($json);
						$dirs = $_Kodi->sendJson(json_encode($json));
						var_dump($dirs);
					}
				}
				setVoiceStatus("Playing $playfile");
			}			
		break;
		case "sources":
			$json = '{"jsonrpc":"2.0","method":"Files.GetSources","params":["video"],"id":1}'; //,{"jsonrpc":"2.0","method":"Files.GetSources","params":["music"],"id":2},{"jsonrpc":"2.0","method":"Addons.GetAddons","params":["xbmc.addon.audio","unknown",true,["path","name"]],"id":3},{"jsonrpc":"2.0","method":"Addons.GetAddons","params":["xbmc.addon.video","unknown",true,["path","name"]],"id":4}]';

			$dirs = $_Kodi->sendJson($json);
			$dirs['result']['files'] = $dirs['result']['sources'];
			var_dump($dirs);
			$output = renderDir($dirs,'sources',kodiCurItem(),($data));
		break;
		case "shows":
			// $path = "multipath://D%3a%5ctv%5c/E%3a%5ctv%5c/F%3a%5ctv%5c/G%3a%5ctv%5c/smb%3a%2f%2f192.168.12.3%2fshayne%2ftv%2f/C%3a%5ctv%5c/";
			$path = $kodi['paths'][0];
			// $dirs = getDir($path)['result']['files'];
			$json = '{"jsonrpc":"2.0","id":"1","method":"Files.GetDirectory","params":{"directory":"'.$path.'","media":"video","properties":["title","artist","file","playcount","lastplayed","mimetype","thumbnail","dateadded"],"sort":{"method":"none","order":"ascending"}}}';
			$dirs = $_Kodi->sendJson($json);
			var_dump($dirs);
			$output = renderDir($dirs,$path,kodiCurItem(),($data));
		break;
		case "previous":
		case "prev":
			if ($playlistMode) {
				// $json = '{ "jsonrpc": "2.0", "method": "Input.ExecuteAction", "params": { "action": "skipnext" }, "id": 1 }'
				// $json = '{ "jsonrpc": "2.0", "method": "Input.ExecuteAction", "params": { "action": "skipprevious" }, "id": 1 }';
				if ($qindex == 1) { return; }
				$qindex--;
				$json = '{"jsonrpc":"2.0","method":"Player.GoTo","params":[1,'.$qindex.'], "id":2}';
				$output = $_Kodi->sendJson($json);

				var_dump($json,$output,'66666666666666666666666666');
//				$json = '{ "jsonrpc": "2.0", "method": "Input.ExecuteAction", "params": { "action": "skipprevious" }, "id": 1 }';
				// usleep(500000);
				// $output = $_Kodi->sendJson($json);
				$kodi['onPlay'] = 'getplaylist';
				$kodi['data'] = $data;

				// $curitem = kodiCurItem();
				// $json = '{"jsonrpc":"2.0","method":"Playlist.GetItems","id":"1742821847813","params":{"playlistid":1,"properties":["title","showtitle","thumbnail","mediapath","file","resume","artist","genre","year","rating","album","track","runtime","duration","playcount","dateadded","episode","artistid","albumid","tvshowid"]}}';
				// $output = $_Kodi->sendJson($json);
				// var_dump($output);
				// $output = renderQueue($output,$curitem);
				// var_dump('3333333333333333333333333333333',$output);

				break;
			}

			if (!isset($kodi['playing']) || $kodi['playing'] == null) {
				$output = "sd89f: Invalid selection";
			} else {
				//$arg = intval($kodi['playing'])+1;
				$arg = intval($kodi['playing'])-1;
				if (!isset($kodi['menu'][$arg])) {
					$output = "Invalid menu selection";
				} else {


					// $arg = intval($kodi['playing'])-1;
					$kodi['playing'] = intval($arg);
					$selection = $kodi['menu'][$arg];
					$kodi['playfile'] = $selection[1];
					$kodi['playfilename'] = $selection[3];
					// // $output = $_Kodi->openFile(addslashes($selection[1]))['result'];
					// $output = $_Kodi->openFile(addcslashes($selection[1],'\\'))['result'];
					kodi('play',$arg);
					// setVoiceStatus("Playing ".$selection[3]);
					// var_dump(fixKodiAudio());
					// if ($data) { $data = null; }
			// $path = "multipath://D%3a%5ctv%5c/E%3a%5ctv%5c/F%3a%5ctv%5c/G%3a%5ctv%5c/smb%3a%2f%2f192.168.12.3%2fshayne%2ftv%2f/C%3a%5ctv%5c/";
				}
			}
		break;
		case "next":
		
			if ($playlistMode) {
				$json = '{ "jsonrpc": "2.0", "method": "Input.ExecuteAction", "params": { "action": "skipnext" }, "id": 1 }';
				// $json = '{ "jsonrpc": "2.0", "method": "Input.ExecuteAction", "params": { "action": "skipprevious" }, "id": 1 }'
				$output = $_Kodi->sendJson($json);
				$kodi['onPlay'] = 'getplaylist';
				$kodi['data'] = $data;

				// $curitem = kodiCurItem();
				// $json = '{"jsonrpc":"2.0","method":"Playlist.GetItems","id":"1742821847813","params":{"playlistid":1,"properties":["title","showtitle","thumbnail","mediapath","file","resume","artist","genre","year","rating","album","track","runtime","duration","playcount","dateadded","episode","artistid","albumid","tvshowid"]}}';
				// $output = $_Kodi->sendJson($json);
				// var_dump($output);
				// $output = renderQueue($output,$curitem);
				// var_dump('3333333333333333333333333333333',$output);
				break;
			}
		
			// if (!isset($kodi['playing'])) {
			if (!isset($kodi['playing']) || $kodi['playing'] == null) {
				$output = "p98sdg: Invalid selection";
			} else {
				$arg = intval($kodi['playing'])+1;
				if (!isset($kodi['menu'][$arg])) {
					$output = "24232: Invalid menu selection";
				} else {
					// $arg = intval($kodi['playing'])+1;
					$kodi['playing'] = intval($arg);
					$selection = $kodi['menu'][$arg];
					$kodi['playfile'] = $selection[1];
					$kodi['playfilename'] = $selection[3];
					kodi('play',$arg);
					// $output = $_Kodi->openFile(addslashes($selection[1]))['result'];
					// $output = $_Kodi->openFile(addcslashes($selection[1],'\\'))['result'];
					// setVoiceStatus("Playing ".$selection[3]);
					// var_dump(fixKodiAudio());
					// if ($data) { $data = null; }
					
			// $path = "multipath://D%3a%5ctv%5c/E%3a%5ctv%5c/F%3a%5ctv%5c/G%3a%5ctv%5c/smb%3a%2f%2f192.168.12.3%2fshayne%2ftv%2f/C%3a%5ctv%5c/";
				}
			}
		break;
		case "showlist":
			$listpath = array_filter($kodi['hist'] , fn($o) => !in_array(trim($o),['queue','bookmarks']));
		case "refresh":
			if (!isset($listpath)) {
				$listpath = $kodi['hist'];
				if (isset($kodi['path'])) {
					$path = $kodi['path'];
				} else if (count($kodi['hist'])) {
					$path = array_pop($kodi['hist']);
				} else {
					$path = $kodi['paths'][0];
				}
			} else {				
				if (!$path = array_pop($listpath)) {
					$path = $kodi['paths'][0];
				}
			}

			if ($path == 'queue') {
				$json = '{"jsonrpc":"2.0","method":"Playlist.GetItems","id":"1742821847813","params":{"playlistid":1,"properties":["title","showtitle","thumbnail","mediapath","file","resume","artist","genre","year","rating","album","track","runtime","duration","playcount","dateadded","episode","artistid","albumid","tvshowid"]}}';
				$output = $_Kodi->sendJson($json);
				$output = renderQueue($output,kodiCurItem());
				break;
			}

			if ($path == 'sources') {
				// $json = '{"jsonrpc":"2.0","method":"Playlist.GetItems","id":"1742821847813","params":{"playlistid":1,"properties":["title","showtitle","thumbnail","mediapath","file","resume","artist","genre","year","rating","album","track","runtime","duration","playcount","dateadded","episode","artistid","albumid","tvshowid"]}}';
				// $output = $_Kodi->sendJson($json);
				// $output = renderQueue($output,kodiCurItem());
				$json = '{"jsonrpc":"2.0","method":"Files.GetSources","params":["video"],"id":1}'; //,{"jsonrpc":"2.0","method":"Files.GetSources","params":["music"],"id":2},{"jsonrpc":"2.0","method":"Addons.GetAddons","params":["xbmc.addon.audio","unknown",true,["path","name"]],"id":3},{"jsonrpc":"2.0","method":"Addons.GetAddons","params":["xbmc.addon.video","unknown",true,["path","name"]],"id":4}]';
				$dirs = $_Kodi->sendJson($json);
				$dirs['result']['files'] = $dirs['result']['sources'];
				$output = renderDir($dirs,'sources',kodiCurItem(),($data));
				break;
			}

			if ($path == 'bookmarks') {
				$output = renderBMS($did);
				break;
			}

			$json = '{"jsonrpc":"2.0","method":"Files.GetDirectory","id":"1743603938944","params":{"directory":"'.$path.'","media":"video","properties":["title","file","artist","duration","comment","description","runtime","playcount","mimetype","thumbnail","dateadded"]}}';
			$dirs = $_Kodi->sendJson($json);
			$output = renderDir($dirs,$path,kodiCurItem(),($data || $array));
		break;
		case "queuerandom":
			$queuecmd = true;
			$arg = 'random';
		case "continue":
			if (!$queuecmd) { $resumefile = true; }
		case "play":
			$playcmd = true;
			if (($arg == 'random' && $action == 'play') || $queuecmd) {
				if ($queuecmd) {
					if ($kodi['queuerandom'] === false) {
						if (isset($kodi['path'])) {
							$path = $kodi['path'];
						} else if (count($kodi['hist'])) {
							$path = array_pop($kodi['hist']);
						} else {
							$path = $kodi['paths'][0];
						}
						$kodi['queuerandom'] = $path;
					}
				} else {
					if ($kodi['playrandom'] === false) {
						if (isset($kodi['path'])) {
							$path = $kodi['path'];
						} else if (count($kodi['hist'])) {
							$path = array_pop($kodi['hist']);
						} else {
							$path = $kodi['paths'][0];
						}
						$kodi['playrandom'] = $path;
					}
				}
			}
		case "select":
			$lkodi = $kodi;
			file_put_contents('lkodi.json',json_encode($lkodi, JSON_PRETTY_PRINT));
			$curitem = $curpath = null;
			$curitem = kodiCurItem();
			if (!is_array($arg)) {
				if ($arg == 'random') {
					if (isset($kodi['path'])) {
						$path = $kodi['path'];
					} else if (count($kodi['hist'])) {
						$path = array_pop($kodi['hist']);
					} else {
						$path = $kodi['paths'][0];
					}
					if (!is_array($lkodi['dirs'] ) || !is_array($lkodi['menu'] ) || $lkodi['dirspath'] != $path) {
						$json = '{"jsonrpc":"2.0","id":"1","method":"Files.GetDirectory","params":{"directory":"'.addcslashes($path,'\\').'","media":"video","properties":["title","file","resume","playcount","lastplayed","mimetype","thumbnail","dateadded"],"sort":{"method":"none","order":"ascending"}}}';
						$dirs = $_Kodi->sendJson($json);
						$output = renderDir($dirs,$path,$curitem,($data));
						$menu = $kodi['menu']; 
					} else {
						$menu = $lkodi['menu']; 
					}
					var_dump($dirs,$path);
					if (isset($dirs['error'])) { var_dump("ERROR",$json); }

					if (count($res = filterArrayByKeyValue($menu,2,false))) {
						var_dump('RES',$res);
						$arg = array_rand($res);
					} else if (count($menu)) {
						$arg = rand(0,count($menu)-1);
					} else {
						if ($kodi['queuerandom']) {
							$kodi['path'] = $kodi['queuerandom'];
							$kodi['queuerandom'] = false;
							return kodi('queuerandom',null,$data);
						} else if ($kodi['playrandom']) {
							$kodi['path'] = $kodi['playrandom'];
							$kodi['playrandom'] = false;
							return kodi('play','random',$data);
						}
						return "s7df89d: Invalid selection: $arg";			
					}
				}
				if (!isset($kodi['menu'][$arg])) {
					return "asd9f7: Invalid selection: $arg";			
				}
				$selection = $kodi['menu'][$arg];
			} else {
				$selection = $arg;
				$arg = $selection[5];
				unset($selection[5]);
			}
			var_dump($selection);
			if ($selection[0] == 'directory') {
				$path = $selection[1];
				$output = "\n";
				$json = '{"jsonrpc":"2.0","id":"1","method":"Files.GetDirectory","params":{"directory":"'.addcslashes($path,'\\').'","media":"video","properties":["title","file","playcount","runtime","resume","lastplayed","mimetype","thumbnail","dateadded"],"sort":{"method":"none","order":"ascending"}}}';
				$dirs = $_Kodi->sendJson($json);
				$kodi['dirs'] = $dirs;
				$kodi['dirspath'] = $path;
				
				file_put_contents('dirs.json',json_encode($dirs, JSON_PRETTY_PRINT));
				var_dump('$path $dirs',$path,$dirs,$json);

				if ($kerr = kodiError($dirs)) { return $kerr; }

				$output = renderDir($dirs,$path,$curitem,($data));
				$curpath = $path;
				if ($kodi['queuerandom']) {
					$output = kodi('queuerandom',null,$data);
				} else if ($kodi['playrandom']) {
					$lpath = array_reverse(explode('/',rtrim(urldecode($path),'/')))[0];
					$output = kodi('play','random',$data);
				} else if (!isset($dirs['error'])) {
					$l = $dirs['result']['files'];
					$ac = array_column($l,'filetype');
					$r = array_count_values($ac);
					if (isset($r['file']) && $r['file'] == 1 && (!isset($r['directory']) || $r['directory'] < 3)) {
						$k = array_keys($ac,'file')[0];
						//$arg = $k;
						$key = $arg;
						//$output = renderDir($dirs,$path,$curitem);
						$arg = $kodi['menu'][$k];
						$arg[5] = $key;
						kodi('play',$arg);
						$kodi = $lkodi;
						$path = $kodi['path'];

						$kodi['playing'] = intval($arg[5]);
						$selection = $arg;
						$kodi['playfile'] = $selection[1];
						$kodi['playfilename'] = $selection[3];


						// $json = '{"jsonrpc":"2.0","id":"1","method":"Files.GetDirectory","params":{"directory":"'.addcslashes($path,'\\').'","media":"video","properties":["title","file","playcount","runtime","resume","lastplayed","mimetype","thumbnail","dateadded"],"sort":{"method":"none","order":"ascending"}}}';
						// $dirs = $_Kodi->sendJson($json);

						$output = renderDir($kodi['dirs'],$path,$curpath,($data));
					}
				}
			} else {
				if (!$kodi['queuerandom']) {
					if ($curitem) { 
						$_Kodi->stop();
						usleep(1000000);
					}
					$kodi['playing'] = intval($arg);
					$kodi['playfile'] = $selection[1];
					$kodi['playfilename'] = $selection[3];
					$t =  $selection[4];
					if ($t !== 0) {
						global $lastStatusPlayer;
						$lastStatusPlayer[5] = "You can resume where you left off by clicking Play";
						$resumeData = $t;
					}
				}
				if ($kodi['queuerandom']) {
					$kodi['path'] = $kodi['queuerandom'];
					$kodi['queuerandom'] = false;
					$json = '{"jsonrpc":"2.0","method":"Playlist.GetItems","id":"1742821847813","params":{"playlistid":1,"properties":["title","thumbnail","file","artist","genre","year","rating","album","track","duration","playcount","dateadded","episode","artistid","albumid","tvshowid"],"limits":{"start":0}}}';
					$inum = count($output = $_Kodi->sendJson($json)['result']['items']);
					
					var_dump('FOOOOOOOOOOOO',$inum,$json,$output);
					$selectionone = $selection[1];
					if (in_array($selectionone,array_column($output,'file'))) {
						$kodi['path'] = $kodi['queuerandom'];
						$output = kodi('queuerandom',null,$data);
						return;
					}
					$json = '[{"jsonrpc":"2.0","method":"Playlist.Insert","params":[1,'.intval($inum).',{"file":"'.addcslashes($selection[1],'\\').'"}],"id":2209}]';
					$addq = $_Kodi->sendJson($json);
					var_dump($addq);
					$json = '{"jsonrpc":"2.0","method":"Playlist.GetItems","id":"1742821847813","params":{"playlistid":1,"properties":["title","showtitle","thumbnail","mediapath","file","resume","artist","genre","year","rating","album","track","runtime","duration","playcount","dateadded","episode","artistid","albumid","tvshowid"]}}';
					$output = $_Kodi->sendJson($json);
					$output = renderQueue($output,$curitem);
					var_dump('3333333333333333333333333333333',$output);
					break;
				} else if ($kodi['playrandom']) {
					$kodi['path'] = $kodi['playrandom'];
					$kodi['playrandom'] = false;
				}
				$_Kodi->openFile(addcslashes($selection[1],'\\'));

				setVoiceStatus("Playing ".$selection[3]);
				var_dump(fixKodiAudio());
				if ($data) { $data = null; }
			}
		
		break;
		case "back":
			var_dump($kodi['hist']);
			$path = array_shift($kodi['hist']);
			$path = array_shift($kodi['hist']);
			if (!count($kodi['hist'])) {
				$path = $kodi['paths'][0];
			}
			
			$did = false;
			if (isset($data['user_id'])) {
				$did = $data['user_id'];
			}
			
			if ($path == 'bookmarks') {
				$output = renderBMS($did);
				break;
			}

			if ($path == 'queue') {
				$json = '{"jsonrpc":"2.0","method":"Playlist.GetItems","id":"1742821847813","params":{"playlistid":1,"properties":["title","showtitle","thumbnail","mediapath","file","resume","artist","genre","year","rating","album","track","runtime","duration","playcount","dateadded","episode","artistid","albumid","tvshowid"]}}';
				$output = $_Kodi->sendJson($json);
				$output = renderQueue($output,kodiCurItem());
				break;
			}

			if ($path == 'sources') {
				$json = '{"jsonrpc":"2.0","method":"Files.GetSources","params":["video"],"id":1}'; //,{"jsonrpc":"2.0","method":"Files.GetSources","params":["music"],"id":2},{"jsonrpc":"2.0","method":"Addons.GetAddons","params":["xbmc.addon.audio","unknown",true,["path","name"]],"id":3},{"jsonrpc":"2.0","method":"Addons.GetAddons","params":["xbmc.addon.video","unknown",true,["path","name"]],"id":4}]';
				$dirs = $_Kodi->sendJson($json);
				$dirs['result']['files'] = $dirs['result']['sources'];
				$output = renderDir($dirs,'sources',kodiCurItem(),($data));
				break;
			}
			$json = '{"jsonrpc":"2.0","id":"1","method":"Files.GetDirectory","params":{"directory":"'.addcslashes($path,'\\').'","media":"video","properties":["title","file","playcount","runtime","resume","lastplayed","mimetype","thumbnail","dateadded"],"sort":{"method":"none","order":"ascending"}}}';
			$dirs = $_Kodi->sendJson($json);
			if ($dirs == NULL) {
				// $json = '{"jsonrpc":"2.0","id":"1","method":"Files.GetDirectory","params":{"directory":"'.$path.'","media":"video","properties":["title","file","playcount","lastplayed","mediapath","artist","duration","runtime","mimetype","thumbnail","dateadded"],"sort":{"method":"none","order":"ascending"}}}';
				$json = '{"jsonrpc":"2.0","id":"1","method":"Files.GetDirectory","params":{"directory":"'.$path.'","media":"video","properties":["title","file","playcount","runtime","resume","lastplayed","mimetype","thumbnail","dateadded"],"sort":{"method":"none","order":"ascending"}}}';
				$dirs = $_Kodi->sendJson($json);
			}
			$output = renderDir($dirs,$path,kodiCurItem(),($data));
		break;
		case "showhist":
			$output = niceList($kodi['hist']);
			sendMsg('380675774794956800', $output);
		break;
		case "yts":
		case "ytsearch":
			$search = urlencode($arg);
			// $path = addcslashes("plugin://plugin.video.youtube/kodion/search/query/?q=$search&type=video",'//');
			$path = "plugin://plugin.video.youtube/kodion/search/query/?q=$search&type=video";
			$json = '{"jsonrpc":"2.0","method":"Files.GetDirectory","id":"1743603938944","params":{"directory":"'.$path.'","media":"video","properties":["title","file","artist","duration","comment","description","runtime","playcount","mimetype","thumbnail","dateadded"]}}';
			$yts = $_Kodi->sendJson($json);
			$dir = [];
			$dir['result']['files'] = cacheYTNames($yts);
			var_dump('YOUTUBESEARCHHHHHHHHHHHHHHHH',$path,$json,$yts);
			$output = renderDir($dir,$path,kodiCurItem(),($data));

		break;
		case "ytp":
		case "ytplay":
			preg_match(
			"/(?:https?:\/\/)?(?:www\.)?(?:m\.)?(?:youtu\.be\/|youtube\.com\/(?:(?:watch)?\?(?:\S*&)?vi?=|(?:embed|v|vi|user|shorts)\/))([^?&\"'>\s]+)/",
			$arg,$matches);
			if (isset($matches[1])) {
				$vid = $matches[1];
			} else {
				$output = 'video id error';
				return 'video id error';
			}
			// var_dump($matches);
			$json = '{"jsonrpc":"2.0","method":"Player.Open","params":{"item":{"file":"plugin://plugin.video.youtube/play/?video_id='.$vid.'"}},"id":"1"}';
			$output = $_Kodi->sendJson($json)['result']." - $vid";

			usleep(250000);
			$playfile = "unknown";
			$json = '{"jsonrpc":"2.0","method":"Player.GetItem","params":[1,["title","thumbnail","file","artist","genre","year","rating","album","track","runtime","duration","playcount","dateadded","episode","artistid","albumid","tvshowid","fanart"]],"id":10}';
			$res = $_Kodi->sendJson($json);
			if ($res['result']) {
				$kodi['playing'] = null;
				$kodi['playfile'] = $res['result']['item']['file'];
				$kodi['playpic'] = $res['result']['item']['thumbnail'];
				$kodi['playfilename'] = $res['result']['item']['label'];
				if (isset($res['result']['item']['label'])) {
					$playfile = $res['result']['item']['label'];
				} else {
					$playfile = $res['result']['item']['file'];
				}
			}
			setVoiceStatus("Playing ".$playfile);
		break;
		case "stop":
			$output = $_Kodi->stop()['result'];
			setVoiceStatus("");
		break;
	}
	var_dump('KODI OUTPUT',$output,$return);
	if ($return) {
		return $output;
	}
	if ($data == null) {
		$output = false;
	} else if ($data) { 
		var_dump("WS OUTPUT FOR ".$kodi['plmode']);
		outputWorkspace($data,$output);
		$output = false;
	}
	return $output;
}

$vidTimesData = [];

function getVidTimes() {
	global $_Kodi;
	global $vidTimesData;
	$json = '{"jsonrpc":"2.0","method":"Player.GetProperties","params":[1,["speed","position","totaltime","time","percentage"]],"id":11}'; //,{"jsonrpc":"2.0","method":"Player.GetItem","params":[1,["title","thumbnail","file","artist","genre","year","rating","album","track","duration","playcount","dateadded","episode","artistid","albumid","tvshowid","fanart"]],"id":12}';
	$props = $_Kodi->sendJson($json)['result'];
	$spd = $props['speed'];
	$pos = $props['position'];
	$pcnt = round($props['percentage'],2);

	// $state = ((!$spd)?"Playing":($pos == -1 || !$pcnt ))?"Stopped":"Paused";

	$state = ($spd)?(($pos == -1 || !$pcnt )?"Stopped":"Playing"):"Paused";
	// $testb = (!$spd)?(($pos > 0 || $pcnt )?"A":"B"):"C";
	// $testc = ($spd)?(($pos > 0 || $pcnt )?"A":"B"):"C";
	// $testd = (false)?((true)?"A":"B"):"C";
	
	if ($state !== "Stopped") {
		$time = array_map('padInt',$props['time']);
		$curtime = implode(':',[$time['hours'],$time['minutes'],$time['seconds']]);
		$csecs = timeArrayToSecs($curtime);
		$cstime = $csecs-time();
		$time = array_map('padInt',$props['totaltime']);
		$endtime = implode(':',[$time['hours'],$time['minutes'],$time['seconds']]);
		$esecs = timeArrayToSecs($endtime);
	} else {
		$curtime = $endtime = '00:00:00';
		$csecs = $esecs = $pcnt = 0;
		$cstime = null;
	}

	$vidTimesData = [$state,$curtime,$endtime,$pcnt,$csecs,$esecs,$cstime,$props];
	return $vidTimesData;
	
}

function niceList($array,$appseperator = '',$binder = 'and') {
	if (!is_array($array)) { return $array; }
	$lastc = count($array);
	$last = array_pop($array);
	$output = implode(', '.$appseperator, $array);
	if ($output) {
		$output .= " $binder ".$appseperator;
	}
	$output .= $last;
	return $output;
}

function numberfy_array($array) {
	if (count($array) > 1) {
		array_walk($array, function(&$value, $key) { $value = "\n[".($key+1)."] ".preg_replace("/.$/",'',$value); });
		$array[0] = ":\n".$array[0];
	} else {
		$array[0] = lcfirst(preg_replace("/.$/",'',$array[0]));
	}
	return $array;
}

function externalUpdate($eurl,$field,$input,$user) {
	error_log(var_dump($eurl,$field,$input,$user['user']));
	include('db.php');
	switch($field) {
		case 'owner':
			if (preg_match("/^<@!\d*>$/",$input)) { 
				$input = preg_replace("/[^0-9]/", "", $input);
			}
			$user = getUser($input);
			if (!$user['status']) {
				return array(false,"User lookup failed for $input!");
			} else {
				return array(true,$user['user']); 
			}
		break;
		case 'shoutdate':
			$stmt = $dbconn->prepare("SELECT shoutdate,biweekly,dates FROM venues WHERE deleted='0' AND eurl=:eurl");
			$stmt->execute(['eurl' => $eurl]); 
			$post = $stmt->fetch(PDO::FETCH_ASSOC);
			foreach ($post AS $row) {
				$newpost[] = array_map(fn($e) => html_entity_decode($e, ENT_QUOTES), $row);
			}
			$post = $newpost;

			// var_dump($post);		
			$dates = json_decode(html_entity_decode($post['dates'],ENT_QUOTES),true);
			$shoutdate = json_decode(html_entity_decode($post['shoutdate'],ENT_QUOTES),true);
			$timezone = $user['timezone'];
			if ($timezone == '') { $timezone = 'America/New_York'; }

			$dates = parseDates($post,$post['biweekly'],$timezone)['ndates'];
		
			$input = explode(' ',$input);
			$mode = trim($input[0]);
			array_shift($input);
			$input = explode(',',implode(' ',$input));
				// var_dump($mode,$input);

			// array_shift($input);
			if ($mode == 'add') {
				$inputdate = $date = $input[0];
				$overwrite = 0;
				if (startsWith($date,'!')) {
					$overwrite = 1;
					$date = stripstring('!',$date);
				}
				array_shift($input);
				$msg = trim(implode(',',$input));
				var_dump($date,$msg,$input);
				if (!preg_match('/^\d..*-\d.-\d.$/',$date)) {
					$date = date('Y-m-d', strtotime($date));
					if ($date == '1969-12-31') { return array(false,"Date interpretation error");
					}
				}
				if (!isset($dates[$date][0])) {
					return array(false,"Date interpretation error. Venue isn't set to be open on $date for $timezone");
				}
				$datetime = $date." ".$dates[$date][0];
				
				$localdate = new DateTime($date.' '.$dates[$date]['0'], new DateTimeZone($timezone));
				$localdate->setTimezone(new DateTimeZone('UTC'));
				$date = $localdate->format('Y-m-d H:i');
				if (isset($shoutdate[$date])) {
					if ($overwrite == 0) {
						return array(false,"That date is already set. To overwrite it, use '!'. eg. !$inputdate");
					}
					foreach ($shoutdate['data'] AS $index => $value) {
						if ($date == $value) { unset($shoutdate['data'][$index]); break; }
					}
				}
		
				$shoutdate['data'][] = ['date' => $date, 'value' => $msg];
				$shoutdate[$date] = ['date' => $date, 'value' => $msg];
				// $shoutdate[]['value'] = $msg;
			} else if ($mode == 'remove') {
				$num = $input[0];
				if (!isset($shoutdate['data'][$num])) { return array(false,"Shout message #$num could not be found!"); }
				$date = $shoutdate['data'][$num]['date'];
				unset($shoutdate[$date]);
				unset($shoutdate['data'][$num]);
			} else if (isset($mode) && $mode != ''){
				return array(false,"``.shoutdate remove {shout #}``");
			}
			var_dump($shoutdate['data']);
			return array(true,json_encode($shoutdate));
		break;
		case "title":
			$cureurl = $eurl;
			$stmt = $dbconn->prepare("SELECT owner FROM venues WHERE eurl=:eurl");
			if ($stmt->execute(['eurl' => $eurl])) {
				$data['powner'] = $stmt->fetchcolumn();
				$powner = ' OR user=:powner';
			} else {
				$powner = '';
			}
			// $eurl = str_replace('--', '-', str_replace(' ','-', preg_replace('/[^\da-zQ_ ]/i', '', str_replace(array('&#39;',"'",'-'),array('Q','Q','_'), trim(strtolower($title = $input))))));

			$eurl = str_replace('--', '-', str_replace(' ', '-', preg_replace('/[^\da-z ]/i', '', trim(strtolower($title = $input)))));
      if ($eurl != $cureurl ) {
				$results = 0;
				$stmt = $dbconn->prepare("SELECT title FROM venues WHERE eurl=:eurl");
				$stmt->execute(['eurl' => $eurl]);
				$results = $stmt->rowCount();
				if ($results != 0) {
					$rtitle = $stmt->fetchcolumn();
					if ($rtitle && $rtitle != '') {
						return array(false, "Name conflict: $title currently has the eurl $eurl");
					} else {
						return array(false, "General error setting title to $title and eurl to $eurl");
					}						
				}
				$query = "UPDATE users SET defaulteurl = :eurl WHERE defaulteurl = :cureurl $powner";
				$res = $dbconn->prepare($query);
				$data['eurl'] = $eurl;
				$data['cureurl'] = $cureurl;
				$res->execute($data);
				unset($data['powner']);
				$stmt = $dbconn->prepare("UPDATE venues SET eurl=:eurl WHERE eurl=:cureurl");
				$stmt->execute($data);
				$stmt = $dbconn->prepare("UPDATE images SET eurl=:eurl WHERE eurl=:cureurl");
				$stmt->execute($data);
				$stmt = $dbconn->prepare("UPDATE tinyurl SET eurl=:eurl WHERE eurl=:cureurl");
				$stmt->execute($data);
      }
			return array(true, $title, $eurl);
		break;
		case "aetheryte":
			if ($input != 'auto') { return array(true, $input); }
			$stmt = $dbconn->prepare("SELECT plot,housing FROM venues WHERE eurl=:eurl");
			$stmt->execute(['eurl' => $eurl]);
			$results = $stmt->rowCount();
			if ($results == 0) { return array(false, "Lookup error!"); }
			$post = $stmt->fetch(PDO::FETCH_ASSOC);
			$plot = $post['plot'];
			$housing = $post['housing'];
			if (!$aetheryte = getath($plot, $housing, true)) { return array(false, "Could not set aetheryte automatically"); }
			return array(true, $aetheryte);
		break;
		case "psize":
			if ($input != 'auto') { return array(true, $input); }
			$stmt = $dbconn->prepare("SELECT plot,housing FROM venues WHERE eurl=:eurl");
			$stmt->execute(['eurl' => $eurl]);
			$results = $stmt->rowCount();
			if ($results == 0) { return array(false, "Lookup error!"); }

			$post = $stmt->fetch(PDO::FETCH_ASSOC);
			$plot = $post['plot'];
			$housing = $post['housing'];
			if (!$psize = getath($plot, $housing, 'psize')) { return array(false, "Could not set plot size automatically"); }
			return array(true, $psize);
		break;
	}
}

function get_date_diff( $time1, $time2, $precision = 2 ) {
	// If not numeric then convert timestamps
	if( !is_int( $time1 ) ) {
		$time1 = strtotime( $time1 );
	}
	if( !is_int( $time2 ) ) {
		$time2 = strtotime( $time2 );
	}

	// If time1 > time2 then swap the 2 values
	if( $time1 > $time2 ) {
		list( $time1, $time2 ) = array( $time2, $time1 );
	}

	// Set up intervals and diffs arrays
	$intervals = array( 'year', 'month', 'day', 'hour', 'minute', 'second' );
	$diffs = array();

	foreach( $intervals as $interval ) {
		// Create temp time from time1 and interval
		$ttime = strtotime( '+1 ' . $interval, $time1 );
		// Set initial values
		$add = 1;
		$looped = 0;
		// Loop until temp time is smaller than time2
		while ( $time2 >= $ttime ) {
			// Create new temp time from time1 and interval
			$add++;
			$ttime = strtotime( "+" . $add . " " . $interval, $time1 );
			$looped++;
		}

		$time1 = strtotime( "+" . $looped . " " . $interval, $time1 );
		$diffs[ $interval ] = $looped;
	}

	$count = 0;
	$times = array();
	foreach( $diffs as $interval => $value ) {
		// Break if we have needed precission
		if( $count >= $precision ) {
			break;
		}
		// Add value and interval if value is bigger than 0
		if( $value > 0 ) {
			if( $value != 1 ){
				$interval .= "s";
			}
			// Add value and interval to times array
			$times[] = $value . " " . $interval;
			$count++;
		}
	}

	// Return string with times
	return implode( ", ", $times );
}

function unvar_dump($str) {
    if (strpos($str, "\n") === false) {
        //Add new lines:
        $regex = array(
            '#(\\[.*?\\]=>)#',
            '#(string\\(|int\\(|float\\(|array\\(|NULL|object\\(|})#',
        );
        $str = preg_replace($regex, "\n\\1", $str);
        $str = trim($str);
    }
    $regex = array(
        '#^\\040*NULL\\040*$#m',
        '#^\\s*array\\((.*?)\\)\\s*{\\s*$#m',
        '#^\\s*string\\((.*?)\\)\\s*(.*?)$#m',
        '#^\\s*int\\((.*?)\\)\\s*$#m',
        '#^\\s*bool\\(true\\)\\s*$#m',
        '#^\\s*bool\\(false\\)\\s*$#m',
        '#^\\s*float\\((.*?)\\)\\s*$#m',
        '#^\\s*\[(\\d+)\\]\\s*=>\\s*$#m',
        '#\\s*?\\r?\\n\\s*#m',
    );
    $replace = array(
        'N',
        'a:\\1:{',
        's:\\1:\\2',
        'i:\\1',
        'b:1',
        'b:0',
        'd:\\1',
        'i:\\1',
        ';'
    );
    $serialized = preg_replace($regex, $replace, $str);
    $func = create_function(
        '$match', 
        'return "s:".strlen($match[1]).":\\"".$match[1]."\\"";'
    );
    $serialized = preg_replace_callback(
        '#\\s*\\["(.*?)"\\]\\s*=>#', 
        $func,
        $serialized
    );
    $func = create_function(
        '$match', 
        'return "O:".strlen($match[1]).":\\"".$match[1]."\\":".$match[2].":{";'
    );
    $serialized = preg_replace_callback(
        '#object\\((.*?)\\).*?\\((\\d+)\\)\\s*{\\s*;#', 
        $func, 
        $serialized
    );
    $serialized = preg_replace(
        array('#};#', '#{;#'), 
        array('}', '{'), 
        $serialized
    );

    return unserialize($serialized);
}

$discorderror = 0;
$dchkmsg = '';

function isRunning($pid){
    try{
        $result = shell_exec(sprintf("ps %d", $pid));
        if( count(preg_split("/\n/", $result)) > 2){
            return true;
        }
				error_log($pid);
				print_r($result);
    }catch(Exception $e){}
    return false;
}

$timezones = timezone_abbreviations_list();
$zones = [];
foreach ($timezones as $key => $code) {
	if(strlen($key) != 3) { continue;}
	$name = $code['0']['timezone_id'];
	if ($name == NULL) {continue;}
	$key = strtoupper($key);
	if (!in_array($name, array_keys($zones))) {
		$zones[$name] = $key;
	}
}

function contains($str, array $arr) {
	foreach($arr as $a) {
		if (stripos($str,$a) !== false) return true;
	}
	return false;
}

function endsWith( $haystack, $needle ) {
  $length = strlen( $needle );
  if( !$length ) {
    return true;
  }
  return substr( $haystack, -$length ) === $needle;
}

function stripos_all($haystack, $needle) {
    $offset = 0;
    $allpos = array();
    while (($pos = stripos($haystack, $needle, $offset)) !== FALSE) {
        $offset   = $pos + 1;
        $allpos[] = $pos;
    }
    return $allpos;
}

function startsWith( $haystack, $needle ) {
	$length = strlen( $needle );
	return substr( $haystack, 0, $length ) === $needle;
}

function stripstring($prefix, $str) {
	if (substr($str, 0, strlen($prefix)) == $prefix) {
		$str = substr($str, strlen($prefix));
	} 
	return $str;
}

$nicks = array();
$memberids = array();
function populateNicksIds() {
	global $discord;
	$members = $discord->guilds->get('id', '788607168228229160')->members;
	global $nicks;
	global $memberids;
	foreach( $members as $member ) {
		$user = $member->user;
//		$avatar = $member->user->getAvatar();
		// $avatar = "https://cdn.discordapp.com/avatars/" . $user->id . "/" . $user->avatar . ".png";
		// $avatar = "https://cdn.discordapp.com/avatars/" . $user->id . "/" . $user->avatar . ".png";
		$nick = $user->username.'#'.$user->discriminator;
		$nicks[$nick] = $user->id;
		$memberids[$user->id] = [$nick];
		$memberids[$user->id]['user'] = $user;
		$memberids[$user->id]['avatar'] = $user->avatar;
	}
}

$channels = array();
$channelids = array();
function populateChannelsIds() {
	global $discord;
	$channeldata = $discord->guilds->get('id', 788607168228229160)->channels;
	global $channels;
	global $channelids;
	foreach( $channeldata as $channel ) {
		$name = $channel->name;
		$id = $channel->id;
		$channels[$name] = $id;
		$channelids[$id] = $name;
	}
}

function getRandomWeightedElement(array $array) {
	$weightedValues = [];
	foreach($array as $key => $val) {
    if(is_numeric($key) || intval($val) == 1) { continue; }
    if (!isset($val)) { $val = 50; }
		$weightedValues[$key] = intval($val);
	}

	$rand = mt_rand(1, (int) array_sum($weightedValues));
	foreach ($weightedValues as $key => $value) {
		$rand -= $value;
		if ($rand <= 0) {
			return $key;
		}
	}
}

$lastwin = '';

function utd($f) {
	$f = str_replace('&nbsp;',' ',$f);
	return str_ireplace(['<b>','</b>'],'**',$f);
}

function linkify($u) {
	return "<a href='$u'>$u</a>";
}

function array_rebuild($array) {
	$newarray = [];
	foreach ($array AS $item => $weight) {
		if (is_numeric($item)) { continue; }
		if (!isset($array[$weight])) {
			$newarray[$item] = 50;
		} else {
			$newarray[$item] = $array[$item];
		}
		//$newarray[] = $value;
	}
	return $newarray;
}

$playlistArray = [
	'sources' => '📁',
	'playlist' => '📄',
	'bookmarks' => '🔖',
	// 'showlist' => '📂',
	'favorites' => '⭐',
	'dice' => '🎲',
	'queuerandom' => '⁉️',
	'prev' => '⬅',
	'next' => '➡',
	'back' => '🔙',
	'refresh' => '🔃'
];
	
$playlistArray = $nums + $playlistArray;

$playerArray = [
	'tprev' => '⏮',
	'rw' => '⏪',
	'stop' => '⏹️',
	'play' => '▶️',
	'pause' => '⏸️',
	'ff' => '⏩',
	'tnext' => '⏭️',
	'movies' => '🎦',
	'tv' => '📺',
	'showpos' => '💠'
];

$emoteArray = $playerArray + $playlistArray;
array_shift($playlistArray);

function sendData($channel,$data, $mode) {
	$myToken = $GLOBALS['myToken'];
	$data_string = json_encode($data);
	$ch = curl_init('https://discord.com/api/v10/channels/' . $channel . "/$mode");
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Content-Length: ' . strlen($data_string),
			'Authorization: Bot ' . $myToken
			)
	);
	$answer  = curl_exec($ch);
	$return = $answer;
	if (curl_error($ch)) {
			$return .= curl_error($ch);
	}
	return $return;
}

function menuComponent() {
	$json = [
			"content"=> "This is a message with components",
			"components" => [
					[
							"type" => 1,
							"components" => [
									[
											"type" => 2,
											"label" => "Click me!",
											"style" => 1,
											"custom_id" => "click_one"
									]
							]

					]
			]
	];
}

$gseek = false;

function setVoiceStatus($status = '',$myChannel = "1274001261976354886",$seek = false) {
	global $gseek;
	global $loop;
	global $timer;
	
	if ($myChannel == null) {
		$myChannel = "1274001261976354886";
	}
	
	if ($status && $timer !== NULL) { $loop->cancelTimer($timer); $timer = NULL; 
		var_dump("Clearing voice status clearer timer");
	} 

	if (!empty($status)) {
		var_dump("Setting voice status to ".$status);
		//$gseek = $seek;
	} else {
		var_dump("Clearing voice status.");
	}

	echo sendData($myChannel,array('status' => $status),'voice-status');
	if (!empty($status)) {
		if ($timer !== NULL) { $loop->cancelTimer($timer); $timer = NULL; }
		global $ttt;
		$ttt = [0,0];
		//usleep(100000);
	}
}							

$ttt = [0,0];

function seekAndSetTimeout($seek = false) {
	var_dump('aaaaaaaaaaaaaaaaaa SEEK AND SET TIMEOUT',$seek);
	global $gseek;
	if (!$seek && $gseek && $gseek !== null) { $seek = $gseek; }
	var_dump('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb SEEK AND SET TIMEOUT',$gseek);
	global $ttt;
	global $loop;
	global $setstuff;
	global $timer;
	global $_Kodi;
	//$ttt = [0,0];


	if ($ttt[0] == 0 && $ttt[1] < 15) {
		$json = '{"jsonrpc":"2.0","method":"Player.GetProperties","params":[1,["playlistid","speed","position","totaltime","time","percentage","shuffled","repeat","canrepeat","canshuffle","canseek","partymode"]],"id":11}'; //,{"jsonrpc":"2.0","method":"Player.GetItem","params":[1,["title","thumbnail","file","artist","genre","year","rating","album","track","duration","playcount","dateadded","episode","artistid","albumid","tvshowid","fanart"]],"id":12}';
		$props = $_Kodi->sendJson($json);
		if ($props && isset($props['result']) && $props['result'] && $props['result']['speed']) {
			var_dump("SPEED VALUE00000000000000000000000000000000000000000000000",$props['result']['speed']);
			
		}
		if (!$props || !$props['result'] || !$props['result']['totaltime'] || !is_integer($props['result']['totaltime']['hours'])) {
			var_dump("Props failed",$props);
			$ttt[1]++;
			$setstuff = $loop->addTimer(intval(1), function () use ($seek) {
				seekAndSetTimeout($seek);
			});
			return;
		}
		$props = $props['result'];
		$time = (($props['totaltime']['hours']*60)*60);
		$time = $time+($props['totaltime']['minutes']*60);
		$ttime = $time+$props['totaltime']['seconds'];
		$time = (($props['time']['hours']*60)*60);
		$time = $time+($props['time']['minutes']*60);
		$time = $time+$props['time']['seconds'];
		// $tries++;
		if ($ttime == 0) {
			var_dump($ttime,"Total time is 0. Retrying...",$ttt);
			$ttt[1]++;
			$setstuff = $loop->addTimer(intval(1), function () use ($seek) {
				seekAndSetTimeout($seek);
			});
			return;
		} else {
			$ttt = [$ttime,0];
		}
	}

	var_dump($ttt);
	$ttime = $ttt[0];
	if (isset($ttt[2])) {
		$time = $ttt[2];
	}
	if ($seek && $seek !== null) {
		$t = $seek;
		if (is_array($t)) {
			var_dump("Seeking array position",$seek);
			if ($t['minutes'] > 3) { $t['minutes'] = $t['minutes']-3; }

			$json = ["id"=>0,"jsonrpc"=>"2.0","method"=>"Player.Seek","params"=>[1]];
			$json["params"][1] = ["time" => $t];

			$time = (($t['hours']*60)*60);
			$time = $time+($t['minutes']*60);
			$time = $time+$t['seconds'];
			$t = $time;
			$ttt[2] = $time;
			// var_dump($json);
			// $seek = $_Kodi->sendJson(json_encode($json));
			// var_dump($seek);
			// usleep(1000000);
			$json = json_encode($json);
		} else if (is_integer($t)) {
			$ttt[2] = $t;
			$time = $t;
			var_dump("Seeking int position",$seek);
			if ($t > 200) { $t = $t-180; }
			$newtime = explode(':',gmdate("H:i:s", $t));
			$json = ["id"=>0,"jsonrpc"=>"2.0","method"=>"Player.Seek","params"=>[1]];
			$json["params"][1] = ["time" => ['hours'=>intval($newtime[0]),'minutes'=>intval($newtime[1]),'seconds' => intval($newtime[2]),'milliseconds'=>00]];
			$json = json_encode($json);
			// $json["params"][1] = ["time" => ['seconds'=>$t]];
		} else {
			$json = $t;
		}
		var_dump($json);
		//$tries = 0;
		$output = $_Kodi->sendJson($json);
		if (isset($output['error']) && $ttt[1] < 15) {
			var_dump($seek,$output);
			$ttt[1]++;
				$setstuff = $loop->addTimer(intval(1), function () use ($json) {
					seekAndSetTimeout($json);
				});
				return;

		}
		global $gseek;
		$gseek = null;
	} else {
		$time = 0;
		$ttime = $ttt[0];
	}
	var_dump($ttt);
	$ctime = $ttime - $time;
	var_dump("Total time $ttime, current time $time. Clearing voice status in $ctime seconds");
	$ttt = [0,0];
	loopy($ctime);

}

$stopLoop = true;
$setstuff = NULL;
$loop = React\EventLoop\Loop::get();
$statusTimer = $timer = null;
$deathroll = 999;
$reactConnector = new \React\Socket\Connector(['dns' => '1.1.1.1', 'timeout' => 10]);
$connector = new \Ratchet\Client\Connector($loop, $reactConnector);
$kevents = [];

$connector('ws://localhost:9099')->then(function($conn) {
	$conn->on('message', function($msg) use ($conn) {
		global $kevents;
		global $gseek;
		global $kodi;
		global $lastStatusPlayer;
		echo "Received: {$msg}\n";
		$msg = json_decode($msg,true);
		$kevents[$msg['method']] = $msg;
		file_put_contents('kevents.json',json_encode($kevents, JSON_PRETTY_PRINT));
		if ($msg['method'] == 'Player.OnStop') {
			global $statusTimer;
			global $loop;
			if ($statusTimer !== NULL) { $loop->cancelTimer($statusTimer); $statusTimer = NULL; }
			$lastStatusPlayer[0] = "Stopped";
			$lastStatusPlayer[1] = "";
			$lastStatusPlayer[2] = "00:00:00";
			$lastStatusPlayer[3] = "00:00:00";
			$lastStatusPlayer[4] = 0;
			$lastStatusPlayer[5] = "";
			setVoiceStatus('');
			playerStatus('useArray');
			$kodi['playfile'] = $kodi['playfilename'] = null;
		}
		if ($msg['method'] == 'Player.OnPause') {
				// $lastStatusPlayer = [$state,$play,$pcnt,$curtime,$endtime];
				$lastStatusPlayer[0] = "Paused";
				if (!isset($msg['params']['data']['item']['title'])) {
					list($curitem,$kodi['playfilename']) = kodiCurItem(true);
				}
				if (!$kodi['playfilename']) {
					$kodi['playfilename'] = $msg['params']['data']['item']['title'];
				}
				$lastStatusPlayer[1] = $kodi['playfilename'];
				playerStatus('useArray');
			setVoiceStatus("Paused ".$kodi['playfilename']);
		} else if ($msg['method'] == 'Player.OnResume') {
			if (!$kodi['playfilename']) {
			list($curitem,$kodi['playfilename']) = kodiCurItem(true);
		}
		setVoiceStatus("Playing ".$kodi['playfilename']);
		$lastStatusPlayer[0] = "Playing";
		$lastStatusPlayer[1] = $kodi['playfilename'];
		playerStatus('useArray');
	} 
	if ($msg['method'] == 'Player.OnPlay') {
		list($curitem,$kodi['playfilename']) = kodiCurItem(true);
		setVoiceStatus("Playing ".$kodi['playfilename']);
		if (!startsWith($curitem,'plugin://plugin.video.youtube/play/?video_id')) {
			$lastStatusPlayer[0] = "Playing";
			$lastStatusPlayer[1] = $kodi['playfilename'];
			playerStatus('useArray');
			if (isset($kodi['onPlay']) && $kodi['onPlay']) {
				kodi($kodi['onPlay'],null,$kodi['data']);
				$kodi['onPlay'] = false;
				unset($kodi['data']);
			}

			if (!$kodi['playfilename']) {
				$kodi['playfilename'] = $msg['params']['data']['item']['title'];
			}
			var_dump('SEEK AND SET TIMEOUT 1',$gseek);
			if ($gseek !== null) { seekAndSetTimeout($gseek); }
		}
	} else if ($msg['method'] == "Player.OnAVStart" || $msg['method'] == 'Other.playback_started') {
			if (isset($kodi['onPlay']) && $kodi['onPlay']) {
				kodi($kodi['onPlay'],null,$kodi['data']);
				$kodi['onPlay'] = false;
				unset($kodi['data']);
			}
			if ( $lastStatusPlayer[0] !== "Playing" ) {
				$lastStatusPlayer[0] = "Playing";
				$lastStatusPlayer[1] = $kodi['playfilename'];
				playerStatus('useArray');
			}
			var_dump('22222222222222222SEEK AND SET TIMEOUT',$gseek);

			if ($gseek !== null) { seekAndSetTimeout($gseek); }
			fixKodiAudio();
			fruityLooper();
		}
	});
	$conn->send('Hello World!');
}, function ($e) {
	echo "Could not connect: {$e->getMessage()}\n";
});

function reactionAction($emojianame,$reaction,$name = '') {
	global $wsLines;
	global $kodi;
	$page = 1;

	if ($name == 'player') {
		global $lastStatusData;
		var_dump('6666666666666666666666666666666666666666666',$lastStatusData,$reaction);
		$lastStatusData = $reaction;
		$wsarrname = 'player';
	}

	var_dump($reaction['channel_id']);
	$cid = $reaction['channel_id'];
	$did = $reaction['user_id'];
	if (isset($wsLines[$cid][$name]['page'])) {
		$page = intval($wsLines[$cid][$name]['page']);
	}
	if (is_numeric($emojianame)) {
		$inum = intval($emojianame);
		$key = ((($page-1)*10)+($inum - 1));
			
		kodi('select',$key,$reaction);
		return;
	}
	
	switch ($emojianame) {
		case "tv":
			kodi('shows',null,$reaction);
		break;
		case "movies":
			kodi('movies',null,$reaction);
		break;
		case "back":
			kodi('back',null,$reaction);
		break;
		case "bookmarks":
			kodi('bookmarks',[null,$did,false],$reaction);
		break;
		case "favs":
			kodi('favs',[null,$did,false],$reaction);
		break;
		case "tprev":
			kodi('prev',null,$reaction);
		break;
		case "tnext":
			kodi('next',null,$reaction);
		break;
		case "prev":
			wsPages($reaction,NULL,'b');
		break;
		case "next":
			wsPages($reaction,NULL,'n');
		break;
		case "playlist":
			kodi('queue',null,$reaction);
		break;
		case "queuerandom":
			kodi('queuerandom',null,$reaction);
		break;
		case "refresh":
			kodi('refresh',null,$reaction);
		break;
		case "showpos":
			// kodi('showlist',null,$reaction);
			global $lastStatusPlayer; list($lastStatusPlayer[0],$lastStatusPlayer[2],$lastStatusPlayer[3],$lastStatusPlayer[4]) = getVidTimes(); $msg = playerStatus('useArray');
		break;
		case "showlist":
			kodi('showlist',null,$reaction);
		break;
		case "sources":
			kodi('sources',null,$reaction);
		break;
		case "stop":
			kodi('stop',null,null);
		break;
		case "rw":
			$arg  = ['time',[" -25 seconds"]];
			kodi("seek",$arg,$reaction);
		break;
		case "ff":
			$arg  = ['time',[" +25 seconds"]];
			kodi("seek",$arg,$reaction);
		break;
		case "dice":
			// $arg  = ['time',["+25 seconds"]];
			kodi("play",'random',$reaction);
		break;
		case "play":
			kodi('btn','play',null);
		break;
		case "pause":
			kodi('btn','pause',null);
		break;
	}
}

function updateKTimes() {
	global $lastStatusPlayer; list($lastStatusPlayer[0],$lastStatusPlayer[2],$lastStatusPlayer[3],$lastStatusPlayer[4]) = getVidTimes(); $msg = playerStatus('useArray');
}

function fruityLooper() {
	updateKTimes();
	global $stopLoop;
	if ($stopLoop) {return; }
	var_dump($msg);
	if ($lastStatusPlayer[0] == "Playing") {
		loopyLoop(5);
	}
	return $msg;
}

function loopyLoop($time) {
	global $loop;
	global $statusTimer;
	if ($statusTimer !== NULL) { $loop->cancelTimer($statusTimer); $statusTimer = NULL; }

	$statusTimer = $loop->addTimer(intval($time), function () use ($loop, $statusTimer) {
		fruityLooper();
	});
	
}

function loopy($time) {
	global $loop;
	global $timer;
	if ($timer !== NULL) { $loop->cancelTimer($timer); $timer = NULL; }

	$timer = $loop->addTimer(intval($time), function () use ($loop, $timer) {
		setVoiceStatus('');
	});
	
}

// Check for an error status and redirect error message parts to admin/user appropriately
function kodiError($return) {
	$error = '';
	if (isset($return['error'])) {
		sendMsg('380675774794956800',  print_r($return['error'],true));
		if (!is_array($return['error'])) { $error = "\n".$return['error']; }
		return "There was a problem processing your request!".$error;
	}
	return false;
}

$discord = new Discord([
	'token' => $GLOBALS['myToken'],
	'storeMessages' => true,
	'loadAllMembers' => true,
	'intents' => 53608447,
	'loop' => $loop
]);

$discord->on('init', function (Discord $discord) {
	$activity = $discord->factory(\Discord\Parts\User\Activity::class, 
		['name' => $GLOBALS['activityName'], 'type' => 2] //, 'name' => 'Test']
	);
	$discord->updatePresence($activity);

	populateChannelsIds();
	populateNicksIds();

	connectKodi($GLOBALS['amDev']);

	$botready = "KodiKitty is ready!";
	echo $botready;

	$channelid = file_get_contents($GLOBALS['filePrefix'].'lastchan');
	file_put_contents($GLOBALS['filePrefix'].'lastchan','none');
	if ($channelid != 'none' && $channelid != '') {
		$channelid = explode(':',$channelid);
		var_dump($channelid);
		$guildid = $channelid[0];
		$channelid = $channelid[1];
		if ($guildid == 'DM') {
			sendMsg($channelid, $botready);
		} else {
			$discord->guilds->get('id', $guildid)
				->channels->get('id', $channelid)
				->sendMessage($botready.tacoGen());
		}
	}			

	$guild = $discord->guilds->get('id', 788607168228229160);

	if ($guild) {
		$channel = $guild->channels->get('id', 844468574462148658);
		$channel->sendMessage($botready);
	}
	sendMsg(380675774794956800, $botready);

	$discord->on(Event::GUILD_MEMBER_ADD, function (Member $member, Discord $discord) {
		populateNicksIds();
	});
	$discord->on(Event::GUILD_MEMBER_UPDATE, function (Member $new, Discord $discord, $old) {
		populateNicksIds();
	});
	$discord->on(Event::GUILD_MEMBER_REMOVE, function (Member $member, Discord $discord) {
		var_dump(json_encode($reaction));
		populateNicksIds();
	});

	$discord->on(Event::MESSAGE_REACTION_ADD, function ($reaction, Discord $discord) {
		var_dump('$reaction');
		$dmMode = false;
		$amDev = $GLOBALS['amDev'];
		if(!isset($reaction['guild_id']) || $reaction['guild_id'] === NULL) { 
			$author = $reaction['user_id'];
			error_log("RA-DM mode");
			$dmMode = true;
		} else {
			error_log("RA-Channel mode");
			if ($reaction['author'] !== NULL) {
				$author = $reaction['author']['id'];
			} else if ($reaction['user_id'] !== NULL) {
				$author = $reaction['user_id'];

			} else {
				error_log("reaction author is NULL");
			}
		}

		file_put_contents('reactiondata.json',json_encode($reaction,JSON_PRETTY_PRINT));
		if ($amDev) { var_dump('$author,$GLOBALS["myID"],$GLOBALS["otherID"]',$author,$GLOBALS['myID'],$GLOBALS['otherID']); }
		if ($author == $GLOBALS['myID'] || $author == $GLOBALS['otherID']) {
			if ($amDev) { var_dump('COOOKIESSSSS 899999999999999999'); }

			return;
		}

		$isws = checkWorkspace($reaction);
		if ($amDev) { var_dump('$isws',$isws,$reaction['emoji']->name); }
		var_dump('$reaction');
		global $emoteArray;
		$eaNames = array_flip($emoteArray);
		$emojiname = $reaction['emoji']->name;
		$emojianame = null;
		if (isset($eaNames[$emojiname])) {
			$emojianame = $eaNames[$emojiname];
			if ($emojianame == "taco") {
				sendReply($reaction, "https://www.crystalshouts.com/graha.gif \nOm nom nom!");
				return;
			}
		}					

		if ($isws) {
			if ($isws === true) {
				$isws = "";
			}
			$name = $isws;
			$channel = getChannel($reaction);
			$channel->broadcastTyping();

			$emojiid = $reaction['emoji']->id;
			if ($amDev) { var_dump('000000000001111',$emojiid,$emojianame,$emojiname); }
			if (!$dmMode) {
				$channel = getChannel($reaction);
				$channel->messages->fetch($reaction['message_id'])->then(function (Message $message) use ($reaction,$author,$amDev) {
					$emojiname = $reaction['emoji']->name;
					$message->deleteReaction(Message::REACT_DELETE_ID, $emojiname, $author)->then(function ($x) use ($reaction,$amDev){
						if ($amDev) { var_dump('$x',$x); }
					});
				});
			}
			$page = null;
			reactionAction($emojianame,$reaction,$name);
			return;
		}
	});

	$discord->on(Event::MESSAGE_REACTION_REMOVE, function ($reaction, Discord $discord) {
		var_dump('$reaction-REMOVE');
		$dmMode = false;
		if(!isset($reaction['guild_id']) || $reaction['guild_id'] === NULL) { 
			$author = $reaction['user_id'];
			error_log("RA-DM mode");
			$dmMode = true;
		} else {
			error_log("RA-Channel mode");
			if ($reaction['author'] !== NULL) {
				$author = $reaction['author']['id'];
			} else if ($reaction['user_id'] !== NULL) {
				$author = $reaction['user_id'];
			} else {
				error_log("reaction author is NULL");
			}
		}

		var_dump('$author',$author,$GLOBALS['myID'],$GLOBALS['otherID']);
		if ($author == $GLOBALS['myID'] || $author == $GLOBALS['otherID']) {
			var_dump('COOOKIESSSSS 55555555555555555555777777777777777');
			return;
		}

		$isws = checkWorkspace($reaction);
		if ($isws && !$dmMode) {

			file_put_contents('discordvar.json',json_encode($discord, JSON_PRETTY_PRINT));
			file_put_contents('discordvar.txt',print_r($discord, true));


			var_dump('YEEEEEEEEEEEEET COOOKIESSSSS 55555555555555555555777777777777777');
			return;
		}

		var_dump('$isws',$isws);
		var_dump('$reaction-REMOVE-ISWS');
		var_dump($reaction);
 
		if ($isws && $dmMode) {

			if ($isws === true) {
				$isws = "";
			}
			$name = $isws;

			$channel = getChannel($reaction);
			$channel->broadcastTyping();

			global $emoteArray;
			$eaNames = array_flip($emoteArray);
			$emojiname = $reaction['emoji']->name;
			$emojianame = null;
			if (isset($eaNames[$emojiname])) {
				$emojianame = $eaNames[$emojiname];
			}					
			
			$page = null;

			reactionAction($emojianame,$reaction,$name);
			return;
		}

		// $channel = $reaction['channel_id'];
		// if ($channel != '791286916334616576') {
			// return;
		// }

		// $rolearray = [
			// '844460031537446962' => '934836079163473951',
			// '844459869381328946' => '934836370927673386',
			// '844495918740275210' => '934836316481421392',
			// '844495918589673473' => '934836445053603881',
			// '733657344088473631' => '937374251508432959',
			// '844581570764341288' => '867119306391420929',
			// '844495918664384572' => '937378270503120967',
			// '945039418635481169' => '945039541415329792'
		// ];
		
		// $did = $reaction['user_id'];
		// $emoji = $reaction['emoji']->id;
		// $emojiname = $reaction['emoji']->name;
		// $guild = $discord->guilds->get('id', 788607168228229160);
		// $member = $guild->members->get('id', $did);
		// $role = $rolearray[$emoji];
		// try {
			// $member->removeRole($rolearray[$emoji])->done(function($return) use ($reaction,$guild,$role,$did,$emojiname,$emoji) {
				// $guild->channels->get('id', 798334904508219462)->sendMessage("<@$did> remove <:$emojiname:$emoji> <@&$role>");
			
			// // function () use ($reaction) {
				// // sendReply($reaction, "unbutter that role, joel! they don't want a {role-id-name}-craving!");
			// });
		// } catch(Exception $e) {
			// error_log(print_r($reaction,true));
			// error_log($e);
		// }			// sendReply($reaction, "giveitaway givitaway nowww $channel $did $emoji");
		// var_dump("REACTION REMOVE",$emoji,$did,$channel);
	});

	// Listen for messages.
	$discord->on(Event::MESSAGE_UPDATE, function (Message $data, Discord $discord, $oldData) {
		if ($data['channel_id'] == '791272018279923755' && isset($data['content'])) {
			print_r('BOT AUDIT: '.$data['content']);
			if (isset($data['embeds']) ) { 
				var_dump('BOT AUDIT - EMBED: ',$data['embeds']);
			}
		}
		// if ($author != '380675774794956800') {
			// return;
		// }

		if ($data->channel->guild_id != NULL && ( isset($data['author']) && $data['author']['id'] == $GLOBALS['myID']) ) { return; }
		
		if ($data['channel_id'] == '839614327002628107' && $data['author']['id'] == '475744554910351370') {
			// var_dump($data);
			if (!isset($data['embeds']) ) { return;	}
			eventMgr($oldData,$data);
		}
	});

	$discord->on(Event::MESSAGE_CREATE, function (Message $data, Discord $discord) {
	
	// var_dump($GLOBALS['RATELIMIT']);
	
	if (!isset($data['content'])) {
		var_dump($data);
		error_log("DATA CONTENT NOT SET");
		return;
	}
		
	$data['content'] = '.'.$data['content'];
	
		if ($data['channel_id'] == '791272018279923755' && isset($data['content'])) {
			print_r('BOT AUDIT: '.$data['content']);
			if (isset($data['embeds']) ) { 
				var_dump('BOT AUDIT - EMBED: ',$data['embeds']);
			}
		}

		global $nicks;
		global $memberids;

		$dmMode = 0;
		if(!isset($data['guild_id']) || $data['guild_id'] === NULL) { 
			if(isset($data['user_id'])) {
				$author = $data['user_id'];
			} else {
				$author = $data['author']['id'];
			}
			error_log("DM mode");
			$dmMode = 1;
		} else {
			error_log("Channel mode");
			$author = $data['author']['id'];
		}

		if ($author == $GLOBALS['otherID'] && $data['content'] != '.reset' && !startsWith($data['content'],'.shout') ) { error_log("other bot is talking. ignoring."); return; }

		if ($author != '380675774794956800' || (!$dmMode && $data['channel_id'] != '1370142425292738673' )) {	
			var_dump('NOT TV REMOTE CHANNEL');
			return;	
		}

		if ( $GLOBALS['commandPrefix'] != '.' ) { 
			$data['content'] = preg_replace('/^\./', 'CMD_PREFIX_IGNORE_THIS', $data['content']);
			$data['content'] = preg_replace('/^'.$GLOBALS['commandPrefix'].'/', '.', $data['content']);
		}

		$commpref = false;

		if (startsWith(strtolower($data['content']),$GLOBALS['commandPrefix']) && !startsWith(strtolower($data['content']),$GLOBALS['commandPrefix'].$GLOBALS['commandPrefix'])) {
			error_log("Command prefix detected. Command issued is: ".$data['content']."\n");
			if (file_exists("/tmp/maintenance.lock")) {
				sendReply($data, "Crystal Systems is in maintenance mode. Please try again in a few minutes.");
				return;
			}
			$commpref = true;
			//if ($dmMode == 1) { sendReply($data, "The ".$GLOBALS['commandPrefix']." is not needed in a DM. *tosses the ".$GLOBALS['commandPrefix']." out the window*"); }
		} else if (	$dmMode == 1 && $data['content'] != 'hello computer' ) { 
			$data['content'] = '.'.$data['content']; 
		}

		if ($dmMode == 1 && $author == $GLOBALS['myID'] ) { error_log("fiiiiiiii"); return; }

		if ($author !== $GLOBALS['otherID'] && $author !== $GLOBALS['myID'] && ($commpref || $dmMode) && isset($data['channel_id']) && !empty($data['channel_id'])) {
			$channel = getChannel($data);
			$channel->broadcastTyping();
		}

		if (($data['channel_id'] == '839614327002628107' && $data['author']['id'] == '475744554910351370' ) || strtolower($data['content']) === '.apollotest') {
			// var_dump($data);
			if (!isset($data['embeds']) ) { return;	}
			eventMgr('add',$data);
		}
		
// workspace dev and testing stubs
		if (strtolower($data['content']) === '.testws' || startsWith(strtolower($data['content']),'.workspace') || startsWith(strtolower($data['content']),'.wsout ') || startsWith(strtolower($data['content']),'.wfortune')) {
			if (isset($data['channel_id']) && !empty($data['channel_id'])) {
				$info = explode(' ',trim($data['content']));
				$wid = null;
				$new = false;
				$output = null;
				$name = '';
				
				if (isset($info[0]) && trim($info[0]) == '.wfortune') {
					$fortune = new Fortune();
					@$msg = $fortune->QuoteFromDir("fortune_data/");
					// $content = nl2br($msg);
					// print_r($data['author']['id'].'----------------------------------------------------');
					$content = str_replace(array("<br />", "<br/>", "<br>"), "\n", $msg);
					$remove = array("\r", "<p>", "</p>", "<h1>", "</h1>");
					$msg = str_replace($remove, ' ', $content);
					outputWorkspace($data,$msg);
					return;
				
				}
				if (isset($info[0]) && trim($info[0]) == '.wsout') {
					// if (isset($info[1]) && $info[1] !== 'reset') {
					$cmd = array_shift($info);
					$name = array_shift($info);
					$output = implode(' ',$info);
					var_dump("DATA",$output,$name);
					outputWorkspace($data,$output,$name);
				} else {
					if (isset($info[1]) && $info[1] == 'reset') {
						$wid = 'reset';
					}
					if (isset($info[1]) && $info[1] !== 'reset') {
						$name = $info[1];
						$new = true;
						$output = "TEEEEST8347489";
					}
				// initWorkspace($data,$wid = null, $new = false, $output = null,$name = '') {
					initWorkspace($data,$wid,$new,$output,$name);
				}
				// sendReply($data,print_r($data,true));
				return;
			}
		}

// word spelling checking and correction
		if (( startsWith(strtolower($data['content']),'.spell') || startsWith(strtolower($data['content']),'.spellcheck'))) {
			if (isset($data['channel_id']) && !empty($data['channel_id'])) {
				$message = explode(' ',strtolower($data['content']));
				$command = $message[0];
				if (!isset($message[1])) {
					$output = "Command usage: ``.$command <word>``";
					sendReply($data, $output);
					return;
				}
				array_shift($message);
				$query = implode(' ',$message);
				$result = spellCheck($query,true);
				// var_dump("spellcheck",$result);
				sendReply($data, $result);
			}
		}

// word defining and auto-correct		
		if (( startsWith(strtolower($data['content']),'.define'))) {
			if (isset($data['channel_id']) && !empty($data['channel_id'])) {
				$message = explode(' ',strtolower($data['content']));
				$command = $message[0];
				if (!isset($message[1])) {
					$output = "Command usage: ``.define <word>``";
					sendReply($data, $output);
					return;
				}
				array_shift($message);
				$query = implode(' ',$message);
				$res = json_decode(curl("https://api.dictionaryapi.dev/api/v2/entries/en/$query")['content'],true)[0];
				if ($res == NULL) {
					if (!$res = spellCheck($query,'array')) {
						sendReply($data, "Could not find **$query**!");
						return;
					}
					$res = json_decode(curl("https://api.dictionaryapi.dev/api/v2/entries/en/$res")['content'],true)[0];
					if ($res == NULL) {
						sendReply($data, "Could not find **$query**!");
						return;
					}
				}
				$word = $res['word'];
				$phonetic = $res['phonetic'];
				if ($phonetic) $phonetic = " **($phonetic)**";
				$definitions = niceList(numberfy_array(array_column($res['meanings'][0]['definitions'],'definition')),'','or').".";
				$result = "**".ucfirst($word)."**$phonetic, a word here which means $definitions";
				sendReply($data, $result);
			}
		}
		
// limit kodi function access 
		$kodichans = ['1370142425292738673'];

// emergency stop for player status refresher
		if ($author == '380675774794956800' && $data['content'] == '.stoploop' ) {
			if (isset($data['channel_id']) && !empty($data['channel_id'])) {
				global $stopLoop;
				$stopLoop = !$stopLoop;
				sendReply($data, print_r($stopLoop,true));
			}
		}
		
// stub for testing purposes
		if ($author == '380675774794956800' && startsWith(strtolower($data['content']), '.testing')) {
			if (isset($data['channel_id']) && !empty($data['channel_id'])) {
				// $channel = getChannel('1274001261976354886');
				// $channel = getChannel($data); // 1274001261976354886
				return;

				//$vidtimes = 
				// $lastStatusPlayer[0] = $vidtimes[0];
				// $lastStatusPlayer[2] = $vidtimes[1];
				// $lastStatusPlayer[3] = $vidtimes[2];
				// $lastStatusPlayer[4] = $vidtimes[3];
				// global $lastStatusPlayer; list($lastStatusPlayer[0],$lastStatusPlayer[2],$lastStatusPlayer[3],$lastStatusPlayer[4]) = getVidTimes(); $msg = playerStatus('useArray');
				// $msg = print_r($msg,true);
				// $msg .= "\n".print_r($lastStatusPlayer,true);
				// $msg .= "\n".print_r(playerStatus('useArray'),true);
				
				$msg = fruityLooper();
				$msg = print_r($msg,true);
				sendReply($data, $msg);

				return;

				$args = explode(' ',trim($data['content']));
				$cmd = ltrim(array_shift($args),'.');
				$arg  = implode(' ',$args);

				preg_match(
				"/(?:https?:\/\/)?(?:www\.)?(?:m\.)?(?:youtu\.be\/|youtube\.com\/(?:(?:watch)?\?(?:\S*&)?vi?=|(?:embed|v|vi|user|shorts)\/))([^?&\"'>\s]+)/",
				$arg,$matches);
				if (isset($matches[1])) {
					$vid = $matches[1];
				} else {
					$output = 'video id error';
					sendReply($data, $arg." ".$output);
					return;
				}




				sendReply($data, print_r($matches,true)."|".$arg);
				$vid = escapeshellarg($vid);
				shell_exec("php /home/shayne/vbot/crystalbot/fetchmp3.php '$vid' > /dev/null 2>/dev/null &");
				sendReply($data, $vid);
				
				return;
				
				global $kodi;
				file_put_contents('kodivar.json',json_encode($kodi, JSON_PRETTY_PRINT));
				return;
				
				$channel = $discord->getChannel('1274001261976354886');
				$members = array_keys($channel->members);
				$invc = ($author == '380675774794956800' || in_array($author,$members));
				var_dump($cmd,$arg,$author,$invc);
				
				
				file_put_contents('voice_debug.json',json_encode($members,JSON_PRETTY_PRINT).json_encode($channel,JSON_PRETTY_PRINT));

// $builder = MessageBuilder::new();
// Now we create an actionRow instance


// $select = SelectMenu::new()
	// ->addOption(Option::new('me?'))
	// ->addOption(Option::new('or me?'));

// $component = SelectMenu::new();
// $builder->addComponent($component);


// $action = ActionRow::new();
// // Let's create the button
// $button = Button::new(Button::STYLE_PRIMARY)->setLabel('Click me!')->setListener(function (Interaction $interaction) {
// // CODE...
// $interaction->respondWithMessage(MessageBuilder::new()->setContent("{$interaction->user} You clicked the button!"));
// }, $discord);
// $channel->sendMessage($builder)->then(function (Message $message) {
// echo "Message sent in the channel {$message->channel->id}";
// });

				// sendReply($data, json_encode($channel,JSON_PRETTY_PRINT));
				// $channel->sendMessage(MessageBuilder::new()
						// ->setContent('Hello, world!'));
						return;
					global $_Kodi;


$json = '{"jsonrpc":"2.0","method":"Playlist.GetPlaylists"}'; //,"params":[1,["audiostreams","currentaudiostream"]],"id":9}';
					$msg = json_encode($_Kodi->sendJson($json),JSON_PRETTY_PRINT);

				sendReply($data, $msg);
				
				return;


					$getPlayerItem = $_Kodi->getPlayerItem();
					$msg = json_encode($getPlayerItem,JSON_PRETTY_PRINT);
					$getPlayerItem = $_Kodi->getPlayerItem(1);
					$msg .= json_encode($getPlayerItem,JSON_PRETTY_PRINT);
				$channel = getChannel($data);
				$channel->sendMessage(MessageBuilder::new()
						->setContent('Hello, world!'));
			}
		}				

// Chatbot initiatior
		if ($author !== $GLOBALS['otherID'] && $author !== $GLOBALS['myID'] && ( strpos($data['content'],"<@".$GLOBALS['myID']."> ") !== false && strpos($data['content'],"<@".$GLOBALS['myID']."> ") >= 0 &&  strpos($data['content'],"<@".$GLOBALS['myID']."> ") < 3)) {
			if (isset($data['channel_id']) && !empty($data['channel_id'])) {
				
				//return;
				$args = explode(' ',trim($data['content']));
				var_dump($args,'df8df8d8f78sdf',strpos($data['content'],"<@".$GLOBALS['myID']."> "));
				$cmd = ltrim(array_shift($args),'.');
				$arg  = implode(' ',$args);

				$msg = chatBot($arg,$author);
				if (is_array($msg)) {
					
					foreach ($msg AS $k => $ms) {
						sendReply($data, $ms);
					}
					
					
				} else {
					sendReply($data, $msg);
				}
				return;
			}
		}

// kodi controls		
		if (strtolower($data['content']) === '.showlist') {
			if (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans)) {
				$msg = kodi('showlist',null,$data);
				sendReply($data, $msg);
			}
		}

		if (strtolower($data['content']) === '.playpause') {
			if (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans)) {
				$msg = kodi();
				sendReply($data, $msg);
				return;
			}
		}

		if (strtolower($data['content']) === '.playall') {
			if (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans)) {
				$msg = kodi('playall');
				sendReply($data, $msg);
				return;
			}
		}

		if (strtolower($data['content']) === '.previous') {
			// if (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans)) {
			if (($author == '380675774794956800' && $dmMode) || (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans))) {
				$msg = kodi('previous',null,$data);
				sendReply($data, $msg);
			}
		}

		if (strtolower($data['content']) === '.kodiaudio') {
			if (($author == '380675774794956800' && $dmMode) || (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans))) {
				$msg = kodi('audiostream',null,$data);
				sendReply($data, $msg);
			}
		}

		if ((strtolower($data['content']) === '.bookmarks' || startsWith(strtolower($data['content']),'.bookmark') || startsWith(strtolower($data['content']),'.unbookmark') || startsWith(strtolower($data['content']),'.resume'))) {
			if (($author == '380675774794956800' && $dmMode) || (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans))) {
				$cmds = ['bookmark','unbookmark','bookmarks','resume'];
				$cmdargs = ['unbookmark','resume'];
				$args = explode(' ',$data['content']);
				
				var_dump($args);
				$cmd = ltrim(array_shift($args),'.');
				$arg  = implode(' ',$args);
				var_dump($cmd);
				if (!in_array($cmd,$cmds)) {
					return;
				}
				if (($cmd == 'bookmark' && !empty($arg) && !is_numeric($arg)) || (in_array($cmd,$cmdargs) && (empty($arg) || !is_numeric($arg)))) {
					sendReply($data, "tp436: invalid selection");
					return;
				}
				
				$channel = $discord->getChannel('1274001261976354886');
				$members = $channel->members;
				// var_dump($members);
				$members = json_decode(json_encode($members),true);
				var_dump($members);
				$members = array_keys($members);
				var_dump($members);
				// $invc = in_array($author,$members);
				$invc = ($author == '380675774794956800' || in_array($author,$members));
				$args = [$arg,$author,$invc];
				
				$msg = kodi($cmd,$args,$data);
				sendReply($data, $msg);
				return;
			}
		}

		if ((strtolower($data['content']) === '.favs' || startsWith(strtolower($data['content']),'.fav ') || startsWith(strtolower($data['content']),'.unfav ') || startsWith(strtolower($data['content']),'.selfav '))) {
			if (($author == '380675774794956800' && $dmMode) || (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans))) {
				$cmds = ['fav','favs','bookmarks','selfav'];
				$cmdargs = ['unbookmark','resume'];
				$args = explode(' ',$data['content']);
				
				var_dump($args);
				$cmd = ltrim(array_shift($args),'.');
				$arg  = implode(' ',$args);
				var_dump($cmd);
				if (!in_array($cmd,$cmds)) {
					return;
				}
				if (($cmd == 'bookmark' && !empty($arg) && !is_numeric($arg)) || (in_array($cmd,$cmdargs) && (empty($arg) || !is_numeric($arg)))) {
					sendReply($data, "tp436: invalid selection");
					return;
				}
				
				$channel = $discord->getChannel('1274001261976354886');
				$members = $channel->members;
				$members = json_decode(json_encode($members),true);
				var_dump($members);
				$members = array_keys($members);
				var_dump($members);
				$invc = ($author == '380675774794956800' || in_array($author,$members));
				$args = [$arg,$author,$invc];
				
				$msg = kodi($cmd,$args,$data);
				sendReply($data, $msg);
				return;
			}
		}

		if (strtolower($data['content']) === '.next') {
			// if (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans)) {
			if (($author == '380675774794956800' && $dmMode) || (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans))) {
				$msg = kodi('next',null,$data);
				sendReply($data, $msg);
			}
		}

		if (strtolower(trim($data['content'])) === '.kodi') {
			if (($author == '380675774794956800' && $dmMode) || (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans))) {
				// initWorkspace($data,$wid = null, $new = false, $output = null,$name = '') {
				initWorkspace($data,'reset',false,kodi('showlist',null,"returnarray"));
				initWorkspace($data,'reset',false,kodi('seek'),'player');
				//sendReply($data, $msg);
			}
		}

		if (strtolower($data['content']) === '.back') {
			if (($author == '380675774794956800' && $dmMode) || (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans))) {
				$msg = kodi("back",null,$data);
				sendReply($data, $msg);
			}
		}

		if (strtolower($data['content']) === '.stop') {
			if (($author == '380675774794956800' && $dmMode) || (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans))) {
				$msg = kodi("stop");
				sendReply($data, $msg);
			}
		}

		if ($author == '380675774794956800' && strtolower($data['content']) === '.showhist') {
			if (($author == '380675774794956800' && $dmMode) || (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans))) {
				$msg = kodi("showhist");
				sendReply($data, $msg);
			}
		}

		if (strtolower($data['content']) === '.movies') {
			if (($author == '380675774794956800' && $dmMode) || (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans))) {
				$msg = kodi("movies",null,$data);
				sendReply($data, $msg);
			}
		}

		if (strtolower($data['content']) === '.shows') {
			if (($author == '380675774794956800' && $dmMode) || (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans))) {
				$msg = kodi("shows",null,$data);
				sendReply($data, $msg);
			}
		}

		if (strtolower($data['content']) === '.sources') {
			if (($author == '380675774794956800' && $dmMode) || (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans))) {
				$msg = kodi("sources",null,$data);
				sendReply($data, $msg);
			}
		}

		if (startsWith(strtolower($data['content']),'.playlist')) {
			if (($author == '380675774794956800' && $dmMode) || (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans))) {
				// $arg  = ['time',["+25 seconds"]];
				$msg = kodi("getplaylist",null,$data);
				sendReply($data, $msg);
			}
		}

		if (startsWith(strtolower($data['content']),'.skipintro')) {
			if (($author == '380675774794956800' && $dmMode) || (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans))) {
				$arg  = ['time',["+25 seconds"]];
				$msg = kodi("seek",$arg,$data);

			}
		}
		
		if (startsWith(strtolower($data['content']),'.seek')) {
			if (($author == '380675774794956800' && $dmMode) || (isset($data['channel_id']) && !empty($data['channel_id']) )) {
				$args = explode(' ',$data['content']);
				
				if (isset($args[1])) {
					array_shift($args);
					$args = implode(' ',$args);
					$regex = '/[+,-]?[0-9:]+\s[a-z,A-Z]+/';
					
					if (preg_match($regex,$args,$matches)) {
						// $arg  = intval($args[1]);
						$arg  = ['time',$matches];
						var_dump($matches);
					} else if ( preg_match('/[0-9]+%/',$args,$matches)) {
						$arg  = ['pcnt',intval($args)];
						var_dump($matches);
					} else {
						sendReply($data, "invalid entry");
						return;
					}
				} else {
					$arg = ['show',null];
				}
				// $args[1];
				if (!in_array($data['channel_id'],$kodichans) && $arg[0] !== 'show') {
					return;
				}
				// $arg  = intval($args[1]);
				$msg = kodi("seek",$arg,$data);
				sendReply($data, $msg);
			}
		}

		if ((startsWith(strtolower($data['content']),'.playfrom') || startsWith(strtolower($data['content']),'.queuefrom') || startsWith(strtolower($data['content']),'.queue') || startsWith(strtolower($data['content']),'.unqueue'))) {
			if (($author == '380675774794956800' && $dmMode) || (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans))) {
				$args = explode(' ',$data['content']);
				// if (trim($args[1]) == 'random') {
					// $arg = 'random';
				// } else {
				$arg = null;
				$qp = 'queue';
				$un = $from = '';
				if (isset($args[0]) && $args[0] == '.playfrom') {
					$qp = 'play';
					$from = 'from';
					global $kodi;
					
					if (!isset($args[1]) || !is_numeric($args[1])	|| !$sel = intval($args[1]) || !isset($kodi['menu'][$sel]) 
						|| !isset($kodi['menu'][$sel][0]) || !isset($kodi['menu'][$sel+1]) || $kodi['menu'][$sel][0] !== 'file') {
						sendReply($data, '5756z: invalid selection');
						return;
					}
					$arg  = intval($args[1]);
					// kodi('stop','',$data);
					//kodi('unqueue','all',null);
					kodi('select',$arg,null);
					usleep(900000);
					kodi('queuefrom',$arg+1,$data);
					return;
					
				} else if (isset($args[0]) && $args[0] == '.unqueue') {
					if (!isset($args[1])) {
						sendReply($data, '756k: invalid selection');
						return;
					}
					// $from = 'from';
					$un = 'un';
					if ($args[1] == 'all') {
						$arg = 'all';
					} else {						
						$arg  = intval($args[1]);
					}
				} else if (isset($args[0]) && $args[0] == '.queuefrom') {
					if (!isset($args[1])) {
						sendReply($data, '1948s: invalid selection');
						return;
					}
					$from = 'from';
					$arg  = intval($args[1]);
				} else if (isset($args[1])) {
					if ($args[1] == 'all') {
						$arg = 'all';
					} else if ($args[1] == 'clear') {
						$arg = 'clear';
					} else {
						$arg  = intval($args[1]);
					}
				}
				
				$msg = kodi($un.$qp.$from,$arg,$data);
				sendReply($data, $msg);
			}
		}

		if ((startsWith(strtolower($data['content']),'.select') || startsWith(strtolower($data['content']),'.play') || startsWith(strtolower($data['content']),'.continue'))) {
			if (($author == '380675774794956800' && $dmMode) || (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans))) {

				$cmds = ['play','continue','select'];
				//$cmdargs = ['unbookmark','resume'];
				$args = explode(' ',$data['content']);
				
				var_dump($args);
				$cmd = ltrim(array_shift($args),'.');
				$arg  = trim(implode(' ',$args));
				if (!$arg && $arg !== "0") {
					sendReply($data, "8968e: Invalid selection `$arg`");
					return;
				}
				
				var_dump($cmd);
				if (!in_array($cmd,$cmds)) {
					return;
				}
				//if ($cmd == 'play') { $cmd = 'select'; }

				// $args = explode(' ',$data['content']);
				if ($arg == 'random') {
					$arg = 'random';
				} else {
					$arg  = intval($arg);
				}
				
				$msg = kodi($cmd,$arg,$data);
				sendReply($data, $msg);
				return;
			}
		}

		if (startsWith(strtolower($data['content']),'.yt')) {
			if (($author == '380675774794956800' && $dmMode) || (isset($data['channel_id']) && !empty($data['channel_id']) && in_array($data['channel_id'],$kodichans))) {
				$ytcmds = ['ytplay','ytsearch','ytp','yts'];
				$args = explode(' ',$data['content']);
				
				var_dump($args);
				$ytcmd = ltrim(array_shift($args),'.');
				var_dump($ytcmd);
				if (!in_array($ytcmd,$ytcmds)) {
					return;
				}
				
				$arg  = implode(' ',$args);
				
				$msg = kodi($ytcmd,$arg,$data);
				sendReply($data, $msg);
			}
		}

// Youtube download
		if (startsWith(strtolower($data['content']), '.dlyt ')) {
			if (isset($data['channel_id']) && !empty($data['channel_id'])) {

				$args = explode(' ',trim($data['content']));
				$cmd = ltrim(array_shift($args),'.');
				$arg  = implode(' ',$args);

				preg_match(
				"/(?:https?:\/\/)?(?:www\.)?(?:m\.)?(?:youtu\.be\/|youtube\.com\/(?:(?:watch)?\?(?:\S*&)?vi?=|(?:embed|v|vi|user|shorts)\/))([^?&\"'>\s]+)/",
				$arg,$matches);
				if (isset($matches[1]) && validVideoId($matches[1])) {
					$vid = $matches[1];
				} else {
					$output = 'video id error';
					sendReply($data, $arg." ".$output);
					return;
				}

				$escauthor = escapeshellarg($author);
				$vid = escapeshellarg($vid);
				sendReply('380675774794956800', "$escauthor $vid");
				chdir("/home/shayne/vbot/crystalbot/");
				
				shell_exec("php /home/shayne/vbot/crystalbot/fetchmp3.php '$vid' '$escauthor' >> fetchmp3.log 2>> fetchmp3.log &");
				// sendReply($data, $vid);

				// $msg = youtubedl($arg);
				sendReply($data, "Processing your request. Please hold...");
				return;
			}
		}

// Initiate DM with user (fix for discord DM glitch)
		if (!$dmMode && strtolower($data['content']) === '.juiceme') {
			if (isset($data['channel_id']) && !empty($data['channel_id'])) {
				sendReply($data, "https://tenor.com/view/alone-gif-8541857");
				sendMsg($author,"I am a kitty cat and I dance dance dance!");
				return;
			}
		}

		if (strtolower($data['content']) === '.fortune') {
			if (isset($data['channel_id']) && !empty($data['channel_id'])) {
				$fortune = new Fortune();
				@$msg = $fortune->QuoteFromDir("fortune_data/");
				// $content = nl2br($msg);
				// print_r($data['author']['id'].'----------------------------------------------------');
				$content = str_replace(array("<br />", "<br/>", "<br>"), "\n", $msg);
				$remove = array("\r", "<p>", "</p>", "<h1>", "</h1>");
				$msg = str_replace($remove, ' ', $content);
				sendReply($data, $msg);
				// sendMsg('362816681837592586', $msg);
			}
		}

		if (( strtolower($data['content']) === '.headpats' || strtolower($data['content']) === '.pet' || strtolower($data['content']) === '.joy' || strtolower($data['content']) === '.givepets')) {
			if (isset($data['channel_id']) && !empty($data['channel_id'])) {
				sendReply($data, "https://www.crystalshouts.com/grahaears.gif");
				return;
			}
		}

		if (( strtolower($data['content']) === '.dance' || strtolower($data['content']) === '.shimmy')) {
			if (isset($data['channel_id']) && !empty($data['channel_id'])) {
				sendReply($data, "https://www.eorzeanshouts.com/grahashimmy.gif");
				return;
			}
		}

		if (( strtolower($data['content']) === '.feedthetia' || strtolower($data['content']) === '.givetaco' || strtolower($data['content']) === '.feedgraha')) {
			if (isset($data['channel_id']) && !empty($data['channel_id'])) {
				$user = getUser($author);
				sendReply($data, "https://www.crystalshouts.com/graha.gif \nOm nom nom!");
				return;
			}
		}

		if (strtolower($data['content']) === '.poke') {
			if (isset($data['channel_id']) && !empty($data['channel_id'])) {
				// $user = getUser($author);
				sendReply($data, "https://www.crystalshouts.com/grahatu.gif");
			}
		}

		if (startsWith(strtolower($data['content']),'.tzconvert')) {
			if (isset($data['channel_id']) && !empty($data['channel_id'])) {
				$user = getUser($author);
				$tz = explode(' ',str_ireplace(' AM  ','AM ',str_replace(' PM ','PM ',str_replace('  ',' ',str_ireplace(['to ','.tzconvert '],'',strtoupper($data['content']))))));
				if (count($tz) < 3) {
					$time = $tz[0];
					$fromtz= $user['timezone'];
					$totz = $tz[1];
				} else {
					$time = $tz[0];
					$fromtz = $tz[1];
					$totz = $tz[2];
				}


				// if (!$fromtz = explode(' ',$tz[0])[1]) {
					// $tz[0] = $user['timezone'];
				// } else {
					// $tz[0] = $fromtz;
				// }
			
				// $fromtz = $tz[0];
			
					// $totz = $user['timezone'];
				// } else {
					// $totz = $tz[1];
				// }
				if (!date_default_timezone_set($totz)) {
					// date_default_timezone_set($user['timezone']);
					date_default_timezone_set( timezone_name_from_abbr($totz));						
				}
				
				sendReply($data,  print_r($tz,true)."$time $fromtz is ".date('h:i A',strtotime($time.' '.$fromtz))." $totz");
			}
		}

		if (( strtolower($data['content']) === 'hello computer' || strtolower($data['content']) === '.help')) {
			if (isset($data['channel_id']) && !empty($data['channel_id'])) {
				$output = getUser($author);
				$defaulteurl = "(None)";
				if ($output['status'] && $output['defaulteurl'] != '') {
					$defaulteurl = $output['defaulteurl'];
				}
				$message = "Use '{blank}' to set a blank value. eg: `.shoutmsg <my-venue-eurl> {blank}`\nGet twitch url: `.twitch <eurl>` or set twitch url: `.twitch <eurl> twitch url`\nGet shout message: `.shoutmsg <eurl>` or  set shout message: `.shoutmsg <eurl> shout message` (discord formatting **will** work, too!)\nRandom fortune cookie message: `.fortune`\n
										Create a new post with `.new Post Title`. Default eurl will switch to new post. You can modify the default eurl with `.default <eurl>`\n
										Your current default eurl is: $defaulteurl\n
										List posts you own with `.myposts`, New posts: `.newposts`, Posts open today: `.opentoday`, and search for posts with `.search`\n
										You can modify your posts with commands such as:\n
										`.addpics`, `.application`, `.validate`, `.site`, `.discord`, `.stream`, `.instagram`,`.twitter`, `.shoutmsg`, `.desc`, `.tags`, and `.note`
										";

				sendReply($data, $message);
			}
		}

		if ((strtolower($data['content']) === '.botprod' || strtolower($data['content']) === '.botproud' || strtolower($data['content']) === '.pushprod')) {
			if ($author == '380675774794956800') {
				sendReply($data, "Pushing Dev Changes....");
				shell_exec("cd /home/shayne/vbot/crystalbot/ && cp csbot.php csbot.php.bak && cp csbot-dev.php csbot.php");
				sleep(1);
				sendReply($data, ".reset");
				if ($GLOBALS['filePrefix'] != 'DEV-') { $data['content'] = '.reset'; }
			} else { sendReply('380675774794956800', "bad auth from $author <@$author>"); sendReply($data,"https://www.crystalshouts.com/noauth.jpg");	}
		}

		if ((strtolower($data['content']) === '.reboot' || strtolower($data['content']) === '.reset')) {
			if ($author == '380675774794956800') {// || $author = $GLOBALS['otherID']) {
				$channelid = $data['channel_id'];
				$guildid = $data['guild_id'];
				if ($dmMode == 1) { 
					$guildid = "DM"; $channelid = $author; 
					shell_exec("php /home/shayne/vbot/crystalbot/sendmsg.php ".$GLOBALS['filePrefix']."$author Restarting... &");
				} else {
					shell_exec("php /home/shayne/vbot/crystalbot/sendmsg.php ".$GLOBALS['filePrefix'].$guildid."#".$channelid." Restarting... &");
				}
				if (isset($channelid)) { $channelid = $guildid.':'.$data['channel_id']; } 
				file_put_contents($GLOBALS['filePrefix'].'lastchan',$channelid);
				sendReply($data, "Restarting...");
				sleep(3);
				exit;
			} else { sendReply('380675774794956800', "bad auth from $author <@$author>"); sendReply($data,"https://www.crystalshouts.com/noauth.jpg");	}
		}

		if (startsWith(strtolower($data['content']),'.happydance')) {
			if (isset($data['channel_id']) && !empty($data['channel_id'])) {
				$hdances = [
					"https://www.crystalshouts.com/happydance.gif",
					"https://www.crystalshouts.com/startrek-dance.gif",
					"https://www.eorzeanshouts.com/grahashimmy.gif",
					"https://c.tenor.com/LHKzT8b-tfcAAAAj/%D8%B5%D8%A8%D9%8A%D9%8A%D8%B4%D8%B7%D8%AD-dance.gif",
					"https://c.tenor.com/2vqE2AJ-6ngAAAAC/woo-seinfeld.gif",
					"https://c.tenor.com/xd2_RUy41sAAAAAC/cute-cat.gif",
					"https://c.tenor.com/2pGUcoYwuhMAAAAC/happy-birthday.gif",
					"https://c.tenor.com/pXnGfrFQgF8AAAAC/dance-emoji.gif"
				];
				sendReply($data, $hdances[array_rand($hdances, 1)]);
			}
		}
		
	});
});

$discord->run();