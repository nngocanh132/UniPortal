-- v17: give generated demo sections realistic capacity headroom.
-- The demo generator enrolls more students per section than the legacy default
-- capacity (110), which made every demo section look full. This is idempotent
-- and only touches generated sections (section_code LIKE 'D-%').
UPDATE ClassSection cs
JOIN (
    SELECT section_id, COUNT(*) AS enrolled
    FROM Enrollment
    WHERE status IN ('Enrolled', 'Completed')
    GROUP BY section_id
) e ON e.section_id = cs.section_id
SET cs.max_enrollment = GREATEST(COALESCE(cs.max_enrollment, 0), e.enrolled + 40),
    cs.capacity       = GREATEST(COALESCE(cs.capacity, 0),       e.enrolled + 40)
WHERE cs.section_code LIKE 'D-%';
