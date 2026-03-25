<?php
if (!defined('ABSPATH')) exit;

class CRM_API_Handler {
    // The base URL for the TherapyFlow API
    private $base_url = "https://demo.therapyflow.pro/api";
    private $last_error = '';
    private static $runtime_cache = [];

    public function get_last_error() {
        return (string) $this->last_error;
    }

    private function clear_last_error() {
        $this->last_error = '';
    }

    private function set_last_error($message) {
        $this->last_error = sanitize_text_field((string) $message);
    }

    private function cache_key($namespace, $parts = []) {
        $raw = $namespace . '|' . implode('|', array_map('strval', $parts));
        return 'crm_api_' . md5($raw);
    }

    private function cache_read($namespace, $parts = []) {
        $key = $this->cache_key($namespace, $parts);
        if (array_key_exists($key, self::$runtime_cache)) {
            return self::$runtime_cache[$key];
        }
        $cached = get_transient($key);
        if ($cached !== false) {
            self::$runtime_cache[$key] = $cached;
            return $cached;
        }
        return null;
    }

    private function cache_write($namespace, $parts, $value, $ttl) {
        $key = $this->cache_key($namespace, $parts);
        self::$runtime_cache[$key] = $value;
        set_transient($key, $value, max(1, (int) $ttl));
    }

    /**
     * 1) List All Therapists
     * GET /all-therapist
     * Returns all active therapists with highlighted info.
     */
    public function get_all_therapists() {
        $this->clear_last_error();
        $cached = $this->cache_read('all_therapists');
        if (is_array($cached)) return $cached;

        $url = $this->base_url . '/all-therapist';
        
        $response = wp_remote_get($url, [
            'timeout'     => 30,
            'sslverify'   => true,
            'headers'     => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/121.0.0.0',
                'Accept'     => 'application/json',
                'Referer'    => 'https://demo.therapyflow.pro/',
            ],
        ]);

        if (is_wp_error($response)) {
            $err = 'CRM therapists API request failed: ' . $response->get_error_message();
            $this->set_last_error($err);
            error_log('CRM API Error (all-therapist): ' . $response->get_error_message());
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code === 200) {
            $data = json_decode($body, true);
            if (is_array($data)) {
                $this->cache_write('all_therapists', [], $data, 5 * MINUTE_IN_SECONDS);
                return $data;
            }
            $this->set_last_error('CRM therapists API returned invalid JSON.');
            return [];
        }

        $this->set_last_error('CRM therapists API returned HTTP ' . $code . '.');
        error_log('CRM API Response Code (all-therapist): ' . $code);
        return [];
    }

    /**
     * NEW: Get Dynamic Appointment Count
     * Calculates the total based on a base number plus successful local logs.
     */
    public function get_appointment_count() {
        // Fetch local logs of successful bookings made through the plugin
        $logs = get_option('crm_sync_logs', []);
        $new_bookings_count = is_array($logs) ? count($logs) : 0;
        
        // Return base total (128) + dynamic new bookings
        return 128 + $new_bookings_count;
    }

    /**
     * 2) Get Public Therapist Profile
     * GET /therapists/:therapistId/public-profile
     * Returns full public profile for a single therapist.
     */
    public function get_therapist_profile($id) {
        $this->clear_last_error();
        if (empty($id)) return null;
        $id = sanitize_text_field((string) $id);
        if ($id === '') return null;

        $cached = $this->cache_read('therapist_profile', [$id]);
        if (is_array($cached)) return $cached;

        $url = $this->base_url . "/therapists/{$id}/public-profile";
        
        $response = wp_remote_get($url, [
            'timeout'   => 20,
            'sslverify' => true,
            'headers'   => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/121.0.0.0',
                'Accept'     => 'application/json'
            ]
        ]);

        if (is_wp_error($response)) {
            $this->set_last_error('CRM profile API request failed: ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->set_last_error('CRM profile API returned HTTP ' . $code . '.');
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $this->set_last_error('CRM profile API returned invalid JSON.');
            return null;
        }
        $this->cache_write('therapist_profile', [$id], $data, 10 * MINUTE_IN_SECONDS);
        return $data;
    }

    /**
     * 3) Get Public Therapist Available Slots
     * GET /public/therapists/:therapistId/available-slots?date=YYYY-MM-DD&sessionType=online|in-person
     */
    public function get_slots($id, $date, $type = 'online') {
        $this->clear_last_error();
        if (empty($id) || empty($date)) return [];
        $id = sanitize_text_field((string) $id);
        $date = sanitize_text_field((string) $date);
        $type = in_array($type, ['online', 'in-person'], true) ? $type : 'online';
        if ($id === '' || $date === '') return [];

        $cached = $this->cache_read('slots', [$id, $date, $type]);
        if (is_array($cached)) return $cached;

        $url = $this->base_url . "/public/therapists/{$id}/available-slots?date={$date}&sessionType={$type}";
        
        $response = wp_remote_get($url, [
            'timeout'   => 20,
            'sslverify' => true,
            'headers'   => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/121.0.0.0',
                'Accept'     => 'application/json'
            ]
        ]);

        if (is_wp_error($response)) {
            $this->set_last_error('CRM slots API request failed: ' . $response->get_error_message());
            error_log('CRM API Error (available-slots): ' . $response->get_error_message());
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->set_last_error('CRM slots API returned HTTP ' . $code . '.');
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $this->set_last_error('CRM slots API returned invalid JSON.');
            return [];
        }
        $this->cache_write('slots', [$id, $date, $type], $data, 60);
        return $data;
    }

    /**
     * 4) Book Public Appointment
     * POST /public/book-appointment
     * UPDATED: Now logs successful bookings locally to update total count.
     */
    public function book_appointment($data) {
        $this->clear_last_error();
        $url = $this->base_url . '/public/book-appointment';
        
        $response = wp_remote_post($url, [
            'method'    => 'POST',
            'timeout'   => 30,
            'sslverify' => true,
            'headers'   => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'User-Agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/121.0.0.0'
            ],
            'body'      => wp_json_encode($data),
        ]);

        if (is_wp_error($response)) {
            $this->set_last_error('CRM booking API request failed: ' . $response->get_error_message());
            error_log('CRM API Booking Post Error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        if (!is_array($result)) {
            $this->set_last_error('CRM booking API returned invalid JSON.');
            return false;
        }
        if ($code < 200 || $code >= 300) {
            $api_message = !empty($result['message']) ? sanitize_text_field((string) $result['message']) : ('CRM booking API returned HTTP ' . $code . '.');
            $this->set_last_error($api_message);
        }

        // --- Logic to update dynamic appointment count ---
        if (isset($result['appointment']) && isset($result['appointment']['id'])) {
            $logs = get_option('crm_sync_logs', []);
            if (!is_array($logs)) $logs = [];
            
            // Add new booking to the top of the log
            array_unshift($logs, [
                'id' => $result['appointment']['id'],
                'fullName' => isset($data['fullName']) ? $data['fullName'] : 'Unknown',
                'date' => current_time('mysql'),
                'type' => isset($data['sessionType']) ? $data['sessionType'] : 'online'
            ]);
            
            // Keep only latest 100 logs to save database space
            update_option('crm_sync_logs', array_slice($logs, 0, 100));
        }

        if (!isset($result['appointment']) && !empty($result['message']) && $this->get_last_error() === '') {
            $this->set_last_error((string) $result['message']);
        }

        return $result;
    }
}
