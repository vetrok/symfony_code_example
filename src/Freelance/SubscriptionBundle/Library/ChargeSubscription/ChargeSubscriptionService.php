<?php

namespace Freelance\SubscriptionBundle\Library\ChargeSubscription;

use Freelance\CoreBundle\Library\EntityManager\EdussonEntityManager;
use Freelance\PayBundle\Entity\Account\AbstractPayAccount;
use Freelance\PayBundle\Entity\PaymentSystem;
use Freelance\PayBundle\Entity\PayTransaction;
use Freelance\PayBundle\Library\PayModel\PayTransactionService;
use Freelance\SubscriptionBundle\Entity\SubscriptionItem;
use Freelance\SubscriptionBundle\Library\ChargeSubscription\API\Request\ChargeSubscriptionService as  APIChargeSubscriptionService;
use Freelance\SubscriptionBundle\Library\PaidModel\SubscriptionCharge;
use Freelance\SystemBundle\Library\ErrorHandler\ErrorHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ChargeSubscriptionService
{
    private const RETURN_URL_STUB = '/';

    /**
     * @var APIChargeSubscriptionService
     */
    private $chargeSubscriptionApiService;
    /**
     * @var ContainerInterface
     */
    private $container;
    /**
     * @var ChargeSubscriptionStateService
     */
    private $chargeSubscriptionStateService;
    /**
     * @var EdussonEntityManager
     */
    private $em;
    /**
     * @var ChargeAttemptService
     */
    private $chargeAttemptService;
    /**
     * @var PayTransactionService
     */
    private $payTransactionService;

    public function __construct(
        ContainerInterface $container,
        EdussonEntityManager $em,
        ChargeAttemptService $chargeAttemptService,
        PayTransactionService $payTransactionService,
        ChargeSubscriptionStateService $chargeSubscriptionStatusService,
        APIChargeSubscriptionService $chargeSubscriptionApiService
    ) {
        $this->container = $container;
        $this->em = $em;
        $this->chargeSubscriptionApiService = $chargeSubscriptionApiService;
        $this->chargeSubscriptionStateService = $chargeSubscriptionStatusService;
        $this->chargeAttemptService = $chargeAttemptService;
        $this->payTransactionService = $payTransactionService;
    }

    /**
     *
     */
    public function tryToChargeSubscriptions(): void
    {
        $soonExpiredSubscriptions = $this->getSoonExpiredSubscriptions();
        foreach ($soonExpiredSubscriptions as $soonExpiredSubscription) {
            if ($this->isSubscriptionItemValid($soonExpiredSubscription)) {
                $this->charge($soonExpiredSubscription);
            }
        }
    }

    /**
     * @param SubscriptionItem $subscriptionItem
     */
    private function charge(SubscriptionItem $subscriptionItem): void
    {
        $payTransaction = $this->createPayTransaction($subscriptionItem);

        $payResponse = $this->chargeSubscriptionApiService->charge(
            $payTransaction,
            $subscriptionItem->getPaymentInfo()->getSubscriptionTokenId()
        );

        switch (true) {
            case $payResponse->isPending():
                $this->chargeSubscriptionStateService->onResponsePending($payTransaction, $subscriptionItem, $payResponse);
                break;
            case $payResponse->isSuccess():
                $this->chargeSubscriptionStateService->onResponseSuccess($payTransaction, $subscriptionItem, $payResponse);
                break;
            case $payResponse->isUnknown():
            case $payResponse->isFail():
            default:
                $this->chargeSubscriptionStateService->onResponseFail($payTransaction, $subscriptionItem, $payResponse);
                break;
        }
    }

    /**
     * @param SubscriptionItem $subscriptionItem
     * @return PayTransaction
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    private function createPayTransaction(SubscriptionItem $subscriptionItem): PayTransaction
    {
        $paidModel = $this->container->get(SubscriptionCharge::class)->create($subscriptionItem);
        $paymentSystem = $this->getPaymentSystem();
        $payAccount = $this->getPayAccount();

        return $this->payTransactionService->createPayTransaction(
            $paidModel,
            $paymentSystem,
            self::RETURN_URL_STUB,
            null,
            $subscriptionItem->getUser()->getSite(),
            $payAccount
        );
    }

    /**
     * @return array
     * @throws \Exception
     */
    private function getSoonExpiredSubscriptions(): array
    {
        return $this->em->getRepository(SubscriptionItem::class)
            ->createQueryBuilder('si')
            ->where('si.nextChargeDate < :now')
            ->andWhere('si.isActive = true')
            ->andWhere('si.isChargeable = true')
            ->andWhere('si.isChargePending = false')
            ->setParameter('now', new \DateTime('now'))
            ->getQuery()
            ->getResult();
    }

    /**
     * @param SubscriptionItem $subscriptionItem
     * @return bool
     * @throws \Exception
     */
    private function isSubscriptionItemValid(SubscriptionItem $subscriptionItem): bool
    {
        if ($subscriptionItem->getDateEnding() < new \DateTime('now')) {
            ErrorHandler::emailToIt(
                'Subscription DateEnding is in past time',
                sprintf('Subscription item ID: %d', $subscriptionItem->getId()),
                'vitalii.yatsenko@boosta.co'
            );
            return false;
        }

        $paymentInfo = $subscriptionItem->getPaymentInfo();
        if (!$paymentInfo) {
            ErrorHandler::emailToIt(
                "SubscriptionItem is chargeable but doesn't have payment info",
                sprintf('Subscription item ID: %d', $subscriptionItem->getId()),
                'vitalii.yatsenko@boosta.co'
            );
            return false;
        }

        $subscriptionTokenId = $paymentInfo->getSubscriptionTokenId();
        if (!$subscriptionTokenId) {
            ErrorHandler::emailToIt(
                "SubscriptionItem has payment info but doesn't have subscription token",
                sprintf(
                    'Subscription item ID: %d. Payment Info ID: %d',
                    $subscriptionItem->getId(),
                    $paymentInfo->getId()
                ),
                'vitalii.yatsenko@boosta.co'
            );
            return false;
        }

        if ($this->chargeAttemptService->isMoreThanMaxChargeAttemptsFailed($subscriptionItem)) {
            ErrorHandler::emailToIt(
                'Subscription charge attempt is too big',
                sprintf('Subscription item ID: %d', $subscriptionItem->getId()),
                'vitalii.yatsenko@boosta.co'
            );
            return false;
        }

        return true;
    }

    /**
     * @return PaymentSystem
     */
    private function getPaymentSystem(): PaymentSystem
    {
        return $this->em->getRepository(PaymentSystem::class)
            ->find(PaymentSystem::EDUSSON_GATEWAY);
    }

    /**
     * @return AbstractPayAccount
     */
    private function getPayAccount(): AbstractPayAccount
    {
        return $this->em->getReference(AbstractPayAccount::class, AbstractPayAccount::GATEWAY_PAYCORE);
    }
}