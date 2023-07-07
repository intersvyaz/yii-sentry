<?php
namespace Skillshare\YiiSentry;

use CApplicationComponent;
use CAssetManager;
use CClientScript;
use CException;
use CJavaScript;
use CWebApplication;
use CWebUser;
use IApplicationComponent;
use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Sentry\ErrorHandler;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Yii;

class SentryComponent extends CApplicationComponent
{
	/**
	 * @var string Sentry DSN.
	 * @see https://github.com/getsentry/raven-php#configuration
	 */
	public string $dsn = '';

	/**
	 * @var mixed[] Raven_Client options.
	 * @see https://github.com/getsentry/raven-php#configuration
	 */
	public array $options = [];

	/**
	 * Publish, register and configure Raven-JS.
	 * @see https://raven-js.readthedocs.org/
	 */
	public bool $useRavenJs = false;

	/**
	 * Raven-JS configuration options.
	 * @var mixed[]
	 * @see https://raven-js.readthedocs.org/en/latest/config/index.html#optional-settings
	 */
	public array $ravenJsOptions = [];

	/**
	 * Raven-JS plugins.
	 * @var mixed[]
	 * @see https://raven-js.readthedocs.org/en/latest/plugins/index.html
	 */
	public array $ravenJsPlugins = [];

	/**
	 * Initialize Raven_ErrorHandler.
	 */
	public bool $useRavenErrorHandler = false;

	/**
	 * @var ClientInterface|null instance.
	 */
	protected ?ClientInterface $raven = null;

	/**
	 * @inheritdoc
	 */
	public function init(): void
	{
		parent::init();
		if ($this->useRavenJs) {
			$this->registerRavenJs();
		}
		if ($this->useRavenErrorHandler) {
			$this->registerRavenErrorHandler();
		}
	}

	/**
	 * Get Raven_Client instance.
	 */
	public function getRaven(): ClientInterface
	{
		if (!isset($this->raven)) {
			$this->registerRaven();
		}

        /** @var ClientInterface */
		return $this->raven;
	}

	/**
	 * Register and configure Raven js.
	 * @param mixed[]|null $options If null, then will be used $this->ravenJsOptions.
	 * @param mixed[]|null $plugins If null, then will be used $this->ravenJsPlugins.
	 * @param mixed[]|null $context If null, then will be used $this->getUserContext().
	 * @throws CException
	 */
	public function registerRavenJs(?array $options = null, ?array $plugins = null, ?array $context = null): bool
	{
		/** @var CAssetManager|null $assetManager */
		$assetManager = $this->getComponent('assetManager');
		/** @var CClientScript|null $clientScript */
		$clientScript = $this->getComponent('clientScript');

		if (!$assetManager || !$clientScript) {
			return false;
		}

		$jsOptions = $options ?? $this->ravenJsOptions;
		$jsPlugins = $plugins ?? $this->ravenJsPlugins;
		$jsContext = $context ?? $this->getUserContext();

		$assetUrl = $assetManager->publish(__DIR__ . '/../../bower-asset/raven-js/dist');
		$clientScript
			->registerScriptFile($assetUrl . '/raven.js', CClientScript::POS_HEAD)
			->registerScript(
				'raven-js1',
				'Raven.config(\'' . $this->getJsDsn() . '\', ' . CJavaScript::encode($jsOptions) . ').install();',
				CClientScript::POS_BEGIN
			);

		if ($jsContext) {
			$clientScript->registerScript(
				'raven-js2',
				'Raven.setUserContext(' . CJavaScript::encode($jsContext) . ');',
				CClientScript::POS_BEGIN
			);
		}

		foreach ($jsPlugins as $plugin) {
			$clientScript->registerScriptFile($assetUrl . '/plugins/' . $plugin . '.js', CClientScript::POS_HEAD);
		}

		return true;
	}

	/**
	 * Initialize raven client.
	 */
	protected function registerRaven(): void
	{
		$this->raven = ClientBuilder::create($this->options)->getClient();
		SentrySdk::getCurrentHub()->bindClient($this->raven);

		$userContext = $this->getUserContext();
		SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) use ($userContext) {
			if ($userContext) {
				$scope->setUser($userContext);
			}
		});
	}

	/**
	 * Return Dsn without security token.
	 */
	protected function getJsDsn(): string
	{
		return (string) preg_replace('#:\w+@#', '@', $this->dsn);
	}

	/**
	 * Get get context (id, name).
	 * @return array{id: mixed, name: string}|null
	 */
	protected function getUserContext(): ?array
	{
		/** @var CWebUser|null $user */
		$user = $this->getComponent('user');
		if ($user && (!property_exists($user, 'isGuest') || !$user->isGuest)) {
			return array(
				'id' => $user->getId(),
				'name' => strtoupper($user->getName()),
			);
		}
		return null;
	}

	/**
	 * Get Yii component if exists and available.
	 */
	protected function getComponent(string $component): ?IApplicationComponent
	{
		$app = Yii::app();

		if (!$app instanceof CWebApplication) {
			return null;
		}

		/** @var IApplicationComponent|null $instance */
		$instance = $app->getComponent($component);

		if ($instance !== null) {
			return $instance;
		}

		return null;
	}

	/**
	 * Register Raven Error Handlers for exceptions and errors.
	 */
	protected function registerRavenErrorHandler(): bool
	{
		if (!isset($this->raven)) {
			$this->registerRaven();
		}

		ErrorHandler::registerOnceExceptionHandler();
		ErrorHandler::registerOnceErrorHandler();
		ErrorHandler::registerOnceFatalErrorHandler();

		return true;
	}
}
