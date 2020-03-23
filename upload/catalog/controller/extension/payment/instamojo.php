<?php

require __DIR__ . "/lib/Instamojo.php";
require __DIR__ . "/lib/Validator.php";

class ControllerExtensionPaymentInstamojo extends Controller 
{
    private $logger;
    private $validator = null;
    
    private const DEFAULT_CURRENCY = 'INR';
    private const PURPOSE_FIRLD_PREFIX = 'Order-';

    public function __construct($arg) 
    {
        $this->logger = new Log('imojo.log');
        parent::__construct($arg);
        $this->validator = new Validator();
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
                $method_data['errors'] = $response['response'];
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
                    $order_id = $response['response']->title;
                    $order_id = explode('-', $order_id)[1];
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
        $order = $this->model_checkout_order->getOrder($orderId);
        
        try {
            $api_data['buyer_name'] = $this->encodeStringData(trim($order['payment_firstname'] . ' ' . $order['payment_lastname'], ENT_QUOTES), 20);
            $api_data['email'] = substr($order['email'], 0, 75);
            $api_data['phone'] = $this->encodeStringData($order['telephone'], 20);
            $api_data['amount'] = $this->currency->format($order['total'], $order['currency_code'], false, false);
            $api_data['redirect_url'] = $this->url->link('extension/payment/instamojo/confirm');
            $api_data['purpose'] = self::PURPOSE_FIRLD_PREFIX . $orderId;
            $api_data['send_email'] = 'True';
            $api_data['send_sms'] = 'True';
            $api_data['allow_repeated_payments'] = 'False';

            $this->validator->set_validation_type(__FUNCTION__);
            if ($this->validator->validate([], $api_data)) {
                $this->logger->write("Data sent for creating order : " . print_r($api_data, true));
                $response = $this->getInstamojoObject()->createPaymentRequest($api_data);
                $this->logger->write("Response from server on creating payment request" . print_r($response, true));
                if (isset($response->id)) {
                    $this->session->data['payment_request_id'] = $response->id;
                    return array('result' => 'success', 'redirect' => $response->longurl);
                }
            }
            return array('result' => 'error', 'response' => $this->validator->get_validation_errors());
        } catch (CurlException $e) {
            $this->handleCurlException($e);
        } catch (ValidationException $e) {
            $this->handleValidationException($e);
        } catch (Exception $e) {
            $this->handleException($e);
        }        
    }
    
