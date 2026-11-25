<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \defgroup   einvoice     Module E-Invoice
 * \brief      Receive e-invoices (XRechnung, ZUGFeRD/Factur-X) from a mailbox
 * \file       core/modules/modEInvoice.class.php
 * \ingroup    einvoice
 * \brief      Module descriptor for E-Invoice
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module EInvoice
 */
class modEInvoice extends DolibarrModules
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->numero = 500220;
		$this->rights_class = 'einvoice';
		$this->family = "financial";
		$this->module_position = '91';
		$this->name = strtolower(preg_replace('/^mod/i', '', get_class($this)));

		$this->description = "Receive e-invoices (XRechnung, ZUGFeRD/Factur-X) from a mailbox";
		$this->descriptionlong = 'Module500220DescLong';

		$this->version = '0.3.0';

		$this->editor_name = 'Gerrett84';
		$this->editor_url = 'https://github.com/Gerrett84';

		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-file-invoice';

		$this->module_parts = array();
		$this->dirs = array('/einvoice/temp', '/einvoice/inbox');

		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("einvoice@einvoice");
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(18, 0);

		$this->const = array();
		$this->config_page_url = array("setup.php@einvoice");
		$this->boxes = array();

		// Scheduled job (disabled by default, enable it in Setup > Scheduled jobs)
		$this->cronjobs = array(
			0 => array(
				'label' => 'EInvoiceFetchCron',
				'jobtype' => 'method',
				'class' => '/einvoice/class/einvoicefetcher.class.php',
				'objectname' => 'EInvoiceFetcher',
				'method' => 'cronFetch',
				'parameters' => '',
				'comment' => 'EInvoiceFetchCronComment',
				'frequency' => 15,
				'unitfrequency' => 60,
				'status' => 0,
				'test' => 'isModEnabled("einvoice")',
				'priority' => 50,
			),
		);

		// Permissions
		$this->rights = array();
		$r = 0;

		$r++;
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = 'Read incoming e-invoices';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'read';

		$r++;
		$this->rights[$r][0] = $this->numero + $r;
		$this->rights[$r][1] = 'Fetch, upload and manage incoming e-invoices';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'write';

		// Menus
		$this->menu = array();
		$r = 0;

		$r++;
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=billing',
			'type' => 'left',
			'titre' => 'EInvoiceInbox',
			'prefix' => img_picto('', 'fa-file-invoice', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'billing',
			'leftmenu' => 'einvoice',
			'url' => '/einvoice/inbox.php?mainmenu=billing&leftmenu=einvoice',
			'langs' => 'einvoice@einvoice',
			'position' => 1300,
			'enabled' => 'isModEnabled("einvoice")',
			'perms' => '$user->hasRight("einvoice", "read")',
			'target' => '',
			'user' => 0,
		);

		$r++;
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=einvoice',
			'type' => 'left',
			'titre' => 'EInvoiceUpload',
			'mainmenu' => 'billing',
			'leftmenu' => 'einvoice_upload',
			'url' => '/einvoice/inbox.php?mainmenu=billing&leftmenu=einvoice&showupload=1',
			'langs' => 'einvoice@einvoice',
			'position' => 1301,
			'enabled' => 'isModEnabled("einvoice")',
			'perms' => '$user->hasRight("einvoice", "write")',
			'target' => '',
			'user' => 0,
		);
		// Setup is reached via Home > Setup > Modules (config_page_url), not from the left menu
	}

	/**
	 * Function called when module is enabled
	 *
	 * @param string $options Options when enabling module
	 * @return int 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/einvoice/sql/');
		if ($result < 0) {
			return -1;
		}

		$sql = array();
		$res = $this->_init($sql, $options);

		// The scheduled job survives disabling the module: bring its comment up to date
		if ($res > 0) {
			$this->db->query("UPDATE ".MAIN_DB_PREFIX."cronjob SET note = 'EInvoiceFetchCronComment' WHERE module_name = 'einvoice' AND methodename = 'cronFetch'");
		}
		// If the job was recreated, restore the automatic fetch setting
		if ($res > 0 && getDolGlobalString('EINVOICE_AUTO') !== '') {
			dol_include_once('/einvoice/class/einvoicefetcher.class.php');
			EInvoiceFetcher::setCronJob($this->db, getDolGlobalInt('EINVOICE_AUTO') === 1, getDolGlobalInt('EINVOICE_AUTO_MINUTES', 15));
		}
		return $res;
	}

	/**
	 * Function called when module is disabled
	 *
	 * @param string $options Options when disabling module
	 * @return int 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		// Remember the automatic fetch setting, the scheduled job is deleted with the module
		dol_include_once('/einvoice/class/einvoicefetcher.class.php');
		$job = EInvoiceFetcher::getCronJob($this->db);
		if ($job) {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			global $conf;
			dolibarr_set_const($this->db, 'EINVOICE_AUTO', (int) $job->status === 1 ? '1' : '0', 'chaine', 0, '', $conf->entity);
			dolibarr_set_const($this->db, 'EINVOICE_AUTO_MINUTES', (string) max(1, (int) round($job->frequency * $job->unitfrequency / 60)), 'chaine', 0, '', $conf->entity);
		}
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
