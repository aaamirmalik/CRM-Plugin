<?php
/**
 * Copy this file into your theme as:
 * wp-content/themes/your-theme/single-crm_services.php
 *
 * This template renders a single CRM Service post synced from:
 * GET /public/services and GET /public/services/{id}
 */

if (!defined('ABSPATH')) exit;

get_header();

$archive_url = get_post_type_archive_link('crm_services');
if (!$archive_url) {
    $archive_url = home_url('/services/');
}
?>

<div class="page-container-am single-service-page-am">
    <main class="single-service-main-am section-am">
        <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
        <?php
            $post_id = get_the_ID();
            $crm_service_id = (string) get_post_meta($post_id, '_crm_service_id', true);
            $service_code = (string) get_post_meta($post_id, '_crm_service_code', true);
            $service_duration = (string) get_post_meta($post_id, '_crm_service_duration', true);
            $base_rate = (string) get_post_meta($post_id, '_crm_base_rate', true);
            $category = (string) get_post_meta($post_id, '_crm_category', true);
            $categories = (array) get_post_meta($post_id, '_crm_categories', true);
            $tags = (array) get_post_meta($post_id, '_crm_tags', true);
            $short_description = (string) get_post_meta($post_id, '_crm_short_description', true);
            $service_image_url = (string) get_post_meta($post_id, '_crm_service_image_url', true);
            $service_icon_url = (string) get_post_meta($post_id, '_crm_service_icon_url', true);
            $service_button = function_exists('get_field') ? (string) get_field('service_button_label', $post_id) : '';

            $summary = $short_description !== '' ? $short_description : get_the_excerpt();

            $image_url = '';
            $image_alt = get_the_title();

            if ($service_image_url !== '') {
                $image_url = $service_image_url;
            } elseif ($service_icon_url !== '') {
                $image_url = $service_icon_url;
            } elseif (has_post_thumbnail()) {
                $thumb_id = get_post_thumbnail_id();
                $thumb_src = wp_get_attachment_image_src($thumb_id, 'large');
                if (!empty($thumb_src[0])) {
                    $image_url = $thumb_src[0];
                }
                $thumb_alt = get_post_meta($thumb_id, '_wp_attachment_image_alt', true);
                if (!empty($thumb_alt)) {
                    $image_alt = $thumb_alt;
                }
            }

            $related_query = new WP_Query([
                'post_type' => 'crm_services',
                'post_status' => 'publish',
                'posts_per_page' => 3,
                'post__not_in' => [$post_id],
                'orderby' => 'title',
                'order' => 'ASC',
            ]);
        ?>

        <div class="container-am">
            <nav class="single-breadcrumb-am" aria-label="Breadcrumb">
                <a href="<?php echo esc_url(home_url('/')); ?>">Home</a>
                <span>/</span>
                <a href="<?php echo esc_url($archive_url); ?>">Services</a>
                <span>/</span>
                <span class="single-breadcrumb-current-am"><?php the_title(); ?></span>
            </nav>
        </div>

        <section class="single-service-hero-am">
            <div class="container-am single-service-hero-grid-am">
                <div class="single-service-copy-am">
                    <div class="eyebrow-am">Therapy Service</div>
                    <h1 class="single-service-title-am"><?php the_title(); ?></h1>
                    <?php if (!empty($summary)) : ?>
                        <p class="single-service-summary-am"><?php echo esc_html($summary); ?></p>
                    <?php endif; ?>

                    <div class="single-service-meta-am" style="display:flex; flex-wrap:wrap; gap:8px; margin:14px 0 18px;">
                        <?php if ($service_code !== '') : ?><span class="profile-badge"><?php echo esc_html($service_code); ?></span><?php endif; ?>
                        <?php if ($category !== '') : ?><span class="profile-badge"><?php echo esc_html($category); ?></span><?php endif; ?>
                        <?php if ($service_duration !== '') : ?><span class="profile-badge"><?php echo esc_html($service_duration); ?> min</span><?php endif; ?>
                        <?php if ($base_rate !== '') : ?><span class="profile-badge">$<?php echo esc_html($base_rate); ?></span><?php endif; ?>
                        <?php if (!empty($categories)) : ?><span class="profile-badge"><?php echo esc_html(implode(', ', array_map('strval', $categories))); ?></span><?php endif; ?>
                    </div>

                    <div class="single-service-actions-am">
                        <a href="<?php echo esc_url(function_exists('get_field') ? (get_field('appointment_url', 'option') ?: '#') : '#'); ?>" class="btn-am btn-primary-am">
                            <?php echo esc_html($service_button !== '' ? $service_button : 'Book This Service'); ?>
                        </a>
                        <a href="<?php echo esc_url(function_exists('get_field') ? (get_field('consultation_url', 'option') ?: '#') : '#'); ?>" class="btn-am btn-outline-am">
                            Request Consultation
                        </a>
                    </div>
                </div>

                <?php if (!empty($image_url)) : ?>
                <div class="single-service-media-am">
                    <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($image_alt); ?>" />
                </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="section-am">
            <div class="container-am single-service-content-wrap-am">
                <div class="single-content-am">
                    <?php the_content(); ?>
                </div>
            </div>
        </section>

        <?php if (!empty($tags)) : ?>
        <section class="section-am" style="padding-top:0;">
            <div class="container-am">
                <div class="single-service-tags-am" style="display:flex; flex-wrap:wrap; gap:8px;">
                    <?php foreach ($tags as $tag) : ?>
                        <?php $tag = sanitize_text_field((string) $tag); if ($tag === '') continue; ?>
                        <span class="profile-badge"><?php echo esc_html($tag); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section class="section-am">
            <div class="container-am">
                <div class="single-service-cta-am">
                    <div>
                        <h3>Need help choosing the right support path?</h3>
                        <p>Our intake team can guide you to the best service based on your goals and comfort.</p>
                    </div>
                    <a href="<?php echo esc_url(function_exists('get_field') ? (get_field('appointment_url', 'option') ?: '#') : '#'); ?>" class="btn-am btn-lg-am single-service-cta-btn-am">
                        Start With Intake
                    </a>
                </div>
            </div>
        </section>

        <?php if ($related_query->have_posts()) : ?>
        <section class="single-service-related-am section-am section-muted-am">
            <div class="container-am">
                <div class="single-related-header-am">
                    <h2>Related Services</h2>
                    <a href="<?php echo esc_url($archive_url); ?>" class="btn-am btn-outline-am">All Services</a>
                </div>

                <div class="services-cards-grid-am">
                    <?php while ($related_query->have_posts()) : $related_query->the_post(); ?>
                        <?php
                            $related_id = get_the_ID();
                            $related_image_url = (string) get_post_meta($related_id, '_crm_service_image_url', true);
                            if ($related_image_url === '') {
                                $related_image_url = (string) get_post_meta($related_id, '_crm_service_icon_url', true);
                            }
                            $related_desc = (string) get_post_meta($related_id, '_crm_short_description', true);
                            $card_desc = $related_desc !== '' ? $related_desc : wp_trim_words(get_the_excerpt(), 20);
                            $related_button = function_exists('get_field') ? (string) get_field('service_button_label', $related_id) : '';
                            $related_permalink = get_permalink($related_id);
                        ?>
                        <article class="services-card-am" style="cursor:pointer;" onclick="window.location='<?php echo esc_url($related_permalink); ?>';">
                            <?php if ($related_image_url !== '') : ?>
                                <div class="services-card-image-am">
                                    <img src="<?php echo esc_url($related_image_url); ?>" alt="<?php echo esc_attr(get_the_title()); ?>" />
                                </div>
                            <?php elseif (has_post_thumbnail()) : ?>
                                <div class="services-card-image-am"><?php the_post_thumbnail('medium_large'); ?></div>
                            <?php endif; ?>

                            <div class="services-card-content-am">
                                <h3><?php the_title(); ?></h3>
                                <p><?php echo esc_html($card_desc); ?></p>
                                <a href="<?php echo esc_url($related_permalink); ?>" class="read-more-link-am">
                                    <?php echo esc_html($related_button !== '' ? $related_button : 'Read More'); ?>
                                    <iconify-icon icon="lucide:arrow-right"></iconify-icon>
                                </a>
                            </div>
                        </article>
                    <?php endwhile; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php wp_reset_postdata(); ?>
        <?php endwhile; else : ?>
            <div class="container-am"><p class="single-empty-am">Service not found.</p></div>
        <?php endif; ?>
    </main>
</div>

<?php get_footer(); ?>
