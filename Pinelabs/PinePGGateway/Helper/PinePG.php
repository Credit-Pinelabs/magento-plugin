<?php

namespace Pinelabs\PinePGGateway\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Sales\Model\Order;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Store\Model\ScopeInterface;
use Pinelabs\PinePGGateway\Logger\Logger;



class PinePG extends AbstractHelper
{
    protected $session;
    protected $quote;
    protected $quoteManagement;
    protected $transactionBuilder;

    protected $orderRepository;
    protected $scopeConfig;

     protected $logger;
 

    public function __construct(
        Context $context,
        \Magento\Checkout\Model\Session $session,
        \Magento\Quote\Model\Quote $quote,
        \Magento\Quote\Model\QuoteManagement $quoteManagement,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        ScopeConfigInterface $scopeConfig,
        BuilderInterface $transactionBuilder,
        \Pinelabs\PinePGGateway\Logger\Logger $logger
    ) {
        $this->session = $session;
        $this->quote = $quote;
        $this->quoteManagement = $quoteManagement;
        $this->orderRepository = $orderRepository;
        $this->scopeConfig = $scopeConfig;
        $this->transactionBuilder = $transactionBuilder;
        $this->logger = $logger;
        parent::__construct($context);

        
    }


   

    public function cancelCurrentOrder($comment)
    {
        $order = $this->session->getLastRealOrder();
        if ($order->getId() && $order->getState() != Order::STATE_CANCELED) {
            $order->registerCancellation($comment)->save();
            return true;
        }
        return false;
    }

    public function restoreQuote()
    {
        return $this->session->restoreQuote();
    }

    public function getUrl($route, $params = [])
    {
        return $this->_getUrl($route, $params);
    }

    

    public function processRefund($orderId, $amount, $reason, $createTransaction = true, $updateOrderState = true)
{
    // Load the order using OrderRepositoryInterface
    $order = $this->orderRepository->get($orderId);

    // Retrieve the plural_order_id from the sales_order table
    $pluralOrderId = $order->getData('plural_order_id');

    if (!$pluralOrderId) {
        throw new \Magento\Framework\Exception\LocalizedException(
            __('Plural Order ID not found for order ID %1', $orderId)
        );
    }

    
   $env = $this->scopeConfig->getValue('payment/pinepgpaymentmethod/PayEnvironment', ScopeInterface::SCOPE_STORE);
  $url = ($env === 'LIVE')
        ? 'https://api.pluralpay.in/api/pay/v1/refunds/' . $pluralOrderId
        : 'https://ipg-apacuat.creditpluspinelabs.com/api/pay/v1/refunds/' . $pluralOrderId;

    $refundAmount = (int) round(((float) $amount) * 100);
    $merchantOrderReference = uniqid('', true) . '_magento_' . $order->getIncrementId();

    // Prepare the body for the refund request
    $body = json_encode([
        'merchant_order_reference' => $merchantOrderReference,
        'refund_amount' => ['value' => $refundAmount, 'currency' => 'INR'],
        'refund_reason' => $reason,
    ]);

    $access_token = $this->getAccessToken();

    $merchant_id = $this->scopeConfig->getValue('payment/pinepgpaymentmethod/MerchantId', ScopeInterface::SCOPE_STORE);

    $headers = [
		'Merchant-ID: ' . $merchant_id,
		'Authorization: Bearer ' . $access_token,
		'Content-Type: application/json',
	];

    $this->logger->info(__LINE__ . ' | ' . __FUNCTION__ . ' Refund API request', [
        'magento_order_id' => $order->getIncrementId(),
        'plural_order_id' => $pluralOrderId,
        'amount' => $amount,
        'amount_paise' => $refundAmount,
        'merchant_order_reference' => $merchantOrderReference,
        'url' => $url,
        'body' => $body
    ]);

    // Send the request
    $response = $this->sendRequest($url, $body, $headers);

    $this->logger->info(__LINE__ . ' | ' . __FUNCTION__ . ' Refund API response', [
        'magento_order_id' => $order->getIncrementId(),
        'response' => $response
    ]);


    // Process the response
    if (!empty($response['data']['status']) && $response['data']['status'] === 'PROCESSED') {
        // Get refund ID from the response
        $refundId = $response['data']['refund_id']
            ?? $response['data']['order_id']
            ?? $response['data']['id']
            ?? null;

        if (!$refundId) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Refund was processed but refund ID was not found in Pine Labs response.')
            );
        }

        // Update the sales_order table with plural_refund_id
        $existingRefundIds = (string) $order->getData('plural_refund_id');
        $refundIds = array_filter(array_map('trim', explode(',', $existingRefundIds)));
        if (!in_array($refundId, $refundIds, true)) {
            $refundIds[] = $refundId;
        }
        $order->setData('plural_refund_id', implode(',', $refundIds));
        $this->orderRepository->save($order);

        $payment = $order->getPayment();


