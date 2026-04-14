<?php
if (!defined('ABSPATH')) exit;

class CRM_Profile_Booking_Shortcode {
    public function __construct() {
        add_shortcode('crm_profile_booking', [$this, 'render_shortcode']);
        add_action('wp_ajax_crm_profile_slots', [$this, 'ajax_profile_slots']);
        add_action('wp_ajax_nopriv_crm_profile_slots', [$this, 'ajax_profile_slots']);
        add_action('wp_ajax_crm_profile_continue_booking', [$this, 'ajax_continue_booking']);
        add_action('wp_ajax_nopriv_crm_profile_continue_booking', [$this, 'ajax_continue_booking']);
    }

    private function fallback_profile() {
        return [];
    }

    private function normalize_education_items($education) {
        if (!is_array($education)) return [];
        $out = [];
        foreach ($education as $row) {
            if (is_array($row)) {
                $out[] = $row;
                continue;
            }
            if (!is_string($row)) continue;
            $line = trim($row);
            if ($line === '') continue;

            $item = ['institution' => $line, 'degree' => '', 'duration' => ''];
            if (preg_match('/^\s*(.*?)\s+[—-]\s+(.*?)(?:\s+\(([^)]*)\))?\s*$/u', $line, $m)) {
                $item['university'] = trim((string) $m[1]);
                $item['degree'] = trim((string) $m[2]);
                $item['duration'] = !empty($m[3]) ? trim((string) $m[3]) : '';
            }
            $out[] = $item;
        }
        return $out;
    }

private function extract_bio_sections($text) {
        $text = is_string($text) ? trim($text) : '';
        if ($text === '') return ['bioHtml' => '', 'arabicBioHtml' => '', 'turkishBioHtml' => ''];

        $english = $text;
        $arabic = '';
        $turkish = '';

        // Clean up leading English header if it exists
        $english = preg_replace('/^(?:<p>)?\s*Biography\s*\(English\)\s*:\s*(?:<\/p>|<br\s*\/?>)?/isu', '', $english);

        // Extract Arabic/Urdu
        $arabic_pattern = '/(?:<p>\s*)?(السيرة الذاتية|Biography\s*\(Arabic\)\s*:)(?:\s*<\/p>|<br\s*\/?>|\s+)(.*)/isu';
        if (preg_match($arabic_pattern, $english, $m, PREG_OFFSET_CAPTURE)) {
            $arabic = trim($m[2][0]);
            // Slice the English string to stop right before the Arabic header
            $english = trim(substr($english, 0, $m[0][1])); 
        }

        // Extract Turkish
        $turkish_pattern = '/(?:<p>\s*)?(Türk Biyografisi)(?:\s*<\/p>|<br\s*\/?>|\s+)(.*)/isu';
        if (preg_match($turkish_pattern, $english, $m, PREG_OFFSET_CAPTURE)) {
            $turkish = trim($m[2][0]);
            // Slice the English string to stop right before the Turkish header
            $english = trim(substr($english, 0, $m[0][1]));
        }
        
        // Safety check: In case Turkish was placed after Arabic and got caught in the Arabic slice
        if (preg_match($turkish_pattern, $arabic, $m, PREG_OFFSET_CAPTURE)) {
            $turkish = trim($m[2][0]);
            $arabic = trim(substr($arabic, 0, $m[0][1]));
        }

        return [
            'bioHtml' => $this->format_bio_html($english),
            'arabicBioHtml' => $this->format_bio_html($arabic),
            'turkishBioHtml' => $this->format_bio_html($turkish),
        ];
    }

    private function format_bio_html($text) {
        if ($text === '') return '';
        // If it already contains HTML tags (like from ACF), leave it alone so we don't double-wrap it
        if (strpos($text, '<p>') !== false || strpos($text, '<br') !== false) {
            return $text;
        }
        return wpautop(esc_html($text));
    }

