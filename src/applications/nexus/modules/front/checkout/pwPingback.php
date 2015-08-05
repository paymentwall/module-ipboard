<?php
/**
 * @brief        pwPingback
 * @author        <a href='http://paymentwall.com'>Paymentwall, Inc.</a>
 * @copyright    (c) 2015 Paymentwall, Inc.
 * @package       Paymentwall
 * @subpackage    Nexus
 */

namespace IPS\nexus\modules\front\checkout;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    header((isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * ipn
 */
class _pwPingback extends \IPS\Dispatcher\Controller
{

    /**
     * Process Pingback
     *
     * @return    void
     */
    protected function manage()
    {
        try {
            $transaction = \IPS\nexus\Transaction::load(\IPS\Request::i()->id);
        } catch (\OutOfRangeException $e) {
            die('Transaction invalid!');
        }

        try {
            $response = $transaction->method->handlerPingback($transaction);
            die($response);
        } catch (\Exception $e) {
            die($e->getMessage());
        }
    }
}