    public function getPaymentDetails($paymentId) 
    {       
        $this->logger->write("Getting Payment detail for payment id: $paymentId");
        try {
            $this->validator->set_validation_type(__FUNCTION__);
            if ($this->validator->validate(['payment_id' => $paymentId])) {
                $this->logger->write("Data sent for getting payment detail " . $paymentId);
                $response = $this->getInstamojoObject()->getPaymentDetail($paymentId);
                $this->logger->write("Response from server on getting payment detail" . print_r($response, true));
                if (isset($response->id)) {
                    return array('status' => 'success', 'response' => $response);
                }
                return array('status' => 'error', 'response' => $response);
            }
            return array('status' => 'error', 'response' => $this->validator->get_validation_errors());
        } catch (CurlException $e) {
            $this->handleCurlException($e);
        } catch (ValidationException $e) {
            $this->handleValidationException($e);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    public function createRefund($payment_id, $trasnaction_id, $refund_amount, $refund_type, $refund_reason)
    {
        $this->logger->write("Creating Refund for payment id: $payment_id");
        try {
            $api_data['transaction_id'] = $trasnaction_id;
            $api_data['refund_amount'] = $refund_amount;
            $api_data['type'] = $this->encodeStringData($refund_type, 3);
            $api_data['body'] = $this->encodeStringData($refund_reason, 100);

            $this->validator->set_validation_type(__FUNCTION__);
            if ($this->validator->validate(['payment_id' => $payment_id], $api_data)) {
                $this->logger->write("Data sent for creating refund " . print_r($api_data, true));
                $response = $this->getInstamojoObject()->createRefund($payment_id, $api_data);
                $this->logger->write("Response from server on creating refund" . print_r($response, true));
                if (isset($response->success) && $response->success == true) {
                    return array('status' => 'success', 'response' => $response);
                }
                return array('status' => 'error', 'response' => $response);
            }
            return array('status' => 'error', 'response' => $this->validator->get_validation_errors());
        } catch (CurlException $e) {
            $this->handleCurlException($e);
        } catch (ValidationException $e) {
            $this->handleValidationException($e);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    public function getPaymentList($page = 1, $limit = 10, $payment_id = '', $buyer_name = '', $seller_name = '', $payout = '', $product_slug = '', $order_id = '', $min_created_at = '', $max_created_at = '', $min_updated_at = '', $max_updated_at = '')
    {
        $this->logger->write("Getting Payments list for payment_id : $payment_id, buyer_name : $buyer_name, seller_name : $seller_name, payout : $payout, product_slug : $product_slug, order_id : $order_id, min_created_at : $min_created_at, max_created_at : $max_created_at, min_updated_at : $min_updated_at, max_updated_at : $max_updated_at");
        try {
            $query_string['page'] = $page;
            $query_string['limit'] = $limit;
            $query_string['id'] = $this->encodeStringData($payment_id, 20);
            $query_string['buyer'] = $this->encodeStringData($buyer_name, 100);
            $query_string['seller'] = $this->encodeStringData($seller_name, 100);
            $query_string['payout'] = $this->encodeStringData($payout, 20);
            $query_string['product'] = $this->encodeStringData($product_slug, 100);
            $query_string['order_id'] = $this->encodeStringData($order_id, 100);
            $query_string['min_created_at'] = $this->encodeStringData($min_created_at, 24);
            $query_string['max_created_at'] = $this->encodeStringData($max_created_at, 24);
            $query_string['min_updated_at'] = $this->encodeStringData($min_updated_at, 24);
            $query_string['max_updated_at'] = $this->encodeStringData($max_updated_at, 24);
            
            $this->validator->set_validation_type(__FUNCTION__);
            if ($this->validator->validate($query_string)) {
                $this->logger->write("Data sent for getting payments list");
                $response = $this->getInstamojoObject()->getPaymentList($this->removeEmptyElementsFromArray($query_string));
                $this->logger->write("Response from server on getting payment list" . print_r($response, true));
                if (isset($response->payments)) {
                    return array('status' => 'success', 'response' => $response);
                }
                return array('status' => 'error', 'response' => $response);
            }
            return array('status' => 'error', 'response' => $this->validator->get_validation_errors());
        } catch (CurlException $e) {
            $this->handleCurlException($e);
        } catch (ValidationException $e) {
            $this->handleValidationException($e);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
            
    public function initiateGatewayOrder($orderId)
    {
        $this->logger->write("Initiate Gateway Orders");
        try {
            $order = $this->model_checkout_order->getOrder($orderId);
            $api_data['name'] = $this->encodeStringData(trim($order['payment_firstname'] . ' ' . $order['payment_lastname'], ENT_QUOTES), 20);
            $api_data['email'] = substr($order['email'], 0, 75);
            $api_data['phone'] = $this->encodeStringData($order['telephone'], 20);
            $api_data['currency'] = self::DEFAULT_CURRENCY;
            $api_data['amount'] = $this->currency->format($order['total'], $order['currency_code'], false, false);
            $api_data['transaction_id'] = self::PURPOSE_FIRLD_PREFIX . $orderId;
            $api_data['redirect_url'] = $this->url->link('extension/payment/instamojo/confirm');
            $this->validator->set_validation_type(__FUNCTION__);
            if ($this->validator->validate([], $api_data)) {
                $this->logger->write('Data sent for initiate gateway order' . print_r($api_data, true));
                $response = $this->getInstamojoObject()->initiateGatewayOrder($api_data);
                $this->logger->write("Response from server on initiate gateway order" . print_r($response, true));
                if (isset($response->order)) {
                    return array('status' => 'success', 'redirect' => $response->payment_options->payment_url);
                }
                return array('status' => 'error', 'response' => $response);
            }
            return array('status' => 'error', 'response' => $this->validator->get_validation_errors());
        } catch (CurlException $e) {
            $this->handleCurlException($e);
        } catch (ValidationException $e) {
            $this->handleValidationException($e);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    public function getGatewayOrder(string $id)
    {    
        $this->logger->write("Get Gateway Order for id: $id");
        try {
            $this->validator->set_validation_type(__FUNCTION__);
            if ($this->validator->validate(['id' => $id])) {
                $this->logger->write('Data sent for getting gateway order');
                $response = $this->getInstamojoObject()->getGatewayOrder($id);
                $this->logger->write("Response from server on getting gateway order" . print_r($response, true));
                if (isset($response->id)) {
                    return array('status' => 'success', 'response' => $response);
                }
                return array('status' => 'error', 'response' => $response);
            }
            return array('status' => 'error', 'response' => $this->validator->get_validation_errors());
        } catch (CurlException $e) {
            $this->handleCurlException($e);
        } catch (ValidationException $e) {
            $this->handleValidationException($e);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
            
    public function getCheckoutOptionsForGatewayOrder(string $id)
    {
        $this->logger->write("Get Checkout Options for Gateway Order for id: $id");
        try {
            $this->validator->set_validation_type(__FUNCTION__);
            if ($this->validator->validate(['id' => $id])) {
                $this->logger->write('Data sent for getting checkout options for gateway order');
                $response = $this->getInstamojoObject()->getCheckoutOptionForGatewayOrder($id);
                $this->logger->write("Response from server on getting checkout options for gateway order " . print_r($response, true));
                if (isset($response->payment_options)) {
                    return array('status' => 'success', 'response' => $response);
                }
                return array('status' => 'error', 'response' => $response);
            }
            return array('status' => 'error', 'response' => $this->validator->get_validation_errors());
        } catch (CurlException $e) {
            $this->handleCurlException($e);
        } catch (ValidationException $e) {
            $this->handleValidationException($e);
        } catch (Exception $e) {
            $this->handleException($e);
        }
    }
    
    private function encodeStringData($string_data, $max_length = null)
    {
        $string_data = html_entity_decode($string_data, ENT_QUOTES, 'UTF-8');

        if ($max_length == null) {
            return $string_data;
        }

        return substr($string_data, 0, $max_length);
    }

    private function removeEmptyElementsFromArray($data_array)
    {
        return array_filter($data_array, function($value) { return !is_null($value) && $value !== ''; });
    }
}
