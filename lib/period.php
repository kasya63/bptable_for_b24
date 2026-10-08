<?php

namespace Local\Bptable;

/**
 * Период: берём только дату, время всегда 23:59:59, TZ Алматы.
 * Хранение: ISO 8601 "2026-09-30T23:59:59+05:00".
 */
final class Period
{
	public static function normalize($raw): ?string
	{
		if ($raw instanceof \Bitrix\Main\Type\Date)
		{
			$raw = $raw->format('Y-m-d');
		}
		elseif ($raw instanceof \DateTimeInterface)
		{
			$raw = $raw->format('Y-m-d');
		}

		if (!is_string($raw) && !is_int($raw))
		{
			return null;
		}

		$s = trim((string)$raw);
		if ($s === '')
		{
			return null;
		}

		$y = $m = $d = null;
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $mm))          // 2026-09-30[...]
		{
			[$y, $m, $d] = [(int)$mm[1], (int)$mm[2], (int)$mm[3]];
		}
		elseif (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})/', $s, $mm)) // 30.09.2026[ 23:59:59]
		{
			[$y, $m, $d] = [(int)$mm[3], (int)$mm[2], (int)$mm[1]];
		}
		elseif (preg_match('/^\d{5}$/', $s))                              // серийная дата Excel
		{
			$base = new \DateTimeImmutable('1899-12-30', new \DateTimeZone(Config::TIMEZONE));
			$dt = $base->modify('+' . (int)$s . ' days');
			[$y, $m, $d] = [(int)$dt->format('Y'), (int)$dt->format('m'), (int)$dt->format('d')];
		}

		if ($y === null || !checkdate($m, $d, $y))
		{
			return null;
		}

		$dt = (new \DateTimeImmutable('now', new \DateTimeZone(Config::TIMEZONE)))
			->setDate($y, $m, $d)
			->setTime(23, 59, 59);

		return $dt->format('Y-m-d\TH:i:sP');
	}

	/** ISO → "30.09.2026" */
	public static function format(?string $iso): string
	{
		if ($iso === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m))
		{
			return '';
		}
		return $m[3] . '.' . $m[2] . '.' . $m[1];
	}
}
