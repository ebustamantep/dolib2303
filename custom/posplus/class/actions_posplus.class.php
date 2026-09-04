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
 * \file    htdocs/posplus/class/actions_posplus.class.php
 * \ingroup posplus
 * \brief   Hooks for TakePOS: cash close button and (optionally) showing the price
 *          in the customer currency under the main price of each product in the div5.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

/**
 * Class ActionsPosplus
 */
class ActionsPosplus extends CommonHookActions
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var string[] Errors
	 */
	public $errors = array();

	/**
	 * @var mixed[] Hook results.
	 */
	public $results = array();

	/**
	 * @var ?string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * @var int Priority of hook (50 if not defined)
	 */
	public $priority = 50;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Return the conversion rate for the customer currency selected in the POS,
	 * or 0 if the feature must not be enabled.
	 *
	 * @return float The conversion rate, or 0 if disabled
	 */
	protected function getPosCurrencyRate()
	{
		global $conf;

		$sessioncurrency = (!empty($_SESSION["takeposcustomercurrency"]) ? $_SESSION["takeposcustomercurrency"] : '');
		if (!isModEnabled('multicurrency') || $sessioncurrency == '' || $sessioncurrency == $conf->currency) {
			return 0;
		}

		include_once DOL_DOCUMENT_ROOT.'/multicurrency/class/multicurrency.class.php';
		$multicurrency = new MultiCurrency($this->db);
		$multicurrency->fetch(0, $sessioncurrency);
		if (!isset($multicurrency->rate->rate) || (float) $multicurrency->rate->rate <= 0) {
			return 0;
		}

		return (float) $multicurrency->rate->rate;
	}

	/**
	 * actionButtons. Called on the TakePOS frontend to add buttons to the action bar.
	 *
	 * @param array<string,mixed> $parameters Hook metadata
	 * @param CommonObject        $object    The object
	 * @param string              $action    Current action (if set)
	 * @param HookManager         $hookmanager Hook manager
	 * @return int <0 on error, 0 on success (0 = add buttons, 1 = replace)
	 */
	public function actionButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$langs->load('posplus@posplus');

		$this->results = array(
			array(
				array(
					'title' => '<span class="fa fa-money-bill-wave paddingrightonly"></span><div class="trunc">'.$langs->trans("PosplusCashClose").'</div>',
					'action' => 'PosplusCashClose();',
				),
			),
		);

		return 0;
	}

	/**
	 * addHtmlHeader. Called into <head> by top_htmlhead (context "main"). We inject
	 * the JS needed by the cash close button and, if applicable, the multicurrency
	 * price display. Only when the page is the TakePOS frontend.
	 *
	 * @param array<string,mixed> $parameters Hook metadata
	 * @param CommonObject        $object    The object
	 * @param string              $action    Current action (if set)
	 * @param HookManager         $hookmanager Hook manager
	 * @return int <0 on error, 0 on success
	 */
	public function addHtmlHeader($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs;

		$this->resprints = '';

		// Only for the TakePOS frontend page.
		$script = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '';
		if (strpos($script, '/takepos/index.php') === false) {
			return 0;
		}

		$langs->load('posplus@posplus');

		$today = dol_print_date(dol_now(), '%Y-%m-%d'); // server date
		$moduleurl = dol_buildpath('/posplus/cashclose.php', 1);
		$payurl = dol_buildpath('/posplus/payplus.php', 1);

		$this->resprints = '<style>
.productprice-sub {
	font-size: 1em;
	font-weight: normal;
	opacity: 0.9;
	text-align: right;
	padding-top: 1px;
	white-space: nowrap;
}
</style>
<script>
var posplusCashCloseUrl = "'.dol_escape_js($moduleurl).'";
var posplusCashCloseToken = "'.dol_escape_js(currentToken()).'";
var posplusCashCloseToday = "'.dol_escape_js($today).'";

function PosplusCashClose() {
	$.colorbox({href: posplusCashCloseUrl + "?token=" + posplusCashCloseToken + "&date=" + posplusCashCloseToday, width: "80%", height: "80%", transition: "none", iframe: "true", title: "'.dol_escape_js($langs->trans("PosplusCaisseClose")).'"});
}

// Replacement of the native "Payment" button by the POSPlus "Pagar" popup.
var posplusPayUrl = "'.dol_escape_js($payurl).'";
var posplusPayToken = "'.dol_escape_js(currentToken()).'";
var posplusPayTitle = "'.dol_escape_js($langs->trans("PosplusPayButton")).'";

function PosplusPayPlus() {
	var invoiceid = $("#invoiceid").val();
	$.colorbox({href: posplusPayUrl + "?place=" + place + "&invoiceid=" + invoiceid + "&token=" + posplusPayToken, width: "80%", height: "90%", transition: "none", iframe: "true", title: posplusPayTitle});
}

$(document).ready(function() {
	// The native button has onclick="CloseBill();". We point it to our popup, relabel it
	// and style it green so the operator easily spots the payment action.
	var btn = $("button[onclick*=\"CloseBill\"]");
	if (btn.length) {
		btn.attr("onclick", "PosplusPayPlus();");
		btn.html("<span class=\"far fa-money-bill-alt paddingrightonly\"></span><div class=\"trunc\">" + posplusPayTitle + "</div>");
		btn.css("background-color", "#28a745");
		btn.css("color", "#ffffff");
		btn.css("border-color", "#1e7e34");
	}
});
</script>';

		// Multicurrency price display (optional)
		$rate = $this->getPosCurrencyRate();
		if ($rate > 0) {
			$sessioncurrency = $_SESSION["takeposcustomercurrency"];
			$symbol = dol_escape_js($langs->getCurrencySymbol($sessioncurrency));

			$this->resprints .= '<script>
var posplusMulticurrencyRate = '.((float) $rate).';
var posplusMulticurrencyCode = "'.dol_escape_js($sessioncurrency).'";
var posplusMulticurrencySymbol = "'.$symbol.'";

function posplusFormatCurrency(amount) {
	if (isNaN(amount)) { return ""; }

	var decCount = 2;
	var nb = Number(amount).toFixed(decCount);
	var parts = nb.split(".");
	var intPart = parts[0];
	var decPart = parts.length > 1 ? parts[1] : "";

	// Thousands separator: dot.
	intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ".");

	// Decimal separator: comma.
	var out = intPart + (decPart ? "," + decPart : "");
	var sym = posplusMulticurrencySymbol || posplusMulticurrencyCode;
	return out + " " + sym;
}

function posplusAppendSecondaryPrice(containerId, price) {
	var el = document.getElementById(containerId);
	if (!el) { return; }

	// Avoid duplicates if the element is filled several times (e.g. re-render).
	if (el.querySelector(".productprice-sub")) { return; }

	var converted = price * posplusMulticurrencyRate;
	var sub = document.createElement("div");
	sub.className = "productprice-sub";
	sub.textContent = posplusFormatCurrency(converted);
	el.appendChild(sub);
}
</script>';
		}

		// Show/hide the invoice discount button, enable/disable the modify price and line
		// discount buttons, and hide the split-sale and free-zone buttons.
		// DESCUENTO_FACTURA = 1 -> show the invoice discount button, 0 -> hide it.
		// MODIFICAR_PRECIO = 1 -> price modifiable, 0 -> show it but disabled.
		// DESCUENTO_LINEA = 1 -> line discount enabled, 0 -> show it but disabled.
		// VENTA_DIVIDIDA = 1 -> show split sale button, 0 -> hide it.
		// PRODUCTO_LIBRE = 1 -> show free zone button, 0 -> hide it.
		$showdiscount = getDolGlobalInt('DESCUENTO_FACTURA', 0);
		$enableprice = getDolGlobalInt('MODIFICAR_PRECIO', 0);
		$enablelinediscount = getDolGlobalInt('DESCUENTO_LINEA', 0);
		$showsplit = getDolGlobalInt('VENTA_DIVIDIDA', 0);
		$showfreezone = getDolGlobalInt('PRODUCTO_LIBRE', 0);

		$this->resprints .= '<script>
