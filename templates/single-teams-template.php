<?php
/**
 * Copy this file into your theme as:
 * wp-content/themes/your-theme/single-team.php
 *
 * This renders the API-first profile/booking UI (test.html style)
 * via [crm_profile_booking].
 */

if (!defined('ABSPATH')) exit;

get_header();

while (have_posts()) : the_post();
    $crm_therapist_id = get_post_meta(get_the_ID(), '_crm_therapist_id', true);
    if (empty($crm_therapist_id) && function_exists('get_field')) {
        $crm_therapist_id = get_field('crm_therapist_id', get_the_ID());
        if (empty($crm_therapist_id)) $crm_therapist_id = get_field('therapist_id', get_the_ID());
    }

    $service_id = function_exists('get_field') ? get_field('service_id', get_the_ID()) : '';
    if (empty($service_id)) $service_id = '50';

    $session_type = function_exists('get_field') ? get_field('session_type', get_the_ID()) : '';
    if (!in_array($session_type, ['online', 'in-person'], true)) $session_type = 'online';

    $shortcode_atts = ' service_id="' . esc_attr($service_id) . '" session_type="' . esc_attr($session_type) . '"';
    if (!empty($crm_therapist_id)) {
        $shortcode_atts .= ' therapist_id="' . esc_attr($crm_therapist_id) . '"';
    } else {
        $shortcode_atts .= ' therapist_name="' . esc_attr(get_the_title()) . '"';
    }
    ?>
    <main id="primary" class="site-main">
        <?php echo do_shortcode('[crm_profile_booking' . $shortcode_atts . ']'); ?>
    </main>
    <?php
endwhile;

get_footer();
