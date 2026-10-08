<?php

declare(strict_types=1);

namespace Meydan\Core\Support;

/** Hashtags typed inline in a narrative's body (`#برچسب`), extracted into tag names. */
final class Hashtags
{
    private const MAX_TAGS = 10;
    private const MIN_LENGTH = 2;
    private const MAX_LENGTH = 64;

    /**
     * `#` tokens of letters, digits or underscore, not glued to the end of a
     * word (so `html#id` inside a pasted link is not mistaken for a tag).
     * Deduplicated case-sensitively, in first-seen order, capped at 10.
     *
     * @return string[]
     */
    public static function extract(string $body): array
    {
        if (!preg_match_all('/(?:^|[\s\x{060C}\x{061B}.,!?؟])#([\p{L}\p{N}_]{' . self::MIN_LENGTH . ',' . self::MAX_LENGTH . '})/u', $body, $matches)) {
            return [];
        }

        $tags = [];
        foreach ($matches[1] as $tag) {
            $tag = trim($tag);
            if ($tag === '' || isset($tags[$tag])) {
                continue;
            }
            $tags[$tag] = true;
            if (count($tags) >= self::MAX_TAGS) {
                break;
            }
        }

        return array_keys($tags);
    }

    /**
     * Explicit tags (already client-provided, e.g. from a future dedicated
     * tag picker) merged with whatever hashtags the body itself contains.
     *
     * @param string[] $explicit
     * @return string[]
     */
    public static function merge(array $explicit, string $body): array
    {
        $explicit = array_values(array_filter(array_map('sanitize_text_field', $explicit)));
        $fromBody = self::extract($body);
        return array_values(array_unique(array_merge($explicit, $fromBody)));
    }
}
