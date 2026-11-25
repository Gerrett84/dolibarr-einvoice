<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       card.php
 * \ingroup    einvoice
 * \brief      Detail view of a received e-invoice
 */

if (!defined('CSRFCHECK_WITH_TOKEN')) {
	define('CSRFCHECK_WITH_TOKEN', '1');
}

$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/einvoice/class/einvoiceinbox.class.php');
dol_include_once('/einvoice/class/einvoicedetails.class.php');

$langs->loadLangs(array("bills", "companies", "einvoice@einvoice"));

if (!isModEnabled('einvoice') || !$user->hasRight('einvoice', 'read')) {
	accessforbidden();
}
$canWrite = $user->hasRight('einvoice', 'write');
$canInvoice = $canWrite && ($user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer'));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

$doc = new EInvoiceInbox($db);
if ($id <= 0 || $doc->fetch($id) <= 0) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}

$self = $_SERVER['PHP_SELF'].'?id='.$doc->id;

// Linked supplier deleted in the meantime: unlink and try to match again
if ($doc->fk_soc) {
	$chk = new Societe($db);
	if ($chk->fetch($doc->fk_soc) <= 0) {
		$doc->setSupplier(0, $user);
		$sid = $doc->findSupplier();
		if ($sid) {
			$doc->setSupplier($sid, $user);
		}
	}
}

/*
 * Actions
 */
if ($canWrite) {
	if ($action === 'ignore') {
		$doc->setStatus(EInvoiceInbox::STATUS_IGNORED, $user);
		header('Location: '.$self);
		exit;
	}
	if ($action === 'reopen') {
		$st = ($doc->format === 'pdf' || $doc->format === 'other') ? EInvoiceInbox::STATUS_NOEINVOICE : (empty($doc->error_msg) ? EInvoiceInbox::STATUS_NEW : EInvoiceInbox::STATUS_ERROR);
		$doc->setStatus($st, $user);
		header('Location: '.$self);
		exit;
	}
	if ($action === 'setsupplier') {
		$doc->setSupplier(GETPOSTINT('socid'), $user);
		header('Location: '.$self);
		exit;
	}
	if ($action === 'addsupplier' && !$doc->fk_soc && $user->hasRight('societe', 'creer')) {
		$d = array();
		foreach (array('name', 'vat', 'taxnumber', 'address', 'zip', 'town', 'country', 'email', 'phone', 'iban', 'bic') as $k) {
			$d[$k] = GETPOST('s_'.$k, $k === 'address' ? 'restricthtml' : 'alphanohtml');
		}
		$d['iban'] = strtoupper(preg_replace('/\s+/', '', $d['iban']));
		$socid = $doc->createSupplier($d, $user);
		if ($socid > 0) {
			$n = EInvoiceInbox::relinkUnmatched($db, $user);
			setEventMessages($langs->trans('EInvoiceSupplierCreated').($n ? ' '.$langs->trans('EInvoiceSupplierRelinked', $n) : ''), null, 'mesgs');
			header('Location: '.$self);
			exit;
		}
		setEventMessages($doc->error, null, 'errors');
		$action = 'createsupplier';
	}
	if ($action === 'confirm_createinvoice' && $confirm === 'yes' && $canInvoice) {
		$fid = $doc->createSupplierInvoice($user, GETPOSTINT('sourceid'));
		if ($fid > 0) {
			setEventMessages($langs->trans('EInvoiceSupplierInvoiceCreated'), null, 'mesgs');
			if ($doc->warnings) {
				setEventMessages('', $doc->warnings, 'warnings');
			}
			header('Location: '.DOL_URL_ROOT.'/fourn/facture/card.php?id='.$fid);
			exit;
		}
		setEventMessages($doc->error, null, 'errors');
		$action = ($fid === -2) ? 'createinvoice' : '';
	}
	if ($action === 'confirm_delete' && $confirm === 'yes') {
		if ($doc->delete($user) > 0) {
			setEventMessages($langs->trans('RecordDeleted'), null, 'mesgs');
			header('Location: '.dol_buildpath('/einvoice/inbox.php', 1));
			exit;
		}
		setEventMessages($doc->error, null, 'errors');
	}
}

