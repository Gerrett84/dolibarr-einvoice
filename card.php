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
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/einvoice/class/einvoiceinbox.class.php');

$langs->loadLangs(array("bills", "companies", "einvoice@einvoice"));

if (!isModEnabled('einvoice') || !$user->hasRight('einvoice', 'read')) {
	accessforbidden();
}
$canWrite = $user->hasRight('einvoice', 'write');

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

$doc = new EInvoiceInbox($db);
if ($id <= 0 || $doc->fetch($id) <= 0) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}

$self = $_SERVER['PHP_SELF'].'?id='.$doc->id;

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

// Buttons
print '<div class="tabsAction">';
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
