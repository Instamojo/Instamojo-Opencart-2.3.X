<?php

/**
 * Instamojo
 * used to manage Instamojo API calls
 * 
 */
include __DIR__ . DIRECTORY_SEPARATOR . "curl.php";
include __DIR__ . DIRECTORY_SEPARATOR . "ValidationException.php";

Class Instamojo 
{
    private $api_endpoint;
    private $auth_endpoint;
    private $auth_headers;
    private $access_token;
    private $client_id;
    private $client_secret;

    function __construct($client_id, $client_secret, $test_mode) 
    {
        $this->curl = new Curl();
        $this->curl->setCacert(__DIR__ . "/cacert.pem");
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;

        if ($test_mode)
            $this->api_endpoint = "https://test.instamojo.com/v2/";
        else
            $this->api_endpoint = "https://www.instamojo.com/v2/";
        if ($test_mode)
            $this->auth_endpoint = "https://test.instamojo.com/oauth2/token/";
        else
            $this->auth_endpoint = "https://www.instamojo.com/oauth2/token/";

        $this->getAccessToken();
    }

    public function getAccessToken() {
        $data = array();
        $data['client_id'] = $this->client_id;
        $data['client_secret'] = $this->client_secret;
        $data['scopes'] = "all";
        $data['grant_type'] = "client_credentials";
        
        $result = $this->curl->post($this->auth_endpoint, $data);
        if ($result) {
            $result = json_decode($result);
            if (isset($result->error)) {
                throw new ValidationException("The Authorization request failed with message '$result->error'", array("Payment Gateway Authorization Failed."), $result);
            } else
                $this->access_token = $result->access_token;
        }

        $this->auth_headers[] = "Authorization:Bearer $this->access_token";
    }

    public function createOrderPayment($data) 
    {
        $endpoint = $this->api_endpoint ."gateway/orders/";
        $result = $this->curl->post($endpoint,$data,array("headers"=>$this->auth_headers));
        $result = json_decode($result);
        if (isset($result->order)) {
            return $result;
        } else {
            $errors = array();
            if (isset($result->message))
                throw new ValidationException("Validation Error with message: $result->message", array($result->message), $result);

            foreach ($result as $k => $v) {
                if (is_array($v))
                    $errors[] = $v[0];
            }
            if ($errors)
                throw new ValidationException("Validation Error Occured with following Errors : ", $errors, $result);
        }
    }

    public function getOrderById($id) 
    {
        $endpoint = $this->api_endpoint . "gateway/orders/id:$id/";
        $result = $this->curl->get($endpoint, array("headers" => $this->auth_headers));

        $result = json_decode($result);
        if (isset($result->id) and $result->id)
            return $result;
        else
            throw new Exception("Unable to Fetch Payment Request id:'$id' Server Responds " . print_r($result, true));
    }

    public function getPaymentStatus($payment_id, $payments) 
    {
        foreach ($payments as $payment) {
            if ($payment->id == $payment_id) {
                return $payment->status;
            }
        }
    }
    
    public function createPaymentRequest($data)
    {
        $endpoint = $this->api_endpoint . "payment_requests/";
        $result = $this->curl->post($endpoint, $data, array("headers" => $this->auth_headers));
        
        return json_decode($result);
    } 
    
    public function getPaymentDetail($paymentId) 
    {
        $endpoint = $this->api_endpoint . "payments/$paymentId/";
        $result = $this->curl->get($endpoint,array("headers" => $this->auth_headers));
        
        return json_decode($result);         
    }
    
    public function getPaymentList($query_string) 
    {
        $endpoint = $this->api_endpoint . 'payments?' . http_build_query($query_string);
        $result = $this->curl->get($endpoint,array("headers" => $this->auth_headers));
        
        return json_decode($result);
    }
    
    public function createRefund($payment_id, $data) 
    {
        $endpoint = $this->api_endpoint . 'payments/' . $payment_id . '/refund/' ;
        $result = $this->curl->post($endpoint, $data, array("headers" => $this->auth_headers));
        
        return json_decode($result);
    }
    
    public function initiateGatewayOrder($data) 
    {
        $endpoint = $this->api_endpoint .'gateway/orders/';
        $result = $this->curl->post($endpoint, $data, array('headers' => $this->auth_headers));
        
        return json_decode($result);         
    }
    
    public function getGatewayOrder($id) 
    {
        $endpoint = $this->api_endpoint . 'gateway/orders/id:' . $id;
        $result = $this->curl->get($endpoint, array('headers' => $this->auth_headers));

        return json_decode($result);
    }
    
    public function getCheckoutOptionForGatewayOrder($id)
    {
        $endpoint = $this->api_endpoint . 'gateway/orders/' . $id . '/checkout-options/';
        $result = $this->curl->get($endpoint, array('headers' => $this->auth_headers));

        return json_decode($result);
    }
    
    public function checkPaymentStatus($payment_id, $response)  
    {
       if($response['status'] == 'success') {
            if($response['response']->id == $payment_id) {
                return $response['response']->status;
            }
        }
    }
    
    public function handleCurlException($e) 
    {
        $this->logger->write("An error occurred on line " . $e->getLine() . " with message " .  $e->getMessage());
        $this->displayError($e->getMessage());
    }
    
    public function handleException($e) 
    {
        $this->logger->write("An error occurred on line " . $e->getLine() . " with message " .  $e->getMessage());
        $this->displayError($e->getMessage());
    }
    
    public function handleValidationException($e) 
    {
        $this->logger->write("Validation Exception Occured with response ".print_r($e->getResponse(), true));
        $errors = '';
        foreach ($e->getErrors() as $error) {
            $errors .= $error;
        }
        $this->displayError($errors);
    }
    
    public function displayError($errors_html) 
    {
        $json = array(
            "result"=>"failure",
            "messages"=>$errors_html           
        );
        die(json_encode($json));
    }
}
