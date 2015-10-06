<?php

namespace IPS\nexus\Gateway;

require_once \IPS\ROOT_PATH . "/system/3rd_party/paymentwall-php/lib/paymentwall.php";

/**
 * @brief        Paymentwall Gateway
 * @author       <a href='http://paymentwall.com'>Paymentwall Team.</a>
 * @copyright    (c) 2015 Paymentwall.
 * @license      MIT
 * @package      IPS Social Suite
 * @subpackage   Nexus
 * @version      1.0.0
 */

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    header((isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * Paymentwall Gateway
 */
class _Paymentwall extends \IPS\nexus\Gateway
{
    /* !Features (Each gateway will override) */

    const SUPPORTS_REFUNDS = false;
    const SUPPORTS_PARTIAL_REFUNDS = false;
    const DEFAULT_PINGBACK_RESPONSE = 'OK';

    /**
     * Can store cards?
     *
     * @return    bool
     */
    public function canStoreCards()
    {
        return FALSE;
    }

    /**
     * Admin can manually charge using this gateway?
     *
     * @return    bool
     */
    public function canAdminCharge()
    {
        $settings = json_decode($this->settings, TRUE);
        return ($settings['method'] === 'direct');
    }

    /* !Payment Gateway */

    /**
     * Authorize
     *
     * @param    \IPS\nexus\Transaction $transaction Transaction
     * @param    array|\IPS\nexus\Customer\CreditCard $values Values from form OR a stored card object if this gateway supports them
     * @param    \IPS\nexus\Fraud\MaxMind\Request|NULL $maxMind *If* MaxMind is enabled, the request object will be passed here so gateway can additional data before request is made
     * @return    \IPS\DateTime|NULL        Auth is valid until or NULL to indicate auth is good forever
     * @throws    \LogicException            Message will be displayed to user
     */
    public function auth(\IPS\nexus\Transaction $transaction, $values, \IPS\nexus\Fraud\MaxMind\Request $maxMind = NULL)
    {
        // Change order status to Waiting
        // Notice: When change status to PENDING, the site appears some errors about the template
        //         Call to undefined method IPS\Theme\class_nexus_admin_transactions::pend()
        $transaction->status = \IPS\nexus\Transaction::STATUS_WAITING;
        $extra = $transaction->extra;
        $extra['history'][] = array('s' => \IPS\nexus\Transaction::STATUS_WAITING);
        $transaction->extra = $extra;
        $transaction->save();

        // In case: When a guest checks out they will be prompted to create an account,
        //          The account will not be created until payment has been authorised
        // So, we create a new account to get member_id before create the widget
        // After checkout, the customer needs re-login to check order status
        $this->registerMember($transaction->invoice);

        $settings = $this->getSettings();
        self::initPaymentwall($settings['project_key'], $settings['secret_key']);

        $widget = new \Paymentwall_Widget(
            ($transaction->invoice->member->member_id
                ? $transaction->invoice->member->member_id
                : $_SERVER['REMOTE_ADDR']),  // id of the end-user who's making the payment
            $settings['widget_code'],        // widget code, e.g. p1; can be picked inside of your merchant account
            array(                           // product details for Flexible Widget Call. To let users select the product on Paymentwall's end, leave this array empty
                new \Paymentwall_Product(
                    $transaction->invoice->id,                        // id of the product in your system
                    $transaction->amount->amount,                    // price
                    $transaction->currency,        // currency code
                    'Invoice #' . $transaction->invoice->id,                            // product name
                    \Paymentwall_Product::TYPE_FIXED            // this is a time-based product; for one-time products, use Paymentwall_Product::TYPE_FIXED and omit the following 3 array elements
                )
            ),
            array_merge(
                array(
                    'test_mode' => (int)$settings['test_mode'],
                    'success_url' => (trim($settings['success_url']) != '')
                        ? trim($settings['success_url'])
                        : (string)\IPS\Http\Url::internal(
                            'app=nexus&module=clients&controller=invoices&id=' . $transaction->invoice->id,
                            'front',
                            'clientsinvoice',
                            array(),
                            \IPS\Settings::i()->nexus_https
                        ),
                    'id' => $transaction->id,
                    'integration_module' => 'ipboard',
                    'email' => $transaction->invoice->member->email,
                ),
                $this->prepareUserProfileData($transaction)
            )
        );

        echo $widget->getHtmlCode(array(
            'width' => '100%',
            'height' => '400px'
        ));
        die;
    }

    /**
     * Capture
     *
     * @param    \IPS\nexus\Transaction $transaction Transaction
     * @return bool
     */
    public function capture(\IPS\nexus\Transaction $transaction)
    {
        return true;
    }

    /**
     * Refund
     *
     * @param    \IPS\nexus\Transaction $transaction Transaction to be refunded
     * @param    float|NULL $amount Amount to refund (NULL for full amount - always in same currency as transaction)
     * @return    mixed  Gateway reference ID for refund, if applicable
     * @throws    \Exception
     */
    public function refund(\IPS\nexus\Transaction $transaction, $amount = NULL)
    {
        return isset($_GET['ref']) ? $_GET['ref'] : null;
    }

    protected static function initPaymentwall($project_key, $secret_key)
    {
        \Paymentwall_Config::getInstance()->set(array(
            'api_type' => \Paymentwall_Config::API_GOODS,
            'public_key' => $project_key,
            'private_key' => $secret_key
        ));
    }

    public function paymentScreen(\IPS\nexus\Invoice $invoice, \IPS\nexus\Money $amount)
    {
        return array();
    }

    public function handlerPingback(\IPS\nexus\Transaction $transaction)
    {
        $settings = $this->getSettings();
        self::initPaymentwall($settings['project_key'], $settings['secret_key']);

        $pingback = new \Paymentwall_Pingback($_GET, $_SERVER['REMOTE_ADDR']);
        //$result = array();
        if ($pingback->validate()) {
            $invoice = $transaction->get_invoice();

            if ($pingback->isDeliverable()) {

                // Call Delivery Confirmation API
                if ($settings['delivery']) {
                    // Delivery Confirmation
                    $delivery = new Paymentwall_GenerericApiObject('delivery');
                    $response = $delivery->post($this->prepareDeleveryData($transaction, $settings['test_mode']), $pingback->getReferenceId());
                }
                $transaction->approve();

            } else if ($pingback->isCancelable()) {
                if ($invoice->status == \IPS\nexus\Invoice::STATUS_PAID) {
                    $transaction->refund();
                    // Update invoice status
                    $invoice->markUnpaid(\IPS\nexus\Invoice::STATUS_CANCELED);
                }
            }
            return self::DEFAULT_PINGBACK_RESPONSE; // Paymentwall expects response to be OK, otherwise the pingback will be resent

        } else {
            return $pingback->getErrorSummary();
        }
    }

    /* !ACP Configuration */

    /**
     * Settings
     *
     * @param    \IPS\Helpers\Form $form The form
     * @return    void
     */
    public function settings(&$form)
    {
        $settings = json_decode($this->settings, TRUE);

        $form->add(new \IPS\Helpers\Form\Text('paymentwall_project_key', $settings['project_key'], TRUE));
        $form->add(new \IPS\Helpers\Form\Text('paymentwall_secret_key', $settings['secret_key'], TRUE));
        $form->add(new \IPS\Helpers\Form\Text('paymentwall_widget_code', $settings['widget_code'], TRUE));
        $form->add(new \IPS\Helpers\Form\Text('paymentwall_success_url', $settings['success_url'], FALSE));
        $form->add(new \IPS\Helpers\Form\YesNo('paymentwall_test_mode', $settings['test_mode'], FALSE, array(), NULL, NULL, NULL, 'paymentwall_test_mode'));
        $form->add(new \IPS\Helpers\Form\YesNo('paymentwall_delivery', $settings['delivery'], FALSE, array(), NULL, NULL, NULL, 'paymentwall_delivery'));
    }

    /**
     * Test Settings
     *
     * @param    array $settings Settings
     * @return    array
     * @throws    \InvalidArgumentException
     */
    public function testSettings($settings)
    {
        if (trim($settings['project_key']) == '') {
            throw new \LogicException('Project key is required');
        }
        if (trim($settings['secret_key']) == '') {
            throw new \LogicException('Secret key is required');
        }
        if (trim($settings['widget_code']) == '') {
            throw new \LogicException('Widget code is required');
        }

        return $settings;
    }

    /**
     * @param \IPS\nexus\Transaction $transaction
     * @return array
     */
    private function prepareUserProfileData(\IPS\nexus\Transaction $transaction)
    {
        $billingAddress = $transaction->invoice->billaddress;
        $billingData = array();
        $member = $transaction->member;

        if ($billingAddress) {
            $billingData = array(
                'customer[city]' => $billingAddress->city,
                'customer[state]' => $billingAddress->region,
                'customer[address]' => implode("\n", $billingAddress->addressLines),
                'customer[country]' => $billingAddress->country,
                'customer[zip]' => $billingAddress->postalCode,
            );
        }

        return array_merge(
            array(
                'customer[username]' => $member->name,
                'customer[firstname]' => $member->cm_first_name,
                'customer[lastname]' => $member->cm_last_name,
                'history[membership]' => $member->member_group_id,
                'history[registration_date]' => $member->joined->getTimestamp(),
                'history[registration_email]' => $member->email,
                'history[registration_age]' => $member->age(),
            ),
            $billingData
        );
    }

    /**
     * @param \IPS\nexus\Transaction $transaction
     * @param bool $isTest
     * @return array
     */
    private function prepareDeleveryData(\IPS\nexus\Transaction $transaction, $isTest = false, $ref)
    {
        $shippingAddress = $transaction->invoice->shipaddress;
        $shippingData = array();

        if ($shippingAddress) {
            $shippingData = array(
                'shipping_address[country]' => $shippingAddress->country,
                'shipping_address[city]' => $shippingAddress->city,
                'shipping_address[zip]' => $shippingAddress->postalCode,
                'shipping_address[state]' => $shippingAddress->region,
                'shipping_address[street]' => implode("\n", $shippingAddress->addressLines),
            );
        }

        return array_merge(
            array(
                'payment_id' => $ref,
                'type' => 'digital',
                'status' => 'delivered',
                'estimated_delivery_datetime' => date('Y/m/d H:i:s'),
                'estimated_update_datetime' => date('Y/m/d H:i:s'),
                'is_test' => $isTest,
                'reason' => 'none',
                'refundable' => 'yes',
                'details' => 'Item will be delivered via email by ' . date('Y/m/d H:i:s'),
                'shipping_address[email]' => $transaction->member->email,
                'shipping_address[firstname]' => $transaction->member->cm_first_name,
                'shipping_address[lastname]' => $transaction->member->cm_last_name,
            ),
            $shippingData
        );
    }

    /**
     * @return mixed
     */
    public function getSettings()
    {
        return json_decode($this->settings, true);
    }

    /**
     * @param \IPS\nexus\Invoice $invoice
     */
    private function registerMember(\IPS\nexus\Invoice &$invoice)
    {
        // Create the member account if this was a guest
        if (!$invoice->member->member_id and $invoice->guest_data) {
            $profileFields = $invoice->guest_data['profileFields'];

            $memberToSave = new \IPS\nexus\Customer;
            foreach ($invoice->guest_data['member'] as $k => $v) {
                $memberToSave->_data[$k] = $v;
                $memberToSave->changed[$k] = $v;
            }
            $memberToSave->save();
            $invoice->member = $memberToSave;
            $invoice->guest_data = NULL;
            $invoice->save();

            // If we've entered an address during checkout, save it
            if ($invoice->billaddress !== NULL) {
                $billing = new \IPS\nexus\Customer\Address;
                $billing->member = $invoice->member;
                $billing->address = $invoice->billaddress;
                $billing->primary_billing = 1;
                $billing->save();
            }

            if ($this->shipaddress !== NULL) {
                $shipping = new \IPS\nexus\Customer\Address;
                $shipping->member = $invoice->member;
                $shipping->address = $invoice->shipaddress;
                $shipping->primary_shipping = 1;
                $shipping->save();
            }

            $profileFields['member_id'] = $memberToSave->member_id;
            \IPS\Db::i()->replace('core_pfields_content', $profileFields);

            // Notify the incoming mail address
            if (\IPS\Settings::i()->new_reg_notify) {
                \IPS\Email::buildFromTemplate('core', 'registration_notify', array($memberToSave, $profileFields))->send(\IPS\Settings::i()->email_in);
            }

            // Update associated transactions
            \IPS\Db::i()->update('nexus_transactions', array('t_member' => $invoice->member->member_id), array('t_invoice=? AND t_member=0', $invoice->id));
        }
    }
}