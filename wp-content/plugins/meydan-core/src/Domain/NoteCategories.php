<?php

declare(strict_types=1);

namespace Meydan\Core\Domain;

/**
 * Categories a note («یادداشت») can be filed under.
 *
 * The pool is the speaker categories plus a vocabulary that belongs to the notes section alone
 * (managed in the admin panel). Content itself keeps its category in the `meydan_content_category`
 * taxonomy; a term is created the first time a category is used, and follows renames afterwards.
 */
final class NoteCategories
{
    public const TAXONOMY = 'meydan_content_category';
    public const OPTION = 'meydan_note_category_options';

    /** @return array<string,string> slug => name of the categories owned by the notes section */
    public static function own(): array
    {
        $stored = get_option(self::OPTION, []);
        return is_array($stored) ? array_filter(array_map('strval', $stored)) : [];
    }

    /**
     * Every selectable category, speaker categories first.
     *
     * @return list<array{slug:string,name:string,source:string}>
     */
    public static function all(): array
    {
        $out = [];
        $seen = [];
        foreach (SpeakerService::categoryOptions() as $slug => $name) {
            $slug = sanitize_key((string) $slug);
            if ($slug === '' || isset($seen[$slug])) continue;
            $seen[$slug] = true;
            $out[] = ['slug' => $slug, 'name' => (string) $name, 'source' => 'speaker'];
        }
        foreach (self::own() as $slug => $name) {
            $slug = sanitize_key((string) $slug);
            if ($slug === '' || isset($seen[$slug])) continue;
            $seen[$slug] = true;
            $out[] = ['slug' => $slug, 'name' => (string) $name, 'source' => 'note'];
        }
        return $out;
    }

    /** @return array{slug:string,name:string,source:string}|null */
    public static function find(string $slug): ?array
    {
        $slug = sanitize_key($slug);
        foreach (self::all() as $item) {
            if ($item['slug'] === $slug) return $item;
        }
        return null;
    }

    /** The taxonomy term for a category, created (or renamed) on demand. Returns the term id. */
    public static function termId(string $slug): int
    {
        $item = self::find($slug);
        if (!$item) return 0;
        $term = get_term_by('slug', $item['slug'], self::TAXONOMY);
        if ($term instanceof \WP_Term) {
            if ($term->name !== $item['name']) wp_update_term($term->term_id, self::TAXONOMY, ['name' => $item['name']]);
            return (int) $term->term_id;
        }
        $created = wp_insert_term($item['name'], self::TAXONOMY, ['slug' => $item['slug']]);
        return is_wp_error($created) ? 0 : (int) $created['term_id'];
    }

    /**
     * The category a published content item gets: the one chosen, otherwise the publisher's own speaker
     * category when they are a speaker, otherwise none.
     */
    public static function resolve(string $chosen, int $publisherId): int
    {
        if ($chosen !== '') {
            $id = self::termId($chosen);
            if ($id > 0) return $id;
        }
        if ($publisherId > 0) {
            $user = get_userdata($publisherId);
            if ($user && in_array(SpeakerService::ROLE, (array) $user->roles, true)) {
                foreach (SpeakerService::categoriesOf($publisherId) as $category) {
                    $id = self::termId((string) $category['slug']);
                    if ($id > 0) return $id;
                }
            }
        }
        return 0;
    }

    public static function flushHub(): void
    {
        delete_transient('meydan_hub_notes');
    }
}
