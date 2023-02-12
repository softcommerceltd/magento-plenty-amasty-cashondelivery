<?php
/**
 * Copyright © Soft Commerce Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace SoftCommerce\PlentyAmastyCashOnDelivery\Model\OrderExportService\ItemGenerator;

use Amasty\CashOnDelivery\Api\Data\PaymentFeeInterface;
use Amasty\CashOnDelivery\Api\PaymentFeeRepositoryInterface;
use Amasty\CashOnDelivery\Model\Config\Source\FixedCalculateBasedOn;
use Amasty\CashOnDelivery\Model\Config\Source\PaymentFeeTypes;
use Amasty\CashOnDelivery\Model\Config\Source\PercentCalculateBasedOn;
use Amasty\CashOnDelivery\Model\ConfigProvider;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventorySalesApi\Model\GetSkuFromOrderItemInterface;
use Magento\InventoryShipping\Model\ResourceModel\ShipmentSource\GetSourceCodeByShipmentId;
use Magento\OfflinePayments\Model\Cashondelivery;
use Magento\Tax\Model\Calculation;
use SoftCommerce\Core\Framework\DataStorageInterfaceFactory;
use SoftCommerce\Core\Framework\MessageStorageInterfaceFactory;
use SoftCommerce\Core\Framework\SearchMultidimensionalArrayInterface;
use SoftCommerce\PlentyOrder\Model\GetSalesOrderTaxRateInterface;
use SoftCommerce\PlentyOrder\Model\SalesOrderReservationRepositoryInterface;
use SoftCommerce\PlentyOrderClient\Api\ShippingCountryRepositoryInterface;
use SoftCommerce\PlentyOrderProfile\Model\OrderExportService\Generator\Order\Items\ItemAbstract;
use SoftCommerce\PlentyOrderRestApi\Model\OrderInterface as HttpClient;
use SoftCommerce\PlentyStock\Model\GetOrderItemSourceSelectionInterface;
use SoftCommerce\PlentyStockProfile\Model\Config\StockConfigInterfaceFactory;
use SoftCommerce\Profile\Model\ServiceAbstract\ProcessorInterface;

/**
 * @inheritdoc
 * Class CashOnDeliveryItem used to export
 * Amasty Cash On Delivery payment fee
 */
class CashOnDeliveryItem extends ItemAbstract implements ProcessorInterface
{
    /**
     * @var ConfigProvider
     */
    private ConfigProvider $config;

    /**
     * @var PaymentFeeRepositoryInterface
     */
    private PaymentFeeRepositoryInterface $paymentFeeRepository;

    /**
     * @var Calculation
     */
    private Calculation $taxCalculation;

    /**
     * @var float|null
     */
    private ?float $taxRate = null;

    /**
     * @param Calculation $taxCalculation
     * @param ConfigProvider $config
     * @param PaymentFeeRepositoryInterface $paymentFeeRepository
     * @param GetOrderItemSourceSelectionInterface $getOrderItemSourceSelection
     * @param GetSalesOrderTaxRateInterface $getSalesOrderTaxRate
     * @param GetSkuFromOrderItemInterface $getSkuFromOrderItem
     * @param GetSourceCodeByShipmentId $getSourceCodeByShipmentIdRepository
     * @param SalesOrderReservationRepositoryInterface $salesOrderReservationRepository
     * @param SearchMultidimensionalArrayInterface $searchMultidimensionalArray
     * @param ScopeConfigInterface $scopeConfig
     * @param ShippingCountryRepositoryInterface $shippingCountryRepository
     * @param StockConfigInterfaceFactory $stockConfigFactory
     * @param DataStorageInterfaceFactory $dataStorageFactory
     * @param MessageStorageInterfaceFactory $messageStorageFactory
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param array $data
     */
    public function __construct(
        Calculation $taxCalculation,
        ConfigProvider $config,
        PaymentFeeRepositoryInterface $paymentFeeRepository,
        GetOrderItemSourceSelectionInterface $getOrderItemSourceSelection,
        GetSalesOrderTaxRateInterface $getSalesOrderTaxRate,
        GetSkuFromOrderItemInterface $getSkuFromOrderItem,
        GetSourceCodeByShipmentId $getSourceCodeByShipmentIdRepository,
        SalesOrderReservationRepositoryInterface $salesOrderReservationRepository,
        SearchMultidimensionalArrayInterface $searchMultidimensionalArray,
        ScopeConfigInterface $scopeConfig,
        ShippingCountryRepositoryInterface $shippingCountryRepository,
        StockConfigInterfaceFactory $stockConfigFactory,
        DataStorageInterfaceFactory $dataStorageFactory,
        MessageStorageInterfaceFactory $messageStorageFactory,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        array $data = []
    ) {
        $this->taxCalculation = $taxCalculation;
        $this->config = $config;
        $this->paymentFeeRepository = $paymentFeeRepository;
        parent::__construct(
            $getOrderItemSourceSelection,
            $getSalesOrderTaxRate,
            $getSkuFromOrderItem,
            $getSourceCodeByShipmentIdRepository,
            $salesOrderReservationRepository,
            $searchMultidimensionalArray,
            $scopeConfig,
            $shippingCountryRepository,
            $stockConfigFactory,
            $dataStorageFactory,
            $messageStorageFactory,
            $searchCriteriaBuilder,
            $data
        );
    }

    /**
     * @inheritDoc
     */
    public function execute(): void
    {
        $this->initialize();

        if ($this->canGenerate()) {
            $this->generate();
        }

        $this->finalize();
    }

