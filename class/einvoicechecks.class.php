<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/einvoicechecks.class.php
 * \ingroup    einvoice
 * \brief      Plausibility checks of a received e-invoice (not a full EN 16931 validation)
 */

require_once __DIR__.'/einvoicedetails.class.php';

/**
 * Finds things a person should look at before booking: wrong recipient, legally incomplete
 * profiles, missing mandatory data, changed bank account, arithmetic errors.
 */
class EInvoiceChecks
{
	/**
	 * Run all checks.
	 *
	 * @param DoliDB              $db      Database
	 * @param EInvoiceInbox       $doc     Inbox entry
	 * @param array<string,mixed> $details Result of EInvoiceDetails::read()
	 * @param Societe             $mysoc   Own company
	 * @param Translate           $langs   Translations
	 * @return array<int,array{level:string,text:string}> level: warning | info
	 */
	public static function run($db, $doc, $details, $mysoc, $langs)
	{
		$out = array();
		$add = function ($level, $key, ...$args) use (&$out, $langs) {
			$out[] = array('level' => $level, 'text' => $langs->trans($key, ...$args));
		};

		// 1. Recipient: is this invoice addressed to us?
		$buyerVat = self::normVat($details['buyer_vat']);
		$ownVat = self::normVat($mysoc->tva_intra);
		if ($buyerVat !== '' && $ownVat !== '') {
			if ($buyerVat !== $ownVat) {
				$add('warning', 'EInvoiceCheckBuyerVat', $details['buyer_vat'], $mysoc->tva_intra);
			}
		} elseif ((string) $doc->buyer_name !== '' && (string) $mysoc->name !== '' && !self::sameName($doc->buyer_name, $mysoc->name)) {
			$add('warning', 'EInvoiceCheckBuyerName', $doc->buyer_name, $mysoc->name);
		}

		// 2. Profile: MINIMUM and BASIC WL are no invoices in the sense of § 14 UStG
		$profile = strtolower((string) $doc->profile);
		if (strpos($profile, 'minimum') !== false || strpos($profile, 'basicwl') !== false) {
			$add('warning', 'EInvoiceCheckProfile', strpos($profile, 'minimum') !== false ? 'MINIMUM' : 'BASIC WL');
		}

		// 3. Mandatory data
		if (trim((string) $doc->invoice_ref) === '') {
			$add('warning', 'EInvoiceCheckNoRef');
		}
		if (empty($doc->invoice_date)) {
			$add('warning', 'EInvoiceCheckNoDate');
		} elseif ($db->jdate($doc->invoice_date) > dol_now() + 86400) {
			$add('info', 'EInvoiceCheckFutureDate');
		}
		if (trim((string) $doc->seller_vat) === '' && trim((string) $doc->seller_taxnumber) === '') {
			$add('warning', 'EInvoiceCheckNoSellerTax');
		} elseif (preg_match('/^DE/i', self::normVat($doc->seller_vat)) && !preg_match('/^DE\d{9}$/', self::normVat($doc->seller_vat))) {
			$add('warning', 'EInvoiceCheckSellerVatFormat', $doc->seller_vat);
		}

		// 4. Payment data
		$ibans = array_values(array_unique(array_filter($details['ibans'])));
		if (in_array((string) $details['payment_means'], array('30', '58'), true) && !$ibans) {
			$add('info', 'EInvoiceCheckNoIban');
		}
		foreach ($ibans as $iban) {
			if (!self::ibanValid($iban)) {
				$add('warning', 'EInvoiceCheckIbanInvalid', $iban);
			}
		}
		if ($ibans && $doc->fk_soc) {
			$known = self::supplierIbans($db, $doc->fk_soc);
			if ($known && !array_intersect($ibans, $known)) {
				$add('warning', 'EInvoiceCheckIbanChanged', implode(', ', $ibans), implode(', ', $known));
			}
		}
		if ($details['due_date'] === '' && trim((string) $details['payment_terms']) === '') {
			$add('info', 'EInvoiceCheckNoDue');
		}
		if ($details['currency'] !== '' && $details['currency'] !== 'EUR') {
			$add('info', 'EInvoiceCheckCurrency', $details['currency']);
		}

		// 5. Arithmetic
		$problems = EInvoiceDetails::check($details);
		if ($problems) {
			$out[] = array('level' => 'warning', 'text' => $langs->trans('EInvoiceArithmeticProblem').' '.implode(' · ', $problems));
		}

		return $out;
	}

	/**
	 * @param string $v VAT id
	 * @return string Upper case, without spaces/dots/dashes
	 */
	public static function normVat($v)
	{
		return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $v));
	}

	/**
	 * Loose name comparison: legal form, punctuation, umlauts and case are ignored;
	 * one name contained in the other counts as the same.
	 *
	 * @param string $a Name
	 * @param string $b Name
	 * @return bool
	 */
	public static function sameName($a, $b)
	{
		$norm = function ($s) {
			$s = mb_strtolower((string) $s, 'UTF-8');
			$s = strtr($s, array('ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', '&' => ' und '));
			$s = preg_replace('/[^a-z0-9]+/', ' ', $s);
			$s = preg_replace('/\b(gmbh|mbh|co|kg|ohg|gbr|ug|haftungsbeschraenkt|ag|se|ek|e k|e v|ev|inh|inhaber|und|the|ltd|inc)\b/', ' ', $s);
			return trim(preg_replace('/\s+/', ' ', $s));
		};
		$x = $norm($a);
		$y = $norm($b);
		if ($x === '' || $y === '') {
			return true;
		}
		if ($x === $y || strpos($x, $y) !== false || strpos($y, $x) !== false) {
			return true;
		}
		similar_text($x, $y, $pct);
		return $pct >= 85;
	}

	/**
	 * ISO 13616 check digits.
	 *
	 * @param string $iban IBAN without spaces
	 * @return bool
	 */
	public static function ibanValid($iban)
	{
		$iban = strtoupper(preg_replace('/\s+/', '', (string) $iban));
		if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban)) {
			return false;
		}
		if (substr($iban, 0, 2) === 'DE' && strlen($iban) !== 22) {
			return false;
		}
		$r = substr($iban, 4).substr($iban, 0, 4);
		$num = '';
		foreach (str_split($r) as $c) {
			$num .= ctype_alpha($c) ? (string) (ord($c) - 55) : $c;
		}
		$mod = 0;
		foreach (str_split($num, 7) as $part) {
			$mod = (int) (($mod.$part) % 97);
		}
		return $mod === 1;
	}

	/**
	 * IBANs stored for a third party.
	 *
	 * @param DoliDB $db    Database
	 * @param int    $socid Third party
	 * @return string[]
	 */
	public static function supplierIbans($db, $socid)
	{
		$list = array();
		$sql = "SELECT iban_prefix FROM ".MAIN_DB_PREFIX."societe_rib WHERE fk_soc = ".((int) $socid)." AND type = 'ban'";
		$res = $db->query($sql);
		while ($res && ($o = $db->fetch_object($res))) {
			$v = (string) $o->iban_prefix;
			if (function_exists('dolDecrypt')) {
				$v = (string) dolDecrypt($v);
			}
			$v = strtoupper(preg_replace('/\s+/', '', $v));
			if ($v !== '') {
				$list[] = $v;
			}
		}
		return array_values(array_unique($list));
	}
}
