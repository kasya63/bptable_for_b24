<?php
/**
 * php -f /home/bitrix/www/local/modules/local.bptable/tools/selftest.php
 * php -f .../selftest.php -- --project=341 --expense=55 --user=1
 * php -f .../selftest.php -- --write=ELEMENT_ID --prop=PROPERTY_CODE --iblock=32
 */
if (PHP_SAPI !== 'cli')
{
	die('CLI only');
}

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../..');
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Local\Bptable\{Money, Period, Normalizer, Renderer};

Loader::requireModule('local.bptable');

$opt = getopt('', ['project::', 'expense::', 'division::', 'org::', 'user::', 'write::', 'prop::', 'iblock::', 'diag-org::']);
$fails = 0;
$eq = function ($label, $got, $exp) use (&$fails) {
	$ok = $got === $exp;
	$fails += $ok ? 0 : 1;
	echo ($ok ? '  OK   ' : '  FAIL ') . $label . ($ok ? '' : ' → ' . var_export($got, true) . ' ≠ ' . var_export($exp, true)) . "\n";
};

echo "== Money\n";
$eq('150000', Money::normalize(150000), '150000.00');
$eq('"150 000,00"', Money::normalize('150 000,00'), '150000.00');
$eq('"2 000 000.5"', Money::normalize('2 000 000.5'), '2000000.50');
$eq('"1.234.567,891"', Money::normalize('1.234.567,891'), '1234567.89');
$eq('"1,234,567.895"', Money::normalize('1,234,567.895'), '1234567.90');
$eq('"abc"', Money::normalize('abc'), null);
$eq('"12,300" (тысячи)', Money::normalize('12,300'), '12300.00');
$eq('"₸61,500"', Money::normalize('₸61,500'), '61500.00');
$eq('"1,234,567"', Money::normalize('1,234,567'), '1234567.00');
$eq('"12.300"', Money::normalize('12.300'), '12300.00');
$eq('"12,30"', Money::normalize('12,30'), '12.30');
$eq('"1,5"', Money::normalize('1,5'), '1.50');
$eq('format', Money::format('4550000.00'), "4\xC2\xA0550\xC2\xA0000,00");

echo "== Period\n";
$eq('30.09.2026 23:59:59', Period::normalize('30.09.2026 23:59:59'), '2026-09-30T23:59:59+05:00');
$eq('30.09.2026', Period::normalize('30.09.2026'), '2026-09-30T23:59:59+05:00');
$eq('2026-09-30T10:00:00', Period::normalize('2026-09-30T10:00:00'), '2026-09-30T23:59:59+05:00');
$eq('31.02.2026', Period::normalize('31.02.2026'), null);
$eq('excel 46295', Period::normalize('46295'), '2026-09-30T23:59:59+05:00');

