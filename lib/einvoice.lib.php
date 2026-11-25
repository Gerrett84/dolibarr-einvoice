<?php
/* Copyright (C) 2026 Gerrett84
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       lib/einvoice.lib.php
 * \ingroup    einvoice
 * \brief      Helpers usable with and without Dolibarr (standalone tests)
 */

if (!function_exists('einvoice_t')) {
	/**
	 * Translated message (module language file), English fallback outside Dolibarr.
	 *
	 * @param string $key      Language key
	 * @param string $fallback English text with %s placeholders
	 * @param mixed  ...$args  Parameters
	 * @return string Plain text (no HTML entities)
	 */
	function einvoice_t($key, $fallback, ...$args)
	{
		global $langs;
		if (is_object($langs) && method_exists($langs, 'transnoentitiesnoconv')) {
			$langs->load('einvoice@einvoice');
			$args = array_map('strval', $args);
			$t = $langs->transnoentitiesnoconv($key, ...$args);
			if ($t !== $key) {
				return $t;
			}
		}
		return $args ? vsprintf($fallback, $args) : $fallback;
	}

	/**
	 * Amount for messages: Dolibarr format when available.
	 *
	 * @param float $v Amount
	 * @return string
	 */
	function einvoice_amount($v)
	{
		if (function_exists('price')) {
			return price((float) $v, 0, '', 1, -1, 2);
		}
		return sprintf('%.2f', (float) $v);
	}
}
