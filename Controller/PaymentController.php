<?php
/**
 * This file is part of paypay4
 *
 * Copyright(c) Akira Kurozumi <info@a-zumi.net>
 *
 * https://a-zumi.net
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Plugin\paypay4\Controller;


use Eccube\Controller\AbstractShoppingController;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Repository\Master\OrderStatusRepository;
use Eccube\Repository\OrderRepository;
use Eccube\Service\CartService;
use Eccube\Service\MailService;
use Eccube\Service\OrderHelper;
use Eccube\Service\PurchaseFlow\PurchaseContext;
use PayPay\OpenPaymentAPI\Client;
use Plugin\paypay4\Repository\PaymentStatusRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class PaymentController
 * @package Plugin\paypay4\Controller
 *
 * @Route("/shopping/paypay")
 */
class PaymentController extends AbstractShoppingController
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var OrderStatusRepository
     */
    private $orderStatusRepository;

    /**
     * @var PaymentStatusRepository
     */
    private $paymentStatusRepository;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var CartService
     */
    private $cartService;

    /**
     * @var MailService
     */
    private $mailService;

    public function __construct(
        Client $client,
        OrderStatusRepository $orderStatusRepository,
        PaymentStatusRepository $paymentStatusRepository,
        OrderRepository $orderRepository,
        CartService $cartService,
        MailService $mailService
    )
    {
        $this->client = $client;
        $this->orderStatusRepository = $orderStatusRepository;
        $this->paymentStatusRepository = $paymentStatusRepository;
        $this->orderRepository = $orderRepository;
        $this->cartService = $cartService;
        $this->mailService = $mailService;
    }

    /**
     * @param Request $request
     * @param $order_no
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     * @throws \Eccube\Service\PurchaseFlow\PurchaseException
     *
     * @Route("/complete/{order_no}", name="paypay_checkout", methods={"GET"})
     */
    public function complete(Request $request, $order_no)
    {
        $OrderStatus = $this->orderStatusRepository->find(OrderStatus::PENDING);

        /** @var Order $Order */
        $Order = $this->orderRepository->findOneBy([
            'order_no' => $order_no,
            'Customer' => $this->getUser(),
            'OrderStatus' => $OrderStatus
        ]);

        if (!$Order) {
            throw new NotFoundHttpException();
        }

        $response = $this->client->payment->getPaymentDetails($Order->getOrderNo());

        if ($response['resultInfo']["code"] !== "SUCCESS") {
            log_error("[PayPay][注文処理]決済エラー");
            $this->addError("決済エラー");

            return $this->rollbackOrder($Order);
        }

        switch ($response["data"]["status"]) {
            case "COMPLETED":
                // purchaseFlow::commitを呼び出し、購入処理をさせる
                $this->purchaseFlow->commit($Order, new PurchaseContext());

                log_info('[PayPay][注文処理] カートをクリアします.', [$Order->getId()]);
                $this->cartService->clear();

                // 受注IDをセッションにセット
                $this->session->set(OrderHelper::SESSION_ORDER_ID, $Order->getId());

                // メール送信
                log_info('[PayPay][注文処理] 注文メールの送信を行います.', [$Order->getId()]);
                $this->mailService->sendOrderMail($Order);
                $this->entityManager->flush();

                log_info('[PayPay][注文処理] 注文処理が完了しました. 購入完了画面へ遷移します.', [$Order->getId()]);
                break;
            case "EXPIRED":
                return $this->rollbackOrder($Order);
                break;
            default:
                return $this->rollbackOrder($Order);
        }

        return $this->redirectToRoute("shopping_complete");
    }

    /**
     * @param Order $Order
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    protected function rollbackOrder(Order $Order)
    {
        $this->purchaseFlow->rollback($Order, new PurchaseContext());

        $this->entityManager->flush();

        return $this->redirectToRoute("shopping_error");
    }
}
