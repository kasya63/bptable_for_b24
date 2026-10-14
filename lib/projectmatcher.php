<?php

namespace Local\Bptable;

/**
 * Проект строки по сотруднику (без ИИ).
 *
 * 1) сотрудник строки ищется в «Пользователи в проектах»
 *    [{"project":"Signature","employees":["Зуб Александр Александрович", ...]}, ...]
 *    — по фамилии и имени в любом порядке, отчество не нужно, казахские буквы = русские;
 * 2) название проекта → ID смарта 140: NAME FIN HUB, затем TITLE без кода («241188 - Signature»);
 *    из нескольких — активный, затем с кодом в TITLE; иначе неоднозначно;
 * 3) не нашли сотрудника / проект неоднозначен → общий проект заявки (article_project_id).
 */
final class ProjectMatcher
{
	/** @var list<array{id:int, title:string, name:string, code:?string, active:bool}> */
	private array $catalog;
	/** @var array<string, list<string>> нормализованное ФИО → проекты */
	private array $people = [];

	public array $stats = ['matched' => 0, 'default' => 0, 'ambiguous' => [], 'notFound' => []];

	public function __construct(array $usersInProjects, array $catalog)
	{
		$this->catalog = $catalog;
		foreach ($usersInProjects as $p)
		{
			if (!is_array($p))
			{
				continue;
			}
			$project = trim((string)($p['project'] ?? ''));
			if ($project === '')
			{
				continue;
			}
			foreach ((array)($p['employees'] ?? []) as $fio)
			{
				$key = self::norm((string)$fio);
				if ($key !== '')
				{
					$this->people[$key][] = $project;
				}
			}
		}
	}

	/** «Пользователи в проектах»: JSON-строка или массив */
	public static function parseUsers($raw): array
	{
		$data = Normalizer::decode($raw);
		if (is_array($data) && isset($data['projects']) && is_array($data['projects']))
		{
			$data = $data['projects'];
		}
		return is_array($data) && array_is_list($data) ? $data : [];
	}

	/** «458» / 458 / [458] / "[458]" / {id:458} → 458 */
	public static function parseProjectId($raw): ?int
	{
		if (is_array($raw))
		{
			$raw = $raw['id'] ?? reset($raw);
		}
		if (is_int($raw))
		{
			return $raw > 0 ? $raw : null;
		}
		return preg_match('/\d+/', (string)$raw, $m) && (int)$m[0] > 0 ? (int)$m[0] : null;
	}

	/**
	 * Проставить project во всех строках.
	 * @param array $data формат v1 (Normalizer)
	 */
	public function apply(array $data, ?int $defaultProjectId): array
	{
		foreach ($data['rows'] as $i => $row)
		{
			$fio = is_array($row['employee'] ?? null) ? (string)($row['employee']['title'] ?? '') : '';
			$projectId = null;

			$names = $fio !== '' ? $this->projectsOf($fio) : [];
			if (count($names) > 1)
			{
				$this->stats['ambiguous'][] = $fio . ' (сотрудник в проектах: ' . implode(', ', $names) . ')';
			}
			elseif (count($names) === 1)
			{
				$projectId = $this->projectId($names[0]);
				if ($projectId === null)
				{
					$this->stats['ambiguous'][] = $fio . ' (проект «' . $names[0] . '» не найден однозначно в справочнике)';
				}
			}
			elseif ($fio !== '')
			{
				$this->stats['notFound'][] = $fio;
			}

			if ($projectId !== null)
			{
				$data['rows'][$i]['project'] = ['id' => $projectId, 'title' => '', 'code' => null];
				$this->stats['matched']++;
			}
			elseif ($defaultProjectId !== null)
			{
				$data['rows'][$i]['project'] = ['id' => $defaultProjectId, 'title' => '', 'code' => null];
				$this->stats['default']++;
			}
			// нет общего проекта — оставляем то, что прислал ИИ
		}
		return $data;
	}

	/** Названия проектов, где есть этот человек (фамилия и имя совпали, порядок любой) */
	private function projectsOf(string $fio): array
	{
		$want = array_values(array_filter(explode(' ', self::norm($fio))));
		if (count($want) < 2)
		{
			return [];
		}
		$found = [];
		foreach ($this->people as $key => $projects)
		{
			$have = explode(' ', $key);
			if (!array_diff($want, $have))
			{
				foreach ($projects as $p)
				{
					$found[$p] = true;
				}
			}
		}
		return array_keys($found);
	}

	private function projectId(string $name): ?int
	{
		$n = self::norm($name);

		$c = array_values(array_filter($this->catalog, fn($p) => self::norm($p['name']) === $n));
		if (!$c)
		{
			$c = array_values(array_filter($this->catalog, fn($p) =>
				self::norm($p['title']) === $n || self::norm(self::stripCode($p['title'])) === $n));
		}
		if (count($c) > 1)
		{
			$active = array_values(array_filter($c, fn($p) => $p['active']));
			$c = $active ?: $c;
		}
		if (count($c) > 1)
		{
			$coded = array_values(array_filter($c, fn($p) => self::stripCode($p['title']) !== $p['title']));
			$c = $coded ?: $c;
		}
		return count($c) === 1 ? $c[0]['id'] : null;
	}

	private static function stripCode(string $title): string
	{
		return preg_replace('/^\s*\d+\s*-\s*/u', '', $title);
	}

	public static function norm(string $s): string
	{
		$s = mb_strtolower(trim($s));
		$s = strtr($s, [
			'ё' => 'е', 'ә' => 'а', 'і' => 'и', 'ң' => 'н', 'ғ' => 'г',
			'ү' => 'у', 'ұ' => 'у', 'қ' => 'к', 'ө' => 'о', 'һ' => 'х', 'й' => 'и',
		]);
		$s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
		return trim(preg_replace('/\s+/u', ' ', $s));
	}
}