    /**
     * @return void
     * @throws LocalizedException
     */
    private function generate(): void
    {
        $context = $this->getContext();
        $salesOrder = $context->getSalesOrder();
        $this->taxRate = null;

        if (!$paymentFee = $this->getPaymentFee((int) $salesOrder->getQuoteId())) {
            return;
        }

        $amounts[] = [
            HttpClient::IS_SYSTEM_CURRENCY => true,
            HttpClient::CURRENCY => $salesOrder->getBaseCurrencyCode(),
            HttpClient::EXCHANGE_RATE => 1,
            HttpClient::PRICE_ORIGINAL_GROSS => $this->getAmountIncTax($paymentFee->getBaseAmount()),
            HttpClient::PRICE_ORIGINAL_NET => $paymentFee->getBaseAmount() ?: 0.00,
            HttpClient::PRICE_NET => $paymentFee->getBaseAmount() ?: 0.00,
            HttpClient::SURCHARGE => 0,
            HttpClient::DISCOUNT => 0,
            HttpClient::IS_PERCENTAGE => false
        ];

        $this->getRequestStorage()->addData(
            [
                HttpClient::TYPE_ID => HttpClient::ITEM_TYPE_UNASSIGNED_VARIATION,
                HttpClient::ITEM_VARIATION_ID => -2,
                HttpClient::REFERRER_ID => $context->orderConfig()->getOrderReferrerId($salesOrder->getStoreId()),
                HttpClient::QUANTITY => 1,
                HttpClient::COUNTRY_VAT_ID => $this->getCountryId($salesOrder->getBillingAddress()->getCountryId()),
                HttpClient::VAT_FIELD => 0,
                HttpClient::VAT_RATE => $this->getVatRate(),
                HttpClient::ORDER_ITEM_NAME => $this->config->getPaymentFeeLabel() ?: __('Cash On Delivery Fee'),
                HttpClient::AMOUNTS => $amounts,
            ]
        );
    }

    /**
     * @return bool
     * @throws LocalizedException
     */
    private function canGenerate(): bool
    {
        $salesOrder = $this->getContext()->getSalesOrder();
        if (!$this->config->isPaymentFeeEnabled($salesOrder->getStoreId())
            || !$this->config->isCashOnDeliveryEnabled($salesOrder->getStoreId())
        ) {
            return false;
        }

        return $salesOrder->getPayment()
            && $salesOrder->getPayment()->getMethod() === Cashondelivery::PAYMENT_METHOD_CASHONDELIVERY_CODE;
    }

    /**
     * @param int $quoteId
     * @return PaymentFeeInterface|null
     */
    private function getPaymentFee(int $quoteId): ?PaymentFeeInterface
    {
        try {
            $paymentFee = $this->paymentFeeRepository->getByQuoteId($quoteId);
        } catch (\Exception $e) {
            $paymentFee = null;
        }
        return $paymentFee;
    }

    /**
     * @param float $amount
     * @return float
     * @throws LocalizedException
     */
    private function getAmountIncTax(float $amount): float
    {
        $salesOrder = $this->getContext()->getSalesOrder();
        $storeId = $salesOrder->getStoreId();
        if (!$this->isFixedAmount($storeId)) {
            return $amount;
        }

        $taxClassId = $this->config->getTaxClassForFixedFee($storeId);
        $paymentFee = $this->config->getPaymentFee($storeId);
        if (!$taxClassId || !$this->isFixedAmountIncludingTax($storeId) || $paymentFee == $amount) {
            return $amount;
        }

        $taxRate = $this->getTaxRateByClassId((int) $taxClassId);
        $taxAmount = $this->taxCalculation->calcTaxAmount($paymentFee, $taxRate, true, false);
        return $amount + $taxAmount;
    }

    /**
     * @return float
     * @throws LocalizedException
     */
    private function getVatRate(): float
    {
        $context = $this->getContext();
        $storeId = $context->getSalesOrder()->getStoreId();
        if ($this->isFixedAmount($storeId)) {
            $taxClassId = $this->config->getTaxClassForFixedFee($storeId);
            return $this->getTaxRateByClassId((int) $taxClassId);
        }

        return $this->isPercentageIncludingTax($storeId)
            ? $this->getSalesOrderTaxRate->getTaxRate((int) $context->getSalesOrder()->getEntityId())
            : 0;
    }

    /**
     * @param $store
     * @return bool
     */
    private function isFixedAmount($store): bool
    {
        return $this->config->getPaymentFeeType($store) == PaymentFeeTypes::FIXED_AMOUNT;
    }

    /**
     * @param $store
     * @return bool
     */
    private function isFixedAmountIncludingTax($store): bool
    {
        return $this->config->getFixedCalculateBasedOn($store) == FixedCalculateBasedOn::INCLUDING_TAX;
    }

    /**
     * @param $store
     * @return bool
     */
    private function isPercentageIncludingTax($store): bool
    {
        return $this->config->getPercentCalculateBasedOn($store) == PercentCalculateBasedOn::INCLUDING_TAX;
    }

    /**
     * @param int $taxClassId
     * @return float
     * @throws LocalizedException
     */
    private function getTaxRateByClassId(int $taxClassId): float
    {
        $salesOrder = $this->getContext()->getSalesOrder();
        if (null === $this->taxRate) {
            $this->taxRate = $this->taxCalculation->getRate(
                $this->taxCalculation->getRateRequest(
                    $salesOrder->getShippingAddress(),
                    $salesOrder->getBillingAddress(),
                    $salesOrder->getCustomerTaxClassId(),
                    $salesOrder->getStore(),
                    $salesOrder->getCustomerId()
                )->setProductClassId($taxClassId)
            );
        }
        return $this->taxRate ?: 0;
    }
}
