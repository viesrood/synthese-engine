<?php

declare(strict_types=1);

namespace viesrood\synthese\services;

use craft\base\Component;
use viesrood\synthese\Plugin;

/**
 * AnswerabilityService
 *
 * Pre-synthese-poort: bepaalt of de opgehaalde chunks relevant genoeg zijn om
 * de LLM aan te roepen. Voorkomt gehallucineerde antwoorden bij zwakke retrieval.
 */
class AnswerabilityService extends Component
{
    /**
     * @param array[] $chunks Herwogen chunks (met 'similarity' of 'final_score')
     */
    public function isAnswerable(array $chunks): bool
    {
        if (empty($chunks)) {
            return false;
        }

        $settings = Plugin::$plugin->getSettings();
        $minChunks = $settings->answerabilityMinChunks;
        $minSimilarity = $settings->answerabilityMinSimilarity;

        $highScoreCount = 0;
        foreach ($chunks as $chunk) {
            $score = (float) ($chunk['similarity'] ?? $chunk['final_score'] ?? 0);
            if ($score >= $minSimilarity) {
                $highScoreCount++;
            }
        }

        return $highScoreCount >= $minChunks;
    }
}
