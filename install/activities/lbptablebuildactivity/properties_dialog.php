<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) { die(); }
/** @var \Bitrix\Bizproc\Activity\PropertiesDialog $dialog */
foreach ($dialog->getMap() as $field):
?>
<tr>
	<td align="right" width="40%" valign="top" class="adm-detail-content-cell-l">
		<?= !empty($field['Required']) ? '<span class="adm-required-field">' . htmlspecialcharsbx($field['Name']) . ':</span>' : htmlspecialcharsbx($field['Name']) . ':' ?>
		<?php if (!empty($field['Description'])): ?><br><span style="color:#828b95;font-size:11px"><?= htmlspecialcharsbx($field['Description']) ?></span><?php endif; ?>
	</td>
	<td width="60%" class="adm-detail-content-cell-r"><?= $dialog->renderFieldControl($field) ?></td>
</tr>
<?php endforeach; ?>
