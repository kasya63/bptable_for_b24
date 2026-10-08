<?php

namespace Local\Bptable\Controller;

use Bitrix\Main\Engine\Controller;

/**
 * BX.ajax.runAction('local:bptable.search.find', {data: {ref: 'project', q: '2512'}})
 * Префильтры по умолчанию: авторизация + CSRF (sessid).
 */
class Search extends Controller
{
	public function findAction(string $ref, string $q = ''): array
	{
		return ['items' => \Local\Bptable\Search::find($ref, $q)];
	}
}
