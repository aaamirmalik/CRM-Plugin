
<?php
if (!defined('ABSPATH')) exit;

class CRM_Booking_Flow_Shortcodes {
    public function __construct() {
        add_shortcode('crm_booking_calendar', [$this, 'render_booking_calendar']);
        add_shortcode('crm_booking_details', [$this, 'render_booking_details']); // backward compatibility

        add_action('wp_ajax_crm_booking_flow_slots', [$this, 'ajax_slots']);
        add_action('wp_ajax_nopriv_crm_booking_flow_slots', [$this, 'ajax_slots']);
        add_action('wp_ajax_crm_booking_flow_month_availability', [$this, 'ajax_month_availability']);
        add_action('wp_ajax_nopriv_crm_booking_flow_month_availability', [$this, 'ajax_month_availability']);

        add_action('wp_ajax_crm_booking_flow_submit', [$this, 'ajax_submit']);
        add_action('wp_ajax_nopriv_crm_booking_flow_submit', [$this, 'ajax_submit']);
    }

    private function fallback_slots() {
        return [];
    }

    private function service_map() {
        return [
            '50' => 'Individual Counselling',
            '51' => 'Free Consultation (15m)',
            '52' => 'Family Counselling',
            '53' => 'Anger Management',
            '54' => 'Refugee Counselling',
            '55' => 'MVA Counselling',
            '56' => 'Student Counselling'
        ];
    }

    private function resolve_therapist($therapists, $id_from_attr = '') {
        $id_from_url = isset($_GET['therapist_id']) ? sanitize_text_field($_GET['therapist_id']) : '';
        $target_id = !empty($id_from_url) ? $id_from_url : $id_from_attr;
        $selected = !empty($therapists) ? $therapists[0] : [];
        if (!empty($target_id)) {
            foreach ($therapists as $t) {
                if (!empty($t['id']) && (string)$t['id'] === (string)$target_id) {
                    $selected = $t;
                    break;
                }
            }
        }
        return $selected;
    }

    private function is_valid_date($date) {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    private function slots_array_from_response($res) {
        if (!is_array($res) || !isset($res['slots']) || !is_array($res['slots'])) return [];
        return $res['slots'];
    }

    private function normalize_slots($res) {
        $raw = $this->slots_array_from_response($res);
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $slot) {
            if (is_string($slot)) {
                $time = sanitize_text_field($slot);
                if ($time !== '') $out[] = ['time' => $time, 'available' => true];
                continue;
            }
            if (!is_array($slot)) continue;
            $time = '';
            foreach (['time', 'slot', 'sessionTime', 'startTime'] as $key) {
                if (!empty($slot[$key])) {
                    $time = sanitize_text_field((string) $slot[$key]);
                    break;
                }
            }
            if ($time === '') continue;

            $available = true;
            if (array_key_exists('available', $slot)) {
                $available = in_array($slot['available'], [true, 1, '1', 'true'], true);
            } elseif (array_key_exists('isAvailable', $slot)) {
                $available = in_array($slot['isAvailable'], [true, 1, '1', 'true'], true);
            } elseif (array_key_exists('booked', $slot)) {
                $available = !in_array($slot['booked'], [true, 1, '1', 'true'], true);
            } elseif (array_key_exists('isBooked', $slot)) {
                $available = !in_array($slot['isBooked'], [true, 1, '1', 'true'], true);
            } elseif (!empty($slot['status']) && is_string($slot['status'])) {
                $status = strtolower(trim((string) $slot['status']));
                $available = !in_array($status, ['booked', 'unavailable', 'busy', 'taken'], true);
            }

            $out[] = ['time' => $time, 'available' => $available];
        }
        return $out;
    }

    private function is_time_available_in_slots($slots, $session_time) {
        if (!is_array($slots) || $session_time === '') return false;
        foreach ($slots as $slot) {
            if (!is_array($slot)) continue;
            $slot_time = isset($slot['time']) ? sanitize_text_field((string) $slot['time']) : '';
            if ($slot_time === '') continue;
            if (strcasecmp($slot_time, $session_time) === 0 && !empty($slot['available'])) {
                return true;
            }
        }
        return false;
    }

    private function has_available_slot($res) {
        $slots = $this->slots_array_from_response($res);
        if (empty($slots)) return false;
        foreach ($slots as $s) {
            if (is_array($s) && !empty($s['available'])) return true;
        }
        return false;
    }

    public function ajax_slots() {
        if (!check_ajax_referer('crm_public_booking_nonce', 'nonce', false)) {
            wp_send_json(['slots' => [], 'used_fallback' => false, 'message' => 'Security check failed.']);
        }
        if (crm_booking_is_rate_limited('flow_slots|' . crm_booking_get_client_ip(), 60, MINUTE_IN_SECONDS)) {
            wp_send_json(['slots' => [], 'used_fallback' => false, 'message' => 'Too many requests.']);
        }

        $therapist_id = isset($_POST['therapist_id']) ? sanitize_text_field($_POST['therapist_id']) : '';
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        $session_type = isset($_POST['session_type']) ? sanitize_text_field($_POST['session_type']) : 'online';

        if (empty($therapist_id) || !$this->is_valid_date($date)) {
            wp_send_json(['slots' => [], 'used_fallback' => false, 'message' => 'Select therapist and valid date first.']);
        }

        if (!in_array($session_type, ['online', 'in-person'], true)) {
            $session_type = 'online';
        }

        $api = new CRM_API_Handler();
        $res = $api->get_slots($therapist_id, $date, $session_type);
        $api_error = $api->get_last_error();
        if ($api_error !== '') {
            wp_send_json(['slots' => [], 'used_fallback' => false, 'message' => $api_error], 502);
        }
        $slots = $this->normalize_slots($res);

        if (empty($slots)) {
            wp_send_json(['slots' => [], 'used_fallback' => false, 'message' => 'No slots available for selected date.']);
        }
        wp_send_json(['slots' => $slots, 'used_fallback' => false]);
    }

