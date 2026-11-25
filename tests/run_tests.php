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

// ---- Profiles without lines (BASIC WL / MINIMUM)
$basicwl = preg_replace('#<ram:IncludedSupplyChainTradeLineItem>.*?</ram:IncludedSupplyChainTradeLineItem>\s*#s', '', file_get_contents($fx.'cii-invoice.xml'));
$d = EInvoiceDetails::read($basicwl);
check('basicwl summary', var_export($d['summary'], true).'|'.count($d['lines']).'|'.$d['lines'][0]['total'].'|'.$d['lines'][0]['vat_rate'].'|'.count($d['allowances']), 'true|1|100|19|0');
check('basicwl arithmetic ok', EInvoiceDetails::check($d), array());
$minimum = preg_replace('#<ram:ApplicableTradeTax>.*?</ram:ApplicableTradeTax>\s*#s', '', $basicwl);
$d = EInvoiceDetails::read($minimum);
check('minimum summary from totals', count($d['lines']).'|'.$d['lines'][0]['total'].'|'.$d['lines'][0]['vat_rate'], '1|100|19');

// ---- Buyer VAT and IBANs
$d = EInvoiceDetails::read(file_get_contents($fx.'cii-invoice.xml'));
check('cii ibans', $d['ibans'], array('DE02120300000000202051'));
$withBuyerVat = str_replace('<ram:BuyerTradeParty><ram:Name>Beispiel Automatiktüren GmbH</ram:Name></ram:BuyerTradeParty>', '<ram:BuyerTradeParty><ram:Name>Beispiel Automatiktüren GmbH</ram:Name><ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">DE 111 222 333</ram:ID></ram:SpecifiedTaxRegistration></ram:BuyerTradeParty>', file_get_contents($fx.'cii-invoice.xml'));
check('cii buyer vat', EInvoiceDetails::read($withBuyerVat)['buyer_vat'], 'DE 111 222 333');
$ublBuyer = str_replace('<cac:AccountingCustomerParty><cac:Party>', '<cac:AccountingCustomerParty><cac:Party><cac:PartyTaxScheme><cbc:CompanyID>DE111222333</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>', file_get_contents($fx.'ubl-creditnote.xml'));
$d = EInvoiceDetails::read($ublBuyer);
check('ubl buyer vat / iban', $d['buyer_vat'].'|'.implode(',', $d['ibans']), 'DE111222333|AT611904300234573201');

// ---- Checks (static helpers)
require_once __DIR__.'/../class/einvoicechecks.class.php';
check('iban valid', EInvoiceChecks::ibanValid('DE02 1203 0000 0000 2020 51'), true);
check('iban valid AT', EInvoiceChecks::ibanValid('AT611904300234573201'), true);
check('iban bad check digit', EInvoiceChecks::ibanValid('DE03120300000000202051'), false);
check('iban DE wrong length', EInvoiceChecks::ibanValid('DE0212030000000020205'), false);
check('vat norm', EInvoiceChecks::normVat('de 123.456-789'), 'DE123456789');
check('same name legal form', EInvoiceChecks::sameName('Beispiel Automatiktüren GmbH', 'BEISPIEL AUTOMATIKTUEREN'), true);
check('same name with co kg', EInvoiceChecks::sameName('Kurina GmbH & Co. KG', 'Kurina GmbH'), true);
check('different name', EInvoiceChecks::sameName('Kunde GmbH', 'Beispiel Automatiktüren GmbH'), false);

// ---- Attachments
require_once __DIR__.'/../class/einvoiceattachments.class.php';
$png = "\x89PNG\r\n\x1A\n".str_repeat("\0", 32);
$csv = "Datum;Stunden\n2026-11-20;3\n";
$exe = "MZ\x90\0".str_repeat("\0", 60);
$ciiAtt = str_replace('</ram:ApplicableHeaderTradeAgreement>',
	'<ram:AdditionalReferencedDocument><ram:IssuerAssignedID>STD-1</ram:IssuerAssignedID><ram:TypeCode>916</ram:TypeCode><ram:Name>Stundennachweis</ram:Name>'
	.'<ram:AttachmentBinaryObject mimeCode="text/csv" filename="../../stunden.csv">'.base64_encode($csv).'</ram:AttachmentBinaryObject></ram:AdditionalReferencedDocument>'
	.'<ram:AdditionalReferencedDocument><ram:IssuerAssignedID>FOTO</ram:IssuerAssignedID><ram:TypeCode>916</ram:TypeCode>'
	.'<ram:AttachmentBinaryObject mimeCode="application/pdf" filename="foto.pdf">'.chunk_split(base64_encode($png), 76, "\n").'</ram:AttachmentBinaryObject></ram:AdditionalReferencedDocument>'
	.'<ram:AdditionalReferencedDocument><ram:IssuerAssignedID>X</ram:IssuerAssignedID><ram:TypeCode>916</ram:TypeCode>'
	.'<ram:AttachmentBinaryObject mimeCode="application/pdf" filename="rechnung.pdf.exe">'.base64_encode($exe).'</ram:AttachmentBinaryObject></ram:AdditionalReferencedDocument>'
	.'<ram:AdditionalReferencedDocument><ram:IssuerAssignedID>LS-7</ram:IssuerAssignedID><ram:URIID>https://portal.example/ls/7</ram:URIID><ram:TypeCode>916</ram:TypeCode><ram:Name>Lieferschein</ram:Name></ram:AdditionalReferencedDocument>'
	.'</ram:ApplicableHeaderTradeAgreement>', file_get_contents($fx.'cii-invoice.xml'));
