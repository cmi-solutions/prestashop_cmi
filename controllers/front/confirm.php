<?php

	class CmiConfirmModuleFrontController extends ModuleFrontController
{
    
    public function postProcess()
    {
		
$cartId = isset($_POST['oid']) ? (int)$_POST['oid'] : (int)$_POST['cartid'];
$cart = new Cart($cartId);
$cartAmount = $cart->getordertotal(false);	
$customer = new Customer((int)$cart->id_customer);
$currency = new CurrencyCore(CurrencyCore::getIdByIsoCode($_POST["currIsoCode"]));
$cart->id_currency = (int)$currency->id;
$cart->id_lang = (int)Configuration::get('PS_LANG_DEFAULT');
$cart->update();
$idStore = $_POST["clientid"];

$SLKSecretkey = Configuration::get("secretkey");
$modeconfirmation = Configuration::get("confirmation_mode");



$payment = $this->module;





$postParams = array();
			foreach ($_POST as $key => $value){
				array_push($postParams, $key);
			}

			natcasesort($postParams);

			$hashval = "";
			foreach ($postParams as $param){
				$paramValue = html_entity_decode(preg_replace("/\n$/","",$_POST[$param]), ENT_QUOTES, 'UTF-8'); 
				$escapedParamValue = str_replace("|", "\\|", str_replace("\\", "\\\\", $paramValue));

				$lowerParam = strtolower($param);
				if($lowerParam != "hash" && $lowerParam != "encoding" )	{
					$hashval = $hashval . $escapedParamValue . "|";
				}
			}

			$storeKey = $SLKSecretkey;
			$escapedStoreKey = str_replace("|", "\\|", str_replace("\\", "\\\\", $storeKey));
			$hashval = $hashval . $escapedStoreKey;

			$calculatedHashValue = hash('sha512', $hashval);
			$actualHash = base64_encode (pack('H*',$calculatedHashValue));

			$retrievedHash = $_POST["HASH"];
			if($retrievedHash == $actualHash)	{
				
				



    $currency_special = NULL;



    $dont_touch_amount = false;

    $paymentMethod = 'cmi';

    $message = "Paiement avec CMI";

    $extraVars = array();

	
	if(isset($_POST['amountCur']) && $_POST['amountCur']){
		$amount = $_POST['amountCur'];
	} else {
		$amount = $_POST['amount'];
	}

if($_POST["ProcReturnCode"] == "00")
{
	// $objOrder = new Order($_POST['oid']); 
	// $idstate = (int)Configuration::get('PS_OS_PAYMENT');
	// @$objOrder->setCurrentState($idstate);
	$cartId = (int)$_POST['oid']; // ici oid = cart id
    $cart = new Cart($cartId);

    if (!Validate::isLoadedObject($cart)) {
        echo "FAILURE";
        exit;
    }

    $customer = new Customer((int)$cart->id_customer);
    if (!Validate::isLoadedObject($customer)) {
        echo "FAILURE";
        exit;
    }

    // Vérifier si une commande existe déjà pour ce panier
    $order = Order::getByCartId((int)$cart->id);

    // Si aucune commande n'existe encore, on la crée maintenant
    if (!Validate::isLoadedObject($order)) {
        $currency_special = null;
        $dont_touch_amount = false;
        $paymentMethod = 'cmi';
        $message = 'Paiement avec CMI';

        if (isset($_POST['amountCur']) && $_POST['amountCur']) {
            $amount = (float)$_POST['amountCur'];
        } else {
            $amount = (float)$_POST['amount'];
        }

        // Créer la commande après succès CMI
        if (method_exists($payment, 'validateOrderMtc')) {
            $payment->validateOrderMtc(
                (int)$cart->id,
                (int)Configuration::get('PS_OS_PAYMENT'),
                (float)$amount,
                $paymentMethod,
                $message,
                array(),
                $currency_special,
                $dont_touch_amount,
                $customer->secure_key
            );
        } else {
            $payment->validateOrder(
                (int)$cart->id,
                (int)Configuration::get('PS_OS_PAYMENT'),
                (float)$amount,
                $paymentMethod,
                $message,
                array(),
                null,
                false,
                $customer->secure_key
            );
        }

        $order = Order::getByCartId((int)$cart->id);
    }

    if (!Validate::isLoadedObject($order)) {
        echo "FAILURE";
        exit;
    }

    $idstate = (int)Configuration::get('PS_OS_PAYMENT');

    if ((int)$order->current_state !== $idstate) {
        $order->setCurrentState($idstate);
    }

    $payments = $order->getOrderPaymentCollection();
    if ($payments->count() > 0) {
        $payments[0]->transaction_id = isset($_POST['TransId']) ? pSQL($_POST['TransId']) : '';
        $payments[0]->card_number = isset($_POST['MaskedPan']) ? substr_replace($_POST['MaskedPan'], '******', 0, 6) : '';
        $payments[0]->card_brand = isset($_POST['EXTRA_CARDBRAND']) ? pSQL($_POST['EXTRA_CARDBRAND']) : '';
        $payments[0]->update();
    }
$callback_mode = Configuration::get("callback_mode");

if($callback_mode == "0" || $callback_mode== "2"){
				if($modeconfirmation == "1"){
					echo "ACTION=POSTAUTH"; 
				} else {
					echo "APPROVED";
				}
			} else if($callback_mode == "1"){
				if($modeconfirmation == "1"){
$merchid = $_POST["clientid"];
$usernameapi = Configuration::get("usernameapi");
$passwordapi = Configuration::get("passwordapi");
$apigateway = Configuration::get("actionslkapi");
				$xml = '<?xml version="1.0" encoding="UTF-8"?>
<CC5Request>
	<Name>'.$usernameapi.'</Name>
	<Password>'.$passwordapi.'</Password>
	<ClientId>'.$merchid.'</ClientId>
	<Type>PostAuth</Type>     
	<OrderId>'.$_POST["ReturnOid"].'</OrderId> 
</CC5Request>';

			$url = $apigateway;
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);

			// For xml, change the content-type.
			curl_setopt ($ch, CURLOPT_HTTPHEADER, Array("Content-Type: text/xml"));

			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);

			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // ask for results to be returned
			
			curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);

			// Send to remote and return data to caller.
			curl_exec($ch);
			curl_close($ch);
			}
			Tools::redirect($this->context->link->getModuleLink('cmi','confirmation',array('cart_id' => $cart->id, 'secure_key' => $customer->secure_key, 'amount' => floatval($amount))));
			}


}
else
{
    $cartId = (int)$_POST['oid'];
    $order = Order::getByCartId($cartId);

    if (Validate::isLoadedObject($order)) {
        $history = new OrderHistory();
        $history->id_order = (int)$order->id;
        $history->changeIdOrderState((int)_PS_OS_ERROR_, (int)$order->id);
    }

    echo "FAILURE";
    exit;
}


} else {

    $currency_special = null;

	$order = new Order();

    $dont_touch_amount = false;

    $paymentMethod = 'cmi';

    $message = null;

    $extraVars = array();
	$order = Order::getByCartId((int)$_POST['oid']);

	if (Validate::isLoadedObject($order)) {
		$history = new OrderHistory();
		$history->id_order = (int)$order->id;
		$history->changeIdOrderState((int)_PS_OS_ERROR_, (int)$order->id);
	}

	echo "FAILURE";

}
exit;





}
}
