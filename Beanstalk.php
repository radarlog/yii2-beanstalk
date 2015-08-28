<?php

namespace yii\beanstalk;

use Yii;
use yii\base\Component;
use Beanstalk\Client;
use RuntimeException;

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
    public $logger = null;

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
            'logger' => $this->logger,
        ]);

        $this->_client->connect();
        $this->connected = $this->_client->connected;
    }

    public function put($data, $tube = 'default', $pri = self::DEFAULT_PRIORITY, $delay = self::DEFAULT_DELAY, $ttr = self::DEFAULT_TTR)
    {
        try {
            if ($tube != $this->_usedTube) {
                $this->_client->useTube($tube);
                $this->_usedTube = $tube;
            }

            if (is_array($data))
                $data = json_encode($data); //stringify;

            return $this->_client->put($pri, $delay, $ttr, $data);
        } catch (RuntimeException $e) {
            Yii::error($e->getMessage());
            return false;
        }

    }

    public function __call($name, $args)
    {
        try {
            $response = call_user_func_array([$this->_client, $name], $args);
            return $this->checkNeedConvert($name, $response);
        } catch (RuntimeException $e) {
            Yii::error($e->getMessage());
            return false;
        }
    }

    protected function checkNeedConvert($name, $response)
    {
        $needConvert = ['reserve', 'peekReady', 'peekDelayed', 'peekBuried'];
        if (in_array($name, $needConvert) && is_array($response)) {
            $object = new \stdClass();
            $object->id = $response['id'];
            $object->data = json_decode($response['body']); //try convert
            if (json_last_error() !== JSON_ERROR_NONE)
                $object->data = $response['body'];

            $response = $object;
        }
        return $response;
    }
}
