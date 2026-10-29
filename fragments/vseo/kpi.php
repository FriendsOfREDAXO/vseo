<?php

declare(strict_types=1);

use FriendsOfRedaxo\VSeo\Util\Format;

/**
 * Kennzahlen 28 Tage gegen Vorzeitraum.
 *
 * @var rex_fragment $this
 * @psalm-scope-this rex_fragment
 */

$kpi = (array) $this->getVar('kpi', []);
$cur = (array) ($kpi['cur'] ?? []);
$prev = (array) ($kpi['prev'] ?? []);
$metrics = [
    'queries' => [rex_i18n::msg('vseo_kpi_queries'), false],
    'urls' => [rex_i18n::msg('vseo_kpi_urls'), false],
    'clicks' => [rex_i18n::msg('vseo_kpi_clicks'), false],
    'impressions' => [rex_i18n::msg('vseo_kpi_impressions'), false],
    'position' => [rex_i18n::msg('vseo_kpi_position'), true],
];
$only = $this->getVar('only');
?>
<dl class="vseo-kpis">
<?php foreach ($metrics as $key => [$label, $inverse]):
    if (is_array($only) && !in_array($key, $only, true)) {
        continue;
    }
    $value = $cur[$key] ?? null;
    ?>
    <div class="vseo-kpi<?= in_array($key, ['queries', 'urls'], true) ? ' is-core' : '' ?>">
        <dt><?= rex_escape($label) ?></dt>
        <dd>
            <span class="vseo-kpi-value"><?= 'position' === $key ? Format::dec(null === $value ? null : (float) $value) : Format::int($value) ?></span>
            <?= Format::delta($value, $prev[$key] ?? null, $inverse) ?>
        </dd>
    </div>
<?php endforeach ?>
</dl>
