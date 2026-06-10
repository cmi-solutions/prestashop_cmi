<?php
	class Cmi extends PaymentModule
{
	private	$_html = '';
	private $_postErrors = array();
	private $_postWarnings = array();

	public function __construct()
	{
		$this->name = 'cmi';
		$this->version = '1.1.0';
		$this->tab = 'payments_gateways';
		$this->need_instance = 1;
		$this->controllers = array('payment', 'validation');
		$this->bootstrap = true;
		$this->module_key = '3a6587e8877a665f590245c45874f65';
		
		parent::__construct();

		$this->author = 'CMI';
		
		$this->displayName = $this->l('CMI');
		$this->description = $this->l('Paiements par carte bancaire avec le CMI');

		$this->confirmUninstall = $this->l('Êtes-vous sur de vouloir désinstaller?');

	}

	/*
	 *
	 * INSTALL / UNINSTALL
	 *
	*/
	public function install()
	{
		Db::getInstance()->execute('
			CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'bpi_secuaccess` (
			`id_user` varchar(20) NOT NULL, `date_creation` datetime) ENGINE=MyISAM  DEFAULT CHARSET=utf8');
			
		if (!Configuration::get('BPI_TYPE_DISPLAY')) {
			Configuration::updateValue('BPI_TYPE_DISPLAY', 1);
		}
		
		if (!parent::install() OR !$this->registerHook('paymentOptions') OR !$this->registerHook('paymentReturn') OR !$this->registerHook('adminOrder') OR !$this->registerHook('rightColumn')) {
			return false;
		}
		
		
		$orderState = new OrderState();
		$langs = Language::getLanguages();
		foreach ($langs AS $lang)
			$orderState->name[$lang['id_lang']] = 'Waiting for CMI validation';
		$orderState->name[2] = 'Attente de validation CMI';
		$orderState->invoice = true;
		$orderState->send_email = false;
		$orderState->logable = true;
		$orderState->color = '#3333FF';
		$orderState->save();
		Configuration::updateValue('BPI_ID_ORDERSTATE', intval($orderState->id));
		
	
		return true;
	}

	public function uninstall() {
		
		Configuration::deleteByName('merchid');
		Configuration::deleteByName('merchid_offshore');
		Configuration::deleteByName('actionslk');
		Configuration::deleteByName('secretkey');
		Configuration::deleteByName('confirmation_mode');
		Configuration::deleteByName('redirection_mode');
		Configuration::deleteByName('cardholder_authentication');
		Configuration::deleteByName('callback_mode');
		Configuration::deleteByName('actionslkapi');
		Configuration::deleteByName('usernameapi');
		Configuration::deleteByName('passwordapi');
		Configuration::deleteByName('currency_store');
		
		Db::getInstance()->Execute('DROP TABLE IF EXISTS '._DB_PREFIX_.'bpi_secuaccess');
		return parent::uninstall();
	}
	

	
	/*
	 *
	 * HOOKS
	 *
	*/
	public function hookRightColumn($params) {
		
		global $cookie;		
		$context = $this->context;
		
		
		$context->smarty->assign('path', 'modules/'.$this->name);
		return $this->display(__FILE__, 'views/templates/front/logo.tpl');
	}
	
	public function hookLeftColumn($params) {
		
		return $this->hookRightColumn($params);
	}
	
