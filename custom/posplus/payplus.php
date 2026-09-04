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
 * \file    posplus/payplus.php
 * \ingroup posplus
 * \brief   Payment popup for TakePOS that replaces the native "Payment" button.
 *          Allows receiving payments in different currencies according to the
 *          currency of each bank account (multicurrency), with the equivalent
 *          in the main company currency.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1'); // No need to renew token (popup loaded in an iframe)
}
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
 * @var Societe $mysoc
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/multicurrency/class/multicurrency.class.php';
require_once __DIR__.'/lib/posplus.lib.php';

// Translations
$langs->loadLangs(array('posplus@posplus', 'bills', 'cashdesk', 'banks'));

// Security
if (!isModEnabled('posplus')) {
	accessforbidden("Module posplus not enabled");
}
if (!$user->hasRight('takepos', 'run')) {
	accessforbidden();
}

$place = (GETPOST('place', 'aZ09') ? GETPOST('place', 'aZ09') : '0'); // $place is id of table for Bar or Restaurant
$invoiceid = GETPOSTINT('invoiceid');

$invoice = new Facture($db);
if ($invoiceid > 0) {
	$invoice->fetch($invoiceid);
} else {
	$terminal = isset($_SESSION['takeposterminal']) ? $_SESSION['takeposterminal'] : '';
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."facture";
	$sql .= " WHERE entity IN (".getEntity('invoice').")";
	$sql .= " AND ref = '(PROV-POS".$terminal."-".$place.")'";
	$resql = $db->query($sql);
	$obj = $db->fetch_object($resql);
	if ($obj) {
		$invoiceid = $obj->rowid;
		$invoice->fetch($invoiceid);
	} else {
		$invoiceid = 0;
	}
}

// If no invoice is found (e.g. empty sale), still render the payment modes so the
// modal is usable; the amounts will be 0 until an invoice is selected.
$invoicenotfound = ($invoice->id <= 0);

// Determine the conversion rate for the customer currency (if any).
$sessioncurrency = (!empty($_SESSION["takeposcustomercurrency"]) ? $_SESSION["takeposcustomercurrency"] : '');
$multicurrencyrate = 0;
if (isModEnabled('multicurrency') && $sessioncurrency != '' && $sessioncurrency != $conf->currency) {
	$currencyrateobj = new MultiCurrency($db);
	$currencyrateobj->fetch(0, $sessioncurrency);
	if (isset($currencyrateobj->rate->rate) && (float) $currencyrateobj->rate->rate > 0) {
		$multicurrencyrate = (float) $currencyrateobj->rate->rate;
	}
}

// The invoice may carry its own multicurrency tx. Used as the foreign-per-main rate.
$invoicerate = (float) $invoice->multicurrency_tx;

// List of valid payment modes and their bank account.
// Replicate takepos/pay.php: a mode is shown if it has a configured bank account,
// or (when the bank module is not enabled) if it is CASH or CB.
$arrayOfPaymentModes = array();
$terminal = isset($_SESSION['takeposterminal']) ? $_SESSION['takeposterminal'] : '';

$sql = "SELECT code, libelle as label FROM ".MAIN_DB_PREFIX."c_paiement";
$sql .= " WHERE entity IN (".getEntity('c_paiement').")";
$sql .= " AND active = 1";
$sql .= " ORDER BY libelle";
$resql = $db->query($sql);

