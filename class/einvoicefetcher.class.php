<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/einvoicefetcher.class.php
 * \ingroup    einvoice
 * \brief      Fetches mails, stores e-invoices in the inbox, applies the post action
 */

require_once __DIR__.'/einvoiceconnector.class.php';
require_once __DIR__.'/einvoicemime.class.php';
require_once __DIR__.'/einvoiceparser.class.php';
require_once __DIR__.'/einvoiceinbox.class.php';

/**
 * Fetch run (manual button or scheduled job).
 */
class EInvoiceFetcher
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';
	/** @var string[] */
	public $errors = array();
	/** @var string Output for the scheduled job */
	public $output = '';
	/** @var bool Run skipped because another one holds the lock */
	public $locked = false;

	/** @var array<string,int> Statistics of the last run */
	public $stats = array('mails' => 0, 'skipped' => 0, 'einvoices' => 0, 'others' => 0, 'duplicates' => 0, 'errors' => 0);

	/** Allowed post actions */
	const ACTIONS = array('none', 'read', 'move', 'trash');

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Entry point for the scheduled job.
	 *
	 * @return int 0 if OK, <0 if KO
	 */
	public function cronFetch()
	{
		global $user;
		$res = $this->run($user);
		$this->output = $this->summary();
		if ($res < 0) {
			$this->output .= "\n".$this->error;
			return -1;
		}
		return 0;
	}

	/**
	 * The scheduled job of this module.
	 *
	 * @param DoliDB $db Database handler
	 * @return object|null rowid, status, frequency, unitfrequency, datenextrun, datelastrun
	 */
	public static function getCronJob($db)
	{
		global $conf;
		$sql = "SELECT rowid, status, frequency, unitfrequency, datenextrun, datelastrun FROM ".MAIN_DB_PREFIX."cronjob";
		$sql .= " WHERE classesname LIKE '%einvoicefetcher.class.php' AND methodename = 'cronFetch'";
		$sql .= " AND entity IN (0, ".((int) $conf->entity).") ORDER BY entity DESC";
		$res = $db->query($sql);
		$obj = $res ? $db->fetch_object($res) : null;
		return $obj ? $obj : null;
	}

	/**
	 * Switch the scheduled job on/off and set its interval.
	 *
	 * @param DoliDB $db      Database handler
	 * @param bool   $enabled On/off
	 * @param int    $minutes Interval in minutes
	 * @return int <0 if KO
	 */
	public static function setCronJob($db, $enabled, $minutes)
	{
		$job = self::getCronJob($db);
		if (!$job) {
			return -1;
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."cronjob SET status = ".($enabled ? 1 : 0).", frequency = ".max(1, (int) $minutes).", unitfrequency = 60";
		if ($enabled && (int) $job->status !== 1) {
			$sql .= ", datenextrun = '".$db->idate(dol_now())."'";
		}
		$sql .= " WHERE rowid = ".((int) $job->rowid);
		return $db->query($sql) ? 1 : -1;
	}

	/**
	 * Did any scheduled job run recently? False means the system scheduler is probably not set up.
	 *
	 * @param DoliDB $db Database handler
	 * @return bool
	 */
	public static function schedulerSeemsActive($db)
	{
		$sql = "SELECT MAX(datelastrun) as last FROM ".MAIN_DB_PREFIX."cronjob WHERE status = 1";
		$res = $db->query($sql);
		$obj = $res ? $db->fetch_object($res) : null;
		$last = $obj ? $db->jdate($obj->last) : 0;
		return $last && $last > dol_now() - 2 * 3600;
	}

	/**
	 * Human readable summary of the last run.
	 *
	 * @return string
	 */
	public function summary()
	{
		global $langs;
		$langs->load('einvoice@einvoice');
		$s = $langs->trans('EInvoiceFetchSummary', $this->stats['mails'], $this->stats['einvoices'], $this->stats['others'], $this->stats['errors']);
		if ($this->stats['duplicates']) {
			$s .= ' '.$langs->trans('EInvoiceFetchDuplicates', $this->stats['duplicates']);
		}
		return $s;
	}

	/**
	 * Data directory of the module for the current entity.
	 *
	 * @return string
	 */
	public static function dataDir()
	{
		global $conf;
		if (!empty($conf->einvoice->multidir_output[$conf->entity])) {
			return $conf->einvoice->multidir_output[$conf->entity];
		}
		return DOL_DATA_ROOT.($conf->entity > 1 ? '/'.$conf->entity : '').'/einvoice';
	}

	/**
	 * Fetch new mails.
	 *
	 * @param User $user User running the fetch
	 * @return int <0 if KO, >=0 number of new inbox entries
	 */
	public function run($user)
	{
		$start = dol_now();
		$this->locked = false;
		$res = $this->doRun($user);
		if (!$this->locked) {
			$this->recordResult($start, $res);
		}
		return $res;
	}

	/**
	 * Remember time and outcome of a run for the status line in the inbox.
	 *
	 * @param int $start Start of the run
	 * @param int $res   Result of doRun()
	 * @return void
	 */
	private function recordResult($start, $res)
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		dolibarr_set_const($this->db, 'EINVOICE_LAST_RUN', $start, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'EINVOICE_LAST_STATUS', $res < 0 ? 'error' : ($this->stats['errors'] ? 'warning' : 'ok'), 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'EINVOICE_LAST_MESSAGE', self::cut($res < 0 ? (string) $this->error : $this->summary(), 250), 'chaine', 0, '', $conf->entity);
	}

	/**
	 * Fetch new mails (without recording the outcome).
	 *
	 * @param User $user User running the fetch
	 * @return int <0 if KO, >=0 number of new inbox entries
	 */
	private function doRun($user)
	{
		global $conf;

		$conn = EInvoiceConnector::fromConfig();
		if (!$conn) {
			$this->error = 'No mailbox connection configured';
			return -1;
		}

		$since = (int) getDolGlobalInt('EINVOICE_FETCH_SINCE');
		if ($since <= 0) {
			$this->error = 'Please set "fetch mails received since" in the module setup';
			return -1;
		}
		$limit = max(1, getDolGlobalInt('EINVOICE_MAX_PER_RUN', 50));

		// One run at a time (button and scheduled job)
		$lockDir = self::dataDir().'/temp';
		dol_mkdir($lockDir);
		$lock = @fopen($lockDir.'/fetch.lock', 'c');
		if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
			$this->error = 'A fetch is already running';
			$this->locked = true;
			return -1;
		}

		// Watermark: mails older than this were all processed in earlier runs. It keeps the
		// listing short when mails stay in the mailbox (post action "none").
		$watermark = getDolGlobalInt('EINVOICE_WATERMARK');
		$effectiveSince = max($since, $watermark);
		$runStart = dol_now();
		$listMax = 500;

		$created = 0;
		$newWatermark = 0;
		try {
			if (!$conn->connect()) {
				$this->error = $conn->error;
				return -1;
			}
			$messages = $conn->listMessages($effectiveSince, $listMax);
			if ($messages === false) {
				$this->error = $conn->error;
				return -1;
			}

			$processed = 0;
			$oldestPending = null; // date of the oldest mail left for a later run
			$lastListed = 0;
			foreach ($messages as $msg) {
				$lastListed = max($lastListed, (int) $msg['date']);
				if ($this->isKnownMail($conn->mailbox, $msg['key'])) {
					$this->stats['skipped']++;
					continue;
				}
				if ($processed >= $limit) {
					$oldestPending = ($oldestPending === null) ? (int) $msg['date'] : min($oldestPending, (int) $msg['date']);
					continue;
				}
				$processed++;
				$res = $this->processMessage($conn, $msg, $user);
				if ($res < 0) {
					$this->stats['errors']++;
					$oldestPending = ($oldestPending === null) ? (int) $msg['date'] : min($oldestPending, (int) $msg['date']);
				} else {
					$created += $res;
				}
			}

			if ($oldestPending !== null) {
				$newWatermark = $oldestPending - 60;
			} elseif (count($messages) < $listMax) {
				$newWatermark = $runStart - 86400; // one day overlap for late deliveries
			} else {
				$newWatermark = $lastListed - 60;
			}
		} finally {
			$conn->close();
			flock($lock, LOCK_UN);
			fclose($lock);
		}

		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		if ($newWatermark > $watermark) {
			dolibarr_set_const($this->db, 'EINVOICE_WATERMARK', $newWatermark, 'chaine', 0, '', $conf->entity);
		}
		dolibarr_set_const($this->db, 'EINVOICE_LAST_FETCH', $runStart, 'chaine', 0, '', $conf->entity);
		return $created;
	}

	/**
	 * Mail already processed?
	 *
	 * @param string $mailbox Mailbox
	 * @param string $key     Mail key
	 * @return bool
	 */
	private function isKnownMail($mailbox, $key)
	{
		global $conf;
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."einvoice_mail";
		$sql .= " WHERE entity = ".((int) $conf->entity);
		$sql .= " AND mailbox = '".$this->db->escape(self::cut(strtolower($mailbox), 190))."'";
		$sql .= " AND mail_key = '".$this->db->escape(self::cut($key, 190))."'";
		$res = $this->db->query($sql);
		return $res && $this->db->num_rows($res) > 0;
	}

	/**
	 * Process one mail: store its e-invoices, remember it, apply post action.
	 *
	 * @param EInvoiceConnector     $conn Connector
	 * @param array<string,mixed>   $msg  Message info from listMessages()
	 * @param User                  $user User
	 * @return int <0 if KO, else number of new inbox entries
	 */
	private function processMessage(EInvoiceConnector $conn, $msg, $user)
	{
		global $conf;

		$raw = $conn->fetchRaw($msg['id']);
		if ($raw === false) {
			// Network / server problem: do not remember the mail, retry next run
			$this->errors[] = $msg['subject'].': '.$conn->error;
			dol_syslog('EInvoiceFetcher: fetch failed for '.$msg['key'].': '.$conn->error, LOG_WARNING);
			return -1;
		}

		$mime = EInvoiceMime::parse($raw);
		$from = $msg['from'] !== '' ? $msg['from'] : $mime['from'];
		$subject = $msg['subject'] !== '' ? $msg['subject'] : $mime['subject'];
		$date = !empty($msg['date']) ? (int) $msg['date'] : (int) $mime['date'];
		$keepPdf = getDolGlobalInt('EINVOICE_KEEP_PLAIN_PDF');

		$this->db->begin();

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."einvoice_mail (entity, mailbox, mail_key, mail_from, mail_subject, mail_date, datec)";
		$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape(self::cut(strtolower($conn->mailbox), 190))."'";
		$sql .= ", '".$this->db->escape(self::cut($msg['key'], 190))."'";
		$sql .= ", '".$this->db->escape(self::cut($from, 255))."', '".$this->db->escape(self::cut($subject, 255))."'";
		$sql .= ", ".($date ? "'".$this->db->idate($date)."'" : "NULL").", '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			$this->db->rollback();
			$this->errors[] = $this->db->lasterror();
			return -1;
		}
		$mailId = $this->db->last_insert_id(MAIN_DB_PREFIX.'einvoice_mail');

		$nbInv = 0;
		$nbOther = 0;
		$created = 0;
		foreach ($mime['attachments'] as $att) {
			$an = EInvoiceParser::analyze($att['data'], $att['filename'], $att['type']);
			if ($an['kind'] !== 'einvoice' && !($an['kind'] === 'pdf' && $keepPdf)) {
				continue;
			}
			if ($an['kind'] === 'einvoice') {
				$nbInv++;
			} else {
				$nbOther++;
			}
			$r = $this->storeDocument($an, $att['data'], $att['filename'], array(
				'source' => getDolGlobalString('EINVOICE_CONN_TYPE') === 'graph' ? 'graph' : 'imap',
				'fk_mail' => $mailId,
				'mail_from' => $from,
				'mail_subject' => $subject,
				'mail_date' => $date,
			), $user);
			if ($r === -2) {
				$this->stats['duplicates']++;
			} elseif ($r < 0) {
				$this->db->rollback();
				$this->errors[] = $subject.': '.$this->error;
				return -1;
			} else {
				$created++;
				if ($an['kind'] === 'einvoice') {
					$this->stats['einvoices']++;
				} else {
					$this->stats['others']++;
				}
			}
		}

		$this->db->commit();
		$this->stats['mails']++;

		// Post action, configurable separately for mails with and without e-invoice
		$action = $nbInv > 0 ? getDolGlobalString('EINVOICE_ACTION_FOUND', 'none') : getDolGlobalString('EINVOICE_ACTION_NOTFOUND', 'none');
		$folder = $nbInv > 0 ? getDolGlobalString('EINVOICE_FOLDER_FOUND', 'E-Rechnung/Verarbeitet') : getDolGlobalString('EINVOICE_FOLDER_NOTFOUND', 'E-Rechnung/Keine E-Rechnung');
		$ok = true;
		if ($action === 'read') {
			$ok = $conn->markRead($msg['id']);
		} elseif ($action === 'move') {
			$ok = $conn->moveTo($msg['id'], $folder);
		} elseif ($action === 'trash') {
			$ok = $conn->trash($msg['id']);
		} else {
			$action = 'none';
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX."einvoice_mail SET nb_einvoice = ".((int) $nbInv).", nb_other = ".((int) $nbOther);
		$sql .= ", action_done = '".$this->db->escape($ok ? $action : 'failed')."'";
		$sql .= ", action_error = ".($ok ? "NULL" : "'".$this->db->escape($conn->error)."'");
		$sql .= " WHERE rowid = ".((int) $mailId);
		$this->db->query($sql);
		if (!$ok) {
			$this->errors[] = $subject.': '.$conn->error;
			dol_syslog('EInvoiceFetcher: post action '.$action.' failed for '.$msg['key'].': '.$conn->error, LOG_WARNING);
		}

		return $created;
	}

	/**
	 * Store a file in the inbox (used by fetcher and upload).
	 *
	 * @param array<string,mixed> $an       Result of EInvoiceParser::analyze()
	 * @param string              $content  File content
	 * @param string              $filename Original name
	 * @param array<string,mixed> $meta     source, fk_mail, mail_from, mail_subject, mail_date
	 * @param User                $user     User
	 * @return int id of the new inbox entry, -2 if duplicate, -1 on error
	 */
	public function storeDocument($an, $content, $filename, $meta, $user)
	{
		global $conf;

		$hash = hash('sha256', $content);
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."einvoice_inbox WHERE entity = ".((int) $conf->entity)." AND file_hash = '".$this->db->escape($hash)."'";
		$res = $this->db->query($sql);
		if ($res && $this->db->num_rows($res) > 0) {
			return -2;
		}

		$safe = dol_sanitizeFileName(self::cut(basename(str_replace('\\', '/', $filename)), 150));
		if ($safe === '') {
			$safe = 'document';
		}
		$rel = 'inbox/'.dol_print_date(dol_now(), '%Y/%m').'/'.substr($hash, 0, 16);
		$dir = self::dataDir().'/'.$rel;
		if (dol_mkdir($dir) < 0) {
			$this->error = 'Cannot create directory '.$dir;
			return -1;
		}
		if (file_put_contents($dir.'/'.$safe, $content) === false) {
			$this->error = 'Cannot write file';
			return -1;
		}
		dolChmod($dir.'/'.$safe);
		$xmlRel = null;
		if ($an['format'] === 'zugferd' && $an['xml'] !== null) {
			file_put_contents($dir.'/embedded-invoice.xml', $an['xml']);
			dolChmod($dir.'/embedded-invoice.xml');
			$xmlRel = $rel.'/embedded-invoice.xml';
		}

		$doc = new EInvoiceInbox($this->db);
		$doc->source = $meta['source'];
		$doc->fk_mail = !empty($meta['fk_mail']) ? (int) $meta['fk_mail'] : null;
		$doc->mail_from = isset($meta['mail_from']) ? $meta['mail_from'] : '';
		$doc->mail_subject = isset($meta['mail_subject']) ? $meta['mail_subject'] : '';
		$doc->mail_date = !empty($meta['mail_date']) ? (int) $meta['mail_date'] : null;
		$doc->filename = $filename;
		$doc->file_hash = $hash;
		$doc->file_path = $rel.'/'.$safe;
		$doc->xml_path = $xmlRel;
		$doc->format = $an['format'];
		foreach (array('profile', 'doc_type', 'invoice_ref', 'invoice_date', 'seller_name', 'seller_vat', 'seller_taxnumber', 'buyer_name', 'buyer_reference', 'currency', 'total_ht', 'total_ttc', 'amount_due') as $f) {
			$doc->$f = isset($an['data'][$f]) ? $an['data'][$f] : null;
		}
		if ($an['kind'] === 'einvoice') {
			$doc->status = $an['error'] ? EInvoiceInbox::STATUS_ERROR : EInvoiceInbox::STATUS_NEW;
		} else {
			$doc->status = EInvoiceInbox::STATUS_NOEINVOICE;
		}
		$doc->error_msg = $an['error'];
		if ($doc->status !== EInvoiceInbox::STATUS_ERROR) {
			$doc->fk_soc = $doc->findSupplier();
		}
		// Same invoice already received as another file (e.g. XML and ZUGFeRD PDF in one mail)?
		$dupOf = $doc->findSameInvoice();
		if ($dupOf) {
			$doc->status = EInvoiceInbox::STATUS_IGNORED;
			$doc->note = 'duplicate_of:'.$dupOf;
		}

		$id = $doc->create($user);
		if ($id < 0) {
			$this->error = $doc->error;
			return -1;
		}
		return $id;
	}

	/**
	 * Cut a string to a max length (multibyte safe).
	 *
	 * @param string $s   String
	 * @param int    $len Length
	 * @return string
	 */
	public static function cut($s, $len)
	{
		return function_exists('mb_substr') ? mb_substr((string) $s, 0, $len, 'UTF-8') : substr((string) $s, 0, $len);
	}
}
