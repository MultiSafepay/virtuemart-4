<?php declare(strict_types=1);

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

require_once(__DIR__ . '/../../vendor/autoload.php');
require_once(JPATH_LIBRARIES . '/vendor/autoload.php');

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Language\Language;
use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\CustomerDetails;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\GatewayInfo\Issuer;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\GatewayInfoInterface;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\PaymentOptions;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\PluginDetails;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\ShoppingCart;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\ShoppingCart\ShippingItem;
use MultiSafepay\Api\Transactions\RefundRequest;
use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Api\Transactions\UpdateRequest;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
use MultiSafepay\Sdk;
use MultiSafepay\ValueObject\CartItem;
use MultiSafepay\ValueObject\Customer\Address;
use MultiSafepay\ValueObject\Customer\AddressParser;
use MultiSafepay\ValueObject\Customer\EmailAddress;
use MultiSafepay\ValueObject\Customer\PhoneNumber;
use MultiSafepay\ValueObject\IpAddress;
use MultiSafepay\ValueObject\Money;
use MultiSafepay\ValueObject\Weight;
use Psr\Http\Client\ClientExceptionInterface;

class MultiSafepayLibrary
{
    /**
     * Returns a Sdk object
     *
     * @param object $method
     *
     * @return Sdk object
     * @since 4.0
     */
    public function getSdkObject(object $method): Sdk
    {
        $sdk = $is_production = false;
        $client = new Client();
        $factory = new HttpFactory();
        if ((string)$method->sandbox !== '1') {
            $is_production = true;
        }

        try {
            $sdk = new Sdk($method->multisafepay_api_key ?? '', $is_production, $client, $factory, $factory);
        } catch (InvalidApiKeyException $e) {
            JLog::add($e->getMessage(), JLog::ERROR, 'com_virtuemart');
            vmError($e->getMessage());
        }
        return $sdk;
    }

    /**
     * Check if issuers are accessible because the API is available
     *
     * @param object $method
     *
     * @return bool|array
     */
    public function getIdealIssuers(object $method): bool|array
    {
        try {
            $sdk = $this->getSdkObject($method);
            return $sdk->getIssuerManager()->getIssuersByGatewayCode('IDEAL');
        } catch (ApiException | ClientExceptionInterface) {
            return false;
        }
    }

