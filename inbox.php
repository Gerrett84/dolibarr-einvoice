<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       inbox.php
 * \ingroup    einvoice
 * \brief      List of received e-invoices, manual fetch and upload
 */

// Every request with an "action" needs a valid CSRF token
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/einvoice/class/einvoiceinbox.class.php');
dol_include_once('/einvoice/class/einvoicefetcher.class.php');
dol_include_once('/einvoice/class/einvoiceparser.class.php');

$langs->loadLangs(array("bills", "companies", "einvoice@einvoice"));

if (!isModEnabled('einvoice') || !$user->hasRight('einvoice', 'read')) {
	accessforbidden();
}
$canWrite = $user->hasRight('einvoice', 'write');

$action = GETPOST('action', 'aZ09');
$filter = GETPOST('filter', 'aZ09');
if ($filter === '') {
	$filter = 'open';
}
$search = GETPOST('search', 'alphanohtml');
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$page = max(0, GETPOSTINT('page'));
$offset = $limit * $page;
$showUpload = GETPOSTINT('showupload');

/*
 * Actions
 */

if ($action === 'fetch' && $canWrite) {
	@set_time_limit(300);
	$fetcher = new EInvoiceFetcher($db);
	$r = $fetcher->run($user);
	if ($r < 0) {
		setEventMessages($langs->trans('EInvoiceFetchFailed').': '.$fetcher->error, null, 'errors');
	} else {
		setEventMessages($fetcher->summary(), null, $fetcher->stats['errors'] ? 'warnings' : 'mesgs');
	}
	if (!empty($fetcher->errors)) {
		setEventMessages('', array_slice($fetcher->errors, 0, 10), 'warnings');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?filter='.urlencode($filter));
	exit;
}

if ($action === 'upload' && $canWrite) {
	$fetcher = new EInvoiceFetcher($db);
	$ok = 0;
	$msgs = array();
	if (!empty($_FILES['files']['name']) && is_array($_FILES['files']['name'])) {
		foreach ($_FILES['files']['name'] as $i => $name) {
			if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['files']['tmp_name'][$i])) {
				$msgs[] = dol_escape_htmltag($name).': '.$langs->trans('ErrorFileNotUploaded');
				continue;
			}
			$content = file_get_contents($_FILES['files']['tmp_name'][$i]);
			$an = EInvoiceParser::analyze($content, $name, $_FILES['files']['type'][$i]);
			if ($an['kind'] !== 'einvoice' && $an['kind'] !== 'pdf') {
				$msgs[] = dol_escape_htmltag($name).': '.$langs->trans('EInvoiceNotRecognized').($an['error'] ? ' ('.dol_escape_htmltag($an['error']).')' : '');
				continue;
			}
			$r = $fetcher->storeDocument($an, $content, $name, array('source' => 'upload'), $user);
			if ($r === -2) {
				$msgs[] = dol_escape_htmltag($name).': '.$langs->trans('EInvoiceDuplicate');
			} elseif ($r < 0) {
				$msgs[] = dol_escape_htmltag($name).': '.dol_escape_htmltag($fetcher->error);
			} else {
				$ok++;
				if ($an['kind'] === 'pdf') {
					$msgs[] = dol_escape_htmltag($name).': '.$langs->trans('EInvoicePdfWithoutXml');
				}
			}
		}
	}
	if ($ok) {
		setEventMessages($langs->trans('EInvoiceUploaded', $ok), null, 'mesgs');
	}
	if ($msgs) {
		setEventMessages('', $msgs, 'warnings');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?filter=all');
	exit;
}

/*
 * View
 */

$filters = array(
	'open' => array('EInvoiceFilterOpen', array(EInvoiceInbox::STATUS_NEW, EInvoiceInbox::STATUS_ERROR)),
	'done' => array('EInvoiceStatusDone', array(EInvoiceInbox::STATUS_DONE)),
	'noeinvoice' => array('EInvoiceStatusNoEInvoice', array(EInvoiceInbox::STATUS_NOEINVOICE)),
	'ignored' => array('EInvoiceStatusIgnored', array(EInvoiceInbox::STATUS_IGNORED)),
	'all' => array('All', array()),
);
if (!isset($filters[$filter])) {
	$filter = 'open';
}

$sqlWhere = " WHERE i.entity = ".((int) $conf->entity);
if (!empty($filters[$filter][1])) {
	$sqlWhere .= " AND i.status IN (".implode(',', array_map('intval', $filters[$filter][1])).")";
}
if ($search !== '') {
	$s = $db->escape($search);
	$sqlWhere .= " AND (i.invoice_ref LIKE '%".$s."%' OR i.seller_name LIKE '%".$s."%' OR i.mail_from LIKE '%".$s."%' OR i.mail_subject LIKE '%".$s."%' OR i.filename LIKE '%".$s."%' OR s.nom LIKE '%".$s."%')";
}

$counts = array();
$resc = $db->query("SELECT status, COUNT(*) as nb FROM ".MAIN_DB_PREFIX."einvoice_inbox WHERE entity = ".((int) $conf->entity)." GROUP BY status");
while ($resc && ($o = $db->fetch_object($resc))) {
	$counts[(int) $o->status] = (int) $o->nb;
}

