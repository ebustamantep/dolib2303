<?php
/* Copyright (C) 2026		Edgar Bustamante	<ebustamantep@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    posplus/cashclose.php
 * \ingroup posplus
 * \brief   Cash close report for TakePOS. Shows a date selector, invoice totals
 *          (issued count, total TTC, base HT, VAT) and payment breakdown by mode.
 */

if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1'); // No need to load and show top/left menu
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1'); // Do not load HTML output functions (no header/footer)
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/lib/posplus.lib.php';

// Translations
$langs->loadLangs(array('posplus@posplus', 'bills', 'cashdesk'));

// Security
if (!isModEnabled('posplus')) {
	accessforbidden("Module posplus not enabled");
}
if (!$user->hasRight('takepos', 'run')) {
	accessforbidden();
}

/*
 * View
 */

$form = new Form($db);

$conf->dol_hide_topmenu = 1;
$selfurl = dol_buildpath('/posplus/cashclose.php', 1);
$conf->dol_hide_leftmenu = 1;

$terminal = isset($_SESSION['takeposterminal']) ? $_SESSION['takeposterminal'] : '';

$defaultdate = dol_print_date(dol_now(), '%Y-%m-%d');
$selecteddate = GETPOST('date', 'aZ09', 1);
if (empty($selecteddate)) {
	$selecteddate = $defaultdate;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selecteddate)) {
	$selecteddate = $defaultdate;
}

// Build the day range (full date range)
$year = (int) substr($selecteddate, 0, 4);
$month = (int) substr($selecteddate, 5, 2);
$day = (int) substr($selecteddate, 8, 2);
$datestart = dol_mktime(0, 0, 0, $month, $day, $year);
$dateend = dol_mktime(23, 59, 59, $month, $day, $year);

