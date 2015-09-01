<?php

namespace yii\beanstalk;

use Yii;
use yii\console\Controller;
use yii\helpers\Console;

class ConsoleController extends Controller
{
    const BURY = "bury";
    const DELETE = "delete";
    const RELEASE = "release";

    public $defaultAction = 'default';
    public $sleep = 0; //in micro seconds

    private $tubeActions = []; //tubes as keys and action handler as values
    private $_stopWorking = false;

    public function beforeAction($action)
    {
        try {
            $beanstalk = Yii::$app->beanstalk;
            $this->setTubeActions($beanstalk);

            while (!$this->_stopWorking) {
                $job = $beanstalk->reserve();
                if (!$job || !array_key_exists($tube = $beanstalk->statsJob($job->id)->tube, $this->tubeActions))
                    continue;

                $actionMethod = $this->tubeActions[$tube];

                $this->setSigHandler(); //start working
                $result = call_user_func([$this, $actionMethod], $job); //run action
                switch ($result) {
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
                $this->setSigDefault(); //end working

                if ($this->sleep)
                    usleep($this->sleep);
            }
        } catch (BeanstalkException $e) {
            $this->stdout($this->ansiFormat("{$e->getMessage()} Exit.\n", Console::FG_RED));
        }

        return false;
    }

    private function setSigDefault()
    {
        if (!extension_loaded('pcntl'))
            return false;

        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);
        return true;
    }

    private function setSigHandler()
    {
        if (!extension_loaded('pcntl'))
            return false;

        declare (ticks = 1);
        pcntl_signal(SIGTERM, [$this, 'sigHandler']);
        pcntl_signal(SIGINT, [$this, 'sigHandler']);
        return true;
    }

    public function sigHandler($signo)
    {
        switch ($signo) {
            case SIGTERM:
            case SIGINT:
                $this->stdout($this->ansiFormat("Will exit after current job done\n", Console::FG_RED));
                $this->_stopWorking = true;
                break;
            default:
                break;
        }
    }

    private function setTubeActions(Beanstalk $beanstalk)
    {
        $params = Yii::$app->request->getParams();
        array_shift($params); //shift controller/action

        $tubes = $params ? $params : $beanstalk->listTubes(); //listen all existed tubes if not set any

        foreach($tubes as $tube) {
            if ($beanstalk->watch($tube)) {
                $this->stdout($this->ansiFormat("Watching {$tube} tube\n", Console::FG_YELLOW));
                $methodName = $this->tube2action($tube);
                $this->tubeActions[$tube] = $this->hasMethod($methodName) ? $methodName : $this->tube2action($this->defaultAction);
            }
        }
    }

    protected function tube2action($tube)
    {
        return 'action' . str_replace(' ', '', ucwords(implode(' ', explode('-', $tube))));
    }

    public function actionDefault($job) {
        $this->stdout("Done {$job->id} job\n");

        return self::DELETE;
    }
}
