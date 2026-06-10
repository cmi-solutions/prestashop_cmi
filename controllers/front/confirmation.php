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

        $order = Order::getByCartId((int)$cart->id);
        $orderId = Validate::isLoadedObject($order) ? (int)$order->id : 0;
        
        $module_id = $this->module->id;

        if ($orderId && ($secure_key == $customer->secure_key)) {
            if ($callbackMode == "2") {
                $objOrder = new Order($orderId); 		
                $idstate = (int)Configuration::get('PS_OS_PAYMENT');
                $currentIdstate = (int)$objOrder->getCurrentState();

                if ($idstate != $currentIdstate) {					
                    $objOrder->setCurrentState($idstate);
                    $payments = $objOrder->getOrderPaymentCollection();

                    if ($payments->count() > 0) {
                        $payments[0]->transaction_id = isset($_POST['TransId']) ? $_POST['TransId'] : '';
                        $payments[0]->card_number = isset($_POST['MaskedPan']) ? substr_replace($_POST['MaskedPan'], '******', 0, 6) : '';
                        $payments[0]->card_brand = isset($_POST['EXTRA_CARDBRAND']) ? $_POST['EXTRA_CARDBRAND'] : '';
                        $payments[0]->update();
                    }
                }

                if ($modeconfirmation == "1" && !empty($_POST["ReturnOid"])) {
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
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: text/xml"));
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                    curl_exec($ch);
                    curl_close($ch);
                }
            }

            Tools::redirect(
                'index.php?controller=order-confirmation&id_cart=' . $cart_id .
                '&id_module=' . $module_id .
                '&id_order=' . $orderId .
                '&key=' . $secure_key .
                '&validated_by_ipn=1'
            );
        } else {
            if (Tools::version_compare(_PS_VERSION_, '1.7', '>')) {
                return $this->setTemplate('module:cmi/views/templates/front/error_ps17.tpl');
            } else {
                return $this->context->controller->setTemplate('error.tpl');
            }
        }
    }
}