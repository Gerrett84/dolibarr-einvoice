<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/einvoiceattachments.class.php
 * \ingroup    einvoice
 * \brief      Supporting documents inside an e-invoice (BG-24): embedded in the XML or as extra files of a ZUGFeRD PDF
 */

require_once __DIR__.'/einvoiceparser.class.php';

/**
 * Reads attached documents. The type is taken from the content (magic bytes), never from
 * the declared MIME code, and only the types allowed by XRechnung are offered for download.
 */
class EInvoiceAttachments
{
	/** Max. size of one attachment in bytes */
	const MAX_SIZE = 26214400;

	/** Allowed types: key => array(mime, extension) */
	const TYPES = array(
		'pdf' => array('application/pdf', 'pdf'),
		'png' => array('image/png', 'png'),
		'jpg' => array('image/jpeg', 'jpg'),
		'csv' => array('text/csv', 'csv'),
		'xlsx' => array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'),
		'ods' => array('application/vnd.oasis.opendocument.spreadsheet', 'ods'),
	);

	/**
	 * All attachments and external references of an e-invoice.
	 *
	 * Attachment keys: name (sanitised, unique), id (BT-122), description (BT-123), type (key of TYPES or ''),
	 * size, data (raw bytes, only if $withData), source (xml|pdf), error ('' | unsupported | toolarge | broken).
	 * Reference keys: id, description, uri.
	 *
	 * @param string|null $xml      Invoice XML
	 * @param string|null $pdf      Raw PDF (ZUGFeRD) or null
	 * @param bool        $withData Decode and return the content
	 * @return array{files:array<int,array<string,mixed>>,links:array<int,array<string,string>>}
	 */
	public static function read($xml, $pdf = null, $withData = false)
	{
		$res = array('files' => array(), 'links' => array());
		if ($xml !== null && $xml !== '') {
			self::fromXml($xml, $withData, $res);
		}
		if ($pdf !== null && $pdf !== '') {
			foreach (self::fromPdf($pdf) as $f) {
				// Same file also embedded in the XML: show it once
				if ($f['hash'] !== '' && in_array($f['hash'], array_column($res['files'], 'hash'), true)) {
					continue;
				}
				if (!$withData) {
					unset($f['data']);
				}
				$res['files'][] = $f;
			}
		}
		foreach ($res['files'] as $i => $f) {
			unset($res['files'][$i]['hash']);
		}
		// Unique file names
		$seen = array();
		foreach ($res['files'] as $i => $f) {
			$name = $f['name'];
			$base = pathinfo($name, PATHINFO_FILENAME);
			$ext = pathinfo($name, PATHINFO_EXTENSION);
			$n = 2;
			while (isset($seen[strtolower($name)])) {
				$name = $base.'-'.$n++.($ext !== '' ? '.'.$ext : '');
			}
			$seen[strtolower($name)] = 1;
			$res['files'][$i]['name'] = $name;
		}
		return $res;
	}

