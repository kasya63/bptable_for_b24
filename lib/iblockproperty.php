<?php

namespace Local\Bptable;

/**
 * Пользовательский тип свойства инфоблока «Таблица распределения».
 * Значение — JSON-строка формата v1 в VALUE свойства типа S.
 *
 * Типы:
 *  - LocalBpTable        — для свойства списка, набор колонок в настройках свойства;
 *  - LocalBpTablePayroll — для полей/переменных БП, колонки ЗП (с сотрудником);
 *  - LocalBpTablePayment — для полей/переменных БП, колонки оплаты (без сотрудника).
 *
 * Контексты GetPublicEditHTML:
 *  - БП (задание, переменная, дизайнер): $arProperty без ID → редактор;
 *  - форма элемента списка: $arProperty с ID → только просмотр (Q9).
 */
class IblockProperty
{
	public const USER_TYPE = 'LocalBpTable';
	/** null — набор колонок из настроек свойства */
	public const PRESET = null;

	public static function GetUserTypeDescription(): array
	{
		return [
			'PROPERTY_TYPE'        => 'S',
			'USER_TYPE'            => static::USER_TYPE,
			'DESCRIPTION'          => static::description(),
			'GetPublicViewHTML'    => [static::class, 'GetPublicViewHTML'],
			'GetPublicEditHTML'    => [static::class, 'GetPublicEditHTML'],
			'GetAdminListViewHTML' => [static::class, 'GetAdminListViewHTML'],
			'GetPropertyFieldHtml' => [static::class, 'GetPropertyFieldHtml'],
			'ConvertToDB'          => [static::class, 'ConvertToDB'],
			'ConvertFromDB'        => [static::class, 'ConvertFromDB'],
			'CheckFields'          => [static::class, 'CheckFields'],
			'GetLength'            => [static::class, 'GetLength'],
			'PrepareSettings'      => [static::class, 'PrepareSettings'],
			'GetSettingsHTML'      => [static::class, 'GetSettingsHTML'],
		];
	}

	protected static function description(): string
	{
		return 'Таблица распределения (local.bptable)';
	}

	/* ---------- хранение ---------- */

	public static function ConvertToDB($arProperty, $value)
	{
		$raw = is_array($value) && array_key_exists('VALUE', $value) ? $value['VALUE'] : $value;
		$data = Normalizer::normalize($raw, static::preset($arProperty), true, static::PRESET !== null);

		return [
			'VALUE' => Normalizer::toJson($data),
			'DESCRIPTION' => '',
		];
	}

	public static function ConvertFromDB($arProperty, $value)
	{
		return $value;
	}

	public static function CheckFields($arProperty, $value)
	{
		$raw = is_array($value) && array_key_exists('VALUE', $value) ? $value['VALUE'] : $value;
		if (is_string($raw) && strlen($raw) > 60000)
		{
			return ['Таблица слишком большая для хранения (> 60 КБ).'];
		}
		// Обязательные колонки проверяем только при заполнении в БП (задание).
		// Форма элемента списка и запись из БП в свойство не блокируются.
		if (static::isBizprocContext($arProperty) && is_string($raw) && trim($raw) !== '')
		{
			$errors = Validator::errors(Normalizer::fromStored($raw, static::preset($arProperty)));
			return $errors ? array_merge(['Таблица распределения:'], $errors) : [];
		}
		return [];
	}

	public static function GetLength($arProperty, $value)
	{
		$raw = is_array($value) ? ($value['VALUE'] ?? '') : $value;
		return is_string($raw) ? strlen(trim($raw)) : 0;
	}

	/* ---------- отображение ---------- */

	public static function GetPublicViewHTML($arProperty, $value, $strHTMLControlName)
	{
		$data = static::data($arProperty, $value);
		$mode = is_array($strHTMLControlName) ? (string)($strHTMLControlName['MODE'] ?? '') : '';

		if ($mode === 'BIZPROC')
		{
			// печатное значение в БП: результат прогоняется через HTMLToTxt
			return nl2br(htmlspecialcharsbx(Renderer::plainText($data)));
		}
		if (in_array($mode, ['CSV_EXPORT', 'SIMPLE_TEXT', 'ELEMENT_TEMPLATE', 'EXCEL_EXPORT'], true))
		{
			return Renderer::summaryText($data);
		}
		return Renderer::view($data, false);
	}

	public static function GetAdminListViewHTML($arProperty, $value, $strHTMLControlName)
	{
		return Renderer::view(static::data($arProperty, $value), false);
	}