	public function getContent() {
		
		global $cookie;
		$messages = '';
		$ht = '';
		if (isset($_POST['submitCmi_config'])) {
			if (!sizeof($this->_postErrors)) {
				if (isset($_POST['currency_store']) && $_POST['currency_store']){
				$currency_store = implode('|', $_POST['currency_store']);
				}
					
				Configuration::updateValue('merchid', trim($_POST['merchid']));
				Configuration::updateValue('merchid_offshore', trim($_POST['merchid_offshore']));
				Configuration::updateValue('actionslk', trim($_POST['actionslk']));
				Configuration::updateValue('secretkey', trim($_POST['secretkey']));
				Configuration::updateValue('currency_store', $currency_store);
				Configuration::updateValue('confirmation_mode', $_POST['confirmation_mode']);
				Configuration::updateValue('redirection_mode', $_POST['redirection_mode']);
				Configuration::updateValue('cardholder_authentication', $_POST['cardholder_authentication']);
				Configuration::updateValue('callback_mode', $_POST['callback_mode']);
				Configuration::updateValue('actionslkapi', $_POST['actionslkapi']);
				Configuration::updateValue('usernameapi', $_POST['usernameapi']);
				Configuration::updateValue('passwordapi', $_POST['passwordapi']);
				
		$messages = $this->l('Settings have been saved.');
			}
			else
			$ht = $this->l('Please fill the required fields');
			

		}
		
		$smarty = false;
		$context = $this->context;
				
		
		
		$base_url = __PS_BASE_URI__;
		$bp_img = '//'.htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8').$base_url.'modules/cmi/';
		
		
		$merchid = Configuration::get('merchid');
		$merchid_offshore = Configuration::get('merchid_offshore');
		$actionslk = Configuration::get('actionslk');	
		$secretkey = Configuration::get('secretkey');
		$confirmation_mode = Configuration::get('confirmation_mode');
		$cardholder_authentication = Configuration::get('cardholder_authentication');
		$currency_store = Configuration::get('currency_store');
		$callback_mode = Configuration::get('callback_mode');
		$actionslkapi = Configuration::get('actionslkapi');
		$usernameapi = Configuration::get('usernameapi');
		$passwordapi = Configuration::get('passwordapi');
		$postErrors = $this->_postErrors;
		$context->smarty->assign(array(
			'legend' => $this->l('Configurer votre Module CMI'),
			'merchid' => $merchid,
			'merchid_offshore' => $merchid_offshore,
			'cmi_form' => $_SERVER['REQUEST_URI'],
			'actionslk' => $actionslk,
			'secretkey' => $secretkey,
			'callback_mode' => $callback_mode,
			'actionslkapi' => $actionslkapi,
			'usernameapi' => $usernameapi,
			'passwordapi' => $passwordapi,
			'confirmation_mode' => $confirmation_mode,
			'redirection_mode' => $redirection_mode,
			'cardholder_authentication' => $cardholder_authentication,
			'postErrors' => $postErrors,
			'successMsg' => $messages,
			'bp_img' => $bp_img
			));
			$currencies_store =  explode('|', $currency_store);
			$new_currencies_store =  array();
			foreach ($currencies_store as $currency_store){
				$new_currencies_store[$currency_store] = $currency_store;
			}
		$context->smarty->assign(array(
			'currencies_store' => $new_currencies_store
			));
		
		$context->controller->addCSS($this->_path.'views/css/cmi.css', 'all');
		$context->controller->addCSS($this->_path.'views/css/font-awesome.min.css', 'all');
		return $this->display(__FILE__, 'views/templates/admin/admin.tpl');
	}

	
	
 public function validateOrderMtc($id_cart, $id_order_state, $amountPaid, $paymentMethod = 'Unknown', $message = NULL, $extraVars = array(), $currency_special = NULL, $dont_touch_amount = false, $secure_key = false)