echo "== Normalizer (ответ ИИ: проект, статья, сотрудник, сумма)\n";
$aiJson = '```json
{"rows":[
 {"period":"30.09.2026","division":"Aimap","project":{"id":458,"title":"Digital Kazdream"},"expense":{"id":81,"title":"ПО"},"employee":"Мади Нурпанов","amount":12300},
 {"Проект":"251265 - SuperVision","Статья":"ПО","Сотрудник":"Кахар Кашимов","Сумма":"₸123,000"},
 {},
 {"employee":"Остаток — 7G","amount":"61,500"}
]}
```';
$d = Normalizer::normalize($aiJson, null, false);
$eq('preset', $d['preset'], 'payroll');
$eq('колонки', array_keys($d['rows'][0]), ['project', 'expense', 'employee', 'amount']);
$eq('пустая строка выкинута', count($d['rows']), 3);
$eq('лишние колонки (период, дивизион) отброшены', isset($d['rows'][0]['period']) || isset($d['rows'][0]['division']), false);
$eq('ID из ответа', $d['rows'][0]['expense'], ['id' => 81, 'title' => 'ПО', 'code' => null]);
$eq('рус. ключи + «₸123,000»', $d['rows'][1]['amount'], '123000.00');
$eq('«61,500» — тысячи', $d['rows'][2]['amount'], '61500.00');
$eq('сводка', Renderer::summaryText($d), "3 строки · 196\xC2\xA0800,00 ₸");

echo "== Обязательные: статья (из справочника), сотрудник, сумма\n";
$errs = \Local\Bptable\Validator::errors($d);
$eq('строка 1 полная', preg_grep('/^Строка 1:/', $errs), []);
$eq('строка 2: статья текстом', (bool)preg_grep('/^Строка 2: Статья \(выберите из справочника\)$/u', $errs), true);
$eq('строка 3: нет статьи', (bool)preg_grep('/^Строка 3: Статья$/u', $errs), true);
$eq('пустая таблица — ошибка', \Local\Bptable\Validator::errors(['rows' => []]) !== [], true);
$eq('нулевая сумма — ошибка', (bool)\Local\Bptable\Validator::errors(Normalizer::normalize(['rows' => [['expense' => ['id' => 1, 'title' => 'x'], 'employee' => 'A', 'amount' => 0]]], null, false)), true);

echo "== Старые данные (с периодом/дивизионом, preset payment) читаются\n";
$old = '{"v":1,"preset":"payment","rows":[{"period":"2026-09-30T23:59:59+05:00","division":{"id":8,"title":"AiMap","code":"x"},"project":{"id":327,"title":"P","code":null},"expense":{"id":25,"title":"E","code":null},"amount":"100.00"}]}';
$o = Normalizer::fromStored($old);
$eq('preset → payroll', $o['preset'], 'payroll');
$eq('рендер не падает', Renderer::summaryText($o), "1 строка · 100,00 ₸");

echo "== Идемпотентность\n";
$j1 = Normalizer::toJson(Normalizer::normalize($aiJson, null, false));
$j2 = Normalizer::toJson(Normalizer::normalize($j1, null, false));
$eq('normalize(normalize(x)) == normalize(x)', $j2, $j1);

$ids = array_filter([
	'project' => (int)($opt['project'] ?? 0), 'expense' => (int)($opt['expense'] ?? 0),
]);
if ($ids || !empty($opt['user']))
{
	echo "== Дозаполнение по ID из БД\n";
	$row = ['amount' => 1];
	foreach ($ids as $k => $id)
	{
		$row[$k] = $id; // JSON-число = ID
	}
	if (!empty($opt['user']))
	{
		$row['employee'] = (int)$opt['user'];
	}
	$d = Normalizer::normalize(['preset' => 'payroll', 'rows' => [$row]]);
	echo json_encode($d['rows'][0], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

if (!empty($opt['write']) && !empty($opt['prop']))
{
	echo "== Запись в элемент {$opt['write']}, свойство {$opt['prop']}\n";
	Loader::requireModule('iblock');
	$full = Normalizer::normalize($aiJson);
	CIBlockElement::SetPropertyValuesEx((int)$opt['write'], (int)($opt['iblock'] ?? 32), [$opt['prop'] => Normalizer::toJson($full)]);
	$res = CIBlockElement::GetProperty((int)($opt['iblock'] ?? 32), (int)$opt['write'], [], ['CODE' => $opt['prop']])->Fetch();
	echo 'В БД: ' . mb_substr((string)$res['VALUE'], 0, 200) . "...\n";
}

if (!empty($opt['diag-org']))
{
	$cid = (int)$opt['diag-org'];
	echo "== Диагностика организации {$cid}\n";
	Loader::requireModule('crm');
	$c = \Bitrix\Crm\CompanyTable::getList(['select' => ['ID', 'TITLE', 'IS_MY_COMPANY'], 'filter' => ['=ID' => $cid]])->fetch();
	echo 'Компания: ' . ($c ? json_encode($c, JSON_UNESCAPED_UNICODE) : 'НЕ НАЙДЕНА') . "\n";
	$conn = \Bitrix\Main\Application::getConnection();
	foreach ($conn->query("SELECT ID, ENTITY_ID, FIELD_NAME, USER_TYPE_ID FROM b_user_field WHERE FIELD_NAME = 'UF_ORGANIZATION_ID_API_1C'") as $r)
	{
		echo '  b_user_field: ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
	}
	echo '  Сущность UF (модуль): ' . var_export(\Local\Bptable\Lookup::ufEntity('UF_ORGANIZATION_ID_API_1C'), true) . "\n";
	$n = 0;
	foreach ($conn->query("SELECT ID, ENTITY_TYPE_ID, ENTITY_ID, NAME, PRESET_ID FROM b_crm_requisite WHERE ENTITY_ID = {$cid}") as $r)
	{
		$n++;
		global $USER_FIELD_MANAGER;
		$v = $USER_FIELD_MANAGER->GetUserFieldValue('CRM_REQUISITE', 'UF_ORGANIZATION_ID_API_1C', (int)$r['ID']);
		echo '  b_crm_requisite: ' . json_encode($r, JSON_UNESCAPED_UNICODE) . ' → UF = ' . var_export($v, true) . "\n";
	}
	echo "  реквизитов с ENTITY_ID={$cid} (любой тип): {$n}\n";
	echo 'Lookup: ' . json_encode(\Local\Bptable\Lookup::refs('organization', [$cid]), JSON_UNESCAPED_UNICODE) . "\n";
	echo 'Поиск «»: ' . json_encode(array_slice(\Local\Bptable\Search::find('organization', ''), 0, 5), JSON_UNESCAPED_UNICODE) . "\n";
}

echo $fails ? "\nFAILED: {$fails}\n" : "\nALL OK\n";