    /**
     * @param array $order
     * @param object $method
     * @param object $cart
     * @param float $total_payment
     * @param string $currency_code_3
     * @param string $payment_name
     *
     * @return array
     *
     * @throws Exception
     * @since 4.0
     */
    public function createDatabaseValues(array $order, object $method, object $cart, float $total_payment, string $currency_code_3, string $payment_name): array
    {
        $db_values = [];
        $db_values['virtuemart_order_id'] = $order['details']['BT']->virtuemart_order_id;
        $db_values['order_number'] = $order['details']['BT']->order_number;
        $db_values['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
        $db_values['payment_name'] = $payment_name;
        $db_values['payment_order_total'] = $total_payment;
        $db_values['payment_currency'] = $currency_code_3;
        $db_values['cost_per_transaction'] = $method->cost_per_transaction;
        $db_values['cost_percent_total'] = $method->cost_percent_total;
        $db_values['tax_id'] = $method->tax_id;
        $db_values['multisafepay_gateway'] = $method->multisafepay_gateway;
        $db_values['multisafepay_ip_address'] = $this->getIpAddress();
        return $db_values;
    }

    /**
     * This method is an alias of getMoneyObject()
     * to create a clean message to build parameters
     * for the method createOrderRequest()
     *
     * @param float $amount
     * @param string $currency_code
     *
     * @return Money
     * @since 4.0
     */
    public function createMoneyAmount(float $amount, string $currency_code): Money
    {
        return $this->getMoneyObject($amount, $currency_code);
    }

    /**
     * Returns two customer objects used to build the order request
     * object avoiding a double loop if necessary, and freeing memory
     *
     * @param array $order
     * @param string $locale
     * @param string $state
     * @param string $country
     * @param SiteApplication|null $app
     *
     * @return array object
     * @throws Exception
     * @since 4.0
     */
    public function createCustomerAndDelivery(array $order, string $locale, string $state, string $country, SiteApplication|null $app): array
    {
        // Billing and shipping data
        $order_array = [
            $order['details']['BT'],
            $order['details']['ST']
        ];

        $shipmentSameAsBilling = false;
        if ($order['details']['BT']->STsameAsBT && ((string)$order['details']['BT']->STsameAsBT === '1')) {
            unset($order_array[1]);
            $shipmentSameAsBilling = true;
        }

        $ip_address = $this->getIpAddress();
        $user_agent = $this->getUserAgent();
        $referrer = $this->getReferrer();
        $ip_forwarded = $this->getIpForwarded();

        $objects = [];
        foreach ($order_array as $order_details) {
            if (empty($order_details->email) && !$shipmentSameAsBilling) {
                $order_details->email = $order['details']['BT']->email;
            }
            $address = $this->createAddress($order_details, $state, $country);
            $user_identity = $this->getUserIdentity($order_details, $app);

            // Customer is created
            $customer = new CustomerDetails();
            if (!empty($ip_address)) {
                $customer->addIpAddress(new IpAddress($ip_address));
            }
            $customer->addFirstName($order_details->first_name ?? '');
            $customer->addLastName($order_details->last_name ?? '');
            $customer->addCompanyName($order_details->company ?? '');
            $customer->addAddress($address);
            if (!empty($order_details->email)) {
                $customer->addEmailAddress(new EmailAddress($order_details->email));
            }
            $customer->addPhoneNumber(new PhoneNumber($order_details->phone_1 ?? ''));
            $customer->addLocale($locale);
            if (!empty($user_agent)) {
                $customer->addUserAgent($user_agent);
            }
            if (!empty($referrer)) {
                $customer->addReferrer($referrer);
            }
            if (!empty($ip_forwarded)) {
                $customer->addForwardedIp(new IpAddress($ip_forwarded));
            }
            if (!empty($user_identity)) {
                $customer->addReference($user_identity);
            }
            $objects[] = $customer;
            unset($order_details, $address, $ip_forwarded, $customer);
        }

        // Customer object is repeated as output for delivery if the shipment is the same as the billing
        if ($shipmentSameAsBilling) {
            $objects[] = $objects[0];
        }
        return $objects;
    }

    /**
     * Returns a PluginDetails object used to build the order request object
     *
     * @param string $version
     *
     * @return PluginDetails
     * @since 4.0
     */
    public function createPluginDetails(string $version): PluginDetails
    {
        return (new PluginDetails())
            ->addApplicationName('Virtuemart ' . VM_VERSION)
            ->addApplicationVersion((string)VM_VERSION)
            ->addPluginVersion($version)
            ->addPartner('')
            ->addShopRootUrl(JURI::root());
    }

    /**
     * Returns a PaymentOptions object used to build the order request object
     *
     * @param array $order
     *
     * @return PaymentOptions object
     * @since 4.0
     */
    public function createPaymentOptions(array $order): PaymentOptions
    {
        $plugin_response = 'option=com_virtuemart&view=pluginresponse&task=';
        $response_received = 'pluginResponseReceived';
        $payment_cancelled = 'pluginUserPaymentCancel';

        $order_number = $order['details']['BT']->order_number;
        $payment_id = $order['details']['BT']->virtuemart_paymentmethod_id;
        $base_url = JURI::root() . 'index.php?' . $plugin_response;

        $notification_url = JROUTE::_($base_url . $response_received . '&on=' . $order_number . '&pm=' . $payment_id . '&type=initial');
        $redirect_url = JROUTE::_($base_url . $response_received . '&on=' . $order_number . '&pm=' . $payment_id . '&type=redirect');
        $cancel_url = JROUTE::_($base_url . $payment_cancelled . '&on=' . $order_number . '&pm=' . $payment_id);

        return (new PaymentOptions())
            ->addNotificationUrl($notification_url ?? '')
            ->addRedirectUrl($redirect_url ?? '')
            ->addCancelUrl($cancel_url ?? '')
            ->addCloseWindow(true);
    }

    /**
     * @param array $order
     * @param object $method
     *
     * @return array
     *
     * @throws Exception
     * @since 4.0
     */
    public function createGatewayInfoAndTransactionType(array $order, object $method): array
    {
        $gateway_info = null;
        $transaction_type = 'redirect';

        // If VMuikit X is not installed, we can get the related bank id, enabling the transaction type as direct as well
        if (((string)$method->multisafepay_gateway === 'IDEAL') && !JComponentHelper::getComponent('com_vmuikitx', true)->enabled) {
            $issuer = (string)$this->getSelectedIssuerBank($order['details']['BT']->virtuemart_paymentmethod_id) ?: '';
            if (!empty($issuer)) {
                $gateway_info = (new Issuer())->addIssuerId($issuer);
                if ($gateway_info) {
                    $transaction_type = 'direct';
                }
            }
        }
        return [$gateway_info, $transaction_type];
    }

    /**
     * @param array $order
     * @param object $cart
     * @param string $currency_code_3
     *
     * @return array
     *
     * @since 4.0
     */
    public function createShoppingCartItems(array $order, object $cart, string $currency_code_3): array
    {
        $shopping_cart_items = [];

        // Coupons: Processing data
        $coupon_percent = 0.00;
        $shopping_cart_items_prev = null;
        if ($order['details']['BT']->coupon_discount && ((float)$order['details']['BT']->coupon_discount !== 0.00)) {
            [$shopping_cart_items_prev, $coupon_percent] = $this->getCoupon($order, $currency_code_3);
        }

        // Shopping Cart Items
        if (!empty($order['items'])) {
            foreach ($order['items'] as $key => $item) {
                $shopping_cart_items[] = $this->getShoppingCartItems($key, $item, $currency_code_3, $cart, $coupon_percent);
            }
        }

        // Shipping Fee
        if ($order['details']['ST']->order_shipment) {
            $shipment_price = (float)$order['details']['ST']->order_shipment;
            if ($shipment_price > 0) {
                $shopping_cart_items[] = $this->getShipping($order, $shipment_price, $currency_code_3);
            }
        }

        // Payment Fee
        if ($order['details']['BT']->order_payment) {
            $payment_price = (float)$order['details']['BT']->order_payment;
            if ($payment_price > 0) {
                $shopping_cart_items[] = $this->getPaymentFee($order, $payment_price, $currency_code_3);
            }
        }

        // Coupons: Ordering its position
        if (!is_null($shopping_cart_items_prev)) {
            $shopping_cart_items[] = $shopping_cart_items_prev;
        }
        return $shopping_cart_items;
    }

    /**
     * Create the order request object
     *
     * @param string $order_id
     * @param Money $amount
     * @param mixed $method
     * @param CustomerDetails $customer
     * @param CustomerDetails $delivery
     * @param PluginDetails $plugin_details
     * @param PaymentOptions $payment_options
     * @param GatewayInfoInterface|null $gateway_info
     * @param array $cart_items
     * @param string|null $transaction_type
     * @param int|null $days_active
     *
     * @return OrderRequest
     * @since 4.0
     */
    public function createOrderRequest(
        string $order_id,
        Money $amount,
        mixed $method,
        CustomerDetails $customer,
        CustomerDetails $delivery,
        PluginDetails $plugin_details,
        PaymentOptions $payment_options,
        GatewayInfoInterface|null $gateway_info,
        string|null $transaction_type,
        array $cart_items,
        int|null $days_active
    ): OrderRequest {
        $order = new OrderRequest();
        $order->addType($transaction_type ?? 'redirect');
        $order->addOrderId($order_id);
        $order->addDescriptionText('Order #' . $order_id);
        $order->addMoney($amount);
        $order->addGatewayCode(strtoupper($method->multisafepay_gateway) ?? '');
        $order->addCustomer($customer);
        $order->addDelivery($delivery);
        $order->addPluginDetails($plugin_details);
        if (!is_null($days_active) && ($days_active > 0)) {
            $order->addDaysActive($days_active);
        }
        $order->addPaymentOptions($payment_options);
        if (!is_null($gateway_info)) {
            $order->addGatewayInfo($gateway_info);
        }
        if (!empty($cart_items)) {
            $order->addShoppingCart(new ShoppingCart($cart_items));
        }
        return $order;
    }

    /**
     * Returns a Money object used to build the order request object
     * (taking the prices of the shopping cart)
     *
     * @param float $amount
     * @param string $currency_code
     *
     * @return Money object
     * @since 4.0
     */
    public function getMoneyObject(float $amount, string $currency_code): Money
    {
        return new Money(round($amount * 100, 2), $currency_code);
    }

    /**
     * Get Shipping details to be used in OrderRequest transaction
     *
     * @param array $order
     * @param string $currency_code_3
     * @param float $shipping_price
     *
     * @return ShippingItem
     * @since 4.0
     */
    public function getShipping(array $order, float $shipping_price, string $currency_code_3): ShippingItem
    {
        $shipping_tax = (float)$order['details']['ST']->order_shipment_tax;

        $shipping_tax_percentage = 0.00;
        if (($shipping_tax > 0) && $shipping_price > 0) {
            $shipping_tax_percentage = round($shipping_tax / $shipping_price, 2) * 100.00;
        }

        return $this->getShippingItemObject(
            $shipping_price,
            $currency_code_3,
            'Shipping Fee',
            1,
            'msp-shipping',
            $shipping_tax_percentage,
            'Shipping Fee for Order #' . $order['details']['BT']->order_number
        );
    }

    /**
     * Get Payment Fee to be used in OrderRequest transaction
     *
     * @param array $order
     * @param float $payment_price
     * @param string $currency_code_3
     *
     * @return CartItem
     * @since 4.0
     */
    public function getPaymentFee(array $order, float $payment_price, string $currency_code_3): CartItem
    {
        $payment_tax = (float)$order['details']['BT']->order_payment_tax;

        $payment_tax_percentage = 0.00;
        if ($payment_tax > 0) {
            $payment_tax_percentage = round($payment_tax / $payment_price, 2) * 100.00;
        }

        return $this->getCartItemObject(
            $payment_price,
            $currency_code_3,
            'Payment Fee',
            1,
            'PaymentFee',
            $payment_tax_percentage,
            'Payment Fee for Order #' . $order['details']['BT']->order_number
        );
    }

    /**
     * Returns shopping cart items array to be used in OrderRequest transaction
     *
     * @param int $item_key
     * @param object $item
     * @param string $currency_code_3
     * @param object $cart
     * @param float $coupon_percent
     *
     * @return CartItem
     * @since 4.0
     */
    public function getShoppingCartItems(int $item_key, object $item, string $currency_code_3, object $cart, float $coupon_percent): CartItem
    {
        $tax_found_on_vm = $product_weight_uom = $product_weight_value = false;
        $product_tax_percentage = $discounted_price = 0.00;

        // NOTE: Sometimes item price is zero but its corresponding tax rate is greater than zero, therefore tax percentage
        // needs to be taken from VirtueMart, because if is taken from the calculation between product tax and product price,
        // to avoid zero division error, tax rate will be 0.00, while the actual tax rate is greater.

        // Get tax percentage from VirtueMart
        if (!empty($cart->cartPrices[$item_key]['VatTax'])) {
            foreach ($cart->cartPrices[$item_key]['VatTax'] as $product_tax_array) {
                foreach ($product_tax_array as $product_key => $product_tax) {
                    if (($product_key === 1) && !empty($product_tax) && is_numeric($product_tax)) {
                        $product_tax_percentage = round((float)$product_tax);
                        $tax_found_on_vm = true;
                    }
                }
            }
        }

        // If tax is not found on VirtueMart, is calculated then
        if (!$tax_found_on_vm && ((float)$item->product_tax > 0) && ((float)$item->product_priceWithoutTax > 0)) {
            $product_tax_percentage = round((float)$item->product_tax / (float)$item->product_priceWithoutTax, 2) * 100.00;
        }

        if (!empty($item->product_weight_uom)) {
            $product_weight_uom = (string)$item->product_weight_uom;
        }
        if (!empty($item->product_weight) && is_numeric($item->product_weight)) {
            $product_weight_value = (float)$item->product_weight;
        }

        // Getting item name ready to be used in the request
        $item_name = $item->order_item_name;
        if (empty($item_name)) {
            $item_name = $item->product_name;
        }
        $item_name = htmlspecialchars_decode($item_name, ENT_QUOTES);

        // Get final price taking into account if is discounted or not
        $final_price = (float)$item->product_priceWithoutTax;
        if ($item->product_discountedPriceWithoutTax) {
            $discounted_price = (float)$item->product_discountedPriceWithoutTax;
        }

        if (($final_price > $discounted_price) && ($discounted_price > 0)) {
            $final_price = $discounted_price;
        }

        // If coupon is based on percentage, it is applied to the price of every item in the shopping cart
        if ($coupon_percent > 0) {
            $final_price -= $final_price * ($coupon_percent / 100.00);
            $item_name .= ' - (Coupon applied: ' . $coupon_percent . '%)';
        }

        return $this->getCartItemObject(
            $final_price,
            $currency_code_3,
            $item_name,
            $item->product_quantity,
            (string)$item->virtuemart_product_id,
            $product_tax_percentage,
            $this->stripDescription($item->product_desc ?: $item->product_s_desc, 200),
            $product_weight_uom,
            $product_weight_value
        );
    }

    /**
     * Get Coupon to be used in OrderRequest transaction
     *
     * @param array $order
     * @param string $currency_code_3
     *
     * @return array
     * @since 4.0
     */
    public function getCoupon(array $order, string $currency_code_3): array
    {
        $coupon_percent_value = 0.00;
        $coupon_is_percent = $coupon_details = false;

        if ($order['details']['BT']->coupon_code) {
            $coupon_details = CouponHelper::getCouponDetails($order['details']['BT']->coupon_code);
        }

        if ($coupon_details) {
            $coupon_class = (string)$coupon_details->percent_or_total;
            // Coupon is percent
            if ($coupon_class === 'percent') {
                $coupon_percent_value = (float)$coupon_details->coupon_value;
                // Just in case coupon_value is forced to 0 by type casting because an odd formatted value
                if ($coupon_percent_value > 0) {
                    $coupon_is_percent = true;
                }
            }
        }

        $coupon_type_total_object = null;
        if (!$coupon_is_percent) {
            $coupon_name = 'Coupon';
            $coupon_type_total_object = $this->getCartItemObject(
                (float)$order['details']['BT']->coupon_discount,
                $currency_code_3,
                $coupon_name,
                1,
                $coupon_name,
                0.00,
                'Coupon for Order #' . $order['details']['BT']->order_number
            );
        }
        return[$coupon_type_total_object, $coupon_percent_value];
    }

    /**
     * Returns an Address object used to build the order request object
     *
     * @param mixed $order_details
     * @param string $state
     * @param string $country
     *
     * @return Address object
     * @since 4.0
     */
    private function createAddress(mixed $order_details, string $state, string $country): Address
    {
        $parsed_address = (new AddressParser())->parse(
            $order_details->address_1 ?? '',
            $order_details->address_2 ?? ''
        );
        $house_number = preg_replace('/[^0-9.]/', '', $parsed_address[1]);

        return (new Address())
            ->addStreetName($parsed_address[0] ?? '')
            ->addStreetNameAdditional($order_details->address_2 ?? '')
            ->addHouseNumber($house_number ?? '')
            ->addZipCode($order_details->zip ?? '')
            ->addCity($order_details->city ?? '')
            ->addState($state ?: '')
            ->addCountryCode($country ?: '');
    }

    /**
     * Returns a CartItem object used to build the order request object
     *
     * @param float $price
     * @param string $currency_code
     * @param string $name
     * @param int $quantity
     * @param string $merchant_item_id
     * @param float $tax_rate
     * @param string $description
     * @param string|bool $weight_unit
     * @param float|bool $weight_value
     *
     * @return CartItem object
     * @since 4.0
     */
    public function getCartItemObject(
        float $price,
        string $currency_code,
        string $name,
        int $quantity,
        string $merchant_item_id,
        float $tax_rate,
        string $description = '',
        string|bool $weight_unit = false,
        float|bool $weight_value = false
    ): CartItem {
        $unit_price = $this->getMoneyObject($price, $currency_code);

        $cart_item = new CartItem();
        $cart_item->addName($name);
        $cart_item->addUnitPrice($unit_price);
        $cart_item->addQuantity($quantity);
        $cart_item->addMerchantItemId($merchant_item_id);
        $cart_item->addTaxRate($tax_rate);
        $cart_item->addDescription($description);
        if ($weight_unit && $weight_value) {
            $cart_item_weight = $this->getWeightObject((string)$weight_unit, (float)$weight_value);
            $cart_item->addWeight($cart_item_weight);
        }
        return $cart_item;
    }

    /**
     * Returns a Weight object used to build the order request object
     *
     * @param string $weight_unit
     * @param float $weight_value
     *
     * @return Weight object
     * @since 4.0
     */
    private function getWeightObject(string $weight_unit, float $weight_value): Weight
    {
        return new Weight(strtoupper($weight_unit), $weight_value);
    }

    /**
     * Returns a ShippingItem object used to build the order request object
     *
     * @param float $price
     * @param string $currency_code
     * @param string $name
     * @param int $quantity
     * @param string $merchant_item_id
     * @param float $tax_rate
     * @param string $description
     *
     * @return ShippingItem
     * @since 4.0
     */
    public function getShippingItemObject(
        float $price,
        string $currency_code,
        string $name,
        int $quantity,
        string $merchant_item_id,
        float $tax_rate,
        string $description = ''
    ): ShippingItem {
        $unit_price = $this->getMoneyObject($price, $currency_code);

        return (new ShippingItem())
            ->addName($name)
            ->addUnitPrice($unit_price)
            ->addQuantity($quantity)
            ->addMerchantItemId($merchant_item_id)
            ->addTaxRate($tax_rate)
            ->addDescription($description);
    }

    /**
     * Send an update about the Shipped status of an order
     *
     * @param string $order_number
     * @param object $method
     * @param array $data
     * @param string $status
     *
     * @return bool
     * @since 4.0
     */
    public function changeOrderStatusTo(string $order_number, object $method, array $data, string $status = ''): bool
    {
        try {
            $sdk = $this->getSdkObject($method);
            $transaction_manager = $sdk->getTransactionManager();
            $update_order = new UpdateRequest();
            $update_order->addId($order_number);
            if (!empty($data)) {
                $update_order->addData($data);
            } else {
                $update_order->addStatus($status);
            }
            $transaction_manager->update($order_number, $update_order);
        } catch (ApiException | ClientExceptionInterface $e) {
            JLog::add($e->getMessage(), JLog::ERROR, 'com_virtuemart');
            vmError($e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * @param string $order_id
     * @param Money $amount
     * @param object $method
     *
     * @return bool
     * @since 4.0
     */
    public function getRefundObject(string $order_id, Money $amount, object $method): bool
    {
        try {
            $sdk = $this->getSdkObject($method);
            $transaction_manager = $sdk->getTransactionManager();
            $transaction = $transaction_manager->get($order_id);
            if ($transaction->getStatus() === 'refunded') {
                // Therefore, the transaction has already been
                // refunded from the MultiSafepay dashboard
                return true;
            }
            $refund_request = (new RefundRequest())->addMoney($amount);
            $transaction_manager->refund($transaction, $refund_request);
        } catch (ApiException | ClientExceptionInterface $e) {
            JLog::add($e->getMessage(), JLog::ERROR, 'com_virtuemart');
            vmError($e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Get the referrer of the client
     *
     * @return string
     * @since 4.0
     */
    private function getReferrer(): string
    {
        $referrer = '';
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $referrer = htmlspecialchars($_SERVER['HTTP_REFERER']);
        }
        return $referrer;
    }

    /**
     * Get the forwarded IP address of the client
     *
     * @return string
     * @since 4.0
     */
    private function getIpForwarded(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            foreach ($ips as $ip) {
                $filtered_ip = filter_var(trim($ip), FILTER_VALIDATE_IP);
                if ($filtered_ip !== false) {
                    return $filtered_ip;
                }
            }
        }
        return '';
    }

    /**
     * Get the IP address of the client
     *
     * @return string
     * @throws Exception
     * @since 4.0
     */
    public function getIpAddress(): string
    {
        $ip_address = JFactory::getApplication()->input->server->get('REMOTE_ADDR', '');
        if (empty($ip_address)) {
            $ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP);
        }

        if ((string)$ip_address === '1') {
            $ip_address = '127.0.0.1';
        }
        return $ip_address;
    }

    /**
     * Get the user agent of the client
     *
     * @return string
     * @since 4.0
     */
    private function getUserAgent(): string
    {
        $user_agent = JBrowser::getInstance()->getAgentString();
        if (empty($user_agent)) {
            $user_agent = htmlspecialchars($_SERVER['HTTP_USER_AGENT']);
        }
        return $user_agent;
    }

    /**
     * @param string $description
     * @param int $limit
     *
     * @return string
     * @since 4.0
     */
    private function stripDescription(string $description, int $limit = 150): string
    {
        if (strlen($description) > $limit) {
            $description = substr($description, 0, strrpos(substr($description, 0, ($limit - 4)), ' ')) . ' ...';
        }
        return $description;
    }

    /**
     * @param object $order_details
     * @param SiteApplication|null $app
     *
     * @return string
     * @since 4.0
     */
    private function getUserIdentity(object $order_details, SiteApplication|null $app): string
    {
        $user_identity = '0';
        if ($order_details->virtuemart_user_id) {
            $user_identity = (string)$order_details->virtuemart_user_id;
        }

        if (empty($user_identity)) {
            if (!is_null($app)) {
                $user_identity = (string)$app->getIdentity()->get('id');
            } else {
                $user_identity = (string)JFactory::getUser()->id;
            }
        }
        return $user_identity;
    }

    /**
     * Gets the transaction from notification
     *
     * @param string|bool $body
     *
     * @return TransactionResponse|bool
     * @since 4.0
     */
    public function getTransactionFromNotification(string|bool $body): TransactionResponse|bool
    {
        if (!empty($body)) {
            try {
                return new TransactionResponse(json_decode($body, true, 512, JSON_THROW_ON_ERROR), $body);
            } catch (ApiException | Exception) {
                return false;
            }
        }
        return false;
    }

    /**
     * @param $paymentmethod_id
     *
     * @return ?string
     * @throws Exception
     * @since 4.0
     */
    public function getSelectedIssuerBank($paymentmethod_id): ?string
    {
        $session_params = $this->getMultiSafepayIdealFromSession();
        if (!empty($session_params)) {
            $var = 'multisafepay_ideal_bank_selected_' . $paymentmethod_id;
            return $session_params->$var;
        }
        return null;
    }

    /**
     * @param array $data
     *
     * @throws Exception
     * @since version
     */
    public function setMultiSafepayIdealIntoSession(array $data): void
    {
        $app = JFactory::getApplication();
        if (!is_null($app)) {
            try {
                $app->getSession()->set('MultiSafepayIdeal', json_encode($data, JSON_THROW_ON_ERROR), 'vm');
            } catch (Exception $e) {
                JLog::add($e->getMessage(), JLog::ERROR, 'com_virtuemart');
            }
        }
    }

    /**
     * @return mixed
     * @throws Exception
     * @since 4.0
     */
    public function getMultiSafepayIdealFromSession(): mixed
    {
        $app = JFactory::getApplication();
        if (!is_null($app)) {
            $data = $app->getSession()->get('MultiSafepayIdeal', 0, 'vm');
        }
        if (empty($data)) {
            return null;
        }

        try {
            return json_decode($data, false, 512, JSON_THROW_ON_ERROR);
        } catch (Exception $e) {
            JLog::add($e->getMessage(), JLog::ERROR, 'com_virtuemart');
        }
        return null;
    }

    /**
     * Instance of the Joomla language
     *
     * @param object|null $app
     * @return Language
     *
     * @since 4.0
     */
    public function getLanguageObject(object $app = null): Language
    {
        if (!is_null($app)) {
            $language = $app->getLanguage();
        } else {
            $language = JFactory::getLanguage();
        }
        return $language;
    }
}
