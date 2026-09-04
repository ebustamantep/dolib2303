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
 * \file    posplus/payplus_ajax.php
 * \ingroup posplus
 * \brief   AJAX endpoint used by the payplus.php popup to register a payment
 *          that may be in a different currency than the main company currency.
 *          Replicates the native takepos/invoice.php action=valid block, but
 *          supports multicurrency amounts (bank account currency).
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
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

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/multicurrency/class/multicurrency.class.php';

// Translations
$langs->loadLangs(array('posplus@posplus', 'bills', 'cashdesk', 'banks', 'errors'));

header('Content-Type: application/json');

// Security
if (!isModEnabled('posplus')) {
	print json_encode(array('error' => 'Module posplus not enabled'));
	$db->close();
	exit;
}
if (!$user->hasRight('takepos', 'run')) {
	print json_encode(array('error' => 'Access forbidden'));
	$db->close();
	exit;
}
if (!$user->hasRight('facture', 'creer')) {
	print json_encode(array('error' => $langs->trans('PermissionDenied')));
	$db->close();
	exit;
}

$payaction = GETPOST('action', 'aZ09');
if ($payaction != 'addpayment') {
	print json_encode(array('error' => 'Invalid action'));
	$db->close();
	exit;
}

$place = (GETPOST('place', 'aZ09') ? GETPOST('place', 'aZ09') : '0');
$invoiceid = GETPOSTINT('invoiceid');
$pay = GETPOST('pay', 'aZ09');
$amountofpayment = (float) GETPOSTFLOAT('amount');
$accountid = GETPOSTINT('accountid');
$currency = GETPOST('currency', 'aZ09'); // currency of the bank account
$rate = (float) GETPOSTFLOAT('rate'); // foreign-per-main rate (0 if unknown)

$error = 0;
$errormsg = '';

// Load the invoice
$invoice = new Facture($db);
if ($invoiceid > 0) {
	$ret = $invoice->fetch($invoiceid);
} else {
	$terminal = isset($_SESSION['takeposterminal']) ? $_SESSION['takeposterminal'] : '';
	$ret = $invoice->fetch(0, '(PROV-POS'.$terminal.'-'.$place.')');
}
if ($ret <= 0) {
	print json_encode(array('error' => 'Invoice not found'));
	$db->close();
	exit;
}

// Normalize the payment code to a c_paiement code.
$paycode = $pay;
if ($pay == 'cash') {
	$paycode = 'LIQ';
}
if ($pay == 'card') {
	$paycode = 'CB';
}
if ($pay == 'cheque') {
	$paycode = 'CHQ';
}

// Find the id of the payment mode in c_paiement.
$paiementid = 0;
if ($paycode) {
	$sql = "SELECT id, code FROM ".MAIN_DB_PREFIX."c_paiement";
	$sql .= " WHERE entity IN (".getEntity('c_paiement').")";
	$sql .= " AND code = '".$db->escape($paycode)."'";
	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj) {
			$paiementid = (int) $obj->id;
		}
	}
}

// Determine the bank account (must be set by the popup).
$bankaccount = $accountid;
if ($bankaccount <= 0) {
	$bankaccount = getDolGlobalInt('CASHDESK_ID_BANKACCOUNT_'.strtoupper($paycode).$_SESSION['takeposterminal']);
}

if ($bankaccount <= 0 && !empty($paycode) && isModEnabled('bank')) {
	$errormsg = $langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("BankAccount"));
	$error++;
}

// We proceed with the payment even without a bank account when the bank module is
// disabled (the native flow allows paying with CASH/CB in that case).
$havebankaccount = ($bankaccount > 0);
$bankcurrency = $conf->currency;
if ($havebankaccount) {
	// Load the bank account to get its currency code.
	$acc = new Account($db);
	$acc->fetch($bankaccount);
	$bankcurrency = ($acc->currency_code ? $acc->currency_code : $conf->currency);
}

