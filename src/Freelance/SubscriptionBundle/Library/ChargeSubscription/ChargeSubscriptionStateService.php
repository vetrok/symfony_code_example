<?php

namespace Freelance\SubscriptionBundle\Library\ChargeSubscription;

use Freelance\CoreBundle\Library\EntityManager\EdussonEntityManager;
use Freelance\PayBundle\Entity\PayTransaction;
use Freelance\PayBundle\Library\PayModel\PayTransactionService;
use Freelance\SubscriptionBundle\Entity\SubscriptionItem;
use Freelance\SubscriptionBundle\Event\ChargeSubscriptionEvent;
use Freelance\SubscriptionBundle\Event\SubscriptionEvent;
use Freelance\SubscriptionBundle\FreelanceSubscriptionBundleEvent;
use Freelance\SubscriptionBundle\Library\ChargeSubscription\API\ChargeSubscriptionResponse;
use Freelance\SubscriptionBundle\Library\PackageProcess\PackageProcessFactory;
use Freelance\SubscriptionBundle\Library\Subscription\SubscriptionItemLogService;
use Freelance\SystemBundle\Library\ErrorHandler\ErrorHandler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ChargeSubscriptionStateService
{
    /**
     * @var SubscriptionItemLogService
     */
    protected $subscriptionItemLogService;

    /**
     * @var EdussonEntityManager
     */
    protected $em;

    /**
     * @var PackageProcessFactory
     */
    protected $packageProcessFactory;

    /**
     * @var ChargeAttemptService
     */
    protected $chargeAttemptService;

    /**
     * @var PayTransactionService
     */
    protected $payTransactionService;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    public function __construct(
        EdussonEntityManager $em,
        PackageProcessFactory $packageProcessFactory,
        SubscriptionItemLogService $subscriptionItemLogService,
        ChargeAttemptService $chargeAttemptService,
        PayTransactionService $payTransactionService,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->em = $em;
        $this->packageProcessFactory = $packageProcessFactory;
        $this->subscriptionItemLogService = $subscriptionItemLogService;
        $this->chargeAttemptService = $chargeAttemptService;
        $this->payTransactionService = $payTransactionService;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @param PayTransaction $payTransaction
     * @param SubscriptionItem $subscriptionItem
     * @param ChargeSubscriptionResponse $payResponse
     */
    public function onResponseFail(
        PayTransaction $payTransaction,
        SubscriptionItem $subscriptionItem,
        ChargeSubscriptionResponse $payResponse
    ): void {

        $this->handleFailedChargeAttempt($subscriptionItem);
        $this->payTransactionService->onFail(
            $payTransaction,
            $payResponse->getBankTransactionId(),
            $payResponse->getErrorMessage(),
            $payResponse->getErrorMessageReal(),
            $payResponse->getEdussonGatewayTransactionId()
        );
        $this->subscriptionItemLogService->logChargeFail($subscriptionItem, $payTransaction);

        ErrorHandler::emailToIt(
            'Charge subscription failed',
            sprintf(
                'Pay Transaction ID: %d , Subscription item ID: %d, PaySubscriptionResponse: %s',
                $payTransaction->getId(),
                $subscriptionItem->getId(),
                serialize($payResponse)
            ),
            'vitalii.yatsenko@boosta.co'
        );
    }

    /**
     * @param PayTransaction $payTransaction
     * @param SubscriptionItem $subscriptionItem
     * @param ChargeSubscriptionResponse $payResponse
     */
    public function onResponseSuccess(
        PayTransaction $payTransaction,
        SubscriptionItem $subscriptionItem,
        ChargeSubscriptionResponse $payResponse
    ): void {
        $this->payTransactionService->onSuccess(
            $payTransaction,
            $payResponse->getBankTransactionId(),
            $payResponse->getEdussonGatewayTransactionId()
        );
        $this->subscriptionItemLogService->logChargeSuccess($subscriptionItem, $payTransaction);
        $this->packageProcessFactory->getUpdateProcess($subscriptionItem->getSubscriptionPackage())
                                    ->update($subscriptionItem);

        $this->eventDispatcher->dispatch(
            FreelanceSubscriptionBundleEvent::SUBSCRIPTION_CHARGE_SUCCESS,
            new ChargeSubscriptionEvent($payTransaction, $subscriptionItem, $payResponse)
        );
    }

    /**
     * @param PayTransaction $payTransaction
     * @param SubscriptionItem $subscriptionItem
     * @param ChargeSubscriptionResponse $payResponse
     */
    public function onResponsePending(
        PayTransaction $payTransaction,
        SubscriptionItem $subscriptionItem,
        ChargeSubscriptionResponse $payResponse
    ): void {
        $this->handleChargePendingCreated($subscriptionItem, $payTransaction);
        $this->payTransactionService->onPending(
            $payTransaction,
            $payResponse->getBankTransactionId(),
            $payResponse->getEdussonGatewayTransactionId()
        );
        $this->subscriptionItemLogService->logChargePendingCreated($subscriptionItem, $payTransaction);
    }

    /**
     * @param SubscriptionItem $subscriptionItem
     */
    protected function handleFailedChargeAttempt(SubscriptionItem $subscriptionItem): void
    {
        try {
            $completelyFailed = false;
            $subscriptionItem->incrementChargeAttempt();

            if ($this->chargeAttemptService->isMaxChargeAttemptsFailed($subscriptionItem)) {
                $subscriptionItem->setIsChargeable(false);
                $completelyFailed = true;
            } else {
                $nextChargeDate = $this->chargeAttemptService->getNextChargeDate($subscriptionItem);
                $subscriptionItem->setNextChargeDate($nextChargeDate);
            }

            $subscriptionItem->setIsChargePending(false);
            $subscriptionItem->setChargePayTransaction(null);
            $this->em->flush($subscriptionItem);

            if ($completelyFailed) {
                $this->eventDispatcher->dispatch(
                    FreelanceSubscriptionBundleEvent::SUBSCRIPTION_CHARGE_COMPLETELY_FAILED,
                    new SubscriptionEvent($subscriptionItem)
                );
            }
        } catch (\Exception $e) {
            ErrorHandler::emailToIt(
                'Handle next charge attempt exception',
                'Message: ' . $e->getMessage(),
                'vitalii.yatsenko@boosta.co'
            );
        }
    }

    /**
     * @param SubscriptionItem $subscriptionItem
     * @param PayTransaction $payTransaction
     */
    protected function handleChargePendingCreated(SubscriptionItem $subscriptionItem, PayTransaction $payTransaction): void
    {
        $subscriptionItem->setIsChargePending(true);
        $subscriptionItem->setChargePayTransaction($payTransaction);
        $this->em->flush($subscriptionItem);
    }
}