<?php

declare(strict_types=1);

/** @var rex_addon $this */

$func = rex_request('func', 'string');
$id = rex_request('id', 'int');
$table = rex::getTable('vseo_core_update');

if ('add' === $func || 'edit' === $func) {
    $form = rex_form::factory($table, $this->i18n('core_update'), 'id = ' . $id);
    $field = $form->addTextField('date');
    $field->setLabel($this->i18n('date'));
    $field->setAttribute('type', 'date');
    $field->getValidator()->add('notEmpty', $this->i18n('error_date'));
    $field = $form->addTextField('date_end');
    $field->setLabel($this->i18n('date_end'));
    $field->setAttribute('type', 'date');
    $field = $form->addTextField('name');
    $field->setLabel($this->i18n('name'));
    $field->getValidator()->add('notEmpty', $this->i18n('error_name'));
    if ('edit' === $func) {
        $form->addParam('id', $id);
    }
    echo $form->get();
    return;
}

$list = rex_list::factory('SELECT id, date, date_end, name FROM ' . $table . ' ORDER BY date DESC', 100);
$list->addTableAttribute('class', 'table-hover');
$list->removeColumn('id');
$list->setColumnLabel('date', $this->i18n('date'));
$list->setColumnLabel('date_end', $this->i18n('date_end'));
$list->setColumnLabel('name', $this->i18n('name'));
$list->setColumnFormat('date', 'date', 'd.m.Y');
$list->setColumnFormat('date_end', 'date', 'd.m.Y');
$list->setColumnParams('name', ['func' => 'edit', 'id' => '###id###']);
$list->addColumn('', '<i class="rex-icon rex-icon-add"></i>', 0, ['<th class="rex-table-icon"><a href="' . $list->getUrl(['func' => 'add']) . '" title="' . rex_i18n::msg('add') . '"><i class="rex-icon rex-icon-add"></i></a></th>', '<td class="rex-table-icon">###VALUE###</td>']);
$list->addColumn(rex_i18n::msg('delete'), rex_i18n::msg('delete'));
$list->setColumnParams(rex_i18n::msg('delete'), ['func' => 'delete', 'id' => '###id###'] + rex_csrf_token::factory('vseo_core_updates')->getUrlParams());
$list->addLinkAttribute(rex_i18n::msg('delete'), 'data-confirm', rex_i18n::msg('delete') . ' ?');

if ('delete' === $func && $id) {
    if (rex_csrf_token::factory('vseo_core_updates')->isValid()) {
        rex_sql::factory()->setQuery('DELETE FROM ' . $table . ' WHERE id = ?', [$id]);
        echo rex_view::success($this->i18n('deleted'));
    } else {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    }
}

$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('core_updates'), false);
$fragment->setVar('content', $list->get(), false);
echo $fragment->parse('core/page/section.php');