    public function ajax_month_availability() {
        if (!check_ajax_referer('crm_public_booking_nonce', 'nonce', false)) {
            wp_send_json(['availability' => [], 'used_fallback' => false, 'message' => 'Security check failed.']);
        }
        if (crm_booking_is_rate_limited('flow_month_avail|' . crm_booking_get_client_ip(), 30, MINUTE_IN_SECONDS)) {
            wp_send_json(['availability' => [], 'used_fallback' => false, 'message' => 'Too many requests.']);
        }

        $therapist_id = isset($_POST['therapist_id']) ? sanitize_text_field($_POST['therapist_id']) : '';
        $year = isset($_POST['year']) ? intval($_POST['year']) : 0;
        $month = isset($_POST['month']) ? intval($_POST['month']) : 0;
        $session_type = isset($_POST['session_type']) ? sanitize_text_field($_POST['session_type']) : 'online';

        if (empty($therapist_id) || $year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            wp_send_json(['availability' => [], 'used_fallback' => false]);
        }

        if (!in_array($session_type, ['online', 'in-person'], true)) {
            $session_type = 'online';
        }

        $cache_key = 'crm_month_avail_' . md5($therapist_id . '|' . $year . '|' . $month . '|' . $session_type);
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['availability'])) {
            wp_send_json($cached);
        }

        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $availability = [];

        $today = current_time('Y-m-d');
        for ($day = 1; $day <= $days_in_month; $day++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            if ($date < $today) continue;
            $weekday = (int) date('w', strtotime($date));
            if ($weekday > 0 && $weekday < 6) $availability[$date] = true;
        }

        $payload = ['availability' => $availability, 'used_fallback' => false];
        set_transient($cache_key, $payload, 30 * MINUTE_IN_SECONDS);
        wp_send_json($payload);
    }

    public function ajax_submit() {
        if (!check_ajax_referer('crm_public_booking_nonce', 'nonce', false)) {
            wp_send_json(['ok' => false, 'message' => 'Security check failed.']);
        }
        if (crm_booking_is_rate_limited('flow_submit|' . crm_booking_get_client_ip(), 10, MINUTE_IN_SECONDS)) {
            wp_send_json(['ok' => false, 'message' => 'Too many requests. Please wait and try again.']);
        }

        $data = isset($_POST['booking_data']) && is_array($_POST['booking_data']) ? $_POST['booking_data'] : [];

        $first = !empty($data['first_name']) ? sanitize_text_field($data['first_name']) : '';
        $last = !empty($data['last_name']) ? sanitize_text_field($data['last_name']) : '';
        $email = !empty($data['email']) ? sanitize_email($data['email']) : '';
        $phone = !empty($data['phone']) ? sanitize_text_field($data['phone']) : '';
        $reason = !empty($data['reason']) ? sanitize_textarea_field($data['reason']) : '';
        $therapist_id = !empty($data['therapist_id']) ? sanitize_text_field($data['therapist_id']) : '';
        $service_id = !empty($data['service_id']) ? sanitize_text_field($data['service_id']) : '50';
        $session_date = !empty($data['session_date']) ? sanitize_text_field($data['session_date']) : '';
        $session_time = !empty($data['session_time']) ? sanitize_text_field($data['session_time']) : '';
        $duration = !empty($data['duration']) ? sanitize_text_field($data['duration']) : '50';
        $session_type = !empty($data['session_type']) ? sanitize_text_field($data['session_type']) : 'online';

        $services = $this->service_map();
        if (empty($therapist_id)) wp_send_json(['ok' => false, 'message' => 'Therapist is required.']);
        if (!isset($services[$service_id])) wp_send_json(['ok' => false, 'message' => 'Invalid service selected.']);
        if (strlen($first) < 2 || strlen($last) < 2) wp_send_json(['ok' => false, 'message' => 'Enter valid first and last name.']);
        if (!is_email($email)) wp_send_json(['ok' => false, 'message' => 'Enter a valid email address.']);
        if (!empty($phone) && !preg_match('/^[0-9\+\-\s\(\)]{7,20}$/', $phone)) wp_send_json(['ok' => false, 'message' => 'Enter a valid phone number.']);
        if (!$this->is_valid_date($session_date)) wp_send_json(['ok' => false, 'message' => 'Invalid date.']);
        if ($session_date < current_time('Y-m-d')) wp_send_json(['ok' => false, 'message' => 'Past dates are not allowed.']);
        $is_12h = preg_match('/^[0-9]{1,2}:[0-9]{2}\s?(AM|PM)$/i', $session_time);
        $is_24h = preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $session_time);
        if (!$is_12h && !$is_24h) wp_send_json(['ok' => false, 'message' => 'Invalid time format.']);
        if (!in_array($session_type, ['online', 'in-person'], true)) wp_send_json(['ok' => false, 'message' => 'Invalid appointment type.']);
        if (strlen($reason) > 1000) wp_send_json(['ok' => false, 'message' => 'Reason is too long.']);

        $api = new CRM_API_Handler();
        $slot_res = $api->get_slots($therapist_id, $session_date, $session_type);
        $api_error = $api->get_last_error();
        if ($api_error !== '') {
            wp_send_json(['ok' => false, 'message' => $api_error], 502);
        }
        $slots = $this->normalize_slots($slot_res);
        if (!$this->is_time_available_in_slots($slots, $session_time)) {
            wp_send_json(['ok' => false, 'message' => 'Selected time is no longer available. Please choose another slot.']);
        }

        $payload = [
            'fullName' => trim($first . ' ' . $last),
            'email' => $email,
            'therapistId' => $therapist_id,
            'serviceId' => $service_id,
            'sessionDate' => $session_date,
            'sessionTime' => $session_time,
            'duration' => $duration,
            'sessionType' => $session_type,
            'phone' => $phone,
            'notes' => $reason
        ];

        $result = $api->book_appointment($payload);
        if (is_array($result) && !empty($result['appointment']['id'])) {
            wp_send_json(['ok' => true, 'appointment' => $result['appointment']]);
        }

        $booking_error = $api->get_last_error();
        $message = is_array($result) && !empty($result['message'])
            ? sanitize_text_field($result['message'])
            : ($booking_error !== '' ? $booking_error : 'Booking failed.');
        wp_send_json(['ok' => false, 'message' => $message], 502);
    }

    public function render_booking_calendar($atts) {
        $atts = shortcode_atts([
            'therapist_id' => '',
            'service_id' => '50',
            'session_type' => 'online'
        ], $atts, 'crm_booking_calendar');

        $api = new CRM_API_Handler();
        $therapists = $api->get_all_therapists();
        if (!is_array($therapists) || empty($therapists)) {
            $therapists = crm_get_cached_therapists();
        }
        if (!is_array($therapists)) $therapists = [];
        $selected_therapist = $this->resolve_therapist($therapists, $atts['therapist_id']);
        $therapist_id = !empty($selected_therapist['id']) ? $selected_therapist['id'] : '';

        $services = $this->service_map();
        $default_service = isset($services[$atts['service_id']]) ? $atts['service_id'] : '50';
        $default_session_type = in_array($atts['session_type'], ['online', 'in-person'], true) ? $atts['session_type'] : 'online';

        ob_start(); ?>
        <div class="crm-flow-booking">
            <style>
                .crm-flow-booking{--bd:#00000014;--pr:#1b6d12;--sf:#eaf7ea;background:#f2fbf5;padding:50px 0;font-family:Inter,system-ui,sans-serif;color:#123029}
                .crm-flow-wrap{max-width:1200px;margin:0 auto;padding:0 24px}.crm-flow-grid{display:grid;grid-template-columns:320px 1fr;gap:30px}
                .crm-card{background:#fff;border:1px solid var(--bd);border-radius:10px}.crm-sidebar{padding:20px}.crm-sidebar h3{margin:0 0 14px}
                .crm-service-list{display:flex;flex-direction:column;gap:8px}.crm-service-item{display:flex;align-items:center;padding:12px;border-radius:8px;border:1px solid transparent;cursor:pointer;background:#fff}
                .crm-service-item.active{background:var(--sf);border-color:var(--pr);color:var(--pr);font-weight:600}
                .crm-main{padding:26px}.crm-head{border-bottom:1px solid var(--bd);padding-bottom:18px;margin-bottom:18px}.crm-title{font-size:28px;font-weight:700;margin:0 0 8px}.crm-sub{color:#6b7280;font-size:14px}
                .crm-therapist-wrap{margin-bottom:16px}.crm-therapist-wrap label{display:block;font-size:13px;font-weight:600;margin-bottom:6px}
                .crm-select{width:100%;border:1px solid var(--bd);border-radius:8px;padding:11px;background:#fff}.crm-mid{display:grid;grid-template-columns:1fr 1fr;gap:40px}
                .crm-cal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}.crm-cal-nav button{width:30px;height:30px;border:1px solid var(--bd);background:#fff;border-radius:50%;cursor:pointer}
                .crm-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;text-align:center}.crm-day-head{font-size:12px;color:#6b7280;padding:6px 0}
                .crm-day{width:40px;height:40px;margin:0 auto;border-radius:50%;border:1px solid transparent;background:#fff;cursor:pointer}
                .crm-day.active{background:var(--pr);color:#fff}.crm-day.disabled{opacity:.4;cursor:not-allowed}.crm-day.available{background:#f2fbf5;border-color:#b7dfb2;color:#1b6d12}
                .crm-day.available.active{background:var(--pr);border-color:var(--pr);color:#fff}
                .crm-slots h4{margin:0 0 4px}.crm-slot-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}.crm-slot{padding:12px;border:1px solid var(--bd);border-radius:8px;background:#fff;cursor:pointer}.crm-slot.active{background:var(--pr);border-color:var(--pr);color:#fff}.crm-slot.disabled{opacity:.55;cursor:not-allowed}.crm-slot.booked{background:#fef2f2;border-color:#ef4444;color:#b91c1c;text-decoration:line-through}.crm-slot.recently-booked{box-shadow:0 0 0 2px #ef4444 inset}
                .crm-foot{margin-top:24px;padding-top:16px;border-top:1px solid var(--bd);display:flex;justify-content:space-between;align-items:center;gap:16px}.crm-btn{background:var(--pr);color:#fff;border:none;border-radius:999px;padding:12px 22px;cursor:pointer}.crm-status{font-size:12px;color:#6b7280}
                .crm-step{display:none}.crm-step.active{display:block}.crm-d-grid{display:grid;grid-template-columns:1fr 340px;gap:20px}.crm-row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
                .crm-field{margin-bottom:14px}.crm-field label{display:block;font-size:13px;font-weight:600;margin-bottom:6px}.crm-input,.crm-textarea{width:100%;border:1px solid var(--bd);border-radius:8px;padding:11px}.crm-textarea{min-height:95px;resize:vertical}
                .crm-divider{height:1px;background:var(--bd);margin:20px 0}.crm-check{display:flex;gap:8px;margin:10px 0;font-size:13px;color:#5a7069}
                .crm-summary{padding:16px;border:1px solid var(--bd);border-radius:10px;background:#fff}.crm-item{display:flex;gap:10px;margin-bottom:14px}.crm-item strong{display:block}.crm-note{font-size:12px;color:#6b7280;text-align:center;margin-top:10px}.crm-msg{margin-top:10px;font-size:13px}
                .crm-item{align-items:flex-start}
                .crm-item-icon{width:28px;height:28px;border-radius:6px;background:var(--sf);color:var(--pr);display:inline-flex;align-items:center;justify-content:center;flex:0 0 28px}
                .crm-item-main{min-width:0;flex:1}
                .crm-item-head{display:flex;align-items:center;gap:8px;line-height:1}
                .crm-item-label{font-size:12px;color:#6b7280}
                .crm-item-value{margin-top:4px;display:block}
                @media (max-width:960px){
                    .crm-flow-booking{padding:30px 0}
                    .crm-flow-wrap{padding:0 16px}
                    .crm-flow-grid{grid-template-columns:1fr;gap:16px}
                    .crm-mid{grid-template-columns:1fr;gap:16px}
                    .crm-d-grid{grid-template-columns:1fr;gap:14px}
                    .crm-row2{grid-template-columns:1fr;gap:10px}
                    .crm-main{padding:16px}
                    .crm-sidebar{padding:14px}
                    .crm-title{font-size:22px}
                    .crm-cal-grid{gap:4px}
                    .crm-day{width:36px;height:36px}
                    .crm-slot-grid{grid-template-columns:1fr 1fr}
                    .crm-foot{flex-direction:column;align-items:stretch}
                    .crm-btn{width:100%;text-align:center}
                }
                @media (max-width:640px){
                    .crm-flow-wrap{padding:0 12px}
                    .crm-service-item{padding:10px;font-size:14px}
                    .crm-title{font-size:20px}
                    .crm-sub{font-size:13px}
                    .crm-cal-head strong{font-size:14px}
                    .crm-day-head{font-size:11px}
                    .crm-day{width:34px;height:34px;font-size:13px}
                    .crm-slot{padding:10px;font-size:14px}
                    .crm-slot-grid{grid-template-columns:1fr}
                    .crm-field label{font-size:12px}
                    .crm-input,.crm-textarea,.crm-select{font-size:14px;padding:10px}
                    .crm-item{font-size:13px}
                }
            </style>
            <div class="crm-flow-wrap">
                <h2 style="margin:0 0 8px">Book an Appointment</h2>
                <p style="margin:0 0 24px;color:#6b7280">Select service, therapist, date and time. Then complete your details to confirm.</p>
                <div class="crm-flow-grid">
                    <aside class="crm-card crm-sidebar">
                        <h3>Our Services</h3>
                        <div class="crm-service-list" id="crm-service-list">
                            <?php foreach ($services as $id => $label): ?>
                                <button type="button" class="crm-service-item <?php echo $id === $default_service ? 'active' : ''; ?>" data-service="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></button>
                            <?php endforeach; ?>
                        </div>
                    </aside>
                    <div class="crm-card crm-main" id="crm-booking-root" data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-therapist-id="<?php echo esc_attr($therapist_id); ?>" data-service-id="<?php echo esc_attr($default_service); ?>" data-session-type="<?php echo esc_attr($default_session_type); ?>">
                        <div class="crm-step active" id="crm-step-calendar">
                            <div class="crm-head"><h3 class="crm-title" id="crm-service-title"><?php echo esc_html($services[$default_service]); ?></h3><div class="crm-sub">30 minutes session · Online / In-person available</div></div>
                            <div class="crm-therapist-wrap"><label for="crm-therapist-select">Select Therapist</label><select id="crm-therapist-select" class="crm-select"><option value="">Choose therapist...</option><?php foreach ($therapists as $t): ?><option value="<?php echo esc_attr($t['id']); ?>" <?php selected((string)$t['id'], (string)$therapist_id); ?>><?php echo esc_html($t['fullName']); ?></option><?php endforeach; ?></select></div>
                            <div class="crm-mid">
                                <div><div class="crm-cal-head"><strong id="crm-month-title"></strong><div class="crm-cal-nav"><button id="crm-prev" type="button">&lt;</button><button id="crm-next" type="button">&gt;</button></div></div><div class="crm-cal-grid" id="crm-calendar-grid"></div></div>
                                <div class="crm-slots"><h4 id="crm-date-title">Select a date</h4><p class="crm-sub" style="margin:0 0 14px">Eastern Time (US &amp; Canada)</p><div class="crm-slot-grid" id="crm-slot-grid"></div></div>
                            </div>
                            <div class="crm-foot"><div><strong id="crm-summary">No time selected</strong></div><button type="button" id="crm-continue-btn" class="crm-btn">Continue to Details</button></div>
                            <div class="crm-status" id="crm-status"></div>
                        </div>
                        <div class="crm-step" id="crm-step-details">
                            <a href="#" id="crm-back-calendar" style="text-decoration:none;color:#6b7280;font-size:14px">Back to calendar</a>
                            <h3 style="margin:8px 0 4px">Your Details</h3>
                            <p style="margin:0 0 16px;color:#6b7280">Please provide your information to secure your appointment.</p>
                            <div class="crm-d-grid">
                                <div>
                                    <h3 style="margin:0 0 14px">Contact Information</h3>
                                    <div class="crm-row2"><div class="crm-field"><label>First Name</label><input class="crm-input" id="crm-first-name"></div><div class="crm-field"><label>Last Name</label><input class="crm-input" id="crm-last-name"></div></div>
                                    <div class="crm-row2"><div class="crm-field"><label>Email Address</label><input type="email" class="crm-input" id="crm-email"></div><div class="crm-field"><label>Phone Number</label><input class="crm-input" id="crm-phone"></div></div>
                                    <div class="crm-divider"></div>
                                    <h3 style="margin:0 0 14px">Appointment Details</h3>
                                    <div class="crm-field"><label>Appointment Type</label><select class="crm-select" id="crm-session-type"><option value="online">Online (Video Session)</option><option value="in-person">In-Person</option></select></div>
                                    <div class="crm-field"><label>Reason for Visit (Optional)</label><textarea class="crm-textarea" id="crm-reason"></textarea></div>
                                    <div class="crm-divider"></div>
                                    <label class="crm-check"><input type="checkbox" id="crm-consent-privacy"> I consent to the collection and use of my personal information.</label>
                                    <label class="crm-check"><input type="checkbox" id="crm-consent-ohip"> I understand OHIP does not generally cover psychotherapy.</label>
                                </div>
                                <aside class="crm-summary">
                                    <h3 style="margin:0 0 14px">Booking Summary</h3>
                                    <div class="crm-item"><div class="crm-item-icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M3 10h18"></path></svg></div><div class="crm-item-main"><div class="crm-item-head"><span class="crm-item-label">Service</span></div><strong class="crm-item-value" id="crm-sum-service"><?php echo esc_html($services[$default_service]); ?></strong></div></div>
                                    <div class="crm-item"><div class="crm-item-icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"></circle><path d="M4 20c1.5-4 5-6 8-6s6.5 2 8 6"></path></svg></div><div class="crm-item-main"><div class="crm-item-head"><span class="crm-item-label">Therapist</span></div><strong class="crm-item-value" id="crm-sum-therapist">-</strong></div></div>
                                    <div class="crm-item"><div class="crm-item-icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 11h18"></path></svg></div><div class="crm-item-main"><div class="crm-item-head"><span class="crm-item-label">Date & Time</span></div><strong class="crm-item-value" id="crm-sum-dt">-</strong></div></div>
                                    <div class="crm-item"><div class="crm-item-icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v6l4 2"></path></svg></div><div class="crm-item-main"><div class="crm-item-head"><span class="crm-item-label">Duration</span></div><strong class="crm-item-value">30 minutes</strong></div></div>
                                    <div class="crm-item"><div class="crm-item-icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s7-6 7-11a7 7 0 0 0-14 0c0 5 7 11 7 11z"></path><circle cx="12" cy="10" r="2.5"></circle></svg></div><div class="crm-item-main"><div class="crm-item-head"><span class="crm-item-label">Location</span></div><strong class="crm-item-value" id="crm-sum-loc">Online (Video)</strong></div></div>
                                    <button type="button" class="crm-btn" id="crm-confirm-btn" style="width:100%">Confirm Appointment</button>
                                    <div class="crm-note">Your information is secure and confidential.</div>
                                    <div class="crm-msg" id="crm-msg"></div>
                                </aside>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script>
            (function(){
                const root=document.getElementById('crm-booking-root'); if(!root) return;
                const services=<?php echo wp_json_encode($services); ?>;
                const ajaxUrl=root.dataset.ajax;
                const bookingNonce='<?php echo esc_js(wp_create_nonce('crm_public_booking_nonce')); ?>';
                let therapistId=root.dataset.therapistId||'', serviceId=root.dataset.serviceId||'50', sessionType=root.dataset.sessionType||'online';
                let viewDate=new Date(), selectedDate='', selectedTime='';
                let recentlyBookedTime='';
                let availabilityMap={};
                const monthTitle=document.getElementById('crm-month-title'), cal=document.getElementById('crm-calendar-grid'), slotGrid=document.getElementById('crm-slot-grid');
                const dateTitle=document.getElementById('crm-date-title'), summary=document.getElementById('crm-summary'), status=document.getElementById('crm-status');
                const therapistSelect=document.getElementById('crm-therapist-select'), stepCalendar=document.getElementById('crm-step-calendar'), stepDetails=document.getElementById('crm-step-details');
                const dayNames=['Su','Mo','Tu','We','Th','Fr','Sa'];
                const iso=d=>`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
                const todayIso=iso(new Date());
                const serviceStorageKey='crm_booking_service';
                let monthRequestToken=0;
                let monthDebounceTimer=null;
                let availabilityLoaded=false;
                const monthAvailabilityCache={};

                function validEmail(v){return /^\S+@\S+\.\S+$/.test(v);} function validPhone(v){return /^[0-9+\-\s()]{7,20}$/.test(v);}
                function applyServiceActive(){document.querySelectorAll('.crm-service-item').forEach(x=>x.classList.toggle('active', x.dataset.service===serviceId));document.getElementById('crm-service-title').textContent=services[serviceId]||'Service';document.getElementById('crm-sum-service').textContent=services[serviceId]||'Service';}
                function setFirstWeekday(){const d=new Date();while(d.getDay()===0||d.getDay()===6)d.setDate(d.getDate()+1);selectedDate=iso(d);viewDate=new Date(d.getFullYear(),d.getMonth(),1);}

                function drawCal(){const y=viewDate.getFullYear(),m=viewDate.getMonth(); monthTitle.textContent=viewDate.toLocaleString(undefined,{month:'long',year:'numeric'}); cal.innerHTML=''; dayNames.forEach(n=>{const h=document.createElement('div');h.className='crm-day-head';h.textContent=n;cal.appendChild(h);}); const first=new Date(y,m,1).getDay(),daysIn=new Date(y,m+1,0).getDate(),prevDays=new Date(y,m,0).getDate(); for(let i=first-1;i>=0;i--){const d=document.createElement('div');d.className='crm-day disabled';d.textContent=String(prevDays-i);cal.appendChild(d);} for(let day=1;day<=daysIn;day++){const dt=new Date(y,m,day),key=iso(dt),weekday=dt.getDay(),b=document.createElement('button'); b.type='button';b.className='crm-day';b.textContent=day;b.dataset.iso=key; const isPast=key<todayIso; const isWeekend=(weekday===0||weekday===6); const isAvailable=!!availabilityMap[key]; if(availabilityLoaded && isAvailable)b.classList.add('available'); if(selectedDate===key)b.classList.add('active'); const canClick = availabilityLoaded && !isPast && !isWeekend && isAvailable; if(!canClick)b.classList.add('disabled'); b.onclick=()=>{if(!canClick)return;selectedDate=key;selectedTime='';drawCal();loadSlots();}; cal.appendChild(b);} const total=first+daysIn,tail=(7-(total%7))%7;for(let i=1;i<=tail;i++){const d=document.createElement('div');d.className='crm-day disabled';d.textContent=String(i);cal.appendChild(d);}}

                function normalizeSlots(raw){
                    if(!Array.isArray(raw)) return [];
                    return raw.map(s=>{
                        if(typeof s==='string') return {time:s,available:true};
                        const time=(s&&typeof s==='object') ? String(s.time||s.slot||s.sessionTime||s.startTime||'') : '';
                        let available=true;
                        if(s&&typeof s==='object'){
                            if(Object.prototype.hasOwnProperty.call(s,'available')) available=(s.available===true||s.available===1||s.available==='1');
                            else if(Object.prototype.hasOwnProperty.call(s,'isAvailable')) available=(s.isAvailable===true||s.isAvailable===1||s.isAvailable==='1');
                            else if(Object.prototype.hasOwnProperty.call(s,'booked')) available=!(s.booked===true||s.booked===1||s.booked==='1');
                            else if(Object.prototype.hasOwnProperty.call(s,'isBooked')) available=!(s.isBooked===true||s.isBooked===1||s.isBooked==='1');
                            else if(typeof s.status==='string'){const st=s.status.toLowerCase(); available=!(st==='booked'||st==='unavailable'||st==='busy'||st==='taken');}
                        }
                        return {time,available};
                    }).filter(x=>x.time!=='');
                }
                function renderSlots(slots){slotGrid.innerHTML=''; if(!Array.isArray(slots)||!slots.length){slotGrid.innerHTML='<div>No slots available.</div>';status.textContent='No slots available for selected date.';return;} let recentlyFound=false; slots.forEach(s=>{const t=s.time||'',av=!!s.available,b=document.createElement('button');b.type='button';b.className='crm-slot';b.textContent=t;if(!av){b.classList.add('disabled','booked');b.disabled=true; if(recentlyBookedTime && t.toLowerCase()===String(recentlyBookedTime).toLowerCase()){b.classList.add('recently-booked'); recentlyFound=true;}} b.onclick=()=>{if(!av)return;document.querySelectorAll('.crm-slot').forEach(x=>x.classList.remove('active'));b.classList.add('active');selectedTime=t;updateSummary();};slotGrid.appendChild(b);}); if(recentlyBookedTime && !recentlyFound) recentlyBookedTime=''; status.textContent='Showing live slots from API.';}
                function loadSlots(){if(!selectedDate||!therapistId){slotGrid.innerHTML='<div>Select therapist and date.</div>';return Promise.resolve([]);} dateTitle.textContent=new Date(selectedDate+'T00:00:00').toLocaleDateString(undefined,{weekday:'long',month:'long',day:'numeric'}); slotGrid.innerHTML='<div>Loading slots...</div>'; const body=new URLSearchParams(); body.append('action','crm_booking_flow_slots'); body.append('nonce',bookingNonce); body.append('therapist_id',therapistId); body.append('date',selectedDate); body.append('session_type',sessionType); return fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()}).then(r=>r.json()).then(res=>{const normalized=normalizeSlots((res&&Array.isArray(res.slots))?res.slots:[]); renderSlots(normalized); if(res&&res.message)status.textContent=res.message; return normalized;}).catch(()=>{renderSlots([]); status.textContent='Could not load slots from API.'; return [];});}
                function loadMonthAvailability(){availabilityMap={}; availabilityLoaded=false; drawCal(); if(!therapistId){status.textContent='Select therapist to load availability.';return;} status.textContent='Loading availability...'; const cacheKey=`${therapistId}|${sessionType}|${viewDate.getFullYear()}-${viewDate.getMonth()+1}`; if(monthAvailabilityCache[cacheKey]){availabilityMap=monthAvailabilityCache[cacheKey]; availabilityLoaded=true; if(selectedDate && !availabilityMap[selectedDate]){selectedDate=''; selectedTime=''; updateSummary(); slotGrid.innerHTML='<div>Select an available highlighted day.</div>'; dateTitle.textContent='Select a date';} drawCal(); status.textContent='Availability loaded.'; return;} const requestToken=++monthRequestToken; const body=new URLSearchParams(); body.append('action','crm_booking_flow_month_availability'); body.append('nonce',bookingNonce); body.append('therapist_id',therapistId); body.append('year',String(viewDate.getFullYear())); body.append('month',String(viewDate.getMonth()+1)); body.append('session_type',sessionType); fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()}).then(r=>r.json()).then(res=>{if(requestToken!==monthRequestToken)return; if(res&&res.message){availabilityMap={}; availabilityLoaded=true; drawCal(); status.textContent=res.message; return;} if(res&&res.availability&&typeof res.availability==='object'){availabilityMap=res.availability; monthAvailabilityCache[cacheKey]=availabilityMap;} availabilityLoaded=true; if(selectedDate && !availabilityMap[selectedDate]){selectedDate=''; selectedTime=''; updateSummary(); slotGrid.innerHTML='<div>Select an available highlighted day.</div>'; dateTitle.textContent='Select a date';} drawCal(); status.textContent='Availability loaded.';}).catch(()=>{if(requestToken!==monthRequestToken)return; availabilityMap={}; availabilityLoaded=true; drawCal(); status.textContent='Could not load availability.';});}
                function scheduleMonthAvailability(){if(monthDebounceTimer)clearTimeout(monthDebounceTimer); monthDebounceTimer=setTimeout(loadMonthAvailability,80);}
                function updateSummary(){if(!selectedDate||!selectedTime){summary.textContent='No time selected';return;} const nice=new Date(selectedDate+'T00:00:00').toLocaleDateString(undefined,{weekday:'long',month:'long',day:'numeric'}); summary.textContent=`${nice} at ${selectedTime}`;}
                function openDetails(){if(!therapistId){alert('Please select a therapist first.');return;} if(!services[serviceId]){alert('Please select a valid service.');return;} if(!selectedDate||!selectedTime){alert('Please select a date and time first.');return;} if(selectedDate<todayIso){alert('Past dates are not allowed.');return;} stepCalendar.classList.remove('active'); stepDetails.classList.add('active'); const opt=therapistSelect.options[therapistSelect.selectedIndex]; document.getElementById('crm-sum-therapist').textContent=opt?opt.textContent.trim():'-'; const nice=new Date(selectedDate+'T00:00:00').toLocaleDateString(undefined,{weekday:'short',month:'short',day:'numeric'}); document.getElementById('crm-sum-dt').textContent=`${nice}, ${selectedTime}`; const sSel=document.getElementById('crm-session-type'); sSel.value=sessionType; document.getElementById('crm-sum-loc').textContent=sSel.value==='in-person'?'In-Person':'Online (Video)';}
                function closeDetails(e){if(e)e.preventDefault(); stepDetails.classList.remove('active'); stepCalendar.classList.add('active');}
                function submitBooking(){const msg=document.getElementById('crm-msg'),btn=document.getElementById('crm-confirm-btn'); if(!document.getElementById('crm-consent-privacy').checked||!document.getElementById('crm-consent-ohip').checked){msg.style.color='#dc2626';msg.textContent='Please accept both consent checkboxes.';return;} const first=document.getElementById('crm-first-name').value.trim(),last=document.getElementById('crm-last-name').value.trim(),email=document.getElementById('crm-email').value.trim(),phone=document.getElementById('crm-phone').value.trim(),reason=document.getElementById('crm-reason').value.trim(); if(first.length<2||last.length<2){msg.style.color='#dc2626';msg.textContent='Enter valid first and last name.';return;} if(!validEmail(email)){msg.style.color='#dc2626';msg.textContent='Enter a valid email address.';return;} if(phone && !validPhone(phone)){msg.style.color='#dc2626';msg.textContent='Enter a valid phone number.';return;} if(reason.length>1000){msg.style.color='#dc2626';msg.textContent='Reason is too long.';return;} if(!therapistId||!services[serviceId]||!selectedDate||!selectedTime){msg.style.color='#dc2626';msg.textContent='Incomplete booking selection.';return;} const sSel=document.getElementById('crm-session-type'); if(!['online','in-person'].includes(sSel.value)){msg.style.color='#dc2626';msg.textContent='Invalid appointment type.';return;} btn.disabled=true; btn.textContent='Confirming...'; msg.textContent=''; const slotBody=new URLSearchParams(); slotBody.append('action','crm_booking_flow_slots'); slotBody.append('nonce',bookingNonce); slotBody.append('therapist_id',therapistId); slotBody.append('date',selectedDate); slotBody.append('session_type',sSel.value||'online'); fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:slotBody.toString()}).then(r=>r.json()).then(slotRes=>{const live=normalizeSlots((slotRes&&Array.isArray(slotRes.slots))?slotRes.slots:[]); const stillAvailable=live.some(x=>x.time.toLowerCase()===String(selectedTime).toLowerCase() && x.available); if(!stillAvailable){recentlyBookedTime=selectedTime; selectedTime=''; updateSummary(); stepDetails.classList.remove('active'); stepCalendar.classList.add('active'); renderSlots(live); status.style.color='#dc2626'; status.textContent='This time slot is no longer available. Please select another highlighted time.'; msg.style.color='#dc2626'; msg.textContent='This time slot is no longer available. Please select another highlighted time.'; btn.disabled=false; btn.textContent='Confirm Appointment'; return null;} const body=new URLSearchParams(); body.append('action','crm_booking_flow_submit'); body.append('nonce',bookingNonce); body.append('booking_data[first_name]',first); body.append('booking_data[last_name]',last); body.append('booking_data[email]',email); body.append('booking_data[phone]',phone); body.append('booking_data[reason]',reason); body.append('booking_data[therapist_id]',therapistId); body.append('booking_data[service_id]',serviceId); body.append('booking_data[session_date]',selectedDate); body.append('booking_data[session_time]',selectedTime); body.append('booking_data[duration]','50'); body.append('booking_data[session_type]',sSel.value||'online'); return fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()}).then(r=>r.json());}).then(res=>{if(!res)return; if(res&&res.ok){msg.style.color='#16a34a';msg.textContent='Appointment confirmed successfully.'+(res.appointment&&res.appointment.id?(' ID: '+res.appointment.id):'');}else{const serverMsg=(res&&res.message)?String(res.message):'Booking failed.'; msg.style.color='#dc2626'; msg.textContent=serverMsg; const lower=serverMsg.toLowerCase(); if(lower.includes('no longer available')||lower.includes('select another time')||lower.includes('not available')){recentlyBookedTime=selectedTime; selectedTime=''; updateSummary(); stepDetails.classList.remove('active'); stepCalendar.classList.add('active'); status.style.color='#dc2626'; status.textContent='That slot was just booked. Please choose another highlighted time.'; loadSlots();}}}).catch(()=>{msg.style.color='#dc2626';msg.textContent='Booking API unavailable right now.';}).finally(()=>{btn.disabled=false;btn.textContent='Confirm Appointment';});}

                document.getElementById('crm-prev').onclick=()=>{viewDate=new Date(viewDate.getFullYear(),viewDate.getMonth()-1,1);scheduleMonthAvailability();};
                document.getElementById('crm-next').onclick=()=>{viewDate=new Date(viewDate.getFullYear(),viewDate.getMonth()+1,1);scheduleMonthAvailability();};
                document.getElementById('crm-continue-btn').onclick=openDetails;
                document.getElementById('crm-back-calendar').onclick=closeDetails;
                document.getElementById('crm-confirm-btn').onclick=submitBooking;
                document.getElementById('crm-service-list').addEventListener('click',e=>{const b=e.target.closest('.crm-service-item'); if(!b) return; serviceId=b.dataset.service; localStorage.setItem(serviceStorageKey, serviceId); applyServiceActive();});
                therapistSelect.addEventListener('change',()=>{therapistId=therapistSelect.value||''; selectedDate=''; selectedTime=''; dateTitle.textContent='Select a date'; slotGrid.innerHTML='<div>Select an available highlighted day.</div>'; updateSummary(); scheduleMonthAvailability();});
                document.getElementById('crm-session-type').addEventListener('change',function(){sessionType=this.value; document.getElementById('crm-sum-loc').textContent=this.value==='in-person'?'In-Person':'Online (Video)'; if(stepCalendar.classList.contains('active')){selectedDate=''; selectedTime=''; dateTitle.textContent='Select a date'; slotGrid.innerHTML='<div>Select an available highlighted day.</div>'; updateSummary(); scheduleMonthAvailability();}});

                let storedService='';
                try { storedService=localStorage.getItem(serviceStorageKey); } catch(e) { storedService=''; }
                if(storedService && services[storedService]) serviceId=storedService; else serviceId='50';
                applyServiceActive();
                if(therapistId) therapistSelect.value=therapistId;
                setFirstWeekday(); drawCal(); updateSummary(); scheduleMonthAvailability(); if(therapistId) loadSlots(); else slotGrid.innerHTML='<div>Select therapist and date.</div>';
            })();
        </script>
        <?php
        return ob_get_clean();
    }

    public function render_booking_details($atts) {
        return $this->render_booking_calendar($atts);
    }
}

new CRM_Booking_Flow_Shortcodes();
