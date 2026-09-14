<?php

declare(strict_types=1);

namespace AIArmada\Growth\Support;

/**
 * Canonical anonymous subject keys.
 *
 * Assignment storage and attribution lookups must agree on the key shape:
 * short identifiers inline, long identifiers hashed. Centralizing the rule
 * keeps long anonymous identifiers attributable on both paths.
 */
final class AnonymousSubjectKey
{
    public const int MAX_LENGTH = 255;

    public const string PREFIX = 'anonymous:';

    public const string HASHED_PREFIX = 'anonymous:sha256:';

    public static function make(string $anonymousId): ?string
    {
        $normalizedAnonymousId = mb_trim($anonymousId);

        if ($normalizedAnonymousId === '') {
            return null;
        }

        $subjectKey = self::PREFIX . $normalizedAnonymousId;

        if (mb_strlen($subjectKey) <= self::MAX_LENGTH) {
            return $subjectKey;
        }

        return self::HASHED_PREFIX . hash('sha256', $normalizedAnonymousId);
    }
}