  private function normalize_profile($raw, $basic = [], $local_fallback = []) {
        $fb = array_merge($this->fallback_profile(), is_array($local_fallback) ? $local_fallback : []);
        $node = (is_array($raw) && isset($raw['profile']) && is_array($raw['profile'])) ? $raw['profile'] : (is_array($raw) ? $raw : []);
        $languages = (!empty($node['languages']) && is_array($node['languages'])) ? $node['languages'] : (isset($fb['languages']) ? $fb['languages'] : []);
        $specializations = (!empty($node['specializations']) && is_array($node['specializations'])) ? $node['specializations'] : (isset($fb['specializations']) ? $fb['specializations'] : []);
        $approaches = (!empty($node['treatmentApproaches']) && is_array($node['treatmentApproaches'])) ? $node['treatmentApproaches'] : ((!empty($node['approaches']) && is_array($node['approaches'])) ? $node['approaches'] : (isset($fb['approaches']) ? $fb['approaches'] : []));
        $age_groups = (!empty($node['ageGroups']) && is_array($node['ageGroups'])) ? $node['ageGroups'] : [];
        $education = (!empty($node['education']) && is_array($node['education'])) ? $node['education'] : (isset($fb['education']) ? $fb['education'] : []);
        if (!is_array($languages)) $languages = [];
        if (!is_array($specializations)) $specializations = [];
        if (!is_array($approaches)) $approaches = [];
        if (!is_array($age_groups)) $age_groups = [];
        if (!is_array($education)) $education = [];
        $education = $this->normalize_education_items($education);

        $years = '';
        if (isset($node['yearsOfExperience']) && $node['yearsOfExperience'] !== '') {
            $years = (string) $node['yearsOfExperience'];
        } elseif (!empty($raw['yearsOfExperience'])) {
            $years = (string) $raw['yearsOfExperience'];
        } elseif (!empty($basic['yearsOfExperience'])) {
            $years = (string) $basic['yearsOfExperience'];
        } elseif (!empty($fb['yearsOfExperience'])) {
            $years = (string) $fb['yearsOfExperience'];
        }

        $role = 'Psychotherapist'; // Default
        if (!empty($raw['title'])) {
            $role = (string) $raw['title']; // This captures "Psychotherapist, Art Therapist" from your JSON
        } elseif (!empty($node['title'])) {
            $role = (string) $node['title'];
        } elseif (!empty($fb['role'])) {
            $role = (string) $fb['role'];
}

        // --- NEW SMART BIO PARSING LOGIC START ---
        
        // 1. Get the raw text to parse (Prioritize API, fallback to ACF)
        $raw_bio_text = !empty($raw['bio']) ? (string)$raw['bio'] : (!empty($fb['bioHtml']) ? (string)$fb['bioHtml'] : '');
        
        // 2. Run our smart parser
        $parsed = $this->extract_bio_sections($raw_bio_text);
        
        $bio_html = $parsed['bioHtml'];
        $arabic_bio_html = $parsed['arabicBioHtml'];
        $turkish_bio_html = $parsed['turkishBioHtml'];

        // 3. If the combined text didn't have translations, but the individual ACF fields do, use those
        if ($arabic_bio_html === '' && !empty($fb['arabicBioHtml'])) {
            $arabic_bio_html = (string) $fb['arabicBioHtml'];
        }
        if ($turkish_bio_html === '' && !empty($fb['turkishBioHtml'])) {
            $turkish_bio_html = (string) $fb['turkishBioHtml'];
        }
        
        // --- NEW SMART BIO PARSING LOGIC END ---

        $image_url = !empty($raw['profilePicture']) ? $raw['profilePicture'] : (!empty($raw['avatarUrl']) ? $raw['avatarUrl'] : (!empty($raw['image']) ? $raw['image'] : (!empty($node['profilePicture']) ? $node['profilePicture'] : (!empty($fb['imageUrl']) ? $fb['imageUrl'] : 'https://storage.googleapis.com/banani-avatars/avatar%2Fmale%2F35-50%2FMiddle%2520Eastern%2F4'))));

        return [
            'fullName' => !empty($raw['fullName']) ? $raw['fullName'] : (!empty($basic['fullName']) ? $basic['fullName'] : (isset($fb['fullName']) ? $fb['fullName'] : '')),
            'email' => !empty($raw['email']) ? $raw['email'] : (!empty($basic['email']) ? $basic['email'] : (isset($fb['email']) ? $fb['email'] : '')),
            'yearsOfExperience' => $years,
            'licenseType' => !empty($node['licenseType']) ? $node['licenseType'] : (!empty($raw['licenseType']) ? $raw['licenseType'] : (isset($fb['licenseType']) ? $fb['licenseType'] : '')),
            'languages' => $languages,
            'specializations' => $specializations,
            'approaches' => $approaches,
            'role' => $role,
            'clientFocus' => !empty($age_groups) ? implode(', ', $age_groups) : (!empty($fb['clientFocus']) ? $fb['clientFocus'] : 'Adults & Children'),
            'imageUrl' => $image_url,
            'phone' => !empty($raw['phone']) ? $raw['phone'] : (!empty($fb['phone']) ? $fb['phone'] : '+15488660366'),
            'bioHtml' => $bio_html,
            'arabicBioHtml' => $arabic_bio_html,
            'turkishBioHtml' => $turkish_bio_html, // FIXED: Now uses the extracted variable instead of just $fb
            'education' => $education,
        ];
    }

