<?php
/**
 * Copy this file into your theme as:
 * wp-content/themes/your-theme/single-crm_therapist.php
 *
 * This renders the therapist profile + booking UI via [crm_profile_booking].
 */

if (!defined('ABSPATH')) exit;

get_header();

while (have_posts()) : the_post();
    $crm_therapist_id = (string) get_post_meta(get_the_ID(), '_crm_therapist_id', true);
    $service_id = function_exists('get_field') ? (string) get_field('service_id', get_the_ID()) : '';
    if ($service_id === '') $service_id = '50';

    $session_type = function_exists('get_field') ? (string) get_field('session_type', get_the_ID()) : '';
    if (!in_array($session_type, ['online', 'in-person'], true)) $session_type = 'online';
    ?>
    <main id="primary" class="site-main">
        <?php
        if ($crm_therapist_id !== '') {
            echo do_shortcode('[crm_profile_booking therapist_id="' . esc_attr($crm_therapist_id) . '" service_id="' . esc_attr($service_id) . '" session_type="' . esc_attr($session_type) . '"]');
        } else {
            echo do_shortcode('[crm_profile_booking therapist_name="' . esc_attr(get_the_title()) . '" service_id="' . esc_attr($service_id) . '" session_type="' . esc_attr($session_type) . '"]');
        }
        ?>
    </main>
    <?php
endwhile;

get_footer();
