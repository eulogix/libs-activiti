<?php

/*
 * This file is part of the Eulogix\Lib package.
 *
 * (c) Eulogix <http://www.eulogix.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Eulogix\Lib\Activiti;

/**
 * @author Pietro Baricco <pietro@eulogix.com>
 */

class ActivitiResult {

    /**
     * @var array
     */
    private $data;

    /**
     * @var array
     */
    private $rawData;

    /**
     * @var int
     */
    private $total, $start, $size;

    /**
     * @var string
     */
    private $sort, $order;

    public function __construct($dataArray) {
        $this->total = @$dataArray['total'];
        $this->start = @$dataArray['start'];
        $this->sort = @$dataArray['sort'];
        $this->order = @$dataArray['order'];
        $this->size = @$dataArray['size'];
        $this->data = @$dataArray['data'];

        $this->rawData = $dataArray;
    }

    /**
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @param $number
     * @return array
     */
    public function getRow($number)
    {
        return $this->data[$number];
    }

    /**
     * @return array
     */
    public function getRawData()
    {
        return $this->rawData;
    }

    /**
     * @return string
     */
    public function getOrder()
    {
        return $this->order;
    }

    /**
     * @return int
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * @return string
     */
    public function getSort()
    {
        return $this->sort;
    }

    /**
     * @return int
     */
    public function getStart()
    {
        return $this->start;
    }

    /**
     * @return int
     */
    public function getTotal()
    {
        return $this->total;
    }

} 