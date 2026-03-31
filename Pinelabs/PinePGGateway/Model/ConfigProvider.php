<?php

namespace Pinelabs\PinePGGateway\Model;

class ConfigProvider extends \Magento\Payment\Model\Method\AbstractMethod implements \Magento\Checkout\Model\ConfigProviderInterface
{
    protected $methodCode = \Pinelabs\PinePGGateway\Model\PinePGPaymentMethod::PAYMENT_PINE_PG_CODE;
    
    protected $method;
    protected $scopeConfig;

    public function __construct(
        \Magento\Payment\Helper\Data $paymenthelper,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ){
        $this->method = $paymenthelper->getMethodInstance($this->methodCode);
        $this->scopeConfig = $scopeConfig;
    }

    public function getConfig(){
        if (!$this->method->isAvailable()) {
            return [];
        }

        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        $integrationMode = $this->scopeConfig->getValue('payment/pinepgpaymentmethod/IntegrationMode', $storeScope) ?: 'REDIRECT';
        $environment = $this->scopeConfig->getValue('payment/pinepgpaymentmethod/PayEnvironment', $storeScope) ?: 'TEST';

        // Plural JS SDK URL based on environment
        $pluralScriptUrl = ($environment === 'LIVE')
            ? 'https://checkout.pluralonline.com/v3/web-sdk-checkout.js'
            : 'https://checkout-staging.pluralonline.com/v3/web-sdk-checkout.js';

        return [
            'payment' => [
                'pinepg' => [
                    'redirectUrl'    => $this->method->getRedirectUrl(),
                    'integrationMode' => $integrationMode,
                    'pluralScriptUrl' => $pluralScriptUrl,
                    'callbackUrl'    => $this->method->getReturnUrl(),
                ]
            ]
        ];
    }
}
