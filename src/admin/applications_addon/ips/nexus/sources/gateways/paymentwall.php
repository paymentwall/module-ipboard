<?php
require_once "libs/Paymentwall/lib/paymentwall.php";

class gateway_paymentwall extends gatewayCore
{
    const DEFAULT_PINGBACK_RESPONSE = 'OK';
    const TRANSACTION_STATUS_OKAY = 'okay';
    const TRANSACTION_STATUS_HOLD = 'hold';
    const TRANSACTION_STATUS_FAIL = 'fail';
    const TRANSACTION_STATUS_WAIT = 'wait';


    protected static function initPaymentwall($app_key, $secret_key)
    {
        Paymentwall_Config::getInstance()->set(array(
            'api_type' => Paymentwall_Config::API_GOODS,
            'public_key' => $app_key,
            'private_key' => $secret_key
        ));
    }

    public function payScreen()
    {
        self::initPaymentwall($this->method['m_settings']['api_key'], $this->method['m_settings']['api_secretkey']);

        $productNames = array();
        foreach ($this->invoice->items as $item) {
            array_push($productNames, $item['quantity'] . ' x ' . $item['itemName']);
        }

        $widget = new Paymentwall_Widget(
            $this->member->member_id,                         // id of the end-user who's making the payment
            $this->method['m_settings']['widget_code'],       // widget code, e.g. p1; can be picked inside of your merchant account
            array(                                            // product details for Flexible Widget Call. To let users select the product on Paymentwall's end, leave this array empty
                new Paymentwall_Product(
                    $this->invoice->id,                       // id of the product in your system
                    $this->invoice->total,                    // price
                    $this->settings['nexus_currency'],        // currency code
                    implode(', ', $productNames),             // product name
                    Paymentwall_Product::TYPE_FIXED           // this is a time-based product; for one-time products, use Paymentwall_Product::TYPE_FIXED and omit the following 3 array elements
                )
            ),
            array_merge(
                array(
                    'test_mode' => (int)$this->method['m_settings']['test_mode'],
                    'success_url' => $this->method['m_settings']['success_url'],
                    'id' => $this->transaction['t_id'],
                ),
                $this->prepareUserProfileData($this->invoice->customer->data)
            )
        );
        echo $widget->getHtmlCode();
        die();
    }

    public function validatePayment()
    {
        $validate = array(
            'status' => self::TRANSACTION_STATUS_HOLD,
            'amount' => 0,
            'note' => "",
            'publicNote' => "",
            'gw_id' => 0,
            'extra' => null
        );

        self::initPaymentwall($this->method['m_settings']['api_key'], $this->method['m_settings']['api_secretkey']);
        $pingback = new Paymentwall_Pingback($_GET, $_SERVER['REMOTE_ADDR']);

        if ($pingback->validate()) {

            $invoice = new invoice($pingback->getProductId());
            $transaction = $this->DB->buildAndFetch(array(
                'select' => '*',
                'from' => 'nexus_transactions',
                'where' => "t_id={$pingback->getParameter('id')}"
            ));

            if (!$invoice || !$transaction) {
                die('Invoice or Transaction is invalid!');
            }

            $validate['amount'] = $transaction['t_amount'];
            $validate['gw_id'] = $pingback->getReferenceId();

            if ($pingback->isDeliverable()) {
                $validate['status'] = self::TRANSACTION_STATUS_OKAY;
                $validate['note'] = 'Transaction approved!, Transaction Id #' . $pingback->getReferenceId();

                $this->afterValidatePayment($validate, $invoice, $transaction);
            } else if ($pingback->isCancelable()) {
                // Not support
            }

            echo self::DEFAULT_PINGBACK_RESPONSE; // Paymentwall expects response to be OK, otherwise the pingback will be resent

        } else {
            echo $pingback->getErrorSummary();
        }

        die();
    }

    private function prepareUserProfileData($customer)
    {
        return array(
            'customer[city]' => $customer['cm_city'],
            'customer[state]' => $customer['cm_state'],
            'customer[address]' => $customer['cm_address_1'],
            'customer[country]' => $customer['cm_country'],
            'customer[zip]' => $customer['cm_zip'],
            'customer[username]' => $customer['name'] ? $customer['name'] : $customer['ip_address'],
            'customer[firstname]' => $customer['cm_first_name'],
            'customer[lastname]' => $customer['cm_last_name'],
            'email' => $customer['email'],
        );
    }

