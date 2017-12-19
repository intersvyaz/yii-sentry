<?php
namespace Intersvyaz\YiiSentry;

use CApplicationComponent;
use CAssetManager;
use CClientScript;
use CException;
use CJavaScript;
use CWebApplication;
use CWebUser;
use IApplicationComponent;
use Raven_Client;
use Raven_ErrorHandler;
use Yii;

class SentryComponent extends CApplicationComponent
{
	/**
	 * @var string Sentry DSN.
	 * @see https://github.com/getsentry/raven-php#configuration
	 */
	public $dsn;

	/**
	 * @var array Raven_Client options.
	 * @see https://github.com/getsentry/raven-php#configuration
	 */
	public $options = array();

	/**
	 * Publish, register and configure Raven-JS.
	 * @see https://raven-js.readthedocs.org/
	 * @var bool
	 */
	public $useRavenJs = false;

	/**
	 * Raven-JS configuration options.
	 * @var array
	 * @see https://raven-js.readthedocs.org/en/latest/config/index.html#optional-settings
	 */
	public $ravenJsOptions = array();

	/**
	 * Raven-JS plugins.
	 * @var array
	 * @see https://raven-js.readthedocs.org/en/latest/plugins/index.html
	 */
	public $ravenJsPlugins = array();

	/**
	 * Initialize Raven_ErrorHandler.
	 * @var bool
	 */
	public $useRavenErrorHandler = false;

	/**
	 * @var Raven_Client instance.
	 */
	protected $raven;

	/**
	 * @inheritdoc
	 */
	public function init()
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
	 * @return Raven_Client
	 */
	public function getRaven()
	{
		if (!isset($this->raven)) {
			$this->registerRaven();
		}

		return $this->raven;
	}

	/**
	 * Register and configure Raven js.
	 * @param array|null $options If null, then will be used $this->ravenJsOptions.
	 * @param array|null $plugins If null, then will be used $this->ravenJsPlugins.
	 * @param array|null $context If null, then will be used $this->getUserContext().
	 * @return bool
	 * @throws CException
	 */
	public function registerRavenJs(array $options = null, array $plugins = null, array $context = null)
	{
		/** @var CAssetManager $assetManager */
		$assetManager = $this->getComponent('assetManager');
		/** @var CClientScript $clientScript */
		$clientScript = $this->getComponent('clientScript');

		if (!$assetManager || !$clientScript) {
			return false;
		}

		$jsOptions = $options !== null ? $options : $this->ravenJsOptions;
		$jsPlugins = $plugins !== null ? $plugins : $this->ravenJsPlugins;
		$jsContext = $context !== null ? $context : $this->getUserContext();

		$assetUrl = $assetManager->publish(__DIR__ . '/assets');
		$clientScript
			->registerScriptFile($assetUrl . '/raven.min.js', CClientScript::POS_HEAD)
			->registerScript(
				'raven-js1',
				'Raven.config(\'' . $this->getJsDsn() . '\', ' . CJavaScript::encode($jsOptions) . ').install()',
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
	}

	/**
	 * Initialize raven client.
	 */
	protected function registerRaven()
	{
		$this->raven = new Raven_Client($this->dsn, $this->options);

		if ($userContext = $this->getUserContext()) {
			$this->raven->user_context($userContext);
		}
	}

	/**
	 * Return Dsn without security token.
	 * @return string
	 */
	protected function getJsDsn()
	{
		return preg_replace('#:\w+@#', '@', $this->dsn);
	}

	/**
	 * Get get context (id, name).
	 * @return array|null
	 */
	protected function getUserContext()
	{
		/** @var CWebUser $user */
		$user = $this->getComponent('user');
		if ($user && !$user->isGuest) {
			return array(
				'id' => $user->getId(),
				'name' => strtoupper($user->getName()),
			);
		}
		return null;
	}

	/**
	 * Get Yii component if exists and available.
	 * @param string $component
	 * @return IApplicationComponent|null
	 */
	protected function getComponent($component)
	{
		if (!Yii::app() instanceof CWebApplication) {
			return null;
		}

		if ($instance = Yii::app()->getComponent($component)) {
			return $instance;
		}

		return null;
	}

	/**
	 * Register Raven Error Handlers for exceptions and errors.
	 * @return bool
	 */
	protected function registerRavenErrorHandler()
	{
		$raven = $this->getRaven();
		if ($raven) {
			$handler = new Raven_ErrorHandler($raven);
			$handler->registerExceptionHandler();
			$handler->registerErrorHandler();
			$handler->registerShutdownFunction();

			return true;
		}

		return false;
	}
}