	{

		if (!$this->active)

			return ;



		$this->context->country->active = true;
		@$this->validateOrder($id_cart, $id_order_state, $amountPaid, $paymentMethod, $message, $extraVars, (int)$currency_special, false, $secure_key);	


	}	
	
 
	public function hookPaymentOptions($params)
	{
		
		$options = array();
		
		$amount = $this->context->cart->getOrderTotal(true, Cart::BOTH);
		$totalAmountTx = floatval($amount);
		$OrderTotal = $totalAmountTx;
				
		
		$currency_order = new Currency(intval($this->context->cart->id_currency));
		$currency_mad = new CurrencyCore(CurrencyCore::getIdByIsoCode("MAD"));

		if(empty($currency_mad) || $currency_mad->active != 1)
			return ;

		// check currency of payment with default MAD currency for the module
		$symbolCurrency = "";
		$totalAmountTxCurrency = "";
		
		$redirection_mode = Configuration::get('redirection_mode');
		
		$order = new Order();		
		$cardholder_authentication = trim(Configuration::get('cardholder_authentication'));
		if($cardholder_authentication == "1"){
			$storetype = "3DPAYHOSTING";
		} else {
			$storetype = "PAYHOSTING";
		}
		
					$url_retour_ok = $this->context->link->getPageLink('order-confirmation', true, null, 'id_cart=' . (int)$this->context->cart->id . '&id_module=' . $this->id . '&key=' . $this->context->customer->secure_key);
        $url_retour_ko = $this->context->link->getPageLink('order', true, null, 'step=3');
		
		
			if (strpos($url_retour_ok, 'https://') !== false || strpos($url_retour_ko, 'https://') !== false) {
			$set_secure_return = true;
			$set_secure_conf = true;
		} else {
			$set_secure_return = false;
			$set_secure_conf = false;
		}
		 $urlConfirmation = $this->getBackUrl($this->context->cart);
		
		
			$query = 'SELECT * FROM `' . _DB_PREFIX_ . 'address` WHERE `id_customer`= "' . $params['cookie']->id_customer . '"';
            $guest_user_detail = Db::getInstance()->ExecuteS($query);
			$billing_detail = new Address((int) ($params['cart']->id_address_invoice));
			$city = !empty($guest_user_detail[0]['city']) ? $guest_user_detail[0]['city'] : '';
            $country = !empty($guest_country[0]['name']) ? $guest_country[0]['name'] : '';
            $postcode = !empty($guest_user_detail[0]['postcode']) ? $guest_user_detail[0]['postcode'] : '';
			$billing_city = !empty($billing_detail->city) ? $billing_detail->city : '';
            $billing_country = !empty($billing_detail->country) ? $billing_detail->country : '';
            $billing_postcode = !empty($billing_detail->postcode) ? $billing_detail->postcode : '';
			$address =  $guest_user_detail[0]['address1'] . ' ' . $guest_user_detail[0]['address2'];
			 $phone_mobile = !empty($guest_user_detail[0]['phone']) ? $guest_user_detail[0]['phone'] : '';
			
			
			$conf_uri = $this->context->link->getModuleLink($this->name, 'confirm');
			
			$currency_special = NULL;
			$dont_touch_amount = false;
			$paymentMethod = 'cmi';
			$message = "Paiement avec CMI";
			$extraVars = array();


			$config = require _PS_CACHE_DIR_ . 'appParameters.php';
			$dbName = $config['parameters']["database_name"];
			
			$base_url = __PS_BASE_URI__;
			$shopUrl = $this->context->shop->getBaseURL(true);;
		$data = array(
			'rnd' => microtime(),
			'storetype' => $storetype,
			'hashAlgorithm' => "ver3",
			'TranType' => "PreAuth",
			'email' => trim($this->context->customer->email),
			'BillToName' => $this->str_without_accents(trim($this->context->customer->firstname .' '.$this->context->customer->lastname)),
			'BillToStreet1' => $this->str_without_accents(trim($address)),
			'BillToCity' => $this->str_without_accents(trim($city)),
			'BillToStateProv' => "",
			'BillToCountry' => $this->str_without_accents(trim($country)),
			'BillToPostalCode' =>  $this->str_without_accents(trim($postcode)),
			'tel' =>  $this->str_without_accents(trim($phone_mobile)),
			'cartid' => $this->context->cart->id,
			'refreshtime' => "5",
			'currIsoCode' => $currency_order->iso_code,
			'shopUrl' => $shopUrl,
			'failUrl' => $shopUrl,
			'set_secure_return' => ($set_secure_return ? 'true' : 'false'),
			'encoding' => "UTF-8"
			);		
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
			$callback_mode = Configuration::get("callback_mode");
			if($callback_mode == "1"){ 
				$data['AutoRedirect'] = "true";
				$data['okUrl'] = $conf_uri; 
				$data['CallbackResponse'] = "false";
				$data['callbackUrl'] = ""; 
			} else {
				if($redirection_mode == "1"){
					$data['AutoRedirect'] = "true";
				} else {
					$data['AutoRedirect'] = "false";
				}
				$data['okUrl'] = $urlConfirmation;
				$data['CallbackResponse'] = "true";
				$data['callbackUrl'] = $conf_uri;
			}
			$lang = $this->context->language->iso_code;
		switch ($lang) {
			case "en":
			case "fr":
			case "ar":
				 $data['lang'] = $lang;
			break;
			default:
				 $data['lang'] = "en";
		}
			$datas = array(
            'action' => Configuration::get('actionslk'),
            'inputs' => [
                'clientid' => array(
                    'name' => 'clientid',
                    'type' => 'hidden',
                    'value' => $data['clientid']
                ),
				 'lang' => array(
                    'name' => 'lang',
                    'type' => 'hidden',
                    'value' => $data['lang'],
                ),
				'rnd' => array(
                    'name' => 'rnd',
                    'type' => 'hidden',
                    'value' => $data['rnd'],
                ),
				'storetype' => array(
                    'name' => 'storetype',
                    'type' => 'hidden',
                    'value' => $data['storetype'],
                ),
				'hashAlgorithm' => array(
                    'name' => 'hashAlgorithm',
                    'type' => 'hidden',
                    'value' => $data['hashAlgorithm'],
                ),
				'TranType' => array(
                    'name' => 'TranType',
                    'type' => 'hidden',
                    'value' => $data['TranType'],
                ),
				'email' => array(
                    'name' => 'email',
                    'type' => 'hidden',
                    'value' => $data['email'],
                ),
				'BillToName' => array(
                    'name' => 'BillToName',
                    'type' => 'hidden',
                    'value' => $data['BillToName'],
                ),
				'BillToStreet1' => array(
                    'name' => 'BillToStreet1',
                    'type' => 'hidden',
                    'value' => $data['BillToStreet1'],
                ),
				'BillToCity' => array(
                    'name' => 'BillToCity',
                    'type' => 'hidden',
                    'value' => $data['BillToCity'],
                ),
				'BillToStateProv' => array(
                    'name' => 'BillToStateProv',
                    'type' => 'hidden',
                    'value' => $data['BillToStateProv'],
                ),
				'BillToCountry' => array(
                    'name' => 'BillToCountry',
                    'type' => 'hidden',
                    'value' => $data['BillToCountry'],
                ),
				'BillToPostalCode' => array(
                    'name' => 'BillToPostalCode',
                    'type' => 'hidden',
                    'value' => $data['BillToPostalCode'],
                ),
				'tel' => array(
                    'name' => 'tel',
                    'type' => 'hidden',
                    'value' => $data['tel'],
                ),
				'cartid' => array(
                    'name' => 'cartid',
                    'type' => 'hidden',
                    'value' => $data['cartid'],
                ),
				'refreshtime' => array(
                    'name' => 'refreshtime',
                    'type' => 'hidden',
                    'value' => $data['refreshtime'],
                ),
				'amount' => array(
                    'name' => 'amount',
                    'type' => 'hidden',
                    'value' => $data['amount'],
                ),
				'currency' => array(
                    'name' => 'currency',
                    'type' => 'hidden',
                    'value' => $data['currency'],
                ),
				'failUrl' => array(
                    'name' => 'failUrl',
                    'type' => 'hidden',
                    'value' => $data['failUrl'],
                ),
				'shopUrl' => array(
                    'name' => 'shopUrl',
                    'type' => 'hidden',
                    'value' => $data['shopUrl'],
                ),
				'okurl' => array(
                    'name' => 'okUrl',
                    'type' => 'hidden',
                    'value' => $data['okUrl'],
                ),
				'callbackUrl' => array(
                    'name' => 'callbackUrl',
                    'type' => 'hidden',
                    'value' => $data['callbackUrl'],
                ),
				'encoding' => array(
                    'name' => 'encoding',
                    'type' => 'hidden',
                    'value' => $data['encoding'],
                ),
				'CallbackResponse' => array(
                    'name' => 'CallbackResponse',
                    'type' => 'hidden',
                    'value' => $data['CallbackResponse'],
                ),
				'AutoRedirect' => array(
                    'name' => 'AutoRedirect',
                    'type' => 'hidden',
                    'value' => $data['AutoRedirect'],
                ),
				'currIsoCode' => array(
                    'name' => 'currIsoCode',
                    'type' => 'hidden',
                    'value' => $data['currIsoCode'],
                ),
				'set_secure_return' => array(
                	'name' => 'set_secure_return',
                	'type' => 'hidden',
                	'value' => ($set_secure_return ? 'true' : 'false')
                )
				]
			);
			if($currency_store == "504"){
				if ($currency_order->id != $currency_mad->id){				
					$datas['inputs']['amountCur'] = array(
							'name' => 'amountCur',
							'type' => 'hidden',
							'value' => $data['amountCur']	
					);			
					$datas['inputs']['symbolCur'] = array(
							'name' => 'symbolCur',
							'type' => 'hidden',
							'value' => $data['symbolCur']	
					);							
				}
			}
			$cart = $this->context->cart;
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

			
        $option = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $option->setCallToActionText($this->l('CMI, Payer en toute sécurité avec votre carte bancaire.'))
            ->setLogo(_MODULE_DIR_.'cmi/views/img/cb.png')
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', array(), true))
            ->setInputs($datas['inputs']);
        $options[] = $option;
        

        
        return $options;
	} 
	
	
	