$sqlCount = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."einvoice_inbox as i LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = i.fk_soc".$sqlWhere;
$resn = $db->query($sqlCount);
$nbtotal = ($resn && ($o = $db->fetch_object($resn))) ? (int) $o->nb : 0;

$sql = "SELECT i.rowid, i.source, i.mail_from, i.mail_subject, i.mail_date, i.filename, i.format, i.doc_type, i.invoice_ref, i.invoice_date,";
$sql .= " i.seller_name, i.seller_vat, i.currency, i.total_ttc, i.status, i.error_msg, i.fk_soc, i.datec, s.nom as socname";
$sql .= " FROM ".MAIN_DB_PREFIX."einvoice_inbox as i LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = i.fk_soc";
$sql .= $sqlWhere;
$sql .= " ORDER BY i.datec DESC, i.rowid DESC";
$sql .= $db->plimit($limit, $offset);
$resql = $db->query($sql);

llxHeader('', $langs->trans('EInvoiceInbox'));

$param = '&filter='.urlencode($filter).($search !== '' ? '&search='.urlencode($search) : '');

$morehtmlright = '';
if ($canWrite) {
	$morehtmlright .= dolGetButtonTitle($langs->trans('EInvoiceUpload'), '', 'fa fa-upload', $_SERVER['PHP_SELF'].'?showupload=1&filter='.urlencode($filter));
}

print_barre_liste($langs->trans('EInvoiceInbox'), $page, $_SERVER['PHP_SELF'], $param, '', '', '', $resql ? $db->num_rows($resql) : 0, $nbtotal, 'fa-file-invoice', 0, $morehtmlright, '', $limit);

if (getDolGlobalString('EINVOICE_CONN_TYPE')) {
	// Status line: last fetch, scheduled job, manual fetch
	$lastRun = getDolGlobalInt('EINVOICE_LAST_RUN');
	$lastStatus = getDolGlobalString('EINVOICE_LAST_STATUS');
	$lastMsg = getDolGlobalString('EINVOICE_LAST_MESSAGE');
	$lastOk = getDolGlobalInt('EINVOICE_LAST_FETCH');

	print '<div class="info clearboth" style="display:flex;flex-wrap:wrap;gap:6px 24px;align-items:center">';
	print '<div>'.$langs->trans('EInvoiceLastFetch').': ';
	if (!$lastRun && !$lastOk) {
		print '<b>'.$langs->trans('EInvoiceLastFetchNever').'</b>';
	} else {
		print '<b>'.dol_print_date($lastRun ? $lastRun : $lastOk, 'dayhour', 'tzuserrel').'</b>';
		if ($lastStatus === 'error') {
			print ' – <span class="error">'.$langs->trans('EInvoiceFetchFailed').': '.dol_escape_htmltag($lastMsg).'</span>';
			if ($lastOk) {
				print '<br><span class="opacitymedium small">'.$langs->trans('EInvoiceLastFetchSuccess').': '.dol_print_date($lastOk, 'dayhour', 'tzuserrel').'</span>';
			}
		} elseif ($lastMsg !== '') {
			print ' – '.($lastStatus === 'warning' ? '<span class="warning">' : '<span class="opacitymedium">').dol_escape_htmltag($lastMsg).'</span>';
		}
	}
	print '</div>';

	// Scheduled job
	$cron = EInvoiceFetcher::getCronJob($db);
	print '<div>'.$langs->trans('EInvoiceAutoFetch').': ';
	if ($cron && (int) $cron->status === 1) {
		$minutes = max(1, (int) round($cron->frequency * $cron->unitfrequency / 60));
		$next = $db->jdate($cron->datenextrun);
		print '<b>'.$langs->trans('EInvoiceAutoFetchOn', $minutes).'</b>';
		if ($next) {
			print ' <span class="opacitymedium">('.$langs->trans('EInvoiceAutoFetchNext', dol_print_date($next, 'hour', 'tzuserrel')).')</span>';
			if ($next < dol_now() - 1800) {
				print '<br><span class="warning">'.$langs->trans('EInvoiceAutoFetchStale').'</span>';
			}
		}
	} else {
		print '<b>'.$langs->trans('EInvoiceAutoFetchOff').'</b>';
		if ($user->admin) {
			print ' – <a href="'.dol_buildpath('/einvoice/admin/setup.php', 1).'?tab=processing">'.$langs->trans('EInvoiceAutoFetchEnable').'</a>';
		}
	}
	print '</div>';

	if ($canWrite) {
		print '<div style="margin-left:auto"><a class="butAction smallpaddingimp" style="margin:0" href="'.$_SERVER['PHP_SELF'].'?action=fetch&token='.newToken().'&filter='.urlencode($filter).'"><span class="fa fa-sync-alt paddingright"></span>'.$langs->trans('EInvoiceFetchNow').'</a></div>';
	}
	print '</div>';
} elseif ($user->admin) {
	print info_admin($langs->trans('EInvoiceNotConfigured').' <a href="'.dol_buildpath('/einvoice/admin/setup.php', 1).'">'.$langs->trans('Setup').'</a>');
}

