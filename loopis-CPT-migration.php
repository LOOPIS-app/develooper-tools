<?php
/**
 * LOOPIS CPT Migration (LCPTM)
 * 
 * A script to migrate posts from one Wordpress CPT to another.
 * 
 * This script will:
 * - Migrate all posts from the source CPT (custom post type) to the target CPT.
 * - Preserve post IDs, authors, dates, comments, and post meta.
 * - Map source taxonomy terms to target taxonomy terms based on the term slug.
 * - It does not delete the source CPT; it only converts posts to the news CPT.
 *
 * How to use:
 * 1. Replace the example CPT's and taxonomies below ('forum' and 'news') with the actual CPT's.
 * 2. Run the script using WP-CLI with the --dry-run flag first to preview changes:
 *   wp --url=https://staging.loopis.app/12845/ eval-file /path/to/loopis-content/scripts/loopis-CPT-migration.php --dry-run
 *   wp --url=https://staging.loopis.app/12845/ eval-file /path/to/loopis-content/scripts/loopis-CPT-migration.php 
 * 
 * Version: 0.01
 * Author: CoPilot (prompted by Johan Hagvil)

 */

if (!defined('ABSPATH')) {
    if (!function_exists('WP_CLI')) {
        exit("Run this via wp eval-file only.\n");
    }
}

$dry_run = in_array('--dry-run', $_SERVER['argv'] ?? [], true);
$source_post_type = 'forum';
$target_post_type = 'news';
$source_taxonomy = 'forum-category';
$target_taxonomy = 'news-category';
$limit = null;

$term_slug_map = [
    'news'    => 'news',
    'current' => 'current',
    'feedback' => 'feedback',
    'help'    => 'help',
    'start'   => 'start',
    'tips'    => 'tips',
];

foreach ($_SERVER['argv'] ?? [] as $index => $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int) substr($arg, 8);
    }
}

$log = function ($message) {
    if (function_exists('WP_CLI')) {
        WP_CLI::log($message);
        return;
    }

    echo $message . PHP_EOL;
};

$warn = function ($message) {
    if (function_exists('WP_CLI')) {
        WP_CLI::warning($message);
        return;
    }

    echo '[WARNING] ' . $message . PHP_EOL;
};

$forum_ids = get_posts([
    'post_type'      => $source_post_type,
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'orderby'        => 'ID',
    'order'          => 'ASC',
]);

if (empty($forum_ids)) {
    $log("No {$source_post_type} posts found.");
    return;
}

if ($limit !== null && $limit > 0) {
    $forum_ids = array_slice($forum_ids, 0, $limit);
    $log("Limited to " . count($forum_ids) . " {$source_post_type} post(s) for this run.");
} else {
    $log("Found " . count($forum_ids) . " {$source_post_type} post(s)." );
}

foreach ($forum_ids as $post_id) {
    $post = get_post($post_id);

    if (!$post) {
        $warn("Post {$post_id} no longer exists; skipping.");
        continue;
    }

    $source_terms = wp_get_object_terms($post_id, $source_taxonomy, ['fields' => 'slugs']);
    if (is_wp_error($source_terms)) {
        $source_terms = [];
    }
    $source_terms = array_values(array_unique(array_filter((array) $source_terms)));

    if ($dry_run) {
        $log(sprintf(
            'DRY-RUN: post_id=%d | title="%s" | source_terms=%s',
            $post_id,
            $post->post_title,
            !empty($source_terms) ? implode(', ', $source_terms) : 'none'
        ));
        continue;
    }

    $updated = wp_update_post([
        'ID'          => $post_id,
        'post_type'   => $target_post_type,
        'post_status' => $post->post_status,
    ], true);

    if (is_wp_error($updated)) {
        $warn("Failed to convert post {$post_id}: " . $updated->get_error_message());
        continue;
    }

    if (taxonomy_exists($target_taxonomy)) {
        $target_terms = [];

        foreach ($source_terms as $source_slug) {
            $target_slug = $term_slug_map[$source_slug] ?? $source_slug;
            $term = get_term_by('slug', $target_slug, $target_taxonomy);

            if (!$term || is_wp_error($term)) {
                $created = wp_insert_term(
                    ucwords(str_replace('-', ' ', $target_slug)),
                    $target_taxonomy,
                    [
                        'slug' => $target_slug,
                    ]
                );

                if (!is_wp_error($created)) {
                    $term = get_term($created['term_id'], $target_taxonomy);
                }
            }

            if ($term && !is_wp_error($term)) {
                $target_terms[] = (int) $term->term_id;
            }
        }

        $target_terms = array_values(array_unique($target_terms));

        if (!empty($target_terms)) {
            wp_set_object_terms($post_id, $target_terms, $target_taxonomy, false);
        }
    }

    $log(sprintf('Converted post %d (%s) to %s.', $post_id, $post->post_title, $target_post_type));
}

$log("Migration complete. Remember to check the archive URLs and post relations after conversion.");

if (!function_exists('WP_CLI')) {
    echo "Done.\n";
}
