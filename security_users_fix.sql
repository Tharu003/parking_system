USE parking_db;

-- This creates a working test account only if it does not already exist.
-- Login:
-- Username: security
-- Password: Security@123

INSERT INTO security_users
(full_name, username, email, phone, password, security_question, security_answer, status)
SELECT
'ParkSmart Security',
'security',
'security@parksmart.local',
'0770000000',
'$2y$12$HnJe6k43MpDWWbugSvoYZu9hafkEONYcJsMPwEFFMp/S8yLKI6Yc2',
'First School',
'$2y$12$Jueudywz9RmAGuI1K2Q0pOTQp/K/V1EX8KkgQzVdJpGwFNvd9i0T2',
'ACTIVE'
WHERE NOT EXISTS (
    SELECT 1 FROM security_users
    WHERE username='security' OR email='security@parksmart.local'
);

SELECT id, full_name, username, email, status
FROM security_users
ORDER BY id;
