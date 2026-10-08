<?php

namespace Local\Bptable;

/**
 * Пользовательское поле (main UF) «Таблица распределения» — для CRM (смарт-процессы, сделки).
 * Классический API UF-типов + VIEW/EDIT_CALLBACK для карточки CRM (entity editor).
 * Хранение: mediumtext (до 16 МБ), формат v1. В карточке — только просмотр (Q9).
 */
final class UserFieldType
{
	public const USER_TYPE_ID = 'local_bptable';

	public static function GetUserTypeDescription(): array
	{
		return [
			'USER_TYPE_ID'  => self::USER_TYPE_ID,
			'CLASS_NAME'    => self::class,
			'DESCRIPTION'   => 'Таблица распределения (local.bptable)',
			'BASE_TYPE'     => 'string',
			'VIEW_CALLBACK' => [self::class, 'GetPublicView'],
			'EDIT_CALLBACK' => [self::class, 'GetPublicEdit'],
		];
	}

	public static function GetDBColumnType($arUserField)
	{
		return 'mediumtext';
	}

	public static function PrepareSettings($arUserField)
	{
		$preset = $arUserField['SETTINGS']['PRESET'] ?? null;
		return ['PRESET' => Config::isPreset($preset) ? $preset : Config::DEFAULT_PRESET];
	}

	public static function GetSettingsHTML($arUserField, $arHtmlControl, $bVarsFromForm)
	{
		$current = $bVarsFromForm
			? ($GLOBALS[$arHtmlControl['NAME']]['PRESET'] ?? null)
			: ($arUserField['SETTINGS']['PRESET'] ?? null);
		if (!Config::isPreset($current))
		{
			$current = Config::DEFAULT_PRESET;
		}

		$options = '';
		foreach (Config::presets() as $code => $p)
		{
			$options .= '<option value="' . htmlspecialcharsbx($code) . '"' . ($code === $current ? ' selected' : '') . '>'
				. htmlspecialcharsbx($p['title']) . '</option>';
		}
		return '<tr><td>Набор колонок:</td><td><select name="' . htmlspecialcharsbx($arHtmlControl['NAME']) . '[PRESET]">'
			. $options . '</select></td></tr>';
	}

	public static function CheckFields($arUserField, $value)
	{
		return [];
	}

	/** Нормализация + дозаполнение перед записью (в т.ч. из БП «Изменение документа») */
	public static function OnBeforeSave($arUserField, $value)
	{
		if (is_array($value) && array_key_exists('VALUE', $value))
		{
			$value = $value['VALUE'];
		}
		$data = Normalizer::normalize($value, self::preset($arUserField), true, false);
		return Normalizer::toJson($data);
	}

	/* ---------- отображение ---------- */

	public static function GetPublicView($arUserField, $arAdditionalParameters = [])
	{
		return Renderer::view(self::data($arUserField, $arUserField['VALUE'] ?? ''), true);
	}

	/** Карточка в режиме редактирования: просмотр + скрытое поле (значение не затирается) */
	public static function GetPublicEdit($arUserField, $arAdditionalParameters = [])
	{
		$name = $arAdditionalParameters['NAME'] ?? $arUserField['FIELD_NAME'];
		$raw = $arUserField['VALUE'] ?? '';
		if (is_array($raw))
		{
			$raw = reset($raw) ?: '';
		}
		return Renderer::view(self::data($arUserField, $raw), true)
			. '<input type="hidden" name="' . htmlspecialcharsbx($name) . '" value="' . htmlspecialcharsbx((string)$raw) . '">';
	}

	public static function GetEditFormHTML($arUserField, $arHtmlControl)
	{
		$raw = (string)($arHtmlControl['VALUE'] ?? '');
		return Renderer::view(self::data($arUserField, htmlspecialcharsback($raw)), true)
			. '<details style="margin-top:6px"><summary style="cursor:pointer;font-size:11px;color:#828b95">JSON</summary>'
			. '<textarea name="' . htmlspecialcharsbx($arHtmlControl['NAME']) . '" rows="8" style="width:100%;font-family:monospace;font-size:11px">'
			. $raw . '</textarea></details>';
	}

	public static function GetAdminListViewHTML($arUserField, $arHtmlControl)
	{
		return Renderer::view(self::data($arUserField, htmlspecialcharsback((string)($arHtmlControl['VALUE'] ?? ''))), false);
	}

	public static function GetPublicText($arUserField)
	{
		return Renderer::plainText(self::data($arUserField, $arUserField['VALUE'] ?? ''));
	}

	/* ---------- helpers ---------- */

	private static function preset($arUserField): string
	{
		$p = $arUserField['SETTINGS']['PRESET'] ?? null;
		return Config::isPreset($p) ? $p : Config::DEFAULT_PRESET;
	}

	private static function data($arUserField, $raw): array
	{
		if (is_array($raw))
		{
			$raw = reset($raw) ?: '';
		}
		return Normalizer::fromStored($raw, self::preset($arUserField));
	}
}
