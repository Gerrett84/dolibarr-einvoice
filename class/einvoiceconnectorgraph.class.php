<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/einvoiceconnectorgraph.class.php
 * \ingroup    einvoice
 * \brief      Mailbox access through Microsoft Graph (application permission)
 */

require_once __DIR__.'/einvoiceconnector.class.php';

/**
 * Microsoft Graph connector (client credentials, application permission Mail.ReadWrite
 * limited to the invoice mailbox via Exchange RBAC for applications).
 */
require_once __DIR__.'/../lib/einvoice.lib.php';

class EInvoiceConnectorGraph extends EInvoiceConnector
{
	/** @var string */
	public $tenant = '';
	/** @var string */
	public $clientId = '';
	/** @var string */
	public $clientSecret = '';

	/** @var string */
	private $token = '';
	/** @var array<string,string> Folder path => id cache */
	private $folderIds = array();

	const GRAPH = 'https://graph.microsoft.com/v1.0';

	/**
	 * @return bool
	 */
	public function connect()
	{
		if ($this->mailbox === '') {
			$this->error = einvoice_t('EInvoiceErrMailboxMissing', 'Mailbox address is required');
			return false;
		}
		$token = $this->getClientCredentialsToken($this->tenant, $this->clientId, $this->clientSecret, 'https://graph.microsoft.com/.default');
		if ($token === false) {
			return false;
		}
		$this->token = $token;
		// Check access to the mailbox and the source folder
		return $this->resolveFolder($this->folder, false) !== false;
	}

	/**
	 * Base URL of the mailbox.
	 *
	 * @return string
	 */
	private function base()
	{
		return self::GRAPH.'/users/'.rawurlencode($this->mailbox);
	}

	/**
	 * Graph request with JSON handling and one retry on throttling.
	 *
	 * @param string     $method Method
	 * @param string     $url    Full URL
	 * @param array|null $json   JSON body
	 * @param bool       $raw    Return raw body instead of decoded JSON
	 * @return mixed|false
	 */
	private function call($method, $url, $json = null, $raw = false)
	{
		$headers = array('Authorization: Bearer '.$this->token, 'Accept: application/json');
		$body = null;
		if ($json !== null) {
			$headers[] = 'Content-Type: application/json';
			$body = json_encode($json);
		}
		for ($try = 0; $try < 2; $try++) {
			$res = $this->http($method, $url, $headers, $body);
			if ($res === false) {
				return false;
			}
			if ($res['code'] == 429 || $res['code'] == 503) {
				$wait = isset($res['headers']['retry-after']) ? (int) $res['headers']['retry-after'] : 5;
				sleep(max(1, min(30, $wait)));
				continue;
			}
			break;
		}
		if ($res['code'] < 200 || $res['code'] >= 300) {
			$msg = '';
			$j = json_decode($res['body'], true);
			if (is_array($j) && isset($j['error']['message'])) {
				$msg = $j['error']['code'].': '.$j['error']['message'];
			}
			$this->error = einvoice_t('EInvoiceErrGraph', 'Microsoft Graph %s failed (HTTP %s)', $method, $res['code']).($msg ? ' - '.$msg : '');
			return false;
		}
		if ($raw) {
			return $res['body'];
		}
		if ($res['body'] === '') {
			return array();
		}
		$j = json_decode($res['body'], true);
		return is_array($j) ? $j : array();
	}

