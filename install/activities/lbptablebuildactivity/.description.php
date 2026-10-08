<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) { die(); }

$arActivityDescription = [
	'NAME'        => 'Таблица распределения: подготовить',
	'DESCRIPTION' => 'Приводит JSON (ответ ИИ, ручной ввод) к формату таблицы распределения, дозаполняет названия/коды по ID, считает итог',
	'TYPE'        => 'activity',
	'CLASS'       => 'LbpTableBuildActivity',
	'JSCLASS'     => 'BizProcActivity',
	'CATEGORY'    => ['ID' => 'other'],
	'RETURN'      => [
		'Result'     => ['NAME' => 'Таблица (JSON)', 'TYPE' => 'string'],
		'RowsCount'  => ['NAME' => 'Количество строк', 'TYPE' => 'int'],
		'Total'      => ['NAME' => 'Итого', 'TYPE' => 'double'],
		'Unresolved' => ['NAME' => 'Ячеек без ID', 'TYPE' => 'int'],
		'Summary'    => ['NAME' => 'Сводка (текст)', 'TYPE' => 'text'],
	],
];
