<?php

require('SourceQuery/bootstrap.php');

use xPaw\SourceQuery\SourceQuery;

const RCON_PASS_CVAR_NAME = 'rcon_password';

enum EDialogCommand: string
{
	case Query	= 'motd_dialogquery';
	case Custom	= 'motd_dialogcmd'; // Specific to the dialog class
	case Rewind	= 'motd_rewinddialog'; // Rewind stack by one level (for 'Back' button)
}

$gIP = &$_REQUEST['ip'];
$gPort = $gUserId = $gSecret = 'undefined'; // Default for safe command sending from builder's JS

// Numeric params.
// Not strictly required for script execution (yet), so that builder works for provided sample dialog forms.
sscanf(@$_REQUEST['port'], '%i', $gPort);
sscanf(@$_REQUEST['userid'], '%i', $gUserId);
sscanf(@$_REQUEST['secret'], '%i', $gSecret);

// Disable cache, for convenience.
// no-store: prevent disk/memory cache entry.
// no-cache: force validation logic off cached entries (belt-and-suspenders for IE).
// must-revalidate: prevent stale reuse edge cases.
// max-age=0: kill heuristic freshness.
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

// Legacy IE compatibility layer
header("Pragma: no-cache");
header("Expires: 0");

header("Connection: close"); // Prevent keep-alive socket reuse weirdness

function sendDialogCommand(EDialogCommand $command, $args = NULL)
{
	global $gIP, $gPort, $gUserId, $gSecret;
	$separators = " \n\r\t";

	if (!is_int($gUserId)) {
		throw (new Exception('Invalid UserID given', 400));
	} else if (!is_int($gSecret)) {
		throw (new Exception('Invalid secret given', 400));
	} else if (!($config = parse_ini_file(dirname(__FILE__, 2) . '/cfg/motd_dialog_cfg.ini'))) {
		throw (new Exception('Missing config file/s', 500));
	} else if (!($rconPass = file_get_contents(@$config['rconPassFile']))) {
		throw (new Exception('Unable to load secret file', 500));
	} else if (strcasecmp(strtok($rconPass, $separators), RCON_PASS_CVAR_NAME) != 0) {
		throw (new Exception('Malformed RCON password setting', 500));
	}

	$gIP ??= $config['serverIP'] ?? $_SERVER['SERVER_ADDR'];
	$gPort = is_int($gPort) ? $gPort : (int)@$config['serverPort'];
	$rconPass = trim(strtok(''), $separators . '"'); // Get actual password (via next strtok string)

	$query = new SourceQuery();

	try {
		$query->Connect($gIP, $gPort);
		$query->SetRconPassword($rconPass);
		return $query->Rcon("$command->value $gUserId $gSecret" . $args);
	} catch (Throwable $t) {
		throw (new Exception($t->getMessage(), 500, $t)); // Re-throw to send correct error code (HTTP)
	} finally {
		$query->Disconnect();
	}
}