print '<style>
body { background-color: #fff; padding: 10px; font-size: 14px; }
#cashclosebox { max-width: 700px; margin: auto; }
table.posclose { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
table.posclose th, table.posclose td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
table.posclose th { background-color: #f0f0f0; }
table.posclose td.right, table.posclose th.right { text-align: right; }
.posclose-title { font-size: 1.4em; font-weight: bold; margin-bottom: 10px; }
.posclose-subtitle { margin-bottom: 15px; opacity: 0.8; }
.posclose-company { font-size: 1.3em; font-weight: bold; text-align: center; margin-bottom: 10px; }
.posclose-total { font-weight: bold; }
.posclose-btn { margin-bottom: 15px; }
.posclose-date { margin-bottom: 15px; }

/* Printing on a 80mm thermal receipt printer */
@media print {
	@page { size: 80mm auto; margin: 2mm; }
	html, body {
		width: 76mm;               /* printable width of 80mm roll (minus margins) */
		margin: 0; padding: 0;
		font-family: "Courier New", monospace;
		font-size: 9px;
		line-height: 1.25;
		color: #000;
	}
	#cashclosebox { max-width: 76mm; margin: 0; }
	table.posclose { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
	table.posclose th, table.posclose td {
		border: none; padding: 1px 0; text-align: left; font-size: 9px;
	}
	.posclose-title { font-size: 12px; text-align: center; }
	.posclose-subtitle { font-size: 9px; opacity: 1; margin-bottom: 4px; }
	.posclose-company { font-size: 12px; text-align: center; margin-bottom: 4px; }
	.posclose-btn, .posclose-date { display: none; }
}
</style>';

print '<div id="cashclosebox">';

// Header shown both on screen and on the printed ticket.
// Force dd/mm/yyyy (day/month/year) independent of the installation language.
$selecteddate_dmy = dol_print_date($datestart, '%d/%m/%Y');
$printdate = dol_print_date(dol_now(), '%d/%m/%Y %H:%M', 'tzuserrel');

print '<div class="posclose-company">'.$mysoc->name.'</div>';
print '<div class="posclose-title">'.$langs->trans('PosplusCashClose').'</div>';

// Terminal
$terminalname = getDolGlobalString('TAKEPOS_TERMINAL_NAME_'.$terminal);
if ($terminalname == 'TAKEPOS_TERMINAL_NAME_'.$terminal) {
	$terminalname = '';
}
if ($terminalname && strpos($terminalname, (string) $terminal) !== false) {
	$termlabel = $terminalname;
} else {
	$termlabel = $terminal.($terminalname ? ' - '.$terminalname : '');
}
print '<div class="posclose-subtitle">'.$langs->trans('PosplusTerminal').': '.$termlabel.'</div>';

// Date + printing date (single line, no duplication)
print '<div class="posclose-subtitle">'.$langs->trans('PosplusCashCloseDate').': '.$selecteddate_dmy.' &nbsp;&nbsp; '.$langs->trans('DateOfPrinting').': '.$printdate.'</div>';

// Print button (hidden when printing)
print '<div class="posclose-btn"><button type="button" onclick="window.print()">'.$langs->trans('Print').'</button></div>';

// Date selector: native date input with calendar popup. Reloads when the date changes.
print '<div class="posclose-date">';
print '<input type="date" value="'.dol_escape_htmltag($selecteddate).'" onchange="location.href=\''.dol_escape_js($selfurl).'?date=\'+this.value">';
print '</div>';

if ($terminal == '') {
	print '<div class="error">'.$langs->trans('PosplusNoData').'</div>';
} else {
	// Invoices totals: only validated/closed invoices (fk_statut >= 1), no drafts
	$sql = "SELECT COUNT(*) AS nb, COALESCE(SUM(f.total_ht), 0) AS ht,";
	$sql .= " COALESCE(SUM(f.total_tva), 0) AS tva, COALESCE(SUM(f.total_ttc), 0) AS ttc";
	$sql .= " FROM ".MAIN_DB_PREFIX."facture AS f";
	$sql .= " WHERE f.entity IN (".getEntity('facture').")";
	$sql .= " AND f.module_source = 'takepos'";
	$sql .= " AND f.pos_source = '".$db->escape($terminal)."'";
	$sql .= " AND f.fk_statut >= 1";
	$sql .= " AND f.datef BETWEEN '".$db->idate($datestart)."' AND '".$db->idate($dateend)."'";

	$nb = 0;
	$ht = $tva = $ttc = 0;

	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj) {
			$nb = (int) $obj->nb;
			$ht = (float) $obj->ht;
			$tva = (float) $obj->tva;
			$ttc = (float) $obj->ttc;
		}
		$db->free($resql);
	}

	// Payment breakdown based on what the bank account registered: llx_bank.amount is the
	// amount in the account currency and llx_bank.amount_main_currency the equivalent in the
	// main company currency. Group by payment mode (b.fk_type) and account currency.
	$sql2 = "SELECT b.fk_type AS code, cp.libelle AS label, ba.currency_code AS cur,";
	$sql2 .= " COUNT(*) AS nb,";
	$sql2 .= " COALESCE(SUM(b.amount), 0) AS amount_cur,";
	$sql2 .= " COALESCE(SUM(b.amount_main_currency), 0) AS amount_main";
	$sql2 .= " FROM ".MAIN_DB_PREFIX."bank AS b";
	$sql2 .= " JOIN ".MAIN_DB_PREFIX."bank_account AS ba ON ba.rowid = b.fk_account";
	$sql2 .= " JOIN ".MAIN_DB_PREFIX."paiement AS p ON p.fk_bank = b.rowid";
	$sql2 .= " JOIN ".MAIN_DB_PREFIX."paiement_facture AS pf ON pf.fk_paiement = p.rowid";
	$sql2 .= " JOIN ".MAIN_DB_PREFIX."facture AS f ON f.rowid = pf.fk_facture";
	$sql2 .= " LEFT JOIN ".MAIN_DB_PREFIX."c_paiement AS cp ON cp.code = b.fk_type";
	$sql2 .= " WHERE f.entity IN (".getEntity('facture').")";
	$sql2 .= " AND f.module_source = 'takepos'";
	$sql2 .= " AND f.pos_source = '".$db->escape($terminal)."'";
	$sql2 .= " AND f.fk_statut >= 1";
	$sql2 .= " AND p.entity IN (".getEntity('paiement').")";
	$sql2 .= " AND b.dateo BETWEEN '".$db->idate($datestart)."' AND '".$db->idate($dateend)."'";
	$sql2 .= " GROUP BY b.fk_type, cp.libelle, ba.currency_code";
	$sql2 .= " ORDER BY cp.libelle, ba.currency_code";

	$payments = array();
	$resql2 = $db->query($sql2);
	if ($resql2) {
		while ($obj2 = $db->fetch_object($resql2)) {
			$payments[] = array(
				'code' => $obj2->code,
				'label' => $obj2->label,
				'cur' => $obj2->cur,
				'nb' => (int) $obj2->nb,
				'amount_cur' => (float) $obj2->amount_cur,
				'amount_main' => (float) $obj2->amount_main,
			);
		}
		$db->free($resql2);
	}

	// Section title: invoices
	print '<table class="posclose">';
	print '<tr><th colspan="2">'.$langs->trans('PosplusInvoicesIssued').'</th></tr>';
	print '<tr><td>'.$langs->trans('PosplusInvoicesIssued').'</td><td class="right">'.$nb.'</td></tr>';
	print '<tr><td>'.$langs->trans('PosplusBase').'</td><td class="right">'.posplus_price($ht, $conf->currency).'</td></tr>';
	print '<tr><td>'.$langs->trans('PosplusVAT').'</td><td class="right">'.posplus_price($tva, $conf->currency).'</td></tr>';
	print '<tr class="posclose-total"><td>'.$langs->trans('PosplusTotalInvoiced').'</td><td class="right">'.posplus_price($ttc, $conf->currency).'</td></tr>';
	print '</table>';

	// Section title: payment breakdown
	print '<table class="posclose">';
	print '<tr><th colspan="4">'.$langs->trans('PosplusPaymentsBreakdown').'</th></tr>';
	if (count($payments) > 0) {
		print '<tr><th>'.$langs->trans('PosplusPaymentMode').'</th><th>'.$langs->trans('PosplusCurrency').'</th><th class="right">'.$langs->trans('PosplusAmountForeign').'</th><th class="right">'.$langs->trans('PosplusAmountLocal').'</th></tr>';
		foreach ($payments as $pay) {
			$curlabel = ($pay['cur'] !== '' && $pay['cur'] !== null ? $pay['cur'] : '');
			// Amount as registered in the bank account currency (may have no symbol if not set)
			$amountpos = ($curlabel ? posplus_price($pay['amount_cur'], $curlabel) : posplus_price($pay['amount_cur']).' '.$curlabel);
			// Equivalent in the main company currency
			$mainlabel = (($pay['amount_main'] != 0) ? posplus_price($pay['amount_main'], $conf->currency) : '-');
			print '<tr><td>'.$pay['label'].' ('.$pay['code'].')</td><td>'.($curlabel ? $curlabel : '-').'</td><td class="right">'.$amountpos.'</td><td class="right">'.$mainlabel.'</td></tr>';
		}
	} else {
		print '<tr><td colspan="4">'.$langs->trans('PosplusNoData').'</td></tr>';
	}
	print '</table>';
}

print '</div>';
$db->close();
