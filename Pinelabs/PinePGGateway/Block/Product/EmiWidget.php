<?php
namespace Pinelabs\PinePGGateway\Block\Product;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Registry;
use Pinelabs\PinePGGateway\Helper\EmiOffers as EmiOffersHelper;
use Psr\Log\LoggerInterface;

class EmiWidget extends Template
{
    /**
     * @var Registry
     */
    protected $registry;
    
    /**
     * @var EmiOffersHelper
     */
    protected $emiOffersHelper;
    
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param EmiOffersHelper $emiOffersHelper
     * @param LoggerInterface $logger
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        EmiOffersHelper $emiOffersHelper,
        LoggerInterface $logger,
        array $data = []
    ) {
        $this->registry = $registry;
        $this->emiOffersHelper = $emiOffersHelper;
        $this->logger = $logger;
        parent::__construct($context, $data);
        
        $this->logger->info('Pinelabs EMI Widget Block: Constructor called');
    }

    /**
     * Check if widget should be displayed
     *
     * @return bool
     */
    public function canDisplay()
    {
        $isEnabled = $this->emiOffersHelper->isEmiWidgetEnabled();
        $product = $this->getProduct();
        
        $this->logger->info('Pinelabs EMI Widget: canDisplay check', [
            'is_enabled' => $isEnabled,
            'has_product' => $product ? 'yes' : 'no',
            'product_id' => $product ? $product->getId() : null
        ]);
        
        return $isEnabled && $product;
    }

    /**
     * Get current product
     *
     * @return \Magento\Catalog\Model\Product|null
     */
    public function getProduct()
    {
        return $this->registry->registry('current_product');
    }

    /**
     * Get product price
     *
     * @return float
     */
    public function getProductPrice()
    {
        $product = $this->getProduct();
        if (!$product) {
            $this->logger->warning('Pinelabs EMI Widget: No product found');
            return 0;
        }
        
        $price = $product->getFinalPrice();
        $this->logger->info('Pinelabs EMI Widget: Product price', [
            'product_id' => $product->getId(),
            'price' => $price
        ]);
        
        return $price;
    }

    /**
     * Get EMI offers data
     *
     * @return array|null
     */
    public function getEmiOffersData()
    {
        $price = $this->getProductPrice();
        if ($price <= 0) {
            $this->logger->warning('Pinelabs EMI Widget: Invalid price', ['price' => $price]);
            return null;
        }

        $product = $this->getProduct();
        $productSku = $product ? $product->getSku() : null;

        $this->logger->info('Pinelabs EMI Widget: Fetching EMI offers', [
            'price' => $price,
            'product_sku' => $productSku
        ]);
        
        $offers = $this->emiOffersHelper->getEmiOffers($price, $productSku);
        
        $this->logger->info('Pinelabs EMI Widget: EMI offers response', [
            'has_offers' => $offers ? 'yes' : 'no',
            'offer_count' => $offers && isset($offers['issuers']) ? count($offers['issuers']) : 0
        ]);
        
        return $offers;
    }

    /**
     * Get no-cost EMI offers
     *
     * @return array
     */
    public function getNoCostOffers()
    {
        $offersData = $this->getEmiOffersData();
        if (!$offersData) {
            return [];
        }

        return $this->emiOffersHelper->getNoCostEmiOffers($offersData);
    }

    /**
     * Get all EMI offers
     *
     * @return array
     */
    public function getAllOffers()
    {
        $offersData = $this->getEmiOffersData();
        if (!$offersData) {
            return [];
        }

        return $this->emiOffersHelper->getAllEmiOffers($offersData);
    }

    /**
     * Get minimum no-cost EMI offer
     *
     * @return array|null
     */
    public function getMinimumNoCostOffer()
    {
        $noCostOffers = $this->getNoCostOffers();
        return !empty($noCostOffers) ? $noCostOffers[0] : null;
    }

    /**
     * Format amount
     *
     * @param int $amountInPaise
     * @return string
     */
    public function formatAmount($amountInPaise)
    {
        return $this->emiOffersHelper->formatAmount($amountInPaise);
    }

    /**
     * Get EMI offers as JSON
     *
     * @return string
     */
    public function getEmiOffersJson()
    {
        return json_encode($this->getAllOffers());
    }

    /**
     * Get no-cost offers as JSON
     *
     * @return string
     */
    public function getNoCostOffersJson()
    {
        return json_encode($this->getNoCostOffers());
    }
}
