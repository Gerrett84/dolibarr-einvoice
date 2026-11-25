<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/einvoiceconnectorimap.class.php
 * \ingroup    einvoice
 * \brief      Small IMAP client (password or OAuth2/XOAUTH2), no php-imap extension needed
 */

require_once __DIR__.'/einvoiceconnector.class.php';

/**
 * IMAP connector. Uses plain sockets so it works without ext-imap (removed in PHP 8.4)
 * and supports XOAUTH2 for Microsoft 365.
 */
require_once __DIR__.'/../lib/einvoice.lib.php';

class EInvoiceConnectorImap extends EInvoiceConnector
{
	/** @var string */
	public $host = 'outlook.office365.com';
	/** @var int */
	public $port = 993;
	/** @var string ssl | starttls | none */
	public $security = 'ssl';
	/** @var string password | oauth */
	public $authMode = 'password';
	/** @var string */
	public $login = '';
	/** @var string */
	public $password = '';
	/** @var string */
	public $tenant = '';
	/** @var string */
	public $clientId = '';
	/** @var string */
	public $clientSecret = '';
	/** @var string Trash folder ('' = detect) */
	public $trashFolder = '';

	/** @var resource|null */
	private $sock = null;
	/** @var int */
	private $tagNo = 0;
	/** @var string[] */
	private $caps = array();
	/** @var string Currently selected folder */
	private $selected = '';
	/** @var string */
	private $uidValidity = '';

	/** Max message size we download (bytes) */
	const MAX_MESSAGE_SIZE = 52428800;

