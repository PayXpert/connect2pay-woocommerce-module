<?php

defined('ABSPATH') || exit;

class WC_Payment_Gateway_Payxpert_Installment_X4 extends WC_Payment_Gateway_Payxpert_Installment {
    const ID = 'payxpert_installment_x4';

    public function __construct() {
        $this->xTimes        = 4;
        $this->method_title  = __('Installment payment x4 by PayXpert', 'payxpert');

        parent::__construct(self::ID);
    }
}
