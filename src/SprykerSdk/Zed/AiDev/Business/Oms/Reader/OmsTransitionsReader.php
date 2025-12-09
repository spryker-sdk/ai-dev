<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerSdk\Zed\AiDev\Business\Oms\Reader;

use Orm\Zed\Oms\Persistence\Map\SpyOmsOrderItemStateTableMap;
use Orm\Zed\Oms\Persistence\Map\SpyOmsOrderProcessTableMap;
use Orm\Zed\Sales\Persistence\SpySalesOrderQuery;
use Spryker\Zed\Oms\Business\OmsFacadeInterface;
use Spryker\Zed\Oms\Business\Process\TransitionInterface;

class OmsTransitionsReader implements OmsTransitionsReaderInterface
{
    protected const string PROCESS_NAME = 'processName';

    protected const string STATE_NAMES = 'stateNames';

    public function __construct(protected OmsFacadeInterface $omsFacade)
    {
    }

    public function getOrderOmsTransitions(string $orderReference): string
    {
        $orderData = $this->getProcessAndStateNamesByOrderReference($orderReference);
        if ($orderData[static::PROCESS_NAME] === null) {
            return '{}';
        }

        return $this->buildTransitionsResponse($orderData[static::PROCESS_NAME], $orderData[static::STATE_NAMES]);
    }

    public function getOmsTransitionsByState(string $stateName, string $processName = ''): string
    {
        return $this->buildTransitionsResponse($processName, [$stateName]);
    }

    /**
     * @param string $processName
     * @param array<string> $stateNames
     *
     * @return string
     */
    protected function buildTransitionsResponse(string $processName, array $stateNames): string
    {
        if ($stateNames === []) {
            return '{}';
        }

        $processes = $this->omsFacade->getProcesses();
        $result = [];

        foreach ($processes as $process) {
            $currentProcessName = $process->getName();

            if ($processName !== '' && $currentProcessName !== $processName) {
                continue;
            }

            $transitions = $process->getAllTransitions();
            $processTransitions = [];

            foreach ($transitions as $transition) {
                $sourceStateName = $transition->getSource()->getName();

                if (!in_array($sourceStateName, $stateNames, true)) {
                    continue;
                }

                $transitionData = $this->buildTransitionData($transition);
                $processTransitions[] = $transitionData;
            }

            if ($processTransitions === [] && $processName === '') {
                continue;
            }

            $result[$currentProcessName] = [
                'providedStateNames' => $stateNames,
                'process' => $currentProcessName,
                'file' => $process->hasFile() ? $process->getFile() : null,
                'transitions' => $processTransitions,
            ];
        }

        return json_encode($result, JSON_PRETTY_PRINT);
    }

    /**
     * @param \Spryker\Zed\Oms\Business\Process\TransitionInterface $transition
     *
     * @return array<string, mixed>
     */
    protected function buildTransitionData(TransitionInterface $transition): array
    {
        $transitionData = [
            'source' => $transition->getSource()->getName(),
            'target' => $transition->getTarget()->getName(),
            'event' => null,
            'condition' => null,
        ];

        if ($transition->hasEvent()) {
            $event = $transition->getEvent();
            $transitionData['event'] = [
                'name' => $event->getName(),
                'manual' => $event->isManual(),
                'onEnter' => $event->isOnEnter(),
                'timeout' => $event->getTimeout(),
                'command' => $event->getCommand(),
            ];
        }

        if ($transition->hasCondition()) {
            $transitionData['condition'] = $transition->getCondition();
        }

        $transitionData['happy'] = $transition->isHappy();

        return $transitionData;
    }

    /**
     * @param string $orderReference
     *
     * @return array<string, mixed>
     */
    protected function getProcessAndStateNamesByOrderReference(string $orderReference): array
    {
        $result = SpySalesOrderQuery::create()
            ->filterByOrderReference($orderReference)
            ->useItemQuery()
                ->useProcessQuery()
                    ->withColumn(SpyOmsOrderProcessTableMap::COL_NAME, static::PROCESS_NAME)
                ->endUse()
                ->useStateQuery()
                    ->withColumn(sprintf('GROUP_CONCAT(DISTINCT %s)', SpyOmsOrderItemStateTableMap::COL_NAME), static::STATE_NAMES)
                ->endUse()
                ->groupBy(SpyOmsOrderProcessTableMap::COL_NAME)
            ->endUse()
            ->select([static::PROCESS_NAME, static::STATE_NAMES])
            ->findOne();

        if ($result === null) {
            return [
                static::PROCESS_NAME => null,
                static::STATE_NAMES => [],
            ];
        }

        return [
            static::PROCESS_NAME => $result[static::PROCESS_NAME],
            static::STATE_NAMES => $result[static::STATE_NAMES] ? explode(',', $result[static::STATE_NAMES]) : [],
        ];
    }
}
