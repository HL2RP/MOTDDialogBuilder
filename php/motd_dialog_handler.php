<?php

require('motd_dialog_base.php');

function exitWithHTTPCode(int $code, string $text)
{
	http_response_code($code);
	exit($text);
}

$args = trim(@$_POST['args']);
$command = EDialogCommand::tryFrom(@$_POST['command']);

if (is_null($command)) {
	exitWithHTTPCode(400, 'Missing/invalid input command');
} else if (strlen($args) > 0) {
	$args = ' ' . base64_encode($args); // Fit for final command
}

try {
	echo sendDialogCommand($command, $args);
} catch (Throwable $t) {
	error_log($t);
	exitWithHTTPCode($t->getCode(), $t->getMessage());
}