	/**
	 * Resolve a folder path like "Inbox/Rechnungen" to its id.
	 *
	 * @param string $path   Folder path ('' = inbox). Well-known names (inbox, deleteditems, ...) allowed.
	 * @param bool   $create Create missing folders
	 * @return string|false
	 */
	private function resolveFolder($path, $create)
	{
		$path = trim((string) $path, " /");
		if ($path === '') {
			$path = 'inbox';
		}
		if (isset($this->folderIds[$path])) {
			return $this->folderIds[$path];
		}

		$parts = explode('/', $path);
		$wellKnown = array('inbox', 'deleteditems', 'archive', 'junkemail', 'drafts', 'sentitems', 'msgfolderroot');
		$first = strtolower($parts[0]);
		$parentId = null;
		$i = 0;
		if (in_array($first, $wellKnown, true) || in_array($first, array('posteingang'), true)) {
			$wk = ($first === 'posteingang') ? 'inbox' : $first;
			$f = $this->call('GET', $this->base().'/mailFolders/'.$wk.'?$select=id,displayName');
			if ($f === false) {
				return false;
			}
			$parentId = $f['id'];
			$i = 1;
		}

		for (; $i < count($parts); $i++) {
			$name = $parts[$i];
			$listUrl = $parentId === null
				? $this->base().'/mailFolders'
				: $this->base().'/mailFolders/'.rawurlencode($parentId).'/childFolders';
			$filter = "displayName eq '".str_replace("'", "''", $name)."'";
			$res = $this->call('GET', $listUrl.'?$select=id,displayName&$top=50&$filter='.rawurlencode($filter));
			if ($res === false) {
				return false;
			}
			if (!empty($res['value'][0]['id'])) {
				$parentId = $res['value'][0]['id'];
				continue;
			}
			if (!$create) {
				$this->error = einvoice_t('EInvoiceErrFolderNotFound', 'Folder not found: %s', $path);
				return false;
			}
			$new = $this->call('POST', $listUrl, array('displayName' => $name));
			if ($new === false || empty($new['id'])) {
				return false;
			}
			$parentId = $new['id'];
		}

		$this->folderIds[$path] = $parentId;
		return $parentId;
	}

	/**
	 * @param int $since Unix timestamp
	 * @param int $limit Max messages
	 * @return array<int,array<string,mixed>>|false
	 */
	public function listMessages($since, $limit)
	{
		$fid = $this->resolveFolder($this->folder, false);
		if ($fid === false) {
			return false;
		}
		$filter = 'receivedDateTime ge '.gmdate('Y-m-d\TH:i:s\Z', (int) $since);
		$url = $this->base().'/mailFolders/'.rawurlencode($fid).'/messages'
			.'?$select=id,internetMessageId,subject,from,receivedDateTime,hasAttachments'
			.'&$filter='.rawurlencode($filter)
			.'&$orderby='.rawurlencode('receivedDateTime asc')
			.'&$top=50';

		$out = array();
		$guard = 0;
		while ($url && count($out) < $limit && $guard++ < 100) {
			$res = $this->call('GET', $url);
			if ($res === false) {
				return false;
			}
			foreach ((array) (isset($res['value']) ? $res['value'] : array()) as $m) {
				if (empty($m['hasAttachments'])) {
					continue; // e-invoices always come as attachment
				}
				$from = '';
				if (!empty($m['from']['emailAddress'])) {
					$ea = $m['from']['emailAddress'];
					$from = (!empty($ea['name']) && $ea['name'] !== $ea['address'] ? $ea['name'].' ' : '').'<'.$ea['address'].'>';
				}
				$out[] = array(
					'id' => $m['id'],
					'key' => !empty($m['internetMessageId']) ? $m['internetMessageId'] : 'graph:'.$m['id'],
					'from' => $from,
					'subject' => isset($m['subject']) ? (string) $m['subject'] : '',
					'date' => !empty($m['receivedDateTime']) ? strtotime($m['receivedDateTime']) : 0,
					'size' => 0,
				);
				if (count($out) >= $limit) {
					break;
				}
			}
			$url = isset($res['@odata.nextLink']) ? $res['@odata.nextLink'] : '';
		}
		return $out;
	}

	/**
	 * @param string $id Message id
	 * @return string|false
	 */
	public function fetchRaw($id)
	{
		return $this->call('GET', $this->base().'/messages/'.rawurlencode($id).'/$value', null, true);
	}

	/**
	 * @param string $id Message id
	 * @return bool
	 */
	public function markRead($id)
	{
		return $this->call('PATCH', $this->base().'/messages/'.rawurlencode($id), array('isRead' => true)) !== false;
	}

	/**
	 * @param string $id     Message id
	 * @param string $folder Folder path
	 * @return bool
	 */
	public function moveTo($id, $folder)
	{
		$fid = $this->resolveFolder($folder, true);
		if ($fid === false) {
			return false;
		}
		return $this->call('POST', $this->base().'/messages/'.rawurlencode($id).'/move', array('destinationId' => $fid)) !== false;
	}

	/**
	 * @param string $id Message id
	 * @return bool
	 */
	public function trash($id)
	{
		return $this->call('POST', $this->base().'/messages/'.rawurlencode($id).'/move', array('destinationId' => 'deleteditems')) !== false;
	}
}
