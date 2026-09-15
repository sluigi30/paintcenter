<?php

namespace App\Filament\Concerns;

/**
 * Save closes the form and lands back on the resource's table.
 *
 * Used on products, brands and categories. It replaces the earlier
 * StaysOnFormAfterCreate / StaysOnFormAfterEdit pair, which held the form open
 * so a half-finished product could be topped up in a second pass; the table is
 * now the wanted landing spot after every Save.
 *
 * Both page types are covered by the one trait: CreateRecord declares
 * `getRedirectUrl(): string` and EditRecord `: ?string`, and `string`
 * satisfies each. Naming the index URL outright also pins the behaviour
 * against a panel-wide `resourceCreatePageRedirect` /
 * `resourceEditPageRedirect` being set later, which would otherwise silently
 * send Save somewhere else.
 */
trait ReturnsToTableAfterSave
{
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