if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$paycode = $obj->code;
		if ($paycode == 'LIQ') {
			$paycode = 'CASH';
		}
		if ($paycode == 'CB') {
			$paycode = 'CB';
		}
		if ($paycode == 'CHQ') {
			$paycode = 'CHEQUE';
		}

		$accountname = "CASHDESK_ID_BANKACCOUNT_".$paycode.$terminal;
		$bankaccount = getDolGlobalInt($accountname);
		if ($bankaccount > 0) {
			$acc = new Account($db);
			$acc->fetch($bankaccount);
			$arrayOfPaymentModes[] = array(
				'code' => $obj->code,
				'label' => $obj->label,
				'accountid' => $bankaccount,
				'currency' => ($acc->currency_code ? $acc->currency_code : $conf->currency),
			);
		}
		if (!isModEnabled('bank') && ($paycode == 'CASH' || $paycode == 'CB')) {
			$arrayOfPaymentModes[] = array(
				'code' => $obj->code,
				'label' => $obj->label,
				'accountid' => 0,
				'currency' => $conf->currency,
			);
		}
	}
}

$remaintopay = 0;
$totaltopay = 0;
if (!$invoicenotfound) {
	$remaintopay = $invoice->getRemainToPay();
	$totaltopay = $invoice->total_ttc;
}

// Helper to format an amount in a currency (comma decimals, dot thousands).
function posplus_fmt_amount($amount, $currency_code)
{
	global $langs;
	$amount = (float) $amount;
	$nbdec = 2;
	$formatted = number_format($amount, $nbdec, ',', '.');
	if ($currency_code) {
		$symbol = $langs->getCurrencySymbol($currency_code);
		if (!empty($symbol)) {
			$formatted .= ' '.$symbol;
		} else {
			$formatted .= ' '.$currency_code;
		}
	}
	return $formatted;
}

$maincurrency = $conf->currency;
$rowcount = count($arrayOfPaymentModes);

