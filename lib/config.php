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

	public const DEFAULT_PRESET = 'payment';

	/** Описание колонок. type: period | ref | employee | amount */
	public static function columns(): array
	{
		return [
			'period'       => ['title' => 'Период',      'type' => 'period'],
			'division'     => ['title' => 'Дивизион',    'type' => 'ref'],
			'organization' => ['title' => 'Организация', 'type' => 'ref'],
			'project'      => ['title' => 'Проект',      'type' => 'ref'],
			'expense'      => ['title' => 'Статья',      'type' => 'ref'],
			'employee'     => ['title' => 'Сотрудник',   'type' => 'employee'],
			'amount'       => ['title' => 'Сумма',       'type' => 'amount'],
		];
	}

	public static function presets(): array
	{
		return [
			'payroll' => [
				'title'   => 'ЗП (с сотрудником)',
				'columns' => ['period', 'division', 'organization', 'project', 'expense', 'employee', 'amount'],
			],
			'payment' => [
				'title'   => 'Оплата (без сотрудника)',
				'columns' => ['period', 'division', 'organization', 'project', 'expense', 'amount'],
			],
		];
	}

	/** Справочники для ref-колонок */
	public static function refs(): array
	{
		return [
			'division' => [
				'kind'        => 'hl',
				'hl_id'       => 32,
				'title_field' => 'UF_NAME',
				'code_field'  => 'UF_CODE',
			],
			'organization' => [
				'kind'       => 'company',          // CRM-компания (мои реквизиты)
				'code_field' => 'UF_ORGANIZATION_ID_API_1C', // на реквизите
			],
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
