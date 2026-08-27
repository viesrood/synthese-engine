<?php

declare(strict_types=1);

namespace viesrood\synthese\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\StringHelper;
use viesrood\synthese\helpers\QueryNormalizer;
use viesrood\synthese\models\Settings;
use viesrood\synthese\Plugin;
use yii\db\Expression;

/**
 * SuggestionsService
 *
 * Turns questions visitors actually asked into suggestions other visitors get
 * to see, but only after an admin approved them.
 *
 * Harvesting runs from the console, never during a visitor's request: it costs
 * an embedding per unseen phrasing and nobody is waiting for it. Clustering is
 * two-stage - identical after normalisation first (free), cosine over the
 * stored embeddings second (one API call per genuinely new phrasing).
 *
 * The embeddings live in MySQL rather than in the Supabase vector store on
 * purpose. There are at most a few hundred of them, a cosine loop in PHP is
 * plenty, and a Supabase project on the free tier pauses after inactivity -
 * a nightly job that depended on it would silently do nothing.
 */
class SuggestionsService extends Component
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const ORIGIN_HARVESTED = 'harvested';
    public const ORIGIN_MANUAL = 'manual';

    private const LOGS = '{{%synthese_logs}}';
    private const SUGGESTIONS = '{{%synthese_suggestions}}';
    private const VARIANTS = '{{%synthese_suggestion_variants}}';

    /** @var array[]|null Approved clusters with their vectors, for related questions. */
    private ?array $vectorCache = null;

    /** @var array<int, float[]>|null Every cluster's vector, held for one harvest run. */
    private ?array $clusterCache = null;

    // -----------------------------------------------------------------
    // Harvesting
    // -----------------------------------------------------------------

    /**
     * Reads the log rows that have not been harvested yet and folds them into
     * suggestion clusters.
     *
     * @return array{scanned: int, skipped: int, newClusters: int, updatedClusters: int, embedded: int}
     */
    public function harvest(?callable $progress = null): array
    {
        $settings = Plugin::$plugin->getSettings();
        $stats = ['scanned' => 0, 'skipped' => 0, 'newClusters' => 0, 'updatedClusters' => 0, 'embedded' => 0];

        $rows = (new Query())
            ->select(['id', 'query', 'query_normalized', 'outcome', 'sources_count', 'ip_hash', 'created_at'])
            ->from(self::LOGS)
            ->where(['harvested_at' => null])
            ->orderBy(['created_at' => SORT_ASC])
            ->all();

        if ($rows === []) {
            return $stats;
        }

        // Group first, so a question asked twenty times costs one decision.
        $groups = [];
        $harvestedIds = [];

        foreach ($rows as $row) {
            $stats['scanned']++;
            $harvestedIds[] = (int) $row['id'];

            $normalized = (string) $row['query_normalized'];
            if ($normalized === '') {
                $normalized = QueryNormalizer::normalize((string) $row['query']);
            }

            if (!$this->isEligible((string) $row['query'], $row, $settings)) {
                $stats['skipped']++;
                continue;
            }

            if (!isset($groups[$normalized])) {
                $groups[$normalized] = [
                    'question' => trim((string) $row['query']),
                    'askCount' => 0,
                    'askers' => [],
                    'firstAskedAt' => (string) $row['created_at'],
                    'lastAskedAt' => (string) $row['created_at'],
                ];
            }

            $groups[$normalized]['askCount']++;
            $groups[$normalized]['lastAskedAt'] = (string) $row['created_at'];
            $ipHash = (string) ($row['ip_hash'] ?? '');
            if ($ipHash !== '') {
                $groups[$normalized]['askers'][$ipHash] = true;
            }
        }

        foreach ($groups as $normalized => $group) {
            $isNew = $this->absorb($normalized, $group, $settings, $stats);
            if ($progress !== null) {
                $progress($group['question'], $isNew);
            }
        }

        $this->markHarvested($harvestedIds);
        $this->vectorCache = null;
        $this->clusterCache = null;

        return $stats;
    }

    /**
     * Folds one normalised question into an existing cluster or creates a new one.
     *
     * @param array{question: string, askCount: int, askers: array, firstAskedAt: string, lastAskedAt: string} $group
     * @param array $stats Mutated in place.
     */
    private function absorb(string $normalized, array $group, Settings $settings, array &$stats): bool
    {
        $askerCount = count($group['askers']);

        // Stage one: a phrasing we have already seen. Free.
        $suggestionId = (new Query())
            ->select(['suggestionId'])
            ->from(self::VARIANTS)
            ->where(['query_normalized' => $normalized])
            ->scalar();

        if ($suggestionId) {
            $this->addVariant((int) $suggestionId, $normalized, $group['askCount']);
            $this->addCounts((int) $suggestionId, $group, $askerCount);
            $stats['updatedClusters']++;
            return false;
        }

        // Stage two: a phrasing we have not seen. Costs one embedding.
        $embedding = Plugin::$plugin->embedding->embed($group['question']);
        if (!empty($embedding)) {
            $stats['embedded']++;
            $match = $this->nearestCluster($embedding, (float) $settings->suggestionClusterThreshold);
            if ($match !== null) {
                $this->addVariant($match, $normalized, $group['askCount']);
                $this->addCounts($match, $group, $askerCount);
                $stats['updatedClusters']++;
                return false;
            }
        }

        $this->createCluster($normalized, $group, $askerCount, $embedding);
        $stats['newClusters']++;
        return true;
    }

    /**
     * Whether a logged question may ever become a suggestion.
     *
     * Everything here is a reason not to show a stranger's typing to other
     * visitors, so the checks are deliberately blunt.
     */
    private function isEligible(string $query, array $row, Settings $settings): bool
    {
        // Only questions that produced a grounded answer. `outcome` says which
        // path the request took; `sources_count` says the answer had something
        // behind it. `top_score` is no use here, it is an RRF score.
        if (($row['outcome'] ?? '') !== 'answered' || (int) ($row['sources_count'] ?? 0) < 1) {
            return false;
        }

        $length = mb_strlen(trim($query));
        if ($length < $settings->suggestionMinLength || $length > $settings->suggestionMaxLength) {
            return false;
        }

        // Anything that looks like it identifies a person or an account.
        $personal = [
            '/[\w.+-]+@[\w-]+\.[a-z]{2,}/iu',          // e-mail
            '/https?:\/\//iu',                          // URL
            '/\b(?:\+?\d[\s.-]?){7,}\b/u',              // phone number
            '/\b\d{4}\s?[a-z]{2}\b/iu',                 // Dutch postcode
            '/\b\d{6,}\b/u',                            // long digit run
        ];
        foreach ($personal as $pattern) {
            if (preg_match($pattern, $query)) {
                return false;
            }
        }

        $lower = mb_strtolower($query, 'UTF-8');
        foreach ($settings->suggestionBlocklist as $word) {
            $word = trim((string) $word);
            if ($word !== '' && str_contains($lower, mb_strtolower($word, 'UTF-8'))) {
                return false;
            }
        }

        return true;
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * Approved suggestions for the visitor-facing chips, pinned ones first.
     *
     * @return array<int, array{id: int, question: string}>
     */
    public function approved(int $limit = 6): array
    {
        $settings = Plugin::$plugin->getSettings();
        if (!$settings->suggestionsEnabled || $limit < 1) {
            return [];
        }

        try {
            return (new Query())
                ->select(['id', 'question'])
                ->from(self::SUGGESTIONS)
                ->where(['status' => self::STATUS_APPROVED])
                ->orderBy(['pinned' => SORT_DESC, 'asker_count' => SORT_DESC, 'last_asked_at' => SORT_DESC, 'id' => SORT_ASC])
                ->limit($limit)
                ->all();
        } catch (\Throwable $e) {
            Craft::warning('SuggestionsService::approved failed: ' . $e->getMessage(), 'synthese-engine');
            return [];
        }
    }

    /**
     * Approved suggestions closest to a question, by cosine over the stored
     * embeddings. The asked question itself is filtered out.
     *
     * @param float[] $embedding
     * @return string[]
     */
    public function relatedToEmbedding(array $embedding, string $askedQuery): array
    {
        $settings = Plugin::$plugin->getSettings();
        $limit = (int) $settings->relatedSuggestionsCount;

        if (!$settings->suggestionsEnabled || $limit < 1 || empty($embedding)) {
            return [];
        }

        $asked = QueryNormalizer::normalize($askedQuery);
        $floor = (float) $settings->relatedMinSimilarity;

        $scored = [];
        foreach ($this->approvedVectors() as $row) {
            if ($row['question_normalized'] === $asked) {
                continue;
            }
            $score = QueryNormalizer::cosine($embedding, $row['vector']);
            if ($score >= $floor) {
                $scored[] = ['question' => $row['question'], 'score' => $score];
            }
        }

        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_column(array_slice($scored, 0, $limit), 'question');
    }

    /**
     * @return array<int, array{question: string, question_normalized: string, vector: float[]}>
     */
    private function approvedVectors(): array
    {
        if ($this->vectorCache !== null) {
            return $this->vectorCache;
        }

        $rows = [];
        try {
            foreach ((new Query())
                ->select(['question', 'question_normalized', 'embedding'])
                ->from(self::SUGGESTIONS)
                ->where(['status' => self::STATUS_APPROVED])
                ->andWhere(['not', ['embedding' => null]])
                ->all() as $row) {
                $vector = QueryNormalizer::unpackVector((string) $row['embedding']);
                if ($vector !== []) {
                    $rows[] = [
                        'question' => (string) $row['question'],
                        'question_normalized' => (string) $row['question_normalized'],
                        'vector' => $vector,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Craft::warning('SuggestionsService::approvedVectors failed: ' . $e->getMessage(), 'synthese-engine');
        }

        return $this->vectorCache = $rows;
    }

    /**
     * All clusters with a given status, for the Control Panel.
     *
     * @return array[]
     */
    public function listByStatus(string $status, int $limit = 200): array
    {
        $settings = Plugin::$plugin->getSettings();
        $minAskers = (int) $settings->suggestionMinAskers;

        $query = (new Query())
            ->select(['id', 'question', 'ask_count', 'asker_count', 'first_asked_at', 'last_asked_at', 'status', 'pinned', 'edited_by_admin', 'origin'])
            ->from(self::SUGGESTIONS)
            ->where(['status' => $status]);

        // The queue only shows what enough different visitors asked, so one
        // person cannot push their own wording into it. Approved and rejected
        // lists show everything, including what an admin added by hand.
        if ($status === self::STATUS_PENDING) {
            $query->andWhere(['or', ['>=', 'asker_count', $minAskers], ['origin' => self::ORIGIN_MANUAL]]);
        }

        try {
            $rows = $query
                ->orderBy(['pinned' => SORT_DESC, 'asker_count' => SORT_DESC, 'last_asked_at' => SORT_DESC, 'id' => SORT_DESC])
                ->limit($limit)
                ->all();
        } catch (\Throwable $e) {
            Craft::warning('SuggestionsService::listByStatus failed: ' . $e->getMessage(), 'synthese-engine');
            return [];
        }

        return $this->attachVariants($rows);
    }

    /**
     * Number of clusters waiting in the queue, for the nav badge.
     */
    public function pendingCount(): int
    {
        try {
            return (int) (new Query())
                ->from(self::SUGGESTIONS)
                ->where(['status' => self::STATUS_PENDING])
                ->andWhere(['or', ['>=', 'asker_count', (int) Plugin::$plugin->getSettings()->suggestionMinAskers], ['origin' => self::ORIGIN_MANUAL]])
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @param array[] $rows
     * @return array[]
     */
    private function attachVariants(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_column($rows, 'id');
        $variants = [];
        foreach ((new Query())
            ->select(['suggestionId', 'query_normalized', 'ask_count'])
            ->from(self::VARIANTS)
            ->where(['suggestionId' => $ids])
            ->orderBy(['ask_count' => SORT_DESC])
            ->all() as $variant) {
            $variants[(int) $variant['suggestionId']][] = $variant;
        }

        foreach ($rows as &$row) {
            $row['variants'] = $variants[(int) $row['id']] ?? [];
        }

        return $rows;
    }

    // -----------------------------------------------------------------
    // Editing (Control Panel)
    // -----------------------------------------------------------------

    public function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            return false;
        }

        $ok = $this->update($id, ['status' => $status]);
        $this->vectorCache = null;
        $this->clusterCache = null;
        return $ok;
    }

    public function setPinned(int $id, bool $pinned): bool
    {
        return $this->update($id, ['pinned' => (int) $pinned]);
    }

    /**
     * Rewrites the visible text. Flags the row so a later harvest leaves the
     * admin's wording alone.
     */
    public function rewrite(int $id, string $question): bool
    {
        $question = trim($question);
        if ($question === '') {
            return false;
        }

        $ok = $this->update($id, [
            'question' => mb_substr($question, 0, 500),
            'edited_by_admin' => 1,
        ]);
        $this->vectorCache = null;
        return $ok;
    }

    public function delete(int $id): bool
    {
        try {
            Craft::$app->getDb()->createCommand()->delete(self::SUGGESTIONS, ['id' => $id])->execute();
            $this->vectorCache = null;
            return true;
        } catch (\Throwable $e) {
            Craft::warning('SuggestionsService::delete failed: ' . $e->getMessage(), 'synthese-engine');
            return false;
        }
    }

    /**
     * Adds a question by hand. Lets an admin fill the chips before there is
     * enough traffic to harvest anything.
     */
    public function addManual(string $question, bool $approved = true): bool
    {
        $question = trim($question);
        if ($question === '') {
            return false;
        }

        $normalized = QueryNormalizer::normalize($question);
        $existing = (new Query())
            ->select(['id'])
            ->from(self::SUGGESTIONS)
            ->where(['question_normalized' => $normalized])
            ->scalar();

        if ($existing) {
            return $this->setStatus((int) $existing, $approved ? self::STATUS_APPROVED : self::STATUS_PENDING);
        }

        $now = date('Y-m-d H:i:s');
        $embedding = Plugin::$plugin->embedding->embed($question);

        try {
            Craft::$app->getDb()->createCommand()->insert(self::SUGGESTIONS, [
                'question' => mb_substr($question, 0, 500),
                'question_normalized' => mb_substr($normalized, 0, 500),
                'embedding' => empty($embedding) ? null : QueryNormalizer::packVector($embedding),
                'ask_count' => 0,
                'asker_count' => 0,
                'status' => $approved ? self::STATUS_APPROVED : self::STATUS_PENDING,
                'pinned' => 0,
                'edited_by_admin' => 1,
                'origin' => self::ORIGIN_MANUAL,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
            $this->vectorCache = null;
            return true;
        } catch (\Throwable $e) {
            Craft::warning('SuggestionsService::addManual failed: ' . $e->getMessage(), 'synthese-engine');
            return false;
        }
    }

    // -----------------------------------------------------------------
    // Retention
    // -----------------------------------------------------------------

    /**
     * Deletes log rows past the retention window. The suggestions survive:
     * they hold only the question text, never the IP hash it came with.
     */
    public function pruneLogs(?int $days = null): int
    {
        $days = $days ?? (int) Plugin::$plugin->getSettings()->logRetentionDays;
        if ($days < 1) {
            return 0;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        try {
            return Craft::$app->getDb()->createCommand()
                ->delete(self::LOGS, ['<', 'created_at', $cutoff])
                ->execute();
        } catch (\Throwable $e) {
            Craft::warning('SuggestionsService::pruneLogs failed: ' . $e->getMessage(), 'synthese-engine');
            return 0;
        }
    }

    // -----------------------------------------------------------------
    // Storage helpers
    // -----------------------------------------------------------------

    /**
     * Nearest approved-or-pending cluster above the threshold, or null.
     *
     * @param float[] $embedding
     */
    private function nearestCluster(array $embedding, float $threshold): ?int
    {
        $best = null;
        $bestScore = $threshold;

        foreach ($this->clusterVectors() as $id => $vector) {
            $score = QueryNormalizer::cosine($embedding, $vector);
            if ($score >= $bestScore) {
                $bestScore = $score;
                $best = $id;
            }
        }

        return $best;
    }

    /**
     * Every cluster's vector, read once and then held for the rest of the run.
     * A harvest compares each new phrasing against all of them, so reading them
     * per comparison would turn one query into hundreds.
     *
     * Rejected clusters are included on purpose: a rejected question that comes
     * back in slightly different words should land on the rejection, not
     * reappear in the queue.
     *
     * @return array<int, float[]>
     */
    private function clusterVectors(): array
    {
        if ($this->clusterCache !== null) {
            return $this->clusterCache;
        }

        $vectors = [];
        try {
            foreach ((new Query())
                ->select(['id', 'embedding'])
                ->from(self::SUGGESTIONS)
                ->where(['not', ['embedding' => null]])
                ->all() as $row) {
                $vector = QueryNormalizer::unpackVector((string) $row['embedding']);
                if ($vector !== []) {
                    $vectors[(int) $row['id']] = $vector;
                }
            }
        } catch (\Throwable $e) {
            Craft::warning('SuggestionsService::clusterVectors failed: ' . $e->getMessage(), 'synthese-engine');
        }

        return $this->clusterCache = $vectors;
    }

    /**
     * @param array{question: string, askCount: int, askers: array, firstAskedAt: string, lastAskedAt: string} $group
     * @param float[] $embedding
     */
    private function createCluster(string $normalized, array $group, int $askerCount, array $embedding): void
    {
        $now = date('Y-m-d H:i:s');

        try {
            $db = Craft::$app->getDb();
            $db->createCommand()->insert(self::SUGGESTIONS, [
                'question' => mb_substr($group['question'], 0, 500),
                'question_normalized' => mb_substr($normalized, 0, 500),
                'embedding' => empty($embedding) ? null : QueryNormalizer::packVector($embedding),
                'ask_count' => $group['askCount'],
                'asker_count' => $askerCount,
                'first_asked_at' => $group['firstAskedAt'],
                'last_asked_at' => $group['lastAskedAt'],
                'status' => self::STATUS_PENDING,
                'pinned' => 0,
                'edited_by_admin' => 0,
                'origin' => self::ORIGIN_HARVESTED,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();

            $newId = (int) $db->getLastInsertID();
            $this->addVariant($newId, $normalized, $group['askCount']);

            if (!empty($embedding) && $this->clusterCache !== null) {
                $this->clusterCache[$newId] = $embedding;
            }
        } catch (\Throwable $e) {
            Craft::warning('SuggestionsService::createCluster failed: ' . $e->getMessage(), 'synthese-engine');
        }
    }

    private function addVariant(int $suggestionId, string $normalized, int $askCount): void
    {
        $now = date('Y-m-d H:i:s');

        try {
            Craft::$app->getDb()->createCommand()->upsert(self::VARIANTS, [
                'suggestionId' => $suggestionId,
                'query_normalized' => mb_substr($normalized, 0, 500),
                'ask_count' => $askCount,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ], [
                'ask_count' => new Expression('[[ask_count]] + ' . $askCount),
                'dateUpdated' => $now,
            ])->execute();
        } catch (\Throwable $e) {
            Craft::warning('SuggestionsService::addVariant failed: ' . $e->getMessage(), 'synthese-engine');
        }
    }

    /**
     * @param array{question: string, askCount: int, askers: array, firstAskedAt: string, lastAskedAt: string} $group
     */
    private function addCounts(int $suggestionId, array $group, int $askerCount): void
    {
        try {
            $db = Craft::$app->getDb();

            // asker_count is a running maximum rather than a true distinct count:
            // the IP hashes behind it are never stored on the suggestion, so the
            // only alternative would be keeping them, which is the thing we are
            // trying to avoid.
            $db->createCommand()->update(self::SUGGESTIONS, [
                'ask_count' => new Expression('[[ask_count]] + ' . $group['askCount']),
                'asker_count' => new Expression('GREATEST([[asker_count]], ' . $askerCount . ')'),
                'last_asked_at' => $group['lastAskedAt'],
                'dateUpdated' => date('Y-m-d H:i:s'),
            ], ['id' => $suggestionId])->execute();
        } catch (\Throwable $e) {
            Craft::warning('SuggestionsService::addCounts failed: ' . $e->getMessage(), 'synthese-engine');
        }
    }

    private function update(int $id, array $values): bool
    {
        try {
            $values['dateUpdated'] = date('Y-m-d H:i:s');
            Craft::$app->getDb()->createCommand()->update(self::SUGGESTIONS, $values, ['id' => $id])->execute();
            return true;
        } catch (\Throwable $e) {
            Craft::warning('SuggestionsService::update failed: ' . $e->getMessage(), 'synthese-engine');
            return false;
        }
    }

    /**
     * @param int[] $ids
     */
    private function markHarvested(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        try {
            Craft::$app->getDb()->createCommand()->update(
                self::LOGS,
                ['harvested_at' => date('Y-m-d H:i:s')],
                ['id' => $ids]
            )->execute();
        } catch (\Throwable $e) {
            Craft::warning('SuggestionsService::markHarvested failed: ' . $e->getMessage(), 'synthese-engine');
        }
    }
}
