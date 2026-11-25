<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/einvoiceconnector.class.php
 * \ingroup    einvoice
 * \brief      Common base for mailbox connectors (Microsoft Graph, IMAP)
 */

/**
 * Mailbox connector.
 *
 * A message is described as array(
 *   'id'      => server id (Graph message id or IMAP UID),
 *   'key'     => stable key for de-duplication (Message-ID header if known),
 *   'from'    => sender, 'subject' => subject, 'date' => unix timestamp,
 *   'size'    => size in bytes (0 if unknown)
 * )
 */
abstract class EInvoiceConnector
{
	/** @var string Last error */
	public $error = '';

	/** @var string Mailbox address / login, used as de-duplication scope */
	public $mailbox = '';

	/** @var string Source folder ('' = inbox) */
	public $folder = '';

	/** @var int Connect/response timeout (seconds) */
	public $timeout = 30;

	/**
	 * Build the connector configured in the module setup.
	 *
	 * @return EInvoiceConnector|null
	 */
	public static function fromConfig()
	{
		$type = getDolGlobalString('EINVOICE_CONN_TYPE');
		if ($type === 'graph') {
			require_once __DIR__.'/einvoiceconnectorgraph.class.php';
			$c = new EInvoiceConnectorGraph();
			$c->tenant = getDolGlobalString('EINVOICE_TENANT_ID');
			$c->clientId = getDolGlobalString('EINVOICE_CLIENT_ID');
			$c->clientSecret = getDolGlobalString('EINVOICE_CLIENT_SECRET');
			$c->mailbox = getDolGlobalString('EINVOICE_MAILBOX');
		} elseif ($type === 'imap_oauth' || $type === 'imap_password') {
			require_once __DIR__.'/einvoiceconnectorimap.class.php';
			$c = new EInvoiceConnectorImap();
			$c->host = getDolGlobalString('EINVOICE_IMAP_HOST', 'outlook.office365.com');
			$c->port = getDolGlobalInt('EINVOICE_IMAP_PORT', 993);
			$c->security = getDolGlobalString('EINVOICE_IMAP_SECURITY', 'ssl');
			$c->mailbox = getDolGlobalString('EINVOICE_MAILBOX');
			$c->trashFolder = getDolGlobalString('EINVOICE_IMAP_TRASH');
			if ($type === 'imap_oauth') {
				$c->authMode = 'oauth';
				$c->tenant = getDolGlobalString('EINVOICE_TENANT_ID');
				$c->clientId = getDolGlobalString('EINVOICE_CLIENT_ID');
				$c->clientSecret = getDolGlobalString('EINVOICE_CLIENT_SECRET');
			} else {
				$c->authMode = 'password';
				$c->login = getDolGlobalString('EINVOICE_IMAP_LOGIN', getDolGlobalString('EINVOICE_MAILBOX'));
				$c->password = getDolGlobalString('EINVOICE_IMAP_PASSWORD');
			}
		} else {
			return null;
		}
		$c->folder = getDolGlobalString('EINVOICE_SOURCE_FOLDER');
		return $c;
	}

	/**
	 * Open the connection / get a token.
	 *
	 * @return bool
	 */
	abstract public function connect();

	/**
	 * List messages of the source folder received since a date (oldest first).
	 *
	 * @param int $since Unix timestamp
	 * @param int $limit Max number of messages
	 * @return array<int,array<string,mixed>>|false
	 */
	abstract public function listMessages($since, $limit);

	/**
	 * Get the raw MIME source of a message.
	 *
	 * @param string $id Server id
	 * @return string|false
	 */
	abstract public function fetchRaw($id);

	/**
	 * Mark a message as read.
	 *
	 * @param string $id Server id
	 * @return bool
	 */
	abstract public function markRead($id);

	/**
	 * Move a message into a folder (created if missing). Subfolders with "/".
	 *
	 * @param string $id     Server id
	 * @param string $folder Target folder path
	 * @return bool
	 */
	abstract public function moveTo($id, $folder);

	/**
	 * Move a message to the deleted items / trash folder.
	 *
	 * @param string $id Server id
	 * @return bool
	 */
	abstract public function trash($id);

	/**
	 * Close the connection.
	 *
	 * @return void
	 */
	public function close()
	{
	}

	/**
	 * Get an OAuth2 token with the client credentials flow (Microsoft Entra ID).
	 *
	 * @param string $tenant       Tenant id or domain
	 * @param string $clientId     Application (client) id
	 * @param string $clientSecret Client secret
	 * @param string $scope        Scope, e.g. https://graph.microsoft.com/.default
	 * @return string|false Access token
	 */
	protected function getClientCredentialsToken($tenant, $clientId, $clientSecret, $scope)
	{
		if ($tenant === '' || $clientId === '' || $clientSecret === '') {
			$this->error = 'Tenant, client id and client secret are required';
			return false;
		}
		$url = 'https://login.microsoftonline.com/'.rawurlencode($tenant).'/oauth2/v2.0/token';
		$res = $this->http('POST', $url, array('Content-Type: application/x-www-form-urlencoded'), http_build_query(array(
			'client_id' => $clientId,
			'client_secret' => $clientSecret,
			'scope' => $scope,
			'grant_type' => 'client_credentials',
		)));
		if ($res === false) {
			return false;
		}
		$json = json_decode($res['body'], true);
		if ($res['code'] != 200 || empty($json['access_token'])) {
			$msg = '';
			if (is_array($json)) {
				$msg = !empty($json['error_description']) ? $json['error_description'] : (isset($json['error']) ? $json['error'] : '');
			}
			$this->error = 'Token request failed (HTTP '.$res['code'].')'.($msg ? ': '.strtok($msg, "\r\n") : '');
			return false;
		}
		return $json['access_token'];
	}

	/**
	 * Minimal HTTP client (curl).
	 *
	 * @param string   $method  GET, POST, PATCH, DELETE
	 * @param string   $url     URL
	 * @param string[] $headers Headers
	 * @param string   $body    Body
	 * @return array{code:int,body:string,headers:array<string,string>}|false
	 */
	protected function http($method, $url, $headers = array(), $body = null)
	{
		if (!function_exists('curl_init')) {
			$this->error = 'PHP curl extension missing';
			return false;
		}
		$respHeaders = array();
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(15, $this->timeout));
		curl_setopt($ch, CURLOPT_TIMEOUT, max(30, $this->timeout * 4));
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$respHeaders) {
			$p = strpos($line, ':');
			if ($p !== false) {
				$respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
			}
			return strlen($line);
		});
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		$resp = curl_exec($ch);
		if ($resp === false) {
			$this->error = 'HTTP error: '.curl_error($ch);
			curl_close($ch);
			return false;
		}
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		return array('code' => $code, 'body' => (string) $resp, 'headers' => $respHeaders);
	}
}
