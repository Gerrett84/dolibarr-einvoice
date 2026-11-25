<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/einvoicemime.class.php
 * \ingroup    einvoice
 * \brief      Minimal MIME parser: headers and file attachments of a raw mail
 */

/**
 * Parses a raw RFC 5322 / MIME message (as delivered by IMAP BODY[] or Graph $value).
 * Only what is needed here: main headers and all file-like parts, including
 * parts of attached mails (message/rfc822, e.g. forwarded invoices).
 */
class EInvoiceMime
{
	/** Maximum nesting depth for multiparts / attached mails */
	const MAX_DEPTH = 10;

	/**
	 * Parse a raw message.
	 *
	 * @param string $raw Raw message
	 * @return array{headers:array<string,string>,from:string,subject:string,date:int,message_id:string,attachments:array<int,array{filename:string,type:string,data:string}>}
	 */
	public static function parse($raw)
	{
		$part = self::splitPart($raw);
		$headers = $part['headers'];

		$attachments = array();
		self::walk($part, $attachments, 0);

		$date = 0;
		if (!empty($headers['date'])) {
			$ts = strtotime(preg_replace('/\s*\([^)]*\)\s*$/', '', $headers['date']));
			$date = $ts ? $ts : 0;
		}

		return array(
			'headers' => $headers,
			'from' => self::decodeHeader(isset($headers['from']) ? $headers['from'] : ''),
			'subject' => self::decodeHeader(isset($headers['subject']) ? $headers['subject'] : ''),
			'date' => $date,
			'message_id' => isset($headers['message-id']) ? trim($headers['message-id']) : '',
			'attachments' => $attachments,
		);
	}

	/**
	 * Split a part into headers and body.
	 *
	 * @param string $raw Raw part
	 * @return array{headers:array<string,string>,body:string}
	 */
	private static function splitPart($raw)
	{
		$pos = strpos($raw, "\r\n\r\n");
		$sepLen = 4;
		$posLf = strpos($raw, "\n\n");
		if ($pos === false || ($posLf !== false && $posLf < $pos)) {
			$pos = $posLf;
			$sepLen = 2;
		}
		if ($pos === false) {
			$headText = $raw;
			$body = '';
		} else {
			$headText = substr($raw, 0, $pos);
			$body = substr($raw, $pos + $sepLen);
		}

		// Unfold continuation lines
		$headText = preg_replace('/\r?\n[ \t]+/', ' ', $headText);
		$headers = array();
		foreach (preg_split('/\r?\n/', $headText) as $line) {
			$c = strpos($line, ':');
			if ($c === false || $c === 0) {
				continue;
			}
			$name = strtolower(trim(substr($line, 0, $c)));
			if (!isset($headers[$name])) {
				$headers[$name] = trim(substr($line, $c + 1));
			}
		}
		return array('headers' => $headers, 'body' => $body);
	}

	/**
	 * Parse "type/subtype; a=b; c*=utf-8''x" into value + params.
	 *
	 * @param string $value Header value
	 * @return array{value:string,params:array<string,string>}
	 */
	public static function parseParams($value)
	{
		$params = array();
		$main = $value;
		$semi = strpos($value, ';');
		if ($semi !== false) {
			$main = substr($value, 0, $semi);
			$rest = substr($value, $semi + 1);
			preg_match_all('/\s*([^=;\s]+)\s*=\s*("((?:[^"\\\\]|\\\\.)*)"|[^;]*)\s*;?/', $rest, $mm, PREG_SET_ORDER);
			$continued = array();
			foreach ($mm as $m) {
				$k = strtolower($m[1]);
				$v = ($m[2] !== '' && $m[2][0] === '"') ? preg_replace('/\\\\(.)/s', '$1', $m[3]) : trim($m[2]);
				// RFC 2231: name*0*=..., name*1*=..., name*=charset''value
				if (preg_match('/^([^*]+)\*(\d+)(\*?)$/', $k, $pm)) {
					$continued[$pm[1]][(int) $pm[2]] = array($v, $pm[3] === '*');
					continue;
				}
				if (substr($k, -1) === '*') {
					$params[substr($k, 0, -1)] = self::decodeRfc2231($v, true);
					continue;
				}
				$params[$k] = $v;
			}
			foreach ($continued as $k => $pieces) {
				ksort($pieces);
				$joined = '';
				$encoded = false;
				foreach ($pieces as $p) {
					$joined .= $p[0];
					$encoded = $encoded || $p[1];
				}
				$params[$k] = self::decodeRfc2231($joined, $encoded);
			}
		}
		return array('value' => strtolower(trim($main)), 'params' => $params);
	}

