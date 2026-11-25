<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Tiny scripted IMAP server for tests (plain TCP, one connection).
 * Usage: php tests/fake_imap_server.php <port> <logfile>
 */

$port = isset($argv[1]) ? (int) $argv[1] : 10143;
$log = isset($argv[2]) ? $argv[2] : '/tmp/fake_imap.log';
$fx = __DIR__.'/fixtures/';

$messages = array(
	101 => file_get_contents($fx.'mail-forwarded.eml'),
	102 => "From: a@b.example\r\nSubject: Newsletter\r\nMessage-ID: <news@b>\r\nContent-Type: text/plain\r\n\r\nNo attachment\r\n",
);
$folders = array('INBOX', 'Deleted Items');

$srv = stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $errstr);
if (!$srv) {
	fwrite(STDERR, "cannot listen: $errstr\n");
	exit(1);
}
file_put_contents($log, '');
$c = stream_socket_accept($srv, 30);
fwrite($c, "* OK fake IMAP ready\r\n");

/**
 * @param resource $c   Socket
 * @param string   $s   Data
 * @return void
 */
function out($c, $s)
{
	fwrite($c, $s);
}

while (($line = fgets($c)) !== false) {
	file_put_contents($log, $line, FILE_APPEND);
	$line = rtrim($line, "\r\n");
	if (!preg_match('/^(\S+) (.*)$/', $line, $m)) {
		continue;
	}
	$tag = $m[1];
	$cmd = $m[2];

	if (preg_match('/^LOGIN "user@example\.com" "p\\\\"w"$/', $cmd)) {
		out($c, "$tag OK LOGIN completed\r\n");
	} elseif (stripos($cmd, 'LOGIN') === 0) {
		out($c, "$tag NO LOGIN failed\r\n");
	} elseif ($cmd === 'CAPABILITY') {
		out($c, "* CAPABILITY IMAP4rev1 MOVE UIDPLUS SPECIAL-USE\r\n$tag OK done\r\n");
	} elseif (preg_match('/^SELECT "(.*)"$/', $cmd, $s)) {
		out($c, "* 2 EXISTS\r\n* OK [UIDVALIDITY 777] ok\r\n$tag OK [READ-WRITE] SELECT completed\r\n");
	} elseif (preg_match('/^UID SEARCH SINCE /', $cmd)) {
		out($c, "* SEARCH 101 102\r\n$tag OK SEARCH completed\r\n");
	} elseif (preg_match('/^UID FETCH ([\d,]+) \(UID RFC822\.SIZE INTERNALDATE BODY\.PEEK\[HEADER\.FIELDS/', $cmd, $f)) {
		$seq = 1;
		foreach (explode(',', $f[1]) as $uid) {
			$raw = $messages[(int) $uid];
			$hdr = substr($raw, 0, strpos($raw, "\r\n\r\n") + 4);
			// keep only requested headers roughly
			out($c, "* ".$seq++." FETCH (UID $uid RFC822.SIZE ".strlen($raw)." INTERNALDATE \"24-Nov-2026 10:15:00 +0100\" BODY[HEADER.FIELDS (MESSAGE-ID FROM SUBJECT DATE CONTENT-TYPE)] {".strlen($hdr)."}\r\n".$hdr.")\r\n");
		}
		out($c, "$tag OK FETCH completed\r\n");
	} elseif (preg_match('/^UID FETCH (\d+) \(UID RFC822\.SIZE BODY\.PEEK\[\]\)$/', $cmd, $f)) {
		$raw = $messages[(int) $f[1]];
		out($c, "* 1 FETCH (UID ".$f[1]." RFC822.SIZE ".strlen($raw)." BODY[] {".strlen($raw)."}\r\n".$raw.")\r\n$tag OK FETCH completed\r\n");
	} elseif (preg_match('/^UID STORE (\d+) \+FLAGS\.SILENT \(\\\\Seen\)$/', $cmd)) {
		out($c, "$tag OK STORE completed\r\n");
	} elseif (preg_match('/^LIST "" "\*"$/', $cmd)) {
		out($c, "* LIST (\\HasNoChildren) \"/\" INBOX\r\n* LIST (\\HasNoChildren \\Trash) \"/\" \"Deleted Items\"\r\n$tag OK LIST completed\r\n");
	} elseif (preg_match('/^LIST "" "(.*)"$/', $cmd, $l)) {
		if (in_array($l[1], $folders, true)) {
			out($c, "* LIST (\\HasNoChildren) \"/\" \"".$l[1]."\"\r\n");
		}
		out($c, "$tag OK LIST completed\r\n");
	} elseif (preg_match('/^CREATE "(.*)"$/', $cmd, $l)) {
		$folders[] = $l[1];
		out($c, "$tag OK CREATE completed\r\n");
	} elseif (preg_match('/^SUBSCRIBE /', $cmd)) {
		out($c, "$tag OK SUBSCRIBE completed\r\n");
	} elseif (preg_match('/^UID MOVE (\d+) "(.*)"$/', $cmd)) {
		out($c, "* OK [COPYUID 1 101 1] moved\r\n* 1 EXPUNGE\r\n$tag OK MOVE completed\r\n");
	} elseif ($cmd === 'LOGOUT') {
		out($c, "* BYE\r\n$tag OK LOGOUT completed\r\n");
		break;
	} else {
		out($c, "$tag BAD unknown command\r\n");
	}
}
fclose($c);
