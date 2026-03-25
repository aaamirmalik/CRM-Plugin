<?php
if (!defined('ABSPATH')) exit;

class CRM_Form_Renderer {
    public function render_full_form() {
        $raw_fields = get_option('crm_form_fields', '[]');
        if (is_array($raw_fields)) {
            $fields = $raw_fields;
        } else {
            $fields = json_decode((string) $raw_fields, true);
        }
        if (!is_array($fields)) $fields = [];
        $title = get_option('crm_form_title', 'Schedule New Session');
        $p_color = sanitize_hex_color(get_option('crm_primary_color', '#1B6D12'));
        if (!$p_color) $p_color = '#1B6D12';
        $radius = absint(get_option('crm_border_radius', '12'));

        // Fetch real therapists from the API
        $api = new CRM_API_Handler();
        $therapists = $api->get_all_therapists();
        if (!is_array($therapists) || empty($therapists)) {
            $therapists = crm_get_cached_therapists();
        }

        ob_start(); ?>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

            .crm-booking-wrapper {
                font-family: 'Inter', -apple-system, sans-serif;
                max-width: 700px;
                margin: 40px auto;
                background: #ffffff;
                border-radius: <?php echo $radius; ?>px;
                box-shadow: 0 20px 50px rgba(0,0,0,0.1);
                overflow: hidden;
                border: 1px solid #e2e8f0;
            }

            .crm-form-header {
                padding: 30px 40px;
                background: #fff;
                border-bottom: 1px solid #f1f5f9;
            }

            .crm-form-header h2 {
                margin: 0;
                font-size: 22px;
                font-weight: 700;
                color: #1e293b;
            }

            .crm-form-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
                padding: 30px 40px;
            }

            .crm-form-group {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }

            .crm-form-group.full { grid-column: span 2; }
            .crm-form-group.half { grid-column: span 1; }

            .crm-form-group label {
                font-size: 13px;
                font-weight: 600;
                color: #475569;
            }

            .crm-form-group input, 
            .crm-form-group select, 
            .crm-form-group textarea {
                padding: 12px 16px;
                border: 1.5px solid #e2e8f0;
                border-radius: 8px;
                font-size: 14px;
                transition: all 0.2s ease;
                background: #fff;
                color: #1e293b;
                width: 100%;
                box-sizing: border-box;
            }

            .crm-form-group input:focus, 
            .crm-form-group select:focus, 
            .crm-form-group textarea:focus {
                border-color: <?php echo $p_color; ?>;
                outline: none;
            }

            /* Duration and Slot Grid */
            .duration-box { display: flex; gap: 10px; margin-top: 5px; }
            .dur-btn { 
                padding: 8px 15px; 
                border: 1.5px solid #e2e8f0; 
                border-radius: 6px; 
                background: #fff; 
                font-size: 13px; 
                cursor: pointer; 
                font-weight: 600;
                color: #64748b;
            }
            .dur-btn.active { background: <?php echo $p_color; ?>; color: #fff; border-color: <?php echo $p_color; ?>; }

            .slots-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
                gap: 10px;
                margin-top: 10px;
            }

            .slot-btn {
                padding: 10px;
                border: 1.5px solid #e2e8f0;
                background: #fff;
                border-radius: 8px;
                cursor: pointer;
                text-align: center;
                font-size: 13px;
                font-weight: 700;
                color: #1e293b;
            }

