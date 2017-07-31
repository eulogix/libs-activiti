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

class IdentityLink extends baseOMClass {

    const TYPE_CANDIDATE = 'candidate';

    /** @var string */
    protected $url;

    /** @var string */
    protected $user;

    /** @var string */
    protected $group;

    /** @var string */
    protected $type;

    /**
     * @return bool
     */
    public function isCandidate() {
        return $this->getType() == self::TYPE_CANDIDATE;
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
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @return string
     */
    public function getGroup()
    {
        return $this->group;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

} 