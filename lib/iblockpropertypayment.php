<?php

namespace Local\Bptable;

final class IblockPropertyPayment extends IblockProperty
{
	public const USER_TYPE = 'LocalBpTablePayment';
	public const PRESET = 'payment';

	protected static function description(): string
	{
		return 'Таблица распределения: оплата (без сотрудника)';
	}
}
