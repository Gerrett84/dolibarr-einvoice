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
		$this->descriptionlong = "Fetches incoming e-invoices from a mailbox (Microsoft 365 via Graph or any IMAP server), detects XRechnung (CII/UBL) and ZUGFeRD/Factur-X PDFs and collects them in an inbox.";

		$this->version = '0.1.0';

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
				'comment' => 'Fetch e-invoices from the configured mailbox',
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

		$r++;
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=einvoice',
			'type' => 'left',
			'titre' => 'Setup',
			'mainmenu' => 'billing',
			'leftmenu' => 'einvoice_setup',
			'url' => '/einvoice/admin/setup.php?mainmenu=billing&leftmenu=einvoice',
			'langs' => 'admin',
			'position' => 1302,
			'enabled' => 'isModEnabled("einvoice")',
			'perms' => '$user->admin',
			'target' => '',
			'user' => 0,
		);
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
		return $this->_init($sql, $options);
	}

	/**
	 * Function called when module is disabled
	 *
	 * @param string $options Options when disabling module
	 * @return int 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
