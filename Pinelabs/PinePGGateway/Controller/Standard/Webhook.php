<?php

namespace Pinelabs\PinePGGateway\Controller\Standard;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Store\Model\ScopeInterface;
use Pinelabs\PinePGGateway\Logger\Logger;

class Webhook extends Action implements CsrfAwareActionInterface
{
    protected $resultJsonFactory;
    protected $orderFactory;
    protected $scopeConfig;

    /** @var Logger */
    protected $_logger;

    public function __construct(Context $context)
    {
        parent::__construct($context);

        // ✅ SAME PATTERN AS Response.php (ObjectManager)
        $om = \Magento\Framework\App\ObjectManager::getInstance();

        $this->resultJsonFactory = $om->get(JsonFactory::class);
        $this->orderFactory     = $om->get(OrderFactory::class);
        $this->scopeConfig      = $om->get(ScopeConfigInterface::class);
        $this->_logger          = $om->get(Logger::class);
    }

    /* ================= CSRF BYPASS ================= */

    public function createCsrfValidationException(
        RequestInterface $request
    ): ?InvalidRequestException {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /* ================= MAIN EXECUTION ================= */

    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            $rawBody = file_get_contents('php://input');
            $headers = $this->getHeaders();

            // ✅ SAME LOGGER STYLE AS Response.php
            $this->_logger->info('PinePG webhook received', [
                'headers' => $headers,
                'body'    => $rawBody
            ]);

            $payload = json_decode($rawBody, true);

            if (
                empty($payload['event_type']) ||
                empty($payload['data']['order_id']) ||
                empty($payload['data']['status'])
            ) {
                return $result->setHttpResponseCode(400)
                    ->setData(['error' => 'Invalid payload']);
            }

            if (
                $payload['event_type'] !== 'ORDER_PROCESSED' ||
                $payload['data']['status'] !== 'PROCESSED'
            ) {
                return $result->setData(['message' => 'Event ignored']);
            }

            $pineOrderId = $payload['data']['order_id'];

            // Load order first so the webhook signature can be verified with
            // the correct store-scoped MerchantSecretKey.
            $order = $this->orderFactory
                ->create()
                ->load($pineOrderId, 'plural_order_id');

            if (!$order->getId()) {
                throw new \Exception('Order not found for Pine order id: ' . $pineOrderId);
            }

            $storeId = (int) $order->getStoreId();

            // 🔐 Signature verification with order store scope
            if (!$this->verifySignature($headers, $rawBody, $storeId)) {
                $this->_logger->error('Invalid PinePG webhook signature for store: ' . $storeId, [
                    'order_id' => $pineOrderId,
                    'entity_id' => $order->getId()
                ]);
                return $result->setHttpResponseCode(401)
                    ->setData(['error' => 'Invalid signature']);
            }

            // 🛑 Idempotency
            if ($order->getState() === \Magento\Sales\Model\Order::STATE_PROCESSING) {
                return $result->setData(['message' => 'Order already processed']);
            }

            // ✅ Register payment capture so Magento creates the invoice/payment transaction
            // same as the normal response flow.
            $payment = $order->getPayment();
            $payment->registerCaptureNotification($order->getGrandTotal());

            // ✅ Mark order paid
            $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING)
                ->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);

            $order->addStatusHistoryComment(
                'Payment confirmed via Pinelabs webhook. Pine Order ID: ' . $pineOrderId
            );

            $order->save();

            $this->_logger->info('Webhook processed successfully', [
                'order_id' => $pineOrderId,
                'entity_id' => $order->getId(),
                'store_id' => $storeId
            ]);

            return $result->setData(['message' => 'Webhook processed successfully']);

        } catch (\Exception $e) {
            $this->_logger->error('PinePG webhook error: ' . $e->getMessage());

            return $result->setHttpResponseCode(500)
                ->setData(['error' => $e->getMessage()]);
        }
    }

    /* ================= SIGNATURE VERIFICATION ================= */

    private function verifySignature(array $headers, string $rawBody, int $storeId = null): bool
{
    $headers = array_change_key_case($headers, CASE_LOWER);

    $webhookId = $headers['webhook-id'] ?? '';
    $timestamp = $headers['webhook-timestamp'] ?? '';
    $signature = $headers['webhook-signature'] ?? '';

    if (!$webhookId || !$timestamp || !$signature) {
        $this->_logger->error('Missing webhook signature headers', [
            'webhook_id' => $webhookId,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'store_id' => $storeId
        ]);
        return false;
    }

    // ⏱ Replay attack protection (5 minutes)
    if (abs(time() - (int)$timestamp) > 300) {
        $this->_logger->error('Webhook timestamp expired', [
            'timestamp' => $timestamp,
            'store_id' => $storeId
        ]);
        return false;
    }

    $paymentMethod = \Magento\Framework\App\ObjectManager::getInstance()
        ->get(\Pinelabs\PinePGGateway\Model\PinePGPaymentMethod::class);

    $secret = $paymentMethod->getConfigData('MerchantSecretKey', $storeId);

    if (!$secret) {
        $this->_logger->error('Webhook secret missing in config for store: ' . $storeId);
        return false;
    }

    // 🔑 IMPORTANT: The secret needs to be base64 encoded first
    $base64Secret = base64_encode($secret);
    $secretBytes = base64_decode($base64Secret);
    
    if ($secretBytes === false) {
        $this->_logger->error('Failed to base64 decode the secret key for store: ' . $storeId);
        return false;
    }

    // ✅ Pine Labs format: webhook-id.timestamp.body
    $signedPayload = $webhookId . '.' . $timestamp . '.' . $rawBody;

    $expectedSignature = base64_encode(
        hash_hmac('sha256', $signedPayload, $secretBytes, true)
    );

    // Header format: v1,XXXX
    $receivedSignature = str_replace('v1,', '', $signature);

    if (!hash_equals($expectedSignature, $receivedSignature)) {
        $this->_logger->error('Webhook signature mismatch for store: ' . $storeId, [
            'expected' => $expectedSignature,
            'received' => $receivedSignature,
            'signed_payload_length' => strlen($signedPayload),
            'raw_body_length' => strlen($rawBody),
            'store_id' => $storeId
        ]);
        return false;
    }

    return true;
}


    /* ================= HEADER FETCH ================= */

    private function getHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return array_change_key_case(getallheaders(), CASE_LOWER);
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        return $headers;
    }
}
