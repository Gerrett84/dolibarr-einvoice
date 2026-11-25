<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       admin/setup.php
 * \ingroup    einvoice
 * \brief      Setup page: mailbox connection and processing options
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/einvoice/class/einvoiceconnector.class.php');
dol_include_once('/einvoice/class/einvoicefetcher.class.php');

if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(array("admin", "einvoice@einvoice"));

$action = GETPOST('action', 'aZ09');
$tab = GETPOST('tab', 'aZ09') === 'processing' ? 'processing' : 'connection';

$connTypes = array(
	'' => $langs->trans('EInvoiceConnNone'),
	'graph' => $langs->trans('EInvoiceConnGraph'),
	'imap_oauth' => $langs->trans('EInvoiceConnImapOauth'),
	'imap_password' => $langs->trans('EInvoiceConnImapPassword'),
);
$postActions = array(
	'none' => $langs->trans('EInvoiceActionNone'),
	'read' => $langs->trans('EInvoiceActionRead'),
	'move' => $langs->trans('EInvoiceActionMove'),
	'trash' => $langs->trans('EInvoiceActionTrash'),
);

/*
 * Actions
 */
if ($action === 'save_connection') {
	$type = GETPOST('EINVOICE_CONN_TYPE', 'aZ09');
	if (!isset($connTypes[$type])) {
		$type = '';
	}
	$mailboxOld = getDolGlobalString('EINVOICE_MAILBOX');
	$folderOld = getDolGlobalString('EINVOICE_SOURCE_FOLDER');
	$typeOld = getDolGlobalString('EINVOICE_CONN_TYPE');

	dolibarr_set_const($db, 'EINVOICE_CONN_TYPE', $type, 'chaine', 0, '', $conf->entity);
	foreach (array('EINVOICE_MAILBOX' => 'alphanohtml', 'EINVOICE_TENANT_ID' => 'alphanohtml', 'EINVOICE_CLIENT_ID' => 'alphanohtml',
		'EINVOICE_IMAP_HOST' => 'alphanohtml', 'EINVOICE_IMAP_LOGIN' => 'alphanohtml', 'EINVOICE_SOURCE_FOLDER' => 'alphanohtml',
		'EINVOICE_IMAP_TRASH' => 'alphanohtml') as $k => $check) {
		if (GETPOSTISSET($k)) {
			dolibarr_set_const($db, $k, trim(GETPOST($k, $check)), 'chaine', 0, '', $conf->entity);
		}
	}
	if (GETPOSTISSET('EINVOICE_IMAP_PORT')) {
		dolibarr_set_const($db, 'EINVOICE_IMAP_PORT', GETPOSTINT('EINVOICE_IMAP_PORT'), 'chaine', 0, '', $conf->entity);
	}
	$sec = GETPOST('EINVOICE_IMAP_SECURITY', 'aZ09');
	if (in_array($sec, array('ssl', 'starttls'), true)) {
		dolibarr_set_const($db, 'EINVOICE_IMAP_SECURITY', $sec, 'chaine', 0, '', $conf->entity);
	}
	// Secrets: only overwrite when something was typed (names end in _SECRET/_PASSWORD -> stored encrypted by Dolibarr)
	foreach (array('EINVOICE_CLIENT_SECRET', 'EINVOICE_IMAP_PASSWORD') as $k) {
		$v = GETPOST($k, 'password');
		if ($v !== '') {
			dolibarr_set_const($db, $k, $v, 'chaine', 0, '', $conf->entity);
		}
	}
	// Different mailbox/folder: start the watermark again from the configured date
	if ($mailboxOld !== getDolGlobalString('EINVOICE_MAILBOX') || $folderOld !== getDolGlobalString('EINVOICE_SOURCE_FOLDER') || $typeOld !== $type) {
		dolibarr_del_const($db, 'EINVOICE_WATERMARK', $conf->entity);
		foreach (array('EINVOICE_LAST_RUN', 'EINVOICE_LAST_STATUS', 'EINVOICE_LAST_MESSAGE', 'EINVOICE_LAST_STATS', 'EINVOICE_LAST_FETCH') as $k) {
			dolibarr_del_const($db, $k, $conf->entity);
		}
	}
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].'?tab=connection');
	exit;
}

