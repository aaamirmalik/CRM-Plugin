<?php
/**
 * Plugin Name: CRM Connector Pro
 * Description: Premium CRM Integration Dashboard for therapist and appointment booking workflows.
 * Version: 10.0
 * Author: Abiha Khan
 */

if (!defined('ABSPATH')) exit;

define('CRM_BOOKING_PATH', plugin_dir_path(__FILE__));
define('CRM_BOOKING_URL', plugin_dir_url(__FILE__));

require_once CRM_BOOKING_PATH . 'includes/class-api-handler.php';
require_once CRM_BOOKING_PATH . 'includes/admin-setting.php';
require_once CRM_BOOKING_PATH . 'includes/class-profile-booking-shortcode.php';
require_once CRM_BOOKING_PATH . 'includes/class-booking-flow-shortcodes.php';

if (!function_exists('crm_booking_get_client_ip')) {
    function crm_booking_get_client_ip() {
        if (empty($_SERVER['REMOTE_ADDR'])) return '0.0.0.0';
        return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
    }
}

if (!function_exists('crm_booking_is_rate_limited')) {
    function crm_booking_is_rate_limited($key, $limit = 10, $window = 60) {
        $transient_key = 'crm_rate_' . md5($key);
        $count = (int) get_transient($transient_key);
        $count++;
        set_transient($transient_key, $count, max(1, (int) $window));
        return $count > (int) $limit;
    }
}

if (!function_exists('crm_get_cached_therapists')) {
    function crm_get_cached_therapists() {
        $q = new WP_Query([
            'post_type' => 'crm_therapist',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        if (empty($q->posts)) return [];

        $out = [];
        foreach ($q->posts as $post_id) {
            $crm_id = (string) get_post_meta($post_id, '_crm_therapist_id', true);
            if ($crm_id === '') continue;
            $out[] = [
                'id' => $crm_id,
                'fullName' => get_the_title($post_id),
                'email' => (string) get_post_meta($post_id, '_crm_email', true),
                'yearsOfExperience' => (string) get_post_meta($post_id, '_crm_years_of_experience', true),
            ];
        }
        return $out;
    }
}

if (!function_exists('crm_get_cached_therapist_profile')) {
    function crm_get_cached_therapist_profile($crm_id = '') {
        $crm_id = sanitize_text_field((string) $crm_id);
        if ($crm_id === '') return [];

        $q = new WP_Query([
            'post_type' => 'crm_therapist',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_crm_therapist_id',
                    'value' => $crm_id,
                    'compare' => '=',
                ]
            ],
            'no_found_rows' => true,
        ]);

        if (empty($q->posts)) return [];
        $post_id = (int) $q->posts[0];

        return [
            'fullName' => get_the_title($post_id),
            'email' => (string) get_post_meta($post_id, '_crm_email', true),
            'yearsOfExperience' => (string) get_post_meta($post_id, '_crm_years_of_experience', true),
            'licenseType' => (string) get_post_meta($post_id, '_crm_license_type', true),
            'languages' => (array) get_post_meta($post_id, '_crm_languages', true),
            'specializations' => (array) get_post_meta($post_id, '_crm_specializations', true),
            'approaches' => (array) get_post_meta($post_id, '_crm_treatment_approaches', true),
            'role' => (string) get_post_meta($post_id, '_crm_role', true),
            'clientFocus' => (string) get_post_meta($post_id, '_crm_client_focus', true),
            'imageUrl' => (string) get_post_meta($post_id, '_crm_avatar_url', true),
            'phone' => (string) get_post_meta($post_id, '_crm_phone', true),
            'bioHtml' => (string) get_post_meta($post_id, '_crm_bio_html', true),
            'arabicBioHtml' => (string) get_post_meta($post_id, '_crm_bio_arabic_html', true),
            'turkishBioHtml' => (string) get_post_meta($post_id, '_crm_bio_turkish_html', true),
            'education' => (array) get_post_meta($post_id, '_crm_education', true),
        ];
    }
}

