<?php

defined('ABSPATH') || exit;

class WC_Payment_Gateway_Payxpert_Installment_X2 extends WC_Payment_Gateway_Payxpert_Installment {
    const ID = 'payxpert_installment_x2';

    public function __construct() {
        $this->xTimes        = 2;
        $this->method_title  = __('Installment payment x2 by PayXpert', 'payxpert');

        parent::__construct(self::ID);
    }
}
