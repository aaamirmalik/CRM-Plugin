<?php
function crm_dashboard_page() {
    // 1. Initialize API Handler and fetch live data
    $api = new CRM_API_Handler();
    $therapists = $api->get_all_therapists();
    
    // NEW: Fetch Dynamic Appointment Count
    $total_appointments = $api->get_appointment_count();

    // 2. Determine connection status and therapist count
    if (is_array($therapists) && !empty($therapists)) {
        $total_therapists = count($therapists);
        $connection_status = 'active';
        $status_label = 'Connected';
    } else {
        $total_therapists = 0;
        $connection_status = 'inactive';
        $status_label = 'Disconnected';
        $therapists = []; 
    }

    // 3. Fetch existing forms
    $all_forms = get_option('crm_registered_forms_list', []);
    $saved_fields = get_option('crm_form_fields', '[]');
    $sync_url = wp_nonce_url(admin_url('admin-post.php?action=crm_sync_therapists_cache'), 'crm_sync_therapists_cache');
    $last_sync = (int) get_option('crm_therapist_last_sync', 0);
    $last_sync_label = $last_sync > 0 ? wp_date('Y-m-d H:i:s', $last_sync) : 'Never';
    
    if(empty($all_forms)) {
        $all_forms[] = [
            'id' => 'crm_default',
            'name' => 'Consultation Form', 
            'shortcode' => '[crm_form]', 
            'entries' => '10', 
            'status' => 'Active',
            'fields' => '[]'
        ];
    }
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

        .builder-left { flex: 1; }
        .builder-right { flex: 0 0 350px; background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; }
        .builder-table { width: 100%; border-collapse: collapse; }
        .builder-table th { text-align: left; font-size: 11px; text-transform: uppercase; color: #64748b; padding: 10px; border-bottom: 2px solid #f1f5f9; }
        .builder-table td { padding: 10px; border-bottom: 1px solid #f1f5f9; }
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
        .slot-pill { padding: 12px; border: 1.5px solid #e2e8f0; border-radius: 8px; text-align: center; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s ease; }
        .slot-pill.available { background: #ffffff; color: #1e293b; border-color: #3b82f6; }
        .slot-pill.available:hover { background: #3b82f6; color: white; transform: translateY(-2px); }
        .slot-pill.booked { background: #f8fafc; color: #cbd5e1; cursor: not-allowed; text-decoration: line-through; border-style: dashed; }
        .slot-pill.selected { background: #10b981 !important; color: white !important; border-color: #059669 !important; }

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
    </style>

    <div class="crm-app-shell">
        <header class="crm-top-nav">
            <div class="crm-nav-left"><h1>CRM Connector Pro</h1><p>Connect Your Website with Your CRM</p></div>
            <div class="crm-nav-right">
                <iconify-icon icon="lucide:message-square"></iconify-icon>
                <iconify-icon icon="lucide:bell"></iconify-icon>
                <iconify-icon icon="lucide:settings"></iconify-icon>
            </div>
        </header>

        <div class="crm-main-layout">
            <aside class="crm-sidebar">
                <nav id="crm-admin-nav">
                    <a class="nav-item active" data-tab="dashboard"><iconify-icon icon="lucide:layout-dashboard"></iconify-icon> Dashboard</a>
                    <a class="nav-item" data-tab="therapists"><iconify-icon icon="lucide:users"></iconify-icon> Therapists</a>
                    <a class="nav-item" data-tab="availability"><iconify-icon icon="lucide:calendar-range"></iconify-icon> Availability</a>
                    <a class="nav-item" data-tab="direct-booking"><iconify-icon icon="lucide:calendar-plus"></iconify-icon> Direct Booking</a>
                    <a class="nav-item" data-tab="forms"><iconify-icon icon="lucide:file-text"></iconify-icon> Forms</a>
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
                                <span class="dot <?php echo $connection_status; ?>"></span> 
                                <div><strong><?php echo $status_label; ?></strong><p>TherapyFlow API</p></div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <label>Active Therapists</label>
                            <div class="huge-number"><?php echo $total_therapists; ?></div>
                            <p>Profiles fetched from CRM</p>
                        </div>
                        <div class="stat-card">
                            <label>Total Appointments</label>
                            <div class="huge-number" style="color: #3b82f6;"><?php echo $total_appointments; ?></div>
                            <p>Dynamic Real-time Count</p>
                        </div>
                        <div class="stat-card"><label>Sync Status</label><div class="huge-number" style="font-size:20px; color:#10b981;">Active</div></div>
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

                <section id="tab-availability" class="crm-tab-content">
                    <div class="crm-section-card">
                        <h3>Easy Availability Checker</h3>
                        <p>Select a therapist and date to instantly see available booking windows.</p>
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
                            <button id="check-slots-btn" class="btn-primary" style="height: 38px;">Fetch Free Slots</button>
                        </div>
                        <div id="slots-result-container" style="margin-top:30px; display:none; background: #fafafa; padding: 25px; border-radius: 12px; border: 1px dashed #ddd;">
                            <h4 id="slots-title" style="margin-top:0;">Clinically Available Times</h4>
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
                                <input type="text" name="fullName" class="f-input" placeholder="Select client" required></div>
                                <div><label class="form-label-premium">Therapist *</label>
                                    <select name="therapistId" class="f-input" required>
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
                                    <option value="">Select service</option>
                                    <option value="50">Psychotherapy (PR) session 60 minutes (MVA) - $149.61 (60min)</option>
                                    <option value="51">Initial Consultation 30 min - Free</option>
                                </select>
                            </div>

                            <div class="form-row-2">
                                <div><label class="form-label-premium">Date *</label>
                                <input type="date" name="sessionDate" class="f-input" required></div>
                                <div><label class="form-label-premium">Room *</label>
                                    <select name="room" class="f-input">
                                        <option value="">Select room</option>
                                        <option>Room 102 - Psych-B</option>
                                        <option>Virtual Meeting Room</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row-2" style="align-items: start;">
                                <div><label class="form-label-premium">Time *</label>
                                <input type="text" name="sessionTime" class="f-input" placeholder="Select time" required></div>
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
                            <thead><tr><th>ID</th><th>Client</th><th>Date</th><th>Type</th><th>Status</th></tr></thead>
                            <tbody id="logs-table-body">
                                <tr><td>#10200</td><td>Test User</td><td>2026-03-16</td><td>Online</td><td><span class="badge active">Scheduled</span></td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section id="tab-forms" class="crm-tab-content">
                    <div class="crm-section-card">
                        <div class="card-header">
                            <div><h3>Appointment Forms</h3><p>Manage your scheduling forms.</p></div>
                            <button class="btn-primary" id="open-builder-btn">Create New Form</button>
                        </div>
                        <table class="crm-table">
                            <thead><tr><th>Form Name</th><th>Shortcode</th><th>Entries</th><th>Status</th><th>Actions</th></tr></thead>
                            <tbody id="crm-forms-list-body">
                                <?php foreach($all_forms as $form): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($form['name']); ?></strong></td>
                                    <td><code><?php echo esc_html($form['shortcode']); ?></code></td>
                                    <td><?php echo esc_html($form['entries']); ?> Entries</td>
                                    <td><span class="badge active"><?php echo esc_html($form['status']); ?></span></td>
                                    <td>
                                        <a href="#" class="edit-form-trigger" data-id="<?php echo esc_attr($form['id']); ?>" data-name="<?php echo esc_attr($form['name']); ?>" data-fields="<?php echo esc_attr($form['fields']); ?>">Edit</a> | 
                                        <a href="#" class="delete-form-trigger" style="color:#ef4444;" data-id="<?php echo esc_attr($form['id']); ?>">Delete</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section id="tab-crm-settings" class="crm-tab-content">
                    <div class="crm-section-card" style="max-width: 600px;">
                        <h3>CRM Settings</h3>
                        <p>Configure your CRM integration settings below.</p>
                        <div class="crm-form-field"><label>Select CRM</label><select class="f-input"><option>TherapyFlow Pro</option></select></div>
                        <div class="crm-form-field"><label>CRM URL</label><input type="text" class="f-input" value="https://demo.therapyflow.pro/api"></div>
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

    <div class="crm-modal-overlay" id="builder-modal">
        <div class="crm-modal">
            <form id="crm-settings-form-logic">
                <input type="hidden" id="form-edit-id" value="">
                <div class="crm-modal-header"><h3>Form Builder Pro</h3><button type="button" id="close-modal-btn" style="background:none; border:none; font-size:24px; cursor:pointer;">&times;</button></div>
                <div class="crm-modal-body">
                    <div class="builder-left">
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
                            <div><label>Form Title</label><input type="text" id="input-title" class="f-input" required></div>
                            <div><label>Brand Color</label><input type="color" id="input-color" value="#1B6D12" style="height:40px; width:100%;"></div>
                        </div>
                        <table class="builder-table">
                            <thead><tr><th>Label</th><th>Type</th><th>Width</th><th></th></tr></thead>
                            <tbody id="crm-fields-body"></tbody>
                        </table>
                        <button type="button" id="add-field-btn" class="btn-blue-outline" style="width:100%; margin-top:15px; border:1px dashed #3b82f6;">+ Add Field</button>
                    </div>
                    <div class="builder-right">
                        <div class="mock-form">
                            <h4 id="prev-title">Preview</h4>
                            <div id="prev-fields-area"></div>
                            <button type="button" id="prev-btn" style="width:100%; color:white; border:none; padding:10px;">Submit</button>
                        </div>
                    </div>
                </div>
                <div class="crm-modal-footer">
                    <button type="button" class="btn-blue-outline close-modal-btn">Cancel</button>
                    <button type="submit" class="btn-primary" id="save-ajax-btn">Save Configuration</button>
                </div>
            </form>
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

            navItems.forEach(item => {
                item.addEventListener('click', function() {
                    const targetTab = this.getAttribute('data-tab');
                    navItems.forEach(i => i.classList.remove('active'));
                    this.classList.add('active');
                    tabContents.forEach(tab => tab.classList.remove('active'));
                    document.getElementById('tab-' + targetTab).classList.add('active');
                });
            });

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
            document.getElementById('check-slots-btn').addEventListener('click', function() {
                const id = document.getElementById('avail-therapist-id').value;
                const date = document.getElementById('avail-date').value;
                const type = document.getElementById('avail-type').value;
                
                if(!id || !date) return alert('Please select a therapist and a specific date.');
                
                const btn = this;
                const grid = document.getElementById('slots-grid-output');
                btn.innerText = "Syncing CRM...";
                grid.innerHTML = "";

                jQuery.post(ajaxurl, { action: 'get_api_slots', nonce: crmAdminNonce, id: id, date: date, type: type }, function(res) {
                    btn.innerText = "Fetch Free Slots";
                    document.getElementById('slots-result-container').style.display = 'block';
                    
                    if(res.slots && res.slots.length > 0) {
                        res.slots.forEach(slot => {
                            const statusClass = slot.available ? 'available' : 'booked';
                            const pill = document.createElement('div');
                            pill.className = `slot-pill ${statusClass}`;
                            pill.textContent = slot.time || '';
                            if (slot.available) {
                                pill.addEventListener('click', () => window.selectThisSlot(slot.time, pill));
                            }
                            grid.appendChild(pill);
                        });
                    } else {
                        grid.innerHTML = "<p style='color:#ef4444; font-weight:600;'>No slots found for this date. The therapist might be fully booked.</p>";
                    }
                });
            });

            window.selectThisSlot = (time, el) => {
                document.querySelectorAll('.slot-pill').forEach(p => p.classList.remove('selected'));
                if (el) el.classList.add('selected');
                if(confirm(`Would you like to transfer ${time} to the Schedule form?`)) {
                    document.querySelector('[data-tab="direct-booking"]').click();
                    document.getElementsByName('sessionTime')[0].value = time;
                }
            };

            // 5. DURATION SELECTION UI
            document.querySelectorAll('.dur-btn').forEach(btn => {
                btn.onclick = function() {
                    document.querySelectorAll('.dur-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    document.querySelector('input[name="duration"]').value = this.dataset.val;
                };
            });

            // 6. MANUAL BOOKING LOGIC (API #4)
            document.getElementById('manual-booking-form').onsubmit = function(e) {
                e.preventDefault();
                const btn = this.querySelector('button[type="submit"]');
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

            // 7. MODAL BUILDER LOGIC
            const modal = document.getElementById('builder-modal');
            const fieldBody = document.getElementById('crm-fields-body');
            const openBtn = document.getElementById('open-builder-btn');

            if(openBtn) openBtn.onclick = () => {
                document.getElementById('crm-settings-form-logic').reset();
                document.getElementById('form-edit-id').value = '';
                fieldBody.innerHTML = '';
                modal.classList.add('open');
            };
            document.getElementById('close-modal-btn').onclick = () => modal.classList.remove('open');

            function createRow(d = { label: '', type: 'text', width: 'half' }) {
                const tr = document.createElement('tr');
                const safeLabel = escapeHtml(d.label || '');
                tr.innerHTML = `
                    <td><input type="text" class="f-label f-input" value="${safeLabel}"></td>
                    <td><select class="f-type f-input">
                        <option value="text" ${d.type=='text'?'selected':''}>Text</option>
                        <option value="select" ${d.type=='select'?'selected':''}>Select</option>
                    </select></td>
                    <td><select class="f-width f-input">
                        <option value="half" ${d.width=='half'?'selected':''}>50%</option>
                        <option value="full" ${d.width=='full'?'selected':''}>100%</option>
                    </select></td>
                    <td><button type="button" class="remove-field" style="color:red; border:none; background:none; cursor:pointer;">&times;</button></td>
                `;
                fieldBody.appendChild(tr);
                updatePreview();
            }

            function updatePreview() {
                document.getElementById('prev-title').innerText = document.getElementById('input-title').value || "Preview";
                document.getElementById('prev-btn').style.backgroundColor = document.getElementById('input-color').value;
                const area = document.getElementById('prev-fields-area');
                area.innerHTML = '';
                Array.from(fieldBody.querySelectorAll('tr')).forEach(tr => {
                    const label = tr.querySelector('.f-label').value;
                    const div = document.createElement('div');
                    div.style.width = tr.querySelector('.f-width').value === 'half' ? '48%' : '100%';
                    div.style.display = 'inline-block';
                    div.innerHTML = `<label style="font-size:10px; font-weight:700; color:#94a3b8; display:block;">${escapeHtml(label || 'Field')}</label><div style="width:100%; height:30px; background:#f1f5f9; border-radius:4px; margin-bottom:10px;"></div>`;
                    area.appendChild(div);
                });
            }

            document.getElementById('add-field-btn').onclick = () => createRow();
            document.getElementById('crm-settings-form-logic').addEventListener('input', updatePreview);
            fieldBody.onclick = (e) => { if(e.target.classList.contains('remove-field')) { e.target.closest('tr').remove(); updatePreview(); } };
            
            document.querySelectorAll('.edit-form-trigger').forEach(btn => {
                btn.onclick = function(e) {
                    e.preventDefault();
                    document.getElementById('form-edit-id').value = this.dataset.id;
                    document.getElementById('input-title').value = this.dataset.name;
                    fieldBody.innerHTML = '';
                    try {
                        const parsed = JSON.parse(this.dataset.fields || '[]');
                        if (Array.isArray(parsed)) parsed.forEach(f => createRow(f));
                    } catch (err) {
                        console.error('Invalid form config payload', err);
                    }
                    modal.classList.add('open');
                };
            });

            document.querySelectorAll('.delete-form-trigger').forEach(btn => {
                btn.onclick = function(e) {
                    e.preventDefault();
                    if(!confirm('Delete this form?')) return;
                    jQuery.post(ajaxurl, { action: 'delete_crm_form_action', nonce: crmAdminNonce, form_id: this.dataset.id }, () => location.reload());
                };
            });

            document.getElementById('crm-settings-form-logic').onsubmit = function(e) {
                e.preventDefault();
                const fields = Array.from(fieldBody.querySelectorAll('tr')).map(tr => ({
                    label: tr.querySelector('.f-label').value,
                    type: tr.querySelector('.f-type').value,
                    width: tr.querySelector('.f-width').value
                }));
                const data = { action: 'save_crm_form_action', form_id: document.getElementById('form-edit-id').value, name: document.getElementById('input-title').value, fields: JSON.stringify(fields) };
                data.nonce = crmAdminNonce;
                jQuery.post(ajaxurl, data, () => location.reload());
            };
        });
    </script>
    <?php
}