if (!function_exists('crm_booking_register_therapist_post_type_for_rewrite')) {
    function crm_booking_register_therapist_post_type_for_rewrite() {
        register_post_type('crm_therapist', [
            'labels' => [
                'name' => 'Therapists',
                'singular_name' => 'Therapist',
                'menu_name' => 'Therapists',
                'add_new_item' => 'Add Therapist',
                'edit_item' => 'Edit Therapist',
            ],
            'public' => true,
            'publicly_queryable' => true,
            'has_archive' => true,
            'rewrite' => ['slug' => 'therapists'],
            'exclude_from_search' => false,
            'show_ui' => true,
            'show_in_menu' => 'crm-dashboard',
            'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }
}

if (!function_exists('crm_booking_plugin_activate')) {
    function crm_booking_plugin_activate() {
        crm_booking_register_therapist_post_type_for_rewrite();
        flush_rewrite_rules();
    }
}

if (!function_exists('crm_booking_plugin_deactivate')) {
    function crm_booking_plugin_deactivate() {
        flush_rewrite_rules();
    }
}

register_activation_hook(__FILE__, 'crm_booking_plugin_activate');
register_deactivation_hook(__FILE__, 'crm_booking_plugin_deactivate');

class CRM_Connector_Core {
    public function __construct() {
        add_action('init', [$this, 'register_therapist_cpt']);
        add_action('init', [$this, 'maybe_sync_therapists_cache']);
        add_action('admin_post_crm_sync_therapists_cache', [$this, 'handle_manual_therapist_sync']);
        add_action('admin_menu', [$this, 'create_menu']);
        add_action('admin_menu', [$this, 'reorder_crm_submenu'], 999);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('add_meta_boxes', [$this, 'register_therapist_profile_metabox']);
        
        add_action('wp_ajax_crm_mark_notifications_read', [$this, 'ajax_mark_notifications_read']);
        add_action('wp_ajax_save_crm_settings_action', [$this, 'save_crm_settings_via_ajax']);

        // AJAX Handlers for CRM API Actions
        add_action('wp_ajax_get_api_slots', [$this, 'ajax_get_slots']);
        add_action('wp_ajax_nopriv_get_api_slots', [$this, 'ajax_get_slots']);
        add_action('wp_ajax_submit_direct_booking', [$this, 'ajax_submit_booking']);

        // NEW: AJAX Handler for Therapist Profile Popup (API #2)
        add_action('wp_ajax_get_therapist_profile_action', [$this, 'ajax_get_therapist_profile']);

        // NEW: AJAX Handler for Frontend Website Submissions
        add_action('wp_ajax_frontend_crm_booking_submit', [$this, 'handle_frontend_booking']);
        add_action('wp_ajax_nopriv_frontend_crm_booking_submit', [$this, 'handle_frontend_booking']);

        // Register Shortcode [crm_form] - Updated for Premium UI
        add_shortcode('crm_form', [$this, 'render_crm_shortcode']);
        add_shortcode('crm_booking', [$this, 'render_crm_shortcode']); // Backward compatibility
    }

    public function create_menu() {
        $menu_cap = 'edit_posts';
        add_menu_page('CRM Connector', 'CRM Connector', $menu_cap, 'crm-dashboard', 'crm_dashboard_page', 'dashicons-rest-api', 2);
        add_submenu_page('crm-dashboard', 'CRM Dashboard', 'Dashboard', $menu_cap, 'crm-dashboard', 'crm_dashboard_page');
        add_submenu_page('options-general.php', 'CRM Connector', 'CRM Connector', $menu_cap, 'crm-dashboard-settings', 'crm_dashboard_page');
    }

    public function reorder_crm_submenu() {
        global $submenu;
        if (empty($submenu['crm-dashboard']) || !is_array($submenu['crm-dashboard'])) return;

        $items = $submenu['crm-dashboard'];
        $dashboard = [];
        $therapists = [];
        $others = [];

        foreach ($items as $item) {
            $slug = isset($item[2]) ? (string) $item[2] : '';
            if ($slug === 'crm-dashboard') {
                $dashboard[] = $item;
            } elseif ($slug === 'edit.php?post_type=crm_therapist') {
                $therapists[] = $item;
            } else {
                $others[] = $item;
            }
        }

        $submenu['crm-dashboard'] = array_merge($dashboard, $therapists, $others);
    }

    public function enqueue_admin_assets($hook) {
        if (strpos((string) $hook, 'crm-dashboard') === false) return;
        wp_enqueue_style('crm-admin-ui', CRM_BOOKING_URL . 'assets/style.css');
        wp_enqueue_script('iconify', 'https://code.iconify.design/iconify-icon/1.0.7/iconify-icon.min.js', [], null, true);
        wp_enqueue_script('jquery');
    }

    public function enqueue_frontend_assets() {
        wp_enqueue_script('jquery');
    }

    public function register_team_mapping_metabox() {
        add_meta_box(
            'crm_team_mapping_box',
            'CRM Therapist Mapping',
            [$this, 'render_team_mapping_metabox'],
            'team',
            'side',
            'default'
        );
    }

    public function render_team_mapping_metabox($post) {
        wp_nonce_field('crm_team_mapping_nonce_action', 'crm_team_mapping_nonce');
        $selected_id = get_post_meta($post->ID, '_crm_therapist_id', true);
        $therapists = crm_get_cached_therapists();
        if (empty($therapists)) {
            $this->sync_therapists_to_posts(true);
            $therapists = crm_get_cached_therapists();
        }
        if (!is_array($therapists)) $therapists = [];
        $sync_url = wp_nonce_url(admin_url('admin-post.php?action=crm_sync_therapists_cache'), 'crm_sync_therapists_cache');
        ?>
        <p style="margin-bottom:8px;">
            <label for="crm_therapist_id"><strong>Linked CRM Therapist</strong></label>
        </p>
        <select name="crm_therapist_id" id="crm_therapist_id" style="width:100%;">
            <option value="">-- Select therapist --</option>
            <?php foreach ($therapists as $t): ?>
                <?php
                $id = isset($t['id']) ? (string) $t['id'] : '';
                $name = isset($t['fullName']) ? $t['fullName'] : $id;
                if ($id === '') continue;
                ?>
                <option value="<?php echo esc_attr($id); ?>" <?php selected((string)$selected_id, $id); ?>>
                    <?php echo esc_html($name); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p style="margin-top:8px; color:#666;">
            This connects this Team post to one CRM therapist profile/booking availability.
        </p>
        <p style="margin-top:10px;">
            <a class="button button-small" href="<?php echo esc_url($sync_url); ?>">Sync Therapist Data</a>
        </p>
        <?php
    }

    public function save_team_mapping_metabox($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['crm_team_mapping_nonce']) || !wp_verify_nonce($_POST['crm_team_mapping_nonce'], 'crm_team_mapping_nonce_action')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $therapist_id = isset($_POST['crm_therapist_id']) ? sanitize_text_field($_POST['crm_therapist_id']) : '';
        if ($therapist_id === '') {
            delete_post_meta($post_id, '_crm_therapist_id');
        } else {
            update_post_meta($post_id, '_crm_therapist_id', $therapist_id);
        }
    }

    public function register_therapist_profile_metabox() {
        add_meta_box(
            'crm_therapist_profile_box',
            'CRM Profile Data',
            [$this, 'render_therapist_profile_metabox'],
            'crm_therapist',
            'normal',
            'high'
        );
    }

    public function render_therapist_profile_metabox($post) {
        $crm_id = (string) get_post_meta($post->ID, '_crm_therapist_id', true);
        $email = (string) get_post_meta($post->ID, '_crm_email', true);
        $years = (string) get_post_meta($post->ID, '_crm_years_of_experience', true);
        $license = (string) get_post_meta($post->ID, '_crm_license_type', true);
        $role = (string) get_post_meta($post->ID, '_crm_role', true);
        $phone = (string) get_post_meta($post->ID, '_crm_phone', true);
        $languages = (array) get_post_meta($post->ID, '_crm_languages', true);
        $specializations = (array) get_post_meta($post->ID, '_crm_specializations', true);
        $approaches = (array) get_post_meta($post->ID, '_crm_treatment_approaches', true);
        $raw = get_post_meta($post->ID, '_crm_profile_raw', true);
        ?>
        <table class="widefat striped" style="margin-bottom:12px;">
            <tbody>
                <tr><th style="width:180px;">CRM Therapist ID</th><td><?php echo esc_html($crm_id ?: '-'); ?></td></tr>
                <tr><th>Email</th><td><?php echo esc_html($email ?: '-'); ?></td></tr>
                <tr><th>Experience</th><td><?php echo esc_html($years ?: '-'); ?></td></tr>
                <tr><th>License</th><td><?php echo esc_html($license ?: '-'); ?></td></tr>
                <tr><th>Role</th><td><?php echo esc_html($role ?: '-'); ?></td></tr>
                <tr><th>Phone</th><td><?php echo esc_html($phone ?: '-'); ?></td></tr>
                <tr><th>Languages</th><td><?php echo esc_html(!empty($languages) ? implode(', ', $languages) : '-'); ?></td></tr>
                <tr><th>Specializations</th><td><?php echo esc_html(!empty($specializations) ? implode(', ', $specializations) : '-'); ?></td></tr>
                <tr><th>Approaches</th><td><?php echo esc_html(!empty($approaches) ? implode(', ', $approaches) : '-'); ?></td></tr>
            </tbody>
        </table>
        <p><strong>Raw API Payload (for debugging):</strong></p>
        <textarea readonly style="width:100%; min-height:220px; font-family:monospace;"><?php echo esc_textarea(wp_json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea>
        <?php
    }

    /**
     * Renders the Premium Scheduling Form (Matches Uploaded Image)
     */
    public function render_crm_shortcode($atts) {
        $api = new CRM_API_Handler();
        $therapists = $api->get_all_therapists();
        if (empty($therapists) || !is_array($therapists)) {
            $therapists = crm_get_cached_therapists();
        } else {
            $this->sync_therapists_to_posts(false, $therapists);
        }
        if (!is_array($therapists)) $therapists = [];
        
        ob_start(); ?>
        <style>
            .crm-premium-form { background: #fff; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); padding: 30px; max-width: 650px; font-family: 'Inter', sans-serif; border: 1px solid #e2e8f0; margin: 20px auto; }
            .crm-premium-form h2 { margin-top: 0; font-size: 20px; color: #1e293b; margin-bottom: 25px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; }
            .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px; }
            .form-item label { display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 8px; }
            .form-item select, .form-item input, .form-item textarea { width: 100%; padding: 12px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 14px; color: #1e293b; background: #fff; transition: 0.2s; }
            .form-item select:focus, .form-item input:focus { border-color: #3b82f6; outline: none; }
            .dur-container { display: flex; gap: 10px; margin-top: 5px; }
            .dur-pill { padding: 10px 15px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 13px; font-weight: 700; color: #64748b; cursor: pointer; transition: 0.2s; background: #fff; }
            .dur-pill.active { background: #3b82f6; color: #fff; border-color: #3b82f6; }
            .btn-group-submit { display: flex; justify-content: flex-end; gap: 12px; margin-top: 30px; border-top: 1px solid #f1f5f9; padding-top: 20px; }
            .btn-cancel { padding: 12px 25px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; font-weight: 600; cursor: pointer; color: #64748b; }
            .btn-primary-crm { padding: 12px 30px; background: #3b82f6; color: #fff; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.3); }
            .zoom-toggle { display: flex; align-items: center; justify-content: space-between; background: #f8fafc; padding: 15px; border-radius: 10px; border: 1px solid #e2e8f0; margin-top: 20px; }
        </style>

        <div class="crm-premium-form">
            <h2>Schedule New Session</h2>
            <form id="crm-frontend-premium-booking">
                <div class="form-grid-2">
                    <div class="form-item">
                        <label>Client *</label>
                        <input type="text" name="fullName" placeholder="Select client" required>
                    </div>
                    <div class="form-item">
                        <label>Therapist *</label>
                        <select name="therapistId" required>
                            <option value="">Select therapist</option>
                            <?php foreach($therapists as $t): ?>
                                <option value="<?php echo esc_attr($t['id']); ?>"><?php echo esc_html($t['fullName']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-item" style="margin-bottom: 20px;">
                    <label>Email *</label>
                    <input type="email" name="email" placeholder="client@example.com" required>
                </div>

                <div class="form-item" style="margin-bottom: 20px;">
                    <label>Service *</label>
                    <select name="serviceId" required>
                        <option value="">Select service</option>
                        <option value="50">Psychotherapy (PR) session 60 minutes (MVA) - $149.61 (60min)</option>
                        <option value="51">Initial Consultation 30 min - Free</option>
                    </select>
                </div>

                <div class="form-grid-2">
                    <div class="form-item">
                        <label>Date *</label>
                        <input type="date" name="sessionDate" required>
                    </div>
                    <div class="form-item">
                        <label>Room *</label>
                        <select name="room">
                            <option value="">Select room</option>
                            <option>Room 102 - Psych-B</option>
                            <option>Virtual Meeting Room</option>
                        </select>
                    </div>
                </div>

                <div class="form-item" style="margin-top:15px;">
                    <label>Time *</label>
                    <input type="text" name="sessionTime" placeholder="Select time (e.g. 11:00 PM)" required>
                </div>

                <div class="form-item" style="margin-top:20px;">
                    <label>Duration (minutes)</label>
                    <div class="dur-container">
                        <div class="dur-pill" data-val="30">30</div>
                        <div class="dur-pill" data-val="45">45</div>
                        <div class="dur-pill active" data-val="60">60</div>
                        <div class="dur-pill" data-val="90">90</div>
                        <div class="dur-pill" data-val="120">120</div>
                        <input type="hidden" name="duration" id="crm-duration-input" value="60">
                    </div>
                </div>

                <div class="form-item" style="margin-top:20px;">
                    <label>Notes (optional)</label>
                    <textarea name="notes" placeholder="Session notes or special instructions" rows="3"></textarea>
                </div>

                <div class="zoom-toggle">
                    <div>
                        <strong style="display:block; font-size:14px;">Enable Virtual Meeting (Zoom)</strong>
                        <small style="color:#64748b;">Create a Zoom meeting for this session.</small>
                    </div>
                    <input type="checkbox" name="isZoom" style="width:20px; height:20px;">
                </div>

                <div class="btn-group-submit">
                    <button type="button" class="btn-cancel">Cancel</button>
                    <button type="submit" class="btn-primary-crm" id="crm-premium-btn">Schedule Session</button>
                </div>
            </form>
        </div>

        <script>
            jQuery(document).ready(function($) {
                $('.dur-pill').click(function() {
                    $('.dur-pill').removeClass('active');
                    $(this).addClass('active');
                    $('#crm-duration-input').val($(this).data('val'));
                });

                $('#crm-frontend-premium-booking').on('submit', function(e) {
                    e.preventDefault();
                    const btn = $('#crm-premium-btn');
                    btn.text('Scheduling...').prop('disabled', true);
                    
                    const data = Object.fromEntries(new FormData(this).entries());
                    data.sessionType = "online";
                    
                    $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                        action: 'frontend_crm_booking_submit',
                        nonce: '<?php echo esc_js(wp_create_nonce('crm_public_booking_nonce')); ?>',
                        booking_data: data
                    }, function(res) {
                        if(res.appointment) {
                            alert('Success! Session scheduled. ID: ' + res.appointment.id);
                            location.reload();
                        } else {
                            alert('Error: ' + (res.message || 'Submission failed.'));
                            btn.text('Schedule Session').prop('disabled', false);
                        }
                    });
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Handles frontend submission by calling the API Handler
     */
    public function handle_frontend_booking() {
        if (!$this->verify_public_ajax_request('frontend_crm_booking_submit', 12, MINUTE_IN_SECONDS)) {
            return;
        }
        $api = new CRM_API_Handler();
        $data = isset($_POST['booking_data']) && is_array($_POST['booking_data']) ? $_POST['booking_data'] : [];
        $payload = $this->sanitize_frontend_booking_payload($data);
        if (is_wp_error($payload)) {
            wp_send_json(['ok' => false, 'message' => $payload->get_error_message()], 400);
        }

        $slot_res = $api->get_slots($payload['therapistId'], $payload['sessionDate'], $payload['sessionType']);
        $slots = (is_array($slot_res) && !empty($slot_res['slots']) && is_array($slot_res['slots'])) ? $slot_res['slots'] : [];
        $time_available = false;
        foreach ($slots as $slot) {
            if (!is_array($slot)) continue;
            $slot_time = isset($slot['time']) ? sanitize_text_field((string) $slot['time']) : '';
            if ($slot_time !== '' && strcasecmp($slot_time, $payload['sessionTime']) === 0 && !empty($slot['available'])) {
                $time_available = true;
                break;
            }
        }
        if (!$time_available) {
            wp_send_json(['ok' => false, 'message' => 'Selected time is no longer available. Please choose another slot.'], 409);
        }

        $result = $api->book_appointment($payload);
        if (!is_array($result)) {
            wp_send_json(['ok' => false, 'message' => 'Booking request failed. Please try again.'], 502);
        }
        wp_send_json($result);
    }

    /**
     * AJAX method to fetch full therapist profile details
     */
    public function ajax_get_therapist_profile() {
        if (!$this->verify_admin_ajax_request()) return;
        $id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';
        $api = new CRM_API_Handler();
        $profile = $api->get_therapist_profile($id);
        wp_send_json($profile);
    }

    // New AJAX method to fetch real slots
    public function ajax_get_slots() {
        if (!$this->verify_public_or_admin_nonce()) return;
        if (crm_booking_is_rate_limited('shared_slots|' . crm_booking_get_client_ip(), 60, MINUTE_IN_SECONDS)) {
            wp_send_json(['slots' => [], 'message' => 'Too many requests.'], 429);
        }
        $api = new CRM_API_Handler();
        $id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'online';
        $slots = $api->get_slots($id, $date, $type);
        wp_send_json($slots);
    }

    // New AJAX method to submit manual booking
    public function ajax_submit_booking() {
        if (!$this->verify_admin_ajax_request()) return;
        $api = new CRM_API_Handler();
        $data = isset($_POST['booking_data']) && is_array($_POST['booking_data']) ? $_POST['booking_data'] : [];
        $payload = $this->sanitize_frontend_booking_payload($data);
        if (is_wp_error($payload)) {
            wp_send_json(['ok' => false, 'message' => $payload->get_error_message()], 400);
        }

        $slot_res = $api->get_slots($payload['therapistId'], $payload['sessionDate'], $payload['sessionType']);
        $slots = (is_array($slot_res) && !empty($slot_res['slots']) && is_array($slot_res['slots'])) ? $slot_res['slots'] : [];
        $time_available = false;
        foreach ($slots as $slot) {
            if (!is_array($slot)) continue;
            $slot_time = isset($slot['time']) ? sanitize_text_field((string) $slot['time']) : '';
            if ($slot_time !== '' && strcasecmp($slot_time, $payload['sessionTime']) === 0 && !empty($slot['available'])) {
                $time_available = true;
                break;
            }
        }
        if (!$time_available) {
            wp_send_json(['ok' => false, 'message' => 'Selected time is no longer available. Please choose another slot.'], 409);
        }

        $result = $api->book_appointment($payload);
        if (is_array($result) && !empty($result['appointment']['id'])) {
            wp_send_json($result);
        }

        $booking_error = $api->get_last_error();
        $message = is_array($result) && !empty($result['message'])
            ? sanitize_text_field((string) $result['message'])
            : ($booking_error !== '' ? $booking_error : 'Booking rejected by CRM.');
        wp_send_json(['ok' => false, 'message' => $message], 502);
    }

    public function ajax_mark_notifications_read() {
        if (!$this->verify_admin_ajax_request()) return;
        $logs = get_option('crm_sync_logs', []);
        if (!is_array($logs) || empty($logs)) {
            wp_send_json_success(['unread' => 0]);
        }

        $first = $logs[0];
        $log_id = isset($first['id']) ? sanitize_text_field((string) $first['id']) : '';
        $log_date = isset($first['date']) ? sanitize_text_field((string) $first['date']) : '';
        $sig = md5($log_id . '|' . $log_date);
        update_user_meta(get_current_user_id(), 'crm_notifications_last_seen_sig', $sig);

        wp_send_json_success(['unread' => 0]);
    }

    public function save_crm_settings_via_ajax() {
        if (!$this->verify_admin_ajax_request()) return;

        $provider = isset($_POST['provider']) ? sanitize_key((string) $_POST['provider']) : 'therapyflow_pro';
        $allowed_providers = ['therapyflow_pro', 'therapyflow_demo', 'custom'];
        if (!in_array($provider, $allowed_providers, true)) {
            $provider = 'therapyflow_pro';
        }

        $api_url = isset($_POST['api_url']) ? trim((string) wp_unslash($_POST['api_url'])) : '';
        $api_url = esc_url_raw($api_url);
        if ($api_url === '' || !filter_var($api_url, FILTER_VALIDATE_URL)) {
            wp_send_json_error(['message' => 'Please provide a valid CRM API URL.'], 400);
        }

        update_option('crm_selected_provider', $provider, false);
        update_option('crm_api_base_url', untrailingslashit($api_url), false);

        $api = new CRM_API_Handler();
        $therapists = $api->get_all_therapists();
        $api_error = $api->get_last_error();
        $connected = is_array($therapists) && !empty($therapists) && $api_error === '';
        $total_therapists = $connected ? count($therapists) : 0;
        $total_appointments = $api->get_appointment_count();

        wp_send_json_success([
            'message' => 'CRM settings saved successfully.',
            'connection_status' => $connected ? 'active' : 'inactive',
            'status_label' => $connected ? 'Connected' : 'Disconnected',
            'sync_status' => $connected ? 'Active' : 'Inactive',
            'total_therapists' => $total_therapists,
            'total_appointments' => $total_appointments,
            'api_error' => $api_error,
        ]);
    }

    private function verify_admin_ajax_request() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized request.'], 403);
            return false;
        }

        if (!check_ajax_referer('crm_admin_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed.'], 403);
            return false;
        }
        return true;
    }

    private function verify_public_ajax_request($action_key, $rate_limit = 10, $window = 60) {
        if (!check_ajax_referer('crm_public_booking_nonce', 'nonce', false)) {
            wp_send_json(['ok' => false, 'message' => 'Security check failed.']);
            return false;
        }

        $ip = crm_booking_get_client_ip();
        if (crm_booking_is_rate_limited($action_key . '|' . $ip, $rate_limit, $window)) {
            wp_send_json(['ok' => false, 'message' => 'Too many requests. Please wait and try again.']);
            return false;
        }
        return true;
    }

    private function verify_public_or_admin_nonce() {
        $public_ok = check_ajax_referer('crm_public_booking_nonce', 'nonce', false);
        $admin_ok = check_ajax_referer('crm_admin_ajax_nonce', 'nonce', false);
        if (!$public_ok && !$admin_ok) {
            wp_send_json(['ok' => false, 'message' => 'Security check failed.'], 403);
            return false;
        }
        return true;
    }

    private function is_valid_iso_date($date) {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    private function sanitize_frontend_booking_payload($data) {
        if (!is_array($data)) {
            return new WP_Error('invalid_payload', 'Invalid booking payload.');
        }

        $full_name = isset($data['fullName']) ? sanitize_text_field((string) $data['fullName']) : '';
        if ($full_name === '' && isset($data['client_name'])) {
            $full_name = sanitize_text_field((string) $data['client_name']);
        }
        $email = isset($data['email']) ? sanitize_email((string) $data['email']) : '';
        if ($email === '' && isset($data['client_email'])) {
            $email = sanitize_email((string) $data['client_email']);
        }
        $therapist_id = isset($data['therapistId']) ? sanitize_text_field((string) $data['therapistId']) : '';
        if ($therapist_id === '' && isset($data['therapist_id'])) {
            $therapist_id = sanitize_text_field((string) $data['therapist_id']);
        }
        $service_id = isset($data['serviceId']) ? sanitize_text_field((string) $data['serviceId']) : '';
        if ($service_id === '' && isset($data['service_id'])) {
            $service_id = sanitize_text_field((string) $data['service_id']);
        }
        $session_date = isset($data['sessionDate']) ? sanitize_text_field((string) $data['sessionDate']) : '';
        if ($session_date === '' && isset($data['session_date'])) {
            $session_date = sanitize_text_field((string) $data['session_date']);
        }
        if ($session_date === '' && isset($data['appointment_date'])) {
            $session_date = sanitize_text_field((string) $data['appointment_date']);
        }
        $session_time = isset($data['sessionTime']) ? sanitize_text_field((string) $data['sessionTime']) : '';
        if ($session_time === '' && isset($data['session_time'])) {
            $session_time = sanitize_text_field((string) $data['session_time']);
        }
        if ($session_time === '' && isset($data['time_slot'])) {
            $session_time = sanitize_text_field((string) $data['time_slot']);
        }
        $duration = isset($data['duration']) ? sanitize_text_field((string) $data['duration']) : '60';
        $session_type = isset($data['sessionType']) ? sanitize_text_field((string) $data['sessionType']) : 'online';
        if (isset($data['session_type']) && $session_type === 'online') {
            $session_type = sanitize_text_field((string) $data['session_type']);
        }
        if (isset($data['medium']) && in_array($data['medium'], ['online', 'physical'], true)) {
            $session_type = $data['medium'] === 'physical' ? 'in-person' : 'online';
        }
        $notes = isset($data['notes']) ? sanitize_textarea_field((string) $data['notes']) : '';
        if ($notes === '' && isset($data['reason'])) {
            $notes = sanitize_textarea_field((string) $data['reason']);
        }
        $phone = isset($data['phone']) ? sanitize_text_field((string) $data['phone']) : '';
        $room = isset($data['room']) ? sanitize_text_field((string) $data['room']) : '';
        if ($room === '' && isset($data['room_id'])) {
            $room = sanitize_text_field((string) $data['room_id']);
        }
        $is_zoom = !empty($data['isZoom']);

        if (strlen($full_name) < 2) {
            return new WP_Error('invalid_name', 'Please enter a valid client name.');
        }
        if (!is_email($email)) {
            return new WP_Error('invalid_email', 'Please enter a valid email address.');
        }
        if ($therapist_id === '') {
            return new WP_Error('missing_therapist', 'Therapist is required.');
        }

        $allowed_service_ids = ['50', '51', '52', '53', '54', '55', '56'];
        if (!in_array($service_id, $allowed_service_ids, true)) {
            return new WP_Error('invalid_service', 'Invalid service selected.');
        }
        if (!$this->is_valid_iso_date($session_date)) {
            return new WP_Error('invalid_date', 'Invalid date.');
        }
        if ($session_date < current_time('Y-m-d')) {
            return new WP_Error('past_date', 'Past dates are not allowed.');
        }
        $time_is_12h = preg_match('/^[0-9]{1,2}:[0-9]{2}\s?(AM|PM)$/i', $session_time);
        $time_is_24h = preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $session_time);
        if (!$time_is_12h && !$time_is_24h) {
            return new WP_Error('invalid_time', 'Invalid time format.');
        }
        if (!in_array($session_type, ['online', 'in-person'], true)) {
            return new WP_Error('invalid_type', 'Invalid appointment type.');
        }
        if (!in_array($duration, ['30', '45', '50', '60', '90', '120'], true)) {
            $duration = '60';
        }
        if (strlen($notes) > 1000) {
            return new WP_Error('notes_too_long', 'Notes are too long.');
        }
        if ($phone !== '' && !preg_match('/^[0-9\+\-\s\(\)]{7,20}$/', $phone)) {
            return new WP_Error('invalid_phone', 'Please enter a valid phone number.');
        }

        $payload = [
            'fullName' => $full_name,
            'email' => $email,
            'therapistId' => $therapist_id,
            'serviceId' => $service_id,
            'sessionDate' => $session_date,
            'sessionTime' => $session_time,
            'duration' => $duration,
            'sessionType' => $session_type,
            'notes' => $notes,
        ];
        if ($phone !== '') $payload['phone'] = $phone;
        if ($room !== '') $payload['room'] = $room;
        if ($is_zoom) $payload['isZoom'] = true;

        return $payload;
    }

    public function register_therapist_cpt() {
        crm_booking_register_therapist_post_type_for_rewrite();
    }

    public function maybe_sync_therapists_cache() {
        if (!is_admin() || wp_doing_ajax()) return;
        if (!current_user_can('manage_options')) return;
        $last = (int) get_option('crm_therapist_last_sync', 0);
        if ($last > 0 && (time() - $last) < (15 * MINUTE_IN_SECONDS)) return;
        $this->sync_therapists_to_posts(false);
    }

    public function handle_manual_therapist_sync() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized request.');
        }
        check_admin_referer('crm_sync_therapists_cache');
        $this->sync_therapists_to_posts(true);
        $redirect = isset($_SERVER['HTTP_REFERER']) ? wp_get_referer() : admin_url('edit.php?post_type=crm_therapist');
        wp_safe_redirect($redirect ? $redirect : admin_url('edit.php?post_type=crm_therapist'));
        exit;
    }

    private function sync_therapists_to_posts($force = false, $seed_therapists = null) {
        if (!$force && get_transient('crm_therapist_sync_lock')) return;
        set_transient('crm_therapist_sync_lock', 1, 2 * MINUTE_IN_SECONDS);

        $api = new CRM_API_Handler();
        $therapists = is_array($seed_therapists) ? $seed_therapists : $api->get_all_therapists();
        if (!is_array($therapists) || empty($therapists)) {
            delete_transient('crm_therapist_sync_lock');
            return;
        }

        foreach ($therapists as $t) {
            if (!is_array($t)) continue;
            $crm_id = isset($t['id']) ? sanitize_text_field((string) $t['id']) : '';
            if ($crm_id === '') continue;

            $profile_raw = $api->get_therapist_profile($crm_id);
            $profile = (is_array($profile_raw) && isset($profile_raw['profile']) && is_array($profile_raw['profile']))
                ? $profile_raw['profile']
                : (is_array($profile_raw) ? $profile_raw : []);

            $full_name = isset($t['fullName']) ? sanitize_text_field($t['fullName']) : '';
            if ($full_name === '' && isset($profile_raw['fullName'])) $full_name = sanitize_text_field((string) $profile_raw['fullName']);
            if ($full_name === '') $full_name = 'Therapist ' . $crm_id;

            $email = isset($t['email']) ? sanitize_email($t['email']) : '';
            if (!$email && isset($profile_raw['email'])) $email = sanitize_email((string) $profile_raw['email']);

            $years = isset($t['yearsOfExperience']) ? sanitize_text_field((string) $t['yearsOfExperience']) : '';
            if ($years === '' && isset($profile['yearsOfExperience'])) $years = sanitize_text_field((string) $profile['yearsOfExperience']);

            $license = isset($profile['licenseType']) ? sanitize_text_field((string) $profile['licenseType']) : '';
            $role = isset($t['title']) ? sanitize_text_field((string) $t['title']) : '';
            if ($role === '' && isset($profile_raw['title'])) $role = sanitize_text_field((string) $profile_raw['title']);
            if ($role === '') $role = 'Psychotherapist';

            $avatar = isset($t['avatarUrl']) ? esc_url_raw((string) $t['avatarUrl']) : '';
            if ($avatar === '' && isset($profile_raw['avatarUrl'])) $avatar = esc_url_raw((string) $profile_raw['avatarUrl']);
            if ($avatar === '' && isset($profile_raw['profilePicture'])) $avatar = esc_url_raw((string) $profile_raw['profilePicture']);
            if ($avatar === '' && isset($profile['profilePicture'])) $avatar = esc_url_raw((string) $profile['profilePicture']);

            $phone = isset($t['phone']) ? sanitize_text_field((string) $t['phone']) : '';
            if ($phone === '' && isset($profile_raw['phone'])) $phone = sanitize_text_field((string) $profile_raw['phone']);
            $client_focus = isset($profile['ageGroups']) && is_array($profile['ageGroups']) ? implode(', ', array_map('sanitize_text_field', $profile['ageGroups'])) : '';
            $bio_html = isset($profile['bioHtml']) ? wp_kses_post((string) $profile['bioHtml']) : '';
            $arabic_bio = isset($profile['arabicBioHtml']) ? wp_kses_post((string) $profile['arabicBioHtml']) : '';
            $turkish_bio = isset($profile['turkishBioHtml']) ? wp_kses_post((string) $profile['turkishBioHtml']) : '';
            if (($bio_html === '' || $arabic_bio === '') && !empty($profile_raw['bio']) && is_string($profile_raw['bio'])) {
                $raw_bio = trim((string) $profile_raw['bio']);
                $english = '';
                $arabic = '';
                if (preg_match('/Biography\s*\(English\)\s*:\s*(.*?)(?:Biography\s*\(Arabic\)\s*:|$)/isu', $raw_bio, $m)) {
                    $english = trim((string) $m[1]);
                }
                if (preg_match('/Biography\s*\(Arabic\)\s*:\s*(.*)$/isu', $raw_bio, $m)) {
                    $arabic = trim((string) $m[1]);
                }
                if ($english === '' && $arabic === '') {
                    $english = $raw_bio;
                }
                if ($bio_html === '' && $english !== '') $bio_html = wpautop(esc_html($english));
                if ($arabic_bio === '' && $arabic !== '') $arabic_bio = wpautop(esc_html($arabic));
            }

            $languages = isset($profile['languages']) && is_array($profile['languages']) ? array_values(array_filter(array_map('sanitize_text_field', $profile['languages']))) : [];
            $specializations = isset($profile['specializations']) && is_array($profile['specializations']) ? array_values(array_filter(array_map('sanitize_text_field', $profile['specializations']))) : [];
            $approaches = isset($profile['treatmentApproaches']) && is_array($profile['treatmentApproaches']) ? array_values(array_filter(array_map('sanitize_text_field', $profile['treatmentApproaches']))) : [];
            if (empty($approaches) && isset($profile['approaches']) && is_array($profile['approaches'])) {
                $approaches = array_values(array_filter(array_map('sanitize_text_field', $profile['approaches'])));
            }
            $education = isset($profile['education']) && is_array($profile['education']) ? $profile['education'] : [];
            $summary_lines = [];
            if ($email) $summary_lines[] = 'Email: ' . $email;
            if ($years) $summary_lines[] = 'Experience: ' . $years;
            if ($license) $summary_lines[] = 'License: ' . $license;
            if ($role) $summary_lines[] = 'Role: ' . $role;
            if ($phone) $summary_lines[] = 'Phone: ' . $phone;
            if (!empty($languages)) $summary_lines[] = 'Languages: ' . implode(', ', $languages);
            if (!empty($specializations)) $summary_lines[] = 'Specializations: ' . implode(', ', $specializations);
            if (!empty($approaches)) $summary_lines[] = 'Approaches: ' . implode(', ', $approaches);

            $content = $bio_html ? wp_kses_post($bio_html) : '';
            if (!empty($summary_lines)) {
                $content .= ($content ? "\n\n" : '') . '<p><strong>Profile Summary</strong><br>' . esc_html(implode(' | ', $summary_lines)) . '</p>';
            }

            $post_id = $this->find_therapist_post_by_crm_id($crm_id);
            $post_data = [
                'post_type' => 'crm_therapist',
                'post_status' => 'publish',
                'post_title' => $full_name,
                'post_content' => $content,
                'post_excerpt' => sanitize_text_field(implode(' | ', $summary_lines)),
            ];
            if ($post_id > 0) {
                $post_data['ID'] = $post_id;
                wp_update_post($post_data);
            } else {
                $post_id = wp_insert_post($post_data);
            }
            if (!$post_id || is_wp_error($post_id)) continue;

            update_post_meta($post_id, '_crm_therapist_id', $crm_id);
            update_post_meta($post_id, '_crm_email', $email);
            update_post_meta($post_id, '_crm_years_of_experience', $years);
            update_post_meta($post_id, '_crm_license_type', $license);
            update_post_meta($post_id, '_crm_languages', $languages);
            update_post_meta($post_id, '_crm_specializations', $specializations);
            update_post_meta($post_id, '_crm_treatment_approaches', $approaches);
            update_post_meta($post_id, '_crm_role', $role);
            update_post_meta($post_id, '_crm_client_focus', sanitize_text_field($client_focus));
            update_post_meta($post_id, '_crm_avatar_url', $avatar);
            update_post_meta($post_id, '_crm_phone', $phone);
            update_post_meta($post_id, '_crm_bio_html', $bio_html);
            update_post_meta($post_id, '_crm_bio_arabic_html', $arabic_bio);
            update_post_meta($post_id, '_crm_bio_turkish_html', $turkish_bio);
            update_post_meta($post_id, '_crm_education', $education);
            update_post_meta($post_id, '_crm_profile_raw', $profile_raw);
        }

        update_option('crm_therapist_last_sync', time(), false);
        delete_transient('crm_therapist_sync_lock');
    }

    private function find_therapist_post_by_crm_id($crm_id) {
        $q = new WP_Query([
            'post_type' => 'crm_therapist',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_crm_therapist_id',
                    'value' => $crm_id,
                    'compare' => '=',
                ]
            ],
            'no_found_rows' => true,
        ]);
        if (empty($q->posts)) return 0;
        return (int) $q->posts[0];
    }
}
new CRM_Connector_Core();
