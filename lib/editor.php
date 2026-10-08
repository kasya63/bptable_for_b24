<?php

namespace Local\Bptable;

/**
 * Разметка редактора для задания БП.
 *
 * Значение живёт в скрытом input с именем поля — его читают и старая форма
 * (getFieldInputValue), и новый UI задания (fields[...] → internalizeValue).
 * Если JS не загрузился, остаётся таблица только для просмотра и значение не теряется.
 *
 * JS грузится из /local/js/local.bptable/editor.js. Запуск продублирован:
 * <script> (если HTML вставлен с выполнением скриптов) и onload у 1px-картинки
 * (срабатывает, даже если HTML вставили через innerHTML без выполнения <script>).
 */
final class Editor
{
	public const JS_URL = '/local/js/local.bptable/editor.js';

	public static function render(string $controlName, array $data): string
	{
		$id = 'lbpt_' . bin2hex(random_bytes(6));
		$preset = $data['preset'] ?? Config::DEFAULT_PRESET;

		$columns = [];
		foreach (Config::presetColumns($preset) as $key)
		{
			$def = Config::columns()[$key];
			$columns[] = ['key' => $key, 'title' => $def['title'], 'type' => $def['type']];
		}

		$cfg = [
			'id'       => $id,
			'name'     => $controlName,
			'preset'   => $preset,
			'columns'  => $columns,
			'value'    => $data,
			'keepText' => Config::KEEP_TEXT_WITHOUT_ID,
			'tzOffset' => (new \DateTimeImmutable('now', new \DateTimeZone(Config::TIMEZONE)))->format('P'),
		];

		$json = Normalizer::toJson($data);
		$cfgAttr = htmlspecialcharsbx(json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		$boot = self::bootJs($id);

		return '<div class="lbpt-editor" id="' . $id . '" data-lbpt-cfg="' . $cfgAttr . '">'
			. '<div class="lbpt-editor__fallback">' . Renderer::view($data, true) . '</div>'
			. '<input type="hidden" class="lbpt-editor__value" name="' . htmlspecialcharsbx($controlName) . '" value="' . htmlspecialcharsbx($json) . '">'
			. '</div>'
			. '<script>' . $boot . '</script>'
			. '<img alt="" width="1" height="1" style="position:absolute;opacity:0;pointer-events:none"'
			. ' src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"'
			. ' onload="' . htmlspecialcharsbx($boot) . '">';
	}

	private static function bootJs(string $id): string
	{
		$ver = @filemtime($_SERVER['DOCUMENT_ROOT'] . self::JS_URL) ?: 1;
		$src = self::JS_URL . '?v=' . $ver;

		// идемпотентно: LocalBpTable.init сам проверяет, инициализирован ли контейнер
		return "(function(w,d,id,src){"
			. "if(w.LocalBpTable){w.LocalBpTable.init(id);return;}"
			. "(w.__lbptQ=w.__lbptQ||[]).push(id);"
			. "if(w.__lbptLoading)return;w.__lbptLoading=1;"
			. "var s=d.createElement('script');s.src=src;"
			. "s.onload=function(){var q=w.__lbptQ||[];w.__lbptQ=[];for(var i=0;i<q.length;i++)w.LocalBpTable.init(q[i]);};"
			. "(d.head||d.documentElement).appendChild(s);"
			. "})(window,document," . json_encode($id) . "," . json_encode($src) . ")";
	}
}
