<?php

namespace Skillshare\YiiSentry\Test;

use CWebApplication;
use IApplicationComponent;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Sentry\Client;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\Severity;
use Skillshare\YiiSentry\SentryComponent;
use Yii;

/**
 * @coversDefaultClass \Skillshare\YiiSentry\SentryComponent
 */
class SentryComponentTest extends TestCase
{
	use MockeryPHPUnitIntegration;

	private CWebApplication $app;

	protected function setUp(): void
	{
		// Re-init Sentry
		SentrySdk::init();

		// Resets the app entirely between runs
		Yii::setApplication(null);
		$config    = [
			'basePath'   => dirname(__DIR__) . '/runtime',
			'components' => [
				'db' => [
					'connectionString' => 'sqlite::memory:',
				],
			],
		];
		$this->app = Yii::createWebApplication($config);
	}

	/**
	 * @covers ::getRaven
	 * @uses \Skillshare\YiiSentry\SentryComponent::getComponent
	 * @uses \Skillshare\YiiSentry\SentryComponent::getUserContext
	 * @uses \Skillshare\YiiSentry\SentryComponent::registerRaven
	 */
	public function testGetRaven(): void
	{
		$sut = new SentryComponent();
		$out = $sut->getRaven();
		$this->assertInstanceOf(ClientInterface::class, $out);
	}

	/**
	 * @covers ::getRaven
	 * @uses \Skillshare\YiiSentry\SentryComponent::getComponent
	 * @uses \Skillshare\YiiSentry\SentryComponent::getUserContext
	 * @uses \Skillshare\YiiSentry\SentryComponent::registerRaven
	 */
	public function testGetRavenWithPreExisting(): void
	{
		$sut = new SentryComponent();
		/** @var ClientInterface&MockInterface $raven */
		$raven = Mockery::mock(ClientInterface::class);

		$ref = new ReflectionProperty($sut, 'raven');
		$ref->setAccessible(true);
		$ref->setValue($sut, $raven);

		$out = $sut->getRaven();
		$this->assertInstanceOf(ClientInterface::class, $out);
		$this->assertSame($raven, $out, 'Should return pre-established property');
	}

	/**
	 * @covers ::registerRaven
	 * @covers ::getUserContext
	 * @uses \Skillshare\YiiSentry\SentryComponent::getComponent
	 * @uses \Skillshare\YiiSentry\SentryComponent::getRaven
	 * @uses \Skillshare\YiiSentry\SentryComponent::getUserContext
	 */
	public function testRegisterRaven()
	{
		$dsn       = 'http://tim:tam@bob.com/8675309';
		$publicKey = 'tim';
		$secretKey = 'tam';
		$dsnHost   = 'bob.com';
		$projectId = 8675309;

		$environment = 'dev';
		$release     = 'dev_release';
		$callback    = fn($i) => 1;

		$sut          = new SentryComponent();
		$sut->options = [
			'dsn'         => $dsn,
			'environment' => $environment,
			'release'     => $release,
			'before_send' => $callback,
		];

		$out = $sut->getRaven();
		$this->assertInstanceOf(ClientInterface::class, $out);
		$options = $out->getOptions();
		$this->assertSame($environment, $options->getEnvironment());
		$this->assertSame($release, $options->getRelease());
		$this->assertSame($callback, $options->getBeforeSendCallback());

		$dsnObj = $options->getDsn(false);
		$this->assertSame($dsnHost, $dsnObj->getHost());
		$this->assertSame($publicKey, $dsnObj->getPublicKey());
		$this->assertSame($secretKey, $dsnObj->getSecretKey());
		$this->assertSame($projectId, $dsnObj->getProjectId());
	}

	/**
	 * @covers ::registerRaven
	 * @covers ::getUserContext
	 * @uses \Skillshare\YiiSentry\SentryComponent::getComponent
	 * @uses \Skillshare\YiiSentry\SentryComponent::getRaven
	 * @uses \Skillshare\YiiSentry\SentryComponent::getUserContext
	 */
	public function testRegisterRavenWithUserContext(): void
	{
		$userId   = 37;
		$userName = 'Dante';
		$userInfo = ['id' => $userId, 'name' => strtoupper($userName)];

		$sut = new SentryComponent();

		/** @var IApplicationComponent&\Mockery\MockInterface $user */
		$user = \Mockery::mock(IApplicationComponent::class);
		$user->shouldReceive('getIsInitialized')->andReturnTrue();
		$user->shouldReceive('getId')->andReturn($userId);
		$user->shouldReceive('getName')->andReturn($userName);
		$this->app->setComponent('user', $user);

		$sut->getRaven()->captureMessage(
			__METHOD__,
			Severity::info(),
			SentrySdk::getCurrentHub()->pushScope()
		);
		$scope            = SentrySdk::getCurrentHub()->pushScope();
		$eventUserContext = $scope->applyToEvent(new Event(), [])->getUserContext()->toArray();
		$this->assertEquals($userInfo, $eventUserContext, 'Scope should have received the user information');
	}

	/**
	 * @covers ::registerRaven
	 * @covers ::getUserContext
	 * @uses \Skillshare\YiiSentry\SentryComponent::getComponent
	 * @uses \Skillshare\YiiSentry\SentryComponent::getRaven
	 * @uses \Skillshare\YiiSentry\SentryComponent::getUserContext
	 */
	public function testRegisterRavenWithGuestUserContext(): void
	{
		$userId   = 37;
		$userName = 'Dante';
		$userInfo = [];

		$sut = new SentryComponent();

		/** @var IApplicationComponent&\Mockery\MockInterface $user */
		$user = \Mockery::mock(IApplicationComponent::class);
		$user->shouldReceive('getIsInitialized')->andReturnTrue();
		$user->isGuest = true;
		$user->shouldReceive('getId')->andReturn($userId)->never();
		$user->shouldReceive('getName')->andReturn($userName)->never();
		$this->app->setComponent('user', $user);

		$sut->getRaven()->captureMessage(
			__METHOD__,
			Severity::info(),
			SentrySdk::getCurrentHub()->pushScope()
		);
		$scope            = SentrySdk::getCurrentHub()->pushScope();
		$eventUserContext = $scope->applyToEvent(new Event(), [])->getUserContext()->toArray();
		$this->assertEquals($userInfo, $eventUserContext, 'Scope should have received the user information');
	}

	/**
	 * @coversNothing
	 */
	public function testEverything()
	{
		$sut = new SentryComponent();
		$sut->init();

		$callback = fn() => 1;

		$sut->options = [
			'dsn'         => 'http://tim:tam@bob.com/8675309',
			'environment' => 'dev',
			'release'     => 'dev_release',
			'before_send' => $callback,
		];

		$raven = $sut->getRaven();

		$this->assertInstanceOf(Client::class, $raven);
		$options = $raven->getOptions();
		$this->assertEquals('dev', $options->getEnvironment());
		$this->assertEquals('dev_release', $options->getRelease());
		$this->assertSame($callback, $options->getBeforeSendCallback());
		$dsn = $options->getDsn(false);
		$this->assertEquals(8675309, $dsn->getProjectId());
		$this->assertEquals('tim', $dsn->getPublicKey());
		$this->assertEquals('tam', $dsn->getSecretKey());
		$this->assertEquals('bob.com', $dsn->getHost());
	}
}