    private function normalize_list($value) {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $v) {
                $v = sanitize_text_field((string) $v);
                if ($v !== '') $out[] = $v;
            }
            return $out;
        }
        if (is_string($value) && trim($value) !== '') {
            $parts = array_map('trim', explode(',', $value));
            $parts = array_filter($parts, function($x){ return $x !== ''; });
            return array_values($parts);
        }
        return [];
    }

    private function get_services_cache() {
        $services = function_exists('crm_get_cached_services') ? crm_get_cached_services() : [];
        return is_array($services) ? $services : [];
    }

    private function get_services_for_therapist($therapist_id) {
        $therapist_id = sanitize_text_field((string) $therapist_id);
        if ($therapist_id === '') return [];
        $services = $this->get_services_cache();
        $matched = [];
        foreach ($services as $s) {
            if (!is_array($s)) continue;
            $sid = isset($s['id']) ? sanitize_text_field((string) $s['id']) : '';
            $name = isset($s['serviceName']) ? sanitize_text_field((string) $s['serviceName']) : '';
            if ($sid === '' || $name === '') continue;
            $therapists = isset($s['therapists']) && is_array($s['therapists']) ? $s['therapists'] : [];
            foreach ($therapists as $t) {
                if (!is_array($t)) continue;
                $tid = '';
                if (isset($t['therapistId'])) {
                    $tid = sanitize_text_field((string) $t['therapistId']);
                } elseif (isset($t['id'])) {
                    $tid = sanitize_text_field((string) $t['id']);
                }
                if ($tid !== '' && $tid === $therapist_id) {
                    $matched[$sid] = $name;
                    break;
                }
            }
        }
        return $matched;
    }

    private function get_current_team_fallback_data() {
        $post_id = 0;
        if (is_singular('team')) {
            $post_id = get_queried_object_id();
        } elseif (get_post_type(get_the_ID()) === 'team') {
            $post_id = get_the_ID();
        }
        if (!$post_id) return [];

        $data = [
            'fullName' => get_the_title($post_id),
            'email' => '',
            'yearsOfExperience' => '',
            'licenseType' => '',
            'languages' => [],
            'specializations' => [],
            'approaches' => [],
            'role' => '',
            'clientFocus' => '',
            'imageUrl' => '',
            'phone' => '',
            'bioHtml' => '',
            'arabicBioHtml' => '',
            'turkishBioHtml' => ''
            ,
            'education' => []
        ];

        if (function_exists('get_field')) {
            $data['email'] = (string) get_field('email', $post_id);
            $data['yearsOfExperience'] = (string) get_field('team_experience', $post_id);
            $data['licenseType'] = (string) get_field('team_credentials', $post_id);
            $data['languages'] = $this->normalize_list(get_field('team_languages', $post_id));
            $data['role'] = (string) get_field('team_role', $post_id);
            $data['clientFocus'] = (string) get_field('team_clients', $post_id);
            $data['phone'] = (string) get_field('phone', $post_id);
            $data['bioHtml'] = (string) get_field('biography', $post_id);
            $data['arabicBioHtml'] = (string) get_field('arabic_biography', $post_id);
            $data['turkishBioHtml'] = (string) get_field('turkish_biography', $post_id);
            $img = get_field('team_image', $post_id);
            if (is_array($img) && !empty($img['url'])) $data['imageUrl'] = (string) $img['url'];
            if (is_string($img) && filter_var($img, FILTER_VALIDATE_URL)) $data['imageUrl'] = $img;
        }

        $areas = get_the_terms($post_id, 'team-category');
        if ($areas && !is_wp_error($areas)) {
            foreach ($areas as $term) {
                $data['specializations'][] = $term->name;
            }
        }

        $approaches = get_the_terms($post_id, 'team-tag');
        if ($approaches && !is_wp_error($approaches)) {
            foreach ($approaches as $term) {
                $data['approaches'][] = $term->name;
            }
        }

        if (function_exists('have_rows') && have_rows('team_education_list', $post_id)) {
            while (have_rows('team_education_list', $post_id)) {
                the_row();
                $data['education'][] = [
                    'university' => (string) get_sub_field('university_name'),
                    'degree' => (string) get_sub_field('degree_title'),
                    'duration' => (string) get_sub_field('duration')
                ];
            }
        }

        if (function_exists('have_rows') && have_rows('additional_team_education_list', $post_id)) {
            while (have_rows('additional_team_education_list', $post_id)) {
                the_row();
                $data['education'][] = [
                    'university' => (string) get_sub_field('additional_university_name'),
                    'degree' => (string) get_sub_field('additioonal_degree_title'),
                    'duration' => (string) get_sub_field('additional_duration')
                ];
            }
        }

        return $data;
    }

    private function render_experience($value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        if (preg_match('/^\d+$/', $value)) return $value . ' Years';
        return $value;
    }

    private function normalize_slots($res) {
        $raw = (is_array($res) && !empty($res['slots']) && is_array($res['slots'])) ? $res['slots'] : [];
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

    private function normalize_name_key($value) {
        $value = (string) $value;
        $value = strtolower($value);
        $value = preg_replace('/[\.,\-\/\(\)\[\]]+/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', trim($value));
        return $value;
    }

    private function find_therapist_by_name($therapists, $name) {
        if (!is_array($therapists) || empty($name)) return null;
        $target = $this->normalize_name_key($name);
        if ($target === '') return null;

        foreach ($therapists as $t) {
            $full = isset($t['fullName']) ? $this->normalize_name_key($t['fullName']) : '';
            if ($full !== '' && $full === $target) return $t;
        }

        foreach ($therapists as $t) {
            $full = isset($t['fullName']) ? $this->normalize_name_key($t['fullName']) : '';
            if ($full !== '' && (strpos($full, $target) !== false || strpos($target, $full) !== false)) return $t;
        }

        return null;
    }

    public function ajax_profile_slots() {
        if (!check_ajax_referer('crm_public_booking_nonce', 'nonce', false)) {
            wp_send_json(['slots' => [], 'used_fallback' => false, 'message' => 'Security check failed.']);
        }
        if (crm_booking_is_rate_limited('profile_slots|' . crm_booking_get_client_ip(), 60, MINUTE_IN_SECONDS)) {
            wp_send_json(['slots' => [], 'used_fallback' => false, 'message' => 'Too many requests.']);
        }

        $id = isset($_POST['therapist_id']) ? sanitize_text_field($_POST['therapist_id']) : '';
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        $type = isset($_POST['session_type']) ? sanitize_text_field($_POST['session_type']) : 'online';
        $api = new CRM_API_Handler();
        $res = $api->get_slots($id, $date, $type);
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

    public function ajax_continue_booking() {
        if (!check_ajax_referer('crm_public_booking_nonce', 'nonce', false)) {
            wp_send_json(['ok' => false, 'message' => 'Security check failed.', 'used_fallback' => false]);
        }
        if (crm_booking_is_rate_limited('profile_submit|' . crm_booking_get_client_ip(), 10, MINUTE_IN_SECONDS)) {
            wp_send_json(['ok' => false, 'message' => 'Too many requests. Please wait and try again.', 'used_fallback' => false]);
        }
        if (!crm_booking_validate_recaptcha_ajax('profile_continue_booking')) {
            return;
        }

        $data = isset($_POST['booking_data']) && is_array($_POST['booking_data']) ? $_POST['booking_data'] : [];
        $email = !empty($data['email']) ? sanitize_email($data['email']) : '';
        $phone = !empty($data['phone']) ? sanitize_text_field($data['phone']) : '';
        $date_of_birth = !empty($data['dateOfBirth']) ? sanitize_text_field($data['dateOfBirth']) : '';
        $notes = !empty($data['notes']) ? sanitize_textarea_field($data['notes']) : 'Booked via profile widget';
        if (!is_email($email)) {
            wp_send_json(['ok' => false, 'message' => 'A valid email is required.', 'used_fallback' => false]);
        }
        if ($phone !== '' && !preg_match('/^[0-9\+\-\s\(\)]{7,20}$/', $phone)) {
            wp_send_json(['ok' => false, 'message' => 'Please enter a valid phone number.', 'used_fallback' => false]);
        }
        if ($date_of_birth !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_of_birth)) {
                wp_send_json(['ok' => false, 'message' => 'Please enter a valid date of birth.', 'used_fallback' => false]);
            }
            if ($date_of_birth > current_time('Y-m-d')) {
                wp_send_json(['ok' => false, 'message' => 'Date of birth cannot be in the future.', 'used_fallback' => false]);
            }
        }
        if (strlen($notes) > 1000) {
            wp_send_json(['ok' => false, 'message' => 'Notes are too long.', 'used_fallback' => false]);
        }
        $payload = [
            'fullName' => !empty($data['fullName']) ? sanitize_text_field($data['fullName']) : 'Website Lead',
            'email' => $email,
            'therapistId' => !empty($data['therapistId']) ? sanitize_text_field($data['therapistId']) : '',
            'serviceId' => !empty($data['serviceId']) ? sanitize_text_field($data['serviceId']) : '50',
            'sessionDate' => !empty($data['sessionDate']) ? sanitize_text_field($data['sessionDate']) : '',
            'sessionTime' => !empty($data['sessionTime']) ? sanitize_text_field($data['sessionTime']) : '',
            'duration' => !empty($data['duration']) ? sanitize_text_field($data['duration']) : '50',
            'sessionType' => !empty($data['sessionType']) ? sanitize_text_field($data['sessionType']) : 'online',
            'notes' => $notes
        ];
        if ($phone !== '') {
            $payload['phone'] = $phone;
        }
        if ($date_of_birth !== '') {
            $payload['dateOfBirth'] = $date_of_birth;
        }
        if (empty($payload['therapistId']) || empty($payload['sessionDate']) || empty($payload['sessionTime'])) {
            wp_send_json(['ok' => false, 'message' => 'Please select therapist, date and time.', 'used_fallback' => false], 400);
        }
        $allowed_services = $this->get_services_for_therapist($payload['therapistId']);
        if (!empty($allowed_services) && !isset($allowed_services[$payload['serviceId']])) {
            wp_send_json(['ok' => false, 'message' => 'Selected service is not available for this therapist.', 'used_fallback' => false], 400);
        }
        if (!in_array($payload['sessionType'], ['online', 'in-person'], true)) {
            $payload['sessionType'] = 'online';
        }

        $api = new CRM_API_Handler();
        $slot_res = $api->get_slots($payload['therapistId'], $payload['sessionDate'], $payload['sessionType']);
        $slots = $this->normalize_slots($slot_res);
        if (!$this->is_time_available_in_slots($slots, $payload['sessionTime'])) {
            wp_send_json(['ok' => false, 'message' => 'This time slot is no longer available. Please select another time.', 'used_fallback' => false], 409);
        }

        $result = $api->book_appointment($payload);
        if (is_array($result) && !empty($result['appointment']['id'])) {
            wp_send_json(['ok' => true, 'appointment' => $result['appointment'], 'used_fallback' => false]);
        }
        $booking_error = $api->get_last_error();
        $message = is_array($result) && !empty($result['message'])
            ? sanitize_text_field($result['message'])
            : ($booking_error !== '' ? $booking_error : 'Booking could not be completed.');
        wp_send_json(['ok' => false, 'message' => $message, 'used_fallback' => false], 502);
    }

    public function render_shortcode($atts) {
        $atts = shortcode_atts(['therapist_id' => '', 'therapist_name' => '', 'service_id' => '', 'session_type' => 'online'], $atts, 'crm_profile_booking');
        $api = new CRM_API_Handler();
        $therapists = $api->get_all_therapists();
        if (!is_array($therapists) || empty($therapists)) {
            $therapists = crm_get_cached_therapists();
        }
        if (!is_array($therapists)) $therapists = [];

        $request_therapist_id = isset($_GET['therapist_id']) ? sanitize_text_field($_GET['therapist_id']) : '';
        $request_therapist_name = isset($_GET['therapist_name']) ? sanitize_text_field($_GET['therapist_name']) : '';
        $attr_therapist_name = !empty($atts['therapist_name']) ? sanitize_text_field($atts['therapist_name']) : '';
        $resolved_therapist_id = !empty($request_therapist_id) ? $request_therapist_id : $atts['therapist_id'];
        if (empty($resolved_therapist_id) && is_singular('team')) {
            $resolved_therapist_id = (string) get_post_meta(get_queried_object_id(), '_crm_therapist_id', true);
        }
        $resolved_therapist_name = !empty($request_therapist_name) ? $request_therapist_name : $attr_therapist_name;

        $selected = !empty($therapists) ? $therapists[0] : [];
        if (!empty($resolved_therapist_id)) {
            foreach ($therapists as $t) {
                if (!empty($t['id']) && (string)$t['id'] === (string)$resolved_therapist_id) { $selected = $t; break; }
            }
        } elseif (!empty($resolved_therapist_name)) {
            $by_name = $this->find_therapist_by_name($therapists, $resolved_therapist_name);
            if (is_array($by_name)) $selected = $by_name;
        }

        $cached_profile = !empty($resolved_therapist_id) ? crm_get_cached_therapist_profile($resolved_therapist_id) : [];
        $tid = !empty($selected['id']) ? (string)$selected['id'] : '';
        if (empty($cached_profile) && !empty($tid)) {
            $cached_profile = crm_get_cached_therapist_profile($tid);
        }
        $team_fallback = $this->get_current_team_fallback_data();
        $profile_fallback = array_merge($team_fallback, is_array($cached_profile) ? $cached_profile : []);
        $raw = $tid ? $api->get_therapist_profile($tid) : [];
        $p = $this->normalize_profile($raw, $selected, $profile_fallback);
        $about_name = explode(',', $p['fullName']);
        $about_name = trim($about_name[0]);
        if ($about_name === '') $about_name = 'Therapist';
        $archive_url = get_post_type_archive_link('therapist');
        if (!$archive_url) $archive_url = home_url('/therapist/');

        $request_service_id = isset($_GET['service_id']) ? sanitize_text_field($_GET['service_id']) : '';
        $resolved_service_id = $request_service_id !== '' ? $request_service_id : (string) $atts['service_id'];
        if ($resolved_service_id === '' && is_singular('crm_services')) {
            $resolved_service_id = (string) get_post_meta(get_queried_object_id(), '_crm_service_id', true);
        }
        $services_for_therapist = $tid ? $this->get_services_for_therapist($tid) : [];
        if (!empty($services_for_therapist) && $resolved_service_id !== '' && !isset($services_for_therapist[$resolved_service_id])) {
            $resolved_service_id = '';
        }
        if ($resolved_service_id === '' && !empty($services_for_therapist)) {
            $service_ids = array_keys($services_for_therapist);
            $resolved_service_id = (string) $service_ids[0];
        }
        if ($resolved_service_id === '') $resolved_service_id = '50';

        ob_start(); ?>
        <div class="crm-profile-export-wrapper">
            <link rel="preconnect" href="https://fonts.googleapis.com" />
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet" />
            <script src="https://code.iconify.design/iconify-icon/3.0.0/iconify-icon.min.js"></script>
            <style>
                .crm-profile-export-wrapper{--background:#fbfef9;--foreground:#123029;--border:#00000014;--primary:#3e5640;--secondary:#eaf7ea;--muted:#f4f6f5;--muted-foreground:#6b7280;--radius-sm:4px;--radius-md:6px;--radius-lg:8px;--radius-xl:12px;font-family:Inter,system-ui,sans-serif;background: linear-gradient(67.68deg, rgba(248, 235, 221, 0.6) 15.06%, #F2F7F2 100%);color:var(--foreground)}
                .crm-profile-export-wrapper *{box-sizing:border-box}.crm-profile-container{max-width:1200px;margin:0 auto;padding:40px}
                .crm-breadcrumbs{display:flex;gap:8px;font-size:13px;color:var(--muted-foreground);margin-bottom:24px; align-items:center}
                .crm-profile-intro{display:flex;gap:30px;background:#fff;padding:30px;border:1px solid var(--border);border-radius:var(--radius-lg);margin-bottom:30px}
                .crm-profile-image{width:200px;height:200px;overflow:hidden;border-radius:var(--radius-md)}.crm-profile-image img{width:100%;height:100%;object-fit:cover}
                .crm-profile-info{ width:calc(100% - 230px)}
                .crm-profile-info h1{margin:0 0 4px;font-size:32px !important;}.crm-profile-role{font-size:14px;color:var(--primary);font-weight:700;margin-bottom:18px;text-transform:uppercase;letter-spacing:.05em}
                .crm-profile-meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px}.crm-profile-meta-item{display:flex;gap:10px}.crm-profile-meta-item h5{margin:0;font-size:12px}.crm-profile-meta-item p{margin:0;font-size:13px;color:var(--muted-foreground)}
                .crm-btn{display:inline-flex;gap:6px;border:1px solid var(--border);border-radius:999px;padding:8px 14px;text-decoration:none;color:var(--foreground);align-items:center}
                .crm-profile-content-grid{display:grid;grid-template-columns:1fr 380px;gap:30px}
                .crm-profile-details h2{font-size:24px;border-bottom:1px solid var(--border);padding-bottom:8px;margin:0 0 12px}.crm-profile-details section{margin-bottom:24px}.crm-profile-details p{color:#35544d}
                .crm-profile-details .crm-bio-rtl{font-family: 'Tajawal', sans-serif !important; direction:rtl;text-align:right}
                .crm-tags{display:flex;flex-wrap:wrap;gap:8px}.crm-badge{background:var(--secondary);padding:4px 10px;border-radius:999px;font-size:13px}
                .crm-edu-list{display:grid;grid-template-columns:1fr;gap:10px}.crm-edu-item{border:1px solid var(--border);border-radius:8px;padding:10px;background:#fff}.crm-edu-item strong{display:block}
                .crm-booking-widget{background:#fff;border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;position:sticky;top:24px}
                .crm-calendar-nav{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}.crm-calendar-nav-btn{width:28px;height:28px;border:1px solid var(--border);border-radius:4px;background:#fff}
                .crm-calendar-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px}.crm-day-head{font-size:12px;color:var(--muted-foreground);text-align:center;padding:6px 0}
                .crm-cell{height:34px;border:none;border-radius:4px;background:#fff;cursor:pointer}.crm-cell.muted{color:#ccc;cursor:default}.crm-cell.available{background:#f2fbf5;color:var(--primary)}.crm-cell.active{background:var(--primary);color:#fff}
                .crm-time-slots-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:10px 0 14px}.crm-time-slot{border:1px solid var(--border);background:#fff;border-radius:6px;padding:10px;cursor:pointer}.crm-time-slot.selected{border-color:var(--primary);background:#f2fbf5;color:var(--primary)}.crm-time-slot.disabled{opacity:.55;cursor:not-allowed}.crm-time-slot.booked{background:#fef2f2;border-color:#ef4444;color:#b91c1c;text-decoration:line-through}
                .crm-booking-summary{background:var(--muted);padding:12px;border-radius:6px;margin-bottom:12px}.crm-row{display:flex;justify-content:space-between;font-size:13px;margin:0 0 6px}.crm-row:last-child{margin:0;padding-top:6px;border-top:1px solid var(--border)}
                .crm-btn-primary{width:100%;background:var(--primary);color:#fff;border:none;border-radius:10px;padding:10px;cursor:pointer}.crm-api-status{font-size:12px;text-align:center;color:var(--muted-foreground);margin-top:8px;min-height:16px}
                .crm-booking-modal{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;padding:16px;z-index:99999}
                .crm-booking-modal.open{display:flex}
                .crm-booking-modal-card{background:#fff;width:100%;max-width:520px;border-radius:12px;padding:18px;border:1px solid var(--border)}
                .crm-booking-modal-card h4{margin:0 0 12px;font-size:20px}
                .crm-booking-modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
                .crm-booking-modal-card input,.crm-booking-modal-card select,.crm-booking-modal-card textarea{width:100%;border:1px solid var(--border);border-radius:8px;padding:10px}
                .crm-modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}
                .crm-btn-secondary{background:#fff;border:1px solid var(--border);border-radius:10px;padding:10px 14px;cursor:pointer}
                .crm-modal-message{font-size:13px; margin-top:10px; min-height:18px}
                .crm-success-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;padding:16px;z-index:99999}
                .crm-success-overlay.open{display:flex}
                .crm-success-card{background:#fff;max-width:420px;width:100%;border-radius:12px;padding:18px;border:1px solid var(--border);text-align:center;box-shadow:0 20px 40px rgba(15,23,42,.18)}
                .crm-success-card h4{margin:0 0 8px;font-size:20px}
                .crm-success-card p{margin:0 0 14px;color:#475569;font-size:14px}
                .crm-success-card button{background:var(--primary);color:#fff;border:none;border-radius:10px;padding:10px 16px;cursor:pointer}
                @media (max-width: 960px){.crm-profile-container{padding:20px}.crm-profile-intro{flex-direction:column}.crm-profile-content-grid{grid-template-columns:1fr} .crm-profile-info{width:100%}}
            </style>
            <div class="crm-profile-container">
                <div class="crm-breadcrumbs"><a href="<?php echo esc_url(home_url('/')); ?>">Home</a><iconify-icon icon="lucide:chevron-right"></iconify-icon><a href="<?php echo esc_url($archive_url); ?>">Our Therapists</a><iconify-icon icon="lucide:chevron-right"></iconify-icon><span><?php echo esc_html($p['fullName']); ?></span></div>
                <div class="crm-profile-intro">
                    <div class="crm-profile-image"><img src="<?php echo esc_url($p['imageUrl']); ?>" alt="<?php echo esc_attr($p['fullName']); ?>"></div>
                    <div class="crm-profile-info">
                        <h1><?php echo esc_html($p['fullName']); ?></h1><div class="crm-profile-role"><?php echo esc_html($p['role']); ?></div>
                        <div class="crm-profile-meta-grid">
                            <div class="crm-profile-meta-item"><iconify-icon icon="lucide:award"></iconify-icon><div><h5>Credentials</h5><p><?php echo esc_html($p['licenseType']); ?></p></div></div>
                            <div class="crm-profile-meta-item"><iconify-icon icon="lucide:briefcase"></iconify-icon><div><h5>Experience</h5><p><?php echo esc_html($this->render_experience($p['yearsOfExperience'])); ?></p></div></div>
                            <div class="crm-profile-meta-item"><iconify-icon icon="lucide:users"></iconify-icon><div><h5>Client Focus</h5><p><?php echo esc_html($p['clientFocus']); ?></p></div></div>
                            <div class="crm-profile-meta-item"><iconify-icon icon="lucide:globe"></iconify-icon><div><h5>Languages</h5><p><?php echo esc_html(implode(', ', $p['languages'])); ?></p></div></div>
                        </div>
                        <div style="display:flex;gap:10px"><a class="crm-btn" href="mailto:<?php echo esc_attr($p['email']); ?>"><iconify-icon icon="lucide:mail"></iconify-icon>Send Email</a><a class="crm-btn" href="tel:<?php echo esc_attr($p['phone']); ?>"><iconify-icon icon="lucide:phone"></iconify-icon>Call Clinic</a></div>
                    </div>
                </div>
                <div class="crm-profile-content-grid">
                    <div class="crm-profile-details">
                        <section><h2>About <?php echo esc_html($about_name); ?></h2><?php if (!empty($p['bioHtml'])) { echo wp_kses_post($p['bioHtml']); } else { ?><p>Profile data is currently unavailable from the CRM API.</p><?php } ?></section>
                        <?php if (!empty($p['arabicBioHtml'])) : ?><section><h2 class="crm-bio-rtl">السيرة الذاتية</h2><div class="crm-bio-rtl"><?php echo wp_kses_post($p['arabicBioHtml']); ?></div></section><?php endif; ?>
                        <?php if (!empty($p['turkishBioHtml'])) : ?><section><h2>Türk Biyografisi</h2><?php echo wp_kses_post($p['turkishBioHtml']); ?></section><?php endif; ?>
                        <?php if (!empty($p['education']) && is_array($p['education'])) : ?>
                        <section><h2>Education</h2><div class="crm-edu-list"><?php foreach ($p['education'] as $edu): $uni = isset($edu['university']) ? $edu['university'] : (isset($edu['institution']) ? $edu['institution'] : ''); $degree = isset($edu['degree']) ? $edu['degree'] : ''; $dur = isset($edu['duration']) ? $edu['duration'] : ''; if ($uni === '' && $degree === '' && $dur === '') continue; ?><div class="crm-edu-item"><?php if ($uni !== ''): ?><strong><?php echo esc_html($uni); ?></strong><?php endif; ?><?php if ($degree !== ''): ?><div><?php echo esc_html($degree); ?></div><?php endif; ?><?php if ($dur !== ''): ?><small><?php echo esc_html($dur); ?></small><?php endif; ?></div><?php endforeach; ?></div></section>
                        <?php endif; ?>
                        <section><h2>Areas of Practice</h2><div class="crm-tags"><?php foreach ($p['specializations'] as $s): ?><span class="crm-badge"><?php echo esc_html($s); ?></span><?php endforeach; ?></div></section>
                        <section><h2>Therapeutic Approaches</h2><div class="crm-tags"><?php foreach ($p['approaches'] as $a): ?><span class="crm-badge"><?php echo esc_html($a); ?></span><?php endforeach; ?></div></section>
                    </div>
                    <aside>
                        <div class="crm-booking-widget" id="crm-profile-widget" data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-therapist="<?php echo esc_attr($tid); ?>" data-service="<?php echo esc_attr($resolved_service_id); ?>" data-session="<?php echo esc_attr($atts['session_type']); ?>" data-services="<?php echo esc_attr(wp_json_encode($services_for_therapist)); ?>">
                            <h3 style="text-align:center;margin:0 0 4px">Book an Appointment</h3><p style="text-align:center;color:#6b7280;font-size:13px;margin:0 0 12px">Select a date and time to schedule a session.</p>
                            <?php if (!empty($services_for_therapist)) : ?>
                                <div style="margin-bottom:12px;">
                                    <label for="crm-profile-service" style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;">Service</label>
                                    <select id="crm-profile-service" style="width:100%;border:1px solid var(--border);border-radius:8px;padding:10px;">
                                        <?php foreach ($services_for_therapist as $sid => $sname): ?>
                                            <option value="<?php echo esc_attr($sid); ?>" <?php selected((string)$sid, (string)$resolved_service_id); ?>><?php echo esc_html($sname); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                            <div class="crm-calendar-nav"><button class="crm-calendar-nav-btn" id="crm-prev" type="button"><iconify-icon icon="lucide:chevron-left"></iconify-icon></button><span id="crm-month-title"></span><button class="crm-calendar-nav-btn" id="crm-next" type="button"><iconify-icon icon="lucide:chevron-right"></iconify-icon></button></div>
                            <div class="crm-calendar-grid" id="crm-calendar-grid"></div>
                            <div style="display:flex;justify-content:space-between;font-size:14px;font-weight:600;margin:12px 0 8px">Available Times <span id="crm-slots-date" style="font-weight:400;color:#6b7280">Select a date</span></div>
                            <div class="crm-time-slots-grid" id="crm-slots-grid"></div>
                            <div class="crm-booking-summary"><div class="crm-row"><span>Date</span><span id="crm-sum-date">-</span></div><div class="crm-row"><span>Time</span><span id="crm-sum-time">-</span></div><div class="crm-row"><span>Duration</span><span>30 min</span></div><div class="crm-row"><span>Session Type</span><span>In-person or Online</span></div></div>
                            <button class="crm-btn-primary" id="crm-continue" type="button">Continue Booking</button><div class="crm-api-status" id="crm-status"></div>
                        </div>
                    </aside>
                </div>
            </div>
            <div class="crm-booking-modal" id="crm-booking-modal">
                <div class="crm-booking-modal-card">
                    <h4>Complete Booking</h4>
                    <div class="crm-booking-modal-grid">
                        <input type="text" id="crm-client-name" placeholder="Your full name" required>
                        <input type="email" id="crm-client-email" placeholder="Email address" required>
                    </div>
                    <div class="crm-booking-modal-grid" style="margin-top:10px">
                        <input type="text" id="crm-client-phone" placeholder="Phone number (optional)">
                        <input type="date" id="crm-client-dob" placeholder="Date of birth (optional)">
                    </div>
                    <div style="margin-top:10px">
                        <select id="crm-client-session-type">
                            <option value="online">Online</option>
                            <option value="in-person">In-person</option>
                        </select>
                    </div>
                    <div style="margin-top:10px">
                        <textarea id="crm-client-notes" rows="3" placeholder="Notes (optional)"></textarea>
                    </div>
                    <?php echo crm_booking_render_recaptcha_field('crm-profile-recaptcha-v2'); ?>
                    <div class="crm-modal-message" id="crm-modal-message"></div>
                    <div class="crm-modal-actions">
                        <button type="button" class="crm-btn-secondary" id="crm-close-modal">Cancel</button>
                        <button type="button" class="crm-btn-primary" style="width:auto" id="crm-submit-booking">Confirm Appointment</button>
                    </div>
                </div>
            </div>
            <div class="crm-success-overlay" id="crm-success-overlay">
                <div class="crm-success-card">
                    <h4>Appointment Confirmed</h4>
                    <p id="crm-success-message">Your appointment has been booked successfully.</p>
                    <button type="button" id="crm-success-ok">OK</button>
                </div>
            </div>
        </div>
        <script>
            (function(){
                const root=document.getElementById('crm-profile-widget'); if(!root) return;
                const ajaxUrl=root.dataset.ajax, therapistId=root.dataset.therapist||'', sessionType=root.dataset.session||'online';
                let serviceId=root.dataset.service||'50';
                let services={};
                try{services=JSON.parse(root.dataset.services||'{}')||{}}catch(e){services={};}
                const bookingNonce='<?php echo esc_js(wp_create_nonce('crm_public_booking_nonce')); ?>';
                const cal=document.getElementById('crm-calendar-grid'), monthTitle=document.getElementById('crm-month-title'), slotsGrid=document.getElementById('crm-slots-grid');
                const slotsDate=document.getElementById('crm-slots-date'), sumDate=document.getElementById('crm-sum-date'), sumTime=document.getElementById('crm-sum-time');
                const status=document.getElementById('crm-status'), prev=document.getElementById('crm-prev'), next=document.getElementById('crm-next'), cont=document.getElementById('crm-continue');
                const modal=document.getElementById('crm-booking-modal'), closeModalBtn=document.getElementById('crm-close-modal');
                const submitBookingBtn=document.getElementById('crm-submit-booking'), nameInput=document.getElementById('crm-client-name');
                const emailInput=document.getElementById('crm-client-email'), phoneInput=document.getElementById('crm-client-phone'), dobInput=document.getElementById('crm-client-dob'), notesInput=document.getElementById('crm-client-notes');
                const sessionTypeInput=document.getElementById('crm-client-session-type');
                const modalMessage=document.getElementById('crm-modal-message');
                const successOk=document.getElementById('crm-success-ok');
                const days=['Su','Mo','Tu','We','Th','Fr','Sa'];
                const serviceSelect=document.getElementById('crm-profile-service');
                let view=new Date(), selectedDate='', selectedTime='';
                const iso=d=>`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
                const todayIso=iso(new Date());
                function validPhone(v){return /^[0-9+\-\s()]{7,20}$/.test(v);}
                function drawCalendar(){
                    const y=view.getFullYear(),m=view.getMonth(); monthTitle.textContent=view.toLocaleString(undefined,{month:'long',year:'numeric'}); cal.innerHTML='';
                    days.forEach(n=>{const h=document.createElement('div');h.className='crm-day-head';h.textContent=n;cal.appendChild(h);});
                    const first=new Date(y,m,1).getDay(), daysIn=new Date(y,m+1,0).getDate(), prevDays=new Date(y,m,0).getDate();
                    for(let i=first-1;i>=0;i--){const c=document.createElement('div');c.className='crm-cell muted';c.textContent=String(prevDays-i);cal.appendChild(c);}
                    for(let d=1;d<=daysIn;d++){const dt=new Date(y,m,d),btn=document.createElement('button');btn.type='button';btn.className='crm-cell';btn.dataset.iso=iso(dt);btn.textContent=String(d); const weekday=dt.getDay(); const selectable=!!therapistId && btn.dataset.iso>=todayIso && weekday!==0 && weekday!==6; if(selectable)btn.classList.add('available'); if(selectedDate===btn.dataset.iso){btn.classList.add('active');btn.classList.remove('available');}
                        if(!selectable){btn.classList.add('muted');btn.disabled=true;}
                        btn.onclick=()=>{if(!selectable)return;selectedDate=btn.dataset.iso;selectedTime='';sumTime.textContent='-';const nice=new Date(selectedDate+'T00:00:00');sumDate.textContent=nice.toLocaleDateString(undefined,{weekday:'short',month:'short',day:'numeric'});slotsDate.textContent=nice.toLocaleDateString(undefined,{month:'short',day:'numeric'});drawCalendar();loadSlots();}; cal.appendChild(btn);}
                    const total=first+daysIn, tail=(7-(total%7))%7; for(let i=1;i<=tail;i++){const c=document.createElement('div');c.className='crm-cell muted';c.textContent=String(i);cal.appendChild(c);}
                }
                function renderSlots(list){slotsGrid.innerHTML='';if(!Array.isArray(list)||!list.length){slotsGrid.innerHTML='<div style="grid-column:span 2;color:#6b7280;font-size:13px">No slots available.</div>';status.textContent='No slots available for selected date.';return;}
                    list.forEach(s=>{const t=typeof s==='string'?s:(s.time||''),avail=(typeof s==='string')?true:(s.available===true||s.available===1||s.available==='1'),b=document.createElement('button');b.type='button';b.className='crm-time-slot';b.textContent=t;if(!avail){b.classList.add('disabled','booked');b.disabled=true;}
                        b.onclick=()=>{if(!avail)return;document.querySelectorAll('.crm-time-slot').forEach(x=>x.classList.remove('selected'));b.classList.add('selected');selectedTime=t;sumTime.textContent=t;};slotsGrid.appendChild(b);});
                    status.textContent='Showing live slots from API.';}
                function loadSlots(){if(!selectedDate||!therapistId)return; slotsGrid.innerHTML='<div style="grid-column:span 2;color:#6b7280;font-size:13px">Loading slots...</div>'; status.textContent='Loading slots...'; const body=new URLSearchParams(); body.append('action','crm_profile_slots'); body.append('therapist_id',therapistId); body.append('date',selectedDate); body.append('session_type',sessionType); body.append('nonce',bookingNonce); fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()}).then(r=>r.json()).then(res=>{if(res&&Array.isArray(res.slots)){renderSlots(res.slots); if(res.message)status.textContent=res.message;} else {renderSlots([]); if(res&&res.message)status.textContent=res.message;}}).catch(()=>{renderSlots([]); status.textContent='Could not load slots from API.';});}
                function openBookingModal(){if(!selectedDate||!selectedTime){status.textContent='Please select a date and time first.';status.style.color='#dc2626';return;}sessionTypeInput.value=sessionType;if(modalMessage){modalMessage.textContent='';modalMessage.style.color='#6b7280';}modal.classList.add('open');}
                function closeBookingModal(){modal.classList.remove('open');}
                function showSuccessPopup(message){
                    const overlay=document.getElementById('crm-success-overlay');
                    const msgEl=document.getElementById('crm-success-message');
                    if(msgEl) msgEl.textContent=message;
                    if(overlay) overlay.classList.add('open');
                }
                function getRecaptchaToken(action,contextEl){
                    if(window.crmRecaptcha && typeof window.crmRecaptcha.getToken==='function'){
                        return window.crmRecaptcha.getToken(action,contextEl||null);
                    }
                    return Promise.resolve('');
                }
                function submitBooking(){
                    const fullName=(nameInput.value||'').trim(), email=(emailInput.value||'').trim(), phone=(phoneInput&&phoneInput.value?phoneInput.value:'').trim(), dateOfBirth=(dobInput&&dobInput.value?dobInput.value:''), notes=(notesInput.value||'').trim();
                    if(!fullName||!email){if(modalMessage){modalMessage.style.color='#dc2626';modalMessage.textContent='Please enter your name and email.';}return;}
                    if(phone && !validPhone(phone)){if(modalMessage){modalMessage.style.color='#dc2626';modalMessage.textContent='Please enter a valid phone number.';}return;}
                    if(dateOfBirth && !/^\d{4}-\d{2}-\d{2}$/.test(dateOfBirth)){if(modalMessage){modalMessage.style.color='#dc2626';modalMessage.textContent='Please enter a valid date of birth.';}return;}
                    if(dateOfBirth && dateOfBirth > todayIso){if(modalMessage){modalMessage.style.color='#dc2626';modalMessage.textContent='Date of birth cannot be in the future.';}return;}
                    submitBookingBtn.disabled=true;submitBookingBtn.textContent='Confirming...';
                    const body=new URLSearchParams();
                    body.append('action','crm_profile_continue_booking');
                    body.append('booking_data[fullName]',fullName);
                    body.append('booking_data[email]',email);
                    body.append('booking_data[phone]',phone);
                    body.append('booking_data[dateOfBirth]',dateOfBirth);
                    body.append('booking_data[notes]',notes);
                    body.append('booking_data[therapistId]',therapistId);
                    body.append('booking_data[serviceId]',serviceId);
                    body.append('booking_data[sessionDate]',selectedDate);
                    body.append('booking_data[sessionTime]',selectedTime);
                    body.append('booking_data[sessionType]',sessionTypeInput.value||sessionType);
                    body.append('booking_data[duration]','50');
                    body.append('nonce',bookingNonce);
                    getRecaptchaToken('profile_continue_booking',modal).then(token=>{body.append('recaptcha_token',token); return fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()}).then(r=>r.json());}).then(res=>{if(res&&res.ok&&res.appointment&&res.appointment.id){const id=' ID: '+res.appointment.id; showSuccessPopup('Appointment confirmed successfully.'+id); return;}else{const msg=(res&&res.message)?res.message:'Booking failed.';if(modalMessage){modalMessage.style.color='#dc2626';modalMessage.textContent=msg;}status.style.color='#dc2626';status.textContent=msg;}})
                    .catch(()=>{if(modalMessage){modalMessage.style.color='#dc2626';modalMessage.textContent='Security check failed. Please refresh and try again.';}status.style.color='#dc2626';status.textContent='Security check failed. Please refresh and try again.';}).finally(()=>{submitBookingBtn.disabled=false;submitBookingBtn.textContent='Confirm Appointment';});
                }
                if(serviceSelect){
                    serviceSelect.addEventListener('change', function(){
                        serviceId=this.value||serviceId;
                    });
                } else if (services && Object.keys(services).length) {
                    const first=Object.keys(services)[0];
                    if(!serviceId || !services[serviceId]) serviceId=first;
                }

                if(successOk) successOk.onclick=()=>{window.location.reload();};
                prev.onclick=()=>{view=new Date(view.getFullYear(),view.getMonth()-1,1);drawCalendar();}; next.onclick=()=>{view=new Date(view.getFullYear(),view.getMonth()+1,1);drawCalendar();}; cont.onclick=openBookingModal;
                closeModalBtn.onclick=closeBookingModal; submitBookingBtn.onclick=submitBooking; modal.onclick=(e)=>{if(e.target===modal)closeBookingModal();};
                drawCalendar();
                slotsGrid.innerHTML='<div style="grid-column:span 2;color:#6b7280;font-size:13px">Select a date to load slots.</div>';
                status.textContent=therapistId ? 'Select a date to load live slots.' : 'Therapist is not configured for booking.';
            })();
        </script>
        <?php
        return ob_get_clean();
    }
}

new CRM_Profile_Booking_Shortcode();