         if ($createTransaction) {
             // Add a refund transaction
             $transaction = $this->transactionBuilder->setPayment($payment)
             ->setOrder($order)
             ->setTransactionId($refundId)
             ->setAdditionalInformation([Transaction::RAW_DETAILS => ['Status' => 'Refunded', 'Refund ID' => $refundId]])
             ->setFailSafe(true)
             ->build(Transaction::TYPE_REFUND);

             // Mark the transaction as closed
             $payment->setIsTransactionClosed(1);

             // Save the transaction and order
             $transaction->save();
             $this->orderRepository->save($order);
         }

         if ($updateOrderState) {
             // Update the order status to refunded
             $order->setStatus(Order::STATE_CLOSED);
             $order->setState(Order::STATE_CLOSED);
             $this->orderRepository->save($order);
         }

        // Add a history comment to the order
        $order->addCommentToStatusHistory(__('Refund successful, Pinelabs Refund Id: %1, Amount: %2', $refundId, $amount));
        $this->orderRepository->save($order);
        return ['status' => 'success', 'refund_id' => $refundId, 'response' => $response];
    } else {
        // Handle refund failure
        $errorMessage = $response['data']['message']
            ?? $response['error_message']
            ?? $response['response_message']
            ?? 'Refund failed';
        $this->logger->error(__LINE__ . ' | ' . __FUNCTION__ . ' Refund API failed', [
            'magento_order_id' => $order->getIncrementId(),
            'message' => $errorMessage,
            'response' => $response
        ]);
        throw new \Magento\Framework\Exception\LocalizedException(
            __('Refund failed: %1', $errorMessage)
        );
    }
}

      public function getAccessToken()
      {
          $env = $this->scopeConfig->getValue('payment/pinepgpaymentmethod/PayEnvironment', ScopeInterface::SCOPE_STORE);
          $url = ($env === 'LIVE')
              ? 'https://api.pluralpay.in/api/auth/v1/token'
              : 'https://ipg-apacuat.creditpluspinelabs.com/api/auth/v1/token';

          $body = json_encode([
              'client_id' => $this->scopeConfig->getValue('payment/pinepgpaymentmethod/MerchantAccessCode', ScopeInterface::SCOPE_STORE),
              'client_secret' => $this->scopeConfig->getValue('payment/pinepgpaymentmethod/MerchantSecretKey', ScopeInterface::SCOPE_STORE),
              'grant_type' => 'client_credentials',
          ]);

          $headers = [
              'Content-Type: application/json',
          ];

          $this->logger->info(__LINE__ . ' | ' . __FUNCTION__ . ' Refund auth token request started', ['url' => $url]);

          $curl = curl_init();
          curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
          curl_setopt_array($curl, [
              CURLOPT_URL => $url,
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_HTTPHEADER => $headers,
              CURLOPT_POST => true,
              CURLOPT_POSTFIELDS => $body,
          ]);

          try {
              $response = curl_exec($curl);

              if ($response === false) {
                  throw new \Exception('cURL Error: ' . curl_error($curl));
              }

              $responseData = json_decode($response, true);

              if (!empty($responseData['access_token'])) {
                  $this->logger->info(__LINE__ . ' | ' . __FUNCTION__ . ' Refund auth token received');
                  return $responseData['access_token'];
              }

              $this->logger->error(__LINE__ . ' | ' . __FUNCTION__ . ' Failed to retrieve refund auth token', ['response' => $responseData]);
              throw new \Exception('Failed to retrieve access token');
          } catch (\Exception $e) {
              $this->logger->error(__LINE__ . ' | ' . __FUNCTION__ . ' Refund auth token error: ' . $e->getMessage());
              throw new \Exception('Error during token retrieval: ' . $e->getMessage());
          } finally {
              curl_close($curl);
          }
      }



   


      public function sendRequest($url, $body, $headers)
{
    $curl = curl_init();
		  curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
		  
		  curl_setopt_array($curl, [
			  CURLOPT_URL => $url,
			  CURLOPT_RETURNTRANSFER => true, // Return response as string
			  CURLOPT_HTTPHEADER => $headers, // Set the headers
			  CURLOPT_POST => true, // HTTP POST method
			  CURLOPT_POSTFIELDS => $body, // Set the POST data
		  ]);

          try {
            // Execute the request and capture the response
            $response = curl_exec($curl);
    
            if ($response === false) {
                $this->logger->error(__LINE__ . ' | ' . __FUNCTION__ . ' Refund API cURL error: ' . curl_error($curl));
                throw new \Exception('cURL Error: ' . curl_error($curl));
            }
    
            $response = json_decode($response, true);
    
            return $response;
        } catch (\Exception $e) {
            $this->logger->error(__LINE__ . ' | ' . __FUNCTION__ . ' Refund API request exception: ' . $e->getMessage());
            throw new \Exception('Error during Refund API request: ' . $e->getMessage());
        } finally {
            curl_close($curl); // Close the cURL session
        }
}



}
