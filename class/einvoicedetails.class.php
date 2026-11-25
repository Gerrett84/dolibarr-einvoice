<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       class/einvoicedetails.class.php
 * \ingroup    einvoice
 * \brief      Reads invoice lines, allowances/charges, VAT breakdown and payment data (CII + UBL)
 */

require_once __DIR__.'/einvoiceparser.class.php';

/**
 * Full content of an e-invoice, as far as needed for a supplier invoice.
 */
class EInvoiceDetails
{
	/**
	 * Read details from XML.
	 *
	 * Result keys: syntax, error, lines[], allowances[], taxes[], due_date, payment_terms,
	 * payment_means, prepaid, line_total, allowance_total, charge_total, tax_basis, tax_total,
	 * grand_total, amount_due, order_ref, contract_ref, billing_ref, delivery_date,
	 * period_start, period_end, notes[].
	 *
	 * Line keys: id, seller_ref, name, description, qty, unit, unit_price, total, vat_rate,
	 * vat_category, period_start, period_end, info_only.
	 * Allowance keys: charge (bool), amount, reason, vat_rate, vat_category.
	 *
	 * @param string $xml XML
	 * @return array<string,mixed>
	 */
	public static function read($xml)
	{
		$res = self::empty();
		$error = '';
		$dom = EInvoiceParser::loadXml($xml, $error);
		if (!$dom || !$dom->documentElement) {
			$res['error'] = $error ? $error : 'Invalid XML';
			return $res;
		}
		$root = $dom->documentElement;
		if ($root->namespaceURI === EInvoiceParser::NS_CII_RSM && $root->localName === 'CrossIndustryInvoice') {
			$res['syntax'] = 'cii';
			return self::addSummaryLines(self::readCii($dom, $res));
		}
		if (($root->namespaceURI === EInvoiceParser::NS_UBL_INVOICE && $root->localName === 'Invoice')
			|| ($root->namespaceURI === EInvoiceParser::NS_UBL_CREDITNOTE && $root->localName === 'CreditNote')) {
			$res['syntax'] = 'ubl';
			return self::addSummaryLines(self::readUbl($dom, $res));
		}
		$res['error'] = 'Not an e-invoice';
		return $res;
	}

	/**
	 * Empty result.
	 *
	 * @return array<string,mixed>
	 */
	private static function empty()
	{
		return array(
			'syntax' => '', 'error' => '', 'lines' => array(), 'allowances' => array(), 'taxes' => array(),
			'due_date' => '', 'payment_terms' => '', 'payment_means' => '', 'prepaid' => null,
			'line_total' => null, 'allowance_total' => null, 'charge_total' => null, 'tax_basis' => null,
			'tax_total' => null, 'grand_total' => null, 'amount_due' => null,
			'order_ref' => '', 'contract_ref' => '', 'billing_ref' => '', 'delivery_date' => '',
			'period_start' => '', 'period_end' => '', 'notes' => array(), 'summary' => false,
		);
	}

