<?php
/**
 * Copyright © Soft Commerce Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace SoftCommerce\PlentyAmastyCashOnDelivery\Profile\OrderExport;

use Amasty\CashOnDelivery\Api\Data\PaymentFeeInterface;
use Amasty\CashOnDelivery\Api\PaymentFeeRepositoryInterface;
use Amasty\CashOnDelivery\Model\Config\Source\FixedCalculateBasedOn;
use Amasty\CashOnDelivery\Model\Config\Source\PaymentFeeTypes;
use Amasty\CashOnDelivery\Model\Config\Source\PercentCalculateBasedOn;
use Amasty\CashOnDelivery\Model\ConfigProvider;
use Magento\Framework\Exception\LocalizedException;
use Magento\OfflinePayments\Model\Cashondelivery;
use Magento\Tax\Model\Calculation;
use Plenty\Core\Framework\DataStorageInterfaceFactory;
use Plenty\Core\Framework\MessageStorageInterfaceFactory;
use Plenty\Order\Profile\Service\OrderExport\AbstractManagement;
use Plenty\Order\Profile\Service\OrderExport\GeneratorInterface;
use Plenty\Order\Rest\OrderInterface as HttpClient;

/**
 * @inheritdoc
 */
class ItemGenerator extends AbstractManagement implements GeneratorInterface
{
    /**
     * @var ConfigProvider
     */
    private $config;

    /**
     * @var PaymentFeeRepositoryInterface
     */
    private $paymentFeeRepository;

    /**
     * @var Calculation
     */
    private $taxCalculation;

    /**
     * @var float|null
     */
    private $taxRate;

    /**
     * ItemGenerator constructor.
     * @param Calculation $taxCalculation
     * @param ConfigProvider $config
     * @param PaymentFeeRepositoryInterface $paymentFeeRepository
     * @param DataStorageInterfaceFactory $dataStorageFactory
     * @param MessageStorageInterfaceFactory $messageStorageFactory
     */
    public function __construct(
        Calculation $taxCalculation,
        ConfigProvider $config,
        PaymentFeeRepositoryInterface $paymentFeeRepository,
        DataStorageInterfaceFactory $dataStorageFactory,
        MessageStorageInterfaceFactory $messageStorageFactory
    ) {
        $this->taxCalculation = $taxCalculation;
        $this->config = $config;
        $this->paymentFeeRepository = $paymentFeeRepository;
        parent::__construct($dataStorageFactory, $messageStorageFactory);
    }

    /**
     * @return ItemGenerator
     */
    public function executeBefore()
    {
        $this->taxRate = null;
        return parent::executeBefore();
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $this->executeBefore();
        $this->generate();
        return $this;
    }

    /**
     * @throws LocalizedException
     */
    public function generate(): void
    {
        $salesOrder = $this->getContext()->getSalesOrder();
        if (!$this->canProcess() || !$paymentFee = $this->getPaymentFee((int) $salesOrder->getQuoteId())) {
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
                HttpClient::REFERRER_ID => $this->getContext()->getProfileEntity()
                    ->getOrderReferrerId($salesOrder->getStoreId()),
                HttpClient::QUANTITY => 1,
                HttpClient::COUNTRY_VAT_ID => $this->getContext()->getCountryId(
                    $this->getContext()->getSalesOrder()->getBillingAddress()->getCountryId()
                ),
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
    private function canProcess(): bool
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
        $storeId = $this->getContext()->getSalesOrder()->getStoreId();
        if ($this->isFixedAmount($storeId)) {
            $taxClassId = $this->config->getTaxClassForFixedFee($storeId);
            return $this->getTaxRateByClassId((int) $taxClassId);
        }

        return $this->isPercentageIncludingTax($storeId)
            ? $this->getContext()->getTaxRate()
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
