<?php
namespace Pinelabs\PinePGGateway\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Pinelabs\PinePGGateway\Model\PinePGPaymentMethod;

class EmiOffers extends AbstractHelper
{
    const XML_PATH_EMI_WIDGET_ENABLED = 'payment/pinepgpaymentmethod/emi_widget_enabled';
    
    /**
     * Bank logos mapping
     * @var array
     */
    protected $bankLogos = [
        'HDFC BANK' => 'hdfc.png',
        'ICICI BANK' => 'icici.png',
        'AXIS BANK' => 'axis.png',
        'KOTAK BANK' => 'kotak.png',
        'RBL BANK' => 'rbl.png',
        'INDUSIND BANK' => 'indusind.png',
        'IDFC FIRST BANK' => 'idfc.png',
        'YES BANK' => 'yes.png',
        'PNB BANK' => 'pnb.png',
        'ONECARD BANK' => 'onecard.png',
        'BOB BANK' => 'bob.png',
        'STANDARD CHARTERED BANK' => 'standard-chartered.png',
        'INDIAN OVERSEAS BANK' => 'indian-overseas.png',
        'AMEX BANK' => 'amex.png'
    ];
    
    /**
     * @var Curl
     */
    protected $curl;
    
    /**
     * @var Json
     */
    protected $json;
    
    /**
     * @var LoggerInterface
     */
    protected $logger;
    
    /**
     * @var PinePGPaymentMethod
     */
    protected $paymentMethod;

    /**
     * @param Context $context
     * @param Curl $curl
     * @param Json $json
     * @param LoggerInterface $logger
     * @param PinePGPaymentMethod $paymentMethod
     */
    public function __construct(
        Context $context,
        Curl $curl,
        Json $json,
        LoggerInterface $logger,
        PinePGPaymentMethod $paymentMethod
    ) {
        parent::__construct($context);
        $this->curl = $curl;
        $this->json = $json;
        $this->logger = $logger;
        $this->paymentMethod = $paymentMethod;
    }