	/**
	 * CII (ZUGFeRD / Factur-X / XRechnung CII).
	 *
	 * @param DOMDocument          $dom Document
	 * @param array<string,mixed>  $res Result skeleton
	 * @return array<string,mixed>
	 */
	private static function readCii(DOMDocument $dom, $res)
	{
		$xp = new DOMXPath($dom);
		$xp->registerNamespace('rsm', EInvoiceParser::NS_CII_RSM);
		$xp->registerNamespace('ram', EInvoiceParser::NS_CII_RAM);
		$xp->registerNamespace('udt', EInvoiceParser::NS_CII_UDT);

		$tx = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction';
		$set = $tx.'/ram:ApplicableHeaderTradeSettlement';
		$sum = $set.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation';

		foreach ($xp->query('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IncludedNote') as $n) {
			$code = self::v($xp, 'ram:SubjectCode', $n);
			$txt = self::v($xp, 'ram:Content', $n);
			if ($txt !== '' && $code !== 'REG') { // REG = seller registration data, shown elsewhere
				$res['notes'][] = $txt;
			}
		}

		foreach ($xp->query($tx.'/ram:IncludedSupplyChainTradeLineItem') as $li) {
			$status = self::v($xp, 'ram:AssociatedDocumentLineDocument/ram:LineStatusReasonCode', $li);
			$qty = self::num(self::v($xp, 'ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', $li));
			$unit = self::attr($xp, 'ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', 'unitCode', $li);
			$price = self::num(self::v($xp, 'ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount', $li));
			$basis = self::num(self::v($xp, 'ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity', $li));
			if ($price !== null && $basis) {
				$price = $price / $basis;
			}
			$total = self::num(self::v($xp, 'ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', $li));
			$res['lines'][] = self::line(array(
				'id' => self::v($xp, 'ram:AssociatedDocumentLineDocument/ram:LineID', $li),
				'seller_ref' => self::v($xp, 'ram:SpecifiedTradeProduct/ram:SellerAssignedID', $li),
				'name' => self::v($xp, 'ram:SpecifiedTradeProduct/ram:Name', $li),
				'description' => self::v($xp, 'ram:SpecifiedTradeProduct/ram:Description', $li),
				'qty' => $qty,
				'unit' => $unit,
				'unit_price' => $price,
				'total' => $total,
				'vat_rate' => self::num(self::v($xp, 'ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent', $li)),
				'vat_category' => self::v($xp, 'ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode', $li),
				'period_start' => self::date(self::v($xp, 'ram:SpecifiedLineTradeSettlement/ram:BillingSpecifiedPeriod/ram:StartDateTime/udt:DateTimeString', $li)),
				'period_end' => self::date(self::v($xp, 'ram:SpecifiedLineTradeSettlement/ram:BillingSpecifiedPeriod/ram:EndDateTime/udt:DateTimeString', $li)),
				'info_only' => in_array($status, array('INFORMATION', 'GROUP'), true),
			));
		}

		foreach ($xp->query($set.'/ram:SpecifiedTradeAllowanceCharge') as $ac) {
			$ind = strtolower(self::v($xp, 'ram:ChargeIndicator/udt:Indicator', $ac));
			$res['allowances'][] = array(
				'charge' => ($ind === 'true' || $ind === '1'),
				'amount' => self::num(self::v($xp, 'ram:ActualAmount', $ac)),
				'reason' => self::v($xp, 'ram:Reason', $ac),
				'vat_rate' => self::num(self::v($xp, 'ram:CategoryTradeTax/ram:RateApplicablePercent', $ac)),
				'vat_category' => self::v($xp, 'ram:CategoryTradeTax/ram:CategoryCode', $ac),
			);
		}
		// Logistics charges (EXTENDED profile)
		foreach ($xp->query($set.'/ram:SpecifiedLogisticsServiceCharge') as $ac) {
			$res['allowances'][] = array(
				'charge' => true,
				'amount' => self::num(self::v($xp, 'ram:AppliedAmount', $ac)),
				'reason' => self::v($xp, 'ram:Description', $ac),
				'vat_rate' => self::num(self::v($xp, 'ram:AppliedTradeTax/ram:RateApplicablePercent', $ac)),
				'vat_category' => self::v($xp, 'ram:AppliedTradeTax/ram:CategoryCode', $ac),
			);
		}

		foreach ($xp->query($set.'/ram:ApplicableTradeTax') as $t) {
			$res['taxes'][] = array(
				'rate' => self::num(self::v($xp, 'ram:RateApplicablePercent', $t)),
				'category' => self::v($xp, 'ram:CategoryCode', $t),
				'basis' => self::num(self::v($xp, 'ram:BasisAmount', $t)),
				'amount' => self::num(self::v($xp, 'ram:CalculatedAmount', $t)),
				'reason' => self::v($xp, 'ram:ExemptionReason', $t),
			);
		}

		$res['payment_means'] = self::v($xp, $set.'/ram:SpecifiedTradeSettlementPaymentMeans/ram:TypeCode');
		$res['payment_terms'] = self::v($xp, $set.'/ram:SpecifiedTradePaymentTerms/ram:Description');
		$res['due_date'] = self::date(self::v($xp, $set.'/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString'));
		$res['period_start'] = self::date(self::v($xp, $set.'/ram:BillingSpecifiedPeriod/ram:StartDateTime/udt:DateTimeString'));
		$res['period_end'] = self::date(self::v($xp, $set.'/ram:BillingSpecifiedPeriod/ram:EndDateTime/udt:DateTimeString'));
		$res['billing_ref'] = self::v($xp, $set.'/ram:InvoiceReferencedDocument/ram:IssuerAssignedID');
		$res['order_ref'] = self::v($xp, $tx.'/ram:ApplicableHeaderTradeAgreement/ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID');
		$res['contract_ref'] = self::v($xp, $tx.'/ram:ApplicableHeaderTradeAgreement/ram:ContractReferencedDocument/ram:IssuerAssignedID');
		$res['delivery_date'] = self::date(self::v($xp, $tx.'/ram:ApplicableHeaderTradeDelivery/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString'));

		$res['line_total'] = self::num(self::v($xp, $sum.'/ram:LineTotalAmount'));
		$res['allowance_total'] = self::num(self::v($xp, $sum.'/ram:AllowanceTotalAmount'));
		$res['charge_total'] = self::num(self::v($xp, $sum.'/ram:ChargeTotalAmount'));
		$res['tax_basis'] = self::num(self::v($xp, $sum.'/ram:TaxBasisTotalAmount'));
		$res['tax_total'] = self::num(self::v($xp, $sum.'/ram:TaxTotalAmount'));
		$res['grand_total'] = self::num(self::v($xp, $sum.'/ram:GrandTotalAmount'));
		$res['prepaid'] = self::num(self::v($xp, $sum.'/ram:TotalPrepaidAmount'));
		$res['amount_due'] = self::num(self::v($xp, $sum.'/ram:DuePayableAmount'));
		return $res;
	}