	public function postProcess()
    {
		echo'test';exit;
	}

	
	public function hookPaymentReturn() {
		
		$smarty = false;
		$context = $this->context;	
		
		if (@$params['objOrder']->module != $this->name)
			return;
		
		if (Tools::getValue('error'))
			$context->smarty->assign('status', 'failed');
		else
			$context->smarty->assign('status', 'ok');
		return $this->display(__FILE__, 'payment_return.tpl');
	}
	
	
    /**
	 *
	 * CMI FUNCTIONS
	 *
	 */	
	public function displayWarnings() {
		
		$nbWarnings = sizeof($this->_postWarning);
		$this->_html .= '
		<div class="warn">
			<h3>'.($nbWarnings > 1 ? $this->l('There are') : $this->l('There is')).' '.$nbWarnings.' '.($nbWarnings > 1 ? $this->l('warnings') : $this->l('warning')).'</h3>
			<ul>';
		foreach ($this->_postWarning AS $warning)
			$this->_html .= '<li>'.$warning.'</li>';
		$this->_html .= '
			</ul>
		</div>';
	}
  	





	public function isSecure(&$fp_POU_requestProtocol)
	{
		$isSecure = false;
		$HTTPS = getenv('HTTPS');
		$HTTP_X_FORWARDED_PROTO = getenv('HTTP_X_FORWARDED_PROTO');
		$HTTP_X_FORWARDED_SSL = getenv('HTTP_X_FORWARDED_SSL');
		if (getenv('HTTPS') == 'on') {
			$isSecure = true;
		}
		elseif (!empty($HTTP_X_FORWARDED_PROTO) && $HTTP_X_FORWARDED_PROTO == 'https' || !empty($HTTP_X_FORWARDED_SSL) && $HTTP_X_FORWARDED_SSL == 'on') {
			$isSecure = true;
		}
		$fp_POU_requestProtocol = $isSecure ? 'https' : 'http';
		return $isSecure;
	}
	
