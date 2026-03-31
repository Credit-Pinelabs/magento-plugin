<?php

namespace Pinelabs\PinePGGateway\Controller\Standard;

use Pinelabs\PinePGGateway\Controller\PinePGVerify;
use Pinelabs\PinePGGateway\Model\ConfigProvider;
use Pinelabs\PinePGGateway\Model\PinePGPaymentMethod;
use \Magento\Framework\Controller\ResultFactory;

class Response extends \Pinelabs\PinePGGateway\Controller\PinePGAbstract
{
    protected $config;
    protected $pinePGPaymentMethod;
    protected $resultFactory;
    protected $encryptor;
    protected $urlEncoder;
    protected $customer;
    protected $customerSession;
    protected $checkoutSession;
    protected $orderFactory;
    protected $orderSender;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Customer $customer,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Pinelabs\PinePGGateway\Logger\Logger $logger,
        \Pinelabs\PinePGGateway\Model\PinePGPaymentMethod $paymentMethod,
        \Pinelabs\PinePGGateway\Helper\PinePG $checkoutHelper,
        \Pinelabs\PinePGGateway\Model\ConfigProvider $config,
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \Magento\Framework\Controller\ResultFactory $resultFactory,
        \Magento\Framework\Encryption\EncryptorInterface $encryptorInterface,
        \Magento\Framework\Url\EncoderInterface $encoderInterface,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender  $orderSender
    ) {
        parent::__construct($context, $customerSession, $checkoutSession, $quoteRepository, $orderFactory, $logger, $paymentMethod, $checkoutHelper, $cartManagement, $resultJsonFactory, $filesystem);
        $this->config = $config;
        $this->pinePGPaymentMethod = $paymentMethod;
        $this->resultFactory = $resultFactory;
        $this->encryptor = $encryptorInterface;
        $this->urlEncoder = $encoderInterface;
        $this->customer = $customer;
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->orderFactory = $orderFactory;
        $this->orderSender = $orderSender;
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $orderId = null;

        // Detect if this is an iframe callback (AJAX POST with source=iframe)
        $isIframe = ($this->getRequest()->getParam('source') === 'iframe');

        try {
            $callbackData = $this->getRequest()->getPostValue();

            // Log the callback data for debugging
            $this->_logger->info('PinePG callback data: ' . json_encode($callbackData));
            $this->_logger->info('Callback source: ' . ($isIframe ? 'iframe' : 'redirect'));

            if (!isset($callbackData['order_id'])) {
                $this->_logger->error('No order_id received in callback data.'. json_encode($callbackData));
                
                // Restore customer session and quote on failure
                $this->restoreCustomerAndCart();
                $this->checkoutSession->restoreQuote();
                
                if ($isIframe) {
                    return $this->resultJsonFactory->create()->setData(['success' => false, 'message' => 'No order ID received']);
                }
                $resultRedirect->setPath('checkout/onepage/failure');
                return $resultRedirect;
            }

            $paymentMethod = $this->getPaymentMethod();
            $orderId = $callbackData['order_id'];
            $statusEnquiry = $callbackData['status'] ?? null;
            $maxRetries = 3;
            $retryDelay = 20; // in seconds

            $order = $this->orderFactory->create()->load($orderId, 'plural_order_id');


              // ✅ HANDLE CANCELLED ORDER
if ($statusEnquiry === 'CANCELLED') {
    $this->_logger->info("Order cancelled by customer/payment gateway: {$orderId}");
 
    if ($order->canCancel()) {
        $order->cancel();
        $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED)
              ->setStatus(\Magento\Sales\Model\Order::STATE_CANCELED);
        $order->addStatusHistoryComment(
            "Order cancelled by customer on Pinelabs Checkout , Pinelabs Order Id : {$orderId}"
        );
        $order->save();
 
        $this->_logger->info("Magento order cancelled successfully. Entity ID: " . $order->getId());
    } else {
        $this->_logger->error("Order cannot be cancelled. Current state: " . $order->getState());
    }

    // Restore session & cart
    $this->restoreCustomerAndCart($orderId);
    $this->checkoutSession->restoreQuote();

    if ($isIframe) {
        return $this->resultJsonFactory->create()->setData(['success' => false, 'message' => 'Payment cancelled']);
    }
    $resultRedirect->setPath('checkout/onepage/failure');
    return $resultRedirect;
}

// ✅ HANDLE CANCELLED ORDER
 
            
            
            if (!empty($orderId)) {
                for ($i = 0; $i < $maxRetries; $i++) {
                    $EnquiryApiResponse = $this->pinePGPaymentMethod->callEnquiryApi($orderId);
                    $statusEnquiry = $EnquiryApiResponse['data']['status'] ?? null;
            
                    $this->_logger->info("Retry #$i - Status from Enquiry API for order_id $orderId: $statusEnquiry");
            
                    if ($statusEnquiry === 'PROCESSED') {
                        break;
                    }
            
                    // Delay before next attempt
                    if ($i < $maxRetries - 1) {
                        sleep($retryDelay);
                    }
                }
            }

            
            
            if ($statusEnquiry !== 'PROCESSED') {
                $this->_logger->error("Order is not processed after $maxRetries retries: " . $orderId);
                
                // Restore customer session and quote on failure
                $this->restoreCustomerAndCart($orderId);
                $this->checkoutSession->restoreQuote();
                
                if ($isIframe) {
                    return $this->resultJsonFactory->create()->setData(['success' => false, 'message' => 'Payment not processed']);
                }
                $resultRedirect->setPath('checkout/onepage/failure');
                return $resultRedirect;
            }

            
               
            if ($order->getId()) {
                $entityId =  $order->getId(); // entity_id
            }

            if (!$entityId) {
                $this->_logger->error('No matching order found for order_id: ' . $orderId);
                
                // Restore customer session and quote on failure
                $this->restoreCustomerAndCart($orderId);
                $this->checkoutSession->restoreQuote();
                
                if ($isIframe) {
                    return $this->resultJsonFactory->create()->setData(['success' => false, 'message' => 'Order not found']);
                }
                $resultRedirect->setPath('checkout/onepage/failure');
                return $resultRedirect;
            }

            if (!$order->getId()) {
                $this->_logger->error('Failed to load order with entity_id: ' . $entityId);
                
                // Restore customer session and quote on failure
                $this->restoreCustomerAndCart($orderId);
                $this->checkoutSession->restoreQuote();
                
                if ($isIframe) {
                    return $this->resultJsonFactory->create()->setData(['success' => false, 'message' => 'Failed to load order']);
                }
                $resultRedirect->setPath('checkout/onepage/failure');
                return $resultRedirect;
            }

            // Log customer details before session restoration
            $this->_logger->info("Customer details - ID: " . $order->getCustomerId() . 
                                ", Email: " . $order->getCustomerEmail() . 
                                ", Is Guest: " . (int)$order->getCustomerIsGuest());

            // Mark the order as successful
            $payment = $order->getPayment();
            $payment->registerCaptureNotification($order->getGrandTotal());

            $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING)
                ->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
                
            $order->save();

            $DateTime = date('Y-m-d H:i:s');
            $payment = $order->getPayment();

            $paymentMethod->postProcessing($order, $payment, $callbackData);

            $this->_logger->info('Checkout Session Order ID: ' . $this->checkoutSession->getLastOrderId());
            $this->_logger->info('Order marked as successful. Entity ID: ' . $entityId);

            // CRITICAL FIX: Restore customer session
            $this->restoreCustomerSession($order);

            // Set checkout session values to show success page
            if ($order && $order->getId() && $order->getQuoteId() && $order->getIncrementId()) {
                $this->checkoutSession->setLastQuoteId($order->getQuoteId());
                $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
                $this->checkoutSession->setLastOrderId($order->getId());
                $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
                $this->checkoutSession->setLastOrderStatus($order->getStatus());
                
                $this->_logger->info('Checkout session values set - QuoteId: ' . $order->getQuoteId() . 
                                    ', OrderId: ' . $order->getId() . 
                                    ', RealOrderId: ' . $order->getIncrementId());
            } else {
                $this->_logger->error('Missing order data: Cannot set checkout session values.');
            }

            // Optionally send order confirmation email
            try {
                $this->orderSender->send($order);
            } catch (\Exception $e) {
                $this->_logger->critical('Error sending order email: ' . $e->getMessage());
            }

            // Return JSON for iframe, redirect for standard flow
            if ($isIframe) {
                return $this->resultJsonFactory->create()->setData(['success' => true, 'message' => 'SUCCESS']);
            }

            $resultRedirect->setPath('checkout/onepage/success');
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->messageManager->addExceptionMessage($e, $e->getMessage());
            $this->_logger->error('LocalizedException: ' . $e->getMessage());
            
            // Restore customer session and quote on exception
            $this->restoreCustomerAndCart($orderId);
            $this->checkoutSession->restoreQuote();
            
            if ($isIframe) {
                return $this->resultJsonFactory->create()->setData(['success' => false, 'message' => $e->getMessage()]);
            }
            $resultRedirect->setPath('checkout/onepage/failure');
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('We can\'t place the order.'));
            $this->_logger->error('Exception: ' . $e->getMessage());
            
            // Restore customer session and quote on exception
            $this->restoreCustomerAndCart($orderId);
            $this->checkoutSession->restoreQuote();
            
            if ($isIframe) {
                return $this->resultJsonFactory->create()->setData(['success' => false, 'message' => $e->getMessage()]);
            }
            $resultRedirect->setPath('checkout/onepage/failure');
        }

        return $resultRedirect;
    }

    private function restoreCustomerSession($order)
    {
        try {
            // Skip if guest order
            if ($order->getCustomerIsGuest()) {
                $this->_logger->info('Guest order - skipping customer session restoration');
                return;
            }

            $customerId = $order->getCustomerId();

            $this->_logger->info("Attempting to restore customer session - Customer ID: {$customerId}");

            if (!$customerId) {
                $this->_logger->error('No customer ID found in order');
                return;
            }

            // Use ObjectManager to get CustomerFactory (to avoid constructor changes)
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $customerFactory = $objectManager->get(\Magento\Customer\Model\CustomerFactory::class);
            
            // Load customer
            $customer = $customerFactory->create()->load($customerId);

            if (!$customer->getId()) {
                $this->_logger->error("Customer not found with ID: {$customerId}");
                return;
            }

            $this->_logger->info("Customer loaded successfully - ID: {$customer->getId()}, Email: {$customer->getEmail()}");

            // Set customer as logged in
            $this->customerSession->setCustomerAsLoggedIn($customer);
            
            // Regenerate session ID for security
            $this->customerSession->regenerateId();
            
            $this->_logger->info("Customer session restored successfully - Customer ID: {$customerId}");
            $this->_logger->info("Current customer session ID: " . $this->customerSession->getCustomerId());
            $this->_logger->info("Is customer logged in: " . (int)$this->customerSession->isLoggedIn());

        } catch (\Exception $e) {
            $this->_logger->error('Error restoring customer session: ' . $e->getMessage());
            $this->_logger->error('Stack trace: ' . $e->getTraceAsString());
        }
    }

    /**
 * Restore customer session and quote/cart
 *
 * @param string|null $orderId
 * @return void
 */
