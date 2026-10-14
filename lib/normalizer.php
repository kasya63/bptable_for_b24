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

		$hasEmployees = self::hasEmployees($rows);
		if ($forcePreset && Config::isPreset($presetFallback))
		{
			$preset = $presetFallback; // тип поля задаёт колонки жёстко
			if ($hasEmployees && !in_array('employee', Config::presetColumns($preset), true))
			{
				Lookup::log("Во входных данных есть сотрудники, но тип поля «{$preset}» без колонки «Сотрудник» — они не сохранены. Используйте тип «ЗП».");
			}
		}
		elseif (!Config::isPreset($preset))
		{
			// preset не указан: есть сотрудники — значит ЗП, иначе настройка поля
			$preset = $hasEmployees
				? 'payroll'
				: (Config::isPreset($presetFallback) ? $presetFallback : Config::DEFAULT_PRESET);
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
		// ИИН из ответа ИИ (колонка «iin» в payment_table AI Lab) → внутрь сотрудника, скрыто.
		// Только с верной контрольной цифрой: искажённый моделью номер не сохраняем.
		if (isset($out['employee']) && is_array($out['employee']) && !isset($out['employee']['iin']))
		{
			$iin = self::iin($row['iin'] ?? $row['employee_iin'] ?? $row['иин'] ?? null);
			if ($iin !== null && self::iinValid($iin))
			{
				$out['employee']['iin'] = $iin;
			}
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
			if ($id === null && $title === '')
			{
				return null;
			}
			$cell = ['id' => $id, 'title' => $title];
			$iin = self::iin($raw['iin'] ?? null);
			if ($iin !== null)
			{
				$cell['iin'] = $iin;   // скрытый ИИН — на фронте не показывается
			}
			return $cell;
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
			$ids = [];
			foreach ($rows as $r)
			{
				if ($r['employee'] && $r['employee']['id'])
				{
					$ids[] = $r['employee']['id'];
				}
			}
			if ($ids)
			{
				$users = Lookup::usersWithIin($ids);
				foreach ($rows as $i => $r)
				{
					$e = $r['employee'];
					if (!$e || !$e['id'] || !isset($users[$e['id']]))
					{
						continue;
					}
					if ($e['title'] === '')
					{
						$rows[$i]['employee']['title'] = $users[$e['id']]['name'];
					}
					// выбран пользователь Б24 → ИИН из его карточки (UF_IIN), пусто — убираем
					if ($users[$e['id']]['iin'] !== null)
					{
						$rows[$i]['employee']['iin'] = $users[$e['id']]['iin'];
					}
					else
					{
						unset($rows[$i]['employee']['iin']);
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

	/** Есть ли хоть одна строка с непустым «Сотрудником» (ключ employee / сотрудник) */
	private static function hasEmployees(array $rows): bool
	{
		foreach ($rows as $row)
		{
			if (!is_array($row) || array_is_list($row))
			{
				continue;
			}
			$v = self::mapKeys($row)['employee'] ?? null;
			if ($v !== null && $v !== '' && $v !== [] && !(is_array($v) && empty($v['id']) && trim((string)($v['title'] ?? '')) === ''))
			{
				return true;
			}
		}
		return false;
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

	/** ИИН: только цифры, строкой (ведущий ноль сохраняется) */
	/** Контрольная цифра ИИН РК (12 цифр, два набора весов) */
	public static function iinValid(string $iin): bool
	{
		if (!preg_match('/^\d{12}$/', $iin))
		{
			return false;
		}
		$d = array_map('intval', str_split($iin));
		$sum = 0;
		for ($i = 0; $i < 11; $i++)
		{
			$sum += $d[$i] * ($i + 1);
		}
		$check = $sum % 11;
		if ($check === 10)
		{
			$w = [3, 4, 5, 6, 7, 8, 9, 10, 11, 1, 2];
			$sum = 0;
			for ($i = 0; $i < 11; $i++)
			{
				$sum += $d[$i] * $w[$i];
			}
			$check = $sum % 11;
			if ($check === 10)
			{
				return false;
			}
		}
		return $check === $d[11];
	}

	private static function iin($v): ?string
	{
		if (!is_scalar($v) || is_bool($v))
		{
			return null;
		}
		$s = preg_replace('/\D+/', '', (string)$v);
		return $s === '' ? null : $s;
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