    /**
     * Action after validate payment
     * @param $validate
     * @param $invoice
     * @param $transaction
     */
    private function afterValidatePayment($validate, $invoice, $transaction)
    {
        // Did that make any sense?
        if (!$this->checkStatus($validate['status'])) {
            $validate['status'] = self::TRANSACTION_STATUS_FAIL;
            $validate['note'] = 'err_naughty_gateway';
        }

        // Save
        $this->updateTransaction($validate, $transaction);

        // Log
        $this->logTransaction('paid', $invoice, $validate, $transaction);

        //-----------------------------------------
        // Is the invoice paid now?
        //-----------------------------------------
        if ($this->isPaid($invoice)) {
            $invoice->markPaid();
        }

        //-----------------------------------------
        // Send email
        //-----------------------------------------
        $this->sendNotification($validate, $transaction, $invoice);
    }

    /**
     * @param $validate
     * @param $transaction
     */
    protected function updateTransaction($validate, $transaction)
    {
        $save = array(
            't_status' => $validate['status'],
            't_extra' => serialize($this->prepareExtraValues($transaction, $validate)),
            't_gw_id' => $validate['gw_id'],
            't_date' => time(),
        );
        if ($validate['amount']) {
            $save['t_amount'] = $validate['amount'];
        }
        $transaction = array_merge($transaction, $save);
        $this->DB->update('nexus_transactions', $save, "t_id={$transaction['t_id']}");
    }

    /**
     * @param $transaction
     * @param $validate
     * @return array
     */
    protected function prepareExtraValues($transaction, $validate)
    {
        $extra = unserialize($transaction['t_extra']);
        $extra['note'] = $validate['note'];
        $extra['publicNote'] = $validate['publicNote'];

        if (isset($validate['extra']) and is_array($validate['extra'])) {
            return array_merge($extra, $validate['extra']);
        }

        return array();
    }

    /**
     * @param $invoice
     * @return bool
     */
    protected function isPaid($invoice)
    {
        $paid = $this->DB->buildAndFetch(array(
            'select' => 'SUM( t_amount ) as paid',
            'from' => 'nexus_transactions',
            'where' => "t_status='" . self::TRANSACTION_STATUS_OKAY . "' AND t_invoice={$invoice->id}"
        ));

        return round(floatval($paid['paid']), 2) >= floatval($invoice->total);
    }

    /**
     * @param $validate
     * @param $transaction
     * @param $invoice
     */
    protected function sendNotification($validate, $transaction, $invoice)
    {
        switch ($validate['status']) {
            case self::TRANSACTION_STATUS_OKAY:
                $invoice->sendNotification('payment_received', 0, $transaction);
                break;

            case self::TRANSACTION_STATUS_HOLD:
                $invoice->sendNotification('payment_held', 0, $transaction);
                break;

            case self::TRANSACTION_STATUS_WAIT:
                $invoice->sendNotification('payment_waiting', $invoice->member, $validate['publicNote']);
                break;

            case self::TRANSACTION_STATUS_FAIL:
                $invoice->sendNotification('payment_failed', 0, $transaction);
                break;
        }
    }

    /**
     * @param string $type
     * @param object $invoice
     * @param array $validate
     * @param array $transaction
     */
    public function logTransaction($type, $invoice, $validate, $transaction)
    {
        if ($invoice->member) {
            customer::load($invoice->member)->logAction('transaction', array(
                'type' => $type,
                'status' => $validate['status'],
                'id' => $transaction['t_id'],
                'invoice_id' => $invoice->id,
                'title' => $invoice->title
            ));
        }
    }

    /**
     * @param string $status
     * @return bool
     */
    public function checkStatus($status)
    {
        return in_array(
            $status,
            array(
                self::TRANSACTION_STATUS_OKAY,
                self::TRANSACTION_STATUS_HOLD,
                self::TRANSACTION_STATUS_FAIL,
                self::TRANSACTION_STATUS_WAIT
            ));
    }
}
