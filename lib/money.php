<?php

namespace Local\Bptable;

/**
 * Суммы храним строкой "150000.00", считаем в тиынах (int) — без float.
 */
final class Money
{
	/** "150 000,00" | 150000 | 150000.5 | "150000" → "150000.00"; мусор → null */
	public static function normalize($raw): ?string
	{
		if ($raw === null || $raw === '' || is_bool($raw) || is_array($raw))
		{
			return null;
		}

		if (is_int($raw))
		{
			return $raw . '.00';
		}

		if (is_float($raw))
		{
			return number_format($raw, 2, '.', '');
		}

		$s = (string)$raw;
		// пробелы, nbsp, узкий nbsp, апострофы-разделители, валюта
		$s = str_replace(["\xC2\xA0", "\xE2\x80\xAF", ' ', "'", '₸', 'KZT', 'тг', 'тенге'], '', $s);
		$s = trim($s);

		// если есть и запятая и точка — последний символ из них считаем десятичным
		$lastComma = strrpos($s, ',');
		$lastDot = strrpos($s, '.');
		if ($lastComma !== false && $lastDot !== false)
		{
			if ($lastComma > $lastDot)
			{
				$s = str_replace('.', '', $s);
				$s = str_replace(',', '.', $s);
			}
			else
			{
				$s = str_replace(',', '', $s);
			}
		}
		elseif ($lastComma !== false || $lastDot !== false)
		{
			// один вид разделителя: ровно 3 цифры после каждого — это тысячи («12,300» = 12 300)
			$sep = $lastComma !== false ? ',' : '.';
			$s = preg_match('/^-?\d{1,3}(' . preg_quote($sep, '/') . '\d{3})+$/', $s)
				? str_replace($sep, '', $s)
				: str_replace(',', '.', $s);
		}

		if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $s, $m))
		{
			return null;
		}

		return self::fromCents(self::partsToCents($m[1] === '-', $m[2], $m[3] ?? ''));
	}

	public static function toCents(?string $normalized): int
	{
		if ($normalized === null || !preg_match('/^(-?)(\d+)\.(\d{2})$/', $normalized, $m))
		{
			return 0;
		}
		return self::partsToCents($m[1] === '-', $m[2], $m[3]);
	}

	public static function fromCents(int $cents): string
	{
		$neg = $cents < 0;
		$abs = abs($cents);
		return ($neg ? '-' : '') . intdiv($abs, 100) . '.' . str_pad((string)($abs % 100), 2, '0', STR_PAD_LEFT);
	}

	/** "4550000.00" → "4 550 000,00" (nbsp) */
	public static function format(?string $normalized): string
	{
		if ($normalized === null)
		{
			return '';
		}
		[$int, $frac] = explode('.', $normalized) + [1 => '00'];
		$neg = str_starts_with($int, '-');
		$int = ltrim($int, '-');
		$int = preg_replace('/\B(?=(\d{3})+(?!\d))/', "\u{00A0}", $int);
		return ($neg ? '-' : '') . $int . ',' . $frac;
	}

	private static function partsToCents(bool $neg, string $int, string $frac): int
	{
		$frac = str_pad($frac, 3, '0');
		$cents = (int)$int * 100 + (int)substr($frac, 0, 2);
		if ((int)$frac[2] >= 5)
		{
			$cents++; // half-up
		}
		return $neg ? -$cents : $cents;
	}
}
