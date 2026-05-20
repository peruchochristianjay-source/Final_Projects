USE mini_system_db;

INSERT INTO entities (name, short_name, category, photo_path) VALUES
  ('Supreme Student Government (SSG)',              'SSG',  'organization', '/mini%20system/IMG/Organizations/SSG.png'),
  ('Junior Operation Executive Society (JOES)',     'JOES', 'organization', '/mini%20system/IMG/Organizations/OAES.png'),
  ('Programers, Animators, Developers Clan (PADC)', 'PADC', 'organization', '/mini%20system/IMG/Organizations/PADC.png'),
  ('Young Mentors Organization (YMO)',              'YMO',  'organization', '/mini%20system/IMG/Organizations/CTE.png'),
  ('Library Student Council (LSC)',                 'LSC',  'organization', '/mini%20system/IMG/Organizations/LSC.png'),
  ('Junior Financial Management Society (JFMS)',    'JFMS', 'organization', '/mini%20system/IMG/Organizations/JFMS.png'),
  ('Sports Club',   'Sports Club',  'club', '/mini%20system/IMG/Clubs/Sports Club.png'),
  ('English Club',  'English Club', 'club', '/mini%20system/IMG/Clubs/ENGLISH.png'),
  ('Sci-Math Club', 'Sci-Math Club','club', '/mini%20system/IMG/Clubs/SCI-MATH.png'),
  ('SamFilko Club', 'SamFilko Club','club', '/mini%20system/IMG/Clubs/SAMFILKO.png');

INSERT INTO entity_funds (entity_id, collections, expenses, previous_funds) VALUES
  (1, 0, 0, 0),
  (2, 0, 0, 0),
  (3, 0, 0, 0),
  (4, 0, 0, 0),
  (5, 0, 0, 0),
  (6, 0, 0, 0),
  (7, 0, 0, 0),
  (8, 0, 0, 0),
  (9, 0, 0, 0),
  (10,0, 0, 0);

INSERT INTO users (full_name, email, password_hash, role, entity_id, auth_provider, is_google_account) VALUES
  ('Admin',          'admin',              '$2y$10$2ERoRtuBDOkvaD7Lb8xBLuiL4SM.kHv6/d8o3UjCn//lxERdqsO3u', 'admin',   NULL, 'local', 0),
  ('SSG Treasurer',  'treasurer@ssg.com',  '$2y$10$r4lTgR6oFh1lPunnl1dj3u.nrYt5ELhw27X8t.YPX0FAgymrKk6xW', 'officer', 1,    'local', 0),
  ('PADC Treasurer', 'treasurer@padc.com', '$2y$10$r4lTgR6oFh1lPunnl1dj3u.nrYt5ELhw27X8t.YPX0FAgymrKk6xW', 'officer', 3,    'local', 0);

INSERT INTO accredited_entries (name, entry_type, adviser, academic_year, accredited_date, expiry_date, status, remarks) VALUES
  ('Supreme Student Government (SSG)',              'Organization', 'Dr. Santos',    '2024-2025', '2024-01-10', '2025-01-10', 'Accredited', ''),
  ('Junior Operation Executive Society (JOES)',     'Organization', 'Ms. Reyes',     '2024-2025', '2024-02-15', '2025-02-15', 'Accredited', ''),
  ('Programers, Animators, Developers Clan (PADC)', 'Organization', 'Mr. Cruz',      '2024-2025', '2024-03-01', '2025-03-01', 'Accredited', ''),
  ('Young Mentors Organization (YMO)',              'Organization', 'Ms. Garcia',    '2023-2024', '2023-06-20', '2024-06-20', 'Expired',    ''),
  ('Library Student Council (LSC)',                 'Organization', 'Mr. Dela Cruz', '2024-2025', '2024-05-05', '2025-05-05', 'Accredited', ''),
  ('Junior Financial Management Society (JFMS)',    'Organization', 'Ms. Torres',    '2024-2025', '2024-07-01', '2025-07-01', 'Pending',    'Awaiting documents'),
  ('Sports Club',   'Club', 'Mr. Bautista', '2024-2025', '2024-01-20', '2025-01-20', 'Accredited', ''),
  ('English Club',  'Club', 'Ms. Lim',      '2024-2025', '2024-03-10', '2025-03-10', 'Accredited', ''),
  ('Sci-Math Club', 'Club', 'Dr. Ramos',    '2023-2024', '2023-09-01', '2024-09-01', 'Expired',    ''),
  ('SamFilko Club', 'Club', 'Mr. Santos',   '2024-2025', '2024-01-15', '2025-01-15', 'Accredited', '');
