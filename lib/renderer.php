<?php

namespace Local\Bptable;

/**
 * HTML-отображение (только просмотр). Без JS: раскрытие через <details>.
 */
final class Renderer
{
	private static bool $cssPrinted = false;

	public static function summaryText(array $data): string
	{
		$rows = $data['rows'] ?? [];
		$n = count($rows);
		if ($n === 0)
		{
			return '';
		}
		return $n . ' ' . self::plural($n, 'строка', 'строки', 'строк')
			. ' · ' . Money::format(Money::fromCents(self::totalCents($rows))) . ' ₸';
	}

	/** Текст для уведомлений/печатного значения в БП: сводка + строка на позицию */
	public static function plainText(array $data): string
	{
		$rows = $data['rows'] ?? [];
		if (!$rows)
		{
			return '';
		}
		$columns = Config::presetColumns($data['preset'] ?? Config::DEFAULT_PRESET);
		$defs = Config::columns();
		$lines = [self::summaryText($data)];
		foreach ($rows as $i => $row)
		{
			$parts = [];
			foreach ($columns as $c)
			{
				$v = $row[$c] ?? null;
				$parts[] = match ($defs[$c]['type'])
				{
					'period' => Period::format($v),
					'amount' => Money::format($v),
					default  => $v ? ($v['title'] !== '' ? $v['title'] : '#' . $v['id']) : '',
				};
			}
			$lines[] = ($i + 1) . '. ' . implode(' | ', $parts);
		}
		return implode("\n", $lines);
	}

	/**
	 * @param bool $open развернуть таблицу сразу (карточка) или показать сводку (грид)
	 */
	public static function view(array $data, bool $open = false): string
	{
		$rows = $data['rows'] ?? [];
		if (!$rows)
		{
			return '<span class="lbpt-empty">—</span>';
		}

		$html = self::css();
		$html .= '<details class="lbpt"' . ($open ? ' open' : '') . '>';
		$html .= '<summary class="lbpt__sum">' . self::e(self::summaryText($data)) . '</summary>';
		$html .= '<div class="lbpt__wrap">' . self::table($data) . '</div>';
		$html .= '</details>';
		return $html;
	}

	public static function table(array $data): string
	{
		$columns = Config::presetColumns($data['preset'] ?? Config::DEFAULT_PRESET);
		$defs = Config::columns();
		$refs = Config::refs();

		$h = '<table class="lbpt__t"><thead><tr>';
		foreach ($columns as $c)
		{
			$h .= '<th' . ($c === 'amount' ? ' class="lbpt__r"' : '') . '>' . self::e($defs[$c]['title']) . '</th>';
		}
		$h .= '</tr></thead><tbody>';

		foreach ($data['rows'] as $row)
		{
			$h .= '<tr>';
			foreach ($columns as $c)
			{
				$v = $row[$c] ?? null;
				switch ($defs[$c]['type'])
				{
					case 'period':
						$h .= '<td>' . self::e(Period::format($v)) . '</td>';
						break;
					case 'amount':
						$h .= '<td class="lbpt__r">' . self::e(Money::format($v)) . '</td>';
						break;
					case 'employee':
						$h .= self::cell($v, Config::userUrl(), false);
						break;
					case 'ref':
						$url = $c === 'organization' ? Config::companyUrl() : ($refs[$c]['url'] ?? null);
						$h .= self::cell($v, $url, true);
						break;
				}
			}
			$h .= '</tr>';
		}

		$h .= '</tbody><tfoot><tr><td colspan="' . (count($columns) - 1) . '">Итого</td>'
			. '<td class="lbpt__r">' . self::e(Money::format(Money::fromCents(self::totalCents($data['rows'])))) . '</td>'
			. '</tr></tfoot></table>';

		return $h;
	}

	private static function cell(?array $v, ?string $url, bool $markMissing): string
	{
		if (!$v)
		{
			return '<td></td>';
		}
		$title = $v['title'] !== '' ? $v['title'] : ('#' . $v['id']);
		$hint = isset($v['code']) && $v['code'] !== null ? ' title="' . self::e('Код: ' . $v['code']) . '"' : '';

		if ($v['id'])
		{
			$inner = $url
				? '<a href="' . self::e(str_replace('#ID#', (string)$v['id'], $url)) . '" target="_blank">' . self::e($title) . '</a>'
				: self::e($title);
			return '<td' . $hint . '>' . $inner . '</td>';
		}

		return '<td' . ($markMissing ? ' class="lbpt__miss" title="Нет ID — сохранён только текст"' : '') . '>'
			. self::e($title) . '</td>';
	}

	private static function totalCents(array $rows): int
	{
		$sum = 0;
		foreach ($rows as $r)
		{
			$sum += Money::toCents($r['amount'] ?? null);
		}
		return $sum;
	}

	private static function plural(int $n, string $one, string $few, string $many): string
	{
		$n = abs($n) % 100;
		$n1 = $n % 10;
		if ($n > 10 && $n < 20) return $many;
		if ($n1 > 1 && $n1 < 5) return $few;
		if ($n1 === 1) return $one;
		return $many;
	}

	private static function e(string $s): string
	{
		return htmlspecialcharsbx($s);
	}

	private static function css(): string
	{
		if (self::$cssPrinted)
		{
			return '';
		}
		self::$cssPrinted = true;
		return '<style>
.lbpt__sum{cursor:pointer;display:inline-block;padding:2px 8px;border-radius:4px;background:#e5f4fd;color:#1e70a7;font-size:12px;white-space:nowrap}
.lbpt[open]>.lbpt__sum{margin-bottom:6px}
.lbpt__wrap{overflow-x:auto;max-width:100%}
.lbpt__t{border-collapse:collapse;font-size:12px;min-width:640px}
.lbpt__t th{font-weight:normal;color:#828b95;text-align:left;padding:5px 8px;border-bottom:1px solid #dfe0e3;white-space:nowrap}
.lbpt__t td{padding:5px 8px;border-bottom:1px solid #edeef0;vertical-align:top}
.lbpt__t tfoot td{font-weight:bold;border-bottom:none}
.lbpt__r{text-align:right!important;white-space:nowrap}
.lbpt__miss{background:#fff5cc;color:#7a5b00}
</style>';
	}
}
