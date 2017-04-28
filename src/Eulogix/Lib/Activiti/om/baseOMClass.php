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

use Eulogix\Lib\Activiti\ActivitiClient;
use Eulogix\Lib\Util\Bean;

/**
 * Client class for Activiti
 *
 * @author Pietro Baricco <pietro@eulogix.com>
 *
 */

class baseOMClass extends Bean {

    /**
     * @var ActivitiClient
     */
    private $client;

    public function __construct($map, ActivitiClient $client) {
        parent::__construct($map);
        $this->client = $client;
    }

    /**
     * @return ActivitiClient
     */
    public function getClient()
    {
        return $this->client;
    }
}