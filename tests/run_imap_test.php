<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * IMAP connector against tests/fake_imap_server.php.
 * Run: php tests/fake_imap_server.php 10143 /tmp/imap.log & sleep 1; php tests/run_imap_test.php 10143 /tmp/imap.log
 */

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
	throw new ErrorException($str, 0, $no, $file, $line);
});

require __DIR__.'/../class/einvoiceconnectorimap.class.php';
require __DIR__.'/../class/einvoicemime.class.php';
require __DIR__.'/../class/einvoiceparser.class.php';

$port = (int) $argv[1];
$log = $argv[2];
$fail = 0;
$n = 0;

/**
 * @param string $label    Label
 * @param mixed  $actual   Actual
 * @param mixed  $expected Expected
 * @return void
 */
function check($label, $actual, $expected)
{
	global $fail, $n;
	$n++;
	if ($actual !== $expected) {
		$fail++;
		echo "FAIL $label: expected ".var_export($expected, true).", got ".var_export($actual, true)."\n";
	} else {
		echo "ok   $label\n";
	}
}

$c = new EInvoiceConnectorImap();
$c->host = '127.0.0.1';
$c->port = $port;
$c->security = 'none';
$c->authMode = 'password';
$c->login = 'user@example.com';
$c->password = 'p"w';
$c->mailbox = 'user@example.com';
$c->timeout = 5;

check('connect', $c->connect(), true);
check('connect error', $c->error, '');

$list = $c->listMessages(strtotime('2026-11-01'), 10);
check('list is array', is_array($list), true);
check('list count (newsletter without attachment skipped)', count($list), 1);
check('list uid', $list[0]['id'], '101');
check('list key', $list[0]['key'], '<outer-123@example.org>');
check('list subject', $list[0]['subject'], 'WG: Rechnung Äpril');

$raw = $c->fetchRaw('101');
check('raw length', strlen($raw), strlen(file_get_contents(__DIR__.'/fixtures/mail-forwarded.eml')));
$m = EInvoiceMime::parse($raw);
check('attachments', count($m['attachments']), 3);

check('mark read', $c->markRead('101'), true);
check('move', $c->moveTo('101', 'E-Rechnung/Verarbeitet'), true);
check('trash', $c->trash('101'), true);
$c->close();

usleep(200000);
$cmds = array_map(function ($l) {
	return preg_replace('/^\S+ /', '', rtrim($l));
}, file($log));
check('login quoted', in_array('LOGIN "user@example.com" "p\\"w"', $cmds, true), true);
check('peek (does not set \\Seen)', (bool) preg_grep('/BODY\.PEEK\[\]/', $cmds), true);
check('created folder', in_array('CREATE "E-Rechnung/Verarbeitet"', $cmds, true), true);
check('moved with MOVE', in_array('UID MOVE 101 "E-Rechnung/Verarbeitet"', $cmds, true), true);
check('trash detected', in_array('UID MOVE 101 "Deleted Items"', $cmds, true), true);

echo "\n".($n - $fail)."/$n passed\n";
exit($fail ? 1 : 0);
