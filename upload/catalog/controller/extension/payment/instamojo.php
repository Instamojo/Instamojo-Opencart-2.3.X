<?php

require __DIR__ . "/lib/Instamojo.php";

class ControllerExtensionPaymentInstamojo extends Controller 
{
    private $logger;
    private $valid_refund_types = ['RFD', 'TNR', 'QFL', 'QNR', 'EWN', 'TAN', 'PTH'];
    
    public function __construct($arg) 
    {
        $this->logger = new Log('imojo.log');
        parent::__construct($arg);
    }

    private function getInstamojoObject() 
    {
        $client_id = $this->config->get('instamojo_client_id');
        $client_secret = $this->config->get('instamojo_client_secret');
        $testmode = $this->config->get('instamojo_testmode');

        $this->logger->write(sprintf("Client Id: %s | Client Secret: %s | TestMode : %s", substr($client_id, -4), substr($client_secret, -4), $testmode));
        return new Instamojo($client_id, $client_secret, $testmode);
    }

    public function start() 
    {
        $this->load->model('checkout/order');

        # if the phone is not valid update it in DB.
        if (($this->request->server['REQUEST_METHOD'] == 'POST')) {
            $order_id = $this->session->data['order_id'];
            $phone = $this->request->post['telephone'];
            $this->logger->write("Phone no updated to $phone for order id: " . $this->session->data['order_id']);
            $this->db->query("UPDATE " . DB_PREFIX . "order set telephone = '" . $this->db->escape($phone) . "' where order_id='$order_id'");
        }

        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        $this->logger->write("Step 2: Creating new Order with " . $this->session->data['order_id']);
         
        if ($order_info) {

            $response = $this->paymentRequest($this->session->data['order_id']);
             
            if ($response['result'] == 'success') {
                $method_data['action'] = $response['redirect'];
            } else {
                $method_data['errors'] = $response['messages'];
            }

            $method_data['telephone'] = $order_info['telephone'];
            $method_data['footer'] = $this->load->controller('common/footer');
            $method_data['header'] = $this->load->controller('common/header');
             
            if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/extension/payment/instamojo/instamojo_redirect.tpl')) {
                $this->response->setOutput($this->load->view($this->config->get('config_template') . '/template/payment/instamojo/instamojo_redirect.tpl', $method_data));
            } else {
                $this->response->setOutput($this->load->view("extension/payment/instamojo/instamojo_redirect.tpl", $method_data));
            }
        } else {
            $this->logger->write("Order information not found Quitting.");
            $this->response->redirect($this->url->link('checkout/checkout', '', 'SSL'));
        }
    }

    public function index() 
    {
        # make customer redirect to the payment/instamojo/start for avoiding problem releted to Journal2.6.x Quickcheckout
        $method_data['action'] = $this->config->get('config_url') . 'index.php';
        $this->logger->write("Action URL: " . $method_data['action']);
        $method_data['confirm'] = 'extension/payment/instamojo/start';
        $this->logger->write("Step 1: Redirecting to extension/payment/instamojo/start");
        return $this->load->view("extension/payment/instamojo/instamojo.tpl", $method_data);
    }

    public function confirm() 
    {
        if (isset($this->request->get['payment_id']) && isset($this->request->get['payment_request_id'])) {
            $payment_id = $this->request->get['payment_id'];
            $payment_request_id = $this->request->get['payment_request_id'];

            $this->logger->write("Callback called with payment ID: $payment_id and payment request ID : $payment_request_id ");

            if ($payment_request_id != $this->session->data['payment_request_id']) {
                $this->logger->write("Payment Request ID not matched  payment request stored in session (" . $this->session->data['payment_request_id'] . ") with Get Request ID $payment_request_id.");
                $this->response->redirect($this->config->get('config_url'));
            }

            try {
                $api = $this->getInstamojoObject();
                
                $response = $this->getPaymentDetails($payment_id);
                $payment_status = $api->checkPaymentStatus($payment_id, $response);
                
                $this->logger->write("Payment status for $payment_id is $payment_status");

                if ($payment_status == 1 OR $payment_status != 1) {
                    $this->logger->write("Response from server is $payment_status.");
                    $order_id = $response['payment_detail']->title;
                   
                    $this->logger->write("order id: " . $order_id);
                    $this->load->model('checkout/order');
                   
                    $order_info = $this->model_checkout_order->getOrder($order_id);
                    if ($order_info) {
                        if ($payment_status == 1) {
                            $this->logger->write("Payment for $payment_id was credited.");
                            $this->model_checkout_order->addOrderHistory($order_id, $this->config->get('instamojo_order_status_id'), "Payment successful for instamojo payment ID: $payment_id", true);
                            $this->response->redirect($this->url->link('checkout/success', '', 'SSL'));
                        } else if ($payment_status != 1) {
                            $this->logger->write("Payment for $payment_id failed.");
                            $this->model_checkout_order->addOrderHistory($order_id, 10, "Payment failed for instamojo payment ID: $payment_id", true);
                            $this->response->redirect($this->url->link('checkout/cart', '', 'SSL'));
                        }
                    } else {
                        $this->logger->write("Order not found with order id $order_id");
                    }
                }
            } catch (CurlException $e) {
                $this->logger->write($e);
            } catch (Exception $e) {
                $this->logger->write($e->getMessage());
                $this->logger->write("Payment for $payment_id was not credited.");
                $this->response->redirect($this->url->link('checkout/cart', '', 'SSL'));
            }
        } else {
            $this->logger->write("Callback called with no payment ID or payment_request Id.");
            $this->response->redirect($this->config->get('config_url'));
        }
    }

    public function paymentRequest($orderId) 
    {
        $this->logger->write("Creating Instamojo Order for order id: $orderId");
        
        $order_info = $this->model_checkout_order->getOrder($orderId);
        try {
            $api_data['amount'] = $this->currency->format($order_info['total'], $order_info['currency_code'], false, false);
            $api_data['purpose'] = $orderId;
            $api_data['buyer_name'] = substr(trim((html_entity_decode($order_info['payment_firstname'] . ' ' . $order_info['payment_lastname'], ENT_QUOTES, 'UTF-8'))), 0, 20);
            $api_data['email'] = substr($order_info['email'], 0, 75);
            $api_data['phone'] = substr(html_entity_decode($order_info['telephone'], ENT_QUOTES, 'UTF-8'), 0, 20);
            $api_data['redirect_url'] = $this->url->link('extension/payment/instamojo/confirm');
            $api_data['allow_repeated_payments'] = 'False';
            $api_data['send_email'] = 'False';
            $api_data['send_sms'] = 'False';

            $this->logger->write("Data Passed for creating Order : " . print_r($api_data, true));

            $api = $this->getInstamojoObject();
            $response = $api->createPaymentRequest($api_data);

            $this->logger->write("Response from Server" . print_r($response, true));

            if (isset($response->id)) {
                $this->session->data['payment_request_id'] = $response->id;
                return array('result' => 'success', 'redirect' => $response->longurl);
            } 
            return array('result' => 'failure', 'message' => $response->message);
                 
        } catch (CurlException $e) {
            $this->handlleCurlException($e);
        } catch (ValidationException $e) {
            $this->handlleValidationException($e);
        } catch (Exception $e) {
           $this->handlleException($e);
        }        
    }
    
    public function getPaymentDetails($paymentId) 
    {       
        $this->logger->write("Getting Payment detail for payment id: $paymentId");

        try {
            $api = $this->getInstamojoObject();        
            $this->logger->write("Data sent for getting payment detail ".$paymentId);
            
            $response = $api->getPaymentDetail($paymentId);
            $this->logger->write("Response from server on getting payment detail" . print_r($response, true));

            if (isset($response->id)) {
                return array('result' => 'success', 'payment_detail' => $response);
            }
            return array('result' => 'error', 'message' => $response->message);
        } catch (CurlException $e) {
            $this->handlleCurlException($e);
        } catch (ValidationException $e) {
            $this->handlleValidationException($e);
        } catch (Exception $e) {
           $this->handlleException($e);
        }
    }
    
    public function paymentsList($page = 1, $limit = 10, $payment_id = '', $buyer_name = '', $seller_name = '', $payout = '', $product_slug = '', $order_id = '', $min_created_at = '', $max_created_at = '', $min_updated_at = '', $max_updated_at = '') 
    {
        $this->logger->write("Getting Payments list for payment_id : $payment_id, buyer_name : $buyer_name, seller_name : $seller_name, payout : $payout, product_slug : $product_slug, order_id : $order_id, min_created_at : $min_created_at, max_created_at : $max_created_at, min_updated_at : $min_updated_at, max_updated_at : $max_updated_at");

        try {
            $api = $this->getInstamojoObject();        
            $this->logger->write("Data sent for getting payments list");
            
            $query_string['page'] = $page;
            $query_string['limit'] = $limit;
            $query_string['id'] = $this->encode_string_data($payment_id, 20);
            $query_string['buyer'] = $this->encode_string_data($buyer_name, 100);
            $query_string['seller'] = $this->encode_string_data($seller_name, 100);
            $query_string['payout'] = $this->encode_string_data($payout, 20);
            $query_string['product'] = $this->encode_string_data($product_slug, 100);
            $query_string['order_id'] = $this->encode_string_data($order_id, 100);
            $query_string['min_created_at'] = $this->encode_string_data($min_created_at, 24);
            $query_string['max_created_at'] = $this->encode_string_data($max_created_at, 24);
            $query_string['min_updated_at'] = $this->encode_string_data($min_updated_at, 24);
            $query_string['max_updated_at'] = $this->encode_string_data($max_updated_at, 24);
            
            $response = $api->getPaymentsList($this->remove_empty_elements_from_array($query_string));
            
            $this->logger->write("Response from server on getting payments list" . print_r($response, true));

            if (isset($response->payments)) {
                return array('result' => 'success', 'payment_list' => $response);
            }
            return array('result' => 'error', 'message' => $response->message);
            
        } catch (CurlException $e) {
            $this->handlleCurlException($e);
        } catch (ValidationException $e) {
            $this->handlleValidationException($e);
        } catch (Exception $e) {
           $this->handlleException($e);
        }
    }
    
    public function createRefund($payment_id, $trasnaction_id, $refund_amount, $refund_type, $refund_reason) 
    {    
        $this->logger->write("Creating Refund for payment id: $payment_id");

        try {
            if (!in_array($refund_type, $this->valid_refund_types)) {
               $this->handlleException('Invalid Refund Type :'.$refund_type);
            }
            $api = $this->getInstamojoObject();        
            
            $api_data['transaction_id'] = $trasnaction_id;
            $api_data['refund_amount'] = $refund_amount;
            $api_data['type'] = $this->encode_string_data($refund_type, 3);
            $api_data['body'] = $this->encode_string_data($refund_reason, 100);
            
            $this->logger->write("Data sent for creating refund ".print_r($api_data,true));
            
            $response = $api->createRefund($payment_id, $api_data);
            $this->logger->write("Response from server on creating refund".print_r($response,true));
            
            if (isset($response->success)) {
                if($response->success == 1) {
                    return array('result' => 'success', 'refund' => $response);
                }
            }

           return array('result' => 'error', 'message' => $response);

        } catch (CurlException $e) {
            $this->handlleCurlException($e);
        } catch (ValidationException $e) {
            $this->handlleValidationException($e);
        } catch (Exception $e) {
           $this->handlleException($e);
        }
    }
    
    private function encode_string_data($string_data, $max_length = null)
    {
        $string_data = html_entity_decode($string_data, ENT_QUOTES, 'UTF-8');

        if ($max_length == null) {
            return $string_data;
        }

        return substr($string_data, 0, $max_length);
    }

    private function remove_empty_elements_from_array($data_array)
    {
        return array_filter($data_array, function($value) { return !is_null($value) && $value !== ''; });
    }

}