	/**
	 * CII ram:AdditionalReferencedDocument / UBL cac:AdditionalDocumentReference.
	 *
	 * @param string                $xml      XML
	 * @param bool                  $withData Decode content
	 * @param array<string,mixed>   $res      Result (in/out)
	 * @return void
	 */
	private static function fromXml($xml, $withData, &$res)
	{
		$error = '';
		$dom = EInvoiceParser::loadXml($xml, $error);
		if (!$dom || !$dom->documentElement) {
			return;
		}
		$xp = new DOMXPath($dom);
		$xp->registerNamespace('rsm', EInvoiceParser::NS_CII_RSM);
		$xp->registerNamespace('ram', EInvoiceParser::NS_CII_RAM);
		$xp->registerNamespace('cbc', EInvoiceParser::NS_UBL_CBC);
		$xp->registerNamespace('cac', EInvoiceParser::NS_UBL_CAC);
		$root = $dom->documentElement;

		if ($root->localName === 'CrossIndustryInvoice') {
			$q = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:AdditionalReferencedDocument';
			$f = array('id' => 'ram:IssuerAssignedID', 'desc' => 'ram:Name', 'bin' => 'ram:AttachmentBinaryObject', 'uri' => 'ram:URIID');
		} else {
			$q = 'cac:AdditionalDocumentReference';
			$f = array('id' => 'cbc:ID', 'desc' => 'cbc:DocumentDescription', 'bin' => 'cac:Attachment/cbc:EmbeddedDocumentBinaryObject', 'uri' => 'cac:Attachment/cac:ExternalReference/cbc:URI');
		}
		$nodes = $root->localName === 'CrossIndustryInvoice' ? $xp->query($q) : $xp->query($q, $root);
		foreach ($nodes as $ref) {
			$id = self::v($xp, $f['id'], $ref);
			$desc = self::v($xp, $f['desc'], $ref);
			$bin = $xp->query($f['bin'], $ref)->item(0);
			if ($bin instanceof DOMElement) {
				$b64 = preg_replace('/\s+/', '', $bin->textContent);
				$size = (int) floor(strlen($b64) * 3 / 4) - substr_count(substr($b64, -2), '=');
				$head = base64_decode(substr($b64, 0, 1024), true);
				$item = array(
					'name' => '', 'id' => $id, 'description' => $desc, 'type' => '', 'size' => max(0, $size),
					'source' => 'xml', 'error' => '', 'declared' => $bin->getAttribute('filename'),
				);
				if ($size <= 0) {
					$item['error'] = 'empty';
				} elseif ($head === false) {
					$item['error'] = 'broken';
				} elseif ($size > self::MAX_SIZE) {
					$item['error'] = 'toolarge';
				}
				$data = null;
				if ($item['error'] === '') {
					// Full content is needed to tell CSV from binary and XLSX from ODS
					$data = base64_decode($b64, true);
					if ($data === false) {
						$item['error'] = 'broken';
						$data = null;
					} else {
						$item['size'] = strlen($data);
						$item['type'] = self::detect($data, $item['declared']);
						if ($item['type'] === '') {
							$item['error'] = 'unsupported';
						}
					}
				}
				$item['hash'] = $data !== null ? sha1($data) : '';
				$item['name'] = self::fileName($item['declared'] !== '' ? $item['declared'] : ($id !== '' ? $id : 'anhang'), $item['type'], count($res['files']) + 1);
				unset($item['declared']);
				if ($withData) {
					$item['data'] = $item['error'] === '' ? $data : null;
				}
				$res['files'][] = $item;
				continue;
			}
			$uri = self::v($xp, $f['uri'], $ref);
			if ($uri !== '' && $uri[0] !== '#') {
				$res['links'][] = array('id' => $id, 'description' => $desc, 'uri' => $uri);
			}
		}
	}

