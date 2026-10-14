<?php
// php -f tools/test_projectmatcher.php — проверка сопоставления сотрудник → проект (без БД)
if (PHP_SAPI !== 'cli') { die('CLI only'); }
$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../..');
define('NO_KEEP_STATISTIC', true); define('NOT_CHECK_PERMISSIONS', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::requireModule('local.bptable');
use Local\Bptable\{ProjectMatcher as PM, Normalizer as N};
$fails=0; $ok=function($c,$l)use(&$fails){echo ($c?'  OK   ':'  FAIL ').$l."\n"; if(!$c)$fails++;};
// справочник (как в промте №217)
$catalog=[
 ['id'=>245,'title'=>'241188 - Signature','name'=>'Signature','code'=>'da42','active'=>true],
 ['id'=>252,'title'=>'241195 - DS 7G','name'=>'DS 7G','code'=>'9567','active'=>true],
 ['id'=>99,'title'=>'150008 - Общие ресурсы KDSS','name'=>'Общие ресурсы KDSS','code'=>'d344','active'=>true],
 ['id'=>427,'title'=>'Общие ресурсы KDSS','name'=>'Общие ресурсы KDSS','code'=>'0051','active'=>true],
 ['id'=>359,'title'=>'Digital Kazdream','name'=>'Центр Корпоративного управления','code'=>'93de','active'=>true],
 ['id'=>458,'title'=>'Digital Kazdream','name'=>'Digital Kazdream','code'=>'e411','active'=>true],
 ['id'=>500,'title'=>'Berkut','name'=>'Berkut','code'=>null,'active'=>false],
 ['id'=>501,'title'=>'251000 - Berkut','name'=>'Berkut','code'=>null,'active'=>true],
];
$users=PM::parseUsers('[{"project":"Signature","employees":["Зуб Александр Александрович","Зуб Ольга Юрьевна"]},{"project":"Общие ресурсы KDSS","employees":["Сембаев Думан Бекболатович"]},{"project":"DS 7G","employees":["Рычко Артем Николаевич","Рычко Наталия Геннадьевна"]},{"project":"Digital Kazdream","employees":["Смагулов Ермек Омиртаевич","Касенов Адлет"]},{"project":"Berkut","employees":["Әмір Ілияс Бірлікұлы","Сайлаубекұлы Султан"]},{"project":"Нет такого","employees":["Ким Игорь"]},{"project":"Signature","employees":["Ким Игорь"]}]');
$ai=['rows'=>[]];
foreach (['Александр Зуб','Думан Сембаев','Артем Рычко','Ермек Смагулов','Илияс Амир','Султан Сайлаубекулы','Алексей Рычко','Мади Нурпанов','Остаток — 7G','Игорь Ким'] as $n)
  $ai['rows'][]=['project'=>['id'=>458,'title'=>'Digital Kazdream'],'expense'=>['id'=>82,'title'=>'Лицензии'],'employee'=>$n,'amount'=>12300];
$d=N::normalize($ai,'payroll',false,true);
$m=new PM($users,$catalog); $r=$m->apply($d, PM::parseProjectId('[458]'));
$p=array_map(fn($x)=>$x['project']['id'],$r['rows']);
$ok($p[0]===245,'Зуб → Signature 245 (порядок ФИО любой, отчество не нужно)');
$ok($p[1]===99,'Сембаев → KDSS: из дублей 99/427 взят с кодом в названии → 99');
$ok($p[2]===252,'Рычко Артем → DS 7G 252');
$ok($p[3]===458,'Смагулов → Digital Kazdream: по NAME FIN HUB → 458, а не 359');
$ok($p[4]===501,'«Илияс Амир» = «Әмір Ілияс» (казахские буквы) → Berkut: активный 501');
$ok($p[5]===501,'«Сайлаубекулы» = «Сайлаубекұлы» → Berkut');
$ok($p[6]===458,'Алексей Рычко (однофамилец, нет в данных) → общий 458');
$ok($p[7]===458,'Мади Нурпанов (нет в данных) → общий 458');
$ok($p[8]===458,'«Остаток — 7G» → общий');
$ok($p[9]===458 && count($m->stats['ambiguous'])===1,'Ким Игорь в двух проектах → неоднозначно → общий + запись в журнал');
$ok($m->stats['matched']===6 && $m->stats['default']===4,'статистика: по сотруднику 6, общий 4');
$ok(PM::parseProjectId('458')===458 && PM::parseProjectId(458)===458 && PM::parseProjectId(['id'=>458])===458 && PM::parseProjectId('')===null,'article_project_id в любом виде');
// без общего проекта — оставляем ИИ
$r2=(new PM([], []))->apply($d, null); $ok($r2['rows'][7]['project']['id']===458,'нет данных и общего проекта — проект от ИИ не трогаем');
echo $fails?"FAILED $fails\n":"ALL OK\n";
