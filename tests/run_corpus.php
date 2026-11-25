<?php
// Usage: php tests/run_corpus.php <dir>  (e.g. clones of github.com/itplr-kosit/xrechnung-testsuite
// and github.com/ZUGFeRD/corpus). Runs every XML/PDF of a corpus through analyze() + EInvoiceDetails; prints one line per file.
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { if (!(error_reporting() & $no)) { return false; } throw new ErrorException($str, 0, $no, $file, $line); });
require __DIR__.'/../class/einvoiceparser.class.php';
require __DIR__.'/../class/einvoicedetails.class.php';
$root = $argv[1];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$stats = array();
foreach ($it as $f) {
	$p = $f->getPathname();
	if (strpos($p, '/.git/') !== false || !preg_match('/\.(xml|pdf)$/i', $p)) continue;
	$rel = substr($p, strlen($root) + 1);
	try {
		$c = file_get_contents($p);
		$an = EInvoiceParser::analyze($c, basename($p), preg_match('/\.pdf$/i', $p) ? 'application/pdf' : 'application/xml');
		$kind = $an['kind'];
		$out = array($kind, $an['format']);
		if ($kind === 'einvoice') {
			$d = EInvoiceDetails::read($an['xml']);
			$pr = EInvoiceDetails::check($d);
			$prof = isset($an['data']['profile']) ? $an['data']['profile'] : '';
			$out[] = 'type='.$an['data']['doc_type'];
			$out[] = 'lines='.count($d['lines']).($d['summary'] ? '(summary)' : '');
			$out[] = 'seller='.($an['data']['seller_name'] !== '' ? 'y' : 'N');
			$out[] = 'ref='.($an['data']['invoice_ref'] !== '' ? 'y' : 'N');
			$out[] = 'ttc='.var_export($an['data']['total_ttc'], true);
			// Simulate Dolibarr: line totals rounded, VAT per line rounded, summed
			$ht = 0.0; $tva = 0.0;
			foreach ($d['lines'] as $l) { if ($l['info_only']) continue; $lt = round($l['unit_price'] * $l['qty'], 2); $ht += $lt; $tva += round($lt * $l['vat_rate'] / 100, 2); }
			foreach ($d['allowances'] as $a) { $v = ($a['charge'] ? 1 : -1) * abs((float) $a['amount']); $ht += $v; $tva += round($v * (float) $a['vat_rate'] / 100, 2); }
			$dh = $d['tax_basis'] !== null ? round($ht - $d['tax_basis'], 2) : 0; $dt = $d['grand_total'] !== null ? round($ht + $tva - $d['grand_total'], 2) : 0;
			$out[] = 'doli_diff_ht='.$dh.' ttc='.$dt;
			$out[] = $pr ? 'PROBLEM: '.implode('; ', $pr) : 'ok';
			$out[] = 'profile='.preg_replace('/^.*[#:]/', '', $prof);
			$stats[$pr ? 'einvoice-problem' : 'einvoice-ok'] = (isset($stats[$pr ? 'einvoice-problem' : 'einvoice-ok']) ? $stats[$pr ? 'einvoice-problem' : 'einvoice-ok'] : 0) + 1;
		} else {
			$out[] = $an['error'];
			$stats[$kind] = (isset($stats[$kind]) ? $stats[$kind] : 0) + 1;
		}
		echo $rel, "\t", implode("\t", $out), "\n";
	} catch (Throwable $e) {
		$stats['CRASH'] = (isset($stats['CRASH']) ? $stats['CRASH'] : 0) + 1;
		echo $rel, "\tCRASH\t", get_class($e), ': ', $e->getMessage(), ' @', basename($e->getFile()), ':', $e->getLine(), "\n";
	}
}
echo "== ", json_encode($stats), "\n";