/*
 * View
 */
$form = new Form($db);
llxHeader('', $langs->trans('EInvoiceDocument').' '.($doc->invoice_ref ? $doc->invoice_ref : $doc->filename));

$details = $doc->getDetails();
if ($action === 'createinvoice' && $canInvoice && $doc->fk_soc && !$doc->fk_facture_fourn) {
	$questions = array();
	if ($doc->isCreditNote()) {
		$sourceId = $doc->findSupplierInvoiceByRef($details ? $details['billing_ref'] : '');
		$opts = array();
		$sqlf = "SELECT rowid, ref, ref_supplier, total_ttc FROM ".MAIN_DB_PREFIX."facture_fourn WHERE fk_soc = ".((int) $doc->fk_soc);
		$sqlf .= " AND type <> 2 AND entity IN (".getEntity('invoice').") ORDER BY datef DESC LIMIT 200";
		$resf = $db->query($sqlf);
		while ($resf && ($o = $db->fetch_object($resf))) {
			$opts[$o->rowid] = $o->ref.' – '.$o->ref_supplier.' ('.price($o->total_ttc).')';
		}
		$questions[] = array('type' => 'select', 'name' => 'sourceid', 'label' => $langs->trans('EInvoiceCorrectedInvoice'), 'values' => $opts, 'default' => $sourceId, 'select_show_empty' => 1);
	}
	print $form->formconfirm($self, $langs->trans('EInvoiceCreateSupplierInvoice'), $langs->trans($doc->isCreditNote() ? 'EInvoiceConfirmCreateCreditNote' : 'EInvoiceConfirmCreateInvoice'), 'confirm_createinvoice', $questions, 'yes', 1, $questions ? 250 : 0, 600);
}
if ($action === 'delete' && $canWrite) {
	print $form->formconfirm($self, $langs->trans('Delete'), $langs->trans('EInvoiceConfirmDelete'), 'confirm_delete', '', 0, 1);
}

$linkback = '<a href="'.dol_buildpath('/einvoice/inbox.php', 1).'">'.$langs->trans('BackToList').'</a>';
print load_fiche_titre($langs->trans('EInvoiceDocument').': '.dol_escape_htmltag($doc->invoice_ref ? $doc->invoice_ref : $doc->filename), $linkback, 'fa-file-invoice');

print '<div class="fichecenter"><div class="fichehalfleft">';
print '<table class="border centpercent tableforfield">';

$row = function ($label, $value) {
	print '<tr><td class="titlefield">'.$label.'</td><td>'.$value.'</td></tr>';
};

$row($langs->trans('Status'), EInvoiceInbox::statusBadge($doc->status));
if ($doc->error_msg) {
	$row($langs->trans('Error'), '<span class="error">'.dol_escape_htmltag($doc->error_msg).'</span>');
}
if ($doc->getDuplicateOf()) {
	$row($langs->trans('EInvoiceDuplicateOf'), '<a href="'.$_SERVER['PHP_SELF'].'?id='.$doc->getDuplicateOf().'">#'.$doc->getDuplicateOf().'</a> <span class="opacitymedium small">'.$langs->trans('EInvoiceDuplicateOfHelp').'</span>');
}
$row($langs->trans('EInvoiceFormat'), dol_escape_htmltag($langs->trans('EInvoiceFormat_'.$doc->format)).($doc->profile ? '<br><span class="opacitymedium small">'.dol_escape_htmltag($doc->profile).'</span>' : ''));
if ($doc->doc_type) {
	$row($langs->trans('Type'), dol_escape_htmltag(EInvoiceInbox::docTypeLabel($doc->doc_type)).' <span class="opacitymedium">('.dol_escape_htmltag($doc->doc_type).')</span>');
}
if ($doc->fk_facture_fourn) {
	require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
	$ff = new FactureFournisseur($db);
	$row($langs->trans('EInvoiceSupplierInvoice'), $ff->fetch($doc->fk_facture_fourn) > 0 ? $ff->getNomUrl(1).' '.$ff->getLibStatut(5) : '<span class="opacitymedium">'.$langs->trans('EInvoiceSupplierInvoiceDeleted').'</span>');
}
$row($langs->trans('RefSupplier'), dol_escape_htmltag($doc->invoice_ref));
$row($langs->trans('DateInvoice'), $doc->invoice_date ? dol_print_date($db->jdate($doc->invoice_date), 'day') : '');

