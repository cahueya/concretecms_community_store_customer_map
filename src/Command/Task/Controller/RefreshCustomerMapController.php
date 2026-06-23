<?php

namespace Concrete\Package\CommunityStoreCustomerMap\Command\Task\Controller;

use Concrete\Core\Command\Task\Controller\AbstractController;
use Concrete\Core\Command\Task\Input\InputInterface;
use Concrete\Core\Command\Task\Runner\CommandTaskRunner;
use Concrete\Core\Command\Task\Runner\TaskRunnerInterface;
use Concrete\Core\Command\Task\TaskInterface;
use Concrete\Package\CommunityStoreCustomerMap\Command\RefreshCustomerMapCommand;

defined('C5_EXECUTE') or die('Access Denied.');

class RefreshCustomerMapController extends AbstractController
{
    public function getName(): string
    {
        return t('Refresh Customer Map');
    }

    public function getDescription(): string
    {
        return t('Reads Community Store orders, geocodes uncached billing addresses and rebuilds customer map aggregates.');
    }

    public function getTaskRunner(TaskInterface $task, InputInterface $input): TaskRunnerInterface
    {
        return new CommandTaskRunner($task, new RefreshCustomerMapCommand(), t('Customer map refreshed.'));
    }
}
