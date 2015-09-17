<?php

require_once "libs/Paymentwall/lib/paymentwall.php";


class gateway_paymentwall extends gatewayCore
{
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

        $email = mysql_fetch_assoc($this->DB->query("SELECT email FROM members WHERE member_id = " . (int)$this->member->member_id));
        $email = $email['email'];

        $widget = new Paymentwall_Widget(
            $this->member->member_id,                        // id of the end-user who's making the payment
            $this->method['m_settings']['widget_code'],        // widget code, e.g. p1; can be picked inside of your merchant account
            array(                                            // product details for Flexible Widget Call. To let users select the product on Paymentwall's end, leave this array empty
                new Paymentwall_Product(
                    $this->invoice->id,                        // id of the product in your system
                    $this->invoice->total,                    // price
                    $this->settings['nexus_currency'],        // currency code
                    implode(', ', $productNames),                            // product name
                    Paymentwall_Product::TYPE_FIXED            // this is a time-based product; for one-time products, use Paymentwall_Product::TYPE_FIXED and omit the following 3 array elements
                )
            ),
            array_merge(
                array(
                    'test_mode' => (int)$this->method['m_settings']['test_mode'],
                    'success_url' => $this->method['m_settings']['success_url'],
                    'id' => $this->transaction['t_id'],
                    'email' => $email,
                ),
                $this->prepareUserProfileData($this->invoice->customer->data)
            )
        );
        echo $widget->getHtmlCode();
        die();
    }

    public function validatePayment()
    {
        self::initPaymentwall($this->method['m_settings']['api_key'], $this->method['m_settings']['api_secretkey']);

        $pingback = new Paymentwall_Pingback($_GET, $_SERVER['REMOTE_ADDR']);
        //$result = array();
        if ($pingback->validate()) {

            if ($pingback->isDeliverable()) {
                //$result = array( 'id' => $pingback->getParameter('id'), 'status' => 'okay', 'amount' => $pingback->getParameter('amount'), 'gw_id' => $pingback->getParameter('ref') );

                $this->DB->update("nexus_invoices",
                    array(
                        'i_status' => 'paid',
                        'i_paid' => time()
                    ),
                    'i_id = ' . (int)$pingback->getProductId());

                $this->DB->update("nexus_transactions",
                    array(
                        't_status' => 'okay',
                        't_extra' => 'a:2:{s:4:\"note\";N;s:10:\"publicNote\";N;}',
                        't_gw_id' => $pingback->getReferenceId()
                    ),
                    't_id = ' . (int)$pingback->getParameter('id'));
            } else if ($pingback->isCancelable()) {
                $this->DB->update("nexus_invoices",
                    array(
                        'i_status' => 'canc'
                    ),
                    'i_id = ' . (int)$pingback->getProductId());

                $this->DB->update("nexus_transactions",
                    array(
                        't_status' => 'fail',
                        't_extra' => 'a:2:{s:4:\"note\";s:19:\"err_naughty_gateway\";s:10:\"publicNote\";N;}',
                        't_gw_id' => $pingback->getReferenceId()
                    ),
                    't_id = ' . (int)$pingback->getParameter('id'));
            }
            echo 'OK'; // Paymentwall expects response to be OK, otherwise the pingback will be resent
            //$this->paidInformation($this->transaction['t_id'], $this->invoice->items);
        } else {
            echo $pingback->getErrorSummary();
        }
        die();
        //return $result;
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
        );
    }
}