// Supplier
$supplierHtml = '';
if ($doc->fk_soc) {
	$soc = new Societe($db);
	if ($soc->fetch($doc->fk_soc) > 0) {
		$supplierHtml = $soc->getNomUrl(1, 'supplier');
	}
} else {
	$supplierHtml = '<span class="warning">'.$langs->trans('EInvoiceSupplierUnknown').'</span>';
}
if ($canWrite && $action === 'editsupplier') {
	$supplierHtml = '<form method="POST" action="'.$self.'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="setsupplier">';
	$supplierHtml .= $form->select_company($doc->fk_soc, 'socid', '(s.fournisseur:=:1)', 1, 0, 0, array(), 0, 'minwidth300');
	$supplierHtml .= ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'"></form>';
} elseif ($canWrite) {
	$supplierHtml .= ' <a class="editfielda" href="'.$self.'&action=editsupplier&token='.newToken().'">'.img_edit($langs->trans('Edit')).'</a>';
}
if ($canWrite && !$doc->fk_soc && $action !== 'editsupplier' && $action !== 'createsupplier' && $user->hasRight('societe', 'creer') && $doc->seller_name) {
	$supplierHtml .= '<br><a class="butAction smallpaddingimp" style="margin:4px 0 0 0" href="'.$self.'&action=createsupplier&token='.newToken().'"><span class="fa fa-plus-circle paddingright"></span>'.$langs->trans('EInvoiceCreateSupplier').'</a>';
}
$row($langs->trans('Supplier'), $supplierHtml);
$row($langs->trans('EInvoiceSellerInDocument'), dol_escape_htmltag($doc->seller_name)
	.($doc->seller_vat ? '<br><span class="opacitymedium small">'.$langs->trans('VATIntra').': '.dol_escape_htmltag($doc->seller_vat).'</span>' : '')
	.($doc->seller_taxnumber ? '<br><span class="opacitymedium small">'.$langs->trans('EInvoiceTaxNumber').': '.dol_escape_htmltag($doc->seller_taxnumber).'</span>' : ''));
if ($doc->buyer_name || $doc->buyer_reference) {
	$row($langs->trans('EInvoiceBuyer'), dol_escape_htmltag($doc->buyer_name).($doc->buyer_reference ? '<br><span class="opacitymedium small">'.$langs->trans('EInvoiceBuyerReference').': '.dol_escape_htmltag($doc->buyer_reference).'</span>' : ''));
}
$cur = $doc->currency ? $doc->currency : $conf->currency;
if ($doc->total_ht !== null) {
	$row($langs->trans('AmountHT'), price($doc->total_ht, 0, $langs, 1, -1, 2, $cur));
}
if ($doc->total_ttc !== null) {
	$row($langs->trans('AmountTTC'), price($doc->total_ttc, 0, $langs, 1, -1, 2, $cur));
}
if ($doc->amount_due !== null && price2num($doc->amount_due) != price2num($doc->total_ttc)) {
	$row($langs->trans('RemainderToPay'), price($doc->amount_due, 0, $langs, 1, -1, 2, $cur));
}
if ($details && $details['syntax']) {
	if ($details['due_date']) {
		$row($langs->trans('DateDue'), dol_print_date(dol_stringtotime($details['due_date'], 0), 'day'));
	}
	if ($details['payment_terms']) {
		$row($langs->trans('PaymentConditions'), dol_escape_htmltag($details['payment_terms']));
	}
	$pm = EInvoiceInbox::paymentModeCode($details['payment_means']);
	if ($pm) {
		$row($langs->trans('PaymentMode'), dol_escape_htmltag($langs->trans('PaymentType'.$pm) !== 'PaymentType'.$pm ? $langs->trans('PaymentType'.$pm) : $pm));
	}
	foreach (array('order_ref' => 'EInvoiceOrderRef', 'contract_ref' => 'EInvoiceContractRef', 'billing_ref' => 'EInvoiceBillingRef') as $k => $lbl) {
		if ($details[$k] !== '') {
			$row($langs->trans($lbl), dol_escape_htmltag($details[$k]));
		}
	}
}
print '</table>';
print '</div><div class="fichehalfright">';
print '<table class="border centpercent tableforfield">';
$row($langs->trans('EInvoiceSource'), dol_escape_htmltag($langs->trans('EInvoiceSource_'.$doc->source)));
if ($doc->source !== 'upload') {
	$row($langs->trans('MailFrom'), dol_escape_htmltag($doc->mail_from));
	$row($langs->trans('MailTopic'), dol_escape_htmltag($doc->mail_subject));
	$row($langs->trans('DateReception'), dol_print_date($doc->mail_date, 'dayhour', 'tzuserrel'));
}
$row($langs->trans('DateCreation'), dol_print_date($doc->datec, 'dayhour', 'tzuserrel'));

