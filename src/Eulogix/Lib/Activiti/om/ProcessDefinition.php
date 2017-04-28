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

class ProcessDefinition extends baseOMClass {

    /** @var string */
    protected $id;

    /** @var string */
    protected $url;

    /** @var string */
    protected $key;

    /** @var string */
    protected $version;

    /** @var string */
    protected $name;

    /** @var string */
    protected $description;

    /** @var string */
    protected $deploymentId;

    /** @var string */
    protected $deploymentUrl;

    /** @var string */
    protected $resource;

    /** @var string */
    protected $diagramResource;

    /** @var string */
    protected $category;

    /** @var string */
    protected $graphicalNotationDefined;

    /** @var string */
    protected $suspended;

    /** @var string */
    protected $startFormDefined;

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
    public function getDeploymentId()
    {
        return $this->deploymentId;
    }

    /**
     * @return string
     */
    public function getDeploymentUrl()
    {
        return $this->deploymentUrl;
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
    public function getDiagramResource()
    {
        return $this->diagramResource;
    }

    /**
     * @return string
     */
    public function getGraphicalNotationDefined()
    {
        return $this->graphicalNotationDefined;
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
    public function getKey()
    {
        return $this->key;
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
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * @return string
     */
    public function getStartFormDefined()
    {
        return $this->startFormDefined;
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
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * @return string
     */
    public function getVersion()
    {
        return $this->version;
    }


} 