<?php
/**
 * Copy this file into your theme as either:
 * 1) wp-content/themes/your-theme/page-appointment.php
 * 2) wp-content/themes/your-theme/template-appointment.php (as custom page template)
 *
 * If using option 2, keep the Template Name block below.
 *
 * Template Name: Appointment Page
 */

if (!defined('ABSPATH')) exit;

get_header();

while (have_posts()) : the_post();
    $crm_therapist_id = get_post_meta(get_the_ID(), '_crm_therapist_id', true);
    $crm_default_session_type = get_post_meta(get_the_ID(), '_crm_session_type', true);
    if (!in_array($crm_default_session_type, ['online', 'in-person'], true)) {
        $crm_default_session_type = 'online';
    }
    ?>
    <main id="primary" class="site-main">
        <?php
        if (!empty($crm_therapist_id)) {
            echo do_shortcode('[crm_booking_calendar therapist_id="' . esc_attr($crm_therapist_id) . '" session_type="' . esc_attr($crm_default_session_type) . '"]');
        } else {
            echo do_shortcode('[crm_booking_calendar session_type="' . esc_attr($crm_default_session_type) . '"]');
        }
        ?>
    </main>
    <?php
endwhile;

get_footer();