$dl = dol_buildpath('/einvoice/download.php', 1).'?id='.$doc->id;
$files = '<a href="'.$dl.'&type=file">'.img_mime($doc->filename).' '.dol_escape_htmltag($doc->filename).'</a>';
if ($doc->xml_path) {
	$files .= '<br><a href="'.$dl.'&type=xml">'.img_mime('x.xml').' '.$langs->trans('EInvoiceEmbeddedXml').'</a>';
}
$row($langs->trans('Documents'), $files);
$row('SHA-256', '<span class="small opacitymedium">'.dol_escape_htmltag($doc->file_hash).'</span>');
print '</table>';
print '</div></div>';
print '<div class="clearboth"></div>';

// Create supplier from invoice data
if ($canWrite && $action === 'createsupplier' && !$doc->fk_soc && $user->hasRight('societe', 'creer')) {
	$d = $doc->getSellerDetails();
	if (GETPOSTISSET('s_name')) {
		foreach (array_keys($d) as $k) {
			$d[$k] = GETPOST('s_'.$k, 'alphanohtml');
		}
	}
	print '<br><form method="POST" action="'.$self.'">';
	print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="addsupplier">';
	print '<table class="noborder centpercent"><tr class="liste_titre"><td colspan="2">'.$langs->trans('EInvoiceCreateSupplier').' <span class="opacitymedium small">– '.$langs->trans('EInvoiceCreateSupplierHelp').'</span></td></tr>';
	$fields = array(
		'name' => array('ThirdPartyName', 'minwidth300', 1),
		'address' => array('Address', 'minwidth300', 0),
		'zip' => array('Zip', 'width100', 0),
		'town' => array('Town', 'minwidth200', 0),
		'country' => array('CountryCode', 'width50', 0),
		'vat' => array('VATIntra', 'minwidth200', 0),
		'taxnumber' => array('EInvoiceTaxNumber', 'minwidth200', 0),
		'email' => array('Email', 'minwidth300', 0),
		'phone' => array('Phone', 'minwidth200', 0),
		'iban' => array('IBAN', 'minwidth300', 0),
		'bic' => array('BIC', 'minwidth100', 0),
	);
	foreach ($fields as $k => $f) {
		print '<tr class="oddeven"><td class="titlefieldcreate'.($f[2] ? ' fieldrequired' : '').'">'.$langs->trans($f[0]).'</td><td>';
		if ($k === 'address') {
			print '<textarea name="s_address" class="'.$f[1].'" rows="2">'.dol_escape_htmltag($d[$k]).'</textarea>';
		} else {
			print '<input type="text" name="s_'.$k.'" class="'.$f[1].'" value="'.dol_escape_htmltag($d[$k]).'"'.($f[2] ? ' required' : '').'>';
		}
		print '</td></tr>';
	}
	print '</table>';
	print '<div class="center margintoponly"><input type="submit" class="button button-save" value="'.$langs->trans('EInvoiceCreateSupplier').'"> <a class="button button-cancel" href="'.$self.'">'.$langs->trans('Cancel').'</a></div>';
	print '</form>';
}

