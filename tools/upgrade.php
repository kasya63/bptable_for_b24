<?php
/**
 * Обновление без переустановки модуля (данные свойств не трогает):
 * php -f /home/bitrix/www/local/modules/local.bptable/tools/upgrade.php
 */
if (PHP_SAPI !== 'cli') { die('CLI only'); }
$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../..');
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

require_once __DIR__ . '/../install/index.php';
$m = new local_bptable();
$m->InstallEvents();
$m->InstallFiles();

$file = $_SERVER['DOCUMENT_ROOT'] . '/local/js/local.bptable/editor.js';
echo 'Обработчики типов: ok' . PHP_EOL;
echo 'JS: ' . (is_file($file) ? $file . ' (' . filesize($file) . ' байт)' : 'НЕ СКОПИРОВАН') . PHP_EOL;

// проверка по БД (b_module_to_module) + дорегистрация старым API, если строк нет
$conn = \Bitrix\Main\Application::getConnection();
$need = [
	['iblock', 'OnIBlockPropertyBuildList', 'Local\\Bptable\\IblockProperty'],
	['iblock', 'OnIBlockPropertyBuildList', 'Local\\Bptable\\IblockPropertyPayroll'],
	['iblock', 'OnIBlockPropertyBuildList', 'Local\\Bptable\\IblockPropertyPayment'],
	['main', 'OnUserTypeBuildList', 'Local\\Bptable\\UserFieldType'],
];
$rows = $conn->query("SELECT ID, FROM_MODULE_ID, MESSAGE_ID, TO_CLASS, TO_METHOD FROM b_module_to_module WHERE TO_MODULE_ID = 'local.bptable'")->fetchAll();
foreach ($need as [$mod, $event, $class])
{
	$found = false;
	foreach ($rows as $r)
	{
		if ($r['FROM_MODULE_ID'] === $mod && $r['MESSAGE_ID'] === $event && ltrim($r['TO_CLASS'], '\\') === $class)
		{
			$found = true;
		}
	}
	if (!$found)
	{
		RegisterModuleDependences($mod, $event, 'local.bptable', '\\' . $class, 'GetUserTypeDescription');
		echo "Дорегистрирован: {$mod}:{$event} → {$class}" . PHP_EOL;
	}
}
foreach ($conn->query("SELECT ID, FROM_MODULE_ID, MESSAGE_ID, TO_CLASS, TO_METHOD FROM b_module_to_module WHERE TO_MODULE_ID = 'local.bptable' ORDER BY ID") as $r)
{
	echo "БД: #{$r['ID']} {$r['FROM_MODULE_ID']}:{$r['MESSAGE_ID']} → {$r['TO_CLASS']}::{$r['TO_METHOD']}" . PHP_EOL;
}
$act = $_SERVER['DOCUMENT_ROOT'] . '/local/activities/lbptablebuildactivity/lbptablebuildactivity.php';
echo 'Действие БП: ' . (is_file($act) ? 'ok' : 'НЕ СКОПИРОВАНО') . PHP_EOL;
