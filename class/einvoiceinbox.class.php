<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/einvoiceinbox.class.php
 * \ingroup    einvoice
 * \brief      One received document in the e-invoice inbox
 */

/**
 * Inbox entry.
 */
class EInvoiceInbox
{
	const STATUS_NEW = 0;
	const STATUS_DONE = 1;
	const STATUS_NOEINVOICE = 2;
	const STATUS_IGNORED = 5;
	const STATUS_ERROR = 9;

	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';

	public $id;
	public $entity;
	public $source;
	public $fk_mail;
	public $mail_from;
	public $mail_subject;
	public $mail_date;
	public $filename;
	public $file_hash;
	public $file_path;
	public $xml_path;
	public $format;
	public $profile;
	public $doc_type;
	public $invoice_ref;
	public $invoice_date;
	public $seller_name;
	public $seller_vat;
	public $seller_taxnumber;
	public $buyer_name;
	public $buyer_reference;
	public $currency;
	public $total_ht;
	public $total_ttc;
	public $amount_due;
	public $status = self::STATUS_NEW;
	public $error_msg;
	public $fk_soc;
	public $fk_facture_fourn;
	public $note;
	public $datec;
	public $fk_user_creat;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * SQL value helper.
	 *
	 * @param mixed  $v    Value
	 * @param string $type s (string), i (int), f (float), d (date Y-m-d), t (timestamp)
	 * @param int    $len  Max string length
	 * @return string
	 */
	private function q($v, $type = 's', $len = 255)
	{
		if ($v === null || $v === '') {
			return 'NULL';
		}
		switch ($type) {
			case 'i':
				return (string) ((int) $v);
			case 'f':
				return is_numeric($v) ? sprintf('%.8F', (float) $v) : 'NULL';
			case 'd':
				return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? "'".$this->db->escape($v)."'" : 'NULL';
			case 't':
				return "'".$this->db->idate((int) $v)."'";
			default:
				$s = function_exists('mb_substr') ? mb_substr((string) $v, 0, $len, 'UTF-8') : substr((string) $v, 0, $len);
				return "'".$this->db->escape($s)."'";
		}
	}

	/**
	 * Insert.
	 *
	 * @param User $user User
	 * @return int id or <0
	 */
	public function create($user)
	{
		global $conf;
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."einvoice_inbox (entity, source, fk_mail, mail_from, mail_subject, mail_date,";
		$sql .= " filename, file_hash, file_path, xml_path, format, profile, doc_type, invoice_ref, invoice_date,";
		$sql .= " seller_name, seller_vat, seller_taxnumber, buyer_name, buyer_reference, currency, total_ht, total_ttc, amount_due,";
		$sql .= " status, error_msg, fk_soc, note, datec, fk_user_creat) VALUES (";
		$sql .= ((int) $conf->entity).", ".$this->q($this->source, 's', 16).", ".$this->q($this->fk_mail, 'i').", ";
		$sql .= $this->q($this->mail_from).", ".$this->q($this->mail_subject).", ".$this->q($this->mail_date, 't').", ";
		$sql .= $this->q($this->filename).", ".$this->q($this->file_hash, 's', 64).", ".$this->q($this->file_path).", ".$this->q($this->xml_path).", ";
		$sql .= $this->q($this->format, 's', 16).", ".$this->q($this->profile).", ".$this->q($this->doc_type, 's', 8).", ";
		$sql .= $this->q($this->invoice_ref, 's', 128).", ".$this->q($this->invoice_date, 'd').", ";
		$sql .= $this->q($this->seller_name).", ".$this->q($this->seller_vat, 's', 64).", ".$this->q($this->seller_taxnumber, 's', 64).", ";
		$sql .= $this->q($this->buyer_name).", ".$this->q($this->buyer_reference).", ".$this->q($this->currency, 's', 3).", ";
		$sql .= $this->q($this->total_ht, 'f').", ".$this->q($this->total_ttc, 'f').", ".$this->q($this->amount_due, 'f').", ";
		$sql .= ((int) $this->status).", ".$this->q($this->error_msg, 's', 65000).", ".$this->q($this->fk_soc, 'i').", ".$this->q($this->note, 's', 65000).", ";
		$sql .= "'".$this->db->idate(dol_now())."', ".((int) $user->id).")";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->id = $this->db->last_insert_id(MAIN_DB_PREFIX.'einvoice_inbox');
		return $this->id;
	}