// Readable invoice
if ($details && $details['syntax'] && $action !== 'createsupplier') {
	$problems = EInvoiceDetails::check($details);
	if ($problems) {
		print '<div class="warning">'.$langs->trans('EInvoiceArithmeticProblem').'<br>'.dol_escape_htmltag(implode(' · ', $problems)).'</div>';
	}
	if (!empty($details['summary'])) {
		print '<div class="warning">'.$langs->trans('EInvoiceSummaryHint').'</div>';
	}
	print '<br><div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td class="width50">'.$langs->trans('EInvoicePos').'</td><td>'.$langs->trans('Description').'</td><td class="right">'.$langs->trans('Qty').'</td><td>'.$langs->trans('Unit').'</td>';
	print '<td class="right">'.$langs->trans('PriceUHT').'</td><td class="right">'.$langs->trans('VAT').'</td><td class="right">'.$langs->trans('TotalHT').'</td></tr>';
	foreach ($details['lines'] as $l) {
		print '<tr class="oddeven'.($l['info_only'] ? ' opacitymedium' : '').'"><td>'.dol_escape_htmltag($l['id']).'</td><td>';
		print !empty($l['summary']) ? '<i>'.$langs->trans('EInvoiceSummaryLine', vatrate((string) $l['vat_rate'], true)).'</i>' : dol_escape_htmltag($l['name']);
		if ($l['description'] !== '' && $l['description'] !== $l['name']) {
			print '<br><span class="opacitymedium small">'.dol_nl2br(dol_escape_htmltag($l['description'])).'</span>';
		}
		$extra = array();
		if ($l['seller_ref'] !== '') {
			$extra[] = $langs->trans('EInvoiceSellerItemRef').': '.dol_escape_htmltag($l['seller_ref']);
		}
		if ($l['period_start'] || $l['period_end']) {
			$extra[] = dol_print_date(dol_stringtotime($l['period_start'], 0), 'day').' – '.dol_print_date(dol_stringtotime($l['period_end'], 0), 'day');
		}
		if ($extra) {
			print '<br><span class="opacitymedium small">'.implode(' · ', $extra).'</span>';
		}
		print '</td><td class="right nowraponall">'.price($l['qty'], 0, $langs, 0, -1, -1).'</td><td class="opacitymedium small">'.dol_escape_htmltag(EInvoiceInbox::unitLabel($l['unit'])).'</td>';
		print '<td class="right nowraponall">'.price($l['unit_price'], 0, $langs, 0, -1, 4).'</td>';
		print '<td class="right nowraponall">'.vatrate((string) $l['vat_rate'], true).($l['vat_category'] && $l['vat_category'] !== 'S' ? ' <span class="opacitymedium small">'.dol_escape_htmltag($l['vat_category']).'</span>' : '').'</td>';
		print '<td class="right nowraponall">'.price($l['total']).'</td></tr>';
	}
	foreach ($details['allowances'] as $a) {
		print '<tr class="oddeven"><td></td><td>'.dol_escape_htmltag($a['reason'] !== '' ? $a['reason'] : $langs->trans($a['charge'] ? 'EInvoiceCharge' : 'EInvoiceAllowance')).'</td><td></td><td></td><td></td>';
		print '<td class="right">'.vatrate((string) $a['vat_rate'], true).'</td><td class="right nowraponall">'.price(($a['charge'] ? 1 : -1) * (float) $a['amount']).'</td></tr>';
	}
	$tot = function ($label, $v, $bold = false) use ($cur, $langs) {
		if ($v === null) {
			return;
		}
		print '<tr class="liste_total"><td colspan="6" class="right">'.$label.'</td><td class="right nowraponall">'.($bold ? '<b>' : '').price($v, 0, $langs, 1, -1, 2, $cur).($bold ? '</b>' : '').'</td></tr>';
	};
	$tot($langs->trans('AmountHT'), $details['tax_basis']);
	foreach ($details['taxes'] as $t) {
		$tot($langs->trans('VAT').' '.vatrate((string) $t['rate'], true).($t['category'] && $t['category'] !== 'S' ? ' ('.dol_escape_htmltag($t['category']).($t['reason'] ? ': '.dol_escape_htmltag($t['reason']) : '').')' : '').' <span class="opacitymedium">'.$langs->trans('EInvoiceOnAmount', price($t['basis'])).'</span>', $t['amount']);
	}
	$tot($langs->trans('AmountTTC'), $details['grand_total'], true);
	if ($details['prepaid']) {
		$tot($langs->trans('AlreadyPaid'), $details['prepaid']);
		$tot($langs->trans('RemainderToPay'), $details['amount_due'], true);
	}
	print '</table></div>';
	if ($details['notes']) {
		print '<div class="opacitymedium small margintoponly">'.dol_nl2br(dol_escape_htmltag(implode("\n\n", $details['notes']))).'</div>';
	}
}