    /**
     * Check if EMI widget is enabled
     *
     * @return bool
     */
    public function isEmiWidgetEnabled()
    {
        return (bool)$this->scopeConfig->getValue(
            self::XML_PATH_EMI_WIDGET_ENABLED,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get EMI offers for a product
     *
     * @param float $amount Product price in rupees
     * @param string|null $productSku Product SKU (will be used as product_code)
     * @return array|null
     */
    public function getEmiOffers($amount, $productSku = null)
    {
        if (!$this->isEmiWidgetEnabled()) {
            return null;
        }

        try {
            $token = $this->paymentMethod->getAccessToken();
            if (!$token) {
                $this->logger->error('Pinelabs EMI: Failed to get access token');
                return null;
            }
        } catch (\Exception $e) {
            $this->logger->error('Pinelabs EMI: Exception getting token: ' . $e->getMessage());
            return null;
        }

        try {
            $env = $this->scopeConfig->getValue(
                'payment/pinepgpaymentmethod/PayEnvironment',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );
            
            $merchantId = $this->scopeConfig->getValue(
                'payment/pinepgpaymentmethod/MerchantId',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );

            $offersUrl = ($env == 'LIVE')
                ? 'https://api.pluralpay.in/api/affordability/v1/offer/discovery'
                : 'https://pluraluat.v2.pinepg.in/api/affordability/v1/offer/discovery';

            // Convert amount to paise (multiply by 100)
            $amountInPaise = (int)($amount * 100);

            // Use product SKU as product_code, or fall back to default
            $productCode = $productSku ?: '225090';

            $requestData = [
                'order_amount' => [
                    'value' => $amountInPaise,
                    'currency' => 'INR'
                ],
                'product_details' => [
                    [
                        'product_code' => $productCode,
                        'product_amount' => [
                            'value' => $amountInPaise,
                            'currency' => 'INR'
                        ]
                    ]
                ]
            ];

            $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curl->setOption(CURLOPT_SSL_VERIFYPEER, false);
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader('Authorization', 'Bearer ' . $token);
            $this->curl->addHeader('Merchant-ID', $merchantId);
            $this->curl->addHeader('correlation-id', uniqid('emi_widget_'));
            $this->curl->post($offersUrl, $this->json->serialize($requestData));

            $response = $this->curl->getBody();
            $httpCode = $this->curl->getStatus();

            if ($httpCode == 200) {
                $responseData = $this->json->unserialize($response);
                $this->logger->info('Pinelabs EMI: Offers fetched successfully', [
                    'product_code' => $productCode,
                    'issuer_count' => isset($responseData['issuers']) ? count($responseData['issuers']) : 0
                ]);
                return $responseData;
            }

            $this->logger->error('Pinelabs EMI: Failed to get offers. HTTP Code: ' . $httpCode . ', Response: ' . $response);
            return null;

        } catch (\Exception $e) {
            $this->logger->error('Pinelabs EMI: Exception getting EMI offers: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get only no-cost EMI offers
     *
     * @param array $offersData
     * @return array
     */
    public function getNoCostEmiOffers($offersData)
    {
        if (!isset($offersData['issuers']) || !is_array($offersData['issuers'])) {
            return [];
        }

        $noCostOffers = [];

        foreach ($offersData['issuers'] as $issuer) {
            if (!isset($issuer['tenures']) || !is_array($issuer['tenures'])) {
                continue;
            }

            foreach ($issuer['tenures'] as $tenure) {
                // Check if it's a no-cost EMI
                if (isset($tenure['emi_type']) && $tenure['emi_type'] === 'NO_COST') {
                    $noCostOffers[] = [
                        'issuer_name' => $issuer['display_name'] ?? $issuer['name'],
                        'issuer_id' => $issuer['id'],
                        'logo' => $this->getBankLogo($issuer['display_name'] ?? $issuer['name']),
                        'tenure_name' => $tenure['name'],
                        'tenure_months' => $tenure['tenure_value'],
                        'monthly_emi' => $tenure['monthly_emi_amount']['value'] ?? 0,
                        'total_amount' => $tenure['total_emi_amount']['value'] ?? 0,
                        'net_payment' => $tenure['net_payment_amount']['value'] ?? 0,
                        'interest_rate' => $tenure['interest_rate_percentage'] ?? 0,
                        'interest_amount' => $tenure['interest_amount']['value'] ?? 0,
                        'emi_type' => $tenure['emi_type']
                    ];
                }
            }
        }

        // Sort by monthly EMI amount (lowest first)
        usort($noCostOffers, function($a, $b) {
            return $a['monthly_emi'] <=> $b['monthly_emi'];
        });

        return $noCostOffers;
    }

    /**
     * Get all EMI offers grouped by issuer
     *
     * @param array $offersData
     * @return array
     */
    public function getAllEmiOffers($offersData)
    {
        if (!isset($offersData['issuers']) || !is_array($offersData['issuers'])) {
            return [];
        }

        $allOffers = [];

        foreach ($offersData['issuers'] as $issuer) {
            if (!isset($issuer['tenures']) || !is_array($issuer['tenures'])) {
                continue;
            }

            $issuerOffers = [
                'issuer_name' => $issuer['display_name'] ?? $issuer['name'],
                'issuer_id' => $issuer['id'],
                'issuer_type' => $issuer['issuer_type'] ?? '',
                'priority' => $issuer['priority'] ?? 999,
                'logo' => $this->getBankLogo($issuer['display_name'] ?? $issuer['name']),
                'tenures' => []
            ];

            foreach ($issuer['tenures'] as $tenure) {
                // Skip "No EMI Only Cashback" tenures for the widget
                if ($tenure['tenure_value'] == 0) {
                    continue;
                }

                $issuerOffers['tenures'][] = [
                    'tenure_name' => $tenure['name'],
                    'tenure_months' => $tenure['tenure_value'],
                    'monthly_emi' => $tenure['monthly_emi_amount']['value'] ?? 0,
                    'total_amount' => $tenure['total_emi_amount']['value'] ?? 0,
                    'net_payment' => $tenure['net_payment_amount']['value'] ?? 0,
                    'interest_rate' => $tenure['interest_rate_percentage'] ?? 0,
                    'interest_amount' => $tenure['interest_amount']['value'] ?? 0,
                    'emi_type' => $tenure['emi_type'] ?? 'STANDARD',
                    'processing_fee' => $tenure['processing_fee_amount']['value'] ?? 0,
                    'discount' => isset($tenure['discount']['amount']['value']) ? $tenure['discount']['amount']['value'] : 0
                ];
            }

            if (!empty($issuerOffers['tenures'])) {
                $allOffers[] = $issuerOffers;
            }
        }

        // Sort by priority
        usort($allOffers, function($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });

        return $allOffers;
    }

    /**
     * Get bank logo filename
     *
     * @param string $issuerName
     * @return string|null
     */
    public function getBankLogo($issuerName)
    {
        return $this->bankLogos[$issuerName] ?? null;
    }

    /**
     * Format amount from paise to rupees
     *
     * @param int $amountInPaise
     * @return string
     */
    public function formatAmount($amountInPaise)
    {
        return number_format($amountInPaise / 100, 2);
    }
}