	public function str_without_accents($str, $charset='utf-8')
	{
		$str = htmlentities($str, ENT_NOQUOTES, $charset);
		$str = preg_replace('#&([A-za-z])(?:acute|cedil|caron|circ|grave|orn|ring|slash|th|tilde|uml);#', '\1', $str);
		$str = preg_replace('#&([A-za-z]{2})(?:lig);#', '\1', $str);
		$str = preg_replace('#&[^;]+;#', '', $str); 
		$str = preg_replace('/[^a-zA-Z0-9_ -]/s','',$str);
		$str = preg_replace('!\s+!', ' ', $str);
		return $str;   
	}
	 public function hookDisplayPaymentReturn($params)
    {
        if (Tools::version_compare(_PS_VERSION_, '1.7', '>')) {
            return false;
        }
        if ($this->active == false) {
            return;
        }
        $order = $params['objOrder'];

        if ($order->getCurrentOrderState()->id != Configuration::get('PS_OS_ERROR')) {
            $this->smarty->assign('status', 'ok');
        }
        $this->smarty->assign(array(
            'id_order' => $order->id,
            'reference' => $order->reference,
            'params' => $params,
            'total' => Tools::displayPrice($params['total_to_pay'], $params['currencyObj'], false),
        ));

        return $this->display(__FILE__, 'views/templates/hook/confirmation.tpl');
    }
    
	public function getBackUrl($cart)
    {
        $id_customer = (int)$cart->id_customer;
        $secure_key = "";
        if ($id_customer) {
            $customer = new Customer($id_customer);
            if (Validate::isLoadedObject($customer)) {
                $secure_key = $customer->secure_key;
            }
        }
        return $this->context->link->getModuleLink(
            'cmi',
            'confirmation',
            array('cart_id' => $cart->id, 'secure_key' => $secure_key)
        );
    }
	
	
}