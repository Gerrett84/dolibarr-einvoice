<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/einvoiceparser.class.php
 * \ingroup    einvoice
 * \brief      Detects e-invoices (CII, UBL, hybrid PDF) and reads their header data
 */

/**
 * Stateless helper to classify a file and read the invoice header.
 *
 * No external libraries: XML via DOM/XPath, embedded XML of a PDF/A-3
 * (ZUGFeRD/Factur-X) is located by scanning for /Type /EmbeddedFile streams.
 */
class EInvoiceParser
{
	const NS_CII_RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
	const NS_CII_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
	const NS_CII_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';
	const NS_UBL_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
	const NS_UBL_CREDITNOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';
	const NS_UBL_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
	const NS_UBL_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

	/** Max size of a file we try to analyse (bytes) */
	const MAX_FILE_SIZE = 52428800;

	/**
	 * Analyse a file.
	 *
	 * @param string $content  Raw file content
	 * @param string $filename Original file name
	 * @param string $mime     Declared content type (may be empty)
	 * @return array{kind:string,format:string,xml:?string,data:array<string,mixed>,error:string}
	 *         kind: einvoice | pdf | other
	 *         format: cii | ubl | zugferd | pdf | other
	 */
	public static function analyze($content, $filename, $mime = '')
	{
		$result = array('kind' => 'other', 'format' => 'other', 'xml' => null, 'data' => array(), 'error' => '');

		if (strlen($content) > self::MAX_FILE_SIZE) {
			$result['error'] = 'File too large';
			return $result;
		}

		$ext = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
		$mime = strtolower((string) $mime);

		if (strncmp($content, '%PDF', 4) === 0) {
			$result['kind'] = 'pdf';
			$result['format'] = 'pdf';
			$found = self::extractXmlFromPdf($content);
			if ($found['error']) {
				$result['error'] = $found['error'];
			}
			if ($found['xml'] !== null) {
				$parsed = self::parseXml($found['xml']);
				if ($parsed['syntax']) {
					$result['kind'] = 'einvoice';
					$result['format'] = 'zugferd';
					$result['xml'] = $found['xml'];
					$result['data'] = $parsed['data'];
					$result['error'] = $parsed['error'];
				}
			}
			return $result;
		}

		$looksXml = in_array($ext, array('xml'), true) || strpos($mime, 'xml') !== false
			|| preg_match('/^\s*(\xEF\xBB\xBF)?\s*</', substr($content, 0, 64));
		if ($looksXml) {
			$parsed = self::parseXml($content);
			if ($parsed['syntax']) {
				$result['kind'] = 'einvoice';
				$result['format'] = $parsed['syntax'];
				$result['xml'] = $content;
				$result['data'] = $parsed['data'];
				$result['error'] = $parsed['error'];
			} elseif ($parsed['error']) {
				$result['error'] = $parsed['error'];
			}
		}

		return $result;
	}

	/**
	 * Find the e-invoice XML embedded in a PDF (ZUGFeRD / Factur-X / XRechnung in PDF/A-3).
	 *
	 * @param string $pdf Raw PDF
	 * @return array{xml:?string,error:string}
	 */
	public static function extractXmlFromPdf($pdf)
	{
		$out = array('xml' => null, 'error' => '');

		if (preg_match('/\/Encrypt\s+\d+\s+\d+\s+R/', $pdf)) {
			$out['error'] = 'PDF is encrypted, embedded files cannot be read';
			return $out;
		}

		$candidates = array();
		$offset = 0;
		// Each embedded file is a stream object: << /Type /EmbeddedFile ... >> stream ... endstream
		while (preg_match('/\/Type\s*\/EmbeddedFile(?![A-Za-z])/', $pdf, $m, PREG_OFFSET_CAPTURE, $offset)) {
			$pos = $m[0][1];
			$offset = $pos + 1;

			// Start of the object dictionary: last "obj" before the match
			$objStart = strrpos(substr($pdf, max(0, $pos - 4096), min($pos, 4096)), 'obj');
			if ($objStart === false) {
				continue;
			}
			$objStart += max(0, $pos - 4096) + 3;

			$streamPos = strpos($pdf, 'stream', $pos);
			if ($streamPos === false) {
				continue;
			}
			$dict = substr($pdf, $objStart, $streamPos - $objStart);
			if (strpos($dict, 'endobj') !== false) {
				continue; // not a stream object
			}

			$dataStart = $streamPos + 6;
			if (substr($pdf, $dataStart, 2) === "\r\n") {
				$dataStart += 2;
			} elseif ($pdf[$dataStart] === "\n" || $pdf[$dataStart] === "\r") {
				$dataStart += 1;
			}

			$data = null;
			if (preg_match('/\/Length\s+(\d+)(?!\s+\d+\s+R)/', $dict, $lm)) {
				$len = (int) $lm[1];
				if ($len > 0 && $dataStart + $len <= strlen($pdf)) {
					$data = substr($pdf, $dataStart, $len);
				}
			}
			if ($data === null) {
				$end = strpos($pdf, 'endstream', $dataStart);
				if ($end === false) {
					continue;
				}
				$data = rtrim(substr($pdf, $dataStart, $end - $dataStart), "\r\n");
			}

			if (preg_match('/\/Filter\s*\[?\s*\/FlateDecode/', $dict)) {
				$decoded = @gzuncompress($data);
				if ($decoded === false) {
					$decoded = @gzinflate($data);
				}
				if ($decoded === false && function_exists('zlib_decode')) {
					$decoded = @zlib_decode($data);
				}
				if ($decoded === false) {
					continue;
				}
				$data = $decoded;
			} elseif (preg_match('/\/Filter/', $dict)) {
				continue; // other filters are not used for XML attachments in practice
			}

			$candidates[] = $data;
		}

		foreach ($candidates as $data) {
			if (preg_match('/^\s*(\xEF\xBB\xBF)?\s*</', substr($data, 0, 64))
				&& (strpos($data, 'CrossIndustryInvoice') !== false || strpos($data, self::NS_UBL_INVOICE) !== false || strpos($data, self::NS_UBL_CREDITNOTE) !== false)) {
				$out['xml'] = $data;
				return $out;
			}
		}

		return $out;
	}