if ($showUpload && $canWrite) {
	print '<form method="POST" enctype="multipart/form-data" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="upload">';
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('EInvoiceUpload').'</td></tr>';
	print '<tr class="oddeven"><td><input type="file" name="files[]" multiple accept=".xml,.pdf,application/pdf,application/xml,text/xml"> ';
	print '<input type="submit" class="button" value="'.$langs->trans('Upload').'">';
	print '<br><span class="opacitymedium small">'.$langs->trans('EInvoiceUploadHelp').'</span></td></tr></table></div>';
	print '</form><br>';
}

// Status filter tabs
$head = array();
$h = 0;
foreach ($filters as $k => $f) {
	$n = 0;
	if (empty($f[1])) {
		$n = array_sum($counts);
	} else {
		foreach ($f[1] as $st) {
			$n += isset($counts[$st]) ? $counts[$st] : 0;
		}
	}
	$head[$h][0] = $_SERVER['PHP_SELF'].'?filter='.$k.($search !== '' ? '&search='.urlencode($search) : '');
	$head[$h][1] = $langs->trans($f[0]).' <span class="badge marginleftonlyshort">'.$n.'</span>';
	$head[$h][2] = $k;
	$h++;
}
print dol_get_fiche_head($head, $filter, '', -1, '');

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="filter" value="'.dol_escape_htmltag($filter).'">';
print '<div class="div-table-responsive">';
print '<table class="tagtable liste noborder centpercent">';
print '<tr class="liste_titre_filter"><td colspan="7"><input type="text" name="search" class="maxwidth300" placeholder="'.dol_escape_htmltag($langs->trans('Search')).'" value="'.dol_escape_htmltag($search).'"> ';
print '<button type="submit" class="liste_titre button_search reposition" name="button_search_x" value="x">'.img_picto($langs->trans('Search'), 'search.png', '', 0, 1).'</button></td></tr>';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('EInvoiceReceived').'</td>';
print '<td>'.$langs->trans('Supplier').'</td>';
print '<td>'.$langs->trans('RefSupplier').'</td>';
print '<td class="center">'.$langs->trans('Date').'</td>';
print '<td class="right">'.$langs->trans('AmountTTC').'</td>';
print '<td>'.$langs->trans('EInvoiceFormat').'</td>';
print '<td class="center">'.$langs->trans('Status').'</td>';
print '</tr>';

if ($resql && $db->num_rows($resql) > 0) {
	$soc = new Societe($db);
	while ($obj = $db->fetch_object($resql)) {
		$url = dol_buildpath('/einvoice/card.php', 1).'?id='.((int) $obj->rowid);
		print '<tr class="oddeven">';
		print '<td class="nowraponall"><a href="'.$url.'">'.dol_print_date($db->jdate($obj->mail_date ? $obj->mail_date : $obj->datec), 'dayhour', 'tzuserrel').'</a>';
		print '<br><span class="opacitymedium small tdoverflowmax250 inline-block" title="'.dol_escape_htmltag($obj->mail_from.' – '.$obj->mail_subject).'">';
		print $obj->source === 'upload' ? $langs->trans('EInvoiceSourceUpload') : dol_escape_htmltag($obj->mail_from);
		print '</span></td>';
		print '<td class="tdoverflowmax250">';
		if ($obj->fk_soc) {
			$soc->id = $obj->fk_soc;
			$soc->name = $obj->socname;
			$soc->fournisseur = 1;
			print $soc->getNomUrl(1, 'supplier');
		} else {
			print dol_escape_htmltag($obj->seller_name ? $obj->seller_name : $obj->filename);
			if ($obj->seller_name) {
				print ' '.img_warning($langs->trans('EInvoiceSupplierUnknown'));
			}
		}
		print '</td>';
		print '<td class="tdoverflowmax150"><a href="'.$url.'">'.dol_escape_htmltag($obj->invoice_ref ? $obj->invoice_ref : $obj->filename).'</a>';
		if ($obj->doc_type && $obj->doc_type !== '380') {
			print '<br><span class="opacitymedium small">'.dol_escape_htmltag(EInvoiceInbox::docTypeLabel($obj->doc_type)).'</span>';
		}
		print '</td>';
		print '<td class="center">'.($obj->invoice_date ? dol_print_date($db->jdate($obj->invoice_date), 'day') : '').'</td>';
		print '<td class="right nowraponall">'.($obj->total_ttc !== null ? price($obj->total_ttc, 0, $langs, 1, -1, 2, $obj->currency ? $obj->currency : $conf->currency) : '').'</td>';
		print '<td>'.dol_escape_htmltag($langs->trans('EInvoiceFormat_'.$obj->format)).'</td>';
		print '<td class="center">'.EInvoiceInbox::statusBadge($obj->status);
		if ((int) $obj->status === EInvoiceInbox::STATUS_ERROR && $obj->error_msg) {
			print ' '.img_warning(dol_escape_htmltag($obj->error_msg));
		}
		print '</td>';
		print '</tr>';
	}
} else {
	print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}
print '</table></div></form>';
print dol_get_fiche_end();

llxFooter();
$db->close();
