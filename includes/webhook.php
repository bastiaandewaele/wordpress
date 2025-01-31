<?php

namespace Storychief\Webhook;

use WP_REST_Request;
use WP_Error;

function register_routes() {
    register_rest_route('storychief', 'webhook', array(
        'methods'  => 'POST',
        'callback' =>  __NAMESPACE__ . '\handle',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', __NAMESPACE__ . '\register_routes');


/**
 * The Main webhook function, orchestrates the requested event to its corresponding function.
 *
 * @param WP_REST_Request $request
 * @return mixed
 */
function handle(WP_REST_Request $request) {
    // We do this because some badly configured servers will return notices and warnings
    // that get prepended or appended to the rest response.
    error_reporting(0);

    $payload = json_decode($request->get_body(), true);

    if (!\Storychief\Tools\validMac($payload)) return new WP_Error('invalid_mac', 'The Mac is invalid', array('status' => 400));
    if (!isset($payload['meta']['event'])) return new WP_Error('no_event_type', 'The event is not set', array('status' => 400));

    $payload = apply_filters('storychief_before_handle_filter', $payload);

    if (isset($payload['meta']['fb-page-ids'])) {
        \Storychief\Settings\update_sc_option('meta_fb_pages', $payload['meta']['fb-page-ids']);
    }

    switch ($payload['meta']['event']) {
        case 'publish':
            $response = handlePublish($payload);
            break;
        case 'update':
            $response = handleUpdate($payload);
            break;
        case 'delete':
            $response = handleDelete($payload);
            break;
        case 'test':
            $response = handleConnectionCheck($payload);
            break;
        default:
            $response = missingMethod();
            break;
    }

    if (is_wp_error($response)) return $response;

    $response = apply_filters('storychief_alter_response', $response);

    if (!is_null($response)) $response  = \Storychief\Tools\appendMac($response);

    return rest_ensure_response($response);
}

/**
 * Handle a publish webhook call
 *
 * @param $payload
 * @return array
 */
function handlePublish($payload) {
    $story = $payload['data'];

    // Before publish action
    do_action('storychief_before_publish_action', array_merge($story));

    $is_draft = (bool)\Storychief\Settings\get_sc_option('test_mode');
    $is_draft = apply_filters('storychief_is_draft_status', $is_draft, $story);

    $post_type = \Storychief\Settings\get_sc_option('post_type') ? \Storychief\Settings\get_sc_option('post_type') : 'post';
    $post_type = apply_filters('storychief_change_post_type', $post_type, $story);

    $post = array(
        'post_type'    => $post_type,
        'post_title'   => $story['title'],
        'post_content' => $story['content'],
        'post_excerpt' => $story['excerpt'] ? $story['excerpt'] : '',
        'post_status'  => $is_draft ? 'draft' : 'publish',
        'post_author'  => null,
        'meta_input'   => array(),
    );

    // Set the slug
    if (isset($story['seo_slug']) && !empty($story['seo_slug'])) {
        $post['post_name'] = $story['seo_slug'];
    }

    if (isset($story['amphtml'])) {
        $post['meta_input']['_amphtml'] = $story['amphtml'];
    }

    $post_ID = safely_upsert_story($post);

    $story = array_merge($story, array('external_id' => $post_ID));

    // Author
    do_action('storychief_save_author_action', $story);

    // Tags
    do_action('storychief_save_tags_action', $story);

    // Categories
    do_action('storychief_save_categories_action', $story);

    // Featured Image
    do_action('storychief_save_featured_image_action', $story);

    // SEO
    do_action('storychief_save_seo_action', $story);

    // Sideload images
    do_action('storychief_sideload_images_action', $post_ID);

    // After publish action
    do_action('storychief_after_publish_action', $story);

    // generic WP cache flush scoped to a post ID.
    // well behaved caching plugins listen for this action.
    // WPEngine (which caches outside of WP) also listens for this action.
    clean_post_cache($post_ID);

    $permalink = \Storychief\Tools\getPermalink($post_ID);

    return array(
        'id'        => $post_ID,
        'permalink' => $permalink,
    );
}

/**
 * Handle a update webhook call
 *
 * @param $payload
 * @return array|WP_Error
 */
function handleUpdate($payload) {
    $story = $payload['data'];

    if (!get_post_status($story['external_id'])) {
        return new WP_Error('post_not_found', 'The post could not be found', array('status' => 404));
    }

    // Before publish action
    do_action('storychief_before_publish_action', array_merge($story));

    $is_test_mode = (bool)\Storychief\Settings\get_sc_option('test_mode');
    $is_draft = apply_filters('storychief_is_draft_status', $is_test_mode, $story);

    $post = array(
        'ID'           => $story['external_id'],
        'post_title'   => $story['title'],
        'post_content' => $story['content'],
        'post_excerpt' => $story['excerpt'] ? $story['excerpt'] : '',
        'post_status'  => $is_draft ? 'draft' : 'publish',
        'meta_input'   => array(),
    );

    // Set the slug
    if (isset($story['seo_slug']) && !empty($story['seo_slug'])) {
        $post['post_name'] = $story['seo_slug'];
    }

    if (isset($story['amphtml'])) {
        $post['meta_input']['_amphtml'] = $story['amphtml'];
    }

    $post_ID = safely_upsert_story($post);

    $story = array_merge($story, array('external_id' => $post_ID));

    // Author
    do_action('storychief_save_author_action', $story);

    // Tags
    do_action('storychief_save_tags_action', $story);

    // Categories
    do_action('storychief_save_categories_action', $story);

    // Featured Image
    do_action('storychief_save_featured_image_action', $story);

    // SEO
    do_action('storychief_save_seo_action', $story);

    // Sideload images
    do_action('storychief_sideload_images_action', $post_ID);

    // After publish action
    do_action('storychief_after_publish_action', $story);

    // generic WP cache flush scoped to a post ID.
    // well behaved caching plugins listen for this action.
    // WPEngine (which caches outside of WP) also listens for this action.
    clean_post_cache($post_ID);

    $permalink = \Storychief\Tools\getPermalink($post_ID);

    return array(
        'id'        => $post_ID,
        'permalink' => $permalink,
    );
}

/**
 * Handle a delete webhook call
 *
 * @param $payload
 * @return array
 */
function handleDelete($payload) {
    $story = $payload['data'];
    $post_ID = $story['external_id'];
    wp_delete_post($post_ID);

    do_action('storychief_after_delete_action', $story);

    return array(
        'id'        => $story['external_id'],
        'permalink' => null,
    );
}

/**
 * Handle a connection test webhook call
 * @param $payload
 * @return array
 */
function handleConnectionCheck($payload) {
    $story = $payload['data'];

    do_action('storychief_after_test_action', $story);

    return array();
}


/**
 * Handle calls to missing methods on the controller.
 *
 * @return mixed
 */
function missingMethod() {
    return;
}

/**
 * Safely save a story by disabling & re-enabling sanitation.
 *
 * @param $data
 * @return int
 */
function safely_upsert_story ($data) {
    // disable sanitation
    kses_remove_filters();

    if(isset($data['ID'])) {
        $post_ID = wp_update_post($data);
    } else {
        $post_ID = wp_insert_post($data);
    }

    // enable sanitation
    kses_init_filters();

    return $post_ID;
}