	/**
	 * Load.
	 *
	 * @param int $id Id
	 * @return int >0 if OK, 0 if not found, <0 if KO
	 */
	public function fetch($id)
	{
		global $conf;
		$sql = "SELECT * FROM ".MAIN_DB_PREFIX."einvoice_inbox WHERE rowid = ".((int) $id)." AND entity = ".((int) $conf->entity);
		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($res);
		if (!$obj) {
			return 0;
		}
		foreach (get_object_vars($obj) as $k => $v) {
			if ($k === 'rowid') {
				$this->id = (int) $v;
			} elseif (property_exists($this, $k)) {
				$this->$k = $v;
			}
		}
		$this->mail_date = $this->db->jdate($obj->mail_date);
		$this->datec = $this->db->jdate($obj->datec);
		$this->status = (int) $obj->status;
		return 1;
	}

	/**
	 * Change status.
	 *
	 * @param int  $status Status
	 * @param User $user   User
	 * @return int
	 */
	public function setStatus($status, $user)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."einvoice_inbox SET status = ".((int) $status).", fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->status = (int) $status;
		return 1;
	}

	/**
	 * Set the supplier (third party).
	 *
	 * @param int  $socid Third party id (0 = none)
	 * @param User $user  User
	 * @return int
	 */
	public function setSupplier($socid, $user)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."einvoice_inbox SET fk_soc = ".($socid > 0 ? (int) $socid : "NULL").", fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->fk_soc = $socid > 0 ? (int) $socid : null;
		return 1;
	}

	/**
	 * Delete the entry and its files.
	 *
	 * @param User $user User
	 * @return int
	 */
	public function delete($user)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."einvoice_inbox WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$path = $this->getFullPath('file');
		if ($path) {
			dol_delete_dir_recursive(dirname($path));
		}
		return 1;
	}

	/**
	 * Absolute path of the stored original or extracted XML, '' if missing / outside the data dir.
	 *
	 * @param string $which file | xml
	 * @return string
	 */
	public function getFullPath($which = 'file')
	{
		require_once __DIR__.'/einvoicefetcher.class.php';
		$rel = ($which === 'xml') ? $this->xml_path : $this->file_path;
		if (empty($rel) || strpos($rel, '..') !== false) {
			return '';
		}
		$base = realpath(EInvoiceFetcher::dataDir());
		$full = realpath(EInvoiceFetcher::dataDir().'/'.$rel);
		if (!$base || !$full || strpos($full, $base.DIRECTORY_SEPARATOR) !== 0) {
			return '';
		}
		return $full;
	}

	/**
	 * XML of the e-invoice (the file itself or the extracted XML).
	 *
	 * @return string|null
	 */
	public function getXml()
	{
		if ($this->format === 'cii' || $this->format === 'ubl') {
			$p = $this->getFullPath('file');
		} elseif ($this->format === 'zugferd') {
			$p = $this->getFullPath('xml');
		} else {
			return null;
		}
		if (!$p) {
			return null;
		}
		$c = file_get_contents($p);
		return $c === false ? null : $c;
	}

	/**
	 * Find the supplier by VAT id, then tax number, then exact name (only if unique).
	 *
	 * @return int|null Third party id
	 */
	public function findSupplier()
	{
		$norm = function ($s) {
			return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $s));
		};

		$vat = $norm($this->seller_vat);
		if ($vat !== '') {
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE entity IN (".getEntity('societe').")";
			$sql .= " AND REPLACE(REPLACE(REPLACE(UPPER(tva_intra), ' ', ''), '.', ''), '-', '') = '".$this->db->escape($vat)."'";
			$id = $this->uniqueId($sql);
			if ($id) {
				return $id;
			}
		}
		$tax = $norm($this->seller_taxnumber);
		if ($tax !== '') {
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE entity IN (".getEntity('societe').")";
			$sql .= " AND (REPLACE(REPLACE(REPLACE(idprof1, ' ', ''), '/', ''), '-', '') = '".$this->db->escape($tax)."'";
			$sql .= " OR REPLACE(REPLACE(REPLACE(idprof2, ' ', ''), '/', ''), '-', '') = '".$this->db->escape($tax)."')";
			$id = $this->uniqueId($sql);
			if ($id) {
				return $id;
			}
		}
		if (!empty($this->seller_name)) {
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE entity IN (".getEntity('societe').")";
			$sql .= " AND fournisseur = 1 AND nom = '".$this->db->escape($this->seller_name)."'";
			$id = $this->uniqueId($sql);
			if ($id) {
				return $id;
			}
		}
		return null;
	}

	/**
	 * Find an earlier inbox entry for the same invoice (same invoice number and
	 * same seller VAT id, or same seller name when no VAT id is given).
	 *
	 * @return int|null Id of the earlier entry
	 */
	public function findSameInvoice()
	{
		global $conf;
		if (empty($this->invoice_ref)) {
			return null;
		}
		$sql = "SELECT rowid, seller_vat, seller_name, doc_type FROM ".MAIN_DB_PREFIX."einvoice_inbox";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND invoice_ref = '".$this->db->escape($this->invoice_ref)."'";
		$sql .= " ORDER BY rowid";
		$res = $this->db->query($sql);
		if (!$res) {
			return null;
		}
		$norm = function ($s) {
			return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $s));
		};
		while ($o = $this->db->fetch_object($res)) {
			if ((string) $o->doc_type !== (string) $this->doc_type) {
				continue; // invoice and credit note may share a number
			}
			if ($norm($this->seller_vat) !== '' && $norm($o->seller_vat) !== '') {
				if ($norm($this->seller_vat) === $norm($o->seller_vat)) {
					return (int) $o->rowid;
				}
				continue;
			}
			if (!empty($this->seller_name) && mb_strtolower(trim($o->seller_name)) === mb_strtolower(trim($this->seller_name))) {
				return (int) $o->rowid;
			}
		}
		return null;
	}

	/**
	 * Id of the entry this one duplicates, from the note.
	 *
	 * @return int
	 */
	public function getDuplicateOf()
	{
		return preg_match('/duplicate_of:(\d+)/', (string) $this->note, $m) ? (int) $m[1] : 0;
	}

	/**
	 * Return the id if the query returns exactly one row.
	 *
	 * @param string $sql SQL
	 * @return int|null
	 */
	private function uniqueId($sql)
	{
		$res = $this->db->query($sql);
		if ($res && $this->db->num_rows($res) == 1) {
			$o = $this->db->fetch_object($res);
			return (int) $o->rowid;
		}
		return null;
	}

	/**
	 * Status badge.
	 *
	 * @param int $status Status
	 * @return string HTML
	 */
	public static function statusBadge($status)
	{
		global $langs;
		$map = array(
			self::STATUS_NEW => array('EInvoiceStatusNew', 'status1'),
			self::STATUS_DONE => array('EInvoiceStatusDone', 'status4'),
			self::STATUS_NOEINVOICE => array('EInvoiceStatusNoEInvoice', 'status0'),
			self::STATUS_IGNORED => array('EInvoiceStatusIgnored', 'status5'),
			self::STATUS_ERROR => array('EInvoiceStatusError', 'status8'),
		);
		$s = isset($map[(int) $status]) ? $map[(int) $status] : array('Unknown', 'status0');
		return dolGetStatus($langs->trans($s[0]), $langs->trans($s[0]), '', $s[1], 1);
	}

	/**
	 * Label of a document type code (BT-3).
	 *
	 * @param string $code Code
	 * @return string
	 */
	public static function docTypeLabel($code)
	{
		global $langs;
		$map = array('380' => 'EInvoiceDocType380', '381' => 'EInvoiceDocType381', '384' => 'EInvoiceDocType384', '389' => 'EInvoiceDocType389', '326' => 'EInvoiceDocType326', '875' => 'EInvoiceDocType875', '876' => 'EInvoiceDocType876', '877' => 'EInvoiceDocType877');
		return isset($map[$code]) ? $langs->trans($map[$code]) : (string) $code;
	}
}