	/**
	 * Load XML safely (no DTD, no network, no entity expansion).
	 *
	 * @param string $xml   XML string
	 * @param string $error Error message (out)
	 * @return DOMDocument|null
	 */
	public static function loadXml($xml, &$error)
	{
		$error = '';
		$xml = preg_replace('/^\xEF\xBB\xBF/', '', (string) $xml);
		if (preg_match('/<!DOCTYPE/i', substr($xml, 0, 4096))) {
			$error = 'XML with DOCTYPE is not accepted';
			return null;
		}
		$prev = libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
		if (!$ok) {
			$err = libxml_get_last_error();
			$error = 'Invalid XML'.($err ? ': '.trim($err->message) : '');
			libxml_clear_errors();
			libxml_use_internal_errors($prev);
			return null;
		}
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		return $dom;
	}

	/**
	 * Detect syntax and read header fields.
	 *
	 * @param string $xml XML string
	 * @return array{syntax:string,data:array<string,mixed>,error:string}  syntax: cii | ubl | '' (not an e-invoice)
	 */
	public static function parseXml($xml)
	{
		$res = array('syntax' => '', 'data' => array(), 'error' => '');
		$error = '';
		$dom = self::loadXml($xml, $error);
		if (!$dom) {
			$res['error'] = $error;
			return $res;
		}
		$root = $dom->documentElement;
		if (!$root) {
			return $res;
		}

		if ($root->namespaceURI === self::NS_CII_RSM && $root->localName === 'CrossIndustryInvoice') {
			$res['syntax'] = 'cii';
			$res['data'] = self::readCii($dom);
		} elseif (($root->namespaceURI === self::NS_UBL_INVOICE && $root->localName === 'Invoice')
			|| ($root->namespaceURI === self::NS_UBL_CREDITNOTE && $root->localName === 'CreditNote')) {
			$res['syntax'] = 'ubl';
			$res['data'] = self::readUbl($dom);
		}
		return $res;
	}

	/**
	 * First matching node value or ''.
	 *
	 * @param DOMXPath     $xp      XPath
	 * @param string       $query   Query
	 * @param DOMNode|null $context Context node
	 * @return string
	 */
	private static function v(DOMXPath $xp, $query, $context = null)
	{
		$nodes = $context ? $xp->query($query, $context) : $xp->query($query);
		if ($nodes && $nodes->length > 0) {
			return trim($nodes->item(0)->textContent);
		}
		return '';
	}

	/**
	 * Convert amount string to float or null.
	 *
	 * @param string $s Amount
	 * @return float|null
	 */
	private static function num($s)
	{
		if ($s === '' || !is_numeric($s)) {
			return null;
		}
		return (float) $s;
	}

