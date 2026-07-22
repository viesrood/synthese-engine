<?php

declare(strict_types=1);

namespace viesrood\synthese\services;

use craft\base\Component;
use viesrood\synthese\Plugin;

/**
 * RerankService
 *
 * Local reweighting (no external provider) of the hybrid search results:
 * title, section and freshness boosts on top of Supabase's RRF score.
 */
class RerankService extends Component
{
    private const TITLE_BOOST = 1.2;
    private const FRESHNESS_BOOST = 1.1;

    /**
     * @param array[] $chunks Raw chunks from VectorService::hybridSearch()
     * @param array<string, float>|null $sectionBoosts Overrides Settings when given
     * @return array[] Reweighted and truncated chunks
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
                    // ignore invalid date
                }
            }

            $chunk['final_score'] = $score;
        }
        unset($chunk);

        usort($chunks, fn($a, $b) => $b['final_score'] <=> $a['final_score']);

        return array_slice($chunks, 0, $topK);
    }
}
