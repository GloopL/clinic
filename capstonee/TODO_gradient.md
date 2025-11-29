# Red Gradient Theme Implementation

## Files to Update (excluding index.php):
### Dashboard Files:
- dashboard.php
- nurse_dashboard.php
- doctor_dashboard.php
- msa_dashboard.php
- user_dashboard.php

### Module Files:
#### Forms:
- modules/forms/medical_form.php
- modules/forms/dental_form.php
- modules/forms/history_form.php
- modules/user_medical_form.php
- modules/user_dental_form.php
- modules/user_history_form.php

#### Records:
- modules/records/patients.php
- modules/records/verify_submission.php
- modules/records/view_record.php
- modules/records/view_patient.php
- modules/records/edit_patient.php
- modules/records/add_patient.php
- modules/records/delete_record.php
- modules/records/get_recent_verifications.php

#### QR Code:
- modules/qrcode/generate.php
- modules/qrcode/scan.php
- modules/qrcode/scan_form.php

#### Analytics:
- modules/analytics/analytics_dashboard.php

### Other Files:
- admin_panel.php
- register.php
- register_student.php
- edit_user.php
- forgot_password.php
- profile.php
- update_user_profile.php
- logout.php

## Changes Required:
- Replace all `bg-[color]-[number]` classes with `bg-gradient-to-r from-red-800 to-red-500`
- Change text colors on gradient backgrounds to `text-white`
- Adjust hover states to maintain gradient appearance
- Update status indicators, buttons, cards, and headers

## Status:
- [x] dashboard.php
- [ ] nurse_dashboard.php
- [ ] doctor_dashboard.php
- [ ] msa_dashboard.php
- [ ] user_dashboard.php
- [ ] modules/forms/medical_form.php
- [ ] modules/forms/dental_form.php
- [ ] modules/forms/history_form.php
- [ ] modules/user_medical_form.php
- [ ] modules/user_dental_form.php
- [ ] modules/user_history_form.php
- [ ] modules/records/patients.php
- [ ] modules/records/verify_submission.php
- [ ] modules/records/view_record.php
- [ ] modules/records/view_patient.php
- [ ] modules/records/edit_patient.php
- [ ] modules/records/add_patient.php
- [ ] modules/records/delete_record.php
- [ ] modules/records/get_recent_verifications.php
- [ ] modules/qrcode/generate.php
- [ ] modules/qrcode/scan.php
- [ ] modules/qrcode/scan_form.php
- [ ] modules/analytics/analytics_dashboard.php
- [ ] admin_panel.php
- [ ] register.php
- [ ] register_student.php
- [ ] edit_user.php
- [ ] forgot_password.php
- [ ] profile.php
- [ ] update_user_profile.php
- [ ] logout.php