// Debug helper (only when constant POSPLUS_DEBUG is set to 1)
if (getDolGlobalInt('POSPLUS_DEBUG')) {
	print '<!-- DEBUG payplus: terminal='.dol_escape_htmltag($terminal).' sessionterm='.dol_escape_htmltag(isset($_SESSION['takeposterminal']) ? $_SESSION['takeposterminal'] : 'NULL').' rowcount='.$rowcount.' bank_enabled='.(isModEnabled('bank') ? '1' : '0').' c_paiement_entity='.dol_escape_htmltag(getEntity('c_paiement')).' -->';
}
?>
<!DOCTYPE html>
<html>
<head>
<style>
body { background-color: #fff; padding: 10px; font-size: 14px; font-family: Arial, Helvetica, sans-serif; }
#payplusbox { max-width: 700px; margin: auto; }
.payplus-total { font-size: 1.3em; font-weight: bold; margin-bottom: 8px; }
.payplus-remain { margin-bottom: 12px; }
table.posplus-pay { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
table.posplus-pay th, table.posplus-pay td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
table.posplus-pay th { background-color: #f0f0f0; }
table.posplus-pay td.right, table.posplus-pay th.right { text-align: right; }
.posplus-pay input[type="text"] { width: 110px; text-align: right; }
.payplus-eq { font-size: 0.9em; opacity: 0.85; }
.payplus-change { font-weight: bold; }
.payplus-btn { margin-top: 10px; }
.payplus-btn button { font-size: 1.1em; padding: 8px 16px; }
</style>
<script type="text/javascript" src="<?php echo DOL_URL_ROOT; ?>/includes/jquery/js/jquery.js"></script>
</head>
<body>
<div id="payplusbox">

<?php
if ($invoicenotfound) {
	print '<div class="payplus-remain error">'.$langs->trans('ErrorNoInvoiceSelected').'</div>';
}

// Total header (main currency + customer currency equivalent, like pay.php)
print '<div class="payplus-total">'.$langs->trans('TotalTTC').': '.posplus_fmt_amount($totaltopay, $maincurrency);
if ($multicurrencyrate > 0) {
	print ' &nbsp; <span class="payplus-eq">('.posplus_fmt_amount($totaltopay * $multicurrencyrate, $sessioncurrency).')</span>';
}
print '</div>';

print '<div class="payplus-remain">'.$langs->trans('RemainToPay').': '.posplus_fmt_amount($remaintopay, $maincurrency);
if ($multicurrencyrate > 0) {
	print ' &nbsp; <span class="payplus-eq">('.posplus_fmt_amount($remaintopay * $multicurrencyrate, $sessioncurrency).')</span>';
}
print '</div>';

// Payment modes breakdown
print '<form id="posplusPayForm" onsubmit="return false;">';
print '<table class="posplus-pay">';
print '<tr><th>'.$langs->trans('PosplusPaymentMode').'</th><th>'.$langs->trans('PosplusBankCurrency').'</th><th class="right">'.$langs->trans('PosplusPaymentReceived').'</th><th class="right">'.$langs->trans('PosplusEquivalent').'</th></tr>';

$i = 0;
foreach ($arrayOfPaymentModes as $mode) {
	$i++;
	$cur = $mode['currency'];
	$isforeign = ($cur != $maincurrency);
	// For the equivalent in main currency we need a foreign-per-main rate.
	// It is only relevant when the mode's currency differs from the main currency;
	// otherwise there is no conversion (rate = 1).
	if ($isforeign) {
		$rate = ($invoicerate > 0 ? $invoicerate : ($multicurrencyrate > 0 ? $multicurrencyrate : 1));
	} else {
		$rate = 1;
	}
	$isCash = ($mode['code'] == 'LIQ');

	print '<tr>';
	print '<td>'.$langs->trans('PaymentTypeShort'.$mode['code']).'</td>';
	print '<td>'.$cur.'</td>';
	print '<td class="right"><input type="text" id="posplus_amount_'.$i.'" class="posplus-amount" data-idx="'.$i.'" data-code="'.dol_escape_js($mode['code']).'" data-account="'.((int) $mode['accountid']).'" data-currency="'.dol_escape_js($cur).'" data-rate="'.$rate.'" data-foreign="'.($isforeign ? '1' : '0').'" data-cash="'.($isCash ? '1' : '0').'" value=""></td>';
	print '<td class="right"><span id="posplus_eq_'.$i.'">-</span></td>';
	print '</tr>';
}

if ($i == 0) {
	print '<tr><td colspan="4">'.$langs->trans('NoPaimementModesDefined').'</td></tr>';
}

print '</table>';

// Change (running total in main currency)
print '<div class="payplus-remain"><strong>'.$langs->trans('Change').':</strong> <span id="posplus_change_display">0</span> '.dol_escape_htmltag($maincurrency).'</div>';

print '</form>';

print '<div class="payplus-btn">';
print '<button type="button" onclick="posplusRegisterPayments()">'.$langs->trans('PosplusRegisterPayment').'</button>';
print ' &nbsp; <button type="button" onclick="parent.$.colorbox.close();">'.$langs->trans('Close').'</button>';
print '</div>';
?>

</div>

<script type="text/javascript">
	var posplusRemainToPay = <?php echo (float) $remaintopay; ?>;
	var posplusTotalTtc = <?php echo (float) $totaltopay; ?>;
	var posplusPlace = <?php echo json_encode($place); ?>;
	var posplusToken = <?php echo json_encode(currentToken()); ?>;
	var posplusInvoiceId = <?php echo (int) $invoiceid; ?>;
	var posplusMainCurrency = <?php echo json_encode($maincurrency); ?>;

	function posplusParseAmount(str) {
		if (!str) { return 0; }
		str = String(str).replace(/\s/g, '');
		// Accept "1.234,56" (es-VE) or "1234.56"
		if (str.indexOf(',') > -1 && str.indexOf('.') > -1) {
			str = str.replace(/\./g, '').replace(',', '.');
		} else if (str.indexOf(',') > -1) {
			str = str.replace(',', '.');
		}
		var v = parseFloat(str);
		return isNaN(v) ? 0 : v;
	}

	function posplusFormatAmount(amount) {
		var nb = Number(amount).toFixed(2);
		var parts = nb.split('.');
		var intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
		return intPart + ',' + (parts[1] || '00');
	}

	function posplusUpdateRow(rowIdx) {
		var input = document.getElementById('posplus_amount_' + rowIdx);
		if (!input) { return; }
		var amount = posplusParseAmount(input.value);
		var rate = parseFloat(input.getAttribute('data-rate')) || 1;
		// rate = foreign per main, so main-equivalent = amount / rate.
		var eq = amount / rate;

		var eqEl = document.getElementById('posplus_eq_' + rowIdx);
		if (eqEl) {
			eqEl.textContent = posplusFormatAmount(eq) + ' ' + posplusMainCurrency;
		}

		// Recompute change across all rows (running total in main currency).
		var totalReceivedMain = 0;
		<?php for ($k = 1; $k <= $rowcount; $k++) { ?>
		var inp<?php echo $k; ?> = document.getElementById('posplus_amount_<?php echo $k; ?>');
		if (inp<?php echo $k; ?>) {
			totalReceivedMain += posplusParseAmount(inp<?php echo $k; ?>.value) / (parseFloat(inp<?php echo $k; ?>.getAttribute('data-rate')) || 1);
		}
		<?php } ?>

		var change = totalReceivedMain - posplusRemainToPay;
		var chEl = document.getElementById('posplus_change_display');
		if (chEl) {
			chEl.textContent = (change > 0 ? posplusFormatAmount(change) : '0');
		}
	}

	<?php for ($k = 1; $k <= $rowcount; $k++) { ?>
	document.addEventListener('DOMContentLoaded', function() {
		var input = document.getElementById('posplus_amount_<?php echo $k; ?>');
		if (input) {
			input.addEventListener('input', function() { posplusUpdateRow(<?php echo $k; ?>); });
		}
	});
	<?php } ?>

	function posplusRegisterPayments() {
		var payments = [];
		<?php for ($k = 1; $k <= $rowcount; $k++) { ?>
		var input<?php echo $k; ?> = document.getElementById('posplus_amount_<?php echo $k; ?>');
		var amt<?php echo $k; ?> = input<?php echo $k; ?> ? posplusParseAmount(input<?php echo $k; ?>.value) : 0;
		if (amt<?php echo $k; ?> > 0) {
			payments.push({
				pay: input<?php echo $k; ?>.getAttribute('data-code'),
				amount: amt<?php echo $k; ?>,
				accountid: input<?php echo $k; ?>.getAttribute('data-account'),
				currency: input<?php echo $k; ?>.getAttribute('data-currency'),
				rate: parseFloat(input<?php echo $k; ?>.getAttribute('data-rate')) || 1
			});
		}
		<?php } ?>

		if (payments.length === 0) {
			alert(<?php echo json_encode($langs->trans('TotalAmountEmpty')); ?>);
			return;
		}

		var idx = 0;
		var failed = false;

		function sendNext() {
			if (failed) { return; }
			if (idx >= payments.length) {
				// All done OK: reload the POS lines and close the popup.
				parent.$("#poslines").load("invoice.php?place=" + posplusPlace + "&token=" + posplusToken);
				parent.$('#invoiceid').val("");
				parent.$.colorbox.close();
				return;
			}

			var p = payments[idx++];
			var url = <?php echo json_encode(dol_buildpath('/posplus/payplus_ajax.php', 1)); ?> + '?token=' + posplusToken;
			var data = {
				action: 'addpayment',
				place: posplusPlace,
				invoiceid: posplusInvoiceId,
				pay: p.pay,
				amount: p.amount,
				accountid: p.accountid,
				currency: p.currency,
				rate: p.rate
			};
			$.post(url, data, function(resp) {
				if (resp && resp.error) {
					failed = true;
					alert(resp.error);
					return;
				}
				sendNext();
			}, 'json').fail(function() {
				failed = true;
				alert(<?php echo json_encode($langs->trans('Error')); ?>);
			});
		}

		sendNext();
	}
</script>
</body>
</html>
<?php
$db->close();