// Buttons
print '<div class="tabsAction">';
if ((string) $doc->doc_type === '389') {
	print '<div class="warning">'.$langs->trans('EInvoiceSelfBilledHint').'</div>';
} elseif ((string) $doc->doc_type === '751') {
	print '<div class="warning">'.$langs->trans('EInvoiceAccountingInfoHint').'</div>';
}
if ($canInvoice && !$doc->fk_facture_fourn && $details && $details['syntax'] && (string) $doc->doc_type !== '389' && in_array($doc->status, array(EInvoiceInbox::STATUS_NEW, EInvoiceInbox::STATUS_ERROR), true)) {
	if ($doc->fk_soc) {
		print dolGetButtonAction('', $langs->trans('EInvoiceCreateSupplierInvoice'), 'default', $self.'&action=createinvoice&token='.newToken());
	} else {
		print dolGetButtonAction($langs->trans('EInvoiceSupplierFirst'), $langs->trans('EInvoiceCreateSupplierInvoice'), 'default', '#', '', false);
	}
}
if ($canWrite) {
	if (in_array($doc->status, array(EInvoiceInbox::STATUS_NEW, EInvoiceInbox::STATUS_ERROR, EInvoiceInbox::STATUS_NOEINVOICE), true)) {
		print dolGetButtonAction('', $langs->trans('EInvoiceIgnore'), 'default', $self.'&action=ignore&token='.newToken());
	}
	if ($doc->status === EInvoiceInbox::STATUS_IGNORED) {
		print dolGetButtonAction('', $langs->trans('ReOpen'), 'default', $self.'&action=reopen&token='.newToken());
	}
	print dolGetButtonAction('', $langs->trans('Delete'), 'delete', $self.'&action=delete&token='.newToken());
}
print '</div>';

// Raw XML
$xml = $doc->getXml();
if ($xml !== null) {
	print '<details class="marginbottomonly"><summary class="cursorpointer">'.$langs->trans('EInvoiceShowXml').'</summary>';
	$pretty = $xml;
	$dom = new DOMDocument();
	$dom->preserveWhiteSpace = false;
	$dom->formatOutput = true;
	if (!preg_match('/<!DOCTYPE/i', substr($xml, 0, 4096)) && @$dom->loadXML(preg_replace('/^\xEF\xBB\xBF/', '', $xml), LIBXML_NONET)) {
		$pretty = $dom->saveXML();
	}
	print '<pre style="max-height:600px;overflow:auto;font-size:0.85em;background:var(--colorbacklinepair2, #f8f8f8);padding:8px">'.dol_escape_htmltag($pretty).'</pre>';
	print '</details>';
}

llxFooter();
$db->close();
