<?php

defined('ABSPATH') || exit;

class WC_Payment_Gateway_Payxpert_Installment_X3 extends WC_Payment_Gateway_Payxpert_Installment {
    const ID = 'payxpert_installment_x3';

    public function __construct() {
        $this->xTimes        = 3;
        $this->method_title  = __('Installment payment x3 by PayXpert', 'payxpert');

        parent::__construct(self::ID);
    }
}