	/**
	 * UBL Invoice / CreditNote (XRechnung UBL, Peppol).
	 *
	 * @param DOMDocument          $dom Document
	 * @param array<string,mixed>  $res Result skeleton
	 * @return array<string,mixed>
	 */
	private static function readUbl(DOMDocument $dom, $res)
	{
		$xp = new DOMXPath($dom);
		$xp->registerNamespace('cbc', EInvoiceParser::NS_UBL_CBC);
		$xp->registerNamespace('cac', EInvoiceParser::NS_UBL_CAC);
		$root = $dom->documentElement;

		foreach ($xp->query('cbc:Note', $root) as $n) {
			if (trim($n->textContent) !== '') {
				$res['notes'][] = trim($n->textContent);
			}
		}

		foreach ($xp->query('cac:InvoiceLine | cac:CreditNoteLine', $root) as $li) {
			$qtyNode = $xp->query('cbc:InvoicedQuantity | cbc:CreditedQuantity', $li)->item(0);
			$price = self::num(self::v($xp, 'cac:Price/cbc:PriceAmount', $li));
			$basis = self::num(self::v($xp, 'cac:Price/cbc:BaseQuantity', $li));
			if ($price !== null && $basis) {
				$price = $price / $basis;
			}
			$res['lines'][] = self::line(array(
				'id' => self::v($xp, 'cbc:ID', $li),
				'seller_ref' => self::v($xp, 'cac:Item/cac:SellersItemIdentification/cbc:ID', $li),
				'name' => self::v($xp, 'cac:Item/cbc:Name', $li),
				'description' => self::v($xp, 'cac:Item/cbc:Description', $li),
				'qty' => $qtyNode ? self::num(trim($qtyNode->textContent)) : null,
				'unit' => $qtyNode instanceof DOMElement ? $qtyNode->getAttribute('unitCode') : '',
				'unit_price' => $price,
				'total' => self::num(self::v($xp, 'cbc:LineExtensionAmount', $li)),
				'vat_rate' => self::num(self::v($xp, 'cac:Item/cac:ClassifiedTaxCategory/cbc:Percent', $li)),
				'vat_category' => self::v($xp, 'cac:Item/cac:ClassifiedTaxCategory/cbc:ID', $li),
				'period_start' => self::date(self::v($xp, 'cac:InvoicePeriod/cbc:StartDate', $li)),
				'period_end' => self::date(self::v($xp, 'cac:InvoicePeriod/cbc:EndDate', $li)),
				'info_only' => false,
			));
		}

		foreach ($xp->query('cac:AllowanceCharge', $root) as $ac) {
			$ind = strtolower(self::v($xp, 'cbc:ChargeIndicator', $ac));
			$res['allowances'][] = array(
				'charge' => ($ind === 'true' || $ind === '1'),
				'amount' => self::num(self::v($xp, 'cbc:Amount', $ac)),
				'reason' => self::v($xp, 'cbc:AllowanceChargeReason', $ac),
				'vat_rate' => self::num(self::v($xp, 'cac:TaxCategory/cbc:Percent', $ac)),
				'vat_category' => self::v($xp, 'cac:TaxCategory/cbc:ID', $ac),
			);
		}

		foreach ($xp->query('cac:TaxTotal/cac:TaxSubtotal', $root) as $t) {
			$res['taxes'][] = array(
				'rate' => self::num(self::v($xp, 'cac:TaxCategory/cbc:Percent', $t)),
				'category' => self::v($xp, 'cac:TaxCategory/cbc:ID', $t),
				'basis' => self::num(self::v($xp, 'cbc:TaxableAmount', $t)),
				'amount' => self::num(self::v($xp, 'cbc:TaxAmount', $t)),
				'reason' => self::v($xp, 'cac:TaxCategory/cbc:TaxExemptionReason', $t),
			);
		}

		$res['payment_means'] = self::v($xp, 'cac:PaymentMeans/cbc:PaymentMeansCode', $root);
		$res['payment_terms'] = self::v($xp, 'cac:PaymentTerms/cbc:Note', $root);
		$res['due_date'] = self::date(self::v($xp, 'cbc:DueDate', $root));
		if ($res['due_date'] === '') {
			$res['due_date'] = self::date(self::v($xp, 'cac:PaymentMeans/cbc:PaymentDueDate', $root));
		}
		$res['period_start'] = self::date(self::v($xp, 'cac:InvoicePeriod/cbc:StartDate', $root));
		$res['period_end'] = self::date(self::v($xp, 'cac:InvoicePeriod/cbc:EndDate', $root));
		$res['billing_ref'] = self::v($xp, 'cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID', $root);
		$res['order_ref'] = self::v($xp, 'cac:OrderReference/cbc:ID', $root);
		$res['contract_ref'] = self::v($xp, 'cac:ContractDocumentReference/cbc:ID', $root);
		$res['delivery_date'] = self::date(self::v($xp, 'cac:Delivery/cbc:ActualDeliveryDate', $root));

		$m = 'cac:LegalMonetaryTotal/';
		$res['line_total'] = self::num(self::v($xp, $m.'cbc:LineExtensionAmount', $root));
		$res['allowance_total'] = self::num(self::v($xp, $m.'cbc:AllowanceTotalAmount', $root));
		$res['charge_total'] = self::num(self::v($xp, $m.'cbc:ChargeTotalAmount', $root));
		$res['tax_basis'] = self::num(self::v($xp, $m.'cbc:TaxExclusiveAmount', $root));
		$res['tax_total'] = self::num(self::v($xp, 'cac:TaxTotal/cbc:TaxAmount', $root));
		$res['grand_total'] = self::num(self::v($xp, $m.'cbc:TaxInclusiveAmount', $root));
		$res['prepaid'] = self::num(self::v($xp, $m.'cbc:PrepaidAmount', $root));
		$res['amount_due'] = self::num(self::v($xp, $m.'cbc:PayableAmount', $root));
		return $res;
	}

