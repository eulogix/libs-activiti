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
 * Beware that this has changed (undocumented) in Activiti 5.17+
 * @author Pietro Baricco <pietro@eulogix.com>
 */

class Task extends baseOMClass {



    /** @var string */
    protected $id;

    /** @var string */
    protected $url;

    /** @var string */
    protected $owner;

    /** @var string */
    protected $assignee;

    /** @var string */
    protected $delegationState;

    /** @var string */
    protected $name;

    /** @var string */
    protected $description;

    /** @var string */
    protected $createTime;

    /** @var string */
    protected $dueDate;

    /** @var string */
    protected $priority;

    /** @var string */
    protected $suspended;

    /** @var string */
    protected $taskDefinitionKey;

    /** @var string */
    protected $tenantId;

    /** @var string */
    protected $category;

    /** @var string */
    protected $formKey;

    /** @var string */
    protected $parentTaskId;

    /** @var string */
    protected $parentTaskUrl;

    /** @var string */
    protected $executionId;

    /** @var string */
    protected $executionUrl;

    /** @var string */
    protected $processInstanceId;

    /** @var string */
    protected $processInstanceUrl;

    /** @var string */
    protected $processDefinitionId;

    /** @var string */
    protected $processDefinitionUrl;

    /**
     * @return ProcessDefinition
     */
    public function getProcessDefinition() {
        return new ProcessDefinition($this->getClient()->fetchResourceUrl($this->getProcessDefinitionUrl()), $this->getClient());
    }

    /**
     * @return ProcessInstance
     */
    public function getProcessInstance() {
        return new ProcessInstance($this->getClient()->fetchResourceUrl($this->getProcessInstanceUrl()), $this->getClient());
    }

    /**
     * @return string
     */
    public function getAssignee()
    {
        return $this->assignee;
    }

    /**
     * @return string
     */
    public function getCategory()
    {
        return $this->category;
    }

    /**
     * @return string
     */
    public function getCreateTime()
    {
        return $this->createTime;
    }

    /**
     * @return string
     */
    public function getDelegationState()
    {
        return $this->delegationState;
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * @return string
     */
    public function getDueDate()
    {
        return $this->dueDate;
    }

    /**
     * @return string
     */
    public function getExecutionId()
    {
        return $this->executionId;
    }

    /**
     * @return string
     */
    public function getExecutionUrl()
    {
        return $this->executionUrl;
    }

    /**
     * @return string
     */
    public function getFormKey()
    {
        return $this->formKey;
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
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getOwner()
    {
        return $this->owner;
    }

    /**
     * @return string
     */
    public function getParentTaskId()
    {
        return $this->parentTaskId;
    }

    /**
     * @return string
     */
    public function getParentTaskUrl()
    {
        return $this->parentTaskUrl;
    }

    /**
     * @return string
     */
    public function getPriority()
    {
        return $this->priority;
    }

    /**
     * @return string
     */
    public function getProcessDefinitionId()
    {
        return $this->processDefinitionId;
    }

    /**
     * @return string
     */
    public function getProcessDefinitionUrl()
    {
        return $this->processDefinitionUrl;
    }

    /**
     * @return string
     */
    public function getProcessInstanceId()
    {
        return $this->processInstanceId;
    }

    /**
     * @return string
     */
    public function getProcessInstanceUrl()
    {
        return $this->processInstanceUrl;
    }

    /**
     * @return string
     */
    public function getSuspended()
    {
        return $this->suspended;
    }

    /**
     * @return string
     */
    public function getTaskDefinitionKey()
    {
        return $this->taskDefinitionKey;
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
     * @param string $user
     * @return boolean
     */
    public function isAssignedTo($user)
    {
        return $this->getAssignee() == $user;
    }

    /**
     * @param string $user
     * @param string[] $groups
     * @return boolean
     */
    public function canBeClaimedBy($user, array $groups)
    {
        $identityLinks = $this->getIdentityLinks();
        foreach($identityLinks as $link)
            if($link->isCandidate() && ($link->getUser() == $user || in_array($link->getGroup(), $groups)))
                return true;
        return false;
    }

    /**
     * @return IdentityLink[]
     */
    public function getIdentityLinks() {
        $client = $this->getClient();
        $identities = $this->getClient()->getAllIdentitylinksForTask($this->getId());
        return array_map(function($identityArray) use ($client) {
            return new IdentityLink($identityArray, $client);
        }, $identities);
    }

} 