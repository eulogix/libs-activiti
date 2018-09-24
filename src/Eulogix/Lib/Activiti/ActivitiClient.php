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
 * Client class for Activiti
 *
 * @author Pietro Baricco <pietro@eulogix.com>
 *
 */

class ActivitiClient {

    const PARAM_START = 'start';
    const PARAM_SORT = 'sort';
    const PARAM_ORDER = 'order';
    const PARAM_SIZE = 'size';

    const NULL = '*null*';

    /**
     * @var string
     */
    private $url, $userName, $password;

    /**
     * @var \PDO
     */
    private $pdo;

    public function __construct($httpUrl, $userName, $password, \PDO $directConnection=null) {
        $this->url = $httpUrl;
        $this->userName = $userName;
        $this->password = $password;
        $this->pdo = $directConnection;
    }

    /**
     * @param $httpVerb
     * @param $url
     * @param array|string $requestBody
     * @param array $parameters
     * @param array $urlParameterKeys
     * @param array $jsonParameterKeys
     * @param array $returnCodes
     * @param array $multiparts
     * @throws \Exception
     * @return ActivitiResult|array|null
     */
    public function fetch($httpVerb, $url,  $requestBody=null, $parameters=[], $urlParameterKeys=[], $jsonParameterKeys=[], $returnCodes=[], $multiparts=[]) {

        $realUrl = $url;
        $queryString = [];
        foreach($urlParameterKeys as $paramName) {
            $paramValue = @$parameters[ $paramName ];
            $needle = "{{$paramName}}";
            if(strpos($realUrl, $needle)!==false)
                $realUrl = str_replace($needle, urlencode($paramValue), $realUrl);
            else $queryString[$paramName] = $paramValue;
        }

        if($multiparts) {
            $raw = $this->fetchRawMultipart($realUrl, $queryString, $requestBody, $multiparts);
        } else {
            $postData = is_array($requestBody) ? json_encode($requestBody) : $requestBody;
            $raw = $this->fetchRaw($httpVerb, $realUrl, $queryString, $postData);
        }

        $statusCode = @$raw['statusCode'];
        $resultBody = @$raw['body'];

        $jsonDec = json_decode($resultBody, true);

        if($statusCode >= 200 && $statusCode < 300) {
            if($jsonDec !== null) {
                if(isset($jsonDec['data']) && isset($jsonDec['size']))
                     return new ActivitiResult($jsonDec);
                else return $jsonDec;
            }
            return $resultBody ? $resultBody : $statusCode;
        } else {
            $message = 'HTTP '.$statusCode.' : '.@$returnCodes[$statusCode];

            if($urlParameterKeys) {
                $message.="\n--- URL parameters: ---\n";
                foreach($urlParameterKeys as $k)
                    $message.="$k\n";
            }

            if($jsonParameterKeys) {
                $message.="\n--- JSON parameters: ---\n";
                foreach($jsonParameterKeys as $k)
                    $message.="$k\n";
            }

            foreach(['errorMessage', 'message', 'exception'] as $key)
                if($jsonDec !== null && isset($jsonDec[$key])) {
                    $message.="\n---\n{$key}: ".$jsonDec[$key];
                }

            throw new \Exception($message, $statusCode);
        }
    }

    /**
     * @param $url
     * @return ActivitiResult|array|mixed
     * @throws \Exception
     */
    public function fetchResourceUrl($url) {
        $raw = $this->fetchRaw('GET', $url);
        $statusCode = @$raw['statusCode'];
        $resultBody = @$raw['body'];
        $jsonDec = json_decode($resultBody, true);

        if($statusCode >= 200 && $statusCode < 300) {
            if($jsonDec !== null) {
                if(isset($jsonDec['data']) && isset($jsonDec['size']))
                    return new ActivitiResult($jsonDec);
                else return $jsonDec;
            }
            return $resultBody;
        } else {
            throw new \Exception('FETCH RESOURCE '.$statusCode, $statusCode);
        }
    }


    /**
     * @param $httpVerb
     * @param $url
     * @param array $parameters
     * @param string $postData
     * @throws \Exception
     * @return array
     */
    public function fetchRaw($httpVerb, $url, $parameters=[], $postData=null) {
        $c = $this->getCurl($postData ? ["Content-Type: application/json"]:[]);

        if(!preg_match('/^http.+?/sim', $url)) {
            $query = http_build_query($parameters);
            curl_setopt ($c, CURLOPT_URL, $this->url.'/'.$url.($query?"?$query":''));
        } else curl_setopt ($c, CURLOPT_URL, $url);

        curl_setopt ($c, CURLINFO_HEADER_OUT ,1);
        curl_setopt ($c, CURLOPT_VERBOSE, 1);
        curl_setopt ($c, CURLOPT_HEADER ,1);

        switch($httpVerb) {
            case 'PUT':
            case 'DELETE': curl_setopt($c, CURLOPT_CUSTOMREQUEST, $httpVerb); break;
            case 'POST':  curl_setopt ($c, CURLOPT_POST, 1); break;
        }

        if($postData)
            curl_setopt ($c, CURLOPT_POSTFIELDS, str_replace("\n","\r\n",$postData));

        $response = curl_exec ($c);
        if(!$response) {
            throw new \Exception(curl_error( $c ));
        }

        $headerSize = curl_getinfo($c, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $headerSize);

        $body = substr($response, $headerSize);

        return [
            'statusCode' => curl_getinfo($c, CURLINFO_HTTP_CODE),
            'body' => $body
        ];
    }

    /**
     * @param $url
     * @param array $parameters
     * @param array $postData
     * @param array $multiparts
     * @throws \Exception
     * @return array
     */
    public function fetchRawMultipart($url, $parameters=[], $postData = [], $multiparts=[]) {
        $c = $this->getCurl(["Content-Type: multipart/form-data;"]);

        $query = http_build_query($parameters);

        curl_setopt ($c, CURLOPT_URL, $this->url.'/'.$url.($query?"?$query":''));
        curl_setopt ($c, CURLINFO_HEADER_OUT ,1);
        curl_setopt ($c, CURLOPT_VERBOSE, 1);
        curl_setopt ($c, CURLOPT_HEADER ,1);

        foreach($multiparts as $mp) {
            $postData['file'] = $this->getMultiPart($mp);
        }

        curl_setopt($c, CURLOPT_POST, true);
        curl_setopt($c, CURLOPT_POSTFIELDS, $postData);

        $response = curl_exec ($c);
        if(!$response) {
            throw new \Exception(curl_error( $c ));
        }

        $headerSize = curl_getinfo($c, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $headerSize);

        $body = substr($response, $headerSize);

        return [
            'statusCode' => curl_getinfo($c, CURLINFO_HTTP_CODE),
            'body' => $body
        ];
    }

    private function getMultiPart($part) {
        switch(@$part['type']) {
            case 'file' : {
                return new \CURLFile($part['file'], @$part['mimeType'], @$part['postName']);
                break;
            }
            //TODO: form data, etc
        }
    }


