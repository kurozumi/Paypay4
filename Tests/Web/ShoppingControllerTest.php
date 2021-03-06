<?php
/**
 * This file is part of Plugin
 *
 * Copyright(c) Akira Kurozumi <info@a-zumi.net>
 *
 * https://a-zumi.net
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Plugin\paypay4\Tests\Web;


use Eccube\Common\Constant;
use Eccube\Entity\Delivery;
use Eccube\Entity\Payment;
use Eccube\Entity\PaymentOption;
use Eccube\Repository\DeliveryRepository;
use Eccube\Repository\PaymentRepository;
use Eccube\Tests\Web\AbstractShoppingControllerTestCase;
use Plugin\paypay4\Service\Method\PayPay;

class ShoppingControllerTest extends AbstractShoppingControllerTestCase
{
    /**
     * @var PaymentRepository
     */
    protected $paymentRepository;

    /**
     * @var DeliveryRepository
     */
    protected $deliveryRepository;

    public function setUp()
    {
        parent::setUp(); // TODO: Change the autogenerated stub

        $this->paymentRepository = $this->entityManager->getRepository(Payment::class);
        $this->deliveryRepository = $this->entityManager->getRepository(Delivery::class);
    }

    public function tearDown()
    {
        parent::tearDown(); // TODO: Change the autogenerated stub
    }

    public function testお支払い方法にPayPay決済が表示されるか()
    {
        /** @var Delivery $delivery 販売種別Aのサンプル業者 */
        $delivery = $this->deliveryRepository->find(1);

        $this->createPaymentOption($delivery);

        $Custoemr = $this->createCustomer();

        // カート画面
        $this->scenarioCartIn($Custoemr);

        // 確認画面
        $crawler = $this->scenarioConfirm();

        self::assertContains("PayPay", $crawler->html());
    }

    public function testPayPay決済ページへリダイレクトされるか()
    {
        /** @var Delivery $delivery 販売種別Aのサンプル業者 */
        $delivery = $this->deliveryRepository->find(1);

        $paymentOption = $this->createPaymentOption($delivery);

        $Customer = $this->createCustomer();

        $this->loginTo($Customer);

        // カート画面
        $this->scenarioCartIn($Customer);

        // 手続き画面
        $this->scenarioConfirm($Customer);

        // 確認画面
        $this->client->request(
            'POST',
            $this->generateUrl('shopping_confirm'),
            [
                '_shopping_order' => [
                    'Shippings' => [
                        [
                            'Delivery' => 1,
                            'DeliveryTime' => 1,
                        ],
                    ],
                    'Payment' => $paymentOption->getPaymentId(),
                    'use_point' => 0,
                    Constant::TOKEN_NAME => '_dummy'
                ]
            ]
        );

        $this->client->request(
            'POST',
            $this->generateUrl('shopping_checkout'),
            [
                '_shopping_order' => [
                    Constant::TOKEN_NAME => '_dummy'
                ]
            ]
        );

        self::assertTrue($this->client->getResponse()->isRedirection());
        self::assertContains("paypay.ne.jp", $this->client->getResponse()->getContent());
    }

    private function createPaymentOption(Delivery $delivery): PaymentOption
    {
        /** @var Payment $payment */
        $payment = $this->paymentRepository->findOneBy([
            "method_class" => PayPay::class
        ]);

        $paymentOption = new PaymentOption();
        $paymentOption
            ->setDeliveryId($delivery->getId())
            ->setDelivery($delivery)
            ->setPaymentId($payment->getId())
            ->setPayment($payment);
        $this->entityManager->persist($paymentOption);
        $this->entityManager->flush();

        return $paymentOption;
    }
}