            .slot-btn.available:hover { border-color: <?php echo $p_color; ?>; color: <?php echo $p_color; ?>; }
            .slot-btn.active { background-color: <?php echo $p_color; ?>; color: #fff; border-color: <?php echo $p_color; ?>; }
            .slot-btn.booked { background: #f8fafc; color: #cbd5e1; cursor: not-allowed; text-decoration: line-through; border-style: dashed; }

            .crm-form-footer {
                padding: 30px 40px;
                background: #fff;
                border-top: 1px solid #f1f5f9;
                display: flex;
                justify-content: flex-end;
                gap: 12px;
            }

            .btn-cancel { padding: 12px 24px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; font-weight: 600; cursor: pointer; color: #64748b; }
            .btn-submit {
                background-color: <?php echo $p_color; ?>;
                color: #fff;
                padding: 12px 30px;
                border: none;
                border-radius: 8px;
                font-size: 15px;
                font-weight: 600;
                cursor: pointer;
            }

            @media (max-width: 640px) { .crm-form-grid { grid-template-columns: 1fr; } .crm-form-group.full, .crm-form-group.half { grid-column: span 1; } }
        </style>

        <div class="crm-booking-wrapper">
            <div class="crm-form-header">
                <h2><?php echo esc_html($title); ?></h2>
            </div>

            <form id="crm-booking-form">
                <div class="crm-form-grid">
                    <?php if (!empty($fields)) : foreach ($fields as $idx => $f) :
                        if (!is_array($f)) continue;
                        $field_label = isset($f['label']) ? sanitize_text_field((string) $f['label']) : ('Field ' . ($idx + 1));
                        $field_type = isset($f['type']) ? sanitize_key((string) $f['type']) : 'text';
                        if (!in_array($field_type, ['text', 'select', 'textarea'], true)) $field_type = 'text';
                        $width_class = (isset($f['width']) && $f['width'] === 'full') ? 'full' : 'half';
                        if ($field_type === 'textarea') $width_class = 'full';
                        $is_required = !empty($f['required']);
                        $req = $is_required ? 'required' : '';
                        $label_clean = strtolower($field_label);
                        $field_id = isset($f['id']) ? sanitize_key((string) $f['id']) : sanitize_title($field_label);
                        if ($field_id === '' || is_numeric($field_id)) $field_id = 'field_' . ($idx + 1);
                        $field_placeholder = isset($f['placeholder']) ? sanitize_text_field((string) $f['placeholder']) : '';
                    ?>
                        <div class="crm-form-group <?php echo $width_class; ?>">
                            <label><?php echo esc_html($field_label); ?><?php echo $is_required ? ' *' : ''; ?></label>
                            
                            <?php if ($field_type === 'select' && strpos($label_clean, 'therapist') !== false) : ?>
                                <select name="therapistId" id="crm-therapist-id" <?php echo $req; ?>>
                                    <option value="">Select Professional</option>
                                    <?php foreach ($therapists as $t) : ?>
                                        <option value="<?php echo esc_attr($t['id']); ?>"><?php echo esc_html($t['fullName']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            
                            <?php elseif (strpos($label_clean, 'service') !== false) : ?>
                                <select name="serviceId" class="crm-input" <?php echo $req; ?>>
                                    <option value="50">Psychotherapy (PR) session 60 minutes (MVA) - $149.61 (60min)</option>
                                </select>

                            <?php elseif ($field_type === 'textarea') : ?>
                                <textarea name="notes" placeholder="Session notes or special instructions" rows="3" <?php echo $req; ?>></textarea>

                            <?php else : ?>
                                <?php 
                                    $input_type = 'text';
                                    $input_id = (strpos($label_clean, 'date') !== false) ? 'crm-session-date' : $field_id;
                                    if (strpos($label_clean, 'date') !== false) $input_type = 'date';
                                    if (strpos($label_clean, 'email') !== false) {
                                        $input_type = 'email';
                                        $input_id = 'email';
                                    }
                                    if (strpos($label_clean, 'phone') !== false) $input_id = 'phone';
                                    if (strpos($label_clean, 'name') !== false) $input_id = 'fullName';
                                ?>
                                <input type="<?php echo esc_attr($input_type); ?>" id="<?php echo esc_attr($input_id); ?>" name="<?php echo esc_attr($input_id); ?>" placeholder="<?php echo esc_attr($field_placeholder); ?>" <?php echo $req; ?>>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                        <div class="crm-form-group full">
                            <label>Duration (minutes)</label>
                            <div class="duration-box">
                                <div class="dur-btn" data-min="30">30</div>
                                <div class="dur-btn" data-min="45">45</div>
                                <div class="dur-btn active" data-min="60">60</div>
                                <div class="dur-btn" data-min="90">90</div>
                                <div class="dur-btn" data-min="120">120</div>
                            </div>
                            <input type="hidden" name="duration" id="selected-duration" value="60">
                        </div>

                        <div class="crm-form-group full" id="crm-slots-wrapper">
                            <label>Available Time Slots</label>
                            <div id="crm-slots-grid" class="slots-grid">
                                <p style="color: #94a3b8; font-size: 13px; grid-column: span 3;">Select therapist and date to see times...</p>
                            </div>
                            <input type="hidden" name="sessionTime" id="selected-session-time" required>
                        </div>

                    <?php endif; ?>
                </div>

                <div class="crm-form-footer">
                    <button type="button" class="btn-cancel">Cancel</button>
                    <button type="submit" class="btn-submit" id="crm-main-submit">Schedule Session</button>
                </div>
            </form>
        </div>

        <script>
            jQuery(document).ready(function($) {
                const ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
                const bookingNonce = '<?php echo esc_js(wp_create_nonce('crm_public_booking_nonce')); ?>';

                // Duration Toggle
                $('.dur-btn').click(function() {
                    $('.dur-btn').removeClass('active');
                    $(this).addClass('active');
                    $('#selected-duration').val($(this).data('min'));
                });

                // Auto-fetch slots when Therapist or Date changes
                $('#crm-therapist-id, #crm-session-date').on('change', function() {
                    const id = $('#crm-therapist-id').val();
                    const date = $('#crm-session-date').val();
                    if(!id || !date) return;

                    $('#crm-slots-grid').html('<p style="font-size:13px; color:<?php echo $p_color; ?>;">Syncing availability...</p>');

                    $.post(ajaxurl, {
                        action: 'get_api_slots',
                        nonce: bookingNonce,
                        id: id,
                        date: date,
                        type: 'online'
                    }, function(res) {
                        let html = '';
                        if(res.slots && res.slots.length > 0) {
                            res.slots.forEach(s => {
                                const state = s.available ? 'available' : 'booked';
                                html += `<div class="slot-btn ${state}" data-time="${s.time}">${s.time}</div>`;
                            });
                        } else {
                            html = '<p style="color:#ef4444; font-size:13px;">No slots found for this date.</p>';
                        }
                        $('#crm-slots-grid').html(html);
                    });
                });

                // Slot Selection
                $(document).on('click', '.slot-btn.available', function() {
                    $('.slot-btn').removeClass('active');
                    $(this).addClass('active');
                    $('#selected-session-time').val($(this).data('time'));
                });

                // Main Submission
                $('#crm-booking-form').on('submit', function(e) {
                    e.preventDefault();
                    if(!$('#selected-session-time').val()) return alert('Please select a time slot.');
                    
                    const btn = $('#crm-main-submit');
                    btn.text('Booking...').prop('disabled', true);
                    
                    const data = Object.fromEntries(new FormData(this).entries());
                    data.sessionType = 'online'; // Default

                    $.post(ajaxurl, {
                        action: 'frontend_crm_booking_submit',
                        nonce: bookingNonce,
                        booking_data: data
                    }, function(res) {
                        alert(res.message);
                        if(res.appointment) location.reload();
                        else btn.text('Schedule Session').prop('disabled', false);
                    });
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }
}
