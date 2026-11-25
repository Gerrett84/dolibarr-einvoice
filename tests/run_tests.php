<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Standalone tests for parser and MIME handling (no Dolibarr needed).
 * Run: php tests/run_tests.php
 */

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
	throw new ErrorException($str, 0, $no, $file, $line);
});

require __DIR__.'/../class/einvoiceparser.class.php';
require __DIR__.'/../class/einvoicemime.class.php';

$fx = __DIR__.'/fixtures/';
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

// CII
$r = EInvoiceParser::analyze(file_get_contents($fx.'cii-invoice.xml'), 'cii-invoice.xml', 'application/xml');
check('cii kind', $r['kind'], 'einvoice');
check('cii format', $r['format'], 'cii');
check('cii ref', $r['data']['invoice_ref'], 'RE-2026-0815');
check('cii date', $r['data']['invoice_date'], '2026-11-20');
check('cii seller', $r['data']['seller_name'], 'Muster Großhandel GmbH');
check('cii vat', $r['data']['seller_vat'], 'DE123456789');
check('cii address', $r['data']['seller_address'], "Hauptstraße 1\nHinterhaus");
check('cii zip/town/country', $r['data']['seller_zip'].' '.$r['data']['seller_town'].' '.$r['data']['seller_country'], '10115 Berlin DE');
check('cii email', $r['data']['seller_email'], 'buchhaltung@muster.example');
check('cii phone', $r['data']['seller_phone'], '+49 30 123456');
check('cii iban/bic', $r['data']['seller_iban'].' '.$r['data']['seller_bic'], 'DE02 1203 0000 0000 2020 51 BYLADEM1001');
check('cii taxno', $r['data']['seller_taxnumber'], '201/123/45678');
check('cii buyer ref', $r['data']['buyer_reference'], '04011000-12345-34');
check('cii total', $r['data']['total_ttc'], 119.0);
check('cii ht', $r['data']['total_ht'], 100.0);
check('cii profile', strpos($r['data']['profile'], 'xrechnung_3.0') !== false, true);

// UBL credit note
$r = EInvoiceParser::analyze(file_get_contents($fx.'ubl-creditnote.xml'), 'x.xml');
check('ubl format', $r['format'], 'ubl');
check('ubl type', $r['data']['doc_type'], '381');
check('ubl seller', $r['data']['seller_name'], 'Beispiel Lieferant AG');
check('ubl vat', $r['data']['seller_vat'], 'DE 987 654 321');
check('ubl address', $r['data']['seller_address'].'|'.$r['data']['seller_zip'].' '.$r['data']['seller_town'].' '.$r['data']['seller_country'], 'Industrieweg 7|1010 Wien AT');
check('ubl email (endpoint)', $r['data']['seller_email'], 'rechnung@lieferant.example');
check('ubl iban', $r['data']['seller_iban'], 'AT611904300234573201');
check('ubl total', $r['data']['total_ttc'], 59.5);
check('ubl date', $r['data']['invoice_date'], '2026-11-21');

// XXE is refused
$r = EInvoiceParser::analyze(file_get_contents($fx.'xxe.xml'), 'xxe.xml');
check('xxe kind', $r['kind'], 'other');
check('xxe error', strpos($r['error'], 'DOCTYPE') !== false, true);

// Hybrid PDF
foreach (array('zugferd.pdf', 'zugferd-indirect-length.pdf') as $f) {
	$r = EInvoiceParser::analyze(file_get_contents($fx.$f), $f, 'application/pdf');
	check("$f kind", $r['kind'], 'einvoice');
	check("$f format", $r['format'], 'zugferd');
	check("$f ref", isset($r['data']['invoice_ref']) ? $r['data']['invoice_ref'] : null, 'RE-2026-0815');
}
$r = EInvoiceParser::analyze(file_get_contents($fx.'plain.pdf'), 'plain.pdf', 'application/pdf');
check('plain pdf kind', $r['kind'], 'pdf');
check('plain pdf xml', $r['xml'], null);

// Random text is not an e-invoice
$r = EInvoiceParser::analyze('<html><body>hi</body></html>', 'a.xml');
check('html kind', $r['kind'], 'other');

