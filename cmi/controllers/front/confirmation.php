<?php 

class CmiConfirmationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        if ((Tools::isSubmit('cart_id') == false) || (Tools::isSubmit('secure_key') == false)) {
            return false;
        }

        $cart_id = Tools::getValue('cart_id');
        $secure_key = Tools::getValue('secure_key');		
		$callbackMode = Configuration::get("callback_mode");
		$modeconfirmation = Configuration::get("confirmation_mode");

        $cart = new Cart((int)$cart_id);
        $customer = new Customer((int)$cart->id_customer);

        /**
         * If the order has been validated we try to retrieve it
         */
        $order_id = Order::getOrderByCartId((int)$cart->id);
		
        
        $module_id = $this->module->id;

        if ($order_id && ($secure_key == $customer->secure_key)) {
			if($callbackMode == "2"){
				$objOrder = new Order($order_id); 		
				$idstate = (int)Configuration::get('PS_OS_PAYMENT');
				$currentIdstate = @$objOrder->getCurrentState();
				if($idstate != $currentIdstate){					
					@$objOrder->setCurrentState($idstate);
					$payments = $objOrder->getOrderPaymentCollection();
					if ($payments->count() > 0) {
						$payments[0]->transaction_id = $_POST['TransId'];
						$payments[0]->card_number = substr_replace($_POST['MaskedPan'],'******',0,6);
						$payments[0]->card_brand = $_POST['EXTRA_CARDBRAND'];
						$payments[0]->update();
					}
				}
				if($modeconfirmation == "1"){
					$merchid = Configuration::get('merchid');
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
				
			}
            /**
             * The order has been placed so we redirect the customer on the confirmation page.
             */

            Tools::redirect('index.php?controller=order-confirmation&id_cart='.$cart_id.'&id_module=' .
                $module_id.'&id_order='.$order_id.'&key='.$secure_key.'&validated_by_ipn=1');
        } else {
            if (Tools::version_compare(_PS_VERSION_, '1.7', '>')) {
                return $this->setTemplate('module:cmi/views/templates/front/error_ps17.tpl');
            } else {
                return $this->context->controller->setTemplate('error.tpl');
            }
        }
    }
}
