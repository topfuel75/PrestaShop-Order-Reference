<?php
/**
*   Orderreference
*
*   Do not copy, modify or distribute this document in any form.
*
*   @author     Matthijs <matthijs@blauwfruit.nl>
*   @copyright  Copyright (c) 2013-2020 blauwfruit (http://blauwfruit.nl)
*   @license    Proprietary Software
*
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

class Orderreference extends Module
{
    protected $config_form = false;
    
    public $_html;

    public function __construct()
    {
        $this->name = 'orderreference';
        $this->tab = 'billing_invoicing';
        $this->version = '1.1.3';
        $this->author = 'blauwfruit';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Order Reference');
        $this->description = $this->l('Changes the order reference of any order upon validation of the order.');

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        Configuration::updateValue('ORDERREFERENCE_LIVE_MODE', false);

        return parent::install() &&
            $this->registerHook('actionObjectOrderUpdateAfter') &&
            $this->registerHook('actionPaymentConfirmation') &&
            $this->registerHook('actionValidateOrder') &&
            $this->registerHook('actionOrderStatusUpdate');
    }

    public function uninstall()
    {
        return parent::uninstall();
    }

    public function renderForm()
    {
        $format = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('API Key'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type'     => 'text',
                        'placeholder' => 'Format',
                        'label'    => $this->l('Format'),
                        'name'     => 'ORDERREFERENCE_format',
                        'size'     => 50,
                        'required' => true
                   ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right'),
           ),
        );

        $helper = new HelperForm();
        $helper->submit_action = 'submit'.$this->name;
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        $helper->title = $this->displayName;
        $helper->fields_value['ORDERREFERENCE_format'] = Configuration::get('ORDERREFERENCE_format');

        return $helper->generateForm(array($format));
    }


    /**
     * Load the configuration form
     */
    public function getContent()
    {
        if (Tools::isSubmit('submit'.$this->name)) {
            if (Configuration::updateValue("ORDERREFERENCE_format", Tools::getValue("ORDERREFERENCE_format"))) {
                $this->_html .= $this->displayConfirmation($this->l('Format is saved successfully'));
            } else {
                $this->_html .= $this->displayWarning($this->l('Format was not saved'));
            }
        }

        $adminModulesPositionsLink = $this->context->link->getAdminLink('AdminModulesPositions');


        $this->_html .= $this->renderForm();
        $preview_reference = $this->getRandomReference();
        $this->context->smarty->assign(array(
            'preview_reference' => $preview_reference,
            'module_position_is_hight' => $this->checkModuleHookPositionisHigh(),
            'module_position_link' => $adminModulesPositionsLink,
        ));
        $this->_html .= $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');
        return $this->_html;
    }    

    public function hookActionPaymentConfirmation($params)
    {
    	$this->changeReference($params['id_order']);
    }

    public function hookActionValidateOrder($params)
    {
    	$params['order']->reference = $this->changeReference($params['order']->id);
        $order = $params['order'];
        global $order;
        $GLOBALS['order'] = $params['order'];
    }

    public function hookActionObjectOrderUpdateAfter($params)
    {
    	$this->changeReference($params['object']->id);
    }

    public function changeReference($id_order)
    {
        $order = new Order($id_order);
        $reference = $this->getFormattedReference($id_order);
        if (!$reference) {
            return;
        }
        $db = Db::getInstance();
        $db->update('orders', array('reference' => $reference),  'id_order=' . (int)$id_order, $limit = 1);
        return $reference;
    }

    public function getFormat()
    {
    	return Configuration::get('ORDERREFERENCE_format');
    }

    public function getFormattedReference($id_order)
    {
		$string = $this->getFormat();
		preg_match_all('/{(.*?)}/', $string, $matches);
		$reference = '';
		foreach ($matches[1] as $value) {
			$vars = explode(':', $value);
			if (count($vars)==2) {
				$reference .= sprintf("$vars[1]", $this->getVariable($vars[0], $id_order));
			} elseif (count($vars)==3) {
				if ($vars[2] == 'capitalize') {
					$reference .= strtoupper(sprintf("$vars[1]", $this->getVariable($vars[0], $id_order)));
				}
			} elseif (count($vars)==4) {
				if ($vars[2] == 'last') {
					$reference .= substr(strtoupper(sprintf("$vars[1]", $this->getVariable($vars[0], $id_order))), -($vars[3]));
				}				
			} else {
				$reference .= $vars[0];
			}
		}
		if (!Validate::isReference($reference)) {
    		Logger::addLog(sprintf('[%s] reference could not be changed, format has invalid characters', $this->name));
    		return false;
		} else {
			return $reference;
		}
    }

    public function getVariable($variable, $id_order)
    {
    	$order = new Order((int)$id_order);
    	$vars = explode('->', $variable);
    	$classVar = trim($vars[1]);
    	// good logic my friend
		if (preg_match('/(order)/', $variable)) {
			$var = isset($order->{$classVar}) ? $order->{$classVar} : '';
		} elseif (preg_match('/(cart)/', $variable)) {
			$cart = new Cart((int)$order->id_cart);
			$var = isset($cart->{$classVar}) ? $cart->{$classVar} : '';
        } elseif (preg_match('/(shop)/', $variable)) {
            $shop = new Shop((int)$order->id_shop);
            $var = isset($shop->{$classVar}) ? $shop->{$classVar} : '';
		} elseif (preg_match('/(delivery_address)/', $variable)) {
			$address = new Address((int)$order->id_address_delivery);
			$var = isset($address->{$classVar}) ? $address->{$classVar} : '';
		} elseif (preg_match('/(invoice_address)/', $variable)) {
			$address = new Address((int)$order->id_address_invoice);
			$var = isset($address->{$classVar}) ? $address->{$classVar} : '';
		} elseif (preg_match('/(customer)/', $variable)) {
			$customer = new Customer((int)$order->id_customer);
			$var = isset($customer->{$classVar}) ? $customer->{$classVar} : '';
		} else {
			$var = $variable;
		}
		return trim(preg_replace('/\s+/', '', $var));
    }

    public function getRandomReference()
    {
        $id_order = Db::getInstance()->getValue('SELECT id_order FROM '._DB_PREFIX_.'orders ORDER BY id_order DESC');
        return $this->getFormattedReference($id_order);
    }

    public function checkModuleHookPositionisHigh()
    {
        return 'orderreference' == Db::getInstance()->getValue(
            'SELECT m.name FROM '._DB_PREFIX_.'hook h
            LEFT JOIN '._DB_PREFIX_.'hook_module hm ON h.id_hook=hm.id_hook
            LEFT JOIN '._DB_PREFIX_.'module m ON hm.id_module=m.id_module
            WHERE h.name LIKE "actionValidateOrder"
            ORDER BY hm.position'
        );
    }
}
