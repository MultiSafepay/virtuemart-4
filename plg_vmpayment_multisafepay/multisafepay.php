<?php 

/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade the MultiSafepay plugin
 * to newer versions in the future. If you wish to customize the plugin for your
 * needs please document your changes and make backups before you update.
 *
 * @author      MultiSafepay <integration@multisafepay.com>
 * @copyright   Copyright (c) MultiSafepay, Inc. (https://www.multisafepay.com)
 * @license     http://www.gnu.org/licenses/gpl-3.0.html
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED,
 * INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR
 * PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
 * HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN
 * ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
 * WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

use Joomla\CMS\Application\CMSApplication;

use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Util\Notification;
use Psr\Http\Client\ClientExceptionInterface;

defined('_JEXEC') or die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');
defined('DS') or define('DS', DIRECTORY_SEPARATOR);

if (!class_exists('vmPSPlugin')) {
    require(VMPATH_PLUGINLIBS . DS . 'vmpsplugin.php');
}
if (!class_exists('VirtueMartModelOrders')) {
    require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
}
if (!class_exists('VirtueMartCart')) {
    require(VMPATH_SITE . DS . 'helpers' . DS . 'cart.php');
}
require_once(__DIR__ . DS . 'multisafepay' .  DS . 'library' . DS . 'multisafepay.php');

class plgVmPaymentMultisafepay extends vmPSPlugin
{
    public const MSP_VERSION = '2.0.1';
    /* stAn - this should not be defined here as php8.4+ compat should be provided by vmplugin.php in VM core:
	public $tableFields;
    */
	private MultiSafepayLibrary $multisafepay_library;

    /**
     * @param $subject
     * @param $config
     * @since 4.0
     */
    public function __construct(&$subject, $config)
    {
		
        parent::__construct($subject, $config);
       //$this->multisafepay_library = new MultiSafepayLibrary($this->params);
        $this->multisafepay_library = new MultiSafepayLibrary();
		$this->multisafepay_library->setParams($this->params); 
		$this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->setConfigParameterable($this->_configTableFieldName, $this->getVarsToPush());
    }

    /**
     * @return string
     * @since 4.0
     */
    protected function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment MultiSafepay Table');
    }

    /**
     * @return array
     * @since 4.0
     */
    public function getTableSQLFields()
    {
        return [
            'id' => 'int(10) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(10) UNSIGNED',
            'order_number' => 'varchar(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(8) UNSIGNED',
            'payment_name' => "varchar(500) NOT NULL DEFAULT ''",
            'payment_order_total' => "decimal(15,5) NOT NULL DEFAULT '0.00000'",
            'payment_currency' => 'char(3)',
            'cost_per_transaction' => 'decimal(10,2)',
            'cost_percent_total' => 'decimal(10,2)',
            'tax_id' => 'smallint(6)',
            'multisafepay_transaction_id' => 'bigint(19) UNSIGNED',
            'multisafepay_gateway' => 'varchar(64)',
            'multisafepay_ip_address' => 'varchar(39)' // IPv6 max length
        ];
    }

    /**
     * @param $cart
     * @param $order
     * @return ?bool
     * @throws Exception
     * @throws ClientExceptionInterface
     * @since 4.0
     */
    public function plgVmConfirmedOrder($cart, $order)
    {
		
        $method = false;
        if ($order['details']['BT']->virtuemart_paymentmethod_id) {
            $method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id);
        }
        if (!$method) {
            return null;
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return null;
        }

        // Instance of JFactory::getApplication()
        $app = JFactory::getApplication();
        $language = $this->multisafepay_library->getLanguageObject($app);
        $language->load('com_virtuemart', JPATH_ADMINISTRATOR);
        self::getPaymentCurrency($method);

        // Getting ready necessary variables for the next steps
        $days_active = null;
        $payment_currency = CurrencyDisplay::getInstance($method->payment_currency);
        $total_payment = round((float)$payment_currency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false), 2);
        $currency_code_3 = ShopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3');
        $locale = (string)str_replace('-', '_', $language->getTag());

        // Filling up database values
        $payment_name = trim(strip_tags($method->payment_name));
        $this->storePSPluginInternalData($this->multisafepay_library->createDatabaseValues($order, $method, $cart, $total_payment, $currency_code_3, $payment_name));

        $state = ShopFunctions::getStateByID((int)$order['details']['BT']->virtuemart_state_id);
        $country = ShopFunctions::getCountryByID((int)$order['details']['BT']->virtuemart_country_id, 'country_2_code');

        // SECTION USING THE PHP-SDK TO FILLING UP THE PARAMETERS USED BY METHOD: createOrderRequest()
		
        // The unique ID of the order
        //$order_number = (string)$order['details']['BT']->order_number;
		//stAn - adds support to order prefix
		$order_number = (string)$order['details']['BT']->order_number;
		$order_number_msp = $this->params->get('order_prefix', '').(string)$order_number;
		
		try {
        // Total amount of money (object)
        $amount = $this->multisafepay_library->createMoneyAmount($total_payment, $currency_code_3);
		
        // Two CustomerDetails (objects) using just one method, because both objects can be filled up with identical or different data
        [$billing_address, $shipment_address] = $this->multisafepay_library->createCustomerAndDelivery($order, $locale, $state, $country, $app);
		
        // Details about the plugin version, application name, application version, and shop url
        $plugin_details = $this->multisafepay_library->createPluginDetails(self::MSP_VERSION);

        // Creating the notification url, redirection url, cancel url, and notification method
        $payment_options = $this->multisafepay_library->createPaymentOptions($order);

        // Adding gateway information and transaction type, because the latter can change to "direct" according to the gateway. Default is "redirect"
        [$gateway_info, $transaction_type] = $this->multisafepay_library->createGatewayInfoAndTransactionType($order, $method);
		
		foreach ($order['items'] as $key => $item) {
			$order['items'][$key]->product_quantity = (int)$order['items'][$key]->product_quantity;
		}
        // The shopping cart items are built using: products, shipping and payment fees (if available), and finally coupons (in this order)
        $shopping_cart_items = $this->multisafepay_library->createShoppingCartItems($order, $cart, $currency_code_3);

        // Days active for the payment link if available to avoid sending zero days
        if ($method->multisafepay_days_active) {
            $days_active = (int)$method->multisafepay_days_active;
        }
		
        // Finally the order request is created and filled up with all the previously created parameters
        $order_request = $this->multisafepay_library->createOrderRequest(
            $order_number_msp,
            $amount,
            $method,
            $billing_address,
            $shipment_address,
            $plugin_details,
            $payment_options,
            $gateway_info,
            $transaction_type,
            $shopping_cart_items,
            $days_active
        );
		}
		catch(Exception $e) {
			JLog::add($e->getMessage(), JLog::ERROR, 'com_virtuemart');
			$html = JText::_('VMPAYMENT_MULTISAFEPAY_GENERIC_ERROR');
			if (class_exists('OPCErrors')) {
				OPCErrors::store('MSP Error', array($e->getMessage(), $order_request)); 
			}
			$app->enqueueMessage($html,'error');
			return false;  
		}
        // MultiSafepay SDK is loaded and the transaction is created using the order request
        $payment_url = ''; 
		try {
            $sdk = $this->multisafepay_library->getSdkObject($method);
            $transaction_manager = $sdk->getTransactionManager()->create($order_request);
            $payment_url = $transaction_manager->getPaymentUrl();
        } catch (Exception $e) {
            JLog::add($e->getMessage(), JLog::ERROR, 'com_virtuemart');

            $html = JText::_('VMPAYMENT_MULTISAFEPAY_GENERIC_ERROR'); 
            
            $app->enqueueMessage(vmText::_($html), 'error');
			
			if (class_exists('OPCErrors')) {
				OPCErrors::store('MSP Error', array($e->getMessage(), $order_request)); 
			}
			
			return false; 
        }

        // URL to redirect the customer is gotten from the transaction manager of the SDK
        if (!empty($payment_url)) {
            $url = htmlspecialchars_decode($payment_url);
            $model_order = VmModel::getModel('orders');

            // User is notified about the order status change
           
			$order['order_status'] = $this->params->get('status_initialized', 'P');
			$order['customer_notified'] = 1;
            $order['comments'] = '';
			$order['virtuemart_order_id'] = (int)$order['details']['BT']->virtuemart_order_id; 
			//stAn - do not send email for P status if already done:
			if (!$this->isOrderAlreadyUpdated($order['virtuemart_order_id'], $order['order_status'])) 
			{
			if (class_exists('OPCErrors')) {
				
				OPCErrors::store('MSP Order Status Update', array('order_number_msp'=>$order_number,'to_status'=>$order['order_status'], 'from_status'=>$order['details']['BT']->order_status)); 
			}
			
            // Updating the order status
            $model_order->updateStatusForOneOrder((int)$order['details']['BT']->virtuemart_order_id, $order, true);
			}
            // FORCED TWO STATUS:
            // 1) Do not delete the cart because is not confirmed the order yet.
            // 2) Order data is not validated yet. Validation is using a cart hash.
            $cart->_confirmDone = false;
            $cart->_dataValidated = false;
            // Recording the cart data into the session
            $cart->setCartIntoSession();
			
            // Now customer can be redirected to the payment page
            $app->redirect($url, 301);
            $app->close();
			return true; 
        }
        return false; 
    }

    /**
     * @param int $virtuemart_paymentmethod_id
     * @param $paymentCurrencyId
     * @return void
     * @since 4.0
     */
    public function plgVmgetPaymentCurrency(int $virtuemart_paymentmethod_id, &$paymentCurrencyId): void
    {
        $method = false;
        if ($virtuemart_paymentmethod_id) {
            $method = $this->getVmPluginMethod($virtuemart_paymentmethod_id);
        }
        if (!$method) {
            return;
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return;
        }
        self::getPaymentCurrency($method);
        $paymentCurrencyId = $method->payment_currency;
    }

    /**
     * Function to handle all responses
     *
     * @throws Exception
     * @throws ClientExceptionInterface
     * @since 4.0
     */
    public function plgVmOnPaymentResponseReceived(&$html): mixed
    {
        vmLanguage::loadJLang('com_virtuemart_orders', true);

        if (isset($_GET['type']) && ((string)$_GET['type'] === 'feed')) {
            echo 'Process Feed';
            exit;
        }
		
		$status = ''; 
        $method = false;
        $virtuemart_paymentmethod_id = vRequest::getInt('pm');
        if ($virtuemart_paymentmethod_id) {
            $method = $this->getVmPluginMethod($virtuemart_paymentmethod_id);
        }
        if (!$method) {
            return null;
        }

        $order_number_msp = vRequest::getString('on', 0);
		$order_number = $order_number_msp; 
		$prefix = $this->params->get('order_prefix', ''); 
		if (!empty($prefix)) {
			//provide support for MSP order prefix
			$order_number = substr($order_number, strlen($prefix)); 
		}
        if (
            !$method->multisafepay_api_key ||
            empty($order_number) ||
            !$this->selectedThisElement($method->payment_element)
        ) {
            return null;
        }
		if (file_exists(JPATH_ROOT.DIRECTORY_SEPARATOR.'components'.DIRECTORY_SEPARATOR.'com_onepage'.DIRECTORY_SEPARATOR.'helpers'.DIRECTORY_SEPARATOR.'errors.php')) {
			require_once(JPATH_ROOT.DIRECTORY_SEPARATOR.'components'.DIRECTORY_SEPARATOR.'com_onepage'.DIRECTORY_SEPARATOR.'helpers'.DIRECTORY_SEPARATOR.'errors.php'); 
		}
        $model_order = VmModel::getModel('orders');
        $order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
        $order_id = (int)$order_id; 
		
		if (empty($order_id)) {
			$validation_failed = true;
		}
		else {
		
		
		$order_object = $model_order->getOrder($order_id);

        // Verification of the notification sent by MultiSafepay
        $validation_failed = false;
        $body = file_get_contents('php://input');
		if (!empty($body)) 
        if ($_SERVER['HTTP_AUTH'] && !Notification::verifyNotification($body, $_SERVER['HTTP_AUTH'], $method->multisafepay_api_key)) {
            $validation_failed = true;
			JLog::add('MSP: Order not found: '.$order_number_msp, JLog::ERROR, 'com_virtuemart');
			
			if (class_exists('OPCErrors')) {
				OPCErrors::store('MSP Error Order not found', array($body)); 
			}
			
		}
		}

        try {
            /** @var TransactionResponse $transaction */
            if ($body) {
                $transaction = $this->multisafepay_library->getTransactionFromNotification($body);
            } else {
                $sdk = $this->multisafepay_library->getSdkObject($method);
				//stAn - check with prefixed order number
                $transaction = $sdk->getTransactionManager()->get($order_number_msp);
            }
            $status = $transaction->getStatus();
            $multisafepay_transaction_id = $transaction->getTransactionId();
        } catch (Exception $e) {
            JLog::add($e->getMessage(), JLog::ERROR, 'com_virtuemart');

            if (class_exists('OPCErrors')) {
				OPCErrors::store('MSP Error Order not found', array($e->getMessage(), $order_number_msp)); 
			}
			$html = JText::_('VMPAYMENT_MULTISAFEPAY_GENERIC_ERROR'); 
            vmError($html);
            vRequest::setVar('html', $html);
            echo $html;
            exit();
        }
		
		if (class_exists('OPCErrors')) {
				$rawData = $transaction->getRawData(); 
				OPCErrors::store('MSP IPN Received', array('order_number_msp'=>$order_number_msp,'status'=>$status,  'transaction'=>$rawData, 'virtuemart_paymentmethod_id'=>$virtuemart_paymentmethod_id, 'body'=>$body)); 
			}
		
		if (!$validation_failed)
		{
        if ($multisafepay_transaction_id) {
			
            $db = JFactory::getDBO();
            if (!is_null($db)) {
                $query = 'UPDATE `#__virtuemart_payment_plg_multisafepay` SET `multisafepay_transaction_id` = "' . (int)$multisafepay_transaction_id . '" WHERE `virtuemart_order_id` = "' . (int)$order_id . '"';
                $db->setQuery($query);
                $db->execute();
            }
        }
		}
		//for thank you page
        $details = [
            'status' => $status,
            'transactionid' => $order_number_msp
        ];
		
		
		
        $order = [];
        $vm_status = '';
        switch ($status) {
            case 'initialized':
                vRequest::setVar('multisafepay_msg', JText::_('VMPAYMENT_MULTISAFEPAY_MSG_INITIALIZED'));
                
                $order['order_status'] = $this->params->get('status_initialized', 'P');
                $vm_status = $order['order_status'];
                break;
            case 'completed':
                vRequest::setVar('multisafepay_msg', JText::_('VMPAYMENT_MULTISAFEPAY_MSG_COMPLETED'));
                
                $order['order_status'] = $this->params->get('status_completed', '');
                $vm_status = $order['order_status'];
				
				$transaction_currency = $transaction->getCurrency();
				$transaction_amount = $transaction->getAmount();	
				$transaction_amount_refunded = $transaction->getAmountRefunded(); 
				$order['paid'] = $transaction_amount / 100; 
				$date = new JDate($transaction->getModified()); 
				$order['paid_on'] = $date->toSql();
				
                break;
            case 'cancelled':
                vRequest::setVar('multisafepay_msg', JText::_('VMPAYMENT_MULTISAFEPAY_MSG_CANCELED'));
               
                $order['order_status'] = $this->params->get('status_canceled', '');
                $vm_status = $order['order_status'];
                break;
            case 'expired':
                vRequest::setVar('multisafepay_msg', JText::_('VMPAYMENT_MULTISAFEPAY_MSG_EXPIRED'));
                
                $order['order_status'] = $this->params->get('status_expired', '');
                
				$vm_status = $order['order_status'];
                break;
            case 'void':
                vRequest::setVar('multisafepay_msg', JText::_('VMPAYMENT_MULTISAFEPAY_MSG_VOID'));
                
                $order['order_status'] = $this->params->get('status_void', '');
                $vm_status = $order['order_status'];
                break;
            case 'declined':
                vRequest::setVar('multisafepay_msg', JText::_('VMPAYMENT_MULTISAFEPAY_MSG_DECLINED'));
                
                $order['order_status'] = $this->params->get('status_declined', '');
                $vm_status = $order['order_status'];
                break;
			case 'partial_refunded':
				 vRequest::setVar('multisafepay_msg', JText::_('VMPAYMENT_MULTISAFEPAY_MSG_REFUNDED'));
                 
				 $transaction_amount = $transaction->getAmount();	
				 $transaction_amount_refunded = $transaction->getAmountRefunded(); 
				 $order['paid'] = ($transaction_amount / 100) - ($transaction_amount_refunded / 100); 
                 $order['order_status'] = $this->params->get('status_refunded', '');
                 $vm_status = $order['order_status'];
                 break;
            case 'refunded':
                vRequest::setVar('multisafepay_msg', JText::_('VMPAYMENT_MULTISAFEPAY_MSG_REFUNDED'));
                 
				 $transaction_amount = $transaction->getAmount();	
				 $transaction_amount_refunded = $transaction->getAmountRefunded(); 
				 $order['paid'] = ($transaction_amount / 100) - ($transaction_amount_refunded / 100); 
                 $order['order_status'] = $this->params->get('status_refunded', '');
                 $vm_status = $order['order_status'];
                 break;
            case 'uncleared':
                vRequest::setVar('multisafepay_msg', JText::_('VMPAYMENT_UNCLEARED_MSG_UNCLEARED'));
                
                $order['order_status'] = $this->params->get('status_uncleared', '');
                $vm_status = $order['order_status'];
                break;
            case 'shipped':
                vRequest::setVar('multisafepay_msg', JText::_('VMPAYMENT_MULTISAFEPAY_MSG_SHIPPED'));
                
                $order['order_status'] = $this->params->get('status_shipped', '');
                $vm_status = $order['order_status'];
                break;
        }
		
		if (!empty($order)) {
		
		
		
		//if at least one known status was detected from msp, product html for customer
			
		$payment_name = $this->renderPluginName($method); 
		if (empty($payment_name)) {
			//payment method is unpblished or was removed
			$db = JFactory::getDBO();
			$db->setQuery('SELECT * FROM `' . $this->_tablename . '` WHERE `virtuemart_order_id` = "' . $virtuemart_order_id . '" order by `id` desc limit 1');
            $payment_table = $db->loadObject();
			if (!empty($payment_table)) {
				$payment_name = $payment_table->payment_name; 
			}
		}
		$html = $this->getPaymentResponseHtml($details, $payment_name , $method, $order_object, $order);
		
		$order['order_status'] = $vm_status; 
		if (empty($body)) {
			$is_customer_context = true; 
		}
		else {
			$is_customer_context = false; 
		}
		if ((!empty($this->params->get('status_do_nothing', '')) || (empty($vm_status)))) 
		{
			$status_do_nothing = $this->params->get('status_do_nothing', ''); 
			if (empty($vm_status) || ($vm_status === $status_do_nothing)) {
				if (!$is_customer_context) {
					echo 'OK, do nothing'; 
					JFactory::getApplication()->close(); 
				}
				else {
					 return $html;
				}
			}
		}
        // Notifying MultiSafepay: Invoiced
		//stAn - not all vendors are using ecommerce to create invoice numbers and thus this must not have any default value:
        $orders_with_invoice = VmConfig::get('inv_os', array());
        if (!empty($orders_with_invoice)) {
		if (!is_array($orders_with_invoice)) {
            $orders_with_invoice = (array)$orders_with_invoice;
        }
		}
		else {
			$orders_with_invoice = array(); 
		}
		//stAn - isAlreadyDone is an alias of "canProceed": 
		//we don't want to double update statuses or change status from Shipped to Confirmed
		
		$isAlreadyDone = $this->isOrderAlreadyUpdated($order_id, $order['order_status'], $is_customer_context);
		if (!$validation_failed) {
		if(empty($isAlreadyDone)) {
		if (isset($_GET['type']) && ($_GET['type'] === 'redirect')) {
				//stAn - if customer is too fast, we get 2x IPN calls with the same data for an order which is not yet updated
				sleep(5); 
			}
		
		//stAn - is this really needed for status update from MSP to VM to update MSP ? 
        foreach ($orders_with_invoice as $one_order_with_invoice) {
            if ($vm_status === (string)$one_order_with_invoice) {
                $invoice_model = VmModel::getModel('invoice');
                $invoice_number = $invoice_model->getInvoiceNumber($order_id);
                if ($invoice_number) {
                    $validation_invoice = $this->multisafepay_library->changeOrderStatusTo($order_number_msp, $method, ['invoice_id' => $invoice_number]);
                    $log_message = 'Update Status as Invoiced for Order #' . $order_number_msp . ' was ' . (!empty($validation_invoice) ? 'Successful' : 'Unsuccessful');
                    (!empty($validation_invoice) ? vmInfo($log_message) : vmError($log_message));
                }
            }
        }
		
		
		
		
        
		
            $order['virtuemart_order_id'] = (int)$order_id;
            $order['comments'] = '';
            if ($order['order_status'] !== $this->params->get('status_canceled', '')) {
                $order['customer_notified'] = 1;
            } else {
                $order['customer_notified'] = 0;
            }
			
			if (class_exists('OPCErrors')) {
				
				OPCErrors::store('MSP Order Status Update', array('order_number_msp'=>$order_number_msp,'order_id'=>$order_id,'to_status'=>$order['order_status'], 'from_status'=>$order_object['details']['BT']->order_status)); 
			}
			
            $model_order->updateStatusForOneOrder($order_id, $order);
        
		}
		}
		}
		
		if ($is_customer_context)
		if (($vm_status === $this->params->get('status_completed', '')) || ($vm_status === $this->params->get('status_initialized', '')))
        {
            $this->emptyCart();
        }

        // NOTE: Altering the status after all the previous actions are done
        if ($validation_failed) {
            JLog::add('Notification for Order #' . $order_number  . ' has been received but is not valid.', JLog::ERROR, 'com_virtuemart');
			if (class_exists('OPCErrors')) {
				OPCErrors::store('MSP Error validation failed', array($order_id, $order_number)); 
			}
           
            /*stAn - we must not allow attacker to change order statatus of an order:
			$order['order_status'] = 'P';
            $order['paid'] = 0;
            $model_order->updateStatusForOneOrder($order_id, $order);
			*/
			
        }

        if (isset($_GET['type']) && ((string)$_GET['type'] === 'redirect')) {
            return $html;
        }
        echo 'OK';
        JFactory::getApplication()->close(); 
    }

    /**
     * @param VirtueMartCart $cart
     *
     * @param int $selected
     * @param mixed $htmlIn
     *
     * @return bool
     * @throws ClientExceptionInterface
     * @throws Exception
     * @since 4.0
     */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, int $selected = 0, mixed &$htmlIn = [])
    {
        if ((string)$this->getPluginMethods($cart->vendorId) === '0') {
            
            return null;
        }
		if (empty($this->methods)) return null; 
		
        $htmla = [];
        //vmdebug('methods', $this->methods);
        VmLanguage::loadJLang('com_virtuemart');
        $currency = CurrencyDisplay::getInstance();
		
		foreach ($this->methods as $method) {
            if ($this->checkConditions($cart, $method, $cart->cartPrices)) {
                $method_sales_price = $this->calculateSalesPrice($cart, $method, $cart->cartPrices);
                $logo = $this->displayLogos($method->payment_logos);
                $payment_cost = $checked = '';
                if ($method_sales_price) {
                    $payment_cost = $currency->priceDisplay($method_sales_price);
                }
                if ($selected === (int)$method->virtuemart_paymentmethod_id) {
                    $checked = 'checked="checked"';
                }

                $html = $this->renderByLayout('display_payment', [
                    'plugin' => $method,
                    'checked' => $checked,
                    'payment_logo' => $logo,
                    'payment_cost' => $payment_cost
                ]);

                $htmla[(int)$method->virtuemart_paymentmethod_id] = trim($html);
            }
        }
		
		

        if (empty($htmlIn)) {
            $htmlIn = [];
        }

        if (!empty($htmla)) {
            if (empty($htmlIn['payment'])) {
                $htmlIn['payment'] = [];
            }

            foreach ($htmla as $key => $value) {
                $htmlIn['payment'][$key] = $value;
            }
        }
        return true;
    }

    /**
     * Set the orders as shipped and invoiced
     *
     * @param $order
     * @param $old_order_status
     *
     * @return ?bool
     * @throws ClientExceptionInterface
     * @since 4.0
     */
    public function plgVmOnUpdateOrderPayment(&$order, $old_order_status)
    {
        $method = false;
		if (!is_object($order)) return null; 
		
        if ($order->virtuemart_paymentmethod_id) {
            $method = $this->getVmPluginMethod($order->virtuemart_paymentmethod_id);
        }
        if (!$method) {
            return null;
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return null;
        }
        // Load the payments
        $payments = $this->getDatasByOrderId($order->virtuemart_order_id);
        if (!$payments) {
            return null;
        }

        $validation = true;
        $model_order = VmModel::getModel('orders');
        $order_object = $model_order->getOrder($order->virtuemart_order_id);
		$order_id = (int)$order->virtuemart_order_id;
		
		$order_number = false; 
		if (!empty($order_object)) {
			$order_number = $order_object['details']['BT']->order_number;
		}
        if ($order_number) {
			$order_number_msp = $this->params->get('order_prefix', '').(string)$order_number;
            // Notifying MultiSafepay: Invoiced
            $invoice_model = VmModel::getModel('invoice');
            $invoice_number = $invoice_model->getInvoiceNumber($order->virtuemart_order_id);
            $status_do_nothing = $this->params->get('status_do_nothing', '');
			
			if ($invoice_number) {
                // Array of orders status that will be set as invoiced. Set by VM as default is C (Confirmed)
                //stAn - no default value should be used here:
				$orders_with_invoice = VmConfig::get('inv_os', array());
                if (!is_array($orders_with_invoice)) {
                    $orders_with_invoice = (array)$orders_with_invoice;
                }

                foreach ($orders_with_invoice as $one_order_with_invoice) {
                    // If the "updated" order status is the same as the "ones" set in VM,
                    // then the order is set as invoiced, and sent to MultiSafepay
                    if (!empty($status_do_nothing) && ($status_do_nothing !== $one_order_with_invoice))
					if ($order->order_status === $one_order_with_invoice) {
                        $validation_invoice = $this->multisafepay_library->changeOrderStatusTo($order_number_msp, $method, ['invoice_id' => $invoice_number]);
                        $log_message = 'Update Status as Invoiced for Order #' . $order_number . ' was ' . (!empty($validation_invoice) ? 'Successful' : 'Unsuccessful');
                        (!empty($validation_invoice) ? vmInfo($log_message) : vmError($log_message));
                    }
                }
            }

            // Notifying MultiSafepay: Order shipped
			$shipped = $this->params->get('status_shipped', ''); 
			
			
			if (!empty($status_do_nothing) && ($status_do_nothing !== $shipped))
            if (($old_order_status !== $shipped) && ($order->order_status === $shipped)) {
                $validation = $this->multisafepay_library->changeOrderStatusTo($order_number_msp, $method, [], 'shipped');
                $log_message = 'Update Status as Shipped for Order #' . $order_number . ' was ' . (!empty($validation) ? 'Successful' : 'Unsuccessful');
                JLog::add($log_message, JLog::ERROR, 'com_virtuemart');
				if (!empty($validation)) { 
					vmInfo($log_message);
				}
				else {				
					vmError($log_message);
				}
            }
			$supress_refunds = $this->params->get('supress_refunds', 0); 
			if (empty($supress_refunds)) {
            // Notifying MultiSafepay: Order refunded
			$refunded = $this->params->get('status_refunded', ''); 
			$history = self::getOrderHistory($order_id); 
			$confirmed = $this->params->get('status_completed', ''); 
			
			if (!empty($status_do_nothing) && ($status_do_nothing !== $refunded))
            if (in_array($confirmed, $history) && ((string)$order->order_status === $refunded)) {
                $payment_currency_id = (int)$order_object['details']['BT']->payment_currency;

                if (!$payment_currency_id && is_array($payments)) {
                    $currency_code_3_prev = 'EUR';
                    foreach ($payments as $payment) {
                        // If the order number is the same, then get the currency code from the
                        // payments array as it wasn't able to get it from the order object
                        if ((int)$payment->virtuemart_order_id === (int)$order_id) {
                            $currency_code_3_prev = $payment->payment_currency;
                            break;
                        }
                    }
                    $payment_currency_id = ShopFunctions::getCurrencyIDByName($currency_code_3_prev);
                }

                $currency_code_3 = ShopFunctions::getCurrencyByID($payment_currency_id, 'currency_code_3');
                $amount = $this->multisafepay_library->getMoneyObject(0.00, $currency_code_3); // Refund totally adding 0
				
                $validation = $this->multisafepay_library->getRefundObject($order_number_msp, $amount, $method);
                $log_message = 'Refund for Order #' . $order_number . ' was ' . (!empty($validation) ? 'Successful' : 'Unsuccessful');
                JLog::add($log_message, JLog::ERROR, 'com_virtuemart');
				if (!empty($validation)) { 
					vmInfo($log_message);
				}
				else {				
					//vmError($log_message);
				}
            }
			}
        } else {
			$log_message = 'Order number not found';
			JLog::add($log_message, JLog::ERROR, 'com_virtuemart');
            vmWarn('Order number not found');
            return null;
        }
        return $validation;
    }

   

   

    /**
     * Display stored payment data for an order
     * @see components/com_virtuemart/helpers/vmPSPlugin::plgVmOnShowOrderBEPayment()
     *
     * @param $payment_method_id
     * @param $virtuemart_order_id
     * @return ?string
     * @since 4.0
     */
    public function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id): ?string
    {
        if (!$this->selectedThisByMethodId($payment_method_id)) {
            return null; // Another method was selected, do nothing
        }

        
        
		$db = JFactory::getDBO();
		
		if (!is_null($db)) {
            $db->setQuery('SELECT * FROM `' . $this->_tablename . '` WHERE `virtuemart_order_id` = "' . $virtuemart_order_id . '" order by `id` desc limit 1');
            $payment_table = $db->loadObject();
            if (!$payment_table) {
                return '';
            }
        }

        self::getPaymentCurrency($payment_table);

        $currency_code_3 = $payment_table->payment_currency;
        /*
		stAn - payment_currency is already in ISO format per createDatabaseValues
		if (!is_null($db)) {
            $db->setQuery('SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id` = "' . $payment_table->payment_currency . '"');
            $currency_code_3 = $db->loadResult();
        }
		*/
		
        $html = '<table class="adminlist">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('MULTISAFEPAY_PAYMENT_NAME', $payment_table->payment_name);
        $html .= $this->getHtmlRowBE('MULTISAFEPAY_PAYMENT_TOTAL_CURRENCY', $payment_table->payment_order_total . ' ' . $currency_code_3);
        $html .= '</table>' . "\n";

        return $html;
    }

    /**
     * @param $payment_name
     * @param $data
     * @return string
     * @since 4.0
     */
    public function getPaymentResponseHtml($data, $payment_name, $currentMethod, $order, $new_order): string
    {
		//stAn, this provides an override for custom thank you page
		
		$html = $this->renderByLayout('thankyou', array('payment' => $currentMethod, 'data'=>$data, 'order'=>$order, 'new_order'=>$new_order, 'ref'=>$this, 'payment_name'=>$payment_name));
        return $html;
    }

    /**
     * @param $method
     * @param $cart_prices
     * @param VirtueMartCart $cart
     * @return float
     * @since 4.0
     */
    public function getCosts(VirtueMartCart $cart, $method, $cart_prices): float
    {
        if (str_ends_with((string)$method->cost_percent_total, '%')) {
            $cost_percent_total = substr((string)$method->cost_percent_total, 0, -1);
        } else {
            $cost_percent_total = $method->cost_percent_total;
        }
        return ((float)$method->cost_per_transaction + ((float)$cart_prices['salesPrice'] * (float)$cost_percent_total * 0.01));
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
     *
     * @param $method
     * @param $cart_prices : cart prices
     * @param $cart
     * @return true if the conditions are fulfilled, false otherwise
     * @since 4.0
     */
    protected function checkConditions($cart, $method, $cart_prices): bool
    {
        $test = true;
        if ($method->multisafepay_ip_validation) {
            $ip = explode(';', $method->multisafepay_ip_address);

            if (!in_array($_SERVER['REMOTE_ADDR'], $ip)) {
                $test = false;
            }
        }

        if (method_exists($cart, 'getST')) {
            $address = $cart->getST();
        } else {
            $address = (
            (
                (
                    (int)$cart->ST === 0
                ) ||
                (
                    (int)$cart->STSameAsBT === 1
                )
            ) ? $cart->BT : $cart->ST
            );
        }

        $amount = (float)$cart_prices['salesPrice'];
        $amount_cond = (
            (
                ($amount >= (float)$method->min_amount) &&
                ($amount <= (float)$method->max_amount)
            ) ||
            (
                ((float)$method->min_amount <= $amount) &&
                ((float)$method->max_amount === 0.0)
            )
        );

        $countries = [];
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }
        // Probably did not give his BT:ST address
        if (!is_array($address)) {
            $address = [];
            $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }

        if (in_array($address['virtuemart_country_id'], $countries) || (count($countries) === 0)) {
            if ($amount_cond && $test) {
                return true;
            }
        }
        return false;
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     *
     * @param string $jplugin_id
     *
     * @return ?bool
     * @since 4.0
     */
    public function plgVmOnStoreInstallPaymentPluginTable(string $jplugin_id): ?bool
    {
        if ($jplugin_id !== (string)$this->_jid) {
            return false;
        }
        return $this->onStoreInstallPluginTable();
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @param $msg
     * @param VirtueMartCart $cart The actual cart
     * @return ?bool if the payment was not selected, true if the data is valid, error message if the data is not valid
     * @throws Exception
     * @since 4.0
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
            return null;
        }

        $method = false;
        if ($cart->virtuemart_paymentmethod_id) {
            $method = $this->getVmPluginMethod($cart->virtuemart_paymentmethod_id);
        }
        if (!$method) {
            return null;
        }

        
        return true;
    }

    /**
     * Calculate the price (value, tax_id) of the selected method.
     * It is called by the calculator.
     * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
     *
     * @param VirtueMartCart $cart
     * @param array $cart_prices
     * @param $cart_prices_name
     *
     * @return ?bool if the method was not selected, false if the shipping rate is not valid anymore, true otherwise
     * @since 4.0
     */
    public function plgVmOnSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    /**
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     *
     * @param VirtueMartCart $cart cart: the cart object
     * @param array $cart_prices
     *
     * @return ?array
     * @since 4.0
     */
    public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = [])
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param $virtuemart_paymentmethod_id
     * @param $payment_name
     * @param $virtuemart_order_id
     * @return void Null for methods that aren't active, text (HTML) otherwise
     * @since 4.0
     */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * This event is fired during the checkout process. It can be used to validate the method data as entered by the user.
     *
     * @param VirtueMartCart $cart
     * @return ?bool True when the data was valid, false otherwise. If the plugin is not activated, it should return null.
     * @throws Exception
     * @since 4.0
     */
    public function plgVmOnCheckoutCheckDataPayment(VirtueMartCart $cart)
    {
        if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
            return null;
        }

        $method = false;
        if ($cart->virtuemart_paymentmethod_id) {
            $method = $this->getVmPluginMethod($cart->virtuemart_paymentmethod_id);
        }
        if (!$method) {
            return null;
        }

        
        return true;
    }

    /**
     * This method is fired when showing when printing an Order
     * It displays the payment method-specific data.
     *
     * @param int $method_id method used for this order
     * @param $order_number
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @since 4.0
     */
    public function plgVmOnShowOrderPrintPayment($order_number, int $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    /**
     * @param $id
     * @param $table
     * @param $name
     * @return bool
     * @since 4.0
     */
    public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    /**
     * @param $data
     * @return bool
     * @since 4.0
     */
    public function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }
	
	private function isOrderAlreadyUpdated($order_id, $toUpdateStatus, $is_customer_context=true) {
		
		$history = self::getOrderHistory($order_id); 
		$last = end($history); 
		//last one is same as current 
		if ($last === $toUpdateStatus) return true; 
		
		//no update is possible for Do Nothing
		$status_do_nothing = $this->params->get('status_do_nothing', ''); 
		if ($toUpdateStatus === $status_do_nothing) return true; 
		
		$locking_status = $this->params->get('locking_status', array()); 
		$locking_status_exceptions = $this->params->get('locking_status_exceptions', array());
		//current status is in locking exceptions, such as refunded or partial refunded:
		//we dont update order in customer context even for exceptions:
		if (!$is_customer_context)
		if (!empty($locking_status_exceptions) && (is_array($locking_status_exceptions))) {
			if (in_array($toUpdateStatus, $locking_status_exceptions)) {
				return false; 
			}
		}
		//we found locking status in history, no update is possible - such as shipped (to confirmed)
		if (!empty($locking_status)) {
			if (!is_array($locking_status)) $locking_status = array($locking_status); 
			foreach ($locking_status as $lc) {
				if (in_array($lc, $history)) {
					return true; 
				}
			}
		}
		
		return false; 
	
		
	}
	
	private static function getOrderHistory($order_id, $after_status='') {
		$db = JFactory::getDBO(); 
		$q = 'select h.order_status_code from #__virtuemart_order_histories as h '; 
		
		$q .= ' where h.virtuemart_order_id = '.(int)$order_id.' '; 
		
		$q .= ' order by h.virtuemart_order_history_id asc'; 
		
		
		
		$db->setQuery($q); 
		$res = $db->loadAssocList(); 
		$ret = array(); 
		if (!empty($res)) {
			foreach ($res as $row) { 
			$ret[] = $row['order_status_code']; 
			if ($row['order_status_code'] === $after_status) $ret = array(); 
			}
		}
		
		$q = 'select order_status from #__virtuemart_orders where virtuemart_order_id = '.(int)$order_id; 
		$db->setQuery($q); 
		$last_order_head_status = $db->loadResult(); 
		if (!empty($last_order_head_status)) {
		$last = end($ret); 
		if ($last !== $last_order_head_status) {
			$ret[] = $last_order_head_status; 
		}
		}
		
		return $ret; 
	}
	
}