	/**
	 * Read CII header data.
	 *
	 * @param DOMDocument $dom Document
	 * @return array<string,mixed>
	 */
	private static function readCii(DOMDocument $dom)
	{
		$xp = new DOMXPath($dom);
		$xp->registerNamespace('rsm', self::NS_CII_RSM);
		$xp->registerNamespace('ram', self::NS_CII_RAM);
		$xp->registerNamespace('udt', self::NS_CII_UDT);

		$tx = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction';
		$agr = $tx.'/ram:ApplicableHeaderTradeAgreement';
		$set = $tx.'/ram:ApplicableHeaderTradeSettlement';
		$sum = $set.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation';

		$date = self::v($xp, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString');

		return array(
			'profile' => self::v($xp, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID'),
			'doc_type' => self::v($xp, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode'),
			'invoice_ref' => self::v($xp, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID'),
			'invoice_date' => self::normDate($date),
			'seller_name' => self::v($xp, $agr.'/ram:SellerTradeParty/ram:Name'),
			'seller_vat' => self::v($xp, $agr.'/ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]'),
			'seller_taxnumber' => self::v($xp, $agr.'/ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="FC"]'),
			'buyer_name' => self::v($xp, $agr.'/ram:BuyerTradeParty/ram:Name'),
			'buyer_reference' => self::v($xp, $agr.'/ram:BuyerReference'),
			'currency' => self::v($xp, $set.'/ram:InvoiceCurrencyCode'),
			'total_ht' => self::num(self::v($xp, $sum.'/ram:TaxBasisTotalAmount')),
			'total_ttc' => self::num(self::v($xp, $sum.'/ram:GrandTotalAmount')),
			'amount_due' => self::num(self::v($xp, $sum.'/ram:DuePayableAmount')),
		);
	}

	/**
	 * Read UBL header data (Invoice or CreditNote).
	 *
	 * @param DOMDocument $dom Document
	 * @return array<string,mixed>
	 */
	private static function readUbl(DOMDocument $dom)
	{
		$xp = new DOMXPath($dom);
		$xp->registerNamespace('cbc', self::NS_UBL_CBC);
		$xp->registerNamespace('cac', self::NS_UBL_CAC);
		$root = $dom->documentElement;

		$seller = $xp->query('cac:AccountingSupplierParty/cac:Party', $root)->item(0);
		$buyer = $xp->query('cac:AccountingCustomerParty/cac:Party', $root)->item(0);

		$sellerName = '';
		$sellerVat = '';
		$sellerTax = '';
		if ($seller) {
			$sellerName = self::v($xp, 'cac:PartyLegalEntity/cbc:RegistrationName', $seller);
			if ($sellerName === '') {
				$sellerName = self::v($xp, 'cac:PartyName/cbc:Name', $seller);
			}
			$sellerVat = self::v($xp, 'cac:PartyTaxScheme[cac:TaxScheme/cbc:ID="VAT"]/cbc:CompanyID', $seller);
			$sellerTax = self::v($xp, 'cac:PartyTaxScheme[not(cac:TaxScheme/cbc:ID="VAT")]/cbc:CompanyID', $seller);
		}
		$buyerName = '';
		if ($buyer) {
			$buyerName = self::v($xp, 'cac:PartyLegalEntity/cbc:RegistrationName', $buyer);
			if ($buyerName === '') {
				$buyerName = self::v($xp, 'cac:PartyName/cbc:Name', $buyer);
			}
		}

		$type = self::v($xp, 'cbc:InvoiceTypeCode', $root);
		if ($type === '') {
			$type = self::v($xp, 'cbc:CreditNoteTypeCode', $root);
		}

		return array(
			'profile' => self::v($xp, 'cbc:CustomizationID', $root),
			'doc_type' => $type,
			'invoice_ref' => self::v($xp, 'cbc:ID', $root),
			'invoice_date' => self::normDate(self::v($xp, 'cbc:IssueDate', $root)),
			'seller_name' => $sellerName,
			'seller_vat' => $sellerVat,
			'seller_taxnumber' => $sellerTax,
			'buyer_name' => $buyerName,
			'buyer_reference' => self::v($xp, 'cbc:BuyerReference', $root),
			'currency' => self::v($xp, 'cbc:DocumentCurrencyCode', $root),
			'total_ht' => self::num(self::v($xp, 'cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount', $root)),
			'total_ttc' => self::num(self::v($xp, 'cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount', $root)),
			'amount_due' => self::num(self::v($xp, 'cac:LegalMonetaryTotal/cbc:PayableAmount', $root)),
		);
	}

	/**
	 * Normalise YYYYMMDD or YYYY-MM-DD to YYYY-MM-DD.
	 *
	 * @param string $s Date string
	 * @return string Empty if not parseable
	 */
	private static function normDate($s)
	{
		$s = trim($s);
		if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
			return $m[1].'-'.$m[2].'-'.$m[3];
		}
		return '';
	}
}
