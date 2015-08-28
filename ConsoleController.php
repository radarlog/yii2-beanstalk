<?php

namespace yii\beanstalk;

use Yii;
use yii\console\Controller;
use yii\helpers\Console;
use RuntimeException;

class ConsoleController extends Controller
{
    const BURY = "bury";
    const DELETE = "delete";
    const RELEASE = "release";

    const RESERVE_TIMEOUT = 5;

    public $defaultAction = 'default';
    public $sleep = 0; //in micro seconds

    private $_isWorking = false;
    private $_stopWorking = false;

    public function beforeAction($action)
    {
        if (!parent::beforeAction($action))
            return false;

        $this->regSigHandler();

        $tube = $action->id;
        try {
            $beanstalk = Yii::$app->beanstalk;
            $tubes = $beanstalk->listTubes();
            if (in_array($tube, $tubes)) {
                $beanstalk->watch($tube);
                $this->stdout($this->ansiFormat("Watching {$tube} tube\n", Console::FG_YELLOW));
            } else {
                $this->stdout($this->ansiFormat("Unable to watch {$tube} tube\n", Console::FG_RED));
                return false;
            }

            while (!$this->_stopWorking) {
                $job = $beanstalk->reserve(static::RESERVE_TIMEOUT); //unblock stream
                if (!$job)
                    continue;

                $this->_isWorking = true;
                $response = call_user_func([$this, $action->actionMethod], $job); //run action
                switch ($response) {
                    case self::RELEASE:
                        $beanstalk->release($job->id, $beanstalk::DEFAULT_PRIORITY, $beanstalk::DEFAULT_DELAY);
                        break;
                    case self::DELETE:
                        $beanstalk->delete($job->id);
                        break;
                    case self::BURY:
                    default:
                        $beanstalk->bury($job->id, $beanstalk::DEFAULT_PRIORITY);
                        break;
                }
                $this->_isWorking = false;

                if ($this->sleep)
                    usleep($this->sleep);
            }
        } catch (RuntimeException $e) {
            Yii::error($e->getMessage());
            return false;
        }

        return false;
    }

    public function regSigHandler()
    {
        if (!extension_loaded('pcntl')) {
            $this->stdout($this->ansiFormat("Signal Handling Disabled!\n", Console::FG_YELLOW));
            return false;
        }
        declare (ticks = 1);
        pcntl_signal(SIGTERM, [$this, 'sigHandler']);
        pcntl_signal(SIGINT, [$this, 'sigHandler']);
        $this->stdout($this->ansiFormat("Signal Handling Registered!\n", Console::FG_YELLOW));
        return true;
    }

    public function sigHandler($signo)
    {
        $this->stdout($this->ansiFormat("Received {$signo} signal\n", Console::FG_YELLOW));
        switch ($signo) {
            case SIGTERM:
            case SIGINT:
                $this->stdout($this->ansiFormat("Will exit after current job done\n", Console::FG_RED));
                if (!$this->_isWorking)
                    Yii::$app->end();

                $this->_stopWorking = true;
                break;
            default:
                break;
        }
    }
}