    /**
     * @param array $header
     * @return resource
     */
    private function getCurl($header=[]) {

        $header[]  = 'Accept: application/json';

        $ch = curl_init($this->url);
        curl_setopt ($ch, CURLOPT_USERAGENT, "Cool PHP Activiti Client");
        curl_setopt ($ch, CURLOPT_USERPWD, $this->userName . ":" . $this->password);
        curl_setopt ($ch, CURLOPT_HTTPHEADER, $header);

        curl_setopt ($ch, CURLOPT_ENCODING, '');
        curl_setopt ($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);

        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 20000);
        curl_setopt($ch, CURLOPT_TIMEOUT, 100);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS , 5000);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT , 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // stop verifying certificate

        return $ch;
    }


    /**
     * returns the number of pending tasks for a given assignee, grouped by the task key.
     * This means that it does consider different versions of the same process definition as the same thing.
     *
     * @param $assignee
     * @param array $inputHash
     * @return ActivitiResult
     */
    public function getUserTaskCount($assignee, $inputHash=[]) {
        return $this->getFlatListOfTasks($inputHash, true);
    }

    /**
     * This method mimics the getListOfTasks method, but uses raw SQL to overcome a limitation/bug in activiti
     * (up to at least 5.17.0), that breaks searching for process instance business key.
     * Hopefully, this will be removed once the Activiti REST implementation gets fixed.
     * It also allows for a more complex combination of criteria
     *
     * @param array $inputHash
     * @return ActivitiResult
     */
    public function getFlatListOfTasks($inputHash = [], $count=false) {

        $select = "select  t.id_ AS \"id\",
                        t.execution_id_ AS \"executionId\",
                        t.proc_inst_id_ AS \"processInstanceId\",
                        t.proc_def_id_ AS \"processDefinitionId\",
                        t.name_ AS \"name\",
                        t.parent_task_id_ AS \"parentTaskId\",
                        t.description_ AS \"description\",
                        t.task_def_key_ AS \"taskDefinitionKey\",
                        t.owner_ AS \"owner\",
                        t.assignee_ AS \"assignee\",
                        t.delegation_ AS \"delegationState\",
                        t.priority_ AS \"priority\",
                        t.create_time_ AS \"createTime\",
                        t.due_date_ AS \"dueDate\",
                        t.suspension_state_ AS \"suspended\",
                        t.tenant_id_ AS \"tenantId\",
                        t.form_key_ AS \"formKey\",

                        pi.business_key_ AS \"businessKey\",

                        procdef.key_ AS \"processDefinitionKey\"";


        $from = "       FROM ACT_RU_TASK t
                        LEFT JOIN ACT_RE_PROCDEF procdef ON procdef.id_= t.proc_def_id_
                        LEFT JOIN ACT_RU_EXECUTION pi ON pi.id_ = t.proc_inst_id_";

        $mainWhereClauses = ['TRUE'];
        $parameters = [];
        $assignmentWhereClauses = [];

        foreach($inputHash as $k => $v) {
            switch($k) {
                case 'processInstanceBusinessKeyLike' : {
                    if(is_array($v)) {
                        $conditions = [];
                        $i = 0;
                        foreach($v as $vl) {
                            $conditions[] = "pi.business_key_ LIKE :processInstanceBusinessKeyLike{$i}";
                            $parameters[":processInstanceBusinessKeyLike{$i}"] = $vl;
                            $i++;
                        }
                        $mainWhereClauses[] = "(". implode(' OR ', $conditions) . ")";
                    } else {
                        $mainWhereClauses[] = "pi.business_key_ LIKE :processInstanceBusinessKeyLike";
                        $parameters[':processInstanceBusinessKeyLike'] = $v;
                    }
                    break;
                }
                case 'processDefinitionKeyLike' : {
                    $mainWhereClauses[] = "procdef.key_ LIKE :processDefinitionKeyLike";
                    $parameters[':processDefinitionKeyLike'] = $v;
                    break;
                }
                //following conditions are OR'd, unless "null" is passed, in which case the condition becomes negative and is ANDed
                case 'assignee' : {
                    if($v == self::NULL)
                        $mainWhereClauses[] = "t.assignee_ IS NULL";
                    else {
                        $assignmentWhereClauses[] = "t.assignee_ = :assignee";
                        $parameters[':assignee'] = $v;
                    }
                    break;
                }
                case 'candidateUser' : {
                    $assignmentWhereClauses[] = "t.id_ IN (SELECT task_id_ FROM ACT_RU_IDENTITYLINK WHERE user_id_ = :candidateUser)";
                    $parameters[':candidateUser'] = $v;
                    break;
                }
                case 'candidateGroup' : {
                    if(is_array($v)) {
                        $conditions = [];
                        $i = 0;
                        foreach($v as $vl) {
                            $conditions[] = "group_id_ = :candidateGroup{$i}";
                            $parameters[":candidateGroup{$i}"] = $vl;
                            $i++;
                        }
                        $assignmentWhereClauses[] = "t.id_ IN (SELECT task_id_ FROM ACT_RU_IDENTITYLINK WHERE (". implode(' OR ', $conditions) . "))";
                    } else {
                        $assignmentWhereClauses[] = "t.id_ IN (SELECT task_id_ FROM ACT_RU_IDENTITYLINK WHERE group_id_ = :candidateGroup)";
                        $parameters[':candidateGroup'] = $v;
                        break;
                    }
                    break;
                }
            }
        }

        if(empty($assignmentWhereClauses))
            $assignmentWhereClauses = ['TRUE'];

        $mainWhereClauses[] = '('.implode(' OR ', $assignmentWhereClauses).')';

        if($count) {
            $innerSQL = $select.' '.$from.' WHERE '.implode(' AND ', $mainWhereClauses);

            $groupBy = "GROUP BY processDefinitionKey";
            $orderBy = "ORDER BY task_count DESC";
            return $this->returnSQLResultset("SELECT  processDefinitionKey, count(*) as task_count "," FROM ( {$innerSQL} ) AS tmp_ ", " WHERE TRUE ", $groupBy, $orderBy, [
                self::PARAM_START => 0,
                self::PARAM_SIZE => 9999
            ], $parameters);
        } else {
            $orderBy = @$inputHash['sort'] ? "ORDER BY {$inputHash['sort']} ".($inputHash['order'] ?? 'ASC') : null;
            return $this->returnSQLResultset($select, $from, 'WHERE '.implode(' AND ', $mainWhereClauses), $groupBy = '', $orderBy, $inputHash, $parameters);
        }
    }

    /**
     * @param string $select
     * @param string $from
     * @param string $where
     * @param string $groupBy
     * @param string $orderBy
     * @param array $inputHash
     * @param array $queryParameters
     * @return ActivitiResult
     */
    private function returnSQLResultset($select, $from, $where, $groupBy, $orderBy, $inputHash, $queryParameters = []) {
        $count = $this->fetchSQL("SELECT COUNT(*) AS nr ".$from.' '.$where, $queryParameters);

        $start = @$inputHash['start'] ? @$inputHash['start'] : 0;
        $size = @$inputHash['size'] ? @$inputHash['size'] : min($count,10);
        $limit="LIMIT $size OFFSET $start";

        $records = $this->fetchSQL($q = $select.' '.$from.' '.$where.' '.$groupBy.' '.$orderBy.' '.$limit, $queryParameters);

        return new ActivitiResult([
            'data' => $records,
            'total' => $count,
            'start' => $start,
            'sort'  => '',
            'order' => '',
            'size' => $size
        ]);
    }

    private function fetchSQL($sql, $bindParams=null, $purgeParams=false) {
        $con = $this->pdo;
        if(!$con)
            throw new \Exception("missing PDO connection");

        $sth = $con->prepare($sql);

        $bindParams = @count($bindParams) > 0 ? $bindParams : null;

        //purge $bindParams from unneeded parameters (which cause an error) : the default is false, but may be switched to true. It is not done to prevent performance hits on the queries
        $p = [];
        if($bindParams) {
            if($purgeParams) {
                foreach($bindParams as $param=>$value) {
                    if(strpos($sql, $param)!==false) {
                        $p[$param]=$value;
                    }
                }
            } else $p = $bindParams;
        }

        //now bind params with their correct type
        foreach($p as $paramName => $paramValue) {
            if(is_bool($paramValue))
                $type = \PDO::PARAM_BOOL;
            elseif(is_null($paramValue))
                $type = \PDO::PARAM_NULL;
            elseif(is_int($paramValue))
                $type = \PDO::PARAM_INT;
            else
                $type = \PDO::PARAM_STR;
            $sth->bindValue($paramName, $paramValue, $type);
        }

        $sth->execute();

        $result = $sth->fetchAll(\PDO::FETCH_ASSOC);
        //if we issued a query like select count(*) from...the raw value is returned
        if( $sth->columnCount() == 1 && $sth->rowCount() == 1)
            $ret = array_pop($result[0]);

        else $ret = $result;

        return $ret;
    }

    /**
     * List of Deployments

     * @return ActivitiResult
     **/
    public function getListOfDeployments() {
        $requestBody = null;
        $inputArray = [];
        $ret = $this->fetch('GET', 'repository/deployments', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates the request was successful.',
            ));
        return $ret;
    }

    /**
     * Get a deployment
     * @param string $deploymentId The id of the deployment to get.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getDeployment($deploymentId) {
        $requestBody = null;
        $inputArray = ['deploymentId' => $deploymentId];
        $ret = $this->fetch('GET', 'repository/deployments/{deploymentId}', $requestBody, $inputArray, array (
                0 => 'deploymentId',
            ), array (
            ), array (
                200 => 'Indicates the deployment was found and returned.',
                404 => 'Indicates the requested deployment was not found.',
            ));
        return $ret;
    }

    /**
     * Create a new deployment
     * @param $fileToDeploy
     * @param string|null $tenantId
     * @return array
     */
    public function createNewDeployment($fileToDeploy, $tenantId=null) {
        $requestBody = [];
        if($tenantId)
            $requestBody['tenantId'] = $tenantId;

        $ret = $this->fetch('POST', 'repository/deployments', $requestBody, [], array (
            ), array (
            ), array (
                201 => 'Indicates the deployment was created.',
                400 => 'Indicates there was no content present in the request body or the content mime-type is not supported for deployment. The status-description contains additional information.',
            ), [
                [
                    'type' => 'file',
                    'file' => $fileToDeploy,
                ]
            ]);
        return $ret;
    }

    /**
     * Delete a deployment
     * @param string $deploymentId The id of the deployment to delete.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function deleteDeployment($deploymentId) {
        $requestBody = null;
        $inputArray = ['deploymentId' => $deploymentId];
        $ret = $this->fetch('DELETE', 'repository/deployments/{deploymentId}', $requestBody, $inputArray, array (
                0 => 'deploymentId',
            ), array (
            ), array (
                204 => 'Indicates the deployment was found and has been deleted. Response-body is intentionally empty.',
                404 => 'Indicates the requested deployment was not found.',
            ));
        return $ret;
    }

    /**
     * List resources in a deployment
     * @param string $deploymentId The id of the deployment to get the resources for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getListResourcesInDeployment($deploymentId) {
        $requestBody = null;
        $inputArray = ['deploymentId' => $deploymentId];
        $ret = $this->fetch('GET', 'repository/deployments/{deploymentId}/resources', $requestBody, $inputArray, array (
                0 => 'deploymentId',
            ), array (
            ), array (
                200 => 'Indicates the deployment was found and the resource list has been returned.',
                404 => 'Indicates the requested deployment was not found.',
            ));
        return $ret;
    }

    /**
     * Get a deployment resource
     * @param string $deploymentId The id of the deployment the requested resource is part of.
     * @param string $resourceId The id of the resource to get. Make sure you URL-encode the resourceId in case it contains forward slashes. Eg: use 'diagrams%2Fmy-process.bpmn20.xml' instead of 'diagrams/Fmy-process.bpmn20.xml'.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getDeploymentResource($deploymentId, $resourceId) {
        $requestBody = null;
        $inputArray = ['deploymentId' => $deploymentId, 'resourceId' => $resourceId];
        $ret = $this->fetch('GET', 'repository/deployments/{deploymentId}/resources/{resourceId}', $requestBody, $inputArray, array (
                0 => 'deploymentId',
                1 => 'resourceId',
            ), array (
            ), array (
                200 => 'Indicates both deployment and resource have been found and the resource has been returned.',
                404 => 'Indicates the requested deployment was not found or there is no resource with the given id present in the deployment. The status-description contains additional information.',
            ));
        return $ret;
    }

    /**
     * Get a deployment resource content
     * @param string $deploymentId The id of the deployment the requested resource is part of.
     * @param string $resourceId The id of the resource to get the data for. Make sure you URL-encode the resourceId in case it contains forward slashes. Eg: use 'diagrams%2Fmy-process.bpmn20.xml' instead of 'diagrams/Fmy-process.bpmn20.xml'.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getDeploymentResourceContent($deploymentId, $resourceId) {
        $requestBody = null;
        $inputArray = ['deploymentId' => $deploymentId, 'resourceId' => $resourceId];
        $ret = $this->fetch('GET', 'repository/deployments/{deploymentId}/resourcedata/{resourceId}', $requestBody, $inputArray, array (
                0 => 'deploymentId',
                1 => 'resourceId',
            ), array (
            ), array (
                200 => 'Indicates both deployment and resource have been found and the resource data has been returned.',
                404 => 'Indicates the requested deployment was not found or there is no resource with the given id present in the deployment. The status-description contains additional information.',
            ));
        return $ret;
    }

    /**
     * List of process definitions
     *
     * input hash keys:
     *
     * version                       : Only return process definitions with the given version.
     * name                          : Only return process definitions with the given name.
     * nameLike                      : Only return process definitions with a name like the given name.
     * key                           : Only return process definitions with the given key.
     * keyLike                       : Only return process definitions with a name like the given key.
     * resourceName                  : Only return process definitions with the given resource name.
     * resourceNameLike              : Only return process definitions with a name like the given resource name.
     * category                      : Only return process definitions with the given category.
     * categoryLike                  : Only return process definitions with a category like the given name.
     * categoryNotEquals             : Only return process definitions which don't have the given category.
     * deploymentId                  : Only return process definitions which are part of a deployment with the given id.
     * startableByUser               : Only return process definitions which can be started by the given user.
     * latest                        : Only return the latest process definition versions. Can only be used together with 'key' and 'keyLike' parameters, using any other parameter will result in a 400-response.
     * suspended                     : If true, only returns process definitions which are suspended. If false, only active process definitions (which are not suspended) are returned.
     * sort                          : (default), 'id', 'key', 'category', 'deploymentId' and 'version'	Property to sort on, to be used together with the 'order'.
     * @param array $inputHash

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getListOfProcessDefinitions($inputHash = []) {
        $requestBody = null;
        $inputArray = array_merge($inputHash, []);
        $ret = $this->fetch('GET', 'repository/process-definitions', $requestBody, $inputArray, array (
                0 => 'version',
                1 => 'name',
                2 => 'nameLike',
                3 => 'key',
                4 => 'keyLike',
                5 => 'resourceName',
                6 => 'resourceNameLike',
                7 => 'category',
                8 => 'categoryLike',
                9 => 'categoryNotEquals',
                10 => 'deploymentId',
                11 => 'startableByUser',
                12 => 'latest',
                13 => 'suspended',
                14 => 'sort',
                15 => 'order',
            ), array (
            ), array (
                200 => 'Indicates request was successful and the process-definitions are returned',
                400 => 'Indicates a parameter was passed in the wrong format or that \'latest\' is used with other parameters other than \'key\' and \'keyLike\'. The status-message contains additional information.',
            ));
        return $ret;
    }

    /**
     * Get a process definition
     * @param string $processDefinitionId The id of the process definition to get.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getProcessDefinition($processDefinitionId) {
        $requestBody = null;
        $inputArray = ['processDefinitionId' => $processDefinitionId];
        $ret = $this->fetch('GET', 'repository/process-definitions/{processDefinitionId}', $requestBody, $inputArray, array (
                0 => 'processDefinitionId',
            ), array (
            ), array (
                200 => 'Indicates the process definition was found and returned.',
                404 => 'Indicates the requested process definition was not found.',
            ));
        return $ret;
    }

    /**
     * Update category for a process definition
     * @param mixed $processDefinitionId
     * @param string $category
     * @throws \Exception

     * @return ActivitiResult
     **/
    public function updateCategoryForProcessDefinition($processDefinitionId, $category) {
        $requestBody = [
            'category' => $category
        ];
        $inputArray = [
            'processDefinitionId' => $processDefinitionId,
        ];
        $ret = $this->fetch('PUT', 'repository/process-definitions/{processDefinitionId}', $requestBody, $inputArray, array (
                0 => 'processDefinitionId',
            ), array (
            ), array (
                200 => 'Indicates the process was category was altered.',
                400 => 'Indicates no category was defined in the request body.',
                404 => 'Indicates the requested process definition was not found.',
            ));
        return $ret;
    }

    /**
     * Get a process definition resource content
     * @param string $processDefinitionId The id of the process definition to get the resource data for.

     * @return ActivitiResult
     **/
    public function getProcessDefinitionResourceContent($processDefinitionId) {
        $requestBody = null;
        $inputArray = ['processDefinitionId' => $processDefinitionId];
        $ret = $this->fetch('GET', 'repository/process-definitions/{processDefinitionId}/resourcedata', $requestBody, $inputArray, array (
                0 => 'processDefinitionId',
            ), array (
            ), array (
            ));
        return $ret;
    }

    /**
     * Get a process definition BPMN model
     * @param string $processDefinitionId The id of the process definition to get the model for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getProcessDefinitionBPMNModel($processDefinitionId) {
        $requestBody = null;
        $inputArray = ['processDefinitionId' => $processDefinitionId];
        $ret = $this->fetch('GET', 'repository/process-definitions/{processDefinitionId}/model', $requestBody, $inputArray, array (
                0 => 'processDefinitionId',
            ), array (
            ), array (
                200 => 'Indicates the process definition was found and the model is returned.',
                404 => 'Indicates the requested process definition was not found.',
            ));
        return $ret;
    }

    /**
     * Suspend a process definition
     * @param mixed $action Action to perform. Either activate or suspend.
     * @param mixed $includeProcessInstances Whether or not to suspend/activate running process-instances for this process-definition. If omitted, the process-instances are left in the state they are.
     * @param mixed $date Date (ISO-8601) when the suspension/activation should be executed. If omitted, the suspend/activation is effective immediatly.
     * @param mixed $processDefinitionId

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function suspendProcessDefinition($action, $includeProcessInstances, $date, $processDefinitionId) {
        $requestBody = null;
        $inputArray = ['action' => $action, 'includeProcessInstances' => $includeProcessInstances, 'date' => $date, 'processDefinitionId' => $processDefinitionId];
        $ret = $this->fetch('PUT', 'repository/process-definitions/{processDefinitionId}', $requestBody, $inputArray, array (
            ), array (
                0 => 'action',
                1 => 'includeProcessInstances',
                2 => 'date',
            ), array (
                200 => 'Indicates the process was suspended.',
                404 => 'Indicates the requested process definition was not found.',
                409 => 'Indicates the requested process definition is already suspended.',
            ));
        return $ret;
    }

    /**
     * Activate a process definition
     * @param mixed $processDefinitionId

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function activateProcessDefinition($processDefinitionId) {
        $requestBody = null;
        $inputArray = ['processDefinitionId' => $processDefinitionId];
        $ret = $this->fetch('PUT', 'repository/process-definitions/{processDefinitionId}', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates the process was activated.',
                404 => 'Indicates the requested process definition was not found.',
                409 => 'Indicates the requested process definition is already active.',
            ));
        return $ret;
    }

    /**
     * Get all candidate starters for a process-definition
     * @param string $processDefinitionId The id of the process definition to get the identity links for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getAllCandidateStartersForProcessDefinition($processDefinitionId) {
        $requestBody = null;
        $inputArray = ['processDefinitionId' => $processDefinitionId];
        $ret = $this->fetch('GET', 'repository/process-definitions/{processDefinitionId}/identitylinks', $requestBody, $inputArray, array (
                0 => 'processDefinitionId',
            ), array (
            ), array (
                200 => 'Indicates the process definition was found and the requested identity links are returned.',
                404 => 'Indicates the requested process definition was not found.',
            ));
        return $ret;
    }

    /**
     * Add a candidate starter to a process definition
     * @param string $processDefinitionId The id of the process definition.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function addCandidateStarterToProcessDefinition($processDefinitionId) {
        $requestBody = null;
        $inputArray = ['processDefinitionId' => $processDefinitionId];
        $ret = $this->fetch('POST', 'repository/process-definitions/{processDefinitionId}/identitylinks', $requestBody, $inputArray, array (
                0 => 'processDefinitionId',
            ), array (
            ), array (
                201 => 'Indicates the process definition was found and the identity link was created.',
                404 => 'Indicates the requested process definition was not found.',
            ));
        return $ret;
    }

    /**
     * Delete a candidate starter from a process definition
     * @param string $processDefinitionId The id of the process definition.
     * @param string $family Either users or groups, depending on the type of identity link.
     * @param string $identityId Either the userId or groupId of the identity to remove as candidate starter.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function deleteCandidateStarterFromProcessDefinition($processDefinitionId, $family, $identityId) {
        $requestBody = null;
        $inputArray = ['processDefinitionId' => $processDefinitionId, 'family' => $family, 'identityId' => $identityId];
        $ret = $this->fetch('DELETE', 'repository/process-definitions/{processDefinitionId}/identitylinks/{family}/{identityId}', $requestBody, $inputArray, array (
                0 => 'processDefinitionId',
                1 => 'family',
                2 => 'identityId',
            ), array (
            ), array (
                204 => 'Indicates the process definition was found and the identity link was removed. The response body is intentionally empty.',
                404 => 'Indicates the requested process definition was not found or the process definition doesn\'t have an identity-link that matches the url.',
            ));
        return $ret;
    }

    /**
     * Get a candidate starter from a process definition
     * @param string $processDefinitionId The id of the process definition.
     * @param string $family Either users or groups, depending on the type of identity link.
     * @param string $identityId Either the userId or groupId of the identity to get as candidate starter.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getCandidateStarterFromProcessDefinition($processDefinitionId, $family, $identityId) {
        $requestBody = null;
        $inputArray = ['processDefinitionId' => $processDefinitionId, 'family' => $family, 'identityId' => $identityId];
        $ret = $this->fetch('GET', 'repository/process-definitions/{processDefinitionId}/identitylinks/{family}/{identityId}', $requestBody, $inputArray, array (
                0 => 'processDefinitionId',
                1 => 'family',
                2 => 'identityId',
            ), array (
            ), array (
                200 => 'Indicates the process definition was found and the identity link was returned.',
                404 => 'Indicates the requested process definition was not found or the process definition doesn\'t have an identity-link that matches the url.',
            ));
        return $ret;
    }

    /**
     * Get a list of models

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getListOfModels() {
        $requestBody = null;
        $inputArray = [];
        $ret = $this->fetch('GET', 'repository/models', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates request was successful and the models are returned',
                400 => 'Indicates a parameter was passed in the wrong format. The status-message contains additional information.',
            ));
        return $ret;
    }

    /**
     * Get a model
     * @param string $modelId The id of the model to get.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getModel($modelId) {
        $requestBody = null;
        $inputArray = ['modelId' => $modelId];
        $ret = $this->fetch('GET', 'repository/models/{modelId}', $requestBody, $inputArray, array (
                0 => 'modelId',
            ), array (
            ), array (
                200 => 'Indicates the model was found and returned.',
                404 => 'Indicates the requested model was not found.',
            ));
        return $ret;
    }

    /**
     * Update a model
     *
     * request Body example:
     *
     *  {
     *     "name":"Model name",
     *     "key":"Model key",
     *     "category":"Model category",
     *     "version":2,
     *     "metaInfo":"Model metainfo",
     *     "deploymentId":"2",
     *     "tenantId":"updatedTenant"
     *  }
     *
     * @param array $requestBody
     * @param mixed $modelId

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function updateModel($modelId, $requestBody = "") {
        $inputArray = ['modelId' => $modelId];
        $ret = $this->fetch('PUT', 'repository/models/{modelId}', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates the model was found and updated.',
                404 => 'Indicates the requested model was not found.',
            ));
        return $ret;
    }

    /**
     * Create a model
     *
     * request Body example:
     *
     *  {
     *     "name":"Model name",
     *     "key":"Model key",
     *     "category":"Model category",
     *     "version":1,
     *     "metaInfo":"Model metainfo",
     *     "deploymentId":"2",
     *     "tenantId":"tenant""
     *  }
     *
     * @param array|string $requestBody
     * @return array
     */
    public function createModel($requestBody = "") {
        $inputArray = [];
        $ret = $this->fetch('POST', 'repository/models', $requestBody, $inputArray, array (
            ), array (
            ), array (
                201 => 'Indicates the model was created.',
            ));
        return $ret;
    }

    /**
     * Delete a model
     * @param string $modelId The id of the model to delete.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function deleteModel($modelId) {
        $requestBody = null;
        $inputArray = ['modelId' => $modelId];
        $ret = $this->fetch('DELETE', 'repository/models/{modelId}', $requestBody, $inputArray, array (
                0 => 'modelId',
            ), array (
            ), array (
                204 => 'Indicates the model was found and has been deleted. Response-body is intentionally empty.',
                404 => 'Indicates the requested model was not found.',
            ));
        return $ret;
    }

    /**
     * Get the editor source for a model
     * @param string $modelId The id of the model.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getTheEditorSourceForModel($modelId) {
        $requestBody = null;
        $inputArray = ['modelId' => $modelId];
        $ret = $this->fetch('GET', 'repository/models/{modelId}/source', $requestBody, $inputArray, array (
                0 => 'modelId',
            ), array (
            ), array (
                200 => 'Indicates the model was found and source is returned.',
                404 => 'Indicates the requested model was not found.',
            ));
        return $ret;
    }

    /**
     * Set the editor source for a model
     * @param string $modelId The id of the model.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function setTheEditorSourceForModel($modelId) {
        $requestBody = null;
        $inputArray = ['modelId' => $modelId];
        $ret = $this->fetch('PUT', 'repository/models/{modelId}/source', $requestBody, $inputArray, array (
                0 => 'modelId',
            ), array (
            ), array (
                200 => 'Indicates the model was found and the source has been updated.',
                404 => 'Indicates the requested model was not found.',
            ));
        return $ret;
    }

    /**
     * Get the extra editor source for a model
     * @param string $modelId The id of the model.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getTheExtraEditorSourceForModel($modelId) {
        $requestBody = null;
        $inputArray = ['modelId' => $modelId];
        $ret = $this->fetch('GET', 'repository/models/{modelId}/source-extra', $requestBody, $inputArray, array (
                0 => 'modelId',
            ), array (
            ), array (
                200 => 'Indicates the model was found and source is returned.',
                404 => 'Indicates the requested model was not found.',
            ));
        return $ret;
    }

    /**
     * Set the extra editor source for a model
     * @param string $modelId The id of the model.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function setTheExtraEditorSourceForModel($modelId) {
        $requestBody = null;
        $inputArray = ['modelId' => $modelId];
        $ret = $this->fetch('PUT', 'repository/models/{modelId}/source-extra', $requestBody, $inputArray, array (
                0 => 'modelId',
            ), array (
            ), array (
                200 => 'Indicates the model was found and the extra source has been updated.',
                404 => 'Indicates the requested model was not found.',
            ));
        return $ret;
    }

    /**
     * Get a process instance
     * @param string $processInstanceId The id of the process instance to get.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getProcessInstance($processInstanceId) {
        $requestBody = null;
        $inputArray = ['processInstanceId' => $processInstanceId];
        $ret = $this->fetch('GET', 'runtime/process-instances/{processInstanceId}', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
            ), array (
            ), array (
                200 => 'Indicates the process instance was found and returned.',
                404 => 'Indicates the requested process instance was not found.',
            ));
        return $ret;
    }

    /**
     * Delete a process instance
     * @param string $processInstanceId The id of the process instance to delete.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function deleteProcessInstance($processInstanceId) {
        $requestBody = null;
        $inputArray = ['processInstanceId' => $processInstanceId];
        $ret = $this->fetch('DELETE', 'runtime/process-instances/{processInstanceId}', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
            ), array (
            ), array (
                204 => 'Indicates the process instance was found and deleted. Response body is left empty intentionally.',
                404 => 'Indicates the requested process instance was not found.',
            ));
        return $ret;
    }

    /**
     * Activate or suspend a process instance
     * @param string $processInstanceId The id of the process instance to activate/suspend.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function activateOrSuspendProcessInstance($processInstanceId) {
        $requestBody = null;
        $inputArray = ['processInstanceId' => $processInstanceId];
        $ret = $this->fetch('PUT', 'runtime/process-instances/{processInstanceId}', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
            ), array (
            ), array (
                200 => 'Indicates the process instance was found and action was executed.',
                400 => 'Indicates an invalid action was supplied.',
                404 => 'Indicates the requested process instance was not found.',
                409 => 'Indicates the requested process instance action cannot be executed since the process-instance is already activated/suspended.',
            ));
        return $ret;
    }

    /**
     * Start a process instance
     * @param $requestBody
     * @return array
     */
    public function startProcessInstance($requestBody) {
        $inputArray = [];
        $ret = $this->fetch('POST', 'runtime/process-instances', $requestBody, $inputArray, array (
            ), array (
            ), array (
                201 => 'Indicates the process instance was created.',
                400 => 'Indicates either the process-definition was not found (based on id or key), no process is started by sending the given message or an invalid variable has been passed. Status description contains additional information about the error.',
            ));
        return $ret;
    }

    /**
     * List of process instances

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getListOfProcessInstances() {
        $requestBody = null;
        $inputArray = [];
        $ret = $this->fetch('GET', 'runtime/process-instances', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates request was successful and the process-instances are returned',
                400 => 'Indicates a parameter was passed in the wrong format . The status-message contains additional information.',
            ));
        return $ret;
    }

    /**
     * Query process instances
     *
     * request Body example:
     *
     *  {
     *    "processDefinitionKey":"oneTaskProcess",
     *    "variables":
     *    [
     *      {
     *          "name" : "myVariable",
     *          "value" : 1234,
     *          "operation" : "equals",
     *          "type" : "long"
     *      },
     *      ...
     *    ],
     *    ...
     *  }
     *
     * @param array|string $requestBody
     * @return array
     */
    public function queryProcessInstances($requestBody = "") {
        $inputArray = [];
        $ret = $this->fetch('POST', 'query/process-instances', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates request was successful and the process-instances are returned',
                400 => 'Indicates a parameter was passed in the wrong format . The status-message contains additional information.',
            ));
        return $ret;
    }

    /**
     * Get diagram for a process instance
     * @param string $processInstanceId The id of the process instance to get the diagram for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getDiagramForProcessInstance($processInstanceId) {
        $requestBody = null;
        $inputArray = ['processInstanceId' => $processInstanceId];
        $ret = $this->fetch('GET', 'runtime/process-instances/{processInstanceId}/diagram', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
            ), array (
            ), array (
                200 => 'Indicates the process instance was found and the diagram was returned.',
                400 => 'Indicates the requested process instance was not found but the process doesn\'t contain any graphical information (BPMN:DI) and no diagram can be created.',
                404 => 'Indicates the requested process instance was not found.',
            ));
        return $ret;
    }

    /**
     * Get involved people for process instance
     * @param string $processInstanceId The id of the process instance to the links for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getInvolvedPeopleForProcessInstance($processInstanceId) {
        $requestBody = null;
        $inputArray = ['processInstanceId' => $processInstanceId];
        $ret = $this->fetch('GET', 'runtime/process-instances/{processInstanceId}/identitylinks', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
            ), array (
            ), array (
                200 => 'Indicates the process instance was found and links are returned.',
                404 => 'Indicates the requested process instance was not found.',
            ));
        return $ret;
    }

    /**
     * Add an involved user to a process instance
     *
     * request Body example:
     *
     *  {
     *    "userId":"kermit",
     *    "type":"participant"
     *  }
     *
     * @param array $requestBody
     * @param string $processInstanceId The id of the process instance to the links for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function addAnInvolvedUserToProcessInstance($processInstanceId, $requestBody = "") {
        $inputArray = ['processInstanceId' => $processInstanceId];
        $ret = $this->fetch('POST', 'runtime/process-instances/{processInstanceId}/identitylinks', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
            ), array (
            ), array (
                201 => 'Indicates the process instance was found and the link is created.',
                400 => 'Indicates the requested body did not contain a userId or a type.',
                404 => 'Indicates the requested process instance was not found.',
            ));
        return $ret;
    }

    /**
     * Remove an involved user to from process instance
     * @param string $processInstanceId The id of the process instance.
     * @param string $userId The id of the user to delete link for.
     * @param string $type Type of link to delete.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function removeAnInvolvedUserToFromProcessInstance($processInstanceId, $userId, $type) {
        $requestBody = null;
        $inputArray = ['processInstanceId' => $processInstanceId, 'userId' => $userId, 'type' => $type];
        $ret = $this->fetch('DELETE', 'runtime/process-instances/{processInstanceId}/identitylinks/users/{userId}/{type}', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
                1 => 'userId',
                2 => 'type',
            ), array (
            ), array (
                204 => 'Indicates the process instance was found and the link has been deleted. Response body is left empty intentionally.',
                404 => 'Indicates the requested process instance was not found or the link to delete doesn\'t exist. The response status contains additional information about the error.',
            ));
        return $ret;
    }

    /**
     * List of variables for a process instance
     * @param string $processInstanceId The id of the process instance to the variables for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getListOfVariablesForProcessInstance($processInstanceId) {
        $requestBody = null;
        $inputArray = ['processInstanceId' => $processInstanceId];
        $ret = $this->fetch('GET', 'runtime/process-instances/{processInstanceId}/variables', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
            ), array (
            ), array (
                200 => 'Indicates the process instance was found and variables are returned.',
                404 => 'Indicates the requested process instance was not found.',
            ));
        return $ret;
    }

    /**
     * Get a variable for a process instance
     * @param string $processInstanceId The id of the process instance to the variables for.
     * @param string $variableName Name of the variable to get.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getVariableForProcessInstance($processInstanceId, $variableName) {
        $requestBody = null;
        $inputArray = ['processInstanceId' => $processInstanceId, 'variableName' => $variableName];
        $ret = $this->fetch('GET', 'runtime/process-instances/{processInstanceId}/variables/{variableName}', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
                1 => 'variableName',
            ), array (
            ), array (
                200 => 'Indicates both the process instance and variable were found and variable is returned.',
                400 => 'Indicates the request body is incomplete or contains illegal values. The status description contains additional information about the error.',
                404 => 'Indicates the requested process instance was not found or the process instance does not have a variable with the given name. Status description contains additional information about the error.',
            ));
        return $ret;
    }

    /**
     * Create (or update) variables on a process instance
     * @param string $processInstanceId The id of the process instance to the variables for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function createOrUpdateVariablesOnProcessInstance($processInstanceId, array $inputHash = []) {
        $requestBody = $inputHash;
        $inputArray = ['processInstanceId' => $processInstanceId];
        $ret = $this->fetch('POST', 'runtime/process-instances/{processInstanceId}/variables', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
            ), array (
            ), array (
                201 => 'Indicates the process instance was found and variable is created.',
                400 => 'Indicates the request body is incomplete or contains illegal values. The status description contains additional information about the error.',
                404 => 'Indicates the requested process instance was not found.',
                409 => 'Indicates the process instance was found but already contains a variable with the given name (only thrown when POST method is used). Use the update-method instead.',
            ));
        return $ret;
    }

    /**
     * Update a single variable on a process instance
     * @param string $processInstanceId The id of the process instance to the variables for.
     * @param string $variableName Name of the variable to get.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function updateSingleVariableOnProcessInstance($processInstanceId, $variableName) {
        $requestBody = null;
        $inputArray = ['processInstanceId' => $processInstanceId, 'variableName' => $variableName];
        $ret = $this->fetch('PUT', 'runtime/process-instances/{processInstanceId}/variables/{variableName}', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
                1 => 'variableName',
            ), array (
            ), array (
                200 => 'Indicates both the process instance and variable were found and variable is updated.',
                404 => 'Indicates the requested process instance was not found or the process instance does not have a variable with the given name. Status description contains additional information about the error.',
            ));
        return $ret;
    }

    /**
     * Create a new binary variable on a process-instance
     * @param string $processInstanceId The id of the process instance to create the new variable for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function createNewBinaryVariableOnProcessInstance($processInstanceId) {
        $requestBody = null;
        $inputArray = ['processInstanceId' => $processInstanceId];
        $ret = $this->fetch('POST', 'runtime/process-instances/{processInstanceId}/variables', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
            ), array (
            ), array (
                201 => 'Indicates the variable was created and the result is returned.',
                400 => 'Indicates the name of the variable to create was missing. Status message provides additional information.',
                404 => 'Indicates the requested process instance was not found.',
                409 => 'Indicates the process instance already has a variable with the given name. Use the PUT method to update the task variable instead.',
                415 => 'Indicates the serializable data contains an object for which no class is present in the JVM running the Activiti engine and therefore cannot be deserialized.',
            ));
        return $ret;
    }

    /**
     * Update an existing binary variable on a process-instance
     * @param string $processInstanceId The id of the process instance to create the new variable for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function updateAnExistingBinaryVariableOnProcessInstance($processInstanceId) {
        $requestBody = null;
        $inputArray = ['processInstanceId' => $processInstanceId];
        $ret = $this->fetch('PUT', 'runtime/process-instances/{processInstanceId}/variables', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
            ), array (
            ), array (
                200 => 'Indicates the variable was updated and the result is returned.',
                400 => 'Indicates the name of the variable to update was missing. Status message provides additional information.',
                404 => 'Indicates the requested process instance was not found or the process instance does not have a variable with the given name.',
                415 => 'Indicates the serializable data contains an object for which no class is present in the JVM running the Activiti engine and therefore cannot be deserialized.',
            ));
        return $ret;
    }

    /**
     * Get an execution
     * @param string $executionId The id of the execution to get.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getAnExecution($executionId) {
        $requestBody = null;
        $inputArray = ['executionId' => $executionId];
        $ret = $this->fetch('GET', 'runtime/executions/{executionId}', $requestBody, $inputArray, array (
                0 => 'executionId',
            ), array (
            ), array (
                200 => 'Indicates the execution was found and returned.',
                404 => 'Indicates the execution was not found.',
            ));
        return $ret;
    }

    /**
     * Execute an action on an execution
     * @param string $executionId The id of the execution to execute action on.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function executeAnActionOnAnExecution($executionId) {
        $requestBody = null;
        $inputArray = ['executionId' => $executionId];
        $ret = $this->fetch('PUT', 'runtime/executions/{executionId}', $requestBody, $inputArray, array (
                0 => 'executionId',
            ), array (
            ), array (
                200 => 'Indicates the execution was found and the action is performed.',
                204 => 'Indicates the execution was found, the action was performed and the action caused the execution to end.',
                400 => 'Indicates an illegal action was requested, required parameters are missing in the request body or illegal variables are passed in. Status description contains additional information about the error.',
                404 => 'Indicates the execution was not found.',
            ));
        return $ret;
    }

    /**
     * Get active activities in an execution
     * @param string $executionId The id of the execution to get activities for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getActiveActivitiesInAnExecution($executionId) {
        $requestBody = null;
        $inputArray = ['executionId' => $executionId];
        $ret = $this->fetch('GET', 'runtime/executions/{executionId}/activities', $requestBody, $inputArray, array (
                0 => 'executionId',
            ), array (
            ), array (
                200 => 'Indicates the execution was found and activities are returned.',
                404 => 'Indicates the execution was not found.',
            ));
        return $ret;
    }

    /**
     * List of executions

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getListOfExecutions() {
        $requestBody = null;
        $inputArray = [];
        $ret = $this->fetch('GET', 'runtime/executions', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates request was successful and the executions are returned',
                400 => 'Indicates a parameter was passed in the wrong format . The status-message contains additional information.',
            ));
        return $ret;
    }

    /**
     * Query executions
     *
     * request Body example:
     *
     *  {
     *    "processDefinitionKey":"oneTaskProcess",
     *    "variables":
     *    [
     *      {
     *          "name" : "myVariable",
     *          "value" : 1234,
     *          "operation" : "equals",
     *          "type" : "long"
     *      },
     *      ...
     *    ],
     *    "processInstanceVariables":
     *    [
     *      {
     *          "name" : "processVariable",
     *          "value" : "some string",
     *          "operation" : "equals",
     *          "type" : "string"
     *      },
     *      ...
     *    ],
     *    ...
     *  }
     *
     * @param array $requestBody

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function queryExecutions($requestBody = "") {
        $inputArray = [];
        $ret = $this->fetch('POST', 'query/executions', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates request was successful and the executions are returned',
                400 => 'Indicates a parameter was passed in the wrong format . The status-message contains additional information.',
            ));
        return $ret;
    }

    /**
     * List of variables for an execution
     * @param string $executionId The id of the execution to the variables for.
     * @param string $scope Either local or global. If omitted, both local and global scoped variables are returned.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getListOfVariablesForAnExecution($executionId, $scope) {
        $requestBody = null;
        $inputArray = ['executionId' => $executionId, 'scope' => $scope];
        $ret = $this->fetch('GET', 'runtime/executions/{executionId}/variables?scope={scope}', $requestBody, $inputArray, array (
                0 => 'executionId',
                1 => 'scope',
            ), array (
            ), array (
                200 => 'Indicates the execution was found and variables are returned.',
                404 => 'Indicates the requested execution was not found.',
            ));
        return $ret;
    }

    /**
     * Get a variable for an execution
     * @param string $executionId The id of the execution to the variables for.
     * @param string $variableName Name of the variable to get.
     * @param string $scope Either local or global. If omitted, local variable is returned (if exists). If not, a global variable is returned (if exists).

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getVariableForAnExecution($executionId, $variableName, $scope) {
        $requestBody = null;
        $inputArray = ['executionId' => $executionId, 'variableName' => $variableName, 'scope' => $scope];
        $ret = $this->fetch('GET', 'runtime/executions/{executionId}/variables/{variableName}?scope={scope}', $requestBody, $inputArray, array (
                0 => 'executionId',
                1 => 'variableName',
                2 => 'scope',
            ), array (
            ), array (
                200 => 'Indicates both the execution and variable were found and variable is returned.',
                400 => 'Indicates the request body is incomplete or contains illegal values. The status description contains additional information about the error.',
                404 => 'Indicates the requested execution was not found or the execution does not have a variable with the given name in the requested scope (in case scope-query parameter was omitted, variable doesn\'t exist in local and global scope). Status description contains additional information about the error.',
            ));
        return $ret;
    }

    /**
     * Create (or update) variables on an execution
     * @param string $executionId The id of the execution to the variables for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function createOrUpdateVariablesOnAnExecution($executionId) {
        $requestBody = null;
        $inputArray = ['executionId' => $executionId];
        $ret = $this->fetch('POST', 'runtime/executions/{executionId}/variables', $requestBody, $inputArray, array (
                0 => 'executionId',
            ), array (
            ), array (
                201 => 'Indicates the execution was found and variable is created.',
                400 => 'Indicates the request body is incomplete or contains illegal values. The status description contains additional information about the error.',
                404 => 'Indicates the requested execution was not found.',
                409 => 'Indicates the execution was found but already contains a variable with the given name (only thrown when POST method is used). Use the update-method instead.',
            ));
        return $ret;
    }

    /**
     * Update a variable on an execution
     * @param string $executionId The id of the execution to update the variables for.
     * @param string $variableName Name of the variable to update.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function updateVariableOnAnExecution($executionId, $variableName) {
        $requestBody = null;
        $inputArray = ['executionId' => $executionId, 'variableName' => $variableName];
        $ret = $this->fetch('PUT', 'runtime/executions/{executionId}/variables/{variableName}', $requestBody, $inputArray, array (
                0 => 'executionId',
                1 => 'variableName',
            ), array (
            ), array (
                200 => 'Indicates both the process instance and variable were found and variable is updated.',
                404 => 'Indicates the requested process instance was not found or the process instance does not have a variable with the given name. Status description contains additional information about the error.',
            ));
        return $ret;
    }

    /**
     * Create a new binary variable on an execution
     * @param string $executionId The id of the execution to create the new variable for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function createNewBinaryVariableOnAnExecution($executionId) {
        $requestBody = null;
        $inputArray = ['executionId' => $executionId];
        $ret = $this->fetch('POST', 'runtime/executions/{executionId}/variables', $requestBody, $inputArray, array (
                0 => 'executionId',
            ), array (
            ), array (
                201 => 'Indicates the variable was created and the result is returned.',
                400 => 'Indicates the name of the variable to create was missing. Status message provides additional information.',
                404 => 'Indicates the requested execution was not found.',
                409 => 'Indicates the execution already has a variable with the given name. Use the PUT method to update the task variable instead.',
                415 => 'Indicates the serializable data contains an object for which no class is present in the JVM running the Activiti engine and therefore cannot be deserialized.',
            ));
        return $ret;
    }

    /**
     * Update an existing binary variable on a process-instance
     * @param string $executionId The id of the execution to create the new variable for.
     * @param string $variableName The name of the variable to update.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function updateAnExistingBinaryVariableOnExecution($executionId, $variableName) {
        $requestBody = null;
        $inputArray = ['executionId' => $executionId, 'variableName' => $variableName];
        $ret = $this->fetch('PUT', 'runtime/executions/{executionId}/variables/{variableName}', $requestBody, $inputArray, array (
                0 => 'executionId',
                1 => 'variableName',
            ), array (
            ), array (
                200 => 'Indicates the variable was updated and the result is returned.',
                400 => 'Indicates the name of the variable to update was missing. Status message provides additional information.',
                404 => 'Indicates the requested execution was not found or the execution does not have a variable with the given name.',
                415 => 'Indicates the serializable data contains an object for which no class is present in the JVM running the Activiti engine and therefore cannot be deserialized.',
            ));
        return $ret;
    }

    /**
     * Get a task
     * @param string $taskId The id of the task to get.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getTask($taskId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId, 'includeProcessVariables'=>'true'];
        $ret = $this->fetch('GET', 'runtime/tasks/{taskId}', $requestBody, $inputArray, array (
                0 => 'taskId',
                1 => 'includeProcessVariables',
            ), array (
            ), array (
                200 => 'Indicates the task was found and returned.',
                404 => 'Indicates the requested task was not found.',
            ));
        return $ret;
    }

    /**
     * List of tasks
     * input hash keys:
     *
     * sort
     * order
     * start
     * size
     *
     * name:				    Only return tasks with the given name.
     * nameLike:				Only return tasks with a name like the given name.
     * description:				Only return tasks with the given description.
     * priority:				Only return tasks with the given priotiry.
     * minimumPriority:			Only return tasks with a priority greater than the given value.
     * maximumPriority:			Only return tasks with a priority lower than the given value.
     * assignee:				Only return tasks assigned to the given user.
     * assigneeLike:			Only return tasks assigned with an assignee like the given value.
     * owner:				    Only return tasks owned by the given user.
     * ownerLike:				Only return tasks assigned with an owner like the given value.
     * unassigned:				Only return tasks that are not assigned to anyone. If false is passed, the value is ignored.
     * delegationState:			Only return tasks that have the given delegation state. Possible values are pending and resolved.
     * candidateUser:			Only return tasks that can be claimed by the given user. This includes both tasks where the user is an explicit candidate for and task that are claimable by a group that the user is a member of.
     * candidateGroup:			Only return tasks that can be claimed by a user in the given group.
     * candidateGroups:			Only return tasks that can be claimed by a user in the given groups. Values split by comma.
     * involvedUser:			Only return tasks in which the given user is involved.
     * taskDefinitionKey:				Only return tasks with the given task definition id.
     * taskDefinitionKeyLike:				Only return tasks with a given task definition id like the given value.
     * processInstanceId:				Only return tasks which are part of the process instance with the given id.
     * processInstanceBusinessKey:				Only return tasks which are part of the process instance with the given business key.
     * processInstanceBusinessKeyLike:				Only return tasks which are part of the process instance which has a business key like the given value.
     * processDefinitionKey:				Only return tasks which are part of a process instance which has a process definition with the given key.
     * processDefinitionKeyLike:				Only return tasks which are part of a process instance which has a process definition with a key like the given value.
     * processDefinitionName:				Only return tasks which are part of a process instance which has a process definition with the given name.
     * processDefinitionNameLike:				Only return tasks which are part of a process instance which has a process definition with a name like the given value.
     * executionId:				Only return tasks which are part of the execution with the given id.
     * createdOn:				Date	Only return tasks which are created on the given date.
     * createdBefore:				Date	Only return tasks which are created before the given date.
     * createdAfter:				Date	Only return tasks which are created after the given date.
     * dueOn:				Date	Only return tasks which are due on the given date.
     * dueBefore:				Date	Only return tasks which are due before the given date.
     * dueAfter:				Date	Only return tasks which are due after the given date.
     * withoutDueDate:				Only return tasks which don't have a due date. The property is ignored if the value is false.
     * withoutDueDate:				Only return tasks which don't have a due date. The property is ignored if the value is false.
     * withoutDueDate:				Only return tasks which don't have a due date. The property is ignored if the value is false.
     * excludeSubTasks:				Only return tasks that are not a subtask of another task.
     * active:				If true, only return tasks that are not suspended (either part of a process that is not suspended or not part of a process at all). If false, only tasks that are part of suspended process instances are returned.
     * includeTaskLocalVariables:				Indication to include task local variables in the result.
     * includeProcessVariables:				Indication to include process variables in the result.
     * tenantId:				Only return tasks with the given tenantId.
     * tenantIdLike:				Only return tasks with a tenantId like the given value.
     * withoutTenantId:				If true, only returns tasks without a tenantId set. If false, the withoutTenantId parameter is ignored.
     * candidateOrAssigned:				Select tasks that has been claimed or assigned to user or waiting to claim by user (candidate user or groups).
     *
     * @param array $inputHash
     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getListOfTasks($inputHash = []) {
        $requestBody = null;
        $inputArray = array_merge($inputHash, []);
        $ret = $this->fetch('GET', 'runtime/tasks', $requestBody, $inputArray, array (
                'sort',
                'order',
                'start',
                'size',

                'name',
                'nameLike',
                'description',
                'priority',
                'minimumPriority',
                'maximumPriority',
                'assignee',
                'assigneeLike',
                'owner',
                'ownerLike',
                'unassigned',
                'delegationState',
                'candidateUser',
                'candidateGroup',
                'candidateGroups',
                'involvedUser',
                'taskDefinitionKey',
                'taskDefinitionKeyLike',
                'processInstanceId',
                'processInstanceBusinessKey',
                'processInstanceBusinessKeyLike',
                'processDefinitionKey',
                'processDefinitionKeyLike',
                'processDefinitionName',
                'processDefinitionNameLike',
                'executionId',
                'createdOn',
                'createdBefore',
                'createdAfter',
                'dueOn',
                'dueBefore',
                'dueAfter',
                'withoutDueDate',
                'withoutDueDate',
                'withoutDueDate',
                'excludeSubTasks',
                'active',
                'includeTaskLocalVariables',
                'includeProcessVariables',
                'tenantId',
                'tenantIdLike',
                'withoutTenantId',
                'candidateOrAssigned'
            ), array (
            ), array (
                200 => 'Indicates request was successful and the tasks are returned',
                400 => 'Indicates a parameter was passed in the wrong format or that \'delegationState\' has an invalid value (other than \'pending\' and \'resolved\'). The status-message contains additional information.',
            ));
        return $ret;
    }


    /**
     * Query for tasks
     *
     * request Body example:
     *
     *  {
     *    "name" : "My task",
     *    "description" : "The task description",
     *
     *    ...
     *
     *    "taskVariables" : [
     *      {
     *        "name" : "myVariable",
     *        "value" : 1234,
     *        "operation" : "equals",
     *        "type" : "long"
     *      }
     *    ],
     *
     *      "processInstanceVariables" : [
     *        {
     *           ...
     *        }
     *      ]
     *    ]
     *  }
     *
     * @param array|string $requestBody
     * @return array
     */
    public function queryForTasks($requestBody = "") {
        $inputArray = [];
        $ret = $this->fetch('POST', 'query/tasks', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates request was successful and the tasks are returned',
                400 => 'Indicates a parameter was passed in the wrong format or that \'delegationState\' has an invalid value (other than \'pending\' and \'resolved\'). The status-message contains additional information.',
            ));
        return $ret;
    }

    /**
     * Update a task
     * @param mixed $taskId

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function updateTask($taskId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId];
        $ret = $this->fetch('PUT', 'runtime/tasks/{taskId}', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates the task was updated.',
                404 => 'Indicates the requested task was not found.',
                409 => 'Indicates the requested task was updated simultaneously.',
            ));
        return $ret;
    }

    /**
     * Task actions
     * @param mixed $taskId
     * @param null $requestBody
     * @return array
     */
    public function taskActions($taskId, $requestBody = null) {
        $inputArray = ['taskId' => $taskId];
        $ret = $this->fetch('POST', 'runtime/tasks/{taskId}', $requestBody, $inputArray, array (
                0 => 'taskId',
            ), array (
            ), array (
                200 => 'Indicates the action was executed.',
                400 => 'When the body contains an invalid value or when the assignee is missing when the action requires it.',
                404 => 'Indicates the requested task was not found.',
                409 => 'Indicates the action cannot be performed due to a conflict. Either the task was updates simultaneously or the task was claimed by another user, in case of the \'claim\' action.',
            ));
        return $ret;
    }

    /**
     * @param string $taskId
     * @param string $assignee
     * @return bool
     */
    public function claimTask($taskId, $assignee) {
        return $this->taskActions($taskId, json_encode([
            "action" => "claim",
            "assignee" => $assignee
        ])) == 200;
    }

    /**
     * Delete a task
     * @param string $taskId The id of the task to delete.
     * @param mixed $cascadeHistory
     * @param mixed $deleteReason

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function deleteTask($taskId, $cascadeHistory, $deleteReason) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId, 'cascadeHistory' => $cascadeHistory, 'deleteReason' => $deleteReason];
        $ret = $this->fetch('DELETE', 'runtime/tasks/{taskId}?cascadeHistory={cascadeHistory}&deleteReason={deleteReason}', $requestBody, $inputArray, array (
                0 => 'taskId',
                1 => 'cascadeHistory',
                2 => 'deleteReason'
            ), array (
            ), array (
                204 => 'Indicates the task was found and has been deleted. Response-body is intentionally empty.',
                403 => 'Indicates the requested task cannot be deleted because it\'s part of a workflow.',
                404 => 'Indicates the requested task was not found.',
            ));
        return $ret;
    }

    /**
     * Get all variables for a task
     * @param string $taskId The id of the task to get variables for.
     * @param mixed $scope

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getAllVariablesForTask($taskId, $scope) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId, 'scope' => $scope];
        $ret = $this->fetch('GET', 'runtime/tasks/{taskId}/variables', $requestBody, $inputArray, array (
                0 => 'taskId',
                1 => 'scope',
            ), array (
            ), array (
                200 => 'Indicates the task was found and the requested variables are returned.',
                404 => 'Indicates the requested task was not found.',
            ));
        return $ret;
    }

    /**
     * @param string $taskId
     * @param string $scope
     * @return array
     */
    public function getAllVariablesForTaskRecursive($taskId, $scope) {
        $vars = $this->getAllVariablesForTask($taskId, $scope);
        $ret = [];
        foreach($vars as &$var) {
            if(@$var['valueUrl']) {
                $var['serializedJavaObject'] = $this->getTheBinaryDataForVariable($taskId, $var['name'], $scope);
            }
            $ret[] = $var;
        }
        return $ret;
    }

    /**
     * Get a variable from a task
     * @param string $taskId The id of the task to get a variable for.
     * @param string $variableName The name of the variable to get.
     * @param mixed $scope

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getVariableFromTask($taskId, $variableName, $scope) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId, 'variableName' => $variableName, 'scope' => $scope];
        $ret = $this->fetch('GET', 'runtime/tasks/{taskId}/variables/{variableName}', $requestBody, $inputArray, array (
                0 => 'taskId',
                1 => 'variableName',
                2 => 'scope',
            ), array (
            ), array (
                200 => 'Indicates the task was found and the requested variables are returned.',
                404 => 'Indicates the requested task was not found or the task doesn\'t have a variable with the given name (in the given scope). Status message provides additional information.',
            ));
        return $ret;
    }

    /**
     * Get the binary data for a variable
     * @param string $taskId The id of the task to get a variable data for.
     * @param string $variableName The name of the variable to get data for. Only variables of type binary and serializable can be used. If any other type of variable is used, a 404 is returned.
     * @param mixed $scope

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getTheBinaryDataForVariable($taskId, $variableName, $scope) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId, 'variableName' => $variableName, 'scope' => $scope];
        $ret = $this->fetch('GET', 'runtime/tasks/{taskId}/variables/{variableName}/data', $requestBody, $inputArray, array (
                0 => 'taskId',
                1 => 'variableName',
                2 => 'scope',
            ), array (
            ), array (
                200 => 'Indicates the task was found and the requested variables are returned.',
                404 => 'Indicates the requested task was not found or the task doesn\'t have a variable with the given name (in the given scope) or the variable doesn\'t have a binary stream available. Status message provides additional information.',
            ));
        return $ret;
    }

    /**
     * Create new variables on a task
     * @param string $taskId The id of the task to create the new variable for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function createNewVariablesOnTask($taskId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId];
        $ret = $this->fetch('POST', 'runtime/tasks/{taskId}/variables', $requestBody, $inputArray, array (
                0 => 'taskId',
            ), array (
            ), array (
                201 => 'Indicates the variables were created and the result is returned.',
                400 => 'Indicates the name of a variable to create was missing or that an attempt is done to create a variable on a standalone task (without a process associated) with scope global or an empty array of variables was included in the request or request did not contain an array of variables. Status message provides additional information.',
                404 => 'Indicates the requested task was not found.',
                409 => 'Indicates the task already has a variable with the given name. Use the PUT method to update the task variable instead.',
            ));
        return $ret;
    }

    /**
     * Create a new binary variable on a task
     * @param string $taskId The id of the task to create the new variable for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function createNewBinaryVariableOnTask($taskId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId];
        $ret = $this->fetch('POST', 'runtime/tasks/{taskId}/variables', $requestBody, $inputArray, array (
                0 => 'taskId',
            ), array (
            ), array (
                201 => 'Indicates the variable was created and the result is returned.',
                400 => 'Indicates the name of the variable to create was missing or that an attempt is done to create a variable on a standalone task (without a process associated) with scope global. Status message provides additional information.',
                404 => 'Indicates the requested task was not found.',
                409 => 'Indicates the task already has a variable with the given name. Use the PUT method to update the task variable instead.',
                415 => 'Indicates the serializable data contains an object for which no class is present in the JVM running the Activiti engine and therefore cannot be deserialized.',
            ));
        return $ret;
    }

    /**
     * Update an existing variable on a task
     * @param string $taskId The id of the task to update the variable for.
     * @param string $variableName The name of the variable to update.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function updateAnExistingVariableOnTask($taskId, $variableName) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId, 'variableName' => $variableName];
        $ret = $this->fetch('PUT', 'runtime/tasks/{taskId}/variables/{variableName}', $requestBody, $inputArray, array (
                0 => 'taskId',
                1 => 'variableName',
            ), array (
            ), array (
                200 => 'Indicates the variables was updated and the result is returned.',
                400 => 'Indicates the name of a variable to update was missing or that an attempt is done to update a variable on a standalone task (without a process associated) with scope global. Status message provides additional information.',
                404 => 'Indicates the requested task was not found or the task doesn\'t have a variable with the given name in the given scope. Status message contains additional information about the error.',
            ));
        return $ret;
    }

    /**
     * Updating a binary variable on a task
     * @param string $taskId The id of the task to update the variable for.
     * @param string $variableName The name of the variable to update.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function updatingBinaryVariableOnTask($taskId, $variableName) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId, 'variableName' => $variableName];
        $ret = $this->fetch('PUT', 'runtime/tasks/{taskId}/variables/{variableName}', $requestBody, $inputArray, array (
                0 => 'taskId',
                1 => 'variableName',
            ), array (
            ), array (
                200 => 'Indicates the variable was updated and the result is returned.',
                400 => 'Indicates the name of the variable to update was missing or that an attempt is done to update a variable on a standalone task (without a process associated) with scope global. Status message provides additional information.',
                404 => 'Indicates the requested task was not found or the variable to update doesn\'t exist for the given task in the given scope.',
                415 => 'Indicates the serializable data contains an object for which no class is present in the JVM running the Activiti engine and therefore cannot be deserialized.',
            ));
        return $ret;
    }

    /**
     * Delete a variable on a task
     * @param string $taskId The id of the task the variable to delete belongs to.
     * @param string $variableName The name of the variable to delete.
     * @param string $scope Scope of variable to delete in. Can be either local or global. If omitted, local is assumed.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function deleteVariableOnTask($taskId, $variableName, $scope) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId, 'variableName' => $variableName, 'scope' => $scope];
        $ret = $this->fetch('DELETE', 'runtime/tasks/{taskId}/variables/{variableName}?scope={scope}', $requestBody, $inputArray, array (
                0 => 'taskId',
                1 => 'variableName',
                2 => 'scope',
            ), array (
            ), array (
                204 => 'Indicates the task variable was found and has been deleted. Response-body is intentionally empty.',
                404 => 'Indicates the requested task was not found or the task doesn\'t have a variable with the given name. Status message contains additional information about the error.',
            ));
        return $ret;
    }

    /**
     * Delete all local variables on a task
     * @param string $taskId The id of the task the variable to delete belongs to.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function deleteAllLocalVariablesOnTask($taskId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId];
        $ret = $this->fetch('DELETE', 'runtime/tasks/{taskId}/variables', $requestBody, $inputArray, array (
                0 => 'taskId',
            ), array (
            ), array (
                204 => 'Indicates all local task variables have been deleted. Response-body is intentionally empty.',
                404 => 'Indicates the requested task was not found.',
            ));
        return $ret;
    }

    /**
     * Get all identity links for a task
     * @param string $taskId The id of the task to get the identity links for.

     * @throws \Exception

     * @return array
     **/
    public function getAllIdentityLinksForTask($taskId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId];
        $ret = $this->fetch('GET', 'runtime/tasks/{taskId}/identitylinks', $requestBody, $inputArray, array (
                0 => 'taskId',
            ), array (
            ), array (
                200 => 'Indicates the task was found and the requested identity links are returned.',
                404 => 'Indicates the requested task was not found.',
            ));
        return $ret;
    }

    /**
     * Get all identitylinks for a task for either groups or users
     * @param mixed $taskId

     * @return ActivitiResult
     **/
    public function getAllIdentitylinksForTaskForEitherGroupsOrUsers($taskId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId];
        $ret = $this->fetch('GET', 'runtime/tasks/{taskId}/identitylinks/users', $requestBody, $inputArray, array (
            ), array (
            ), array (
            ));
        return $ret;
    }

    /**
     * Get a single identity link on a task
     * @param string $taskId The id of the task .
     * @param string $family Either groups or users, depending on what kind of identity is targetted.
     * @param string $identityId The id of the identity.
     * @param string $type The type of identity link.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getSingleIdentityLinkOnTask($taskId, $family, $identityId, $type) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId, 'family' => $family, 'identityId' => $identityId, 'type' => $type];
        $ret = $this->fetch('GET', 'runtime/tasks/{taskId}/identitylinks/{family}/{identityId}/{type}', $requestBody, $inputArray, array (
                0 => 'taskId',
                1 => 'family',
                2 => 'identityId',
                3 => 'type',
            ), array (
            ), array (
                200 => 'Indicates the task and identity link was found and returned.',
                404 => 'Indicates the requested task was not found or the task doesn\'t have the requested identityLink. The status contains additional information about this error.',
            ));
        return $ret;
    }

    /**
     * Create an identity link on a task
     * @param string $taskId The id of the task .

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function createAnIdentityLinkOnTask($taskId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId];
        $ret = $this->fetch('POST', 'runtime/tasks/{taskId}/identitylinks', $requestBody, $inputArray, array (
                0 => 'taskId',
            ), array (
            ), array (
                201 => 'Indicates the task was found and the identity link was created.',
                404 => 'Indicates the requested task was not found or the task doesn\'t have the requested identityLink. The status contains additional information about this error.',
            ));
        return $ret;
    }

    /**
     * Delete an identity link on a task
     * @param string $taskId The id of the task.
     * @param string $family Either groups or users, depending on what kind of identity is targetted.
     * @param string $identityId The id of the identity.
     * @param string $type The type of identity link.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function deleteAnIdentityLinkOnTask($taskId, $family, $identityId, $type) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId, 'family' => $family, 'identityId' => $identityId, 'type' => $type];
        $ret = $this->fetch('DELETE', 'runtime/tasks/{taskId}/identitylinks/{family}/{identityId}/{type}', $requestBody, $inputArray, array (
                0 => 'taskId',
                1 => 'family',
                2 => 'identityId',
                3 => 'type',
            ), array (
            ), array (
                204 => 'Indicates the task and identity link were found and the link has been deleted. Response-body is intentionally empty.',
                404 => 'Indicates the requested task was not found or the task doesn\'t have the requested identityLink. The status contains additional information about this error.',
            ));
        return $ret;
    }

    /**
     * Create a new comment on a task
     *
     * request Body example:
     *
     *  {
     *    "message" : "This is a comment on the task.",
     *    "saveProcessInstanceId" : true
     *  }
     *
     * @param string $taskId The id of the task to create the comment for.
     * @param array|string $requestBody
     * @return array
     */
    public function createNewCommentOnTask($taskId, $requestBody = "") {
        $inputArray = ['taskId' => $taskId];
        $ret = $this->fetch('POST', 'runtime/tasks/{taskId}/comments', $requestBody, $inputArray, array (
                0 => 'taskId',
            ), array (
            ), array (
                201 => 'Indicates the comment was created and the result is returned.',
                400 => 'Indicates the comment is missing from the request.',
                404 => 'Indicates the requested task was not found.',
            ));
        return $ret;
    }

    /**
     * Get all comments on a task
     * @param string $taskId The id of the task to get the comments for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getAllCommentsOnTask($taskId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId];
        $ret = $this->fetch('GET', 'runtime/tasks/{taskId}/comments', $requestBody, $inputArray, array (
                0 => 'taskId',
            ), array (
            ), array (
                200 => 'Indicates the task was found and the comments are returned.',
                404 => 'Indicates the requested task was not found.',
            ));
        return $ret;
    }

    /**
     * Get a comment on a task
     * @param string $taskId The id of the task to get the comment for.
     * @param string $commentId The id of the comment.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getCommentOnTask($taskId, $commentId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId, 'commentId' => $commentId];
        $ret = $this->fetch('GET', 'runtime/tasks/{taskId}/comments/{commentId}', $requestBody, $inputArray, array (
                0 => 'taskId',
                1 => 'commentId',
            ), array (
            ), array (
                200 => 'Indicates the task and comment were found and the comment is returned.',
                404 => 'Indicates the requested task was not found or the tasks doesn\'t have a comment with the given ID.',
            ));
        return $ret;
    }

    /**
     * Delete a comment on a task
     * @param string $taskId The id of the task to delete the comment for.
     * @param string $commentId The id of the comment.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function deleteCommentOnTask($taskId, $commentId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId, 'commentId' => $commentId];
        $ret = $this->fetch('DELETE', 'runtime/tasks/{taskId}/comments/{commentId}', $requestBody, $inputArray, array (
                0 => 'taskId',
                1 => 'commentId',
            ), array (
            ), array (
                204 => 'Indicates the task and comment were found and the comment is deleted. Response body is left empty intentionally.',
                404 => 'Indicates the requested task was not found or the tasks doesn\'t have a comment with the given ID.',
            ));
        return $ret;
    }

    /**
     * Get all events for a task
     * @param string $taskId The id of the task to get the events for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getAllEventsForTask($taskId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId];
        $ret = $this->fetch('GET', 'runtime/tasks/{taskId}/events', $requestBody, $inputArray, array (
                0 => 'taskId',
            ), array (
            ), array (
                200 => 'Indicates the task was found and the events are returned.',
                404 => 'Indicates the requested task was not found.',
            ));
        return $ret;
    }

    /**
     * Get an event on a task
     * @param string $taskId The id of the task to get the event for.
     * @param string $eventId The id of the event.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getAnEventOnTask($taskId, $eventId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId, 'eventId' => $eventId];
        $ret = $this->fetch('GET', 'runtime/tasks/{taskId}/events/{eventId}', $requestBody, $inputArray, array (
                0 => 'taskId',
                1 => 'eventId',
            ), array (
            ), array (
                200 => 'Indicates the task and event were found and the event is returned.',
                404 => 'Indicates the requested task was not found or the tasks doesn\'t have an event with the given ID.',
            ));
        return $ret;
    }

    /**
     * Create a new attachment on a task, containing a link to an external resource
     *
     * request Body example:
     *
     *  {
     *    "name":"Simple attachment",
     *    "description":"Simple attachment description",
     *    "type":"simpleType",
     *    "externalUrl":"http://activiti.org"
     *  }
     *
     * @param string $taskId The id of the task to create the attachment for.
     * @param array|string $requestBody
     * @return array
     */
    public function createNewAttachmentOnTaskContainingLinkToAnExternalResource($taskId, $requestBody = "") {
        $inputArray = ['taskId' => $taskId];
        $ret = $this->fetch('POST', 'runtime/tasks/{taskId}/attachments', $requestBody, $inputArray, array (
                0 => 'taskId',
            ), array (
            ), array (
                201 => 'Indicates the attachment was created and the result is returned.',
                400 => 'Indicates the attachment name is missing from the request.',
                404 => 'Indicates the requested task was not found.',
            ));
        return $ret;
    }

    /**
     * Create a new attachment on a task, with an attached file
     * @param string $taskId The id of the task to create the attachment for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function createNewAttachmentOnTaskWithAnAttachedFile($taskId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId];
        $ret = $this->fetch('POST', 'runtime/tasks/{taskId}/attachments', $requestBody, $inputArray, array (
                0 => 'taskId',
            ), array (
            ), array (
                201 => 'Indicates the attachment was created and the result is returned.',
                400 => 'Indicates the attachment name is missing from the request or no file was present in the request. The error-message contains additional information.',
                404 => 'Indicates the requested task was not found.',
            ));
        return $ret;
    }

    /**
     * Get all attachments on a task
     * @param string $taskId The id of the task to get the attachments for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getAllAttachmentsOnTask($taskId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId];
        $ret = $this->fetch('GET', 'runtime/tasks/{taskId}/attachments', $requestBody, $inputArray, array (
                0 => 'taskId',
            ), array (
            ), array (
                200 => 'Indicates the task was found and the attachments are returned.',
                404 => 'Indicates the requested task was not found.',
            ));
        return $ret;
    }

    /**
     * Get an attachment on a task
     * @param string $taskId The id of the task to get the attachment for.
     * @param string $attachmentId The id of the attachment.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getAnAttachmentOnTask($taskId, $attachmentId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId, 'attachmentId' => $attachmentId];
        $ret = $this->fetch('GET', 'runtime/tasks/{taskId}/attachments/{attachmentId}', $requestBody, $inputArray, array (
                0 => 'taskId',
                1 => 'attachmentId',
            ), array (
            ), array (
                200 => 'Indicates the task and attachment were found and the attachment is returned.',
                404 => 'Indicates the requested task was not found or the tasks doesn\'t have a attachment with the given ID.',
            ));
        return $ret;
    }

    /**
     * Get the content for an attachment
     * @param string $taskId The id of the task to get a variable data for.
     * @param string $attachmentId The id of the attachment, a 404 is returned when the attachment points to an external URL rather than content attached in Activiti.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getTheContentForAnAttachment($taskId, $attachmentId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId, 'attachmentId' => $attachmentId];
        $ret = $this->fetch('GET', 'runtime/tasks/{taskId}/attachment/{attachmentId}/content', $requestBody, $inputArray, array (
                0 => 'taskId',
                1 => 'attachmentId',
            ), array (
            ), array (
                200 => 'Indicates the task and attachment was found and the requested content is returned.',
                404 => 'Indicates the requested task was not found or the task doesn\'t have an attachment with the given id or the attachment doesn\'t have a binary stream available. Status message provides additional information.',
            ));
        return $ret;
    }

    /**
     * Delete an attachment on a task
     * @param string $taskId The id of the task to delete the attachment for.
     * @param string $attachmentId The id of the attachment.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function deleteAnAttachmentOnTask($taskId, $attachmentId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId, 'attachmentId' => $attachmentId];
        $ret = $this->fetch('DELETE', 'runtime/tasks/{taskId}/attachments/{attachmentId}', $requestBody, $inputArray, array (
                0 => 'taskId',
                1 => 'attachmentId',
            ), array (
            ), array (
                204 => 'Indicates the task and attachment were found and the attachment is deleted. Response body is left empty intentionally.',
                404 => 'Indicates the requested task was not found or the tasks doesn\'t have a attachment with the given ID.',
            ));
        return $ret;
    }

    /**
     * Get a historic process instance
     * @param mixed $processInstanceId

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getHistoricProcessInstance($processInstanceId) {
        $requestBody = null;
        $inputArray = ['processInstanceId' => $processInstanceId];
        $ret = $this->fetch('GET', 'history/historic-process-instances/{processInstanceId}', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
            ), array (
            ), array (
                200 => 'Indicates that the historic process instances could be found.',
                404 => 'Indicates that the historic process instances could not be found.',
            ));
        return $ret;
    }

    /**
     * List of historic process instances
     *
     * input hash keys:
     *
     * processInstanceId             : An id of the historic process instance.
     * processDefinitionKey          : The process definition key of the historic process instance.
     * processDefinitionId           : The process definition id of the historic process instance.
     * businessKey                   : The business key of the historic process instance.
     * involvedUser                  : An involved user of the historic process instance.
     * finished                      : Indication if the historic process instance is finished.
     * superProcessInstanceId        : An optional parent process id of the historic process instance.
     * excludeSubprocesses           : Return only historic process instances which aren't sub processes.
     * finishedAfter                 : Return only historic process instances that were finished after this date.
     * finishedBefore                : Return only historic process instances that were finished before this date.
     * startedAfter                  : Return only historic process instances that were started after this date.
     * startedBefore                 : Return only historic process instances that were started before this date.
     * startedBy                     : Return only historic process instances that were started by this user.
     * includeProcessVariables       : An indication if the historic process instance variables should be returned as well.
     * tenantId                      : Only return instances with the given tenantId.
     * tenantIdLike                  : Only return instances with a tenantId like the given value.
     * withoutTenantId               : If true, only returns instances without a tenantId set. If false, the withoutTenantId parameter is ignored.
     * @param array $inputHash

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getListOfHistoricProcessInstances($inputHash = []) {
        $requestBody = null;
        $inputArray = array_merge($inputHash, []);
        $ret = $this->fetch('GET', 'history/historic-process-instances', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
                1 => 'processDefinitionKey',
                2 => 'processDefinitionId',
                3 => 'businessKey',
                4 => 'involvedUser',
                5 => 'finished',
                6 => 'superProcessInstanceId',
                7 => 'excludeSubprocesses',
                8 => 'finishedAfter',
                9 => 'finishedBefore',
                10 => 'startedAfter',
                11 => 'startedBefore',
                12 => 'startedBy',
                13 => 'includeProcessVariables',
                14 => 'tenantId',
                15 => 'tenantIdLike',
                16 => 'withoutTenantId',
            ), array (
            ), array (
                200 => 'Indicates that historic process instances could be queried.',
                400 => 'Indicates an parameter was passed in the wrong format. The status-message contains additional information.',
            ));
        return $ret;
    }

    /**
     * Query for historic process instances
     *
     * request Body example:
     *
     *  {
     *    "processDefinitionId" : "oneTaskProcess%3A1%3A4",
     *    ...
     *
     *    "variables" : [
     *      {
     *        "name" : "myVariable",
     *        "value" : 1234,
     *        "operation" : "equals",
     *        "type" : "long"
     *      }
     *    ]
     *  }
     *
     * @param array $requestBody

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function queryForHistoricProcessInstances($requestBody = "") {
        $inputArray = [];
        $ret = $this->fetch('POST', 'query/historic-process-instances', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates request was successful and the tasks are returned',
                400 => 'Indicates an parameter was passed in the wrong format. The status-message contains additional information.',
            ));
        return $ret;
    }

    /**
     * Delete a historic process instance
     * @param mixed $processInstanceId

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function deleteHistoricProcessInstance($processInstanceId) {
        $requestBody = null;
        $inputArray = ['processInstanceId' => $processInstanceId];
        $ret = $this->fetch('DELETE', 'history/historic-process-instances/{processInstanceId}', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates that the historic process instance was deleted.',
                404 => 'Indicates that the historic process instance could not be found.',
            ));
        return $ret;
    }

    /**
     * Get the identity links of a historic process instance
     * @param mixed $processInstanceId

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getTheIdentityLinksOfHistoricProcessInstance($processInstanceId) {
        $requestBody = null;
        $inputArray = ['processInstanceId' => $processInstanceId];
        $ret = $this->fetch('GET', 'history/historic-process-instance/{processInstanceId}/identitylinks', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates request was successful and the identity links are returned',
                404 => 'Indicates the process instance could not be found.',
            ));
        return $ret;
    }

    /**
     * Get the binary data for a historic process instance variable
     * @param mixed $processInstanceId
     * @param mixed $variableName

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getTheBinaryDataForHistoricProcessInstanceVariable($processInstanceId, $variableName) {
        $requestBody = null;
        $inputArray = ['processInstanceId' => $processInstanceId, 'variableName' => $variableName];
        $ret = $this->fetch('GET', 'history/historic-process-instances/{processInstanceId}/variables/{variableName}/data', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates the process instance was found and the requested variable data is returned.',
                404 => 'Indicates the requested process instance was not found or the process instance doesn\'t have a variable with the given name or the variable doesn\'t have a binary stream available. Status message provides additional information.',
            ));
        return $ret;
    }

    /**
     * Create a new comment on a historic process instance
     *
     * request Body example:
     *
     *  {
     *    "message" : "This is a comment.",
     *    "saveProcessInstanceId" : true
     *  }
     *
     * @param array $requestBody
     * @param string $processInstanceId The id of the process instance to create the comment for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function createNewCommentOnHistoricProcessInstance($processInstanceId, $requestBody = "") {
        $inputArray = ['processInstanceId' => $processInstanceId];
        $ret = $this->fetch('POST', 'history/historic-process-instances/{processInstanceId}/comments', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
            ), array (
            ), array (
                201 => 'Indicates the comment was created and the result is returned.',
                400 => 'Indicates the comment is missing from the request.',
                404 => 'Indicates the requested historic process instance was not found.',
            ));
        return $ret;
    }

    /**
     * Get all comments on a historic process instance
     * @param string $processInstanceId The id of the process instance to get the comments for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getAllCommentsOnHistoricProcessInstance($processInstanceId) {
        $requestBody = null;
        $inputArray = ['processInstanceId' => $processInstanceId];
        $ret = $this->fetch('GET', 'history/historic-process-instances/{processInstanceId}/comments', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
            ), array (
            ), array (
                200 => 'Indicates the process instance was found and the comments are returned.',
                404 => 'Indicates the requested task was not found.',
            ));
        return $ret;
    }

    /**
     * Get a comment on a historic process instance
     * @param string $processInstanceId The id of the historic process instance to get the comment for.
     * @param string $commentId The id of the comment.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getCommentOnHistoricProcessInstance($processInstanceId, $commentId) {
        $requestBody = null;
        $inputArray = ['processInstanceId' => $processInstanceId, 'commentId' => $commentId];
        $ret = $this->fetch('GET', 'history/historic-process-instances/{processInstanceId}/comments/{commentId}', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
                1 => 'commentId',
            ), array (
            ), array (
                200 => 'Indicates the historic process instance and comment were found and the comment is returned.',
                404 => 'Indicates the requested historic process instance was not found or the historic process instance doesn\'t have a comment with the given ID.',
            ));
        return $ret;
    }

    /**
     * Delete a comment on a historic process instance
     * @param string $processInstanceId The id of the historic process instance to delete the comment for.
     * @param string $commentId The id of the comment.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function deleteCommentOnHistoricProcessInstance($processInstanceId, $commentId) {
        $requestBody = null;
        $inputArray = ['processInstanceId' => $processInstanceId, 'commentId' => $commentId];
        $ret = $this->fetch('DELETE', 'history/historic-process-instances/{processInstanceId}/comments/{commentId}', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
                1 => 'commentId',
            ), array (
            ), array (
                204 => 'Indicates the historic process instance and comment were found and the comment is deleted. Response body is left empty intentionally.',
                404 => 'Indicates the requested task was not found or the historic process instance doesn\'t have a comment with the given ID.',
            ));
        return $ret;
    }

    /**
     * Get a single historic task instance
     * @param mixed $taskId

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getSingleHistoricTaskInstance($taskId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId];
        $ret = $this->fetch('GET', 'history/historic-task-instances/{taskId}', $requestBody, $inputArray, array (
                0 => 'taskId'
            ), array (
            ), array (
                200 => 'Indicates that the historic task instances could be found.',
                404 => 'Indicates that the historic task instances could not be found.',
            ));
        return $ret;
    }

    /**
     * Get historic task instances
     *
     * input hash keys:
     *
     * taskId                        : An id of the historic task instance.
     * processInstanceId             : The process instance id of the historic task instance.
     * processDefinitionKey          : The process definition key of the historic task instance.
     * processDefinitionKeyLike      : The process definition key of the historic task instance, which matches the given value.
     * processDefinitionId           : The process definition id of the historic task instance.
     * processDefinitionName         : The process definition name of the historic task instance.
     * processDefinitionNameLike     : The process definition name of the historic task instance, which matches the given value.
     * processBusinessKey            : The process instance business key of the historic task instance.
     * processBusinessKeyLike        : The process instance business key of the historic task instance that matches the given value.
     * executionId                   : The execution id of the historic task instance.
     * taskDefinitionKey             : The task identifier from the process definition for the historic task instance.
     * taskName                      : The task name of the historic task instance.
     * taskNameLike                  : The task name with 'like' operator for the historic task instance.
     * taskDescription               : The task description of the historic task instance.
     * taskDescriptionLike           : The task description with 'like' operator for the historic task instance.
     * taskDeleteReason              : The task delete reason of the historic task instance.
     * taskDeleteReasonLike          : The task delete reason with 'like' operator for the historic task instance.
     * taskAssignee                  : The assignee of the historic task instance.
     * taskAssigneeLike              : The assignee with 'like' operator for the historic task instance.
     * taskOwner                     : The owner of the historic task instance.
     * taskOwnerLike                 : The owner with 'like' operator for the historic task instance.
     * taskInvolvedUser              : An involved user of the historic task instance.
     * taskPriority                  : The priority of the historic task instance.
     * finished                      : Indication if the historic task instance is finished.
     * processFinished               : Indication if the process instance of the historic task instance is finished.
     * parentTaskId                  : An optional parent task id of the historic task instance.
     * dueDate                       : Return only historic task instances that have a due date equal this date.
     * dueDateAfter                  : Return only historic task instances that have a due date after this date.
     * dueDateBefore                 : Return only historic task instances that have a due date before this date.
     * withoutDueDate                : Return only historic task instances that have no due-date. When false is provided as value, this parameter is ignored.
     * taskCompletedOn               : Return only historic task instances that have been completed on this date.
     * taskCompletedAfter            : Return only historic task instances that have been completed after this date.
     * taskCompletedBefore           : Return only historic task instances that have been completed before this date.
     * taskCreatedOn                 : Return only historic task instances that were created on this date.
     * taskCreatedBefore             : Return only historic task instances that were created before this date.
     * taskCreatedAfter              : Return only historic task instances that were created after this date.
     * includeTaskLocalVariables     : An indication if the historic task instance local variables should be returned as well.
     * includeProcessVariables       : An indication if the historic task instance global variables should be returned as well.
     * tenantId                      : Only return historic task instances with the given tenantId.
     * tenantIdLike                  : Only return historic task instances with a tenantId like the given value.
     * withoutTenantId               : If true, only returns historic task instances without a tenantId set. If false, the withoutTenantId parameter is ignored.
     * @param array $inputHash

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getHistoricTaskInstances($inputHash = []) {
        $requestBody = null;
        $inputArray = array_merge($inputHash, []);
        $ret = $this->fetch('GET', 'history/historic-task-instances', $requestBody, $inputArray, array (
                0 => 'taskId',
                1 => 'processInstanceId',
                2 => 'processDefinitionKey',
                3 => 'processDefinitionKeyLike',
                4 => 'processDefinitionId',
                5 => 'processDefinitionName',
                6 => 'processDefinitionNameLike',
                7 => 'processBusinessKey',
                8 => 'processBusinessKeyLike',
                9 => 'executionId',
                10 => 'taskDefinitionKey',
                11 => 'taskName',
                12 => 'taskNameLike',
                13 => 'taskDescription',
                14 => 'taskDescriptionLike',
                15 => 'taskDefinitionKey',
                16 => 'taskDeleteReason',
                17 => 'taskDeleteReasonLike',
                18 => 'taskAssignee',
                19 => 'taskAssigneeLike',
                20 => 'taskOwner',
                21 => 'taskOwnerLike',
                22 => 'taskInvolvedUser',
                23 => 'taskPriority',
                24 => 'finished',
                25 => 'processFinished',
                26 => 'parentTaskId',
                27 => 'dueDate',
                28 => 'dueDateAfter',
                29 => 'dueDateBefore',
                30 => 'withoutDueDate',
                31 => 'taskCompletedOn',
                32 => 'taskCompletedAfter',
                33 => 'taskCompletedBefore',
                34 => 'taskCreatedOn',
                35 => 'taskCreatedBefore',
                36 => 'taskCreatedAfter',
                37 => 'includeTaskLocalVariables',
                38 => 'includeProcessVariables',
                39 => 'tenantId',
                40 => 'tenantIdLike',
                41 => 'withoutTenantId',
            ), array (
            ), array (
                200 => 'Indicates that historic process instances could be queried.',
                400 => 'Indicates an parameter was passed in the wrong format. The status-message contains additional information.',
            ));
        return $ret;
    }

    /**
     * Query for historic task instances
     *
     * request Body example:
     *
     *  {
     *    "processDefinitionId" : "oneTaskProcess%3A1%3A4",
     *    ...
     *
     *    "variables" : [
     *      {
     *        "name" : "myVariable",
     *        "value" : 1234,
     *        "operation" : "equals",
     *        "type" : "long"
     *      }
     *    ]
     *  }
     *
     * @param array|string $requestBody
     * @return array
     */
    public function queryForHistoricTaskInstances($requestBody = "") {
        $inputArray = [];
        $ret = $this->fetch('POST', 'query/historic-task-instances', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates request was successful and the tasks are returned',
                400 => 'Indicates an parameter was passed in the wrong format. The status-message contains additional information.',
            ));
        return $ret;
    }

    /**
     * Delete a historic task instance
     * @param mixed $taskId

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function deleteHistoricTaskInstance($taskId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId];
        $ret = $this->fetch('DELETE', 'history/historic-task-instances/{taskId}', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates that the historic task instance was deleted.',
                404 => 'Indicates that the historic task instance could not be found.',
            ));
        return $ret;
    }

    /**
     * Get the identity links of a historic task instance
     * @param mixed $taskId

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getTheIdentityLinksOfHistoricTaskInstance($taskId) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId];
        $ret = $this->fetch('GET', 'history/historic-task-instance/{taskId}/identitylinks', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates request was successful and the identity links are returned',
                404 => 'Indicates the task instance could not be found.',
            ));
        return $ret;
    }

    /**
     * Get the binary data for a historic task instance variable
     * @param mixed $taskId
     * @param mixed $variableName

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getTheBinaryDataForHistoricTaskInstanceVariableByTaskIdAndVariableName($taskId, $variableName) {
        $requestBody = null;
        $inputArray = ['taskId' => $taskId, 'variableName' => $variableName];
        $ret = $this->fetch('GET', 'history/historic-task-instances/{taskId}/variables/{variableName}/data', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates the task instance was found and the requested variable data is returned.',
                404 => 'Indicates the requested task instance was not found or the process instance doesn\'t have a variable with the given name or the variable doesn\'t have a binary stream available. Status message provides additional information.',
            ));
        return $ret;
    }

    /**
     * Get historic activity instances
     *
     * input hash keys:
     *
     * activityId                    : An id of the activity instance.
     * activityInstanceId            : An id of the historic activity instance.
     * activityName                  : The name of the historic activity instance.
     * activityType                  : The element type of the historic activity instance.
     * executionId                   : The execution id of the historic activity instance.
     * finished                      : Indication if the historic activity instance is finished.
     * taskAssignee                  : The assignee of the historic activity instance.
     * processInstanceId             : The process instance id of the historic activity instance.
     * processDefinitionId           : The process definition id of the historic activity instance.
     * tenantId                      : Only return instances with the given tenantId.
     * tenantIdLike                  : Only return instances with a tenantId like the given value.
     * withoutTenantId               : If true, only returns instances without a tenantId set. If false, the withoutTenantId parameter is ignored.
     * @param array $inputHash

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getHistoricActivityInstances($inputHash = []) {
        $requestBody = null;
        $inputArray = array_merge($inputHash, []);
        $ret = $this->fetch('GET', 'history/historic-activity-instances', $requestBody, $inputArray, array (
                0 => 'activityId',
                1 => 'activityInstanceId',
                2 => 'activityName',
                3 => 'activityType',
                4 => 'executionId',
                5 => 'finished',
                6 => 'taskAssignee',
                7 => 'processInstanceId',
                8 => 'processDefinitionId',
                9 => 'tenantId',
                10 => 'tenantIdLike',
                11 => 'withoutTenantId',
            ), array (
            ), array (
                200 => 'Indicates that historic activity instances could be queried.',
                400 => 'Indicates an parameter was passed in the wrong format. The status-message contains additional information.',
            ));
        return $ret;
    }

    /**
     * Query for historic activity instances
     *
     * request Body example:
     *
     *  {
     *    "processDefinitionId" : "oneTaskProcess%3A1%3A4"
     *  }
     *
     * @param array $requestBody

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function queryForHistoricActivityInstances($requestBody = "") {
        $inputArray = [];
        $ret = $this->fetch('POST', 'query/historic-activity-instances', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates request was successful and the activities are returned',
                400 => 'Indicates an parameter was passed in the wrong format. The status-message contains additional information.',
            ));
        return $ret;
    }

    /**
     * List of historic variable instances
     * @param string $processInstanceId The process instance id of the historic variable instance.
     * @param string $taskId The task id of the historic variable instance.
     * @param boolean $excludeTaskVariables Indication to exclude the task variables from the result.
     * @param string $variableName The variable name of the historic variable instance.
     * @param string $variableNameLike The variable name using the 'like' operator for the historic variable instance.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getListOfHistoricVariableInstances($processInstanceId, $taskId, $excludeTaskVariables, $variableName, $variableNameLike) {
        $requestBody = null;
        $inputArray = ['processInstanceId' => $processInstanceId, 'taskId' => $taskId, 'excludeTaskVariables' => $excludeTaskVariables, 'variableName' => $variableName, 'variableNameLike' => $variableNameLike];
        $ret = $this->fetch('GET', 'history/historic-variable-instances', $requestBody, $inputArray, array (
                0 => 'processInstanceId',
                1 => 'taskId',
                2 => 'excludeTaskVariables',
                3 => 'variableName',
                4 => 'variableNameLike',
            ), array (
            ), array (
                200 => 'Indicates that historic variable instances could be queried.',
                400 => 'Indicates an parameter was passed in the wrong format. The status-message contains additional information.',
            ));
        return $ret;
    }

    /**
     * Query for historic variable instances
     *
     * request Body example:
     *
     *  {
     *    "processDefinitionId" : "oneTaskProcess%3A1%3A4",
     *    ...
     *
     *    "variables" : [
     *      {
     *        "name" : "myVariable",
     *        "value" : 1234,
     *        "operation" : "equals",
     *        "type" : "long"
     *      }
     *    ]
     *  }
     *
     * @param array $requestBody

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function queryForHistoricVariableInstances($requestBody = "") {
        $inputArray = [];
        $ret = $this->fetch('POST', 'query/historic-variable-instances', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates request was successful and the tasks are returned',
                400 => 'Indicates an parameter was passed in the wrong format. The status-message contains additional information.',
            ));
        return $ret;
    }

    /**
     * Get the binary data for a historic task instance variable
     * @param mixed $varInstanceId

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getTheBinaryDataForHistoricTaskInstanceVariable($varInstanceId) {
        $requestBody = null;
        $inputArray = ['varInstanceId' => $varInstanceId];
        $ret = $this->fetch('GET', 'history/historic-variable-instances/{varInstanceId}/data', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates the variable instance was found and the requested variable data is returned.',
                404 => 'Indicates the requested variable instance was not found or the variable instance doesn\'t have a variable with the given name or the variable doesn\'t have a binary stream available. Status message provides additional information.',
            ));
        return $ret;
    }

    /**
     * Get historic detail
     *
     * input hash keys:
     *
     * id                            : The id of the historic detail.
     * processInstanceId             : The process instance id of the historic detail.
     * executionId                   : The execution id of the historic detail.
     * activityInstanceId            : The activity instance id of the historic detail.
     * taskId                        : The task id of the historic detail.
     * selectOnlyFormProperties      : Indication to only return form properties in the result.
     * selectOnlyVariableUpdates     : Indication to only return variable updates in the result.
     * @param array $inputHash

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getHistoricDetail($inputHash = []) {
        $requestBody = null;
        $inputArray = array_merge($inputHash, []);
        $ret = $this->fetch('GET', 'history/historic-detail', $requestBody, $inputArray, array (
                0 => 'id',
                1 => 'processInstanceId',
                2 => 'executionId',
                3 => 'activityInstanceId',
                4 => 'taskId',
                5 => 'selectOnlyFormProperties',
                6 => 'selectOnlyVariableUpdates',
            ), array (
            ), array (
                200 => 'Indicates that historic detail could be queried.',
                400 => 'Indicates an parameter was passed in the wrong format. The status-message contains additional information.',
            ));
        return $ret;
    }

    /**
     * Query for historic details
     *
     * request Body example:
     *
     *  {
     *    "processInstanceId" : "5",
     *  }
     *
     * @param array $requestBody

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function queryForHistoricDetails($requestBody = "") {
        $inputArray = [];
        $ret = $this->fetch('POST', 'query/historic-detail', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates request was successful and the historic details are returned',
                400 => 'Indicates an parameter was passed in the wrong format. The status-message contains additional information.',
            ));
        return $ret;
    }

    /**
     * Get the binary data for a historic detail variable
     * @param mixed $detailId

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getTheBinaryDataForHistoricDetailVariable($detailId) {
        $requestBody = null;
        $inputArray = ['detailId' => $detailId];
        $ret = $this->fetch('GET', 'history/historic-detail/{detailId}/data', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates the historic detail instance was found and the requested variable data is returned.',
                404 => 'Indicates the requested historic detail instance was not found or the historic detail instance doesn\'t have a variable with the given name or the variable doesn\'t have a binary stream available. Status message provides additional information.',
            ));
        return $ret;
    }

    /**
     * Get form data
     * @param (if $taskId no processDefinitionId)	String	The task id corresponding to the form data that needs to be retrieved.
     * @param (if $processDefinitionId no taskId)	String	The process definition id corresponding to the start event form data that needs to be retrieved.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getFormData($taskId=null, $processDefinitionId=null) {
        $requestBody = null;
        $inputArray = [];
        if($taskId) $inputArray['taskId'] = $taskId;
        if($processDefinitionId) $inputArray['processDefinitionId'] = $processDefinitionId;
        $ret = $this->fetch('GET', 'form/form-data', $requestBody, $inputArray, array (
                0 => 'taskId',
                1 => 'processDefinitionId',
            ), array (
            ), array (
                200 => 'Indicates that form data could be queried.',
                404 => 'Indicates that form data could not be found.',
            ));
        return $ret;
    }

    /**
     * Submit task form data
     * @param integer $taskId
     * @param array $properties
     * @return array
     */
    public function submitTaskFormData($taskId, $properties) {
        $requestBody = [
            'taskId' => $taskId,
            'properties' => $this->buildPropertiesArrayFromHash($properties)
        ];
        $inputArray = [];
        $ret = $this->fetch('POST', 'form/form-data', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates request was successful and the form data was submitted',
                400 => 'Indicates an parameter was passed in the wrong format. The status-message contains additional information.',
            ));
        return $ret;
    }

    /**
     * Submit task form data
     * @param int $processDefinitionId
     * @param string|null $businessKey
     * @param array $properties
     * @return array
     */
    public function submitStartEventFormData($processDefinitionId, $businessKey = null, $properties) {
        //TODO: implement as above but for the start form
        $inputArray = [];
        $ret = $this->fetch('POST', 'form/form-data', $requestBody=null, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates request was successful and the form data was submitted',
                400 => 'Indicates an parameter was passed in the wrong format. The status-message contains additional information.',
            ));
        return $ret;
    }

    /**
     * builds an array which conforms to the format expected by the form post methods from a key=>value php hash
     * @param $hash
     * @return array
     */
    private function buildPropertiesArrayFromHash($hash) {
        $ret = [];
        if(is_array($hash))
            foreach($hash as $key=>$v)
                if(!empty($v))
                    $ret[] = ['id'=>$key, 'value'=>$v];
        return $ret;
    }

    /**
     * List of tables

     * @return ActivitiResult
     **/
    public function getListOfTables() {
        $requestBody = null;
        $inputArray = [];
        $ret = $this->fetch('GET', 'management/tables', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates the request was successful.',
            ));
        return $ret;
    }

    /**
     * Get a single table
     * @param string $tableName The name of the table to get.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getSingleTable($tableName) {
        $requestBody = null;
        $inputArray = ['tableName' => $tableName];
        $ret = $this->fetch('GET', 'management/tables/{tableName}', $requestBody, $inputArray, array (
                0 => 'tableName',
            ), array (
            ), array (
                200 => 'Indicates the table exists and the table count is returned.',
                404 => 'Indicates the requested table does not exist.',
            ));
        return $ret;
    }

    /**
     * Get column info for a single table
     * @param string $tableName The name of the table to get.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getColumnInfoForSingleTable($tableName) {
        $requestBody = null;
        $inputArray = ['tableName' => $tableName];
        $ret = $this->fetch('GET', 'management/tables/{tableName}/columns', $requestBody, $inputArray, array (
                0 => 'tableName',
            ), array (
            ), array (
                200 => 'Indicates the table exists and the table column info is returned.',
                404 => 'Indicates the requested table does not exist.',
            ));
        return $ret;
    }

    /**
     * Get row data for a single table
     * @param string $tableName The name of the table to get.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getRowDataForSingleTable($tableName) {
        $requestBody = null;
        $inputArray = ['tableName' => $tableName];
        $ret = $this->fetch('GET', 'management/tables/{tableName}/data', $requestBody, $inputArray, array (
                0 => 'tableName',
            ), array (
            ), array (
                200 => 'Indicates the table exists and the table row data is returned.',
                404 => 'Indicates the requested table does not exist.',
            ));
        return $ret;
    }

    /**
     * Get engine properties

     * @return ActivitiResult
     **/
    public function getEngineProperties() {
        $requestBody = null;
        $inputArray = [];
        $ret = $this->fetch('GET', 'management/properties', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates the properties are returned.',
            ));
        return $ret;
    }

    /**
     * Get engine info

     * @return ActivitiResult
     **/
    public function getEngineInfo() {
        $requestBody = null;
        $inputArray = [];
        $ret = $this->fetch('GET', 'management/engine', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates the engine info is returned.',
            ));
        return $ret;
    }

    /**
     * Signal event received
     * @param mixed $signalName Name of the signal
     * @param mixed $tenantId ID of the tenant that the signal event should be processed in
     * @param mixed $async If true, handling of the signal will happen asynchronously. Return code will be 202 - Accepted to indicate the request is accepted but not yet executed. If false, handling the signal will be done immedialty and result (200 - OK) will only return after this completed successfully. Defaults to false if omitted.
     * @param mixed $variables Array of variables (in the general variables format) to use as payload to pass along with the signal. Cannot be used in case async is set to true, this will result in an error.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function signalEventReceived($signalName, $tenantId, $async, $variables) {
        $requestBody = null;
        $inputArray = ['signalName' => $signalName, 'tenantId' => $tenantId, 'async' => $async, 'variables' => $variables];
        $ret = $this->fetch('POST', 'runtime/signals', $requestBody, $inputArray, array (
            ), array (
                0 => 'signalName',
                1 => 'tenantId',
                2 => 'async',
                3 => 'variables',
            ), array (
                200 => 'Indicated signal has been processed and no errors occured.',
                202 => 'Indicated signal processing is queued as a job, ready to be executed.',
                400 => 'Signal not processed. The signal name is missing or variables are used toghether with async, which is not allowed. Response body contains additional information about the error.',
            ));
        return $ret;
    }

    /**
     * Get a single job
     * @param string $jobId The id of the job to get.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getSingleJob($jobId) {
        $requestBody = null;
        $inputArray = ['jobId' => $jobId];
        $ret = $this->fetch('GET', 'management/jobs/{jobId}', $requestBody, $inputArray, array (
                0 => 'jobId',
            ), array (
            ), array (
                200 => 'Indicates the job exists and is returned.',
                404 => 'Indicates the requested job does not exist.',
            ));
        return $ret;
    }

    /**
     * Delete a job
     * @param string $jobId The id of the job to delete.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function deleteJob($jobId) {
        $requestBody = null;
        $inputArray = ['jobId' => $jobId];
        $ret = $this->fetch('DELETE', 'management/jobs/{jobId}', $requestBody, $inputArray, array (
                0 => 'jobId',
            ), array (
            ), array (
                204 => 'Indicates the job was found and has been deleted. Response-body is intentionally empty.',
                404 => 'Indicates the requested job was not found.',
            ));
        return $ret;
    }

    /**
     * Execute a single job
     * @param mixed $action Action to perform. Only execute is supported.
     * @param mixed $jobId

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function executeSingleJob($action, $jobId) {
        $requestBody = null;
        $inputArray = ['action' => $action, 'jobId' => $jobId];
        $ret = $this->fetch('POST', 'management/jobs/{jobId}', $requestBody, $inputArray, array (
            ), array (
                0 => 'action',
            ), array (
                204 => 'Indicates the job was executed. Response-body is intentionally empty.',
                404 => 'Indicates the requested job was not found.',
                500 => 'Indicates the an exception occurred while executing the job. The status-description contains additional detail about the error. The full error-stacktrace can be fetched later on if needed.',
            ));
        return $ret;
    }

    /**
     * Get the exception stacktrace for a job
     * @param mixed $jobId

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getTheExceptionStacktraceForJob($jobId) {
        $requestBody = null;
        $inputArray = ['jobId' => $jobId];
        $ret = $this->fetch('GET', 'management/jobs/{jobId}/exception-stacktrace', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates the requested job was not found and the stacktrace has been returned. The response contains the raw stacktrace and always has a Content-type of text/plain.',
                404 => 'Indicates the requested job was not found or the job doesn\'t have an exception stacktrace. Status-description contains additional information about the error.',
            ));
        return $ret;
    }

    /**
     * Get a list of jobs

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getListOfJobs() {
        $requestBody = null;
        $inputArray = [];
        $ret = $this->fetch('GET', 'management/jobs', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates the requested jobs were returned.',
                400 => 'Indicates an illegal value has been used in a url query parameter or the both \'messagesOnly\' and \'timersOnly\' are used as parameters. Status description contains additional details about the error.',
            ));
        return $ret;
    }

    /**
     * Get a single user
     * @param string $userId The id of the user to get.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getSingleUser($userId) {
        $requestBody = null;
        $inputArray = ['userId' => $userId];
        $ret = $this->fetch('GET', 'identity/users/{userId}', $requestBody, $inputArray, array (
                0 => 'userId',
            ), array (
            ), array (
                200 => 'Indicates the user exists and is returned.',
                404 => 'Indicates the requested user does not exist.',
            ));
        return $ret;
    }

    /**
     * Get a list of users

     * @return ActivitiResult
     **/
    public function getListOfUsers() {
        $requestBody = null;
        $inputArray = [];
        $ret = $this->fetch('GET', 'identity/users', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates the requested users were returned.',
            ));
        return $ret;
    }

    /**
     * Update a user
     * @param mixed $userId

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function updateUser($userId) {
        $requestBody = null;
        $inputArray = ['userId' => $userId];
        $ret = $this->fetch('PUT', 'identity/users/{userId}', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates the user was updated.',
                404 => 'Indicates the requested user was not found.',
                409 => 'Indicates the requested user was updated simultaneously.',
            ));
        return $ret;
    }

    /**
     * Create a user
     * @param $requestBody
     * @return array
     */
    public function createUser($requestBody) {
        $inputArray = [];
        $ret = $this->fetch('POST', 'identity/users', $requestBody, $inputArray, array (
            ), array (
            ), array (
                201 => 'Indicates the user was created.',
                400 => 'Indicates the id of the user was missing.',
            ));
        return $ret;
    }

    /**
     * Delete a user
     * @param string $userId The id of the user to delete.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function deleteUser($userId) {
        $requestBody = null;
        $inputArray = ['userId' => $userId];
        $ret = $this->fetch('DELETE', 'identity/users/{userId}', $requestBody, $inputArray, array (
                0 => 'userId',
            ), array (
            ), array (
                204 => 'Indicates the user was found and has been deleted. Response-body is intentionally empty.',
                404 => 'Indicates the requested user was not found.',
            ));
        return $ret;
    }

    /**
     * Get a user's picture
     * @param string $userId The id of the user to get the picture for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getUsersPicture($userId) {
        $requestBody = null;
        $inputArray = ['userId' => $userId];
        $ret = $this->fetch('GET', 'identity/users/{userId}/picture', $requestBody, $inputArray, array (
                0 => 'userId',
            ), array (
            ), array (
                200 => 'Indicates the user was found and has a picture, which is returned in the body.',
                404 => 'Indicates the requested user was not found or the user does not have a profile picture. Status-description contains additional information about the error.',
            ));
        return $ret;
    }

    /**
     * Updating a user's picture
     * @param string $userId The id of the user to get the picture for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getUpdatingUsersPicture($userId) {
        $requestBody = null;
        $inputArray = ['userId' => $userId];
        $ret = $this->fetch('GET', 'identity/users/{userId}/picture', $requestBody, $inputArray, array (
                0 => 'userId',
            ), array (
            ), array (
                200 => 'Indicates the user was found and the picture has been updated. The response-body is left empty intentionally.',
                404 => 'Indicates the requested user was not found.',
            ));
        return $ret;
    }

    /**
     * List a user's info
     * @param string $userId The id of the user to get the info for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function listUsersInfo($userId) {
        $requestBody = null;
        $inputArray = ['userId' => $userId];
        $ret = $this->fetch('PUT', 'identity/users/{userId}/info', $requestBody, $inputArray, array (
                0 => 'userId',
            ), array (
            ), array (
                200 => 'Indicates the user was found and list of info (key and url) is returned.',
                404 => 'Indicates the requested user was not found.',
            ));
        return $ret;
    }

    /**
     * Get a user's info
     * @param string $userId The id of the user to get the info for.
     * @param string $key The key of the user info to get.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getUsersInfo($userId, $key) {
        $requestBody = null;
        $inputArray = ['userId' => $userId, 'key' => $key];
        $ret = $this->fetch('GET', 'identity/users/{userId}/info/{key}', $requestBody, $inputArray, array (
                0 => 'userId',
                1 => 'key',
            ), array (
            ), array (
                200 => 'Indicates the user was found and the user has info for the given key..',
                404 => 'Indicates the requested user was not found or the user doesn\'t have info for the given key. Status description contains additional information about the error.',
            ));
        return $ret;
    }

    /**
     * Update a user's info
     *
     * request Body example:
     *
     *  {
     *     "value":"The updated value"
     *  }
     *
     * @param array $requestBody
     * @param string $userId The id of the user to update the info for.
     * @param string $key The key of the user info to update.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function updateUsersInfo($userId, $key, $requestBody = "") {
        $inputArray = ['userId' => $userId, 'key' => $key];
        $ret = $this->fetch('PUT', 'identity/users/{userId}/info/{key}', $requestBody, $inputArray, array (
                0 => 'userId',
                1 => 'key',
            ), array (
            ), array (
                200 => 'Indicates the user was found and the info has been updated.',
                400 => 'Indicates the value was missing from the request body.',
                404 => 'Indicates the requested user was not found or the user doesn\'t have info for the given key. Status description contains additional information about the error.',
            ));
        return $ret;
    }

    /**
     * Create a new user's info entry
     *
     * request Body example:
     *
     *  {
     *     "key":"key1",
     *     "value":"The value"
     *  }
     *
     * @param array $requestBody
     * @param string $userId The id of the user to create the info for.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function createNewUsersInfoEntry($userId, $requestBody = "") {
        $inputArray = ['userId' => $userId];
        $ret = $this->fetch('POST', 'identity/users/{userId}/info', $requestBody, $inputArray, array (
                0 => 'userId',
            ), array (
            ), array (
                201 => 'Indicates the user was found and the info has been created.',
                400 => 'Indicates the key or value was missing from the request body. Status description contains additional information about the error.',
                404 => 'Indicates the requested user was not found.',
                409 => 'Indicates there is already an info-entry with the given key for the user, update the resource instance (PUT).',
            ));
        return $ret;
    }

    /**
     * Delete a user's info
     * @param string $userId The id of the user to delete the info for.
     * @param string $key The key of the user info to delete.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function deleteUsersInfo($userId, $key) {
        $requestBody = null;
        $inputArray = ['userId' => $userId, 'key' => $key];
        $ret = $this->fetch('DELETE', 'identity/users/{userId}/info/{key}', $requestBody, $inputArray, array (
                0 => 'userId',
                1 => 'key',
            ), array (
            ), array (
                204 => 'Indicates the user was found and the info for the given key has been deleted. Response body is left empty intentionally.',
                404 => 'Indicates the requested user was not found or the user doesn\'t have info for the given key. Status description contains additional information about the error.',
            ));
        return $ret;
    }

    /**
     * Get a single group
     * @param string $groupId The id of the group to get.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function getSingleGroup($groupId) {
        $requestBody = null;
        $inputArray = ['groupId' => $groupId];
        $ret = $this->fetch('GET', 'identity/groups/{groupId}', $requestBody, $inputArray, array (
                0 => 'groupId',
            ), array (
            ), array (
                200 => 'Indicates the group exists and is returned.',
                404 => 'Indicates the requested group does not exist.',
            ));
        return $ret;
    }

    /**
     * Get a list of groups

     * @return ActivitiResult
     **/
    public function getListOfGroups() {
        $requestBody = null;
        $inputArray = [];
        $ret = $this->fetch('GET', 'identity/groups', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates the requested groups were returned.',
            ));
        return $ret;
    }

    /**
     * Update a group
     * @param mixed $groupId

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function updateGroup($groupId) {
        $requestBody = null;
        $inputArray = ['groupId' => $groupId];
        $ret = $this->fetch('PUT', 'identity/groups/{groupId}', $requestBody, $inputArray, array (
            ), array (
            ), array (
                200 => 'Indicates the group was updated.',
                404 => 'Indicates the requested group was not found.',
                409 => 'Indicates the requested group was updated simultaneously.',
            ));
        return $ret;
    }

    /**
     * Create a group

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function createGroup() {
        $requestBody = null;
        $inputArray = [];
        $ret = $this->fetch('POST', 'identity/groups', $requestBody, $inputArray, array (
            ), array (
            ), array (
                201 => 'Indicates the group was created.',
                400 => 'Indicates the id of the group was missing.',
            ));
        return $ret;
    }

    /**
     * Delete a group
     * @param string $groupId The id of the group to delete.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function deleteGroup($groupId) {
        $requestBody = null;
        $inputArray = ['groupId' => $groupId];
        $ret = $this->fetch('DELETE', 'identity/groups/{groupId}', $requestBody, $inputArray, array (
                0 => 'groupId',
            ), array (
            ), array (
                204 => 'Indicates the group was found and has been deleted. Response-body is intentionally empty.',
                404 => 'Indicates the requested group was not found.',
            ));
        return $ret;
    }

    /**
     * Add a member to a group
     * @param string $groupId The id of the group to add a member to.

     * @throws \Exception

     * @return ActivitiResult
     **/
    public function addMemberToGroup($groupId) {
        $requestBody = null;
        $inputArray = ['groupId' => $groupId];
        $ret = $this->fetch('POST', 'identity/groups/{groupId}/members', $requestBody, $inputArray, array (
                0 => 'groupId',
            ), array (
            ), array (
                201 => 'Indicates the group was found and the member has been added.',
                404 => 'Indicates the requested group was not found.',
                409 => 'Indicates the requested user is already a member of the group.',
            ));
        return $ret;
    }


}