	public static function GetPublicEditHTML($arProperty, $value, $strHTMLControlName)
	{
		$raw = is_array($value) ? ($value['VALUE'] ?? '') : (string)$value;
		$name = is_array($strHTMLControlName) ? (string)($strHTMLControlName['VALUE'] ?? '') : '';

		if (static::isBizprocContext($arProperty))
		{
			if (is_string($raw) && \CBPActivity::isExpression($raw))
			{
				$raw = ''; // в дизайнере выражение уходит в соседнее поле «_text»
			}
			$data = Normalizer::normalize($raw, static::preset($arProperty), true, static::PRESET !== null);
			return Editor::render($name, $data);
		}

		// форма элемента списка: только просмотр + скрытое поле, чтобы не затереть значение
		return Renderer::view(static::data($arProperty, $value), true)
			. '<input type="hidden" name="' . htmlspecialcharsbx($name) . '" value="' . htmlspecialcharsbx((string)$raw) . '">';
	}

	/** Админка: просмотр + JSON в textarea (для ручных тестов) */
	public static function GetPropertyFieldHtml($arProperty, $value, $strHTMLControlName)
	{
		$raw = is_array($value) ? ($value['VALUE'] ?? '') : (string)$value;
		$name = is_array($strHTMLControlName) ? ($strHTMLControlName['VALUE'] ?? '') : '';

		return Renderer::view(static::data($arProperty, $value), true)
			. '<details style="margin-top:6px"><summary style="cursor:pointer;font-size:11px;color:#828b95">JSON</summary>'
			. '<textarea name="' . htmlspecialcharsbx($name) . '" rows="8" style="width:100%;font-family:monospace;font-size:11px">'
			. htmlspecialcharsbx((string)$raw) . '</textarea></details>';
	}

	/* ---------- настройки свойства ---------- */

	public static function PrepareSettings($arProperty)
	{
		$preset = static::PRESET ?? ($arProperty['USER_TYPE_SETTINGS']['PRESET'] ?? null);
		return ['PRESET' => Config::isPreset($preset) ? $preset : Config::DEFAULT_PRESET];
	}

	public static function GetSettingsHTML($arProperty, $strHTMLControlName, &$arPropertyFields)
	{
		$arPropertyFields = [
			'HIDE' => ['ROW_COUNT', 'COL_COUNT', 'DEFAULT_VALUE', 'MULTIPLE_CNT', 'WITH_DESCRIPTION', 'SEARCHABLE', 'FILTRABLE', 'SMART_FILTER', 'MULTIPLE'],
			'SET'  => ['MULTIPLE' => 'N', 'WITH_DESCRIPTION' => 'N', 'SEARCHABLE' => 'N', 'FILTRABLE' => 'N', 'SMART_FILTER' => 'N'],
		];

		$current = static::preset($arProperty);
		if (static::PRESET !== null)
		{
			return '<tr><td>Набор колонок:</td><td>' . htmlspecialcharsbx(Config::presets()[$current]['title']) . '</td></tr>';
		}

		$options = '';
		foreach (Config::presets() as $code => $p)
		{
			$options .= '<option value="' . htmlspecialcharsbx($code) . '"' . ($code === $current ? ' selected' : '') . '>'
				. htmlspecialcharsbx($p['title']) . '</option>';
		}
		return '<tr><td>Набор колонок:</td><td><select name="' . htmlspecialcharsbx($strHTMLControlName['NAME']) . '[PRESET]">'
			. $options . '</select></td></tr>';
	}

	/* ---------- helpers ---------- */

	protected static function isBizprocContext($arProperty): bool
	{
		return is_array($arProperty)
			&& !isset($arProperty['ID'])
			&& array_key_exists('LINK_IBLOCK_ID', $arProperty);
	}

	protected static function preset($arProperty): string
	{
		if (static::PRESET !== null)
		{
			return static::PRESET;
		}
		$settings = $arProperty['USER_TYPE_SETTINGS'] ?? null;
		if (is_string($settings))
		{
			$settings = @unserialize($settings, ['allowed_classes' => false]);
		}
		$p = is_array($settings) ? ($settings['PRESET'] ?? null) : null;
		return Config::isPreset($p) ? $p : Config::DEFAULT_PRESET;
	}

	protected static function data($arProperty, $value): array
	{
		$raw = is_array($value) ? ($value['VALUE'] ?? '') : $value;
		return Normalizer::fromStored($raw, static::preset($arProperty));
	}
}
