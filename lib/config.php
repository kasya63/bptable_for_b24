<?php

namespace Local\Bptable;

/**
 * Вся конфигурация в одном месте: колонки, пресеты, справочники.
 */
final class Config
{
	public const FORMAT_VERSION = 1;
	public const TIMEZONE = 'Asia/Almaty';

	/**
	 * Ячейка-справочник без ID:
	 *  true  — сохраняем {id: null, title: "текст"} (подсвечивается жёлтым);
	 *  false — ячейка пустая.
	 */
	public const KEEP_TEXT_WITHOUT_ID = true;

	public const DEFAULT_PRESET = 'payroll';

	/**
	 * Колонки. type: ref | employee | amount.
	 * required — без значения строка не принимается (для ref — нужен ID из справочника).
	 */
	public static function columns(): array
	{
		return [
			'project'  => ['title' => 'Проект',    'type' => 'ref',      'required' => false],
			'expense'  => ['title' => 'Статья',    'type' => 'ref',      'required' => true],
			'employee' => ['title' => 'Сотрудник', 'type' => 'employee', 'required' => true],
			'amount'   => ['title' => 'Сумма',     'type' => 'amount',   'required' => true],
		];
	}

	/**
	 * Решение под одну задачу — один набор колонок.
	 * Период ставит 1С, дивизион и организация общие на заявку — в таблице их нет.
	 */
	public static function presets(): array
	{
		return [
			'payroll' => [
				'title'   => 'Распределение по сотрудникам',
				'columns' => ['project', 'expense', 'employee', 'amount'],
			],
		];
	}

	/** Справочники для ref-колонок */
	public static function refs(): array
	{
		return [
			'project' => [
				'kind'           => 'crm',
				'entity_type_id' => 140,
				'code_field'     => 'UF_CRM_20_ID_FIN_HUB',
				'url'            => '/crm/type/140/details/#ID#/',
			],
			'expense' => [
				'kind'           => 'crm',
				'entity_type_id' => 150,
				'code_field'     => 'UF_CRM_21_ID_FIN_HUB',
				'url'            => '/crm/type/150/details/#ID#/',
			],
		];
	}

	public static function companyUrl(): string
	{
		return '/crm/company/details/#ID#/';
	}

	public static function userUrl(): string
	{
		return '/company/personal/user/#ID#/';
	}

	/** Альтернативные ключи во входном JSON (ответ ИИ, ручной ввод) */
	public static function keyAliases(): array
	{
		return [
			'период' => 'period',
			'дивизион' => 'division', 'division_id' => 'division',
			'организация' => 'organization', 'org' => 'organization', 'company' => 'organization',
			'проект' => 'project',
			'статья' => 'expense', 'article' => 'expense', 'expense_item' => 'expense',
			'сотрудник' => 'employee', 'user' => 'employee',
			'сумма' => 'amount', 'sum' => 'amount',
		];
	}

	public static function presetColumns(string $preset): array
	{
		$presets = self::presets();
		return ($presets[$preset] ?? $presets[self::DEFAULT_PRESET])['columns'];
	}

	public static function isPreset(?string $preset): bool
	{
		return $preset !== null && isset(self::presets()[$preset]);
	}
}
