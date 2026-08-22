<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use Anthropic\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes a customer's English name in Thaana, by asking Claude.
 *
 * This is TRANSLITERATION, not translation: "Ahmed Nazeeh" becomes
 * "އަހްމަދު ނަޒީހު" — the same name, in the other script — never a Dhivehi
 * word that means something. Maldivian names are mostly Arabic-derived and
 * have conventional Thaana spellings, which is exactly the kind of thing a
 * model knows and a lookup table does not.
 *
 * EVERY failure returns null. The caller is a queued job whose only job is to
 * fill in a nullable column; a customer without a Dhivehi name is shown their
 * English one, which is what happened before this existed. Nothing about
 * registration may depend on this succeeding.
 */
final readonly class ClaudeDhivehiNameWriter implements DhivehiNameWriter
{
    /**
     * Thaana occupies U+0780–U+07BF, plus ﷲ (U+FDF2).
     *
     * That one Arabic ligature is deliberate. It is the conventional Dhivehi
     * spelling of Abdulla — އަބްދުﷲ — confirmed by the owner on 2026-08-21,
     * and it is one of the most common names in the Maldives. Rejecting it as
     * "not Thaana" meant every Abdulla silently got no Dhivehi name at all.
     *
     * Only that ligature: a model answering in full Arabic script is still
     * refused, which is what a bare Presentation-Forms range would have let
     * through.
     */
    private const string THAANA = '/^[\x{0780}-\x{07BF}\x{FDF2}\s]+$/u';

    /**
     * A word that OPENS with a vowel sign.
     *
     * U+07A6–U+07B0 are fili — combining vowels that must sit on a consonant.
     * A name starting with one is malformed, and the model does occasionally
     * produce it: the backfill returned "ައަހްމަދު" for Ahmed, a stray fathaa
     * before the alifu. Every character is Thaana, so the block test above
     * passes it happily; this is the check that does not.
     */
    private const string LEADING_FILI = '/(?:^|\s)[\x{07A6}-\x{07B0}]/u';

    public function __construct(
        private ?string $apiKey,
        private string $model,
    ) {}

    public function write(string $englishName): ?string
    {
        $englishName = trim($englishName);

        if ($this->apiKey === null || $this->apiKey === '' || $englishName === '') {
            return null;
        }

        // Up to three asks. A single answer is genuinely variable — measured
        // on 2026-08-21, one name in eight comes back malformed (a dropped
        // leading consonant, an answer that argues with itself), and which
        // name it is changes run to run. Rewording the prompt moved the
        // failure around rather than removing it; retrying removes it, and
        // three asks cost a fraction of a cent against a name the customer
        // would otherwise have to correct by hand.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $written = $this->ask($englishName);

            if ($written !== null) {
                return $written;
            }
        }

        return null;
    }

    private function ask(string $englishName): ?string
    {
        try {
            $message = (new Client(apiKey: $this->apiKey))->messages->create(
                model: $this->model,
                // Room for the reasoning AND the answer. At 200 the thinking
                // consumed the whole budget and the model returned either an
                // empty answer or — worse — a truncated one ("އަޙް" for Ahmed
                // Nazeeh) that passed validation and would have been stored.
                maxTokens: 2000,
                // Transliterating a name needs no deliberation. Low effort
                // keeps the thinking brief rather than disabling it, which on
                // Opus 5 risks leaking internal tags into the answer.
                outputConfig: ['effort' => 'low'],
                system: <<<'PROMPT'
                You transliterate personal names from Latin script into Thaana
                (Dhivehi), as used in the Maldives.

                Rules:
                - Transliterate the SOUND of the name. Never translate its meaning.
                - Use the conventional Maldivian spelling where a name has one:
                  Ahmed is އަހްމަދު, Mohamed is މުހައްމަދު, Aishath is އާއިޝަތު,
                  Fathimath is ފާތިމަތު, Ali is އަލީ, Hassan is ހަސަން,
                  Abdulla is އަބްދުﷲ.
                - Keep every part of the name, in the same order.
                - Answer with the Thaana name and nothing else: no quotes, no
                  Latin letters, no explanation, no punctuation.
                PROMPT,
                messages: [
                    ['role' => 'user', 'content' => $englishName],
                ],
            );

            // A response that ran out of tokens is a response that may be cut
            // mid-name, and a half-written name looks like a real one.
            if ($message->stopReason === 'max_tokens') {
                return null;
            }

            $answer = '';

            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $answer .= $block->text;
                }
            }

            return $this->clean($answer);
        } catch (Throwable $e) {
            // A name we could not write is not an incident. Logged at info so
            // a run of them is visible without paging anybody.
            Log::info('Could not write a name in Thaana', [
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);

            return null;
        }
    }

    /**
     * Refuse anything that is not purely Thaana.
     *
     * A model that answers "The name is އަހްމަދު" or falls back to Latin has
     * not done the job, and storing that would put English back on a Dhivehi
     * screen with extra steps. Better to leave the column null.
     */
    private function clean(string $answer): ?string
    {
        // Invisible characters, dropped rather than refused. The model
        // sometimes prefixes a zero-width space, which is not Thaana, is not
        // visible to anyone, and was costing a perfectly good name.
        $answer = (string) preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $answer);
        $answer = trim(preg_replace('/\s+/u', ' ', $answer) ?? '');

        if ($answer === '' || mb_strlen($answer) > 120) {
            return null;
        }

        // Usually the whole answer is the name. When it is not, the model has
        // reasoned out loud first — "ipraahiimu vaahidu — wait, need Thaana.
        // <newline> އިބްރާހީމް ވަހީދު" — and the name it settled on is still in
        // there. Take the longest run of Thaana rather than discard the lot;
        // anything genuinely unusable has no such run and still returns null.
        if (preg_match(self::THAANA, $answer) !== 1) {
            // Arabic script anywhere (other than the one allowed ligature)
            // means part of the NAME is in it — "عبدﷲ ޝަރީފް". Extracting the
            // Thaana there returns "ﷲ ޝަރީފް": a truncated name that looks
            // real and would be stored silently. Observed 2026-08-21; refuse
            // and let the retry ask again.
            if (preg_match('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDF1}\x{FDF3}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $answer) === 1) {
                return null;
            }

            preg_match_all('/[\x{0780}-\x{07BF}\x{FDF2}][\x{0780}-\x{07BF}\x{FDF2}\s]*/u', $answer, $runs);

            $answer = trim((string) array_reduce(
                $runs[0] ?? [],
                static fn (string $best, string $run): string => mb_strlen($run) > mb_strlen($best) ? $run : $best,
                '',
            ));

            if ($answer === '' || preg_match(self::THAANA, $answer) !== 1) {
                return null;
            }
        }

        return preg_match(self::LEADING_FILI, $answer) === 1 ? null : $answer;
    }
}
