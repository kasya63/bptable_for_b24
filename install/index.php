<?php

use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;

class local_bptable extends CModule
{
	public $MODULE_ID = 'local.bptable';
	public $MODULE_VERSION;
	public $MODULE_VERSION_DATE;
	public $MODULE_NAME = 'Таблица распределения для БП';
	public $MODULE_DESCRIPTION = 'Тип свойства/поля «таблица» (проекты, статьи, суммы) для заданий БП и списков';
	public $PARTNER_NAME = 'KazDream';
	public $PARTNER_URI = '';

	private const TYPES = [
		'\\Local\\Bptable\\IblockProperty',
		'\\Local\\Bptable\\IblockPropertyPayroll',
		'\\Local\\Bptable\\IblockPropertyPayment',
	];

	private const UF_TYPE = '\\Local\\Bptable\\UserFieldType';

	public function __construct()
	{
		$arModuleVersion = [];
		include __DIR__ . '/version.php';
		$this->MODULE_VERSION = $arModuleVersion['VERSION'];
		$this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
	}

	public function DoInstall()
	{
		ModuleManager::registerModule($this->MODULE_ID);
		$this->InstallEvents();
		$this->InstallFiles();
	}

	public function DoUninstall()
	{
		$this->UnInstallEvents();
		$this->UnInstallFiles();
		ModuleManager::unRegisterModule($this->MODULE_ID);
	}

	public function InstallEvents()
	{
		$em = EventManager::getInstance();
		foreach (self::TYPES as $class)
		{
			// повторная регистрация не дублирует обработчик
			$em->unRegisterEventHandler('iblock', 'OnIBlockPropertyBuildList', $this->MODULE_ID, $class, 'GetUserTypeDescription');
			$em->registerEventHandler('iblock', 'OnIBlockPropertyBuildList', $this->MODULE_ID, $class, 'GetUserTypeDescription');
		}
		$em->unRegisterEventHandler('main', 'OnUserTypeBuildList', $this->MODULE_ID, self::UF_TYPE, 'GetUserTypeDescription');
		$em->registerEventHandler('main', 'OnUserTypeBuildList', $this->MODULE_ID, self::UF_TYPE, 'GetUserTypeDescription');
		return true;
	}

	public function UnInstallEvents()
	{
		$em = EventManager::getInstance();
		foreach (self::TYPES as $class)
		{
			$em->unRegisterEventHandler('iblock', 'OnIBlockPropertyBuildList', $this->MODULE_ID, $class, 'GetUserTypeDescription');
		}
		$em->unRegisterEventHandler('main', 'OnUserTypeBuildList', $this->MODULE_ID, self::UF_TYPE, 'GetUserTypeDescription');
		return true;
	}

	public function InstallFiles()
	{
		CopyDirFiles(__DIR__ . '/js', $_SERVER['DOCUMENT_ROOT'] . '/local/js', true, true);
		CopyDirFiles(__DIR__ . '/activities', $_SERVER['DOCUMENT_ROOT'] . '/local/activities', true, true);
		return true;
	}

	public function UnInstallFiles()
	{
		DeleteDirFilesEx('/local/js/local.bptable');
		DeleteDirFilesEx('/local/activities/lbptablebuildactivity');
		return true;
	}
}
