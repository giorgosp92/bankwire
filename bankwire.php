<?php
/*
* 2007-2014 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2014 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_'))
	exit;

class BankWire extends PaymentModule
{
	private $_html = '';
	private $_postErrors = array();

	public $extra_mail_vars;
	public function __construct()
	{
		$this->name = 'bankwire';
		$this->tab = 'payments_gateways';
		$this->version = '0.7.1';
		$this->author = 'PrestaShop';
		$this->controllers = array('payment', 'validation');
		
		$this->currencies = true;
		$this->currencies_mode = 'checkbox';

		$this->bootstrap = true;
		parent::__construct();	

		$this->displayName = $this->l('Bank wire');
		$this->description = $this->l('Accept payments for your products via bank wire transfer.');
		$this->confirmUninstall = $this->l('Are you sure about removing these details?');
		if (!isset($this->owner) || !isset($this->details) || !isset($this->address))
			$this->warning = $this->l('Account owner and account details must be configured before using this module.');
		if (!count(Currency::checkPaymentCurrencies($this->id)))
			$this->warning = $this->l('No currency has been set for this module.');

		$this->extra_mail_vars = $this->getBankAccounts();
	}

	public function install()
	{
		if (!parent::install() || !$this->registerHook('payment') || !$this->registerHook('paymentReturn'))
			return false;
		return Db::getInstance()->execute('
		CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'bankwire` (
			`id` int(6) NOT NULL AUTO_INCREMENT,
			`id_shop` INTEGER UNSIGNED NOT NULL DEFAULT \'1\',
			`id_shop_group` INTEGER UNSIGNED NOT NULL DEFAULT \'1\',
			`owner` varchar(255) NOT NULL,
			`details` text NOT NULL,
			`address` text NOT NULL,
			PRIMARY KEY(`id`)
		) ENGINE='._MYSQL_ENGINE_.' default CHARSET=utf8');
	}

	public function uninstall()
	{
		Db::getInstance()->execute('DROP TABLE '._DB_PREFIX_.'bankwire');
		return parent::uninstall();
	}

	private function _postValidation()
	{
		if (Tools::isSubmit('btnSubmit'))
		{
			if (!Tools::getValue('BANK_WIRE_DETAILS'))
				$this->_postErrors[] = $this->l('Account details are required.');
			elseif (!Tools::getValue('BANK_WIRE_OWNER'))
				$this->_postErrors[] = $this->l('Account owner is required.');
		}
	}

	private function _postProcess()
	{
		if (Tools::isSubmit('btnSubmit')) {
			Db::getInstance()->execute('INSERT INTO '._DB_PREFIX_.'bankwire (id_shop, id_shop_group, owner, details, address)
					VALUES
					('.$this->context->shop->id.',
					'.$this->context->shop->id_shop_group.',\''
					.Tools::getValue('BANK_WIRE_OWNER').'\',\''
					.Tools::getValue('BANK_WIRE_DETAILS').'\',\''
					.Tools::getValue('BANK_WIRE_ADDRESS').'\'
					)') or die(Db::getInstance()->getMsgError());
		}

		$this->_html .= $this->displayConfirmation($this->l('New Account added successfully'));
	}

	private function _displayBankWire()
	{
		return $this->display(__FILE__, 'infos.tpl');
	}

	public function getContent()
	{
		if (Tools::isSubmit('btnSubmit'))
		{
			$this->_postValidation();
			if (!count($this->_postErrors))
				$this->_postProcess();
			else
				foreach ($this->_postErrors as $err)
					$this->_html .= $this->displayError($err);
		}
		elseif (Tools::isSubmit('deletebankwire'))
		{
			//action button of helper list "delete" was pressed
			$this->deleterow(Tools::getValue('id'));
		}
		else
			$this->_html .= '<br />';

		$this->_html .= $this->_displayBankWire();
		$this->_html .= $this->renderForm();
		$this->_html .= $this->renderList();

		return $this->_html;
	}

	public function hookPayment($params)
	{
		if (!$this->active)
			return;
		if (!$this->checkCurrency($params['cart']))
			return;


		$this->smarty->assign(array(
			'this_path' => $this->_path,
			'this_path_bw' => $this->_path,
			'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->name.'/'
		));
		return $this->display(__FILE__, 'payment.tpl');
	}

	public function hookPaymentReturn($params)
	{
		if (!$this->active)
			return;

		$state = $params['objOrder']->getCurrentState();
		if ($state == Configuration::get('PS_OS_BANKWIRE') || $state == Configuration::get('PS_OS_OUTOFSTOCK'))
		{
			$this->smarty->assign(array(
				'total_to_pay' => Tools::displayPrice($params['total_to_pay'], $params['currencyObj'], false),
				'bankAccounts' => $this->getBankAccounts(),
				'status' => 'ok',
				'id_order' => $params['objOrder']->id
			));
			if (isset($params['objOrder']->reference) && !empty($params['objOrder']->reference))
				$this->smarty->assign('reference', $params['objOrder']->reference);
		}
		else
			$this->smarty->assign('status', 'failed');
		return $this->display(__FILE__, 'payment_return.tpl');
	}
	
	public function checkCurrency($cart)
	{
		$currency_order = new Currency($cart->id_currency);
		$currencies_module = $this->getCurrency($cart->id_currency);

		if (is_array($currencies_module))
			foreach ($currencies_module as $currency_module)
				if ($currency_order->id == $currency_module['id_currency'])
					return true;
		return false;
	}
	
	public function renderForm()
	{
		$fields_form = array(
			'form' => array(
				'legend' => array(
					'title' => $this->l('Contact details'),
					'icon' => 'icon-envelope'
				),
				'input' => array(
					array(
						'type' => 'text',
						'label' => $this->l('Account owner'),
						'name' => 'BANK_WIRE_OWNER',
					),
					array(
						'type' => 'textarea',
						'label' => $this->l('Details'),
						'name' => 'BANK_WIRE_DETAILS',
						'desc' => $this->l('Such as bank branch, IBAN number, BIC, etc.')
					),
					array(
						'type' => 'textarea',
						'label' => $this->l('Bank address'),
						'name' => 'BANK_WIRE_ADDRESS',
					),
				),
				'submit' => array(
					'title' => $this->l('Add New Account'),
				)
			),
		);
		
		$helper = new HelperForm();
		$helper->show_toolbar = false;
		//$helper->table =  $this->table;
		$lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
		$helper->default_form_language = $lang->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
		$this->fields_form = array();
		$helper->id = (int)Tools::getValue('id_carrier');
		$helper->identifier = $this->identifier;
		$helper->submit_action = 'btnSubmit';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->tpl_vars = array(
			'languages' => $this->context->controller->getLanguages(),
			'id_language' => $this->context->language->id
		);

		return $helper->generateForm(array($fields_form));
	}
	

	public function renderList() {
		$records_table = array(
					'id' => array(
						'title' => $this->l('Id'),
						'width' => 140,
	            		'type' => 'text',
						),
					'owner' => array(
						'title' => $this->l('Account Owner'),
						'width' => 140,
	            		'type' => 'text',
						),
					'details' => array(
						'title' => $this->l('Details'),
						'width' => 140,
	            		'type' => 'text',
						),
					'address' => array(
						'title' => $this->l('Bank address'),
						'width' => 140,
	            		'type' => 'text',
						)
			);

		$helper = new HelperList();
		$helper->shopLinkType = '';
		$helper->simple_header = true;
		$helper->_select = $this->getBankAccounts();
	    $helper->actions = array('delete');
	    $helper->identifier = 'id';
	    $helper->show_toolbar = true;
	    $helper->_defaultOrderBy = 'id';
	    $helper->title = $this->l('Existing Accounts');

	    $helper->token = Tools::getAdminTokenLite('AdminModules');
    	$helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
    	$helper->table = "bankwire";
	    return $helper->generateList($helper->_select, $records_table);
	}

	public function getBankAccounts() {
		$sql = 'SELECT *
		FROM '._DB_PREFIX_.'bankwire';
		return Db::getInstance()->executeS($sql);
	}

	public function deleterow($id) {
		if ($id != null)
		{
			Db::getInstance()->execute('DELETE FROM '._DB_PREFIX_.'bankwire WHERE `id` = '.$id);
			$this->_html .= $this->displayConfirmation($this->l('Bank Account was deleted successfully'));
		}	
	}
}