$a = EInvoiceAttachments::read($ciiAtt, null, true);
check('cii att count', count($a['files']).'|'.count($a['links']), '3|1');
check('cii att csv (path stripped)', $a['files'][0]['name'].'|'.$a['files'][0]['type'].'|'.$a['files'][0]['description'].'|'.($a['files'][0]['data'] === $csv ? 'data' : 'nodata'), 'stunden.csv|csv|Stundennachweis|data');
check('cii att type from content, not mime', $a['files'][1]['name'].'|'.$a['files'][1]['type'].'|'.$a['files'][1]['size'], 'foto.png|png|'.strlen($png));
check('cii att exe refused', $a['files'][2]['error'].'|'.var_export($a['files'][2]['data'], true).'|'.$a['files'][2]['name'], 'unsupported|NULL|rechnung.pdf.bin');
check('cii link', $a['links'][0]['uri'].'|'.$a['links'][0]['description'], 'https://portal.example/ls/7|Lieferschein');
check('cii att still parses as invoice', EInvoiceDetails::read($ciiAtt)['grand_total'], 119.0);

$ublAtt = str_replace('<cac:AccountingSupplierParty>',
	'<cac:AdditionalDocumentReference><cbc:ID>A1</cbc:ID><cbc:DocumentDescription>Aufmaß</cbc:DocumentDescription><cac:Attachment><cbc:EmbeddedDocumentBinaryObject mimeCode="application/pdf" filename="aufmass.pdf">'.base64_encode("%PDF-1.4\n%%EOF").'</cbc:EmbeddedDocumentBinaryObject></cac:Attachment></cac:AdditionalDocumentReference>'
	.'<cac:AdditionalDocumentReference><cbc:ID>A2</cbc:ID><cac:Attachment><cbc:EmbeddedDocumentBinaryObject mimeCode="application/pdf" filename="aufmass.pdf">'.base64_encode("%PDF-1.7\n%%EOF").'</cbc:EmbeddedDocumentBinaryObject></cac:Attachment></cac:AdditionalDocumentReference>'
	.'<cac:AdditionalDocumentReference><cbc:ID>web</cbc:ID><cac:Attachment><cac:ExternalReference><cbc:URI>https://x.example/doc</cbc:URI></cac:ExternalReference></cac:Attachment></cac:AdditionalDocumentReference>'
	.'<cac:AccountingSupplierParty>', file_get_contents($fx.'ubl-creditnote.xml'));
$a = EInvoiceAttachments::read($ublAtt, null, false);
check('ubl att names unique', $a['files'][0]['name'].'|'.$a['files'][1]['name'].'|'.$a['files'][0]['type'].'|'.$a['files'][0]['description'], 'aufmass.pdf|aufmass-2.pdf|pdf|Aufmaß');
check('ubl att without data', array_key_exists('data', $a['files'][0]), false);
check('ubl link', $a['links'][0]['uri'], 'https://x.example/doc');

// PDF with invoice XML + an extra embedded PNG (compressed) + named via /UF (UTF-16)
$xmlInv = file_get_contents($fx.'cii-invoice.xml');
$pngz = gzcompress($png);
$ufName = '<FEFF'.strtoupper(bin2hex(mb_convert_encoding('Übersicht.png', 'UTF-16BE', 'UTF-8'))).'>';
$pdf = "%PDF-1.7\n"
	."5 0 obj\n<< /Type /Filespec /F (factur-x.xml) /UF (factur-x.xml) /EF << /F 6 0 R /UF 6 0 R >> /AFRelationship /Data >>\nendobj\n"
	."6 0 obj\n<< /Type /EmbeddedFile /Subtype /text#2Fxml /Length ".strlen($xmlInv)." >>\nstream\n".$xmlInv."\nendstream\nendobj\n"
	."7 0 obj\n<< /Type /Filespec /F (scan.png) /UF ".$ufName." /EF << /F 8 0 R >> >>\nendobj\n"
	."8 0 obj\n<< /Type /EmbeddedFile /Subtype /image#2Fpng /Filter /FlateDecode /Length ".strlen($pngz)." >>\nstream\n".$pngz."\nendstream\nendobj\n"
	."%%EOF\n";
check('pdf xml still found', EInvoiceParser::extractXmlFromPdf($pdf)['xml'] === $xmlInv, true);
$a = EInvoiceAttachments::read(null, $pdf, true);
check('pdf extra file', count($a['files']).'|'.$a['files'][0]['name'].'|'.$a['files'][0]['type'].'|'.($a['files'][0]['data'] === $png ? 'data' : 'nodata'), '1|Übersicht.png|png|data');
$pdfBadLen = str_replace('/Length '.strlen($pngz).' ', '/Length '.(strlen($pngz) - 5).' ', $pdf);
check('pdf wrong /Length tolerated', EInvoiceAttachments::read(null, $pdfBadLen, true)['files'][0]['data'] === $png, true);
$empty = str_replace(base64_encode($csv), '', $ciiAtt);
check('empty attachment', EInvoiceAttachments::read($empty)['files'][0]['error'], 'empty');
check('internal #ef reference hidden', count(EInvoiceAttachments::read(str_replace('https://portal.example/ls/7', '#ef=foto.png', $ciiAtt))['links']), 0);
$pdfOctet = str_replace('/Subtype /image#2Fpng', '/Subtype /application#2Foctet-stream', $pdf);
check('pdf subtype octet-stream', EInvoiceAttachments::read(null, $pdfOctet, true)['files'][0]['data'] === $png, true);
check('real zugferd fixture: no extra files', count(EInvoiceAttachments::read(null, file_get_contents($fx.'zugferd.pdf'))['files']), 0);

echo "\n".($n - $fail)."/$n passed\n";
exit($fail ? 1 : 0);
