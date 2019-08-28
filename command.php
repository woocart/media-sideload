<?php
namespace WooCart\CLi\Sideload;

if (!class_exists('WP_CLI')) {
    return;
}

function import_image($img_src, $delete_post = false)
{
    global $wpdb;

    // Most of this was stolen from media_sideload_image
    $tmp = download_url($img_src);

    // Set variables for storage
    // fix file filename for query strings
    preg_match('/[^\?]+\.(jpe?g?|gif|png|webm)\b/i', $img_src, $matches);
    $file_array = array();
    $file_array['name'] = sanitize_file_name(urldecode(basename($matches[0])));
    $file_array['tmp_name'] = $tmp;

    // If error storing temporarily, unlink
    if (is_wp_error($tmp)) {
        @unlink($file_array['tmp_name']);
        $file_array['tmp_name'] = '';
        \WP_CLI::warning($tmp->get_error_message());
        return;
    }

    // do the validation and storage stuff
    $id = media_handle_sideload($file_array, null);

    // If not storing permanently, remove metadata
    if ($delete_post) {
        wp_delete_post($id, true);
    }

    $new_img = wp_get_attachment_image_src($id, 'full');

    return $new_img[0];
}

function replace_posts($domain, $post_type, $verbose)
{
    global $wpdb;

    $where_parts = ["post_content LIKE '%$domain%'"];

    if (!empty($post_type)) {
        $where_parts[] = $wpdb->prepare("post_type = %s", sanitize_key($post_type));
    } else {
        $where_parts[] = "post_type NOT IN ('revision')";
    }

    if (!empty($where_parts)) {
        $where = 'WHERE ' . implode(' AND ', $where_parts);
    } else {
        $where = '';
    }

    $query = "SELECT ID, post_content FROM $wpdb->posts $where";

    foreach (new \WP_CLI\Iterators\Query($query) as $post) {

        $num_sideloaded_images = 0;

        if (empty($post->post_content)) {
            continue;
        }

        preg_match_all('/(?:https?:)(?:[\/|.|\w|\s|-])*\.(?:jpe?g?|gif|png|webm)/im', $post->post_content, $images, PREG_SET_ORDER);

        $img_srcs = array();
        foreach ($images as $img) {

            // Sometimes old content management systems put spaces in the URLs
            $img_src = esc_url_raw(str_replace(' ', '%20', $img[0]));
            if ($domain != parse_url($img_src, PHP_URL_HOST)) {
                continue;
            }

            // Don't permit the same media to be sideloaded twice for this post
            if (in_array($img_src, $img_srcs)) {
                continue;
            }
            $new_img = import_image($img_src);

            if (!empty($new_img)) {
                $post->post_content = str_replace($img[0], $new_img, $post->post_content);
                $num_sideloaded_images++;
                $img_srcs[] = $img_src;

                if ($verbose) {
                    \WP_CLI::line(sprintf("Replaced '%s' with '%s' for post #%d", $img_src, $new_img, $post->ID));
                }
            } else {
                \WP_CLI::line(sprintf("Failed importing %s in #%d", $img_src, $post->ID));
            }
        }

        if ($num_sideloaded_images) {
            $wpdb->update($wpdb->posts, array('post_content' => $post->post_content), array('ID' => $post->ID));
            clean_post_cache($post->ID);
            if ($verbose) {
                \WP_CLI::line(sprintf("Sideloaded %d media references for post #%d", $num_sideloaded_images, $post->ID));
            }
        } else if (!$num_sideloaded_images && $verbose) {
            \WP_CLI::line(sprintf("No media sideloading necessary for post #%d", $post->ID));
        }
    }
}

function replace_attachments($domain, $verbose)
{
    global $wpdb;

    $where_parts = ["guid LIKE '%$domain%'"];
    $where_parts[] = "post_type = 'attachment'";

    if (!empty($where_parts)) {
        $where = 'WHERE ' . implode(' AND ', $where_parts);
    } else {
        $where = '';
    }

    $query = "SELECT ID, guid FROM $wpdb->posts $where";

    foreach (new \WP_CLI\Iterators\Query($query) as $post) {

        $num_sideloaded_images = 0;

        if (empty($post->guid)) {
            continue;
        }

        preg_match_all('/(?:https?:)(?:[\/|.|\w|\s|-])*\.(?:jpe?g?|gif|png|webm)/im', $post->guid, $images, PREG_SET_ORDER);

        $img_srcs = array();
        foreach ($images as $img) {

            // Sometimes old content management systems put spaces in the URLs
            $img_src = esc_url_raw(str_replace(' ', '%20', $img[0]));
            if ($domain != parse_url($img_src, PHP_URL_HOST)) {
                continue;
            }

            // Don't permit the same media to be sideloaded twice for this post
            if (in_array($img_src, $img_srcs)) {
                continue;
            }
            $new_img = import_image($img_src, true);

            if (!empty($new_img)) {
                $post->guid = str_replace($img[0], $new_img, $post->guid);
                $num_sideloaded_images++;
                $img_srcs[] = $img_src;

                if ($verbose) {
                    \WP_CLI::line(sprintf("Replaced '%s' with '%s' for attachment #%d", $img_src, $new_img, $post->ID));
                }
            } else {
                \WP_CLI::line(sprintf("Failed importing %s in #%d", $img_src, $post->ID));
            }
        }

        if ($num_sideloaded_images) {
            $wpdb->update($wpdb->posts, array('guid' => $post->guid), array('ID' => $post->ID));
            clean_post_cache($post->ID);
            if ($verbose) {
                \WP_CLI::line(sprintf("Sideloaded %d media references for attachment #%d", $num_sideloaded_images, $post->ID));
            }
        } else if (!$num_sideloaded_images && $verbose) {
            \WP_CLI::line(sprintf("No media sideloading necessary for attachment #%d", $post->ID));
        }
    }
}

