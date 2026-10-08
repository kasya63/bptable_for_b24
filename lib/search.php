<?php

namespace Local\Bptable;

use Bitrix\Main\Loader;

/**
 * Поиск для автокомплита. Без проверки прав CRM (Q8=a): отдаём только id/title/code.
 */
final class Search
{
	public const LIMIT = 20;

	/** @return array<int, array{id:int, title:string, code:?string}> */
	public static function find(string $ref, string $q): array
	{
		$q = trim(preg_replace('/\s+/u', ' ', $q));
		if (mb_strlen($q) > 100)
		{
			$q = mb_substr($q, 0, 100);
		}

		try
		{
			if ($ref === 'employee')
			{
				return self::users($q);
			}

			$cfg = Config::refs()[$ref] ?? null;
			if ($cfg === null)
			{
				return [];
			}

			return match ($cfg['kind'])
			{
				'crm'     => self::crm($cfg, $q),
				'hl'      => self::hl($cfg, $q),
				'company' => self::company($ref, $q),
				default   => [],
			};
		}
		catch (\Throwable $e)
		{
			Lookup::log("search {$ref}: " . $e->getMessage());
			return [];
		}
	}

	private static function crm(array $cfg, string $q): array
	{
		Loader::requireModule('crm');
		$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory((int)$cfg['entity_type_id']);
		if (!$factory)
		{
			return [];
		}
		$filter = $q !== '' ? ['%TITLE' => $q] : [];
		$items = $factory->getItems([
			'select' => ['ID', 'TITLE', $cfg['code_field']],
			'filter' => $filter,
			'order'  => ['TITLE' => 'ASC'],
			'limit'  => self::LIMIT,
		]);

		$out = [];
		foreach ($items as $item)
		{
			$code = $item->get($cfg['code_field']);
			$out[] = [
				'id'    => (int)$item->getId(),
				'title' => (string)$item->getTitle(),
				'code'  => ($code === null || $code === '') ? null : (string)$code,
			];
		}
		return $out;
	}

	private static function hl(array $cfg, string $q): array
	{
		Loader::requireModule('highloadblock');
		$hl = \Bitrix\Highloadblock\HighloadBlockTable::getById((int)$cfg['hl_id'])->fetch();
		if (!$hl)
		{
			return [];
		}
		$dataClass = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hl)->getDataClass();

		$filter = [];
		if ($q !== '')
		{
			$filter[] = ['LOGIC' => 'OR', '%' . $cfg['title_field'] => $q, '%' . $cfg['code_field'] => $q];
		}
		$rows = $dataClass::getList([
			'select' => ['ID', $cfg['title_field'], $cfg['code_field']],
			'filter' => $filter,
			'order'  => [$cfg['title_field'] => 'ASC'],
			'limit'  => self::LIMIT,
		]);

		$out = [];
		while ($row = $rows->fetch())
		{
			$code = $row[$cfg['code_field']] ?? null;
			$out[] = [
				'id'    => (int)$row['ID'],
				'title' => (string)$row[$cfg['title_field']],
				'code'  => ($code === null || $code === '') ? null : (string)$code,
			];
		}
		return $out;
	}

	private static function company(string $ref, string $q): array
	{
		Loader::requireModule('crm');
		$filter = ['=IS_MY_COMPANY' => 'Y'];
		if ($q !== '')
		{
			$filter['%TITLE'] = $q;
		}
		$rows = \Bitrix\Crm\CompanyTable::getList([
			'select' => ['ID'],
			'filter' => $filter,
			'order'  => ['TITLE' => 'ASC'],
			'limit'  => self::LIMIT,
		]);
		$ids = [];
		while ($row = $rows->fetch())
		{
			$ids[] = (int)$row['ID'];
		}

		$found = Lookup::refs($ref, $ids); // title + code с реквизита
		$out = [];
		foreach ($ids as $id)
		{
			if (isset($found[$id]))
			{
				$out[] = ['id' => $id, 'title' => $found[$id]['title'], 'code' => $found[$id]['code']];
			}
		}
		return $out;
	}

	private static function users(string $q): array
	{
		$filter = [
			'=ACTIVE' => 'Y',
			['LOGIC' => 'OR', '=EXTERNAL_AUTH_ID' => null, '!@EXTERNAL_AUTH_ID' => \Bitrix\Main\UserTable::getExternalUserTypes()],
		];
		foreach (preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) as $word)
		{
			$filter[] = ['LOGIC' => 'OR', '%LAST_NAME' => $word, '%NAME' => $word, '%SECOND_NAME' => $word];
		}

		$format = \CSite::GetNameFormat(false);
		$rows = \Bitrix\Main\UserTable::getList([
			'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'WORK_POSITION'],
			'filter' => $filter,
			'order'  => ['LAST_NAME' => 'ASC', 'NAME' => 'ASC'],
			'limit'  => self::LIMIT,
		]);

		$out = [];
		while ($row = $rows->fetch())
		{
			$out[] = [
				'id'    => (int)$row['ID'],
				'title' => \CUser::FormatName($format, $row, true, false),
				'code'  => null,
				'hint'  => (string)$row['WORK_POSITION'],
			];
		}
		return $out;
	}
}
