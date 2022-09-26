<?php

namespace Freelance\SubscriptionBundle\Command;

use Freelance\SubscriptionBundle\Library\ChargeSubscription\ChargeSubscriptionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ChargeSubscriptionCommand extends Command
{
    /**
     * @var ChargeSubscriptionService
     */
    private $chargeSubscriptionService;

    /**
     * @param ChargeSubscriptionService $chargeSubscriptionService
     */
    public function __construct(ChargeSubscriptionService $chargeSubscriptionService)
    {
        $this->chargeSubscriptionService = $chargeSubscriptionService;
        parent::__construct();
    }

    /**
     *
     */
    protected function configure(): void
    {
        $this->setName('recurrent_pay:subscription:charge');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->chargeSubscriptionService->tryToChargeSubscriptions();
        $output->writeln('');
    }
}