if (!$error && !empty($paycode)) {
	$now = dol_now();
	$invoice->oldcopy = dol_clone($invoice, 2);
	$db->begin();

	if ($invoice->status != Facture::STATUS_DRAFT) {
		if ($invoice->status != Facture::STATUS_CLOSED) {
			$error++;
			$errormsg = $langs->trans('InvoiceIsAlreadyValidated', 'TakePos');
		}
		// If invoice already validated but not fully paid, we must still be able to add a payment.
		$remaintopay = $invoice->getRemainToPay();
		if ($remaintopay > 0 && $invoice->type != Facture::TYPE_CREDIT_NOTE) {
			$error = 0;
			$errormsg = '';
		}
	}

	if (!$error && count($invoice->lines) == 0) {
		$error++;
		$errormsg = $langs->trans('NoLinesToBill', 'TakePos');
	}

	$remaintopay = $invoice->getRemainToPay();
	if (!$error && $remaintopay <= 0) {
		$error++;
		$errormsg = $langs->trans('InvoiceIsAlreadyValidated', 'TakePos');
	}

	$res = 0;
	// Validate the draft invoice (same logic as takepos/invoice.php action=valid).
	if (!$error) {
		if ($invoice->status == Facture::STATUS_DRAFT) {
			$constantforkey = 'CASHDESK_NO_DECREASE_STOCK'.(isset($_SESSION["takeposterminal"]) ? $_SESSION["takeposterminal"] : '');
			$allowstockchange = (getDolGlobalString($constantforkey) != "1");

			if (isModEnabled('stock') && !isModEnabled('productbatch') && $allowstockchange) {
				$savconst = getDolGlobalString('STOCK_CALCULATE_ON_BILL');
				$conf->global->STOCK_CALCULATE_ON_BILL = 1; // Force stock change during invoice validation
				$constantforkey = 'CASHDESK_ID_WAREHOUSE'.(isset($_SESSION["takeposterminal"]) ? $_SESSION["takeposterminal"] : '');
				$batch_rule = 0;
				$res = $invoice->validate($user, '', getDolGlobalInt($constantforkey), 0, $batch_rule);
				$conf->global->STOCK_CALCULATE_ON_BILL = $savconst;
			} else {
				$res = $invoice->validate($user);
				if ($res < 0) {
					$error++;
					$langs->load("admin");
					$errormsg = ($invoice->error == 'NotConfigured' ? $langs->trans("NotConfigured").' (TakePos numbering module)' : $invoice->error);
				}
			}
		} else {
			// Already validated (not draft): just allow payment if there is a remaining amount.
			$res = 1;
		}
	}

	if (!$error && $res >= 0) {
		// Reload remaining amount after validation.
		$remaintopay = $invoice->getRemainToPay();

		$payment = new Paiement($db);
		$payment->datepaye = $now;
		$payment->fk_account = $bankaccount;
		if ($paycode == 'LIQ') {
			$payment->pos_change = GETPOSTFLOAT('excess');
		}

		// Paiement::create() converts using the rate stored on the invoice
		// (MultiCurrency::getInvoiceRate). Only use the multicurrency path when the
		// invoice actually carries a rate; otherwise fall back to a main-currency payment.
		$invoicestoredrate = (float) $invoice->multicurrency_tx;
		$invoicestoredrate = ($invoicestoredrate > 0) ? $invoicestoredrate : (($rate > 0) ? $rate : 1);
		$usemulticurrency = ($havebankaccount && $bankcurrency != $conf->currency && (float) $invoice->multicurrency_tx > 0);

		// Amount in main currency (for the cap on the remain to pay).
		$mainamount = $amountofpayment;
		if ($usemulticurrency) {
			$mainamount = $amountofpayment / $invoicestoredrate;
		}

		// For the payment record, if the bank account is in a foreign currency (and the
		// invoice has a rate) we set the multicurrency amount (in the invoice/bank currency)
		// and let create() fill the main amount. Otherwise we record a plain main-currency payment.
		$invoicedcurrency = $invoice->multicurrency_code;
		if ($usemulticurrency) {
			$payment->amounts[$invoice->id] = price2num($mainamount, 'MT'); // main currency
			$payment->multicurrency_amounts[$invoice->id] = price2num($amountofpayment, 'MT'); // foreign currency
			$payment->multicurrency_code[$invoice->id] = ($invoicedcurrency ? $invoicedcurrency : $bankcurrency);
			$payment->multicurrency_tx[$invoice->id] = $invoicestoredrate;
			$payment->multicurrency_currency = ($invoicedcurrency ? $invoicedcurrency : $bankcurrency);
		} else {
			$payment->amounts[$invoice->id] = $amountofpayment;
		}

		// Do not exceed the remain to pay (main currency).
		if ($payment->amounts[$invoice->id] <= 0 || $payment->amounts[$invoice->id] > $remaintopay) {
			$payment->amounts[$invoice->id] = $remaintopay;
			if ($usemulticurrency) {
				$payment->multicurrency_amounts[$invoice->id] = price2num($remaintopay * $invoicestoredrate, 'MT');
			}
		}

		$payment->paiementid = $paiementid;
		$payment->paiementcode = $paycode;
		$payment->num_payment = '';

		$res = $payment->create($user); // This records the payment and regenerates the PDF
		if ($res < 0) {
			$error++;
			$errormsg = $payment->error;
		} else {
			if (!empty($payment->warnings)) {
				// silence warnings for AJAX
			}
			// Record the bank transaction only when a bank account is configured.
			if ($havebankaccount) {
				$res = $payment->addPaymentToBank($user, 'payment', '(CustomerInvoicePayment)', $bankaccount, '', '');
				if ($res < 0) {
					$error++;
					$errormsg = $payment->error;
				}
			}
		}
	}

	if (!$error) {
		// After payment: close the invoice if fully paid.
		$remaintopay = $invoice->getRemainToPay();
		if ($remaintopay == 0) {
			$invoice->setPaid($user);
			$invoice->paye = 1;
			$invoice->status = $invoice::STATUS_CLOSED;
			$invoice->close_code = '';
			$invoice->setPaymentMethods($paiementid);
		}
		$db->commit();
	} else {
		$invoice->ref = $invoice->oldcopy->ref;
		$invoice->paye = $invoice->oldcopy->paye;
		$invoice->status = $invoice->oldcopy->status;
		$invoice->statut = $invoice->oldcopy->statut;
		$db->rollback();
	}
}

if ($error) {
	print json_encode(array('error' => $errormsg ? $errormsg : $langs->trans('Error')));
} else {
	print json_encode(array('success' => true, 'remaintopay' => $remaintopay));
}
$db->close();