	/**
	 * Profiles MINIMUM and BASIC WL carry no lines. Then build one summary line per VAT rate
	 * from the VAT breakdown (its basis already contains document level allowances/charges),
	 * or a single line from the totals when there is no breakdown either (MINIMUM).
	 *
	 * @param array<string,mixed> $res Result
	 * @return array<string,mixed>
	 */
	private static function addSummaryLines($res)
	{
		foreach ($res['lines'] as $l) {
			if (!$l['info_only']) {
				return $res;
			}
		}
		$sum = array();
		foreach ($res['taxes'] as $t) {
			if ($t['basis'] !== null) {
				$sum[] = array('basis' => $t['basis'], 'rate' => (float) $t['rate'], 'category' => $t['category']);
			}
		}
		if (!$sum && $res['tax_basis'] !== null) {
			$rate = 0.0;
			if ($res['tax_total'] && abs($res['tax_basis']) > 0.001) {
				$rate = round($res['tax_total'] / $res['tax_basis'] * 100, 2);
				foreach (array(0, 7, 19) as $std) {
					if (abs($rate - $std) < 0.1) {
						$rate = (float) $std;
					}
				}
			}
			$sum[] = array('basis' => $res['tax_basis'], 'rate' => $rate, 'category' => '');
		}
		if (!$sum) {
			return $res;
		}
		$i = 0;
		foreach ($sum as $x) {
			$res['lines'][] = array(
				'id' => (string) ++$i, 'seller_ref' => '', 'name' => '', 'description' => '',
				'qty' => 1.0, 'unit' => '', 'unit_price' => (float) $x['basis'], 'total' => (float) $x['basis'],
				'vat_rate' => $x['rate'], 'vat_category' => $x['category'], 'period_start' => '', 'period_end' => '',
				'info_only' => false, 'summary' => true,
			);
		}
		$res['summary'] = true;
		$res['allowances_included'] = $res['allowances'];
		$res['allowances'] = array(); // already contained in the VAT basis
		return $res;
	}

