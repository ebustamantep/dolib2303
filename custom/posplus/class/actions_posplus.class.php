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
	// The native button has onclick="CloseBill();". We point it to our popup and relabel it.
	var btn = $("button[onclick*=\"CloseBill\"]");
	if (btn.length) {
		btn.attr("onclick", "PosplusPayPlus();");
		btn.html("<span class=\"far fa-money-bill-alt paddingrightonly\"></span><div class=\"trunc\">" + posplusPayTitle + "</div>");
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
}