$(document).ready(function() {
	// Invoice discount button (added by core in the action menu with Reduction()).
	var discountAfter = '.(($showdiscount) ? 'true' : 'false').';
	if (!discountAfter) {
		$("button[onclick*=\"Reduction\"]").hide();
	}

	function disableBtn(sel, enabled) {
		var btn = $(sel);
		if (btn.length) {
			if (enabled) {
				btn.prop("disabled", false);
				btn.css("opacity", "");
			} else {
				btn.prop("disabled", true);
				btn.css("opacity", "0.4");
			}
		}
	}

	// Modify price button (id="price" in the numpad).
	disableBtn("#price", '.(($enableprice) ? 'true' : 'false').');

	// Line discount button (id="reduction" in the numpad).
	disableBtn("#reduction", '.(($enablelinediscount) ? 'true' : 'false').');

	// Split sale button (core action Split()).
	var splitAfter = '.(($showsplit) ? 'true' : 'false').';
	if (!splitAfter) {
		$("button[onclick*=\"Split()\"]").hide();
	}

	// Free zone (free product) button (core action FreeZone()).
	var freezoneAfter = '.(($showfreezone) ? 'true' : 'false').';
	if (!freezoneAfter) {
		$("button[onclick*=\"FreeZone()\"]").hide();
	}
});
</script>';

		return 0;
	}

	/**
	 * completeJSProductDisplay. Called for each product displayed in the div5
	 * (context "takeposfrontend"). $this->resprints is output as raw JS inside the
	 * product display loop, right after the main price is rendered. We append the
	 * price converted to the customer currency.
	 *
	 * @param array<string,mixed> $parameters Hook metadata ('caller' is 'loadProducts' or 'search2')
	 * @param CommonObject        $object    The object
	 * @param string              $action    Current action (if set)
	 * @param HookManager         $hookmanager Hook manager
	 * @return int <0 on error, 0 on success
	 */
	public function completeJSProductDisplay($parameters, &$object, &$action, $hookmanager)
	{
		global $conf;

		$this->resprints = '';

		if (!isset($parameters['caller'])) {
			return 0;
		}

		$rate = $this->getPosCurrencyRate();
		if ($rate <= 0) {
			return 0;
		}

		// In category loading the div index is 'ishow' and the data index is 'idata';
		// in search both are 'i'. We reuse them so the hook works in both cases.
		$divIndex = ($parameters['caller'] == 'search2') ? 'i' : 'ishow';
		$dataIndex = ($parameters['caller'] == 'search2') ? 'i' : 'idata';

		$priceField = (getDolGlobalInt('TAKEPOS_CHANGE_PRICE_HT') ? 'price' : 'price_ttc');

		$this->resprints = '
		if (typeof '.$dataIndex.' !== "undefined" && data['.$dataIndex.'] && data['.$dataIndex.']["'.$priceField.'"] !== undefined) {
			posplusAppendSecondaryPrice("proprice"+'.$divIndex.', parseFloat(data['.$dataIndex.']["'.$priceField.'"]));
		}';

		return 0;
	}

	/**
	 * completeTakePosInvoiceHeader. Called on the TakePOS invoice header (context
	 * "takeposinvoice"). We rewrite the customer block (#customerandsales) so it
	 * shows the full customer name and, on a second line, the intracommunity VAT
	 * number, without touching the core file.
	 *
	 * @param array<string,mixed> $parameters Hook metadata
	 * @param CommonObject        $object    The invoice object
	 * @param string              $action    Current action (if set)
	 * @param HookManager         $hookmanager Hook manager
	 * @return int <0 on error, 0 on success
	 */
	public function completeTakePosInvoiceHeader($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs, $conf, $user;

		$this->resprints = '';

		if (!is_object($object) || empty($object->element) || $object->element != 'facture') {
			return 0;
		}

		// Load the module translation file so the intra label is translated.
		$langs->load('posplus@posplus');

		// Only if a real customer is set (not the generic POS thirdparty).
		$constforcompanyid = 'CASHDESK_ID_THIRDPARTY'.(empty($_SESSION['takeposterminal']) ? '1' : $_SESSION['takeposterminal']);
		$genericid = getDolGlobalInt($constforcompanyid);
		if (empty($object->socid) || (int) $object->socid == (int) $genericid) {
			return 0;
		}

		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$soc = new Societe($db);
		if ($soc->fetch($object->socid) <= 0) {
			return 0;
		}

		$name = $soc->name;
		$intra = trim($soc->tva_intra);
		// Native translation for the local tax id (CI/RIF in es_VE). The key is the native
		// ProfId1ES from the es_VE companies.lang file (do not add a new custom key).
		$intralabel = $langs->transnoentities('ProfId1ES');

		$this->resprints = '<script>
	$(document).ready(function() {
		var cs = $("#customerandsales");
		if (!cs.length) { return; }
		var pname = '.(json_encode($name)).';
		var pintra = '.(json_encode($intra)).';
		var pintralabel = '.(json_encode($intralabel)).';
		cs.html("").append(\'<a class="valignmiddle" id="customer" onclick="Customer();" style="line-height:1.15; display:inline-block;"><span class="fas fa-building paddingrightonly"></span><span><b>\' + pname + \'</b>\' + (pintra ? \'<br><span style="font-size:0.8em; opacity:0.85;">\' + pintralabel + \': \' + pintra + \'</span>\' : \'\') + \'</span></a>\');
	});
	</script>';

		return 0;
	}

	/**
	 * TakeposReceipt. Called on the TakePOS receipt page (context "takeposfrontend",
	 * hook TakeposReceipt). The core receipt.php prints only the customer name; we
	 * re-render the ticket so it also shows the customer's CI/RIF (tva_intra) right
	 * after the name, without touching the core file.
	 *
	 * @param array<string,mixed> $parameters Hook metadata
	 * @param CommonObject        $object    The invoice object
	 * @param string              $action    Current action (if set)
	 * @param HookManager         $hookmanager Hook manager
	 * @return int <0 on error, 0 on success
	 */
	public function TakeposReceipt($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs, $conf, $mysoc;

		$this->resprints = '';

		if (!is_object($object) || empty($object->element) || $object->element != 'facture') {
			return 0;
		}
		if (!getDolGlobalString('TAKEPOS_SHOW_CUSTOMER')) {
			return 0; // Core shows customer name only when this flag is on; nothing to extend otherwise.
		}

		// Only extend when the invoice has a real customer (not the generic POS thirdparty).
		$constforcompanyid = 'CASHDESK_ID_THIRDPARTY'.(empty($_SESSION['takeposterminal']) ? '1' : $_SESSION['takeposterminal']);
		$genericid = getDolGlobalInt($constforcompanyid);
		if (empty($object->socid) || (int) $object->socid == (int) $genericid) {
			return 0;
		}

		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$soc = new Societe($db);
		if ($soc->fetch($object->socid) <= 0) {
			return 0;
		}

		$langs->loadLangs(array("main", "bills", "cashdesk", "companies", "posplus@posplus"));

		$intra = trim($soc->tva_intra);
		$intralabel = $langs->transnoentities('ProfId1ES'); // CI/RIF in es_VE (native key)

		$facid = (int) $object->id;
		$gift = GETPOSTINT('gift');

		$out = '';

		// Header: company name + specimen
		$out .= '<center><div style="font-size: 1.5em"><b>'.$mysoc->name.'</b>';
		if (GETPOST('specimen')) {
			$out .= '<br>!!!!! SPECIMEN !!!!!';
		}
		$out .= '</div></center><br>';

		// Header free text
		$out .= '<p class="left">';
		$constFreeText = 'TAKEPOS_HEADER'.(empty($_SESSION['takeposterminal']) ? '0' : $_SESSION['takeposterminal']);
		if (getDolGlobalString('TAKEPOS_HEADER') || getDolGlobalString($constFreeText)) {
			$newfreetext = '';
			$substitutionarray = getCommonSubstitutionArray($langs);
			complete_substitutions_array($substitutionarray, $langs, $object);
			if (getDolGlobalString('TAKEPOS_HEADER')) {
				$newfreetext .= make_substitutions(getDolGlobalString('TAKEPOS_HEADER'), $substitutionarray);
			}
			if (getDolGlobalString($constFreeText)) {
				$newfreetext .= make_substitutions(getDolGlobalString($constFreeText), $substitutionarray);
			}
			$out .= nl2br($newfreetext);
		}
		$out .= '</p>';

		// Reference / terminal / customer (name + CI/RIF)
		$out .= '<p class="right">';
		if (getDolGlobalString('TAKEPOS_RECEIPT_NAME')) {
			$out .= getDolGlobalString('TAKEPOS_RECEIPT_NAME').' ';
		} else {
			$out .= $langs->trans("InvoiceRef").' ';
		}
		$out .= $object->ref;
		$out .= '<br>'.$langs->trans("Terminal").' '.($object->pos_source ? $object->pos_source : 'Backoffice');
		// Customer: name + CI/RIF
		$out .= '<br>'.$langs->trans("Customer").': '.$soc->name;
		if ($intra) {
			$out .= ' &middot; '.$intralabel.': '.$intra;
		}
		// Date
		$out .= '<br>'.$langs->trans('Date').': '.dol_print_date($object->date ? $object->date : dol_now(), 'day');
		// Date of printing
		if (isALNERunningVersion() || !getDolGlobalString('TAKEPOS_HIDE_DATE_OF_PRINTING')) {
			$out .= '<br>'.$langs->trans("DateOfPrinting").': '.dol_print_date(dol_now(), 'dayhour', 'tzuserrel');
		}
		$out .= '</p><br>';

		// Lines
		$out .= '<table class="centpercent" style="border-top-style: double;"><thead><tr>';
		$out .= '<th class="left">'.$langs->trans("Label").'</th>';
		$out .= '<th class="right">'.$langs->trans("Qty").'</th>';
		$out .= '<th class="right">'.($gift != 1 ? $langs->trans("Price") : '').'</th>';
		if (getDolGlobalString('TAKEPOS_SHOW_HT_RECEIPT')) {
			$out .= '<th class="right">'.($gift != 1 ? $langs->trans("TotalHT") : '').'</th>';
		}
		$out .= '<th class="right">'.($gift != 1 ? $langs->trans("TotalTTC") : '').'</th>';
		$out .= '</tr></thead><tbody>';
		foreach ($object->lines as $line) {
			$out .= '<tr>';
			$out .= '<td>'.(!empty($line->product_label) ? $line->product_label : $line->desc).'</td>';
			$out .= '<td class="right">'.$line->qty.'</td>';
			if ($gift != 1) {
				$out .= '<td class="right">'.price(price2num($line->total_ttc / $line->qty, 'MT'), 1).'</td>';
			}
			if (getDolGlobalString('TAKEPOS_SHOW_HT_RECEIPT')) {
				$out .= '<td class="right">'.($gift != 1 ? price($line->total_ht, 1) : '').'</td>';
			}
			$out .= '<td class="right">'.($gift != 1 ? price($line->total_ttc, 1) : '').'</td>';
			$out .= '</tr>';
		}
		$out .= '</tbody></table><br>';

		// Totals
		$out .= '<table class="right centpercent"><tr>';
		$out .= '<th class="right">'.($gift != 1 ? $langs->trans("TotalHT") : '').'</th>';
		$out .= '<td class="right">'.($gift != 1 ? price($object->total_ht, 1, '', 1, -1, -1, $conf->currency) : '').'</td></tr>';
		if (getDolGlobalString('TAKEPOS_TICKET_VAT_GROUPPED')) {
			$vat_groups = array();
			foreach ($object->lines as $line) {
				if (!array_key_exists((string) $line->tva_tx, $vat_groups)) {
					$vat_groups[(string) $line->tva_tx] = 0;
				}
				$vat_groups[(string) $line->tva_tx] += $line->total_tva;
			}
			foreach ($vat_groups as $key => $val) {
				$out .= '<tr><th class="right">'.($gift != 1 ? $langs->trans("VAT").' '.vatrate($key, true) : '').'</th>';
				$out .= '<td class="right">'.($gift != 1 ? price($val, 1, '', 1, -1, -1, $conf->currency) : '').'</td></tr>';
			}
		} else {
			$out .= '<tr><th class="right">'.$langs->trans("TotalVAT").'</th><td class="right">'.price($object->total_tva, 1, '', 1, -1, -1, $conf->currency).'</td></tr>';
		}
		if (price2num($object->total_localtax1, 'MU') || $mysoc->useLocalTax(1)) {
			$out .= '<tr><th class="right">'.$langs->trans("TotalLT1").'</th><td class="right">'.price($object->total_localtax1, 1, '', 1, -1, -1, $conf->currency).'</td></tr>';
		}
		if (price2num($object->total_localtax2, 'MU') || $mysoc->useLocalTax(2)) {
			$out .= '<tr><th class="right">'.$langs->trans("TotalLT2").'</th><td class="right">'.price($object->total_localtax2, 1, '', 1, -1, -1, $conf->currency).'</td></tr>';
		}
		$out .= '<tr><th class="right">'.$langs->trans("TotalTTC").'</th><td class="right">'.price($object->total_ttc, 1, '', 1, -1, -1, $conf->currency).'</td></tr>';

		// Payments
		if (getDolGlobalString('TAKEPOS_PRINT_PAYMENT_METHOD')) {
			$sql = "SELECT p.pos_change as pos_change, p.datep as date, p.fk_paiement, p.num_paiement as num,";
			$sql .= " f.multicurrency_code, pf.amount as amount, pf.multicurrency_amount, cp.code";
			$sql .= " FROM ".MAIN_DB_PREFIX."paiement_facture as pf, ".MAIN_DB_PREFIX."facture as f, ".MAIN_DB_PREFIX."paiement as p";
			$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_paiement as cp ON p.fk_paiement = cp.id";
			$sql .= " WHERE pf.fk_facture = f.rowid AND pf.fk_paiement = p.rowid AND pf.fk_facture = ".((int) $facid);
			$sql .= " ORDER BY p.datep";
			$resql = $db->query($sql);
			if ($resql) {
				$num = $db->num_rows($resql);
				$i = 0;
				while ($i < $num) {
					$row = $db->fetch_object($resql);
					$out .= '<tr>';
					$out .= '<td class="right">'.$langs->transnoentitiesnoconv("PaymentTypeShort".$row->code).'</td>';
					$out .= '<td class="right">';
					$amount_payment = (isModEnabled('multicurrency') && $object->multicurrency_tx != 1) ? $row->multicurrency_amount : $row->amount;
					if ((!isModEnabled('multicurrency') || $object->multicurrency_tx == 1) && $row->code == "LIQ" && $row->pos_change > 0) {
						$amount_payment += $row->pos_change;
						$currency = $conf->currency;
					} else {
						$currency = $row->multicurrency_code;
					}
					$out .= price($amount_payment, 1, '', 1, -1, -1, $currency);
					$out .= '</td></tr>';
					if ((!isModEnabled('multicurrency') || $object->multicurrency_tx == 1) && $row->code == "LIQ" && $row->pos_change > 0) {
						$out .= '<tr><td class="right">'.$langs->trans("Change").'</td><td class="right">'.price($row->pos_change, 1, '', 1, -1, -1, $currency).'</td></tr>';
					}
					$i++;
				}
			}
		}

		$out .= '</table><br><br><br>';

		// Footer free text
		$constFreeText = 'TAKEPOS_FOOTER'.(empty($_SESSION['takeposterminal']) ? '0' : $_SESSION['takeposterminal']);
		if (getDolGlobalString('TAKEPOS_FOOTER') || getDolGlobalString($constFreeText)) {
			$newfreetext = '';
			$substitutionarray = getCommonSubstitutionArray($langs);
			complete_substitutions_array($substitutionarray, $langs, $object);
			if (getDolGlobalString($constFreeText)) {
				$newfreetext .= make_substitutions(getDolGlobalString($constFreeText), $substitutionarray);
			}
			if (getDolGlobalString('TAKEPOS_FOOTER')) {
				$newfreetext .= make_substitutions(getDolGlobalString('TAKEPOS_FOOTER'), $substitutionarray);
			}
			$out .= $newfreetext;
		}

		// Auto print
		if (!GETPOST('forcenoautoopen')) {
			$out .= '<script type="text/javascript">';
			if ($facid) {
				$out .= 'window.print();';
			}
			$out .= '</script>';
		}

		$this->resprints = $out;
		return 0;
	}
}
