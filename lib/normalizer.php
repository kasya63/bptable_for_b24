<?php

namespace Local\Bptable;

/**
 * Приводит любой вход (упрощённый от ИИ, полный v1, массив) к формату хранения v1:
 * {"v":1,"preset":"payroll","rows":[{period, division:{id,title,code}, ..., amount}]}
 *
 * Правила (согласованы):
 *  - ID от ИИ принимаем без проверки (Q3=b); дозаполняем из справочника только отсутствующие title/code;
 *  - текстового маппинга на справочники нет; без ID → см. Config::KEEP_TEXT_WITHOUT_ID;
 *  - число JSON (int) в ref-ячейке = ID; строка = текст (даже "251277");
 *  - полностью пустые строки выкидываем, неполные сохраняем как есть.
 */
final class Normalizer
{
	/**
	 * @param mixed $input JSON-строка | массив
	 * @param bool $enrich дозаполнять title/code по ID из БД
	 * @param bool $forcePreset колонки берутся из $presetFallback, даже если во входе другой preset
	 */
	public static function normalize($input, ?string $presetFallback = null, bool $enrich = true, bool $forcePreset = false): array
	{
		$data = self::decode($input);

		$preset = null;
		$rows = [];
		if (is_array($data))
		{
			if (array_key_exists('rows', $data) || array_key_exists('preset', $data))
			{
				$preset = is_string($data['preset'] ?? null) ? $data['preset'] : null;
				$rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
			}
			elseif (array_is_list($data))
			{
				$rows = $data;
			}
		}

		if ($forcePreset && Config::isPreset($presetFallback))
		{
			$preset = $presetFallback; // тип поля задаёт колонки жёстко
		}
		elseif (!Config::isPreset($preset))
		{
			$preset = Config::isPreset($presetFallback) ? $presetFallback : Config::DEFAULT_PRESET;
		}
		$columns = Config::presetColumns($preset);

		$out = [];
		foreach ($rows as $row)
		{
			if (!is_array($row))
			{
				continue;
			}
			$norm = self::row($row, $columns);
			if (!self::isEmptyRow($norm))
			{
				$out[] = $norm;
			}
		}

		if ($enrich && $out)
		{
			$out = self::enrich($out, $columns);
		}

		if (!Config::KEEP_TEXT_WITHOUT_ID)
		{
			$out = self::dropTextWithoutId($out, $columns);
			$out = array_values(array_filter($out, fn($r) => !self::isEmptyRow($r)));
		}

		return [
			'v' => Config::FORMAT_VERSION,
			'preset' => $preset,
			'rows' => $out,
		];
	}

	/** Хранимое значение → массив, без запросов в БД (для отображения) */
	public static function fromStored($stored, ?string $presetFallback = null): array
	{
		$data = self::decode($stored);
		if (is_array($data) && (int)($data['v'] ?? 0) === Config::FORMAT_VERSION && is_array($data['rows'] ?? null))
		{
			if (!Config::isPreset($data['preset'] ?? null))
			{
				$data['preset'] = Config::isPreset($presetFallback) ? $presetFallback : Config::DEFAULT_PRESET;
			}
			return $data;
		}
		// старый/чужой формат — нормализуем без БД
		return self::normalize($stored, $presetFallback, false);
	}

	public static function toJson(array $data): string
	{
		if (empty($data['rows']))
		{
			return '';
		}
		return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/** JSON-строка (в т.ч. в ```json-обёртке от ИИ) → массив */
	public static function decode($input)
	{
		if (is_array($input))
		{
			return $input;
		}
		if (!is_string($input))
		{
			return null;
		}
		$s = trim($input);
		if ($s === '')
		{
			return null;
		}
		$s = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $s);
		$data = json_decode($s, true);
		return json_last_error() === JSON_ERROR_NONE ? $data : null;
	}

	private static function row(array $row, array $columns): array
	{
		// позиционная строка (вставка из Excel) → по порядку колонок пресета
		if (array_is_list($row))
		{
			$row = array_combine(
				array_slice($columns, 0, min(count($columns), count($row))),
				array_slice($row, 0, min(count($columns), count($row)))
			) ?: [];
		}
		else
		{
			$row = self::mapKeys($row);
		}

		$defs = Config::columns();
		$out = [];
		foreach ($columns as $key)
		{
			$raw = $row[$key] ?? null;
			$out[$key] = match ($defs[$key]['type'])
			{
				'period'   => Period::normalize($raw),
				'ref'      => self::refCell($raw),
				'employee' => self::employeeCell($raw),
				'amount'   => Money::normalize($raw),
			};
		}
		return $out;
	}

