<?php
/**
 * @category    CoinGate
 * @package     CoinGate_Merchant
 * @author      CoinGate
 * @copyright   CoinGate (https://coingate.com)
 * @license     https://github.com/coingate/magento2-plugin/blob/master/LICENSE The MIT License (MIT)
 */

declare(strict_types = 1);

namespace CoinGate\Merchant\Model;

use CoinGate\Client;
use CoinGate\Exception\ApiErrorException;
use CoinGate\Resources\CreateOrder;
use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Class Payment
 */
class Payment
{
    /**
     * @var string
     */
    public const COINGATE_ORDER_TOKEN_KEY = 'coingate_order_token';

    /**
     * @var string
     */
    private const PAID_STATUS = 'paid';

    /**
     * @var array
     */
    private const STATUSES_FOR_CANSEL = [
        'invalid',
        'expired',
        'canceled',
        'refunded',
    ];

    private UrlInterface $urlBuilder;
    private StoreManagerInterface $storeManager;
    private OrderManagementInterface $orderManagement;
    private OrderPaymentRepositoryInterface $paymentRepository;
    private ?Client $client = null;
    private ConfigManagement $configManagement;
    private OrderRepository $orderRepository;
    private LoggerInterface $logger;

    /**
     * @param UrlInterface $urlBuilder
     * @param StoreManagerInterface $storeManager
     * @param OrderManagementInterface $orderManagement
     * @param OrderPaymentRepositoryInterface $paymentRepository
     * @param ConfigManagement $configManagement
     * @param OrderRepository $orderRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        UrlInterface $urlBuilder,
        StoreManagerInterface $storeManager,
        OrderManagementInterface $orderManagement,
        OrderPaymentRepositoryInterface $paymentRepository,
        ConfigManagement $configManagement,
        OrderRepository $orderRepository,
        LoggerInterface $logger
    ) {
        $this->urlBuilder = $urlBuilder;
        $this->storeManager = $storeManager;
        $this->orderManagement = $orderManagement;
        $this->paymentRepository = $paymentRepository;
        $this->configManagement = $configManagement;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
    }

    /**
     * Get CoinGate Order From API
     *
     * @param OrderInterface $order
     *
     * @return CreateOrder|mixed
     */
    public function getCoinGateOrder(OrderInterface $order)
    {
        $description = [];
        $token = substr(md5((string)rand()), 0, 32);
        $payment = $order->getPayment();
        $payment->setAdditionalInformation(self::COINGATE_ORDER_TOKEN_KEY, $token);
        $this->paymentRepository->save($payment);

        foreach ($order->getAllItems() as $item) {
            $description[] = number_format((float)$item->getQtyOrdered(), 0) . ' × ' . $item->getName();
        }

        try {
            $params = [
                'order_id' => $order->getIncrementId(),
                'price_amount' => number_format((float) $order->getGrandTotal(), 2, '.', ''),
                'price_currency' => $order->getOrderCurrencyCode(),
                'receive_currency' => $this->configManagement->getReceiveCurrency(),
                'callback_url' => $this->urlBuilder->getUrl(
                    'coingate/payment/callback',
                    [
                        '_query' => [
                            'token' => $payment->getAdditionalInformation(self::COINGATE_ORDER_TOKEN_KEY)
                        ]
                    ]
                ),
                'cancel_url' => $this->urlBuilder->getUrl('coingate/payment/cancelOrder'),
                'success_url' => $this->urlBuilder->getUrl('checkout/onepage/success'),
                'title' => $this->storeManager->getWebsite()->getName(),
                'description' => implode(', ', $description),
                'token' => $payment->getAdditionalInformation(self::COINGATE_ORDER_TOKEN_KEY)
            ];
        } catch (LocalizedException $exception) {
            $this->logger->critical($exception->getMessage());

            return null;
        }

        $client = $this->getClient();

        try {
            $cgOrder = $client->order->create($params);
        } catch (ApiErrorException $exception) {
            $this->logger->critical($exception->getMessage());

            return null;
        }

        return $cgOrder;
    }

    /**
     * Validate CoinGate Callback
     *
     * @param Order $order
     * @param int $requestId
     *
     * @return void
     */
    public function validateCoinGateCallback(Order $order, int $requestId): void
    {
        try {
            $client = $this->getClient();
            $cgOrder = $client->order->get($requestId);

            if (!$cgOrder) {
                throw new Exception('CoinGate Order #' . $requestId . ' does not exist');
            }

            if ($cgOrder->status === self::PAID_STATUS) {
                $order->setState(Order::STATE_PROCESSING);
                $orderConfig = $order->getConfig();
                $order->setStatus($orderConfig->getStateDefaultStatus(Order::STATE_PROCESSING));
                $this->orderRepository->save($order);
            } elseif (in_array($cgOrder->status, self::STATUSES_FOR_CANSEL)) {
                $this->orderManagement->cancel($cgOrder->order_id);
            }
        } catch (Exception $exception) {
            $this->logger->critical($exception);
        }
    }

    /**
     * Get Http CoinGate Client
     *
     * @return Client|null
     */
    private function getClient(): ?Client
    {
        if (!$this->client) {
            $environment = $this->configManagement->isSandboxMode();
            $this->client = new Client($this->configManagement->getApiAuthToken(), $environment);
        }

        return $this->client;
    }
}
