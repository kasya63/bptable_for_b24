<?php

namespace Local\Bptable;

use Bitrix\Main\Loader;

/**
 * Дозаполнение title/code по ID — пачкой, без проверки прав CRM.
 * Возвращает [id => ['title' => string, 'code' => ?string]].
 * ID, которых нет в справочнике, просто отсутствуют в ответе.
 */
final class Lookup
{
	/** @var array<string, array<int, array>> кэш на хит */
	private static array $cache = [];

	public static function refs(string $refKey, array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		if (!$ids)
		{
			return [];
		}

		$cfg = Config::refs()[$refKey] ?? null;
		if ($cfg === null)
		{
			return [];
		}

		$cached = self::$cache[$refKey] ?? [];
		$missing = array_diff($ids, array_keys($cached));
		if ($missing)
		{
			try
			{
				$fetched = match ($cfg['kind'])
				{
					'crm'     => self::crm($cfg, $missing),
					'hl'      => self::hl($cfg, $missing),
					'company' => self::company($cfg, $missing),
					default   => [],
				};
			}
			catch (\Throwable $e)
			{
				self::log("lookup {$refKey}: " . $e->getMessage());
				$fetched = [];
			}
			$cached = $fetched + $cached;
			self::$cache[$refKey] = $cached;
		}

		return array_intersect_key($cached, array_flip($ids));
	}

	/** [userId => 'Фамилия Имя Отчество'] */
	public static function users(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		if (!$ids)
		{
			return [];
		}

		$result = [];
		$format = \CSite::GetNameFormat(false);
		$rows = \Bitrix\Main\UserTable::getList([
			'select' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN'],
			'filter' => ['@ID' => $ids],
		]);
		while ($row = $rows->fetch())
		{
			$result[(int)$row['ID']] = \CUser::FormatName($format, $row, true, false);
		}
		return $result;
	}

	private static function crm(array $cfg, array $ids): array
	{
		Loader::requireModule('crm');
		$factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory((int)$cfg['entity_type_id']);
		if (!$factory)
		{
			return [];
		}

		$result = [];
		// getItems() — без проверки прав (в отличие от getItemsFilteredByPermissions)
		$items = $factory->getItems([
			'select' => ['ID', 'TITLE', $cfg['code_field']],
			'filter' => ['@ID' => $ids],
		]);
		foreach ($items as $item)
		{
			$code = $item->get($cfg['code_field']);
			$result[(int)$item->getId()] = [
				'title' => (string)$item->getTitle(),
				'code'  => ($code === null || $code === '') ? null : (string)$code,
			];
		}
		return $result;
	}

	private static function hl(array $cfg, array $ids): array
	{
		Loader::requireModule('highloadblock');
		$hl = \Bitrix\Highloadblock\HighloadBlockTable::getById((int)$cfg['hl_id'])->fetch();
		if (!$hl)
		{
			return [];
		}
		$dataClass = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hl)->getDataClass();

		$result = [];
		$rows = $dataClass::getList([
			'select' => ['ID', $cfg['title_field'], $cfg['code_field']],
			'filter' => ['@ID' => $ids],
		]);
		while ($row = $rows->fetch())
		{
			$code = $row[$cfg['code_field']] ?? null;
			$result[(int)$row['ID']] = [
				'title' => (string)$row[$cfg['title_field']],
				'code'  => ($code === null || $code === '') ? null : (string)$code,
			];
		}
		return $result;
	}

	/**
	 * Компания: title = TITLE компании,
	 * code = первый непустой UF_ORGANIZATION_ID_API_1C среди её реквизитов (по ID ASC).
	 * Компания и реквизиты читаются независимо: сбой на реквизитах не теряет title.
	 */
	private static function company(array $cfg, array $ids): array
	{
		Loader::requireModule('crm');

		$result = [];
		$rows = \Bitrix\Crm\CompanyTable::getList([
			'select' => ['ID', 'TITLE'],
			'filter' => ['@ID' => $ids],
		]);
		while ($row = $rows->fetch())
		{
			$result[(int)$row['ID']] = ['title' => (string)$row['TITLE'], 'code' => null];
		}
		if (!$result)
		{
			return [];
		}

		try
		{
			$codes = self::requisiteCodes(array_keys($result), $cfg['code_field']);
		}
		catch (\Throwable $e)
		{
			self::log('requisite codes: ' . $e->getMessage());
			$codes = [];
		}

		foreach ($codes as $companyId => $code)
		{
			$result[$companyId]['code'] = $code;
		}
		return $result;
	}

	/**
	 * [companyId => code]. Сущность UF определяется по b_user_field:
	 *  CRM_COMPANY   — поле на компании;
	 *  CRM_REQUISITE — поле на реквизите (первый непустой по ID ASC).
	 */
	private static function requisiteCodes(array $companyIds, string $field): array
	{
		$entity = self::ufEntity($field);
		if ($entity === null)
		{
			self::log("UF {$field} не найден в b_user_field");
			return [];
		}

		global $USER_FIELD_MANAGER;
		$codes = [];

		if ($entity === 'CRM_COMPANY')
		{
			foreach ($companyIds as $cid)
			{
				$v = trim((string)$USER_FIELD_MANAGER->GetUserFieldValue('CRM_COMPANY', $field, $cid));
				if ($v !== '')
				{
					$codes[$cid] = $v;
				}
			}
			return $codes;
		}

		if ($entity !== 'CRM_REQUISITE')
		{
			self::log("UF {$field} принадлежит сущности {$entity} — чтение не поддержано");
			return [];
		}

		$byCompany = [];
		$rq = \Bitrix\Crm\RequisiteTable::getList([
			'select' => ['ID', 'ENTITY_ID'],
			'filter' => ['=ENTITY_TYPE_ID' => \CCrmOwnerType::Company, '@ENTITY_ID' => $companyIds],
			'order'  => ['ENTITY_ID' => 'ASC', 'ID' => 'ASC'],
		]);
		while ($row = $rq->fetch())
		{
			$byCompany[(int)$row['ENTITY_ID']][] = (int)$row['ID'];
		}

		foreach ($byCompany as $companyId => $rqIds)
		{
			foreach ($rqIds as $rqId)
			{
				$code = trim((string)$USER_FIELD_MANAGER->GetUserFieldValue('CRM_REQUISITE', $field, $rqId));
				if ($code === '')
				{
					continue;
				}
				if (!isset($codes[$companyId]))
				{
					$codes[$companyId] = $code;
				}
				elseif ($codes[$companyId] !== $code)
				{
					self::log("company {$companyId}: несколько разных {$field}, взят первый ({$codes[$companyId]})");
				}
			}
		}
		return $codes;
	}

	public static function ufEntity(string $field): ?string
	{
		static $cache = [];
		if (!array_key_exists($field, $cache))
		{
			$row = \Bitrix\Main\UserFieldTable::getList([
				'select' => ['ENTITY_ID'],
				'filter' => ['=FIELD_NAME' => $field],
				'limit'  => 1,
			])->fetch();
			$cache[$field] = $row ? (string)$row['ENTITY_ID'] : null;
		}
		return $cache[$field];
	}

	public static function log(string $message): void
	{
		if (class_exists(\CEventLog::class))
		{
			\CEventLog::Add([
				'SEVERITY' => 'WARNING',
				'AUDIT_TYPE_ID' => 'LOCAL_BPTABLE',
				'MODULE_ID' => 'local.bptable',
				'DESCRIPTION' => $message,
			]);
		}
	}
}
