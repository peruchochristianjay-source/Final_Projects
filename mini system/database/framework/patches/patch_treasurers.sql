USE mini_system_db;

-- Add treasurers for all entities that don't have one yet
-- Password for all: Officer@123 (hashed)

INSERT IGNORE INTO users (full_name, email, password_hash, role, entity_id, auth_provider, is_google_account) VALUES
('SSG Treasurer',          'treasurer@ssg.com',     '$2y$10$r4lTgR6oFh1lPunnl1dj3u.nrYt5ELhw27X8t.YPX0FAgymrKk6xW', 'officer', 1,  'local', 0),
('JOES Treasurer',         'treasurer@joes.com',    '$2y$10$r4lTgR6oFh1lPunnl1dj3u.nrYt5ELhw27X8t.YPX0FAgymrKk6xW', 'officer', 2,  'local', 0),
('PADC Treasurer',         'treasurer@padc.com',    '$2y$10$r4lTgR6oFh1lPunnl1dj3u.nrYt5ELhw27X8t.YPX0FAgymrKk6xW', 'officer', 3,  'local', 0),
('YMO Treasurer',          'treasurer@ymo.com',     '$2y$10$r4lTgR6oFh1lPunnl1dj3u.nrYt5ELhw27X8t.YPX0FAgymrKk6xW', 'officer', 4,  'local', 0),
('LSC Treasurer',          'treasurer@lsc.com',     '$2y$10$r4lTgR6oFh1lPunnl1dj3u.nrYt5ELhw27X8t.YPX0FAgymrKk6xW', 'officer', 5,  'local', 0),
('JFMS Treasurer',         'treasurer@jfms.com',    '$2y$10$r4lTgR6oFh1lPunnl1dj3u.nrYt5ELhw27X8t.YPX0FAgymrKk6xW', 'officer', 6,  'local', 0),
('Sports Club Treasurer',  'treasurer@sports.com',  '$2y$10$r4lTgR6oFh1lPunnl1dj3u.nrYt5ELhw27X8t.YPX0FAgymrKk6xW', 'officer', 7,  'local', 0),
('English Club Treasurer', 'treasurer@english.com', '$2y$10$r4lTgR6oFh1lPunnl1dj3u.nrYt5ELhw27X8t.YPX0FAgymrKk6xW', 'officer', 8,  'local', 0),
('Sci-Math Treasurer',     'treasurer@scimath.com', '$2y$10$r4lTgR6oFh1lPunnl1dj3u.nrYt5ELhw27X8t.YPX0FAgymrKk6xW', 'officer', 9,  'local', 0),
('SamFilko Treasurer',     'treasurer@samfilko.com','$2y$10$r4lTgR6oFh1lPunnl1dj3u.nrYt5ELhw27X8t.YPX0FAgymrKk6xW', 'officer', 10, 'local', 0);
