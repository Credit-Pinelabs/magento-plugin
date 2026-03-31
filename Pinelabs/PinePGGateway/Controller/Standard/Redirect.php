<?php

namespace Pinelabs\PinePGGateway\Controller\Standard;

class Redirect extends \Pinelabs\PinePGGateway\Controller\PinePGAbstract {

    public function execute() 
	{
			if (!$this->getRequest()->isAjax()) {
				$this->_cancelPayment();
				$this->_checkoutSession->restoreQuote();
				$this->getResponse()->setRedirect(
						$this->getCheckoutHelper()->getUrl('checkout')
				);
			}
		
			$order = $this->getOrder();
			//$order->setState('pending')->setStatus('pending');
			//$order->save();
			$quote = $this->getQuote();
			$email = $this->getRequest()->getParam('email');
			if ($this->getCustomerSession()->isLoggedIn()) {
				$this->getCheckoutSession()->loadCustomerQuote();
				$quote->updateCustomerData($this->getQuote()->getCustomer());
			} else {
				$quote->setCustomerEmail($email);
			}

			if ($this->getCustomerSession()->isLoggedIn()) {
				$quote->setCheckoutMethod(\Magento\Checkout\Model\Type\Onepage::METHOD_CUSTOMER);
			} else {
				$quote->setCheckoutMethod(\Magento\Checkout\Model\Type\Onepage::METHOD_GUEST);
			}
			
			$quote->setCustomerEmail($email);
			$quote->save();

        $params = [];
		
		$orderApiResult = $this->getPaymentMethod()->callOrderApi($order);
        $params["url"] = $orderApiResult['redirect_url'];
        $params["order_id"] = $orderApiResult['order_id'];
        return $this->resultJsonFactory->create()->setData($params);
		
    }

}
