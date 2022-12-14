<?php
namespace Skillshare\YiiSentry;

use CLogger;
use CLogRoute;
use Sentry\ClientInterface;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\Scope;
use Yii;

class SentryLogRoute extends CLogRoute
{
	/**
	 * @var string Component ID of the sentry client that should be used to send the logs
	 */
	public string $sentryComponent = 'sentry';

	/**
	 * @see self::getStackTrace().
	 */
	public string $tracePattern = '/#(?<number>\d+) (?<file>[^(]+)\((?<line>\d+)\): (?<cls>[^-]+)(->|::)(?<func>[^\(]+)/m';

	/**
	 * Raven_Client instance from SentryComponent->getRaven();
	 */
	protected ?ClientInterface $raven = null;

	public static function getSeverityFromLogLevel(string $level): Severity
	{
		$severityLevels = [
			CLogger::LEVEL_PROFILE => Severity::debug(),
			CLogger::LEVEL_TRACE   => Severity::debug(),
			'debug'                => Severity::debug(),
			CLogger::LEVEL_INFO    => Severity::info(),
			CLogger::LEVEL_WARNING => Severity::warning(),
			'warn'                 => Severity::warning(),
			CLogger::LEVEL_ERROR   => Severity::error(),
			'fatal'                => Severity::fatal(),
		];

		$level = strtolower($level);
		if (array_key_exists($level, $severityLevels)) {
			return $severityLevels[$level];
		}

		return Severity::error();
	}

	/**
	 * @param array{string, string, string, float} $logs
	 *
	 * @inheritdoc
	 */
	protected function processLogs($logs): void
	{
		/** @phpstan-ignore-next-line */
		if (count($logs) == 0) {
			return;
		}

        $raven = $this->getRaven();

		if ($raven === null) {
			return;
		}

		SentrySdk::getCurrentHub()->withScope(function (Scope $scope) use ($raven, $logs) {
			foreach ($logs as $log) {
				/** @phpstan-ignore-next-line */
				[$message, $level, $category, $timestamp] = $log;

				$title = (string) preg_replace('#Stack trace:.+#s', '', $message); // remove stack trace from title
				// ensure %'s in messages aren't interpreted as replacement
				// characters by the vsprintf inside raven
				$title = (string) str_replace('%', '%%', $title);

				$scope->setExtras([
					'category'  => $category,
					'timestamp' => $timestamp, // TODO: I dont know if this has en effect
				]);
				$raven->captureMessage($title, self::getSeverityFromLogLevel($level), $scope);
			}
		});
	}

	/**
	 * Parse yii stack trace for sentry.
	 *
	 * Example log string:
	 * #22 /var/www/example.is74.ru/vendor/yiisoft/yii/framework/web/CWebApplication.php(282): CController->run('index')
	 * @return array<array{file: string, line: string|int, function: string, class: string}>
	 */
	protected function getStackTrace(string $log): array
	{
		$stack = array();
		if (strpos($log, 'Stack trace:') !== false) {
			if (preg_match_all($this->tracePattern, $log, $m, PREG_SET_ORDER)) {
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
	 * Return Raven_Client instance or null if error.
	 *
	 * @return ClientInterface|null
	 */
	protected function getRaven(): ?ClientInterface
	{
		if (!isset($this->raven)) {
			$this->raven = null;
			if (!Yii::app()->hasComponent($this->sentryComponent)) {
				Yii::log("'$this->sentryComponent' does not exist", CLogger::LEVEL_TRACE, __CLASS__);
			} else {
				/** @var SentryComponent|null $sentry */
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
