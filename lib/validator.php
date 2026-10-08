<?php

namespace Local\Bptable;

/**
 * Обязательные колонки (Config::columns()[...]['required']).
 * ref — нужен ID из справочника; employee — ФИО; amount — ненулевая сумма.
 */
final class Validator
{
	/** @return list<string> */
	public static function errors(array $data): array
	{
		$rows = $data['rows'] ?? [];
		if (!$rows)
		{
			return ['Таблица распределения пустая: добавьте хотя бы одну строку.'];
		}

		$defs = Config::columns();
		$columns = Config::presetColumns($data['preset'] ?? Config::DEFAULT_PRESET);
		$errors = [];
		foreach ($rows as $i => $row)
		{
			$missing = [];
			foreach ($columns as $c)
			{
				if (empty($defs[$c]['required']))
				{
					continue;
				}
				$v = $row[$c] ?? null;
				$ok = match ($defs[$c]['type'])
				{
					'ref'      => is_array($v) && !empty($v['id']),
					'employee' => is_array($v) && trim((string)($v['title'] ?? '')) !== '',
					'amount'   => $v !== null && Money::toCents($v) !== 0,
					default    => $v !== null,
				};
				if (!$ok)
				{
					$missing[] = $defs[$c]['title'] . ($defs[$c]['type'] === 'ref' && is_array($v) && ($v['title'] ?? '') !== '' ? ' (выберите из справочника)' : '');
				}
			}
			if ($missing)
			{
				$errors[] = 'Строка ' . ($i + 1) . ': ' . implode(', ', $missing);
			}
		}
		return $errors;
	}
}