// MIME: forwarded mail with nested parts
$m = EInvoiceMime::parse(file_get_contents($fx.'mail-forwarded.eml'));
check('mime subject', $m['subject'], 'WG: Rechnung Äpril');
check('mime from', $m['from'], 'Jürgen Weiterleiter <jw@example.org>');
check('mime msgid', $m['message_id'], '<outer-123@example.org>');
check('mime date', $m['date'], strtotime('2026-11-24 09:15:00 UTC'));
check('mime attachment count', count($m['attachments']), 3);
$names = array_map(function ($a) {
	return $a['filename'];
}, $m['attachments']);
check('mime names', $names, array('xrechnung.xml', 'Rechnung Müller.pdf', 'Lieferschein Teil 2.pdf'));
$kinds = array_map(function ($a) {
	$r = EInvoiceParser::analyze($a['data'], $a['filename'], $a['type']);
	return $r['format'];
}, $m['attachments']);
check('mime formats', $kinds, array('ubl', 'zugferd', 'pdf'));

// Header with continuation lines / RFC 2231 param parsing
$p = EInvoiceMime::parseParams('attachment; filename="a \"b\".pdf"; size=12');
check('quoted param', $p['params']['filename'], 'a "b".pdf');

// ---- Details (lines, allowances, payment)
require_once __DIR__.'/../class/einvoicedetails.class.php';
$d = EInvoiceDetails::read(file_get_contents($fx.'cii-invoice.xml'));
check('cii details syntax', $d['syntax'], 'cii');
check('cii lines', count($d['lines']), 2);
check('cii line1 name/ref', $d['lines'][0]['name'].'|'.$d['lines'][0]['seller_ref'].'|'.$d['lines'][0]['description'], 'Türband|A-100|verzinkt');
check('cii line1 qty/price (basis qty, rebate via total)', $d['lines'][0]['qty'].'|'.round($d['lines'][0]['unit_price'], 4).'|'.$d['lines'][0]['total'], '2|55|110');
check('cii line2 unit/vat', $d['lines'][1]['unit'].'|'.$d['lines'][1]['vat_rate'].'|'.$d['lines'][1]['unit_price'], 'HUR|19|3');
check('cii allowance', count($d['allowances']).'|'.var_export($d['allowances'][0]['charge'], true).'|'.$d['allowances'][0]['amount'].'|'.$d['allowances'][0]['reason'], '1|false|19|Treuerabatt');
check('cii due/terms/means', $d['due_date'].'|'.$d['payment_terms'].'|'.$d['payment_means'], '2026-12-04|14 Tage netto|58');
check('cii totals', $d['line_total'].'|'.$d['tax_basis'].'|'.$d['tax_total'].'|'.$d['grand_total'], '119|100|19|119');
check('cii arithmetic ok', EInvoiceDetails::check($d), array());

$d = EInvoiceDetails::read(file_get_contents($fx.'ubl-creditnote.xml'));
check('ubl details lines', count($d['lines']).'|'.$d['lines'][0]['name'].'|'.$d['lines'][0]['seller_ref'].'|'.$d['lines'][0]['qty'].'|'.$d['lines'][0]['unit_price'], '1|Rücknahme Schließer|TS93|1|50');
check('ubl billing ref', $d['billing_ref'], 'RE-0042');
check('ubl taxes', count($d['taxes']).'|'.$d['taxes'][0]['amount'].'|'.$d['tax_total'], '1|9.5|9.5');
check('ubl arithmetic ok', EInvoiceDetails::check($d), array());

$bad = str_replace('<ram:GrandTotalAmount>119.00</ram:GrandTotalAmount>', '<ram:GrandTotalAmount>120.00</ram:GrandTotalAmount>', file_get_contents($fx.'cii-invoice.xml'));
check('arithmetic problem detected', count(EInvoiceDetails::check(EInvoiceDetails::read($bad))), 1);
check('details on xxe rejected', EInvoiceDetails::read(file_get_contents($fx.'xxe.xml'))['syntax'], '');

echo "\n".($n - $fail)."/$n passed\n";
exit($fail ? 1 : 0);