if ($action === 'save_processing') {
	$sinceOld = getDolGlobalInt('EINVOICE_FETCH_SINCE');
	$since = dol_mktime(0, 0, 0, GETPOSTINT('sincemonth'), GETPOSTINT('sinceday'), GETPOSTINT('sinceyear'));
	if ($since) {
		dolibarr_set_const($db, 'EINVOICE_FETCH_SINCE', $since, 'chaine', 0, '', $conf->entity);
		if ($since < $sinceOld) {
			dolibarr_del_const($db, 'EINVOICE_WATERMARK', $conf->entity);
		}
	}
	$minutes = GETPOSTINT('EINVOICE_AUTO_MINUTES');
	if (!in_array($minutes, array(5, 10, 15, 30, 60), true)) {
		$minutes = 15;
	}
	EInvoiceFetcher::setCronJob($db, GETPOSTINT('EINVOICE_AUTO') === 1, $minutes);
	// Also kept as constants, so the setting survives disabling/enabling the module
	dolibarr_set_const($db, 'EINVOICE_AUTO', GETPOSTINT('EINVOICE_AUTO') === 1 ? '1' : '0', 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'EINVOICE_AUTO_MINUTES', (string) $minutes, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'EINVOICE_MAX_PER_RUN', max(1, min(500, GETPOSTINT('EINVOICE_MAX_PER_RUN'))), 'chaine', 0, '', $conf->entity);
	foreach (array('EINVOICE_ACTION_FOUND', 'EINVOICE_ACTION_NOTFOUND') as $k) {
		$v = GETPOST($k, 'aZ09');
		dolibarr_set_const($db, $k, isset($postActions[$v]) ? $v : 'none', 'chaine', 0, '', $conf->entity);
	}
	foreach (array('EINVOICE_FOLDER_FOUND', 'EINVOICE_FOLDER_NOTFOUND') as $k) {
		dolibarr_set_const($db, $k, trim(GETPOST($k, 'alphanohtml')), 'chaine', 0, '', $conf->entity);
	}
	dolibarr_set_const($db, 'EINVOICE_KEEP_PLAIN_PDF', GETPOSTINT('EINVOICE_KEEP_PLAIN_PDF') ? 1 : 0, 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF'].'?tab=processing');
	exit;
}

$testResult = null;
if ($action === 'test') {
	$conn = EInvoiceConnector::fromConfig();
	if (!$conn) {
		$testResult = array('ok' => false, 'msg' => $langs->trans('EInvoiceNotConfigured'));
	} elseif (!$conn->connect()) {
		$testResult = array('ok' => false, 'msg' => $conn->error);
	} else {
		$list = $conn->listMessages(dol_now() - 30 * 86400, 10);
		if ($list === false) {
			$testResult = array('ok' => false, 'msg' => $conn->error);
		} else {
			$testResult = array('ok' => true, 'msg' => $langs->trans('EInvoiceTestOk', count($list)), 'list' => $list);
		}
		$conn->close();
	}
}

/*
 * View
 */
$form = new Form($db);
llxHeader('', $langs->trans('EInvoiceSetup'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans('EInvoiceSetup'), $linkback, 'title_setup');

$head = array();
$head[0] = array($_SERVER['PHP_SELF'].'?tab=connection', $langs->trans('EInvoiceTabConnection'), 'connection');
$head[1] = array($_SERVER['PHP_SELF'].'?tab=processing', $langs->trans('EInvoiceTabProcessing'), 'processing');
print dol_get_fiche_head($head, $tab, '', -1, 'fa-file-invoice');

$type = getDolGlobalString('EINVOICE_CONN_TYPE');

if ($tab === 'connection') {
	print '<span class="opacitymedium">'.$langs->trans('EInvoiceConnectionHelp').'</span><br><br>';

	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" autocomplete="off">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="save_connection">';
	print '<input type="hidden" name="tab" value="connection">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

	$secretSet = function ($k) use ($langs) {
		return getDolGlobalString($k) !== '' ? $langs->trans('EInvoiceSecretStored') : '';
	};

	$rows = array(
		array('EINVOICE_CONN_TYPE', 'all', $langs->trans('EInvoiceConnType'), $form->selectarray('EINVOICE_CONN_TYPE', $connTypes, $type, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300', 1), ''),
		array('EINVOICE_MAILBOX', 'graph imap_oauth imap_password', $langs->trans('EInvoiceMailbox'), '<input type="email" class="minwidth300" name="EINVOICE_MAILBOX" value="'.dol_escape_htmltag(getDolGlobalString('EINVOICE_MAILBOX')).'" placeholder="rechnung@example.com">', ''),
		array('EINVOICE_TENANT_ID', 'graph imap_oauth', $langs->trans('EInvoiceTenantId'), '<input type="text" class="minwidth300" name="EINVOICE_TENANT_ID" value="'.dol_escape_htmltag(getDolGlobalString('EINVOICE_TENANT_ID')).'" placeholder="contoso.onmicrosoft.com / GUID">', $langs->trans('EInvoiceTenantIdHelp')),
		array('EINVOICE_CLIENT_ID', 'graph imap_oauth', $langs->trans('EInvoiceClientId'), '<input type="text" class="minwidth300" name="EINVOICE_CLIENT_ID" value="'.dol_escape_htmltag(getDolGlobalString('EINVOICE_CLIENT_ID')).'">', ''),
		array('EINVOICE_CLIENT_SECRET', 'graph imap_oauth', $langs->trans('EInvoiceClientSecret'), '<input type="password" class="minwidth300" name="EINVOICE_CLIENT_SECRET" value="" autocomplete="new-password" placeholder="'.dol_escape_htmltag($secretSet('EINVOICE_CLIENT_SECRET')).'">', $langs->trans('EInvoiceSecretHelp')),
		array('EINVOICE_IMAP_HOST', 'imap_oauth imap_password', $langs->trans('EInvoiceImapHost'), '<input type="text" class="minwidth300" name="EINVOICE_IMAP_HOST" value="'.dol_escape_htmltag(getDolGlobalString('EINVOICE_IMAP_HOST', 'outlook.office365.com')).'">', ''),
		array('EINVOICE_IMAP_PORT', 'imap_oauth imap_password', $langs->trans('EInvoiceImapPort'), '<input type="number" class="width75" name="EINVOICE_IMAP_PORT" value="'.getDolGlobalInt('EINVOICE_IMAP_PORT', 993).'"> '
			.$form->selectarray('EINVOICE_IMAP_SECURITY', array('ssl' => 'SSL/TLS', 'starttls' => 'STARTTLS'), getDolGlobalString('EINVOICE_IMAP_SECURITY', 'ssl')), ''),
		array('EINVOICE_IMAP_LOGIN', 'imap_password', $langs->trans('Login'), '<input type="text" class="minwidth300" name="EINVOICE_IMAP_LOGIN" value="'.dol_escape_htmltag(getDolGlobalString('EINVOICE_IMAP_LOGIN')).'" placeholder="'.dol_escape_htmltag($langs->trans('EInvoiceLoginDefault')).'">', ''),
		array('EINVOICE_IMAP_PASSWORD', 'imap_password', $langs->trans('Password'), '<input type="password" class="minwidth300" name="EINVOICE_IMAP_PASSWORD" value="" autocomplete="new-password" placeholder="'.dol_escape_htmltag($secretSet('EINVOICE_IMAP_PASSWORD')).'">', $langs->trans('EInvoiceSecretHelp')),
		array('EINVOICE_SOURCE_FOLDER', 'graph imap_oauth imap_password', $langs->trans('EInvoiceSourceFolder'), '<input type="text" class="minwidth300" name="EINVOICE_SOURCE_FOLDER" value="'.dol_escape_htmltag(getDolGlobalString('EINVOICE_SOURCE_FOLDER')).'" placeholder="Inbox">', $langs->trans('EInvoiceSourceFolderHelp')),
		array('EINVOICE_IMAP_TRASH', 'imap_oauth imap_password', $langs->trans('EInvoiceTrashFolder'), '<input type="text" class="minwidth300" name="EINVOICE_IMAP_TRASH" value="'.dol_escape_htmltag(getDolGlobalString('EINVOICE_IMAP_TRASH')).'" placeholder="'.dol_escape_htmltag($langs->trans('EInvoiceAutoDetect')).'">', ''),
	);
	foreach ($rows as $r) {
		print '<tr class="oddeven einvoice-row" data-for="'.$r[1].'"><td class="titlefieldcreate">'.$r[2];
		if ($r[4]) {
			print '<br><span class="opacitymedium small">'.$r[4].'</span>';
		}
		print '</td><td>'.$r[3].'</td></tr>';
	}
	print '</table>';
	print '<div class="center margintoponly"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
	print '</form>';

	print '<script>
	jQuery(function ($) {
		function upd() {
			var t = $("#EINVOICE_CONN_TYPE").val();
			$(".einvoice-row").each(function () {
				var f = $(this).data("for");
				$(this).toggle(f === "all" || (t !== "" && (" " + f + " ").indexOf(" " + t + " ") >= 0));
			});
		}
		$("#EINVOICE_CONN_TYPE").on("change", upd);
		upd();
	});
	</script>';

	// Connection test
	if ($type !== '') {
		print '<br><div class="center"><a class="butAction" href="'.$_SERVER['PHP_SELF'].'?tab=connection&action=test&token='.newToken().'">'.$langs->trans('EInvoiceTestConnection').'</a></div>';
	}
	if ($testResult !== null) {
		print '<br><div class="'.($testResult['ok'] ? 'ok' : 'error').'">'.dol_escape_htmltag($testResult['msg']).'</div>';
		if (!empty($testResult['list'])) {
			print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('MailFrom').'</td><td>'.$langs->trans('MailTopic').'</td></tr>';
			foreach ($testResult['list'] as $m) {
				print '<tr class="oddeven"><td class="nowraponall">'.dol_print_date($m['date'], 'dayhour', 'tzuserrel').'</td><td>'.dol_escape_htmltag($m['from']).'</td><td>'.dol_escape_htmltag($m['subject']).'</td></tr>';
			}
			print '</table>';
			print '<span class="opacitymedium small">'.$langs->trans('EInvoiceTestNothingChanged').'</span>';
		}
	}

	print '<br><br>'.info_admin($langs->trans('EInvoiceM365Help'), 0, 0, '1', '');
} else {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="save_processing">';
	print '<input type="hidden" name="tab" value="processing">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

	print '<tr class="oddeven"><td class="titlefieldcreate fieldrequired">'.$langs->trans('EInvoiceFetchSince').'<br><span class="opacitymedium small">'.$langs->trans('EInvoiceFetchSinceHelp').'</span></td><td>';
	$since = getDolGlobalInt('EINVOICE_FETCH_SINCE');
	print $form->selectDate($since ? $since : dol_now(), 'since', 0, 0, 0, '', 1, 0);
	print '</td></tr>';

	$job = EInvoiceFetcher::getCronJob($db);
	$jobOn = $job && (int) $job->status === 1;
	$jobMin = $job ? max(1, (int) round($job->frequency * $job->unitfrequency / 60)) : 15;
	print '<tr class="oddeven"><td>'.$langs->trans('EInvoiceAutoFetch').'</td><td>';
	print '<input type="checkbox" id="EINVOICE_AUTO" name="EINVOICE_AUTO" value="1"'.($jobOn ? ' checked' : '').($job ? '' : ' disabled').'> ';
	print '<label for="EINVOICE_AUTO">'.$langs->trans('EInvoiceAutoFetchEvery').'</label> ';
	$opts = array();
	foreach (array(5, 10, 15, 30, 60) as $m) {
		$opts[$m] = $langs->trans('EInvoiceMinutes', $m);
	}
	print $form->selectarray('EINVOICE_AUTO_MINUTES', $opts, in_array($jobMin, array(5, 10, 15, 30, 60), true) ? $jobMin : 15, 0, 0, 0, '', 0, 0, $job ? 0 : 1);
	if (!$job) {
		print ' <span class="error">'.$langs->trans('EInvoiceCronJobMissing').'</span>';
	} elseif ($jobOn && !EInvoiceFetcher::schedulerSeemsActive($db)) {
		print '<br><span class="warning">'.$langs->trans('EInvoiceSchedulerMissing', DOL_URL_ROOT.'/cron/admin/cron.php').'</span>';
	}
	print '</td></tr>';

	print '<tr class="oddeven"><td>'.$langs->trans('EInvoiceMaxPerRun').'</td><td><input type="number" class="width75" min="1" max="500" name="EINVOICE_MAX_PER_RUN" value="'.getDolGlobalInt('EINVOICE_MAX_PER_RUN', 50).'"></td></tr>';

	foreach (array('FOUND' => array('EInvoiceActionFound', 'E-Rechnung/Verarbeitet'), 'NOTFOUND' => array('EInvoiceActionNotFound', 'E-Rechnung/Keine E-Rechnung')) as $k => $def) {
		print '<tr class="oddeven"><td>'.$langs->trans($def[0]).'</td><td>';
		print $form->selectarray('EINVOICE_ACTION_'.$k, $postActions, getDolGlobalString('EINVOICE_ACTION_'.$k, 'none'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
		print ' <span class="einvoice-folder-'.$k.'">'.$langs->trans('EInvoiceTargetFolder').': <input type="text" class="minwidth200" name="EINVOICE_FOLDER_'.$k.'" value="'.dol_escape_htmltag(getDolGlobalString('EINVOICE_FOLDER_'.$k, $def[1])).'"></span>';
		print '</td></tr>';
	}

	print '<tr class="oddeven"><td>'.$langs->trans('EInvoiceKeepPlainPdf').'<br><span class="opacitymedium small">'.$langs->trans('EInvoiceKeepPlainPdfHelp').'</span></td><td>';
	print '<input type="checkbox" name="EINVOICE_KEEP_PLAIN_PDF" value="1"'.(getDolGlobalInt('EINVOICE_KEEP_PLAIN_PDF') ? ' checked' : '').'>';
	print '</td></tr>';

	print '</table>';
	print '<div class="center margintoponly"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
	print '</form>';

	print '<script>
	jQuery(function ($) {
		["FOUND", "NOTFOUND"].forEach(function (k) {
			function upd() { $(".einvoice-folder-" + k).toggle($("#EINVOICE_ACTION_" + k).val() === "move"); }
			$("#EINVOICE_ACTION_" + k).on("change", upd);
			upd();
		});
	});
	</script>';

	print '<br>'.info_admin($langs->trans('EInvoiceParallelHint'));
	$wm = getDolGlobalInt('EINVOICE_WATERMARK');
	if ($wm) {
		print '<br><span class="opacitymedium small">'.$langs->trans('EInvoiceWatermark').': '.dol_print_date($wm, 'dayhour', 'tzuserrel').'</span>';
	}
}

print dol_get_fiche_end();
llxFooter();
$db->close();