	private static function mapKeys(array $row): array
	{
		$aliases = Config::keyAliases();
		$out = [];
		foreach ($row as $k => $v)
		{
			$lk = mb_strtolower(trim((string)$k));
			$out[$aliases[$lk] ?? $lk] = $v;
		}
		return $out;
	}

	private static function refCell($raw): ?array
	{
		if ($raw === null || $raw === '' || $raw === [])
		{
			return null;
		}

		if (is_int($raw))
		{
			return $raw > 0 ? ['id' => $raw, 'title' => '', 'code' => null] : null;
		}

		if (is_array($raw))
		{
			$id = self::intOrNull($raw['id'] ?? $raw['ID'] ?? null);
			$title = self::str($raw['title'] ?? $raw['name'] ?? $raw['TITLE'] ?? '');
			$code = self::str($raw['code'] ?? $raw['CODE'] ?? '');
			if ($id === null && $title === '')
			{
				return null;
			}
			return ['id' => $id, 'title' => $title, 'code' => $code === '' ? null : $code];
		}

		$title = self::str($raw);
		return $title === '' ? null : ['id' => null, 'title' => $title, 'code' => null];
	}

	private static function employeeCell($raw): ?array
	{
		if ($raw === null || $raw === '' || $raw === [])
		{
			return null;
		}
		if (is_int($raw))
		{
			return $raw > 0 ? ['id' => $raw, 'title' => ''] : null;
		}
		if (is_array($raw))
		{
			$id = self::intOrNull($raw['id'] ?? $raw['ID'] ?? null);
			$title = self::str($raw['title'] ?? $raw['name'] ?? '');
			return ($id === null && $title === '') ? null : ['id' => $id, 'title' => $title];
		}
		$title = self::str($raw);
		return $title === '' ? null : ['id' => null, 'title' => $title];
	}

	/** Дозаполнить пустые title/code по ID. Присланное не перезаписываем. */
	private static function enrich(array $rows, array $columns): array
	{
		$refKeys = array_values(array_filter($columns, fn($c) => Config::columns()[$c]['type'] === 'ref'));

		foreach ($refKeys as $key)
		{
			$need = [];
			foreach ($rows as $r)
			{
				$cell = $r[$key];
				if ($cell && $cell['id'] && ($cell['title'] === '' || $cell['code'] === null))
				{
					$need[] = $cell['id'];
				}
			}
			if (!$need)
			{
				continue;
			}
			$found = Lookup::refs($key, $need);
			foreach ($rows as $i => $r)
			{
				$cell = $r[$key];
				if ($cell && $cell['id'] && isset($found[$cell['id']]))
				{
					if ($cell['title'] === '')
					{
						$rows[$i][$key]['title'] = $found[$cell['id']]['title'];
					}
					if ($cell['code'] === null)
					{
						$rows[$i][$key]['code'] = $found[$cell['id']]['code'];
					}
				}
			}
		}

		if (in_array('employee', $columns, true))
		{
			$need = [];
			foreach ($rows as $r)
			{
				if ($r['employee'] && $r['employee']['id'] && $r['employee']['title'] === '')
				{
					$need[] = $r['employee']['id'];
				}
			}
			if ($need)
			{
				$found = Lookup::users($need);
				foreach ($rows as $i => $r)
				{
					$e = $r['employee'];
					if ($e && $e['id'] && $e['title'] === '' && isset($found[$e['id']]))
					{
						$rows[$i]['employee']['title'] = $found[$e['id']];
					}
				}
			}
		}

		return $rows;
	}

	private static function dropTextWithoutId(array $rows, array $columns): array
	{
		foreach ($rows as $i => $r)
		{
			foreach ($columns as $key)
			{
				if (Config::columns()[$key]['type'] === 'ref' && $r[$key] && $r[$key]['id'] === null)
				{
					$rows[$i][$key] = null;
				}
			}
		}
		return $rows;
	}

	private static function isEmptyRow(array $row): bool
	{
		foreach ($row as $v)
		{
			if ($v !== null)
			{
				return false;
			}
		}
		return true;
	}

	private static function intOrNull($v): ?int
	{
		if (is_int($v))
		{
			return $v > 0 ? $v : null;
		}
		if (is_string($v) && ctype_digit(trim($v)))
		{
			$i = (int)trim($v);
			return $i > 0 ? $i : null;
		}
		return null;
	}

	private static function str($v): string
	{
		if (is_array($v) || is_object($v) || $v === null)
		{
			return '';
		}
		return trim(preg_replace('/\s+/u', ' ', (string)$v));
	}
}
