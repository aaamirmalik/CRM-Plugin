<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<style>
    /* Your CSS remains the same - keeping it scoped to the container */
    .booking-container {
        width: 100%;
        max-width: 900px;
        margin: 20px auto;
        background: var(--white, #fff);
        border: 1px solid #E5E7EB;
        border-radius: 10px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.03);
        overflow: hidden;
        color: #123029;
    }
    .header { 
        padding: 30px;
    border-bottom: 1px solid #E5E7EB;
    background: #f8faf9;
 }
    .header h1 {
         font-size: 24px;
         margin: 0 0 5px 0;
         }
    .header p {
        color: #6b7280;
        font-size: 14px;
        margin: 0;
    }
    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 25px;
        padding: 30px;
    }
    .full-width { grid-column: span 2; }
    .form-group { display: flex; flex-direction: column; gap: 8px; }
    label { font-size: 13px; font-weight: 600; text-transform: uppercase; color: #4b5563; }
    input, select, textarea { padding: 12px; border: 1px solid #E5E7EB; border-radius: 6px; font-size: 15px; }
    .session-toggle { display: flex; gap: 10px; margin-top: 5px; }
    .toggle-btn { flex: 1; padding: 10px; border: 1px solid #E5E7EB; background: #fff; cursor: pointer; border-radius: 6px; display: flex; align-items: center; justify-content: center; gap: 8px; }
    .toggle-btn.active { background: #3e5640; color: white; border-color: #3e5640; }
    .room-selection { background: #f0fdf4; padding: 15px; border-radius: 8px; border: 1px dashed #3e5640; display: none; flex-direction: column; }
    .footer { padding: 20px 30px; background: #f8faf9; border-top: 1px solid #E5E7EB; display: flex; justify-content: flex-end; }
    .btn-submit { background: #3e5640; color: white; padding: 12px 30px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; }
    @media (max-width: 600px) { .form-grid { grid-template-columns: 1fr; } .full-width { grid-column: span 1; } }
    /* Loading Spinner */
.btn-submit:disabled {
    background: #6b7280;
    cursor: not-allowed;
    display: flex;
    align-items: center;
    gap: 10px;
}

.spinner {
    width: 18px;
    height: 18px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-radius: 50%;
    border-top-color: #fff;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<div class="booking-container">
    <div class="header">
        <h1>New Appointment Entry</h1>
        <p>Fill out the details below to sync with the CRM system.</p>
    </div>

    <form id="crm-booking-form" class="form-grid">
        <div class="form-group full-width">
            <label>Your Name</label>
            <input type="text" name="client_name" placeholder="Enter your full name..." required>
        </div>

        <div class="form-group">
            <label>Select Therapist (CRM)</label>
            <select name="therapist_id" id="therapist-select" required>
                <option value="">Loading Therapists...</option>
            </select>
        </div>

        <div class="form-group">
            <label>Select Service (CRM)</label>
            <select name="service_id" id="service-select" required>
                <option value="">Loading Services...</option>
            </select>
        </div>

        <div class="form-group">
            <label>Choose Date</label>
            <input type="date" name="appointment_date" required>
        </div>

        <div class="form-group">
            <label>Select Time Slot (CRM)</label>
            <select name="time_slot" required>
                <option value="09:00 AM">09:00 AM</option>
                <option value="10:00 AM">10:00 AM</option>
                <option value="11:00 AM">11:00 AM</option>
                <option value="02:00 PM">02:00 PM</option>
            </select>
        </div>

        <div class="form-group">
            <label>Medium</label>
            <input type="hidden" name="medium" id="medium-input" value="online">
            <div class="session-toggle">
                <button type="button" class="toggle-btn active" data-type="online" onclick="setMedium('online')">
                    <iconify-icon icon="lucide:video"></iconify-icon> Online
                </button>
                <button type="button" class="toggle-btn" data-type="physical" onclick="setMedium('physical')">
                    <iconify-icon icon="lucide:map-pin"></iconify-icon> Physical
                </button>
            </div>
        </div>

        <div class="form-group room-selection" id="roomGroup">
            <label>Select Room (CRM)</label>
            <select name="room_id">
                <option value="Room A">Room A - Ground Floor</option>
                <option value="Room B">Room B - Quiet Zone</option>
                <option value="Executive">Executive Suite</option>
            </select>
        </div>

        <div class="form-group">
            <label>Select Duration</label>
            <select name="duration">
                <option value="30">30 Minutes</option>
                <option value="50" selected>50 Minutes</option>
                <option value="90">90 Minutes</option>
            </select>
        </div>

        <div class="form-group">
            <label>Select Session Type</label>
            <select name="session_type">
                <option value="standard">Standard Session</option>
                <option value="intake">Initial Intake</option>
                <option value="emergency">Emergency Follow-up</option>
            </select>
        </div>

        <div class="form-group full-width">
            <label>Write Note</label>
            <textarea name="notes" rows="4" placeholder="Add any specific clinical notes..."></textarea>
        </div>

        <div class="footer full-width" style="grid-column: span 2;">
            <button type="submit" class="btn-submit">Save Booking to CRM</button>
        </div>
    </form>
</div>

<script>
    // Local UI Logic
    function setMedium(type) {
        const btns = document.querySelectorAll('.toggle-btn');
        const roomGroup = document.getElementById('roomGroup');
        const mediumInput = document.getElementById('medium-input');
        
        btns.forEach(b => b.classList.remove('active'));
        mediumInput.value = type;
        
        if(type === 'physical') {
            document.querySelector('[data-type="physical"]').classList.add('active');
            roomGroup.style.display = 'flex';
        } else {
            document.querySelector('[data-type="online"]').classList.add('active');
            roomGroup.style.display = 'none';
        }
    }
</script>