<?php

namespace Skillshare\YiiSentry\Test;

use CLogger;
use IApplicationComponent;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\Severity;
use Sentry\Transport\TransportFactoryInterface;
use Sentry\Transport\TransportInterface;
use Skillshare\YiiSentry\SentryLogRoute;
use Yii;

/**
 * @coversDefaultClass \Skillshare\YiiSentry\SentryLogRoute
 * @uses \Skillshare\YiiSentry\SentryComponent
 */
class SentryLogRouteTest extends TestCase
{
	use MockeryPHPUnitIntegration;

	public function severityLogLevelProvider()
	{
		$r = [];

		$r['CLogger::PROFILE'] = [Severity::debug(), CLogger::LEVEL_PROFILE];
		$r['CLogger::TRACE']   = [Severity::debug(), CLogger::LEVEL_TRACE];
		$r['CLogger::INFO']    = [Severity::info(), CLogger::LEVEL_INFO];
		$r['CLogger::WARNING'] = [Severity::warning(), CLogger::LEVEL_WARNING];
		$r['CLogger::ERROR']   = [Severity::error(), CLogger::LEVEL_ERROR];
		$r['Raw string debug'] = [Severity::debug(), 'debug',];
		$r['Raw string warn']  = [Severity::warning(), 'warn'];
		$r['Raw string fatal'] = [Severity::fatal(), 'fatal'];
		$r['Raw string DEBUG'] = [Severity::debug(), 'DEBUG',];
		$r['Raw string WARN']  = [Severity::warning(), 'WARN'];
		$r['Raw string FATAL'] = [Severity::fatal(), 'FATAL'];
		$r['Unknown is error'] = [Severity::error(), __CLASS__];
		return $r;
	}

	/**
	 * @covers ::getSeverityFromLogLevel
	 * @dataProvider severityLogLevelProvider
	 */
	public function testSeverityFromLogLevel($expect, $level): void
	{
		$this->assertEquals($expect, SentryLogRoute::getSeverityFromLogLevel($level));
	}

	/**
	 * @covers ::processLogs
	 * @uses \Skillshare\YiiSentry\SentryLogRoute::getRaven
	 * @uses \Skillshare\YiiSentry\SentryLogRoute::getSeverityFromLogLevel
	 */
	public function testProcessLogsWithNoRaven(): void
	{
		$logger = new CLogger();
		$logger->log('Nothing');

		$sut = $this->createSentryLogRouteWithRaven(null);
		$this->assertNull($sut->collectLogs($logger, true));
	}

	/**
	 * @covers ::processLogs
	 * @uses \Skillshare\YiiSentry\SentryLogRoute::getRaven
	 * @uses \Skillshare\YiiSentry\SentryLogRoute::getSeverityFromLogLevel
	 */
	public function testProcessLogs(): void
	{
		$message1  = 'Test Log Message';
		$level1    = 'error';
		$severity1 = Severity::error();
		$category1 = __METHOD__;

		$message2  = 'Another Test Log Message';
		$level2    = 'warn';
		$severity2 = Severity::warning();
		$category2 = __CLASS__;

		$raven = $this->getTestClientForLogs([
			[$message1, $severity1, $category1],
			[$message2, $severity2, $category2],
		]);

		$sut = $this->createSentryLogRouteWithRaven($raven);

		$logger = new CLogger();
		$logger->log($message1, $level1, $category1);
		$logger->log($message2, $level2, $category2);

		$sut->collectLogs($logger, true);
	}

	/**
	 * @covers ::getRaven
	 * @uses  \Skillshare\YiiSentry\SentryLogRoute::getSeverityFromLogLevel
	 * @uses  \Skillshare\YiiSentry\SentryLogRoute::processLogs
	 */
	public function testGetRavenPreExisting()
	{
		$raven = $this->getTestClientForLogs([['Test', Severity::info(), 'application']]);
		$sut   = $this->createSentryLogRouteWithRaven($raven);

		$logger = new CLogger();
		$logger->log('Test');
		$sut->collectLogs($logger, true);
	}

	/**
	 * @covers ::getRaven
	 * @uses  \Skillshare\YiiSentry\SentryLogRoute::getSeverityFromLogLevel
	 * @uses  \Skillshare\YiiSentry\SentryLogRoute::processLogs
	 */
	public function testGetRavenFromNonExistentComponent()
	{
		$sut = new SentryLogRoute();

		$logger = new CLogger();
		$logger->log('Test');
		$sut->collectLogs($logger, true);
		$this->assertTrue(true, 'Things should succeed without errors');
	}

	/**
	 * @covers ::getRaven
	 * @uses  \Skillshare\YiiSentry\SentryLogRoute::getSeverityFromLogLevel
	 * @uses  \Skillshare\YiiSentry\SentryLogRoute::processLogs
	 */
	public function testGetRavenFromPreExistentComponent()
	{
		$raven = $this->getTestClientForLogs([['Test', Severity::info(), 'application']]);

		/** @var IApplicationComponent&\Mockery\MockInterface $sentry */
		$sentry = Mockery::mock(IApplicationComponent::class);
		$sentry->shouldReceive('getIsInitialized')->andReturnTrue();
		$sentry->shouldReceive('getRaven')->andReturn($raven);

		$sut = new SentryLogRoute();
		Yii::app()->setComponent($sut->sentryComponent, $sentry);

		$logger = new CLogger();
		$logger->log('Test');
		$sut->collectLogs($logger, true);
	}

	protected function createSentryLogRouteWithRaven($raven): SentryLogRoute
	{
		$sut = new SentryLogRoute();

		$ref = new ReflectionProperty($sut, 'raven');
		$ref->setAccessible(true);
		$ref->setValue($sut, $raven);
		return $sut;
	}

	protected function getTestClientForLogs(array $logs): ClientInterface
	{
		/** @var TransportInterface&\Mockery\MockInterface $transport */
		$transport = Mockery::mock(TransportInterface::class);
		foreach ($logs as $log) {
			[$message, $severity, $category] = $log;
			$transport->shouldReceive('send')->withArgs(function (Event $event) use ($message, $severity, $category) {
				self::assertEquals($message, $event->getMessage());
				self::assertTrue($event->getLevel()->isEqualTo($severity), 'Log Level was not set correctly');
				self::assertArrayHasKey('category', $event->getExtraContext(), 'Category should be set on event');
				self::assertEquals($category, $event->getExtraContext()['category'], 'Category should be set correctly on event');
				return true;
			})->once();
		}

		/** @var TransportFactoryInterface&\Mockery\MockInterface $transportFactory */
		$transportFactory = Mockery::mock(TransportFactoryInterface::class);
		$transportFactory->shouldReceive('create')->andReturn($transport);
		return ((new ClientBuilder())->setTransportFactory($transportFactory))->getClient();
	}
}
