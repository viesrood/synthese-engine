<?php

declare(strict_types=1);

namespace viesrood\synthese\helpers;

/**
 * QueryNormalizer
 *
 * One definition of "these two questions are typed the same". Used for the
 * answer cache key, for grouping log rows and for matching a question against
 * an existing suggestion cluster, so those three can never drift apart.
 */
final class QueryNormalizer
{
    /**
     * Lowercases, collapses whitespace and drops trailing sentence punctuation.
     *
     * Deliberately shallow: it folds "Wat is Metacom?", "wat is metacom" and
     * "Wat  is  Metacom" together and nothing more. Anything that needs to
     * recognise a rephrasing is the embedding's job, not this method's.
     */
    public static function normalize(string $query): string
    {
        $query = mb_strtolower($query, 'UTF-8');
        $query = preg_replace('/\s+/u', ' ', $query) ?? $query;
        return rtrim(trim($query), '?!.');
    }

    /**
     * Cosine similarity of two equal-length vectors, 0.0 when either is empty
     * or their lengths differ.
     *
     * @param float[] $a
     * @param float[] $b
     */
    public static function cosine(array $a, array $b): float
    {
        $length = count($a);
        if ($length === 0 || $length !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Packs an embedding into a compact binary blob (4 bytes per dimension).
     *
     * @param float[] $vector
     */
    public static function packVector(array $vector): string
    {
        return pack('g*', ...array_map('floatval', $vector));
    }

    /**
     * @return float[]
     */
    public static function unpackVector(string $blob): array
    {
        if ($blob === '') {
            return [];
        }
        $values = unpack('g*', $blob);
        return $values === false ? [] : array_values($values);
    }
}