	/**
	 * Embedded files of a PDF other than the invoice XML itself.
	 *
	 * @param string $pdf Raw PDF
	 * @return array<int,array<string,mixed>>
	 */
	public static function fromPdf($pdf)
	{
		$out = array();
		if (preg_match('/\/Encrypt\s+\d+\s+\d+\s+R/', $pdf)) {
			return $out;
		}
		foreach (EInvoiceParser::pdfEmbeddedStreams($pdf) as $s) {
			$data = $s['data'];
			// The invoice XML (factur-x.xml, zugferd-invoice.xml, xrechnung.xml) is shown separately
			if (preg_match('/^\s*(\xEF\xBB\xBF)?\s*</', substr((string) $data, 0, 64))
				&& (strpos($data, 'CrossIndustryInvoice') !== false || strpos($data, EInvoiceParser::NS_UBL_INVOICE) !== false || strpos($data, EInvoiceParser::NS_UBL_CREDITNOTE) !== false)) {
				continue;
			}
			$declared = self::pdfFileName($pdf, $s['obj']);
			$item = array('name' => '', 'id' => '', 'description' => '', 'type' => '', 'size' => 0, 'source' => 'pdf', 'error' => '', 'data' => null);
			if ($data === null) {
				$item['error'] = 'broken';
			} elseif ($data === '') {
				$item['error'] = 'empty';
			} elseif (strlen($data) > self::MAX_SIZE) {
				$item['error'] = 'toolarge';
				$item['size'] = strlen($data);
			} else {
				$item['size'] = strlen($data);
				$item['type'] = self::detect($data, $declared);
				if ($item['type'] === '') {
					$item['error'] = 'unsupported';
				} else {
					$item['data'] = $data;
				}
			}
			$item['hash'] = $data !== null && $data !== '' ? sha1($data) : '';
			$item['name'] = self::fileName($declared !== '' ? $declared : 'anhang', $item['type'], count($out) + 1);
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Name of the file specification whose /EF points to object $obj.
	 *
	 * @param string $pdf Raw PDF
	 * @param int    $obj Object number of the embedded file stream
	 * @return string
	 */
	private static function pdfFileName($pdf, $obj)
	{
		if ($obj <= 0 || !preg_match('/\/EF\s*<<[^>]*?\/(?:UF|F)\s+'.((int) $obj).'\s+0\s+R/s', $pdf, $m, PREG_OFFSET_CAPTURE)) {
			return '';
		}
		$pos = $m[0][1];
		// Filespec dictionary: from the preceding "obj" (or "<<" of an inline dict) to a bit after /EF
		$start = max(0, $pos - 2048);
		$chunk = substr($pdf, $start, $pos - $start + 512);
		$endPos = strpos($chunk, 'endobj', $pos - $start);
		if ($endPos !== false) {
			$chunk = substr($chunk, 0, $endPos);
		}
		$objPos = strrpos(substr($chunk, 0, $pos - $start), ' obj');
		if ($objPos !== false) {
			$chunk = substr($chunk, $objPos);
		}
		foreach (array('UF', 'F') as $key) {
			if (preg_match('/\/'.$key.'\s*(\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f\s]*>)/s', $chunk, $nm)) {
				$name = self::pdfString($nm[1]);
				if ($name !== '') {
					return $name;
				}
			}
		}
		return '';
	}

	/**
	 * Decode a PDF literal or hex string (PDFDocEncoding treated as Latin-1, UTF-16BE with BOM).
	 *
	 * @param string $s String token incl. delimiters
	 * @return string UTF-8
	 */
	private static function pdfString($s)
	{
		if ($s[0] === '<') {
			$raw = (string) hex2bin(preg_replace('/[^0-9A-Fa-f]/', '', substr($s, 1, -1)).(strlen(preg_replace('/[^0-9A-Fa-f]/', '', $s)) % 2 ? '0' : ''));
		} else {
			$raw = preg_replace_callback('/\\\\([0-7]{1,3}|.)/s', function ($m) {
				$map = array('n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f");
				if (ctype_digit($m[1])) {
					return chr(octdec($m[1]) & 0xFF);
				}
				return isset($map[$m[1]]) ? $map[$m[1]] : $m[1];
			}, substr($s, 1, -1));
		}
		if (substr($raw, 0, 2) === "\xFE\xFF") {
			$utf8 = function_exists('mb_convert_encoding') ? mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE') : '';
		} elseif (preg_match('//u', $raw)) {
			$utf8 = $raw;
		} else {
			$utf8 = function_exists('mb_convert_encoding') ? mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1') : '';
		}
		return trim(str_replace("\0", '', (string) $utf8));
	}

	/**
	 * Type from content.
	 *
	 * @param string $data     Content
	 * @param string $declared Declared file name (only used to tell XLSX from other ZIPs)
	 * @return string Key of TYPES or ''
	 */
	public static function detect($data, $declared = '')
	{
		$h = substr($data, 0, 8);
		if (strncmp($h, '%PDF-', 5) === 0) {
			return 'pdf';
		}
		if ($h === "\x89PNG\r\n\x1A\n") {
			return 'png';
		}
		if (strncmp($h, "\xFF\xD8\xFF", 3) === 0) {
			return 'jpg';
		}
		if (strncmp($h, "PK\x03\x04", 4) === 0) {
			if (strpos(substr($data, 0, 200), 'mimetypeapplication/vnd.oasis.opendocument.spreadsheet') !== false) {
				return 'ods';
			}
			if (strpos($data, '[Content_Types].xml') !== false && strpos($data, 'xl/') !== false) {
				return 'xlsx';
			}
			return '';
		}
		// CSV: text without control bytes, valid UTF-8 or Latin-1, at least one separator
		$sample = substr($data, 0, 65536);
		if ($sample !== '' && !preg_match('/[\x00-\x08\x0E-\x1F]/', $sample) && !preg_match('/^\s*</', $sample)
			&& preg_match('/[;,\t]/', $sample) && (preg_match('/\.(csv|txt)$/i', $declared) || preg_match('/[;,\t].*\n/', $sample))) {
			return 'csv';
		}
		return '';
	}

	/**
	 * Safe file name with the extension of the detected type.
	 *
	 * @param string $name Declared name
	 * @param string $type Detected type
	 * @param int    $n    Running number (fallback name)
	 * @return string
	 */
	private static function fileName($name, $type, $n)
	{
		$name = basename(str_replace('\\', '/', (string) $name));
		$base = pathinfo($name, PATHINFO_FILENAME);
		$base = preg_replace('/[^\p{L}\p{N} ._()+-]/u', '_', (string) $base);
		$base = trim(preg_replace('/[\s_]+/', ' ', $base), ' .');
		if ($base === '') {
			$base = 'anhang-'.$n;
		}
		$base = function_exists('mb_substr') ? mb_substr($base, 0, 100) : substr($base, 0, 100);
		if ($type !== '') {
			return $base.'.'.self::TYPES[$type][1];
		}
		return $base.'.bin';
	}

	/**
	 * @param DOMXPath $xp  XPath
	 * @param string   $q   Query
	 * @param DOMNode  $ctx Context
	 * @return string
	 */
	private static function v(DOMXPath $xp, $q, $ctx)
	{
		$n = $xp->query($q, $ctx);
		return ($n && $n->length) ? trim($n->item(0)->textContent) : '';
	}
}
