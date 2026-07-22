<?php

declare(strict_types=1);

namespace viesrood\synthese\widgets;

use Craft;
use craft\base\Widget;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use viesrood\synthese\Plugin;

/**
 * Dashboard-widget met een 7-daagse rollup van de zoekstatistieken.
 */
class SyntheseWidget extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('synthese-engine', 'Synthese Engine');
    }

    public static function icon(): ?string
    {
        return '@appicons/search.svg';
    }

    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->getIsAdmin();
    }

    public function getTitle(): ?string
    {
        return Craft::t('synthese-engine', 'Synthese Engine');
    }

    public function getBodyHtml(): ?string
    {
        $rollup = Plugin::$plugin->stats->getRollup(7);
        $vector = Plugin::$plugin->vector->getStats();
        $url = UrlHelper::cpUrl('synthese-engine');

        $rows = [
            [Craft::t('synthese-engine', 'Zoekopdrachten (7 dagen)'), (string) $rollup['total']],
            [Craft::t('synthese-engine', 'Beantwoordbaar'), $rollup['answerableRate'] . '%'],
            [Craft::t('synthese-engine', 'Cache-hit'), $rollup['cacheHitRate'] . '%'],
            [Craft::t('synthese-engine', 'Gem. duur'), $rollup['avgDurationMs'] . ' ms'],
            [Craft::t('synthese-engine', 'Chunks in index'), (string) ($vector['chunks'] ?? 0)],
        ];

        $html = '<table class="data fullwidth"><tbody>';
        foreach ($rows as [$label, $value]) {
            $html .= '<tr><th>' . Html::encode($label) . '</th><td class="rightalign">' . Html::encode($value) . '</td></tr>';
        }
        $html .= '</tbody></table>';
        $html .= '<p style="margin-top:10px;"><a class="btn" href="' . $url . '">' . Craft::t('synthese-engine', 'Dashboard openen') . '</a></p>';

        return $html;
    }
}
