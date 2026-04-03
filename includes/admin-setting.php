<?php
function crm_dashboard_page() {
    // 1. Initialize API Handler and fetch live data
    $configured_crm = (string) get_option('crm_selected_provider', 'therapyflow_pro');
    $configured_crm_url = (string) get_option('crm_api_base_url', 'https://demo.therapyflow.pro/api');
    $crm_provider_options = [
        'therapyflow_pro' => 'TherapyFlow Pro',
        'therapyflow_demo' => 'TherapyFlow Demo',
        'custom' => 'Custom CRM',
    ];
    if (!isset($crm_provider_options[$configured_crm])) $configured_crm = 'therapyflow_pro';

    $api = new CRM_API_Handler();
    $therapists = $api->get_all_therapists();
    $therapists_api_error = $api->get_last_error();
    $services = $api->get_all_services();
    $services_api_error = $api->get_last_error();
    $api_error = $therapists_api_error;
    
    // NEW: Fetch Dynamic Appointment Count
    $total_appointments = $api->get_appointment_count();

    // 2. Determine connection status and therapist count
    if (is_array($therapists) && !empty($therapists) && $therapists_api_error === '') {
        $total_therapists = count($therapists);
        $connection_status = 'active';
        $status_label = 'Connected';
    } else {
        $total_therapists = 0;
        $connection_status = 'inactive';
        $status_label = 'Disconnected';
        $therapists = []; 
    }
    if (is_array($services) && $services_api_error === '') {
        $total_services = count($services);
    } else {
        $total_services = 0;
        $services = function_exists('crm_get_cached_services') ? crm_get_cached_services() : [];
    }

    // 3. Fetch appointment logs
    $booking_logs = get_option('crm_sync_logs', []);
    if (!is_array($booking_logs)) $booking_logs = [];
    $recent_booking_logs = array_slice($booking_logs, 0, 8);
    $current_user_id = get_current_user_id();
    $notification_meta_key = 'crm_notifications_last_seen_sig';
    $last_seen_sig = $current_user_id ? (string) get_user_meta($current_user_id, $notification_meta_key, true) : '';
    $latest_notification_sig = '';
    $unread_notifications_count = 0;
    if (!empty($booking_logs)) {
        $first = $booking_logs[0];
        $latest_id = isset($first['id']) ? sanitize_text_field((string) $first['id']) : '';
        $latest_date = isset($first['date']) ? sanitize_text_field((string) $first['date']) : '';
        $latest_notification_sig = md5($latest_id . '|' . $latest_date);

        foreach ($booking_logs as $log) {
            if (!is_array($log)) continue;
            $log_id = isset($log['id']) ? sanitize_text_field((string) $log['id']) : '';
            $log_date = isset($log['date']) ? sanitize_text_field((string) $log['date']) : '';
            $sig = md5($log_id . '|' . $log_date);
            if ($last_seen_sig !== '' && hash_equals($last_seen_sig, $sig)) {
                break;
            }
            $unread_notifications_count++;
        }
    }
    $sync_url = wp_nonce_url(admin_url('admin-post.php?action=crm_sync_therapists_cache'), 'crm_sync_therapists_cache');
    $last_sync = (int) get_option('crm_therapist_last_sync', 0);
    $last_sync_label = $last_sync > 0 ? wp_date('Y-m-d H:i:s', $last_sync) : 'Never';
    $services_sync_url = wp_nonce_url(admin_url('admin-post.php?action=crm_sync_services_cache'), 'crm_sync_services_cache');
    $services_last_sync = (int) get_option('crm_service_last_sync', 0);
    $services_last_sync_label = $services_last_sync > 0 ? wp_date('Y-m-d H:i:s', $services_last_sync) : 'Never';
    
    ?>
    <style>
        /* TAB SYSTEM CSS */
        .crm-tab-content { display: none; animation: fadeIn 0.3s ease-in-out; }
        .crm-tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .nav-item { cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; gap: 10px; padding: 12px 20px; color: #94a3b8; text-decoration: none; }
        .nav-item.active { background: #1e293b; color: white !important; border-left: 4px solid #3b82f6; }

        /* MODAL POPUP */
        .crm-modal-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(8px); z-index: 9999;
            align-items: center; justify-content: center;
        }
        .crm-modal-overlay.open { display: flex; }
        .crm-modal {
            background: #fff; width: 95%; max-width: 1000px; height: 85vh;
            border-radius: 16px; display: flex; flex-direction: column; overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        .crm-modal-header { padding: 20px 30px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; }
        .crm-modal-body { padding: 30px; overflow-y: auto; flex: 1; display: flex; gap: 30px; }
        .crm-modal-footer { padding: 20px 30px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 10px; background: #f8fafc; }

        .f-input { width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px; font-size: 13px; margin-bottom: 10px; }
        
        /* Dashboard Stats */
        .dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
        .dot.active { background: #10b981; }
        .dot.inactive { background: #ef4444; }
        .status-indicator { display: flex; gap: 10px; align-items: center; }
        .table-search { padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px; width: 250px; font-size: 13px; }

        /* Therapist Avatar Style */
        .t-avatar { width: 32px; height: 32px; border-radius: 50%; background: #3b82f6; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 12px; }
        
        /* New Slot Grid Styling - EASY FOR USERS */
        .slots-visual-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 12px; margin-top: 20px; }
        .slot-pill { padding: 12px; border: 1.5px solid #e2e8f0; border-radius: 8px; text-align: center; font-size: 14px; font-weight: 700; cursor: default; }
        .slot-pill.available { background: #ffffff; color: #1e293b; border-color: #3b82f6; }
        .slot-pill.booked { background: #fef2f2; color: #b91c1c; border-color: #ef4444; text-decoration: line-through; }

        /* Profile Modal Styling */
        .profile-section { margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 15px; }
        .profile-label { font-weight: 700; font-size: 11px; text-transform: uppercase; color: #94a3b8; display: block; margin-bottom: 5px; }
        .profile-value { font-size: 15px; color: #1e293b; line-height: 1.5; }
        .profile-badge { display: inline-block; padding: 4px 10px; background: #eff6ff; color: #3b82f6; border-radius: 4px; font-size: 12px; margin-right: 5px; margin-bottom: 5px; font-weight: 600; }

        /* Direct Booking Form Enhancement - Matching Image Style */
        .crm-booking-form-wrap { background: #fff; border-radius: 12px; padding: 30px; border: 1px solid #e2e8f0; max-width: 700px; margin: 0 auto; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px; }
        .duration-box { display: flex; gap: 10px; margin-top: 8px; }
        .dur-btn { padding: 10px 15px; border: 1.5px solid #e2e8f0; border-radius: 6px; background: #fff; font-size: 13px; cursor: pointer; font-weight: 600; color: #64748b; transition: 0.2s; }
        .dur-btn.active { background: #3b82f6; color: #fff; border-color: #3b82f6; }
        .form-label-premium { font-weight: 600; font-size: 14px; color: #334155; display: block; margin-bottom: 8px; }
        .crm-nav-right { position: relative; display: flex; gap: 10px; align-items: center; }
        .crm-top-icon-btn { width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid #dbe3ef; border-radius: 8px; background: #fff; color: #334155; cursor: pointer; position: relative; }
        .crm-top-icon-btn:hover { background: #f8fafc; }
        .crm-dot-count { position: absolute; top: -6px; right: -6px; min-width: 18px; height: 18px; border-radius: 999px; background: #ef4444; color: #fff; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; padding: 0 4px; }
        .crm-top-popover { position: absolute; top: 44px; right: 0; width: 320px; max-height: 360px; overflow-y: auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12); display: none; z-index: 20; }
        .crm-top-popover.open { display: block; }
        .crm-popover-header { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; font-weight: 700; color: #334155; }
        .crm-popover-item { padding: 10px 14px; border-bottom: 1px solid #f8fafc; }
        .crm-popover-item:last-child { border-bottom: none; }
        .crm-popover-item small { color: #64748b; display: block; margin-top: 2px; }
        .crm-top-actions { display: flex; gap: 8px; padding: 10px 14px; border-top: 1px solid #f1f5f9; background: #f8fafc; }
        .crm-top-actions button { border: 1px solid #dbe3ef; background: #fff; color: #1e293b; border-radius: 6px; padding: 6px 10px; cursor: pointer; font-size: 12px; }

        @media (max-width: 992px) {
            .form-row-2 { grid-template-columns: 1fr; gap: 12px; }
            .crm-booking-form-wrap { padding: 18px; max-width: 100%; }
            .crm-nav-left h1 { font-size: 18px; }
            .crm-nav-left p { font-size: 12px; }
        }

        @media (max-width: 768px) {
            .crm-top-popover {
                width: min(94vw, 340px);
                right: 0;
                left: auto;
            }
            .crm-top-actions {
                flex-direction: column;
            }
            .crm-top-actions button {
                width: 100%;
            }

            #tab-therapists .card-header > div:last-child,
            #tab-services .card-header > div:last-child,
            #tab-availability .crm-section-card > div[style*="display:flex"],
            #tab-direct-booking form > div[style*="display:flex"] {
                display: grid !important;
                grid-template-columns: 1fr !important;
                gap: 10px !important;
                align-items: stretch !important;
            }

            .table-search { width: 100%; }
            .duration-box { flex-wrap: wrap; }
            .dur-btn { min-width: 58px; text-align: center; }

            /* Dashboard cards */
            #tab-dashboard .crm-stat-grid { grid-template-columns: 1fr; gap: 12px; }
            #tab-dashboard .stat-card { padding: 14px; }
            #tab-dashboard .status-indicator { gap: 8px; align-items: flex-start; flex-wrap: wrap; }
            #tab-dashboard .status-indicator > div { min-width: 0; }
            #tab-dashboard #crm-status-label { display: block; font-size: 16px; line-height: 1.2; word-break: break-word; }
            #tab-dashboard #crm-sync-status { font-size: 18px !important; line-height: 1.2; word-break: break-word; }

            /* Therapist table -> mobile cards */
            #tab-therapists .crm-table,
            #tab-services .crm-table,
            #tab-appointment-logs .crm-table {
                display: block;
                overflow: visible;
            }
            #tab-therapists .crm-table thead,
            #tab-services .crm-table thead,
            #tab-appointment-logs .crm-table thead {
                display: none;
            }
            #tab-therapists .crm-table tbody,
            #tab-services .crm-table tbody,
            #tab-appointment-logs .crm-table tbody {
                display: block;
            }
            #tab-therapists .crm-table tr,
            #tab-services .crm-table tr,
            #tab-appointment-logs .crm-table tr {
                display: block;
                border: 1px solid #e2e8f0;
                border-radius: 10px;
                margin-bottom: 10px;
                background: #fff;
                overflow: hidden;
            }
            #tab-therapists .crm-table td,
            #tab-services .crm-table td,
            #tab-appointment-logs .crm-table td {
                display: flex;
                justify-content: space-between;
                gap: 12px;
                align-items: flex-start;
                padding: 10px 12px;
                border-bottom: 1px solid #f1f5f9;
                white-space: normal;
                word-break: break-word;
            }
            #tab-therapists .crm-table td:last-child,
            #tab-services .crm-table td:last-child,
            #tab-appointment-logs .crm-table td:last-child {
                border-bottom: 0;
            }
            #tab-therapists .crm-table td::before,
            #tab-services .crm-table td::before,
            #tab-appointment-logs .crm-table td::before {
                font-size: 12px;
                font-weight: 700;
                color: #64748b;
                text-transform: uppercase;
                letter-spacing: .03em;
                flex: 0 0 96px;
            }
            #tab-therapists .crm-table td:nth-child(1)::before { content: "Therapist"; }
            #tab-therapists .crm-table td:nth-child(2)::before { content: "Email"; }
            #tab-therapists .crm-table td:nth-child(3)::before { content: "Experience"; }
            #tab-therapists .crm-table td:nth-child(4)::before { content: "Actions"; }
            #tab-services .crm-table td:nth-child(1)::before { content: "Service"; }
            #tab-services .crm-table td:nth-child(2)::before { content: "Code"; }
            #tab-services .crm-table td:nth-child(3)::before { content: "Category"; }
            #tab-services .crm-table td:nth-child(4)::before { content: "Duration"; }
            #tab-services .crm-table td:nth-child(5)::before { content: "Base Rate"; }
            #tab-appointment-logs .crm-table td:nth-child(1)::before { content: "ID"; }
            #tab-appointment-logs .crm-table td:nth-child(2)::before { content: "Client"; }
            #tab-appointment-logs .crm-table td:nth-child(3)::before { content: "Date"; }
            #tab-appointment-logs .crm-table td:nth-child(4)::before { content: "Type"; }
            #tab-appointment-logs .crm-table td:nth-child(5)::before { content: "Appointment Date"; }
            #tab-appointment-logs .crm-table td:nth-child(6)::before { content: "Service"; }
            #tab-appointment-logs .crm-table td:nth-child(7)::before { content: "Therapist"; }
            #tab-appointment-logs .crm-table td:nth-child(8)::before { content: "Status"; }

            #tab-therapists .crm-table td[colspan],
            #tab-services .crm-table td[colspan],
            #tab-appointment-logs .crm-table td[colspan] {
                display: block;
                text-align: center;
                border-bottom: 0;
            }
            #tab-therapists .crm-table td[colspan]::before,
            #tab-services .crm-table td[colspan]::before,
            #tab-appointment-logs .crm-table td[colspan]::before {
                content: none;
            }
            #tab-therapists .view-profile-api {
                width: 100%;
            }
        }
    </style>

    <div class="crm-app-shell">
        <header class="crm-top-nav">
            <div class="crm-nav-left"><h1>CRM Connector Pro</h1><p>Connect Your Website with Your CRM</p></div>
            <div class="crm-nav-right">
                <button type="button" class="crm-top-icon-btn" id="crm-top-bell-btn" title="Notifications">
                    <iconify-icon icon="lucide:bell"></iconify-icon>
                    <?php if ($unread_notifications_count > 0) : ?>
                        <span class="crm-dot-count" id="crm-notification-badge"><?php echo esc_html($unread_notifications_count > 99 ? '99+' : (string) $unread_notifications_count); ?></span>
                    <?php endif; ?>
                </button>
                <button type="button" class="crm-top-icon-btn" id="crm-top-settings-btn" title="Open CRM Settings">
                    <iconify-icon icon="lucide:settings"></iconify-icon>
                </button>
                <div class="crm-top-popover" id="crm-notification-popover">
                    <div class="crm-popover-header">Recent Booking Notifications</div>
                    <?php if (!empty($recent_booking_logs)) : ?>
                        <?php foreach ($recent_booking_logs as $log) : ?>
                            <?php
                            $log_name = isset($log['fullName']) ? sanitize_text_field((string) $log['fullName']) : 'Client';
                            $log_type = isset($log['type']) ? sanitize_text_field((string) $log['type']) : 'online';
                            $log_date = isset($log['date']) ? sanitize_text_field((string) $log['date']) : '';
                            ?>
                            <div class="crm-popover-item">
                                <strong><?php echo esc_html($log_name); ?></strong>
                                <small><?php echo esc_html(ucfirst($log_type)); ?><?php echo $log_date !== '' ? ' · ' . esc_html($log_date) : ''; ?></small>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="crm-popover-item"><small>No recent booking notifications.</small></div>
                    <?php endif; ?>
                    <div class="crm-top-actions">
                        <button type="button" id="crm-popover-open-logs">View Logs</button>
                        <button type="button" id="crm-popover-open-settings">Open Settings</button>
                    </div>
                </div>
            </div>
        </header>

        <div class="crm-main-layout">
            <aside class="crm-sidebar">
                <nav id="crm-admin-nav">
                    <a class="nav-item active" data-tab="dashboard"><iconify-icon icon="lucide:layout-dashboard"></iconify-icon> Dashboard</a>
                    <a class="nav-item" data-tab="therapists"><iconify-icon icon="lucide:users"></iconify-icon> Therapists</a>
                    <a class="nav-item" data-tab="services"><iconify-icon icon="lucide:briefcase-business"></iconify-icon> Services</a>
                    <a class="nav-item" data-tab="availability"><iconify-icon icon="lucide:calendar-range"></iconify-icon> Availability</a>
                    <a class="nav-item" data-tab="direct-booking"><iconify-icon icon="lucide:calendar-plus"></iconify-icon> Direct Booking</a>
                    <a class="nav-item" data-tab="appointment-logs"><iconify-icon icon="lucide:history"></iconify-icon> Appointment Logs</a>
                    <a class="nav-item" data-tab="crm-settings"><iconify-icon icon="lucide:settings-2"></iconify-icon> CRM Settings</a>
                </nav>
            </aside>

            <main class="crm-content">
                <section id="tab-dashboard" class="crm-tab-content active">
                    <div class="crm-stat-grid">
                        <div class="stat-card">
                            <label>CRM Status</label>
                            <div class="status-indicator">
                                <span id="crm-status-dot" class="dot <?php echo $connection_status; ?>"></span> 
                                <div><strong id="crm-status-label"><?php echo $status_label; ?></strong><p>TherapyFlow API</p></div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <label>Active Therapists</label>
                            <div id="crm-total-therapists" class="huge-number"><?php echo $total_therapists; ?></div>
                            <p>Profiles fetched from CRM</p>
                        </div>
                        <div class="stat-card">
                            <label>Total Appointments</label>
                            <div id="crm-total-appointments" class="huge-number" style="color: #3b82f6;"><?php echo $total_appointments; ?></div>
                            <p>Dynamic Real-time Count</p>
                        </div>
                        <div class="stat-card">
                            <label>Active Services</label>
                            <div id="crm-total-services" class="huge-number"><?php echo (int) $total_services; ?></div>
                            <p>Services fetched from CRM</p>
                        </div>
                        <div class="stat-card">
                            <label>Sync Status</label>
                            <div id="crm-sync-status" class="huge-number" style="font-size:20px; color:<?php echo $connection_status === 'active' ? '#10b981' : '#ef4444'; ?>;">
                                <?php echo $connection_status === 'active' ? 'Active' : 'Inactive'; ?>
                            </div>
                        </div>
                    </div>
                </section>

                <section id="tab-therapists" class="crm-tab-content">
                    <div class="crm-section-card">
                        <div class="card-header">
                            <div><h3>Professional Profiles</h3><p>Active therapists listed in TherapyFlow CRM.</p></div>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <span style="font-size:12px; color:#64748b;">Last Sync: <?php echo esc_html($last_sync_label); ?></span>
                                <a href="<?php echo esc_url($sync_url); ?>" class="btn-blue-outline" style="padding:8px 12px; text-decoration:none;">Sync Therapists</a>
                                <input type="text" id="therapist-search" class="table-search" placeholder="Filter by name...">
                            </div>
                        </div>
                        <table class="crm-table">
                            <thead>
                                <tr>
                                    <th>Therapist</th>
                                    <th>Email</th>
                                    <th>Experience</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="therapist-table-body">
                                <?php if(!empty($therapists)): foreach($therapists as $t): ?>
                                <tr>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:10px;">
                                            <div class="t-avatar"><?php echo strtoupper(substr($t['fullName'], 0, 1)); ?></div>
                                            <strong><?php echo esc_html($t['fullName']); ?></strong>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html($t['email']); ?></td>
                                    <td><?php echo esc_html($t['yearsOfExperience']); ?> Years</td>
                                    <td>
                                        <button class="btn-blue-outline view-profile-api" data-id="<?php echo esc_attr($t['id']); ?>" style="padding: 6px 12px; font-size: 12px; cursor:pointer;">View Detailed Profile</button>
                                    </td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr><td colspan="4" style="text-align:center; padding: 20px;">No therapist profiles available.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section id="tab-services" class="crm-tab-content">
                    <div class="crm-section-card">
                        <div class="card-header">
                            <div><h3>Services</h3><p>Active services listed in TherapyFlow CRM.</p></div>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <span style="font-size:12px; color:#64748b;">Last Sync: <?php echo esc_html($services_last_sync_label); ?></span>
                                <a href="<?php echo esc_url($services_sync_url); ?>" class="btn-blue-outline" style="padding:8px 12px; text-decoration:none;">Sync Services</a>
                                <input type="text" id="service-search" class="table-search" placeholder="Filter by service name/code...">
                            </div>
                        </div>
                        <table class="crm-table">
                            <thead>
                                <tr>
                                    <th>Service</th>
                                    <th>Code</th>
                                    <th>Category</th>
                                    <th>Duration</th>
                                    <th>Base Rate</th>
                                </tr>
                            </thead>
                            <tbody id="service-table-body">
                                <?php if(!empty($services)): foreach($services as $s): ?>
                                <?php
                                    $service_name = isset($s['serviceName']) ? sanitize_text_field((string) $s['serviceName']) : '';
                                    if ($service_name === '' && isset($s['fullName'])) $service_name = sanitize_text_field((string) $s['fullName']);
                                    $service_code = isset($s['serviceCode']) ? sanitize_text_field((string) $s['serviceCode']) : '';
                                    $service_category = isset($s['category']) ? sanitize_text_field((string) $s['category']) : '';
                                    $service_duration = isset($s['duration']) ? sanitize_text_field((string) $s['duration']) : '';
                                    $service_rate = isset($s['baseRate']) ? sanitize_text_field((string) $s['baseRate']) : '';
                                    if ($service_name === '') $service_name = 'Service';
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html($service_name); ?></strong></td>
                                    <td><?php echo esc_html($service_code !== '' ? $service_code : '-'); ?></td>
                                    <td><?php echo esc_html($service_category !== '' ? $service_category : '-'); ?></td>
                                    <td><?php echo esc_html($service_duration !== '' ? ($service_duration . ' min') : '-'); ?></td>
                                    <td><?php echo esc_html($service_rate !== '' ? $service_rate : '-'); ?></td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr><td colspan="5" style="text-align:center; padding: 20px;">No service profiles available.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section id="tab-availability" class="crm-tab-content">
                    <div class="crm-section-card">
                        <h3>Easy Availability Checker</h3>
                        <p>Select therapist, date, and session type. Slots load automatically after date selection.</p>
                        <div style="display:flex; gap:15px; margin-top:20px; align-items: flex-end;">
                            <div style="flex:1;">
                                <label>1. Select Therapist</label>
                                <select id="avail-therapist-id" class="f-input">
                                    <option value="">Choose Therapist...</option>
                                    <?php foreach($therapists as $t): ?>
                                        <option value="<?php echo esc_attr($t['id']); ?>"><?php echo esc_html($t['fullName']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="flex:1;">
                                <label>2. Select Date</label>
                                <input type="date" id="avail-date" class="f-input">
                            </div>
                            <div style="flex:1;">
                                <label>3. Session Type</label>
                                <select id="avail-type" class="f-input">
                                    <option value="online">Online</option>
                                    <option value="in-person">In-Person</option>
                                </select>
                            </div>
                        </div>
                        <div id="slots-result-container" style="margin-top:30px; display:none; background: #fafafa; padding: 25px; border-radius: 12px; border: 1px dashed #ddd;">
                            <h4 id="slots-title" style="margin-top:0;">Time Slots</h4>
                            <div class="slots-visual-grid" id="slots-grid-output"></div>
                        </div>
                    </div>
                </section>

                <section id="tab-direct-booking" class="crm-tab-content">
                    <div class="crm-booking-form-wrap">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                            <h2 style="margin:0; font-size:20px;">Schedule New Session</h2>
                            <button type="button" style="background:none; border:none; font-size:24px; color:#94a3b8; cursor:pointer;">&times;</button>
                        </div>
                        <form id="manual-booking-form">
                            <div class="form-row-2">
                                <div><label class="form-label-premium">Client *</label>
                                <input type="text" name="fullName" class="f-input" placeholder="Client name" required></div>
                                <div><label class="form-label-premium">Email *</label>
                                <input type="email" name="email" class="f-input" placeholder="client@example.com" required></div>
                            </div>
                            <div class="form-row-2">
                                <div><label class="form-label-premium">Therapist *</label>
                                    <select name="therapistId" id="manual-therapist-id" class="f-input" required>
                                        <option value="">Select therapist</option>
                                        <?php foreach($therapists as $t): ?>
                                            <option value="<?php echo esc_attr($t['id']); ?>"><?php echo esc_html($t['fullName']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div style="margin-bottom:15px;">
                                <label class="form-label-premium">Service *</label>
                                <select name="serviceId" class="f-input" required>
                                    <option value="51" selected>Free Consultation</option>
                                </select>
                            </div>

                            <div class="form-row-2">
                                <div><label class="form-label-premium">Date *</label>
                                <input type="date" id="manual-session-date" name="sessionDate" class="f-input" required></div>
                                <div><label class="form-label-premium">Session Type *</label>
                                    <select name="sessionType" id="manual-session-type" class="f-input" required>
                                        <option value="online">Online</option>
                                        <option value="in-person">In-Person</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row-2" style="align-items: start;">
                                <div><label class="form-label-premium">Time *</label>
                                    <select name="sessionTime" id="manual-session-time" class="f-input" required>
                                        <option value="">Select therapist/date/type first</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label-premium">Duration (minutes)</label>
                                    <div class="duration-box">
                                        <div class="dur-btn" data-val="30">30</div>
                                        <div class="dur-btn" data-val="45">45</div>
                                        <div class="dur-btn active" data-val="60">60</div>
                                        <div class="dur-btn" data-val="90">90</div>
                                        <div class="dur-btn" data-val="120">120</div>
                                        <input type="hidden" name="duration" value="60">
                                    </div>
                                </div>
                            </div>

                            <div style="margin-bottom:15px;">
                                <label class="form-label-premium">Notes (optional)</label>
                                <textarea name="notes" class="f-input" style="height:100px; resize:none;" placeholder="Session notes or special instructions"></textarea>
                            </div>

                            <div style="display:flex; justify-content:flex-end; gap:15px; margin-top:30px;">
                                <button type="button" class="dur-btn" style="border:none; padding:12px 25px;">Cancel</button>
                                <button type="submit" class="btn-primary" style="padding:12px 35px; border-radius:8px; font-weight:600;">Schedule Session</button>
                            </div>
                        </form>
                    </div>
                </section>

                <section id="tab-appointment-logs" class="crm-tab-content">
                    <div class="crm-section-card">
                        <h3>Appointment Sync Logs</h3>
                        <table class="crm-table">
                            <thead><tr><th>ID</th><th>Client</th><th>Date</th><th>Type</th><th>Appointment Date</th><th>Service</th><th>Therapist</th><th>Status</th></tr></thead>
                            <tbody id="logs-table-body">
                                <?php if (!empty($booking_logs)) : ?>
                                    <?php foreach ($booking_logs as $log) : ?>
                                        <?php
                                        $log_id = isset($log['id']) ? sanitize_text_field((string) $log['id']) : '-';
                                        $log_name = isset($log['fullName']) ? sanitize_text_field((string) $log['fullName']) : 'Unknown Client';
                                        $log_date = isset($log['date']) ? sanitize_text_field((string) $log['date']) : '-';
                                        $log_type = isset($log['type']) ? sanitize_text_field((string) $log['type']) : 'online';
                                        $log_appointment_date = isset($log['sessionDate']) ? sanitize_text_field((string) $log['sessionDate']) : '-';
                                        $log_service_name = isset($log['serviceName']) ? sanitize_text_field((string) $log['serviceName']) : '-';
                                        $log_therapist_name = isset($log['therapistName']) ? sanitize_text_field((string) $log['therapistName']) : '-';
                                        ?>
                                        <tr>
                                            <td>#<?php echo esc_html($log_id); ?></td>
                                            <td><?php echo esc_html($log_name); ?></td>
                                            <td><?php echo esc_html($log_date); ?></td>
                                            <td><?php echo esc_html(ucfirst($log_type)); ?></td>
                                            <td><?php echo esc_html($log_appointment_date); ?></td>
                                            <td><?php echo esc_html($log_service_name); ?></td>
                                            <td><?php echo esc_html($log_therapist_name); ?></td>
                                            <td><span class="badge active">Scheduled</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <tr><td colspan="8" style="text-align:center; padding:20px;">No appointment logs found yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section id="tab-crm-settings" class="crm-tab-content">
                    <div class="crm-section-card" style="max-width: 600px;">
                        <h3>CRM Settings</h3>
                        <p>Configure your CRM integration settings below.</p>
                        <div class="crm-form-field">
                            <label>Select CRM</label>
                            <select class="f-input" id="crm-settings-provider">
                                <?php foreach ($crm_provider_options as $provider_key => $provider_label) : ?>
                                    <option value="<?php echo esc_attr($provider_key); ?>" <?php selected($configured_crm, $provider_key); ?>>
                                        <?php echo esc_html($provider_label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="crm-form-field">
                            <label>CRM API URL</label>
                            <input type="text" id="crm-settings-api-url" class="f-input" value="<?php echo esc_attr($configured_crm_url); ?>" placeholder="https://your-crm-domain/api">
                        </div>
                        <div style="display:flex; align-items:center; gap:10px; margin-top:10px;">
                            <button type="button" class="btn-primary" id="crm-save-settings-btn">Save Settings</button>
                            <span id="crm-settings-status" style="font-size:13px; color:#64748b;"></span>
                        </div>
                        <p id="crm-settings-conn-error" style="margin-top:12px; color:#ef4444; font-size:12px; <?php echo ($connection_status !== 'active' && $api_error !== '') ? '' : 'display:none;'; ?>">
                            <?php if ($connection_status !== 'active' && $api_error !== '') : ?>
                                <?php echo esc_html('Connection Error: ' . $api_error); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </section>
            </main>
        </div>
    </div>

    <div class="crm-modal-overlay" id="profile-modal">
        <div class="crm-modal" style="max-width: 600px; height: auto; max-height: 90vh;">
            <div class="crm-modal-header">
                <h3>Therapist Public Profile</h3>
                <button type="button" class="close-profile-modal" style="background:none; border:none; font-size:24px; cursor:pointer;">&times;</button>
            </div>
            <div class="crm-modal-body" id="profile-content-area" style="display:block;">
                <p>Loading therapist details...</p>
            </div>
            <div class="crm-modal-footer">
                <button type="button" class="btn-primary close-profile-modal">Close Profile</button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
            const crmAdminNonce = '<?php echo esc_js(wp_create_nonce('crm_admin_ajax_nonce')); ?>';
            const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, (ch) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[ch]);

            // 1. TAB SWITCHER
            const navItems = document.querySelectorAll('.nav-item');
            const tabContents = document.querySelectorAll('.crm-tab-content');
            const notificationPopover = document.getElementById('crm-notification-popover');

            function activateTab(targetTab) {
                if (!targetTab) return;
                navItems.forEach(i => i.classList.remove('active'));
                const activeNav = document.querySelector(`.nav-item[data-tab="${targetTab}"]`);
                if (activeNav) activeNav.classList.add('active');
                tabContents.forEach(tab => tab.classList.remove('active'));
                const target = document.getElementById('tab-' + targetTab);
                if (target) target.classList.add('active');
            }

            navItems.forEach(item => {
                item.addEventListener('click', function() {
                    const targetTab = this.getAttribute('data-tab');
                    activateTab(targetTab);
                    if (notificationPopover) notificationPopover.classList.remove('open');
                });
            });

            // Top Bar Shortcuts (Notifications / Settings)
            const topBellBtn = document.getElementById('crm-top-bell-btn');
            const topSettingsBtn = document.getElementById('crm-top-settings-btn');
            const popOpenLogsBtn = document.getElementById('crm-popover-open-logs');
            const popOpenSettingsBtn = document.getElementById('crm-popover-open-settings');
            const notificationBadge = document.getElementById('crm-notification-badge');
            let notificationsMarkedRead = <?php echo $unread_notifications_count > 0 ? 'false' : 'true'; ?>;

            if (topBellBtn && notificationPopover) {
                topBellBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const willOpen = !notificationPopover.classList.contains('open');
                    notificationPopover.classList.toggle('open');
                    if (willOpen && !notificationsMarkedRead) {
                        jQuery.post(ajaxurl, { action: 'crm_mark_notifications_read', nonce: crmAdminNonce }, function(res) {
                            if (res && res.success) {
                                notificationsMarkedRead = true;
                                if (notificationBadge) notificationBadge.remove();
                            }
                        });
                    }
                });
                document.addEventListener('click', function(e) {
                    if (!notificationPopover.contains(e.target) && e.target !== topBellBtn) {
                        notificationPopover.classList.remove('open');
                    }
                });
            }
            if (topSettingsBtn) {
                topSettingsBtn.addEventListener('click', function() {
                    activateTab('crm-settings');
                    if (notificationPopover) notificationPopover.classList.remove('open');
                });
            }
            if (popOpenLogsBtn) {
                popOpenLogsBtn.addEventListener('click', function() {
                    activateTab('appointment-logs');
                    if (notificationPopover) notificationPopover.classList.remove('open');
                });
            }
            if (popOpenSettingsBtn) {
                popOpenSettingsBtn.addEventListener('click', function() {
                    activateTab('crm-settings');
                    if (notificationPopover) notificationPopover.classList.remove('open');
                });
            }

            // CRM Settings Save
            const crmSaveSettingsBtn = document.getElementById('crm-save-settings-btn');
            const crmProviderSelect = document.getElementById('crm-settings-provider');
            const crmApiUrlInput = document.getElementById('crm-settings-api-url');
            const crmSettingsStatus = document.getElementById('crm-settings-status');
            if (crmSaveSettingsBtn && crmProviderSelect && crmApiUrlInput) {
                crmSaveSettingsBtn.addEventListener('click', function() {
                    const provider = crmProviderSelect.value || 'therapyflow_pro';
                    const apiUrl = (crmApiUrlInput.value || '').trim();
                    if (!apiUrl) {
                        if (crmSettingsStatus) {
                            crmSettingsStatus.style.color = '#ef4444';
                            crmSettingsStatus.textContent = 'CRM API URL is required.';
                        }
                        return;
                    }
                    crmSaveSettingsBtn.disabled = true;
                    crmSaveSettingsBtn.textContent = 'Saving...';
                    if (crmSettingsStatus) {
                        crmSettingsStatus.style.color = '#64748b';
                        crmSettingsStatus.textContent = 'Saving settings...';
                    }

                    jQuery.post(ajaxurl, {
                        action: 'save_crm_settings_action',
                        nonce: crmAdminNonce,
                        provider: provider,
                        api_url: apiUrl
                    }, function(res) {
                        if (res && res.success) {
                            if (crmSettingsStatus) {
                                crmSettingsStatus.style.color = '#10b981';
                                crmSettingsStatus.textContent = (res.data && res.data.message) ? res.data.message : 'CRM settings saved.';
                            }
                            if (res.data) {
                                const statusDot = document.getElementById('crm-status-dot');
                                const statusLabel = document.getElementById('crm-status-label');
                                const syncStatus = document.getElementById('crm-sync-status');
                                const totalTherapists = document.getElementById('crm-total-therapists');
                                const totalServices = document.getElementById('crm-total-services');
                                const totalAppointments = document.getElementById('crm-total-appointments');
                                const connError = document.getElementById('crm-settings-conn-error');

                                if (statusDot) {
                                    statusDot.classList.remove('active', 'inactive');
                                    statusDot.classList.add(res.data.connection_status === 'active' ? 'active' : 'inactive');
                                }
                                if (statusLabel) statusLabel.textContent = res.data.status_label || 'Disconnected';
                                if (syncStatus) {
                                    syncStatus.textContent = res.data.sync_status || 'Inactive';
                                    syncStatus.style.color = (res.data.connection_status === 'active') ? '#10b981' : '#ef4444';
                                }
                                if (typeof res.data.total_therapists !== 'undefined' && totalTherapists) {
                                    totalTherapists.textContent = String(res.data.total_therapists);
                                }
                                if (typeof res.data.total_services !== 'undefined' && totalServices) {
                                    totalServices.textContent = String(res.data.total_services);
                                }
                                if (typeof res.data.total_appointments !== 'undefined' && totalAppointments) {
                                    totalAppointments.textContent = String(res.data.total_appointments);
                                }
                                if (connError) {
                                    if (res.data.connection_status === 'active') {
                                        connError.style.display = 'none';
                                        connError.textContent = '';
                                    } else {
                                        connError.style.display = '';
                                        connError.textContent = res.data.api_error ? ('Connection Error: ' + res.data.api_error) : 'Connection Error: Unable to connect to CRM.';
                                    }
                                }
                            }
                        } else {
                            if (crmSettingsStatus) {
                                crmSettingsStatus.style.color = '#ef4444';
                                crmSettingsStatus.textContent = (res && res.data && res.data.message) ? res.data.message : 'Could not save CRM settings.';
                            }
                        }
                    }).fail(function() {
                        if (crmSettingsStatus) {
                            crmSettingsStatus.style.color = '#ef4444';
                            crmSettingsStatus.textContent = 'Save request failed.';
                        }
                    }).always(function() {
                        crmSaveSettingsBtn.disabled = false;
                        crmSaveSettingsBtn.textContent = 'Save Settings';
                    });
                });
            }

            // 2. THERAPIST SEARCH
            const searchInput = document.getElementById('therapist-search');
            if(searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const value = this.value.toLowerCase();
                    const rows = document.querySelectorAll('#therapist-table-body tr');
                    rows.forEach(row => {
                        row.style.display = row.innerText.toLowerCase().includes(value) ? '' : 'none';
                    });
                });
            }

            // 2b. SERVICE SEARCH
            const serviceSearchInput = document.getElementById('service-search');
            if(serviceSearchInput) {
                serviceSearchInput.addEventListener('keyup', function() {
                    const value = this.value.toLowerCase();
                    const rows = document.querySelectorAll('#service-table-body tr');
                    rows.forEach(row => {
                        row.style.display = row.innerText.toLowerCase().includes(value) ? '' : 'none';
                    });
                });
            }

            // 3. VIEW PROFILE MODAL LOGIC (API #2)
            const profileModal = document.getElementById('profile-modal');
            const profileContent = document.getElementById('profile-content-area');

            document.querySelector('#therapist-table-body').addEventListener('click', function(e) {
                if(e.target && e.target.classList.contains('view-profile-api')) {
                    const id = e.target.dataset.id;
                    profileModal.classList.add('open');
                    profileContent.innerHTML = '<p>Fetching data from CRM...</p>';

                    jQuery.post(ajaxurl, { action: 'get_therapist_profile_action', nonce: crmAdminNonce, id: id }, function(res) {
                        if(res.profile) {
                            const p = res.profile;
                            let specs = Array.isArray(p.specializations)
                                ? p.specializations.map(s => `<span class="profile-badge">${escapeHtml(s)}</span>`).join('')
                                : '';
                            let apps = Array.isArray(p.treatmentApproaches)
                                ? p.treatmentApproaches.map(a => `<span class="profile-badge" style="background:#fef2f2; color:#ef4444;">${escapeHtml(a)}</span>`).join('')
                                : '';
                            const languages = Array.isArray(p.languages) ? p.languages.map(escapeHtml).join(', ') : 'N/A';
                            
                            profileContent.innerHTML = `
                                <div class="profile-section"><span class="profile-label">Full Name</span><div class="profile-value">${escapeHtml(res.fullName)}</div></div>
                                <div class="profile-section"><span class="profile-label">License Info</span><div class="profile-value">${escapeHtml(p.licenseType)} (${escapeHtml(p.licenseNumber)}) - Exp: ${escapeHtml(p.licenseExpiry)}</div></div>
                                <div class="profile-section"><span class="profile-label">Specializations</span><div>${specs || 'N/A'}</div></div>
                                <div class="profile-section"><span class="profile-label">Treatment Approaches</span><div>${apps || 'N/A'}</div></div>
                                <div class="profile-section"><span class="profile-label">Languages</span><div class="profile-value">${languages}</div></div>
                                <div class="profile-section"><span class="profile-label">Experience</span><div class="profile-value">${escapeHtml(p.yearsOfExperience)} Years of practice</div></div>
                            `;
                        }
                    });
                }
            });

            document.querySelectorAll('.close-profile-modal').forEach(b => b.onclick = () => profileModal.classList.remove('open'));

            // 4. AUTO AVAILABILITY SLOTS LOGIC (API #3)
            const availTherapist = document.getElementById('avail-therapist-id');
            const availDate = document.getElementById('avail-date');
            const availType = document.getElementById('avail-type');
            const slotsGrid = document.getElementById('slots-grid-output');
            const slotsResultContainer = document.getElementById('slots-result-container');
            const todayIso = new Date().toISOString().split('T')[0];
            let availabilityRequestToken = 0;

            if (availDate) {
                availDate.setAttribute('min', todayIso);
            }

            function loadAvailabilitySlots() {
                const id = availTherapist ? availTherapist.value : '';
                const date = availDate ? availDate.value : '';
                const type = availType ? availType.value : 'online';
                const requestToken = ++availabilityRequestToken;

                if (!date) {
                    if (slotsResultContainer) slotsResultContainer.style.display = 'none';
                    if (slotsGrid) slotsGrid.innerHTML = '';
                    return;
                }
                if (date < todayIso) {
                    if (slotsResultContainer) slotsResultContainer.style.display = 'block';
                    if (slotsGrid) slotsGrid.innerHTML = "<p style='color:#ef4444; font-weight:600;'>Past dates are not allowed.</p>";
                    return;
                }
                if (!id) {
                    if (slotsResultContainer) slotsResultContainer.style.display = 'block';
                    if (slotsGrid) slotsGrid.innerHTML = "<p style='color:#ef4444; font-weight:600;'>Please select a therapist first.</p>";
                    return;
                }

                if (slotsResultContainer) slotsResultContainer.style.display = 'block';
                if (slotsGrid) slotsGrid.innerHTML = "<p style='color:#3b82f6; font-weight:600;'>Loading time slots...</p>";

                jQuery.post(ajaxurl, { action: 'get_api_slots', nonce: crmAdminNonce, id: id, date: date, type: type }, function(res) {
                    // Ignore stale async responses when user has already changed selection.
                    if (requestToken !== availabilityRequestToken) return;
                    if ((availTherapist && availTherapist.value !== id) || (availDate && availDate.value !== date) || (availType && availType.value !== type)) return;
                    if (!slotsGrid) return;
                    slotsGrid.innerHTML = '';

                    if (res.slots && res.slots.length > 0) {
                        res.slots.forEach(slot => {
                            const statusClass = slot.available ? 'available' : 'booked';
                            const pill = document.createElement('div');
                            pill.className = `slot-pill ${statusClass}`;
                            pill.textContent = slot.time || '';
                            slotsGrid.appendChild(pill);
                        });
                    } else {
                        slotsGrid.innerHTML = "<p style='color:#ef4444; font-weight:600;'>No time slots found for this date.</p>";
                    }
                });
            }

            if (availDate) availDate.addEventListener('change', loadAvailabilitySlots);
            if (availTherapist) availTherapist.addEventListener('change', () => {
                if (availDate && availDate.value) loadAvailabilitySlots();
            });
            if (availType) availType.addEventListener('change', () => {
                if (availDate && availDate.value) loadAvailabilitySlots();
            });

            // 5. DURATION SELECTION UI
            const manualForm = document.getElementById('manual-booking-form');
            const manualDurationButtons = manualForm ? manualForm.querySelectorAll('.duration-box .dur-btn') : [];
            const manualDurationInput = manualForm ? manualForm.querySelector('input[name="duration"]') : null;
            manualDurationButtons.forEach(btn => {
                btn.onclick = function() {
                    manualDurationButtons.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    if (manualDurationInput) manualDurationInput.value = this.dataset.val;
                };
            });

            // 6. DIRECT BOOKING SLOTS LOADING
            const manualTherapist = document.getElementById('manual-therapist-id');
            const manualDate = document.getElementById('manual-session-date');
            const manualType = document.getElementById('manual-session-type');
            const manualTime = document.getElementById('manual-session-time');

            function resetManualTime(message) {
                if (!manualTime) return;
                manualTime.innerHTML = `<option value="">${message}</option>`;
            }

            function loadManualSlots() {
                if (!manualTherapist || !manualDate || !manualType || !manualTime) return;
                const id = manualTherapist.value;
                const date = manualDate.value;
                const type = manualType.value || 'online';

                if (!id || !date) {
                    resetManualTime('Select therapist/date first');
                    return;
                }

                resetManualTime('Loading slots...');
                jQuery.post(ajaxurl, { action: 'get_api_slots', nonce: crmAdminNonce, id: id, date: date, type: type }, function(res) {
                    const slots = (res && Array.isArray(res.slots)) ? res.slots : [];
                    const available = slots.filter(s => s && s.available && s.time);
                    if (!available.length) {
                        resetManualTime('No available time slots');
                        return;
                    }
                    manualTime.innerHTML = '<option value="">Select time</option>';
                    available.forEach(slot => {
                        const opt = document.createElement('option');
                        opt.value = slot.time;
                        opt.textContent = slot.time;
                        manualTime.appendChild(opt);
                    });
                });
            }

            if (manualTherapist) manualTherapist.addEventListener('change', loadManualSlots);
            if (manualDate) manualDate.addEventListener('change', loadManualSlots);
            if (manualType) manualType.addEventListener('change', loadManualSlots);

            // 7. MANUAL BOOKING LOGIC (API #4)
            document.getElementById('manual-booking-form').onsubmit = function(e) {
                e.preventDefault();
                const btn = this.querySelector('button[type="submit"]');
                const selectedTime = this.querySelector('select[name="sessionTime"]');
                if (!selectedTime || !selectedTime.value) {
                    alert('Please select a valid available time slot.');
                    return;
                }
                btn.innerText = "Transmitting to CRM...";
                const data = Object.fromEntries(new FormData(this).entries());
                jQuery.post(ajaxurl, { action: 'submit_direct_booking', nonce: crmAdminNonce, booking_data: data }, function(res) {
                    btn.innerText = "Schedule Session";
                    if(res.appointment) {
                        alert("Appointment Successfully Booked! ID: " + res.appointment.id);
                        location.reload();
                    } else {
                        alert("Error: " + (res.message || "Booking rejected by CRM."));
                    }
                });
            };

        });
    </script>
    <?php
}
