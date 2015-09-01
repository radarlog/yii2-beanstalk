<?php

namespace yii\beanstalk;

use Yii;
use yii\base\Component;
use Beanstalk\Client;

class Beanstalk extends Component
{
    const DEFAULT_PRIORITY = 1000;
    const DEFAULT_DELAY = 0; //Do not wait to put job into the ready queue.
    const DEFAULT_TTR = 60; //Give the job 1 minute to run.

    public $host = "127.0.0.1";
    public $port = 11300;
    public $timeout = 1;
    public $persistent = true;
    public $connected = false;

    private $_usedTube;
    protected $_client;

    public function init()
    {
        parent::init();

        $this->_client = new Client([
            'persistent' => $this->persistent,
            'host' => $this->host,
            'port' => $this->port,
            'timeout' => $this->timeout,
            'logger' => $this,
        ]);

        $this->_client->connect();
        $this->connected = $this->_client->connected;
        if(!$this->connected)
            throw new BeanstalkException('Is beanstalkd running?');
    }

    public function error($message, array $context = [])
    {
        Yii::error($message, __METHOD__); //log all errors
    }

    public function put($data, $tube = 'default', $pri = self::DEFAULT_PRIORITY, $delay = self::DEFAULT_DELAY, $ttr = self::DEFAULT_TTR)
    {
        if ($tube != $this->_usedTube) {
            $this->_client->useTube($tube);
            $this->_usedTube = $tube;
        }

        if (is_array($data))
            $data = json_encode($data); //stringify;

        return $this->_client->put($pri, $delay, $ttr, $data);
    }

    public function __call($name, $args)
    {
        $response = call_user_func_array([$this->_client, $name], $args);
        return $this->arrayToObject($name, $response);
    }

    protected function arrayToObject($name, $response)
    {
        $job2object = ['reserve', 'peekReady', 'peekDelayed', 'peekBuried'];
        if (in_array($name, $job2object) && is_array($response)) {
            $object = new \stdClass();
            $object->id = $response['id'];
            $object->data = json_decode($response['body']); //try convert
            if (json_last_error() !== JSON_ERROR_NONE)
                $object->data = $response['body'];

            $response = $object;
        }

        $stats2object = ['statsJob', 'statsTube', 'stats'];
        if (in_array($name, $stats2object) && is_array($response))
            $response = (object)$response;

        return $response;
    }
}
