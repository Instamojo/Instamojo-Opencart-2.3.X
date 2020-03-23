<?php

class ValidationRules {
    
    private $validation_rules = [];

    public function __construct() 
    {
        $this->init_validation_rules();
    }
    
    private function init_validation_rules()
    {
        $this->validation_rules['paymentRequest'] = ['buyer_name' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => true],
                                                    'email' => [ 'DATA_TYPE'=> 'email', 'REQUIRED' => false],
                                                    'phone' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => false],
                                                    'amount' => [ 'DATA_TYPE'=> 'amount', 'REQUIRED' => true],
                                                    'redirect_url' => [ 'DATA_TYPE'=> 'url', 'REQUIRED' => true],                                                    
                                                    'purpose' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => true],
                                                    'send_email' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => true, 'DATA_IN' => ['True', 'False']],
                                                    'send_sms' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => true, 'DATA_IN' => ['True', 'False']],
                                                    'webhook' => [ 'DATA_TYPE'=> 'url', 'REQUIRED' => false],
                                                    'allow_repeated_payments' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => false, 'DATA_IN' => ['True', 'False']] ];

        $this->validation_rules['getPaymentDetails'] = ['payment_id' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => true]];
        
        $this->validation_rules['createRefund'] = ['type' => ['DATA_TYPE'=> 'string', 'REQUIRED' => true , 'DATA_IN' => ['RFD', 'TNR', 'QFL', 'QNR', 'EWN', 'TAN', 'PTH']],
                                                    'body' => ['DATA_TYPE'=> 'string', 'REQUIRED' => true],
                                                    'refund_amount' => ['DATA_TYPE' => 'amount', 'REQUIRED' => true],
                                                    'transaction_id' => ['DATA_TYPE'=> 'string', 'REQUIRED' => true],
                                                    'payment_id' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => true]];
        
        $this->validation_rules['getPaymentList'] = ['page' => ['DATA_TYPE'=> 'integer', 'REQUIRED' => true],
                                                    'limit' => ['DATA_TYPE'=> 'integer', 'REQUIRED' => true],
                                                    'id' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => false],
                                                    'buyer' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => false],
                                                    'seller' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => false],
                                                    'payout' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => false],
                                                    'product' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => false],
                                                    'order_id' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => false],
                                                    'min_created_at' => [ 'DATA_TYPE'=> 'datetime', 'REQUIRED' => false],
                                                    'max_created_at' => [ 'DATA_TYPE'=> 'datetime', 'REQUIRED' => false],
                                                    'min_updated_at' => [ 'DATA_TYPE'=> 'datetime', 'REQUIRED' => false],
                                                    'max_updated_at' => [ 'DATA_TYPE'=> 'datetime', 'REQUIRED' => false]];
  
        $this->validation_rules['getCheckoutOptionsForGatewayOrder'] = ['id' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => true]];
        
        $this->validation_rules['getGatewayOrder'] = ['id' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => true]];
        
        $this->validation_rules['initiateGatewayOrder'] = ['name' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => true],
                                                             'email' => [ 'DATA_TYPE'=> 'email', 'REQUIRED' => false],
                                                             'phone' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => false],
                                                             'amount' => [ 'DATA_TYPE'=> 'amount', 'REQUIRED' => true],
                                                             'transaction_id' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => true],
                                                             'currency' => [ 'DATA_TYPE'=> 'string', 'REQUIRED' => true, 'DATA_IN' => ['INR']],
                                                             'redirect_url' => [ 'DATA_TYPE'=> 'url', 'REQUIRED' => true]];
    }
    
    public function get_validation_rule($api_type)
    {
        if (array_key_exists($api_type, $this->validation_rules)) {
            
            return $this->validation_rules[$api_type];
        }
        
        return null;
    }
}