function replace_postmeta($domain, $verbose)
{
    global $wpdb;

    $query = "SELECT meta_id, meta_value FROM $wpdb->postmeta WHERE meta_value LIKE '%$domain%'";

    foreach (new \WP_CLI\Iterators\Query($query) as $post) {

        $num_sideloaded_images = 0;

        if (empty($post->meta_value)) {
            continue;
        }

        preg_match_all('/(?:https?:)(?:[\/|.|\w|\s|-])*\.(?:jpe?g?|gif|png|webm)/im', $post->meta_value, $images, PREG_SET_ORDER);

        $img_srcs = array();
        foreach ($images as $img) {

            // Sometimes old content management systems put spaces in the URLs
            $img_src = esc_url_raw(str_replace(' ', '%20', $img[0]));
            if ($domain != parse_url($img_src, PHP_URL_HOST)) {
                continue;
            }

            // Don't permit the same media to be sideloaded twice for this post
            if (in_array($img_src, $img_srcs)) {
                continue;
            }
            $new_img = import_image($img_src);

            if (!empty($new_img)) {
                $replacer = new \WP_CLI\SearchReplacer($img[0], $new_img, true);
                $post->meta_value = $replacer->run($post->meta_value);
                $num_sideloaded_images++;
                $img_srcs[] = $img_src;

                if ($verbose) {
                    \WP_CLI::line(sprintf("Replaced '%s' with '%s' for meta #%d", $img_src, $new_img, $post->meta_id));
                }
            } else {
                \WP_CLI::line(sprintf("Failed importing %s in #%d", $img_src, $post->meta_id));
            }
        }

        if ($num_sideloaded_images) {
            $wpdb->update($wpdb->postmeta, array('meta_value' => $post->meta_value), array('meta_id' => $post->meta_id));
            if ($verbose) {
                \WP_CLI::line(sprintf("Sideloaded %d media references for meta #%d", $num_sideloaded_images, $post->$meta_id));
            }
        } else if (!$num_sideloaded_images && $verbose) {
            \WP_CLI::line(sprintf("No media sideloading necessary for meta #%d", $post->meta_id));
        }
    }
}

function replace_option($domain, $verbose)
{
    global $wpdb;

    $query = "SELECT option_id, option_value FROM $wpdb->options WHERE option_value LIKE '%$domain%'";

    foreach (new \WP_CLI\Iterators\Query($query) as $post) {

        $num_sideloaded_images = 0;

        if (empty($post->option_value)) {
            continue;
        }

        preg_match_all('/(?:https?:)(?:[\/|.|\w|\s|-])*\.(?:jpe?g?|gif|png|webm)/im', $post->option_value, $images, PREG_SET_ORDER);

        $img_srcs = array();
        foreach ($images as $img) {

            // Sometimes old content management systems put spaces in the URLs
            $img_src = esc_url_raw(str_replace(' ', '%20', $img[0]));
            if ($domain != parse_url($img_src, PHP_URL_HOST)) {
                continue;
            }

            // Don't permit the same media to be sideloaded twice for this post
            if (in_array($img_src, $img_srcs)) {
                continue;
            }
            $new_img = import_image($img_src);

            if (!empty($new_img)) {
                $replacer = new \WP_CLI\SearchReplacer($img[0], $new_img, true);
                $post->option_value = $replacer->run($post->option_value);
                $num_sideloaded_images++;
                $img_srcs[] = $img_src;

                if ($verbose) {
                    \WP_CLI::line(sprintf("Replaced '%s' with '%s' for option #%d", $img_src, $new_img, $post->option_id));
                }
            } else {
                \WP_CLI::line(sprintf("Failed importing %s in #%d", $img_src, $post->option_id));
            }
        }

        if ($num_sideloaded_images) {
            $wpdb->update($wpdb->options, array('option_value' => $post->option_value), array('option_id' => $post->option_id));
            if ($verbose) {
                \WP_CLI::line(sprintf("Sideloaded %d media references for option #%d", $num_sideloaded_images, $post->$option_id));
            }
        } else if (!$num_sideloaded_images && $verbose) {
            \WP_CLI::line(sprintf("No media sideloading necessary for option #%d", $post->option_id));
        }
    }
}

/**
 * Sideload embedded images, and update post content references.
 *
 * Searches through the post_content field for images hosted on remote domains,
 * downloads those it finds into the Media Library, and updates the reference
 * in the post_content field.
 *
 * In more real terms, this command can help "fix" all post references to
 * `<img src="http://remotedomain.com/image.jpg" />` by downloading the image into
 * the Media Library, and updating the post_content to instead use
 * `<img src="http://correctdomain.com/image.jpg" />`.
 *
 * ## OPTIONS
 *
 * <domain>
 * : Specify the domain to sideload images from, because you don't want to sideload images you've already imported.
 *
 * [--post_type=<post-type>]
 * : Only sideload images embedded in the post_content of a specific post type.
 *
 * [--verbose]
 * : Show more information about the process on STDOUT.
 */
$run_sideload_media_command = function ($args, $assoc_args) {
    $domain = array_shift($args);
    $defaults = array(
        'post_type' => '',
        'verbose' => true,
    );
    $assoc_args = array_merge($defaults, $assoc_args);
    $post_type = \WP_CLI\Utils\get_flag_value($assoc_args, 'post_type');
    $verbose = \WP_CLI\Utils\get_flag_value($assoc_args, 'verbose');

    replace_attachments($domain, $verbose);
    replace_posts($domain, $post_type, $verbose);
    replace_postmeta($domain, $verbose);
    replace_option($domain, $verbose);

};

\WP_CLI::add_command('media sideload', $run_sideload_media_command);
