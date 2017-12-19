<?php
namespace Intersvyaz\YiiSentry;

use CLogger;
use CLogRoute;
use Raven_Client;
use Yii;

class SentryLogRoute extends CLogRoute
{
	/**
	 * @var string Component ID of the sentry client that should be used to send the logs
	 */
	public $sentryComponent = 'sentry';

	/**
	 * @see self::getStackTrace().
	 * @var string
	 */
	public $tracePattern = '/#(?<number>\d+) (?<file>[^(]+)\((?<line>\d+)\): (?<cls>[^-]+)(->|::)(?<func>[^\(]+)/m';

	public $except = [];

	/**
	 * Raven_Client instance from SentryComponent->getRaven();
	 * @var Raven_Client
	 */
	protected $raven;

	public function init() {
		parent::init();

		if (is_string($this->except)) {
			$this->except = preg_split('/[\s,]+/',strtolower($this->except),-1,PREG_SPLIT_NO_EMPTY);
		} else {
			new \CException('Bad except value');
		}
	}


	/**
	 * @inheritdoc
	 */
	protected function processLogs($logs)
	{
		if (count($logs) == 0) {
			return;
		}

		if (!$raven = $this->getRaven()) {
			return;
		}

		foreach ($logs as $log) {
			list($message, $level, $category, $timestamp) = $log;

			if (!in_array(strtolower($category), $this->except)) {

				$title = preg_replace('#Stack trace:.+#s', '', $message); // remove stack trace from title
				$raven->captureMessage(
					$title,
					array(
						'extra' => array(
							'category' => $category,
						),
					),
					array(
						'level' => $level,
						'timestamp' => $timestamp,
					),
					$this->getStackTrace($message)
				);

			}
		}
	}

	/**
	 * Parse yii stack trace for sentry.
	 *
	 * Example log string:
	 * #22 /var/www/example.is74.ru/vendor/yiisoft/yii/framework/web/CWebApplication.php(282): CController->run('index')
	 * @param string $log
	 * @return array|string
	 */
	protected function getStackTrace($log)
	{
		$stack = array();
		if (strpos($log, 'Stack trace:') !== false) {
			if (preg_match_all($this->tracePattern, $log, $m, PREG_SET_ORDER)) {
				$stack = array();
				foreach ($m as $row) {
					$stack[] = array(
						'file' => $row['file'],
						'line' => $row['line'],
						'function' => $row['func'],
						'class' => $row['cls'],
					);
				}
			}

		}

		return $stack;
	}

	/**
	 * Return Raven_Client instance or false if error.
	 * @return Raven_Client|bool
	 */
	protected function getRaven()
	{
		if (!isset($this->raven)) {
			$this->raven = false;
			if (!Yii::app()->hasComponent($this->sentryComponent)) {
				Yii::log("'$this->sentryComponent' does not exist", CLogger::LEVEL_TRACE, __CLASS__);
			} else {
				/** @var SentryComponent $sentry */
				$sentry = Yii::app()->{$this->sentryComponent};
				if (!$sentry || !$sentry->getIsInitialized()) {
					Yii::log("'$this->sentryComponent' not initialised", CLogger::LEVEL_TRACE, __CLASS__);
				} else {
					$this->raven = $sentry->getRaven();
				}
			}
		}

		return $this->raven;
	}
}