	/**
	 * Decode an RFC 2231 extended value (charset'lang'percent-encoded).
	 *
	 * @param string $v       Value
	 * @param bool   $encoded Whether the value carries charset/percent encoding
	 * @return string
	 */
	private static function decodeRfc2231($v, $encoded)
	{
		if (!$encoded) {
			return $v;
		}
		$charset = 'UTF-8';
		if (preg_match("/^([^']*)'[^']*'(.*)$/s", $v, $m)) {
			$charset = $m[1] ? $m[1] : 'UTF-8';
			$v = $m[2];
		}
		$v = rawurldecode($v);
		if (strtoupper($charset) !== 'UTF-8') {
			$conv = @iconv($charset, 'UTF-8//IGNORE', $v);
			if ($conv !== false) {
				$v = $conv;
			}
		}
		return $v;
	}

	/**
	 * Decode RFC 2047 encoded words to UTF-8.
	 *
	 * @param string $s Header value
	 * @return string
	 */
	public static function decodeHeader($s)
	{
		if ($s === '' || strpos($s, '=?') === false) {
			return self::toUtf8($s);
		}
		$dec = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
		if ($dec === false) {
			$dec = mb_decode_mimeheader($s);
		}
		return $dec;
	}

	/**
	 * Make sure a string is valid UTF-8 (assume Windows-1252 otherwise).
	 *
	 * @param string $s String
	 * @return string
	 */
	private static function toUtf8($s)
	{
		if ($s === '' || mb_check_encoding($s, 'UTF-8')) {
			return $s;
		}
		$conv = @iconv('Windows-1252', 'UTF-8//IGNORE', $s);
		return $conv === false ? $s : $conv;
	}

	/**
	 * Walk the MIME tree and collect file parts.
	 *
	 * @param array{headers:array<string,string>,body:string} $part        Part
	 * @param array<int,array{filename:string,type:string,data:string}>  $attachments Collected attachments (in/out)
	 * @param int                                              $depth       Depth
	 * @return void
	 */
	private static function walk($part, &$attachments, $depth)
	{
		if ($depth > self::MAX_DEPTH) {
			return;
		}
		$h = $part['headers'];
		$ct = self::parseParams(isset($h['content-type']) ? $h['content-type'] : 'text/plain');
		$type = $ct['value'];

		if (strpos($type, 'multipart/') === 0 && !empty($ct['params']['boundary'])) {
			foreach (self::splitMultipart($part['body'], $ct['params']['boundary']) as $sub) {
				self::walk(self::splitPart($sub), $attachments, $depth + 1);
			}
			return;
		}

		$data = self::decodeBody($part['body'], isset($h['content-transfer-encoding']) ? $h['content-transfer-encoding'] : '');

		if ($type === 'message/rfc822') {
			// Attached / forwarded mail: look inside, too
			self::walk(self::splitPart($data), $attachments, $depth + 1);
			return;
		}

		$cd = self::parseParams(isset($h['content-disposition']) ? $h['content-disposition'] : '');
		$filename = '';
		if (!empty($cd['params']['filename'])) {
			$filename = $cd['params']['filename'];
		} elseif (!empty($ct['params']['name'])) {
			$filename = $ct['params']['name'];
		}
		$filename = self::decodeHeader($filename);

		$isFileType = in_array($type, array('application/pdf', 'application/xml', 'text/xml', 'application/octet-stream'), true)
			|| preg_match('/\+xml$/', $type);
		if ($filename === '' && !$isFileType) {
			return; // mail text, inline HTML etc.
		}
		if ($filename === '') {
			$filename = 'attachment'.(strpos($type, 'pdf') !== false ? '.pdf' : (strpos($type, 'xml') !== false ? '.xml' : '.bin'));
		}

		$attachments[] = array('filename' => $filename, 'type' => $type, 'data' => $data);
	}

	/**
	 * Split a multipart body into its raw sub parts.
	 *
	 * @param string $body     Body
	 * @param string $boundary Boundary
	 * @return string[]
	 */
	private static function splitMultipart($body, $boundary)
	{
		$parts = array();
		$re = '/(?:\A|\r?\n)--'.preg_quote($boundary, '/').'(--)?[ \t]*(\r?\n|\z)/';
		if (!preg_match_all($re, $body, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
			return $parts;
		}
		$count = count($mm);
		for ($i = 0; $i < $count; $i++) {
			if (isset($mm[$i][1]) && $mm[$i][1][1] !== -1 && $mm[$i][1][0] === '--') {
				break; // closing delimiter
			}
			$start = $mm[$i][0][1] + strlen($mm[$i][0][0]);
			$stop = ($i + 1 < $count) ? $mm[$i + 1][0][1] : strlen($body);
			$parts[] = substr($body, $start, $stop - $start);
		}
		return $parts;
	}

	/**
	 * Decode a body according to Content-Transfer-Encoding.
	 *
	 * @param string $body     Body
	 * @param string $encoding Encoding
	 * @return string
	 */
	private static function decodeBody($body, $encoding)
	{
		$encoding = strtolower(trim($encoding));
		if ($encoding === 'base64') {
			$dec = base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body));
			return $dec === false ? '' : $dec;
		}
		if ($encoding === 'quoted-printable') {
			return quoted_printable_decode($body);
		}
		return $body;
	}
}
