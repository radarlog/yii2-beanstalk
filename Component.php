<?php

namespace yii\beanstalk;

class Component extends \yii\base\Component
{
	public $host = "127.0.0.1";
	public $port = 11300;
	public $connectTimeout = 1;
	public $connected = false;

	public function init() {
		parent::init();
	}
}