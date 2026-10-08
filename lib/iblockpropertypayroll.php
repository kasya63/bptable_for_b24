<?php

namespace Local\Bptable;

final class IblockPropertyPayroll extends IblockProperty
{
	public const USER_TYPE = 'LocalBpTablePayroll';
	public const PRESET = 'payroll';

	protected static function description(): string
	{
		return 'Таблица распределения: ЗП (с сотрудником)';
	}
}
