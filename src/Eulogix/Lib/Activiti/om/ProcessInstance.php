<?php

/*
 * This file is part of the Eulogix\Lib package.
 *
 * (c) Eulogix <http://www.eulogix.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Eulogix\Lib\Activiti\om;

/**
 * @author Pietro Baricco <pietro@eulogix.com>
 */

class ProcessInstance extends baseOMClass {

    /** @var string */
    protected $id;

    /** @var string */
    protected $url;

    /** @var string */
    protected $businessKey;

    /** @var bool */
    protected $suspended, $ended;

    /** @var string */
    protected $processDefinitionUrl;

    /** @var string */
    protected $activityId;

    /** @var string */
    protected $tenantId;

    /**
     * @return ProcessDefinition
     */
    public function getProcessDefinition() {
        return new ProcessDefinition($this->getClient()->fetchResourceUrl($this->getProcessDefinitionUrl()), $this->getClient());
    }

    /**
     * @param array $queryHash
     * @return Task[]
     */
    public function getTasks($queryHash=[]) {
        $ret = [];
        $tasks = $this->getClient()->getListOfTasks(array_merge([
                'processInstanceId' => $this->getId()
            ],$queryHash));
        for($i=0; $i<$tasks['total']; $i++) {
            $taskArray = $tasks['data'][$i];
            $ret[] = new Task($taskArray, $this->getClient());
        }
        return $ret;
    }

    /**
     * @return string
     */
    public function getActivityId()
    {
        return $this->activityId;
    }

    /**
     * @return string
     */
    public function getBusinessKey()
    {
        return $this->businessKey;
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getProcessDefinitionUrl()
    {
        return $this->processDefinitionUrl;
    }

    /**
     * @return bool
     */
    public function getSuspended()
    {
        return $this->suspended;
    }

    /**
     * @return string
     */
    public function getTenantId()
    {
        return $this->tenantId;
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * @return boolean
     */
    public function getEnded()
    {
        return $this->ended;
    }

} 