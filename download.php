<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       download.php
 * \ingroup    einvoice
 * \brief      Download the original file or the embedded XML of an inbox entry
 */

if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

dol_include_once('/einvoice/class/einvoiceinbox.class.php');

if (!isModEnabled('einvoice') || !$user->hasRight('einvoice', 'read')) {
	accessforbidden();
}

$doc = new EInvoiceInbox($db);
if ($doc->fetch(GETPOSTINT('id')) <= 0) {
	httponly_accessforbidden('Not found', 404);
}

$type = GETPOST('type', 'aZ09');
if ($type === 'att') {
	// Supporting document from inside the e-invoice
	dol_include_once('/einvoice/class/einvoiceattachments.class.php');
	$att = $doc->getAttachments(true);
	$n = GETPOSTINT('n');
	if (!isset($att['files'][$n]) || $att['files'][$n]['error'] !== '' || $att['files'][$n]['data'] === null) {
		httponly_accessforbidden('File not found', 404);
	}
	$f = $att['files'][$n];
	$mime = EInvoiceAttachments::TYPES[$f['type']][0];
	$name = dol_sanitizeFileName($f['name']);
	$inline = in_array($f['type'], array('pdf', 'png', 'jpg'), true);
	top_httphead($mime);
	header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="'.str_replace('"', '', $name).'"; filename*=UTF-8\'\''.rawurlencode($name));
	header('Content-Length: '.strlen($f['data']));
	header('X-Content-Type-Options: nosniff');
	if ($f['type'] !== 'pdf') {
		header("Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox");
	}
	print $f['data'];
	$db->close();
	exit;
}
$type = $type === 'xml' ? 'xml' : 'file';
$path = $doc->getFullPath($type);
if (!$path || !is_readable($path)) {
	httponly_accessforbidden('File not found', 404);
}

if ($type === 'xml') {
	$name = pathinfo($doc->filename, PATHINFO_FILENAME).'.xml';
	$mime = 'application/xml';
} else {
	$name = $doc->filename;
	$mime = $doc->format === 'zugferd' || $doc->format === 'pdf' ? 'application/pdf' : (in_array($doc->format, array('cii', 'ubl'), true) ? 'application/xml' : 'application/octet-stream');
}
$name = dol_sanitizeFileName($name);
$inline = ($mime === 'application/pdf');

top_httphead($mime);
header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="'.str_replace('"', '', $name).'"; filename*=UTF-8\'\''.rawurlencode($name));
header('Content-Length: '.filesize($path));
header('X-Content-Type-Options: nosniff');
if (!$inline) {
	header("Content-Security-Policy: default-src 'none'; sandbox");
}
readfile($path);
$db->close();
