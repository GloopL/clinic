-- Query to check all diagnoses in the medical_diagnoses table
-- Run this in your database to see if your diagnosis was saved

-- View all diagnoses (most recent first)
SELECT 
    id,
    patient_id,
    record_id,
    diagnosis_type,
    diagnosis_date,
    provider_name,
    provider_role,
    chief_complaint,
    assessment,
    severity,
    status,
    created_at
FROM medical_diagnoses
ORDER BY created_at DESC
LIMIT 20;

-- View diagnoses for a specific patient (replace 104 with your patient_id)
SELECT 
    id,
    patient_id,
    record_id,
    diagnosis_type,
    diagnosis_date,
    provider_name,
    provider_role,
    chief_complaint,
    assessment,
    severity,
    status,
    created_at
FROM medical_diagnoses
WHERE patient_id = 104
ORDER BY created_at DESC;

-- Count diagnoses by type
SELECT 
    diagnosis_type,
    COUNT(*) as count
FROM medical_diagnoses
GROUP BY diagnosis_type;

-- View the most recent diagnosis
SELECT *
FROM medical_diagnoses
ORDER BY created_at DESC
LIMIT 1;