private function restoreCustomerAndCart($orderId = null)
{
    try {
        $customer = null;

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $customerFactory = $objectManager->get(\Magento\Customer\Model\CustomerFactory::class);

        // If orderId provided, restore customer from order
        if ($orderId) {
            $order = $this->orderFactory->create()->load($orderId, 'plural_order_id');
            if ($order && $order->getId() && !$order->getCustomerIsGuest()) {
                $customer = $customerFactory->create()->load($order->getCustomerId());
            }

            // Restore quote from order
            if ($order && $order->getQuoteId()) {
                $quote = $objectManager->create(\Magento\Quote\Model\Quote::class)->load($order->getQuoteId());
                if ($quote && $quote->getId()) {
                    $quote->setIsActive(true)->collectTotals()->save();
                    $this->checkoutSession->replaceQuote($quote);
                    $this->checkoutSession->setQuoteId($quote->getId());
                }
            }
        }

        // If no order or guest, restore customer from session
        if (!$customer && $this->customerSession->getCustomerId()) {
            $customer = $customerFactory->create()->load($this->customerSession->getCustomerId());
        }

        // Restore customer login
        if ($customer && $customer->getId()) {
            $this->customerSession->setCustomerAsLoggedIn($customer);
            $this->customerSession->regenerateId();
        }

    } catch (\Exception $e) {
        $this->_logger->error('Error restoring customer/cart: ' . $e->getMessage());
    }

    // Always try restoring the quote
    try {
        $this->checkoutSession->restoreQuote();
    } catch (\Exception $e) {
        $this->_logger->error('Error restoring quote: ' . $e->getMessage());
    }
}

}