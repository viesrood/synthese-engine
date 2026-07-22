<?php

declare(strict_types=1);

namespace viesrood\synthese\services;

use craft\base\Component;
use viesrood\synthese\Plugin;

/**
 * RerankService
 *
 * Lokale herweging (geen externe provider) van de hybride-zoekresultaten:
 * title-, section- en versheid-boosts bovenop de RRF-score van Supabase.
 */
class RerankService extends Component
{
    private const TITLE_BOOST = 1.2;
    private const FRESHNESS_BOOST = 1.1;

    /**
     * @param array[] $chunks Ruwe chunks uit VectorService::hybridSearch()
     * @param array<string, float>|null $sectionBoosts Overschrijft Settings indien gegeven
     * @return array[] Herwogen en ingekorte chunks
     */
    public function rerank(array $chunks, ?array $sectionBoosts = null, ?int $topK = null): array
    {
        $settings = Plugin::$plugin->getSettings();
        $sectionBoosts ??= $settings->sectionBoosts;
        $topK ??= $settings->topK;
        $freshnessDays = $settings->freshnessDays;

        $now = new \DateTimeImmutable();

        foreach ($chunks as &$chunk) {
            $score = (float) ($chunk['rrf_score'] ?? $chunk['similarity'] ?? 0.5);

            if (($chunk['chunk_type'] ?? '') === 'title') {
                $score *= self::TITLE_BOOST;
            }

            $section = $chunk['section'] ?? '';
            if (isset($sectionBoosts[$section])) {
                $score *= (float) $sectionBoosts[$section];
            }

            if (!empty($chunk['post_date'])) {
                try {
                    $postDate = new \DateTimeImmutable($chunk['post_date']);
                    if ((int) $now->diff($postDate)->days <= $freshnessDays) {
                        $score *= self::FRESHNESS_BOOST;
                    }
                } catch (\Throwable) {
                    // ongeldige datum negeren
                }
            }

            $chunk['final_score'] = $score;
        }
        unset($chunk);

        usort($chunks, fn($a, $b) => $b['final_score'] <=> $a['final_score']);

        return array_slice($chunks, 0, $topK);
    }
}
