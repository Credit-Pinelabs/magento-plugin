<?php

namespace Pinelabs\PinePGGateway\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Pinelabs\PinePGGateway\Helper\PinePG;
use Pinelabs\PinePGGateway\Logger\Logger;
use Pinelabs\PinePGGateway\Model\PinePGPaymentMethod;

class CreditMemoRefundObserver implements ObserverInterface
{
    /**
     * @var PinePG
     */
    protected $pinePGHelper;

    /**
     * @var Logger
     */
    protected $logger;

    public function __construct(
        PinePG $pinePGHelper,
        Logger $logger
    ) {
        $this->pinePGHelper = $pinePGHelper;
        $this->logger = $logger;
    }

    /**
     * Trigger Pine Labs refund API when admin creates a credit memo with Refund Offline.
     * Online Refund is handled by PinePGPaymentMethod::refund().
     *
     * @param Observer $observer
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute(Observer $observer)
    {
        $creditmemo = $observer->getEvent()->getCreditmemo();
        if (!$creditmemo || !$creditmemo->getOrder()) {
            return;
        }

        $order = $creditmemo->getOrder();
        $payment = $order->getPayment();

        if (!$payment || $payment->getMethod() !== PinePGPaymentMethod::PAYMENT_PINE_PG_CODE) {
            $this->logger->info(__LINE__ . ' | ' . __FUNCTION__ . ' Skipping Pine Labs refund because order payment method is not Pine Labs.', [
                'order_id' => $order->getIncrementId(),
                'payment_method' => $payment ? $payment->getMethod() : null
            ]);
            return;
        }

        if (!$order->getData('plural_order_id')) {
            $this->logger->info(__LINE__ . ' | ' . __FUNCTION__ . ' Skipping Pine Labs refund because Plural order id is missing.', [
                'order_id' => $order->getIncrementId(),
                'payment_method' => $payment->getMethod()
            ]);
            return;
        }

        if ($creditmemo->getDoTransaction()) {
            $this->logger->info(__LINE__ . ' | ' . __FUNCTION__ . ' Skipping observer refund because online refund is already handled by payment method.', [
                'order_id' => $order->getIncrementId(),
                'creditmemo_id' => $creditmemo->getIncrementId()
            ]);
            return;
        }

        $amount = (float) $creditmemo->getGrandTotal();
        if ($amount <= 0) {
            $this->logger->info(__LINE__ . ' | ' . __FUNCTION__ . ' Skipping Pine Labs refund for zero amount credit memo.', [
                'order_id' => $order->getIncrementId(),
                'creditmemo_id' => $creditmemo->getIncrementId()
            ]);
            return;
        }

        $this->logger->info(__LINE__ . ' | ' . __FUNCTION__ . ' Credit memo offline refund detected; calling Pine Labs refund API.', [
            'order_id' => $order->getIncrementId(),
            'entity_id' => $order->getEntityId(),
            'creditmemo_id' => $creditmemo->getIncrementId(),
            'amount' => $amount
        ]);

        $response = $this->pinePGHelper->processRefund(
            $order->getEntityId(),
            $amount,
            'Magento Credit Memo Refund',
            false,
            false
        );

        $this->logger->info(__LINE__ . ' | ' . __FUNCTION__ . ' Credit memo offline Pine Labs refund completed.', [
            'order_id' => $order->getIncrementId(),
            'creditmemo_id' => $creditmemo->getIncrementId(),
            'refund_id' => $response['refund_id'] ?? null
        ]);
    }
}
