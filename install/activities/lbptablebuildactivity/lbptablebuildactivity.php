<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) { die(); }

use Bitrix\Bizproc;
use Bitrix\Main\Loader;

class CBPLbpTableBuildActivity extends CBPActivity
{
	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title'          => '',
			'Source'         => '',
			'Preset'         => 'payroll',
			'TargetVariable' => '',
			// return
			'Result'     => '',
			'RowsCount'  => 0,
			'Total'      => 0,
			'Unresolved' => 0,
			'Summary'    => '',
		];
		$this->SetPropertiesTypes([
			'Result'     => ['Type' => 'string'],
			'RowsCount'  => ['Type' => 'int'],
			'Total'      => ['Type' => 'double'],
			'Unresolved' => ['Type' => 'int'],
			'Summary'    => ['Type' => 'text'],
		]);
	}

	protected function ReInitialize()
	{
		parent::ReInitialize();
		$this->Result = '';
		$this->RowsCount = 0;
		$this->Total = 0;
		$this->Unresolved = 0;
		$this->Summary = '';
	}

	public function Execute()
	{
		if (!Loader::includeModule('local.bptable'))
		{
			$this->WriteToTrackingService('Модуль local.bptable не установлен', 0, CBPTrackingType::Error);
			return CBPActivityExecutionStatus::Closed;
		}

		$source = $this->Source;
		if (is_array($source))
		{
			// множественное значение / массив от другого действия
			$source = \CBPHelper::isAssociativeArray($source) || isset($source['rows'])
				? $source
				: implode("\n", array_map('strval', $source));
		}

		$decoded = \Local\Bptable\Normalizer::decode($source);
		if ($decoded === null && trim((string)(is_array($source) ? 'x' : $source)) !== '')
		{
			$this->WriteToTrackingService('Источник не является корректным JSON — таблица пустая', 0, CBPTrackingType::Error);
		}

		$preset = \Local\Bptable\Config::isPreset($this->Preset) ? $this->Preset : \Local\Bptable\Config::DEFAULT_PRESET;
		$data = \Local\Bptable\Normalizer::normalize($decoded ?? '', $preset, true, true);
		$json = \Local\Bptable\Normalizer::toJson($data);

		$stats = \Local\Bptable\Stats::of($data);

		$this->Result = $json;
		$this->RowsCount = $stats['rows'];
		$this->Total = $stats['total'];
		$this->Unresolved = $stats['unresolved'];
		$this->Summary = \Local\Bptable\Renderer::plainText($data);

		$target = trim((string)$this->TargetVariable);
		if ($target !== '')
		{
			$this->GetRootActivity()->SetVariable($target, $json);
		}

		$this->WriteToTrackingService(sprintf(
			'Таблица: строк %d, итого %s, ячеек без ID %d%s',
			$stats['rows'],
			\Local\Bptable\Money::format(\Local\Bptable\Money::fromCents($stats['totalCents'])),
			$stats['unresolved'],
			$target !== '' ? ", записано в {$target}" : ''
		));

		return CBPActivityExecutionStatus::Closed;
	}

	public static function ValidateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null)
	{
		$errors = [];
		if (CBPHelper::isEmptyValue($arTestProperties['Source'] ?? null))
		{
			$errors[] = ['code' => 'NotExist', 'parameter' => 'Source', 'message' => 'Не указан источник (JSON)'];
		}
		return array_merge($errors, parent::ValidateProperties($arTestProperties, $user));
	}

	protected static function getPropertiesMap(array $variables = []): array
	{
		$varOptions = ['' => '— не записывать —'];
		foreach ($variables as $id => $var)
		{
			$varOptions[$id] = ($var['Name'] ?? $id) . ' [' . $id . ']';
		}

		$presetOptions = [];
		if (Loader::includeModule('local.bptable'))
		{
			foreach (\Local\Bptable\Config::presets() as $code => $p)
			{
				$presetOptions[$code] = $p['title'];
			}
		}

		return [
			'Source' => [
				'Name'        => 'Источник (JSON)',
				'Description' => 'Ответ ИИ или любой JSON: {"rows":[...]} или массив строк. Выберите переменную/результат через «...»',
				'FieldName'   => 'lbpt_source',
				'Type'        => Bizproc\FieldType::TEXT,
				'Required'    => true,
			],
			'Preset' => [
				'Name'      => 'Набор колонок',
				'FieldName' => 'lbpt_preset',
				'Type'      => Bizproc\FieldType::SELECT,
				'Options'   => $presetOptions,
				'Default'   => 'payroll',
				'Required'  => true,
			],
			'TargetVariable' => [
				'Name'        => 'Записать в переменную',
				'Description' => 'Переменная с типом «Таблица распределения…», которая стоит полем в задании',
				'FieldName'   => 'lbpt_target',
				'Type'        => Bizproc\FieldType::SELECT,
				'Options'     => $varOptions,
			],
		];
	}

	public static function GetPropertiesDialog(
		$documentType, $activityName, $arWorkflowTemplate, $arWorkflowParameters, $arWorkflowVariables,
		$arCurrentValues = null, $formName = '', $popupWindow = null, $siteId = ''
	)
	{
		$dialog = new Bizproc\Activity\PropertiesDialog(__FILE__, [
			'documentType'       => $documentType,
			'activityName'       => $activityName,
			'workflowTemplate'   => $arWorkflowTemplate,
			'workflowParameters' => $arWorkflowParameters,
			'workflowVariables'  => $arWorkflowVariables,
			'currentValues'      => $arCurrentValues,
			'formName'           => $formName,
			'siteId'             => $siteId,
		]);
		$dialog->setMap(static::getPropertiesMap(is_array($arWorkflowVariables) ? $arWorkflowVariables : []));
		return $dialog;
	}

	public static function GetPropertiesDialogValues(
		$documentType, $activityName, &$arWorkflowTemplate, &$arWorkflowParameters, &$arWorkflowVariables,
		$arCurrentValues, &$errors
	)
	{
		$documentService = CBPRuntime::GetRuntime(true)->getDocumentService();
		$properties = [];

		foreach (static::getPropertiesMap(is_array($arWorkflowVariables) ? $arWorkflowVariables : []) as $id => $map)
		{
			$field = $documentService->getFieldTypeObject($documentType, $map);
			$properties[$id] = $field
				? $field->extractValue(['Field' => $map['FieldName']], $arCurrentValues, $errors)
				: ($arCurrentValues[$map['FieldName']] ?? null);
		}

		$errors = static::ValidateProperties($properties, new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser));
		if ($errors)
		{
			return false;
		}

		$current = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
		$current['Properties'] = $properties;
		return true;
	}
}