	/**
	 * Normalise a line: missing quantity = 1, unit price derived from the line total when the
	 * given price does not reproduce it (rebates on line level, rounding).
	 *
	 * @param array<string,mixed> $l Line
	 * @return array<string,mixed>
	 */
	private static function line($l)
	{
		if ($l['qty'] === null) {
			$l['qty'] = 1.0;
		}
		if ($l['total'] !== null && $l['qty'] != 0) {
			if ($l['unit_price'] === null || abs(round($l['unit_price'] * $l['qty'], 2) - $l['total']) > 0.005) {
				$l['unit_price'] = $l['total'] / $l['qty'];
			}
		}
		if ($l['vat_rate'] === null) {
			$l['vat_rate'] = 0.0;
		}
		$l['summary'] = false;
		return $l;
	}

	/**
	 * Check the arithmetic of the document itself. Returns a list of problems (empty = fine).
	 *
	 * @param array<string,mixed> $d Result of read()
	 * @return string[]
	 */
	public static function check($d)
	{
		$p = array();
		$lines = 0.0;
		foreach ($d['lines'] as $l) {
			if (!$l['info_only']) {
				$lines += (float) $l['total'];
			}
		}
		$allow = 0.0;
		$charge = 0.0;
		foreach ($d['allowances'] as $a) {
			if ($a['charge']) {
				$charge += (float) $a['amount'];
			} else {
				$allow += (float) $a['amount'];
			}
		}
		if (!empty($d['summary'])) {
			$lines = (float) $d['tax_basis'];
		} elseif ($d['line_total'] !== null && abs($lines - $d['line_total']) > 0.011) {
			$p[] = sprintf('lines %.2f <> line total %.2f', $lines, $d['line_total']);
		}
		$basis = $lines - $allow + $charge;
		if ($d['tax_basis'] !== null && abs($basis - $d['tax_basis']) > 0.011) {
			$p[] = sprintf('net %.2f <> tax basis %.2f', $basis, $d['tax_basis']);
		}
		$tax = 0.0;
		foreach ($d['taxes'] as $t) {
			$tax += (float) $t['amount'];
		}
		if ($d['tax_total'] !== null && $d['taxes'] && abs($tax - $d['tax_total']) > 0.011) {
			$p[] = sprintf('VAT breakdown %.2f <> VAT total %.2f', $tax, $d['tax_total']);
		}
		if ($d['grand_total'] !== null && $d['tax_basis'] !== null && $d['tax_total'] !== null
			&& abs($d['tax_basis'] + $d['tax_total'] - $d['grand_total']) > 0.011) {
			$p[] = sprintf('net + VAT %.2f <> gross %.2f', $d['tax_basis'] + $d['tax_total'], $d['grand_total']);
		}
		return $p;
	}

	/**
	 * Node value or ''.
	 *
	 * @param DOMXPath     $xp      XPath
	 * @param string       $q       Query
	 * @param DOMNode|null $context Context
	 * @return string
	 */
	private static function v(DOMXPath $xp, $q, $context = null)
	{
		$n = $context ? $xp->query($q, $context) : $xp->query($q);
		return ($n && $n->length) ? trim($n->item(0)->textContent) : '';
	}

	/**
	 * Attribute of the first matching node or ''.
	 *
	 * @param DOMXPath     $xp      XPath
	 * @param string       $q       Query
	 * @param string       $attr    Attribute
	 * @param DOMNode|null $context Context
	 * @return string
	 */
	private static function attr(DOMXPath $xp, $q, $attr, $context = null)
	{
		$n = $context ? $xp->query($q, $context) : $xp->query($q);
		return ($n && $n->length && $n->item(0) instanceof DOMElement) ? $n->item(0)->getAttribute($attr) : '';
	}

	/**
	 * @param string $s Number
	 * @return float|null
	 */
	private static function num($s)
	{
		$s = trim((string) $s);
		return ($s !== '' && is_numeric($s)) ? (float) $s : null;
	}

	/**
	 * YYYYMMDD / YYYY-MM-DD to YYYY-MM-DD.
	 *
	 * @param string $s Date
	 * @return string
	 */
	private static function date($s)
	{
		if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', trim($s), $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
			return $m[1].'-'.$m[2].'-'.$m[3];
		}
		return '';
	}
}