	/**
	 * @return bool
	 */
	public function connect()
	{
		$this->close();
		$remote = ($this->security === 'ssl' ? 'ssl://' : 'tcp://').$this->host.':'.((int) $this->port);
		$ctx = stream_context_create(array('ssl' => array('verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $this->host)));
		$errno = 0;
		$errstr = '';
		$sock = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
		if (!$sock) {
			$this->error = einvoice_t('EInvoiceErrImapConnect', 'Connection to IMAP server %s failed: %s', $this->host.':'.$this->port, $errstr);
			return false;
		}
		stream_set_timeout($sock, $this->timeout);
		$this->sock = $sock;

		$greeting = $this->readLine();
		if ($greeting === false || strpos($greeting, '* OK') !== 0) {
			$this->error = einvoice_t('EInvoiceErrImapGreeting', 'Unexpected answer of the IMAP server: %s', trim((string) $greeting));
			$this->close();
			return false;
		}

		if ($this->security === 'starttls') {
			$r = $this->command('STARTTLS');
			if ($r['status'] !== 'OK' || !@stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0))) {
				$this->error = einvoice_t('EInvoiceErrStarttls', 'Encryption (STARTTLS) failed');
				$this->close();
				return false;
			}
		}

		if ($this->authMode === 'oauth') {
			$token = $this->getClientCredentialsToken($this->tenant, $this->clientId, $this->clientSecret, 'https://outlook.office365.com/.default');
			if ($token === false) {
				$this->close();
				return false;
			}
			$sasl = base64_encode('user='.$this->mailbox."\x01auth=Bearer ".$token."\x01\x01");
			$r = $this->command('AUTHENTICATE XOAUTH2 '.$sasl, true);
		} else {
			$r = $this->command('LOGIN '.$this->quote($this->login).' '.$this->quote($this->password));
		}
		if ($r['status'] !== 'OK') {
			$this->error = einvoice_t('EInvoiceErrImapLogin', 'IMAP login failed: %s', $r['text']);
			$this->close();
			return false;
		}

		$r = $this->command('CAPABILITY');
		foreach ($r['untagged'] as $u) {
			if (stripos($u['text'], 'CAPABILITY ') === 0) {
				$this->caps = array_map('strtoupper', preg_split('/\s+/', trim(substr($u['text'], 11))));
			}
		}

		return $this->select($this->folder === '' ? 'INBOX' : $this->folder);
	}

	/**
	 * @return void
	 */
	public function close()
	{
		if ($this->sock) {
			@fwrite($this->sock, 'Z'.(++$this->tagNo)." LOGOUT\r\n");
			@fclose($this->sock);
		}
		$this->sock = null;
		$this->selected = '';
	}

	/**
	 * Read one line (with CRLF).
	 *
	 * @return string|false
	 */
	private function readLine()
	{
		$line = fgets($this->sock);
		if ($line === false) {
			$meta = stream_get_meta_data($this->sock);
			$this->error = !empty($meta['timed_out']) ? 'IMAP timeout' : 'IMAP connection lost';
		}
		return $line;
	}

	/**
	 * Read exactly $n bytes.
	 *
	 * @param int $n Bytes
	 * @return string|false
	 */
	private function readBytes($n)
	{
		$buf = '';
		while (strlen($buf) < $n) {
			$chunk = fread($this->sock, min(65536, $n - strlen($buf)));
			if ($chunk === false || $chunk === '') {
				$meta = stream_get_meta_data($this->sock);
				if (!empty($meta['timed_out']) || feof($this->sock)) {
					$this->error = einvoice_t('EInvoiceErrImapRead', 'Reading from the IMAP server failed');
					return false;
				}
				continue;
			}
			$buf .= $chunk;
		}
		return $buf;
	}

	/**
	 * Send a command and collect the response.
	 *
	 * Untagged responses are returned as array('text' => line without literals, 'literals' => [...]).
	 *
	 * @param string $cmd           Command without tag
	 * @param bool   $isSaslInitial Answer a "+" continuation with an empty line (SASL error path)
	 * @return array{status:string,text:string,untagged:array<int,array{text:string,literals:string[]}>}
	 */
	private function command($cmd, $isSaslInitial = false)
	{
		$out = array('status' => 'BAD', 'text' => '', 'untagged' => array());
		if (!$this->sock) {
			$out['text'] = 'not connected';
			return $out;
		}
		$tag = 'A'.(++$this->tagNo);
		if (@fwrite($this->sock, $tag.' '.$cmd."\r\n") === false) {
			$out['text'] = 'write failed';
			return $out;
		}

		$current = null;
		while (true) {
			$line = $this->readLine();
			if ($line === false) {
				$out['text'] = $this->error;
				return $out;
			}
			// Literal at end of line: {123}
			while (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
				$lit = $this->readBytes((int) $m[1]);
				if ($lit === false) {
					$out['text'] = $this->error;
					return $out;
				}
				if ($current === null) {
					$current = array('text' => '', 'literals' => array());
				}
				$current['text'] .= substr($line, 0, -strlen($m[0])).'{L'.count($current['literals']).'}';
				$current['literals'][] = $lit;
				$line = $this->readLine();
				if ($line === false) {
					$out['text'] = $this->error;
					return $out;
				}
			}

			if ($current !== null) {
				$current['text'] .= rtrim($line, "\r\n");
				$text = $current['text'];
				$lits = $current['literals'];
				$current = null;
			} else {
				$text = rtrim($line, "\r\n");
				$lits = array();
			}

			if (strpos($text, $tag.' ') === 0) {
				$rest = substr($text, strlen($tag) + 1);
				$sp = strpos($rest, ' ');
				$out['status'] = strtoupper($sp === false ? $rest : substr($rest, 0, $sp));
				$out['text'] = $sp === false ? '' : substr($rest, $sp + 1);
				return $out;
			}
			if (strpos($text, '+') === 0) {
				// Continuation: only expected on SASL failure -> send empty response
				if ($isSaslInitial) {
					fwrite($this->sock, "\r\n");
					continue;
				}
				$out['text'] = 'unexpected continuation';
				return $out;
			}
			if (strpos($text, '* ') === 0) {
				$out['untagged'][] = array('text' => substr($text, 2), 'literals' => $lits);
			}
		}
	}

	/**
	 * Quote a string for IMAP.
	 *
	 * @param string $s String
	 * @return string
	 */
	private function quote($s)
	{
		return '"'.str_replace(array('\\', '"'), array('\\\\', '\\"'), $s).'"';
	}

	/**
	 * Encode a folder name (modified UTF-7) and quote it.
	 *
	 * @param string $name Folder path, "/" as separator
	 * @return string
	 */
	private function folderArg($name)
	{
		$enc = function_exists('mb_convert_encoding') ? mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8') : $name;
		return $this->quote($enc);
	}

	/**
	 * Select a folder.
	 *
	 * @param string $folder Folder
	 * @return bool
	 */
	private function select($folder)
	{
		if ($this->selected === $folder) {
			return true;
		}
		$r = $this->command('SELECT '.$this->folderArg($folder));
		if ($r['status'] !== 'OK') {
			$this->error = einvoice_t('EInvoiceErrImapFolder', 'Cannot open folder "%s": %s', $folder, $r['text']);
			return false;
		}
		foreach ($r['untagged'] as $u) {
			if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $u['text'], $m)) {
				$this->uidValidity = $m[1];
			}
		}
		$this->selected = $folder;
		return true;
	}

	/**
	 * @param int $since Unix timestamp
	 * @param int $limit Max messages
	 * @return array<int,array<string,mixed>>|false
	 */
	public function listMessages($since, $limit)
	{
		$src = $this->folder === '' ? 'INBOX' : $this->folder;
		if (!$this->select($src)) {
			return false;
		}
		$r = $this->command('UID SEARCH SINCE '.gmdate('j-M-Y', (int) $since));
		if ($r['status'] !== 'OK') {
			$this->error = einvoice_t('EInvoiceErrImap', 'IMAP command %s failed: %s', 'SEARCH', $r['text']);
			return false;
		}
		$uids = array();
		foreach ($r['untagged'] as $u) {
			if (stripos($u['text'], 'SEARCH') === 0) {
				foreach (preg_split('/\s+/', trim(substr($u['text'], 6))) as $x) {
					if (ctype_digit($x)) {
						$uids[] = (int) $x;
					}
				}
			}
		}
		sort($uids);

		$out = array();
		foreach (array_chunk($uids, 50) as $chunk) {
			$r = $this->command('UID FETCH '.implode(',', $chunk).' (UID RFC822.SIZE INTERNALDATE BODY.PEEK[HEADER.FIELDS (MESSAGE-ID FROM SUBJECT DATE CONTENT-TYPE)])');
			if ($r['status'] !== 'OK') {
				$this->error = einvoice_t('EInvoiceErrImap', 'IMAP command %s failed: %s', 'FETCH', $r['text']);
				return false;
			}
			foreach ($r['untagged'] as $u) {
				if (!preg_match('/^\d+ FETCH /i', $u['text']) || !preg_match('/UID (\d+)/i', $u['text'], $um)) {
					continue;
				}
				$uid = $um[1];
				$size = preg_match('/RFC822\.SIZE (\d+)/i', $u['text'], $sm) ? (int) $sm[1] : 0;
				$idate = preg_match('/INTERNALDATE "([^"]+)"/i', $u['text'], $dm) ? strtotime($dm[1]) : 0;
				$hdr = isset($u['literals'][0]) ? $u['literals'][0] : '';
				require_once __DIR__.'/einvoicemime.class.php';
				$p = EInvoiceMime::parse($hdr."\r\n\r\n");
				$ct = EInvoiceMime::parseParams(isset($p['headers']['content-type']) ? $p['headers']['content-type'] : 'text/plain');
				if (strpos($ct['value'], 'multipart/') !== 0 && !in_array($ct['value'], array('application/pdf', 'application/xml', 'text/xml'), true)) {
					continue; // no attachment possible
				}
				if ($idate && $idate < $since) {
					continue; // SINCE is date based, filter exact time
				}
				$out[] = array(
					'id' => $uid,
					'key' => $p['message_id'] !== '' ? $p['message_id'] : 'imap:'.$this->uidValidity.':'.$uid,
					'from' => $p['from'],
					'subject' => $p['subject'],
					'date' => $idate ? $idate : $p['date'],
					'size' => $size,
				);
				if (count($out) >= $limit) {
					return $out;
				}
			}
		}
		return $out;
	}

	/**
	 * @param string $id UID
	 * @return string|false
	 */
	public function fetchRaw($id)
	{
		if (!$this->select($this->folder === '' ? 'INBOX' : $this->folder)) {
			return false;
		}
		$r = $this->command('UID FETCH '.((int) $id).' (UID RFC822.SIZE BODY.PEEK[])');
		if ($r['status'] !== 'OK') {
			$this->error = einvoice_t('EInvoiceErrImap', 'IMAP command %s failed: %s', 'FETCH', $r['text']);
			return false;
		}
		foreach ($r['untagged'] as $u) {
			if (preg_match('/UID '.((int) $id).'\b/i', $u['text']) && isset($u['literals'][0])) {
				if (strlen($u['literals'][0]) > self::MAX_MESSAGE_SIZE) {
					$this->error = einvoice_t('EInvoiceErrMailTooLarge', 'Mail too large');
					return false;
				}
				return $u['literals'][0];
			}
		}
		$this->error = einvoice_t('EInvoiceErrMailNotFound', 'Mail %s not found', $id);
		return false;
	}

	/**
	 * @param string $id UID
	 * @return bool
	 */
	public function markRead($id)
	{
		$r = $this->command('UID STORE '.((int) $id).' +FLAGS.SILENT (\\Seen)');
		if ($r['status'] !== 'OK') {
			$this->error = einvoice_t('EInvoiceErrImap', 'IMAP command %s failed: %s', 'STORE', $r['text']);
			return false;
		}
		return true;
	}

	/**
	 * Create a folder if it does not exist.
	 *
	 * @param string $folder Folder
	 * @return bool
	 */
	private function ensureFolder($folder)
	{
		$r = $this->command('LIST "" '.$this->folderArg($folder));
		if ($r['status'] === 'OK' && count($r['untagged']) > 0) {
			return true;
		}
		$r = $this->command('CREATE '.$this->folderArg($folder));
		if ($r['status'] !== 'OK' && stripos($r['text'], 'exist') === false) {
			$this->error = einvoice_t('EInvoiceErrImapCreate', 'Cannot create folder "%s": %s', $folder, $r['text']);
			return false;
		}
		$this->command('SUBSCRIBE '.$this->folderArg($folder));
		return true;
	}

	/**
	 * @param string $id     UID
	 * @param string $folder Folder
	 * @return bool
	 */
	public function moveTo($id, $folder)
	{
		if (!$this->ensureFolder($folder)) {
			return false;
		}
		$uid = (int) $id;
		if (in_array('MOVE', $this->caps, true)) {
			$r = $this->command('UID MOVE '.$uid.' '.$this->folderArg($folder));
			if ($r['status'] !== 'OK') {
				$this->error = einvoice_t('EInvoiceErrImap', 'IMAP command %s failed: %s', 'MOVE', $r['text']);
				return false;
			}
			return true;
		}
		$r = $this->command('UID COPY '.$uid.' '.$this->folderArg($folder));
		if ($r['status'] !== 'OK') {
			$this->error = einvoice_t('EInvoiceErrImap', 'IMAP command %s failed: %s', 'COPY', $r['text']);
			return false;
		}
		$r = $this->command('UID STORE '.$uid.' +FLAGS.SILENT (\\Deleted)');
		if ($r['status'] !== 'OK') {
			$this->error = einvoice_t('EInvoiceErrImap', 'IMAP command %s failed: %s', 'STORE', $r['text']);
			return false;
		}
		$r = $this->command(in_array('UIDPLUS', $this->caps, true) ? 'UID EXPUNGE '.$uid : 'EXPUNGE');
		return $r['status'] === 'OK';
	}

	/**
	 * @param string $id UID
	 * @return bool
	 */
	public function trash($id)
	{
		$folder = $this->trashFolder;
		if ($folder === '') {
			$folder = $this->detectTrash();
		}
		if ($folder === '') {
			$this->error = einvoice_t('EInvoiceErrTrash', 'Trash folder not found, please set it in the module setup');
			return false;
		}
		return $this->moveTo($id, $folder);
	}

	/**
	 * Find the trash folder (SPECIAL-USE \Trash or common names).
	 *
	 * @return string
	 */
	private function detectTrash()
	{
		$r = $this->command('LIST "" "*"');
		if ($r['status'] !== 'OK') {
			return '';
		}
		$names = array();
		foreach ($r['untagged'] as $u) {
			if (!preg_match('/^LIST \(([^)]*)\) (?:"[^"]*"|NIL) (.+)$/i', $u['text'], $m)) {
				continue;
			}
			$name = trim($m[2]);
			if (preg_match('/^\{L(\d+)\}$/', $name, $lm) && isset($u['literals'][(int) $lm[1]])) {
				$name = $u['literals'][(int) $lm[1]];
			} elseif ($name !== '' && $name[0] === '"') {
				$name = stripslashes(substr($name, 1, -1));
			}
			if (function_exists('mb_convert_encoding')) {
				$name = mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
			}
			if (stripos($m[1], '\\Trash') !== false) {
				return $name;
			}
			$names[] = $name;
		}
		foreach (array('Deleted Items', 'Gelöschte Elemente', 'Trash', 'Papierkorb', 'INBOX.Trash') as $cand) {
			if (in_array($cand, $names, true)) {
				return $cand;
			}
		}
		return '';
	}
}
