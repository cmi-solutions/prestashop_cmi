<?php
class CmiValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
		
		$data = $_POST;	
		$payment = $this->module;
        $clientid = Tools::getValue('clientid');
		$cart = $this->context->cart;
		$amount = $this->context->cart->getOrderTotal(true, Cart::BOTH);
		$totalAmountTx = floatval($amount);
		$OrderTotal = $totalAmountTx;
		$currency_order = new Currency(intval($this->context->cart->id_currency));
		$currency_mad = new CurrencyCore(CurrencyCore::getIdByIsoCode("MAD"));
		$currency_store = Configuration::get('currency_store');
			$acurrency_store =  explode('|', $currency_store);
			if(in_array("504", $acurrency_store) && count($acurrency_store) == 1){
				$data['clientid'] = Configuration::get('merchid');
				$data['currency'] = "504";
				if ($currency_order->id != $currency_mad->id){
					$this->context->cart->id_currency = (int)$currency_order->id;
					$this->context->cart->update();
					
					$context = $this->context;
					
					$symbolCurrency = $currency_order->iso_code;
					$totalAmountTxCurrency = $totalAmountTx;
					$totalAmountTx = floatval(($this->context->cart->getOrderTotal(true, Cart::BOTH)*$currency_mad->conversion_rate) / $currency_order->conversion_rate) ;
					$data['amountCur'] = $OrderTotal;
					$data['symbolCur'] = $currency_order->iso_code;
				}
			} else if(!in_array("504", $acurrency_store) && count($acurrency_store) > 0){
				$data['clientid'] = Configuration::get('merchid_offshore');
				$data['currency'] = $currency_order->iso_code_num;
			} else if(in_array("504", $acurrency_store) && count($acurrency_store) > 1){
				if ($currency_order->id == $currency_mad->id){
					$data['clientid'] = Configuration::get('merchid');
				} else {
					$data['clientid'] = Configuration::get('merchid_offshore');
				}
				$data['currency'] = $currency_order->iso_code_num;
			}
			$data['amount'] = number_format($totalAmountTx, 2, '.', '');
	
		
		
		 $id_customer = (int)$cart->id_customer;
		$secure_key = "";
		if ($id_customer) {
			$customer = new Customer($id_customer);
			if (Validate::isLoadedObject($customer)) {
				$secure_key = $customer->secure_key;
			}
		}
		$currency_special = NULL;


		$dont_touch_amount = false;

		$paymentMethod = 'cmi';

		$message = "Paiement avec CMI";

		$extraVars = array();
		if(isset($data['amountCur']) && $data['amountCur']){
		$amount = $data['amountCur'];
	} else {
		$amount = $data['amount'];
	}

		@$payment->validateOrderMtc((int)$cart ->id, Configuration::get('BPI_ID_ORDERSTATE'), floatval($amount), $paymentMethod, $message, $extraVars, $currency_special, $dont_touch_amount, $customer->secure_key);
		$order_id = Order::getOrderByCartId((int)($cart->id));
		$order = new Order($order_id);
		$actionslk = Configuration::get('actionslk');
		
		$cmi_args_array = array();
		$data['oid'] = $order_id;
		$postParams = array();
			foreach ($data as $key => $value){
				array_push($postParams	, $key);
			}

			natcasesort($postParams);

			$hashval = "";
			foreach ($postParams as $param){
				$paramValue = $data[$param];
				$escapedParamValue = str_replace("|", "\\|", str_replace("\\", "\\\\", $paramValue));

				$lowerParam = strtolower($param);
				if($lowerParam != "hash" && $lowerParam != "encoding" )	{
					$hashval = $hashval . $escapedParamValue . "|";
				}
			}

			$storeKey = Configuration::get('secretkey');
			$escapedStoreKey = str_replace("|", "\\|", str_replace("\\", "\\\\", $storeKey));
			$hashval = $hashval . $escapedStoreKey;

			$calculatedHashValue = hash('sha512', $hashval);
			$hash = base64_encode (pack('H*',$calculatedHashValue));
			
			$data['HASH'] = $hash;	
		$this->context->smarty->assign('data', $data);
		$this->context->smarty->assign('actionslk', $actionslk);
		return $this->setTemplate('module:cmi/views/templates/front/payment.tpl');
    }
}
