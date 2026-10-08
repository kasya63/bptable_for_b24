<?php

namespace Local\Bptable;

final class Stats
{
	/** @return array{rows:int, totalCents:int, total:float, unresolved:int} */
	public static function of(array $data): array
	{
		$rows = $data['rows'] ?? [];
		$columns = Config::presetColumns($data['preset'] ?? Config::DEFAULT_PRESET);
		$defs = Config::columns();

		$cents = 0;
		$unresolved = 0;
		foreach ($rows as $r)
		{
			$cents += Money::toCents($r['amount'] ?? null);
			foreach ($columns as $c)
			{
				if ($defs[$c]['type'] === 'ref' && !empty($r[$c]) && empty($r[$c]['id']))
				{
					$unresolved++;
				}
			}
		}

		return [
			'rows'       => count($rows),
			'totalCents' => $cents,
			'total'      => $cents / 100,
			'unresolved' => $unresolved,
		];
	}
}
