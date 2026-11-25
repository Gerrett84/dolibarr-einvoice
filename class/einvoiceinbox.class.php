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
require_once __DIR__.'/../lib/einvoice.lib.php';

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
	/** @var string[] Warnings of the last createSupplierInvoice() */
	public $warnings = array();
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
	 * Supporting documents inside the e-invoice (XML attachments, further files of a ZUGFeRD PDF).
	 *
	 * @param bool $withData Include the content
	 * @return array{files:array<int,array<string,mixed>>,links:array<int,array<string,string>>}
	 */
	public function getAttachments($withData = false)
	{
		require_once __DIR__.'/einvoiceattachments.class.php';
		$pdf = null;
		if ($this->format === 'zugferd') {
			$p = $this->getFullPath('file');
			$pdf = $p ? file_get_contents($p) : null;
			if ($pdf === false) {
				$pdf = null;
			}
		}
		return EInvoiceAttachments::read($this->getXml(), $pdf, $withData);
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
	 * Seller details read again from the stored XML (address, contact, bank account).
	 *
	 * @return array<string,string>
	 */
	public function getSellerDetails()
	{
		$d = array('name' => (string) $this->seller_name, 'vat' => (string) $this->seller_vat, 'taxnumber' => (string) $this->seller_taxnumber,
			'address' => '', 'zip' => '', 'town' => '', 'country' => '', 'email' => '', 'phone' => '', 'iban' => '', 'bic' => '');
		$xml = $this->getXml();
		if ($xml !== null) {
			dol_include_once('/einvoice/class/einvoiceparser.class.php');
			$p = EInvoiceParser::parseXml($xml);
			foreach (array('address', 'zip', 'town', 'country', 'email', 'phone', 'iban', 'bic') as $k) {
				if (!empty($p['data']['seller_'.$k])) {
					$d[$k] = (string) $p['data']['seller_'.$k];
				}
			}
		}
		$d['iban'] = strtoupper(preg_replace('/\s+/', '', $d['iban']));
		return $d;
	}

	/**
	 * Create a new supplier (third party) and link this entry to it.
	 *
	 * @param array<string,string> $d    Data (keys as in getSellerDetails())
	 * @param User                 $user User
	 * @return int Id of the new third party, <0 if KO
	 */
	public function createSupplier($d, $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/companybankaccount.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';

		if (trim($d['name']) === '') {
			$this->error = einvoice_t('EInvoiceErrNameMissing', 'Name missing');
			return -1;
		}
		$this->db->begin();
		$soc = new Societe($this->db);
		$soc->name = trim($d['name']);
		$soc->client = 0;
		$soc->fournisseur = 1;
		$soc->code_fournisseur = -1; // automatic code if the numbering module supports it
		$soc->status = 1;
		$soc->address = trim($d['address']);
		$soc->zip = trim($d['zip']);
		$soc->town = trim($d['town']);
		if (!empty($d['country'])) {
			$soc->country_id = (int) dol_getIdFromCode($this->db, strtoupper(trim($d['country'])), 'c_country', 'code', 'rowid');
			$soc->country_code = strtoupper(trim($d['country']));
		}
		$soc->email = trim($d['email']);
		$soc->phone = trim($d['phone']);
		$soc->tva_intra = trim($d['vat']);
		$soc->tva_assuj = 1;
		$soc->idprof1 = trim($d['taxnumber']); // DE: Steuernummer
		$socid = $soc->create($user);
		if ($socid <= 0) {
			$this->error = $soc->error ? $soc->error : implode(', ', $soc->errors);
			$this->db->rollback();
			return -1;
		}
		if (!empty($d['iban'])) {
			$rib = new CompanyBankAccount($this->db);
			$rib->socid = $socid;
			$rib->type = 'ban';
			$rib->label = 'E-Rechnung';
			$rib->iban = $d['iban'];
			$rib->bic = trim($d['bic']);
			$rib->owner_name = $soc->name;
			$rib->default_rib = 1;
			if ($rib->create($user) <= 0 || $rib->update($user) <= 0) {
				$this->error = $rib->error;
				$this->db->rollback();
				return -1;
			}
		}
		if ($this->setSupplier($socid, $user) < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return $socid;
	}

	/**
	 * Parsed details (lines, totals, payment) of the stored e-invoice, null if no XML.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getDetails()
	{
		$xml = $this->getXml();
		if ($xml === null) {
			return null;
		}
		dol_include_once('/einvoice/class/einvoicedetails.class.php');
		return EInvoiceDetails::read($xml);
	}

	/**
	 * Is this a credit note (document type 381 and similar)?
	 *
	 * @return bool
	 */
	public function isCreditNote()
	{
		return in_array((string) $this->doc_type, array('381', '261', '262', '296', '308', '396', '420', '458', '532'), true);
	}

	/**
	 * Prepayment / partial construction invoice: becomes a deposit invoice in Dolibarr.
	 *
	 * @return bool
	 */
	public function isDeposit()
	{
		return in_array((string) $this->doc_type, array('386', '875'), true);
	}

	/**
	 * Deposit invoices of the linked supplier that can be deducted from a final invoice.
	 *
	 * available: validated deposits whose discount is not used yet (or not created yet), by id, with
	 *            ref, ref_supplier, date, total_ht, total_ttc, paid, discounts (unused discount ids, empty = convert first)
	 * drafts:    deposits still in draft (must be validated first), by id => ref
	 * preselect: ids that are probably meant (billing reference or sum equal to the prepaid amount)
	 *
	 * @return array{available:array<int,array<string,mixed>>,drafts:array<int,string>,preselect:int[]}
	 */
	public function getDepositCandidates()
	{
		$res = array('available' => array(), 'drafts' => array(), 'preselect' => array());
		if (!$this->fk_soc) {
			return $res;
		}
		$sql = "SELECT f.rowid, f.ref, f.ref_supplier, f.datef, f.total_ht, f.total_ttc, f.fk_statut, f.paye FROM ".MAIN_DB_PREFIX."facture_fourn as f";
		$sql .= " WHERE f.fk_soc = ".((int) $this->fk_soc)." AND f.type = 3 AND f.entity IN (".getEntity('supplier_invoice').")";
		$sql .= " ORDER BY f.datef, f.rowid";
		$resql = $this->db->query($sql);
		while ($resql && ($o = $this->db->fetch_object($resql))) {
			if ((int) $o->fk_statut === 0) {
				$res['drafts'][(int) $o->rowid] = $o->ref;
				continue;
			}
			$disc = array();
			$used = false;
			$sql2 = "SELECT rowid, fk_invoice_supplier, fk_invoice_supplier_line FROM ".MAIN_DB_PREFIX."societe_remise_except";
			$sql2 .= " WHERE fk_invoice_supplier_source = ".((int) $o->rowid)." AND discount_type = 1";
			$res2 = $this->db->query($sql2);
			while ($res2 && ($r = $this->db->fetch_object($res2))) {
				if ($r->fk_invoice_supplier || $r->fk_invoice_supplier_line) {
					$used = true;
				} else {
					$disc[] = (int) $r->rowid;
				}
			}
			if ($used || (int) $o->fk_statut === 3) { // already deducted or abandoned
				continue;
			}
			$res['available'][(int) $o->rowid] = array(
				'ref' => $o->ref, 'ref_supplier' => (string) $o->ref_supplier, 'date' => $this->db->jdate($o->datef),
				'total_ht' => (float) $o->total_ht, 'total_ttc' => (float) $o->total_ttc, 'paid' => (int) $o->paye, 'discounts' => $disc,
			);
		}

		// Preselection
		$d = $this->getDetails();
		$prepaid = $d ? (float) $d['prepaid'] : 0.0;
		if ($res['available'] && $prepaid > 0) {
			$byRef = array();
			$norm = function ($s) {
				return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $s));
			};
			if ($d['billing_ref'] !== '') {
				foreach ($res['available'] as $id => $c) {
					if ($c['ref_supplier'] !== '' && $norm($c['ref_supplier']) === $norm($d['billing_ref'])) {
						$byRef[] = $id;
					}
				}
			}
			$sumAll = array_sum(array_column($res['available'], 'total_ttc'));
			if ($byRef) {
				$res['preselect'] = $byRef;
			} elseif (abs($sumAll - $prepaid) < 0.011) {
				$res['preselect'] = array_keys($res['available']);
			} else {
				foreach ($res['available'] as $id => $c) {
					if (abs($c['total_ttc'] - $prepaid) < 0.011) {
						$res['preselect'] = array($id);
						break;
					}
				}
			}
		}
		return $res;
	}

	/**
	 * Convert a validated supplier deposit invoice into discounts, one per VAT rate
	 * (same as the button "Convert into discount" on the supplier invoice).
	 *
	 * @param int  $facid Deposit invoice
	 * @param User $user  User
	 * @return int[]|false Ids of the created discounts
	 */
	public function convertDepositToDiscount($facid, $user)
	{
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/discount.class.php';
		$dep = new FactureFournisseur($this->db);
		if ($dep->fetch($facid) <= 0 || (int) $dep->type !== FactureFournisseur::TYPE_DEPOSIT || (int) $dep->status === 0) {
			$this->error = einvoice_t('EInvoiceErrDepositNotFound', 'Deposit invoice %s not found or not validated', $facid);
			return false;
		}
		$sum = array();
		foreach ($dep->lines as $line) {
			if ($line->product_type < 9 && $line->total_ht != 0) {
				$k = $line->tva_tx.'|'.$line->vat_src_code;
				if (!isset($sum[$k])) {
					$sum[$k] = array('tva_tx' => (float) $line->tva_tx, 'code' => (string) $line->vat_src_code, 'ht' => 0.0, 'tva' => 0.0, 'ttc' => 0.0, 'mht' => 0.0, 'mtva' => 0.0, 'mttc' => 0.0);
				}
				$sum[$k]['ht'] += $line->total_ht;
				$sum[$k]['tva'] += $line->total_tva;
				$sum[$k]['ttc'] += $line->total_ttc;
				$sum[$k]['mht'] += $line->multicurrency_total_ht;
				$sum[$k]['mtva'] += $line->multicurrency_total_tva;
				$sum[$k]['mttc'] += $line->multicurrency_total_ttc;
			}
		}
		$ids = array();
		foreach ($sum as $v) {
			$disc = new DiscountAbsolute($this->db);
			$disc->description = '(DEPOSIT)';
			$disc->discount_type = 1;
			$disc->fk_soc = $dep->socid;
			$disc->socid = $dep->socid;
			$disc->fk_invoice_supplier_source = $dep->id;
			$disc->amount_ht = abs($v['ht']);
			$disc->amount_tva = abs($v['tva']);
			$disc->amount_ttc = abs($v['ttc']);
			$disc->multicurrency_amount_ht = abs($v['mht']);
			$disc->multicurrency_amount_tva = abs($v['mtva']);
			$disc->multicurrency_amount_ttc = abs($v['mttc']);
			$disc->tva_tx = abs($v['tva_tx']);
			$disc->vat_src_code = $v['code'];
			$r = $disc->create($user);
			if ($r <= 0) {
				$this->error = $disc->error;
				return false;
			}
			$ids[] = (int) $r;
		}
		return $ids;
	}

	/**
	 * Existing supplier invoice of the linked supplier with the same supplier reference.
	 *
	 * @return int Id or 0
	 */
	public function findExistingSupplierInvoice()
	{
		if (!$this->fk_soc || (string) $this->invoice_ref === '') {
			return 0;
		}
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."facture_fourn WHERE entity IN (".getEntity('invoice').")";
		$sql .= " AND fk_soc = ".((int) $this->fk_soc)." AND ref_supplier = '".$this->db->escape($this->invoice_ref)."'";
		$res = $this->db->query($sql);
		$o = $res ? $this->db->fetch_object($res) : null;
		return $o ? (int) $o->rowid : 0;
	}

	/**
	 * Supplier invoice of the linked supplier with the given supplier reference (source of a credit note).
	 *
	 * @param string $ref Supplier reference
	 * @return int Id or 0
	 */
	public function findSupplierInvoiceByRef($ref)
	{
		if (!$this->fk_soc || trim((string) $ref) === '') {
			return 0;
		}
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."facture_fourn WHERE entity IN (".getEntity('invoice').")";
		$sql .= " AND fk_soc = ".((int) $this->fk_soc)." AND type <> 2 AND ref_supplier = '".$this->db->escape(trim($ref))."'";
		$res = $this->db->query($sql);
		$o = $res ? $this->db->fetch_object($res) : null;
		return $o ? (int) $o->rowid : 0;
	}

	/**
	 * Create a draft supplier invoice (or credit note) from this e-invoice.
	 * Lines, document level allowances/charges, due date and payment mode are taken over,
	 * the original file is attached. Differences in the totals end up in $this->warnings.
	 *
	 * @param User  $user       User
	 * @param int   $sourceId   Invoice corrected by a credit note (0 = search by billing reference)
	 * @param int[] $depositIds Supplier deposit invoices to deduct (see getDepositCandidates())
	 * @return int Id of the supplier invoice, -1 error, -2 credit note without source invoice
	 */
	public function createSupplierInvoice($user, $sourceId = 0, $depositIds = array())
	{
		global $conf, $langs;
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$langs->loadLangs(array('bills', 'einvoice@einvoice'));
		$this->warnings = array();

		$soc = new Societe($this->db);
		if (!$this->fk_soc || $soc->fetch($this->fk_soc) <= 0) {
			$this->error = $langs->trans('EInvoiceSupplierUnknown');
			return -1;
		}
		if ($this->fk_facture_fourn) {
			$this->error = $langs->trans('EInvoiceAlreadyTransferred');
			return -1;
		}
		if ((string) $this->doc_type === '389') {
			$this->error = $langs->trans('EInvoiceSelfBilledHint');
			return -1;
		}
		$existing = $this->findExistingSupplierInvoice();
		if ($existing) {
			$this->error = $langs->trans('EInvoiceSupplierInvoiceExists', $this->invoice_ref);
			return -1;
		}
		$d = $this->getDetails();
		if (!$d || $d['syntax'] === '') {
			$this->error = $d ? $d['error'] : einvoice_t('EInvoiceErrNoXml', 'No invoice data (XML) found');
			return -1;
		}
		if ($this->currency && $this->currency !== $conf->currency) {
			$this->error = $langs->trans('EInvoiceForeignCurrency', $this->currency);
			return -1;
		}

		$credit = $this->isCreditNote();
		if ($credit) {
			if ($sourceId <= 0) {
				$sourceId = $this->findSupplierInvoiceByRef($d['billing_ref']);
			}
			if ($sourceId <= 0 && !getDolGlobalString('INVOICE_CREDIT_NOTE_STANDALONE')) {
				$this->error = $langs->trans('EInvoiceCreditNoteNeedsSource');
				return -2;
			}
		}

		$this->db->begin();
		$fac = new FactureFournisseur($this->db);
		$fac->socid = (int) $this->fk_soc;
		$fac->ref_supplier = (string) $this->invoice_ref;
		$fac->type = $credit ? FactureFournisseur::TYPE_CREDIT_NOTE : ($this->isDeposit() ? FactureFournisseur::TYPE_DEPOSIT : FactureFournisseur::TYPE_STANDARD);
		if ($credit && $sourceId > 0) {
			$fac->fk_facture_source = (int) $sourceId;
		}
		$fac->date = $this->invoice_date ? dol_stringtotime($this->invoice_date, 0) : dol_now();
		if ($d['due_date'] !== '') {
			$fac->date_echeance = dol_stringtotime($d['due_date'], 0);
		}
		$modeCode = self::paymentModeCode($d['payment_means']);
		if ($modeCode !== '') {
			$modeId = (int) dol_getIdFromCode($this->db, $modeCode, 'c_paiement', 'code', 'id', 1);
			if ($modeId > 0) {
				$fac->mode_reglement_id = $modeId;
			}
		}
		$note = array($langs->trans('EInvoiceNoteFrom', $this->id, $this->filename));
		foreach (array('order_ref' => 'EInvoiceOrderRef', 'contract_ref' => 'EInvoiceContractRef', 'billing_ref' => 'EInvoiceBillingRef') as $k => $lbl) {
			if ($d[$k] !== '') {
				$note[] = $langs->transnoentities($lbl).': '.$d[$k];
			}
		}
		if ($d['payment_terms'] !== '') {
			$note[] = $langs->transnoentities('PaymentConditions').': '.$d['payment_terms'];
		}
		foreach ($d['notes'] as $n) {
			$note[] = $n;
		}
		$fac->note_private = implode("\n", $note);

		$id = $fac->create($user);
		if ($id <= 0) {
			$this->error = $fac->error ? $fac->error : implode(', ', $fac->errors);
			$this->db->rollback();
			return -1;
		}

		$rang = 0;
		foreach ($d['lines'] as $l) {
			if ($l['info_only']) {
				continue;
			}
			$desc = !empty($l['summary']) ? $langs->transnoentities('EInvoiceSummaryLine', vatrate((string) $l['vat_rate'], true)) : $l['name'];
			if ($l['description'] !== '' && $l['description'] !== $l['name']) {
				$desc .= ($desc !== '' ? "\n" : '').$l['description'];
			}
			$type = in_array($l['unit'], array('HUR', 'MIN', 'DAY', 'MON', 'WEE', 'ANN', 'LH'), true) ? 1 : 0;
			$r = $fac->addline(
				$desc,
				(float) $l['unit_price'],
				(float) $l['vat_rate'],
				0,
				0,
				(float) $l['qty'],
				0,
				0,
				$l['period_start'] ? dol_stringtotime($l['period_start'], 0) : 0,
				$l['period_end'] ? dol_stringtotime($l['period_end'], 0) : 0,
				0,
				0,
				'HT',
				$type,
				++$rang,
				1,
				array(),
				null,
				0,
				0,
				(string) $l['seller_ref']
			);
			if ($r < 0) {
				$this->error = $fac->error;
				$this->db->rollback();
				return -1;
			}
		}
		foreach ($d['allowances'] as $a) {
			if (!$a['amount']) {
				continue;
			}
			if ($credit && !$a['charge']) {
				$this->warnings[] = $langs->trans('EInvoiceCreditNoteAllowance');
			}
			$label = $a['reason'] !== '' ? $a['reason'] : $langs->transnoentities($a['charge'] ? 'EInvoiceCharge' : 'EInvoiceAllowance');
			$r = $fac->addline($label, ($a['charge'] ? 1 : -1) * (float) $a['amount'], (float) $a['vat_rate'], 0, 0, 1, 0, 0, 0, 0, 0, 0, 'HT', 1, ++$rang, 1);
			if ($r < 0) {
				$this->error = $fac->error;
				$this->db->rollback();
				return -1;
			}
		}
		// Deduct deposits (same as "Convert into discount" + "apply discount" by hand)
		$appliedHt = 0.0;
		$appliedTtc = 0.0;
		$appliedRefs = array();
		if (!$credit && !$this->isDeposit() && $depositIds) {
			$cands = $this->getDepositCandidates();
			foreach (array_unique(array_map('intval', (array) $depositIds)) as $depId) {
				if (!isset($cands['available'][$depId])) {
					continue;
				}
				$c = $cands['available'][$depId];
				$remIds = $c['discounts'];
				if (!$remIds) {
					$remIds = $this->convertDepositToDiscount($depId, $user);
					if ($remIds === false) {
						$this->db->rollback();
						return -1;
					}
				}
				foreach ($remIds as $remId) {
					if ($fac->insert_discount($remId) < 0) {
						$this->error = $fac->error;
						$this->db->rollback();
						return -1;
					}
				}
				$appliedHt += $c['total_ht'];
				$appliedTtc += $c['total_ttc'];
				$appliedRefs[] = $c['ref'].($c['ref_supplier'] !== '' ? ' ('.$c['ref_supplier'].')' : '');
			}
		}

		// Round VAT per rate like the e-invoice does (instead of summing rounded line VAT)
		$fac->update_price(1, '1');
		$fac->fetch($id);

		// Totals check: what Dolibarr computed vs. what the e-invoice says
		$sign = $credit ? -1 : 1;
		if ($d['tax_basis'] !== null && abs($fac->total_ht - $sign * $d['tax_basis'] + $appliedHt) > 0.011) {
			$this->warnings[] = $langs->trans('EInvoiceTotalMismatch', $langs->transnoentities('AmountHT'), price($sign * $d['tax_basis'] - $appliedHt), price($fac->total_ht));
		}
		if ($d['grand_total'] !== null && abs($fac->total_ttc - $sign * $d['grand_total'] + $appliedTtc) > 0.011) {
			$this->warnings[] = $langs->trans('EInvoiceTotalMismatch', $langs->transnoentities('AmountTTC'), price($sign * $d['grand_total'] - $appliedTtc), price($fac->total_ttc));
		}
		if ($appliedRefs) {
			$this->warnings[] = $langs->trans('EInvoiceDepositsApplied', implode(', ', $appliedRefs), price($appliedTtc));
		}
		if (!empty($d['summary'])) {
			$this->warnings[] = $langs->trans('EInvoiceSummaryWarning');
		}
		if ($d['prepaid'] && abs($d['prepaid'] - $appliedTtc) > 0.011) {
			$this->warnings[] = $appliedRefs
				? $langs->trans('EInvoicePrepaidDiffers', price($appliedTtc), price($d['prepaid']))
				: $langs->trans('EInvoicePrepaid', price($d['prepaid']));
		}

		// Attach original (and the extracted XML of a PDF)
		$dir = $conf->fournisseur->facture->dir_output.'/'.get_exdir($fac->id, 2, 0, 0, $fac, 'invoice_supplier').dol_sanitizeFileName($fac->ref);
		dol_mkdir($dir);
		$src = $this->getFullPath('file');
		if ($src) {
			dol_copy($src, $dir.'/'.dol_sanitizeFileName($this->filename), '0', 1);
		}
		$xmlSrc = $this->getFullPath('xml');
		if ($xmlSrc && $xmlSrc !== $src) {
			dol_copy($xmlSrc, $dir.'/'.dol_sanitizeFileName(pathinfo($this->filename, PATHINFO_FILENAME).'.xml'), '0', 1);
		}
		// Supporting documents from inside the e-invoice
		$att = $this->getAttachments(true);
		foreach ($att['files'] as $f) {
			if ($f['error'] !== '' || $f['data'] === null) {
				continue;
			}
			$target = $dir.'/'.dol_sanitizeFileName($f['name']);
			if (!file_exists($target)) {
				file_put_contents($target, $f['data']);
				dolChmod($target);
			}
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX."einvoice_inbox SET fk_facture_fourn = ".((int) $fac->id).", status = ".self::STATUS_DONE.", fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		$this->fk_facture_fourn = $fac->id;
		$this->status = self::STATUS_DONE;
		return $fac->id;
	}

	/**
	 * Readable label of a UN/ECE Rec 20 unit code.
	 *
	 * @param string $code Unit code
	 * @return string
	 */
	public static function unitLabel($code)
	{
		global $langs;
		$code = (string) $code;
		if ($code === '') {
			return '';
		}
		$key = 'EInvoiceUnit_'.$code;
		$t = $langs->trans($key);
		return $t !== $key ? $t : $code;
	}

	/**
	 * UNTDID 4461 payment means code to Dolibarr payment mode code.
	 *
	 * @param string $code Code from the e-invoice
	 * @return string Dolibarr code or ''
	 */
	public static function paymentModeCode($code)
	{
		$map = array('10' => 'LIQ', '20' => 'CHQ', '30' => 'VIR', '31' => 'VIR', '42' => 'VIR', '58' => 'VIR', '49' => 'PRE', '59' => 'PRE', '48' => 'CB', '54' => 'CB', '55' => 'CB');
		return isset($map[(string) $code]) ? $map[(string) $code] : '';
	}

	/**
	 * Try to match all entries without supplier again (after a supplier was created or edited).
	 *
	 * @param DoliDB $db   Database handler
	 * @param User   $user User
	 * @return int Number of entries linked
	 */
	public static function relinkUnmatched($db, $user)
	{
		global $conf;
		$n = 0;
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."einvoice_inbox WHERE entity = ".((int) $conf->entity)." AND fk_soc IS NULL";
		$res = $db->query($sql);
		$ids = array();
		while ($res && ($o = $db->fetch_object($res))) {
			$ids[] = (int) $o->rowid;
		}
		foreach ($ids as $id) {
			$e = new self($db);
			if ($e->fetch($id) > 0) {
				$socid = $e->findSupplier();
				if ($socid && $e->setSupplier($socid, $user) > 0) {
					$n++;
				}
			}
		}
		return $n;
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
		$key = 'EInvoiceDocType'.preg_replace('/\D/', '', (string) $code);
		$t = $langs->trans($key);
		return $t !== $key ? $t : (string) $code;
	}
}
