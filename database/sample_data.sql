-- Sample content so the site is not empty on first run. Delete it later from the admin area.
-- First admin: admin@prepdeck.local / Admin@12345  (CHANGE THIS PASSWORD after your first sign-in; the admin overview warns you until you do)
INSERT IGNORE INTO `users` (`id`,`role_id`,`department_id`,`full_name`,`email`,`password_hash`,`status`) VALUES
 (1,4,NULL,'Admin','admin@prepdeck.local','$2y$10$HLJXGO1KKuVyk1KEELFNLOurOSorYADbFeZtgbtOU1Y6zmimi8OIe','active');

INSERT IGNORE INTO `courses` (`id`,`department_id`,`code`,`title`,`level`) VALUES
 (1,1,'CSC 101','Introduction to Computing',100),(2,1,'CSC 201','Data Structures and Algorithms',200),(3,1,'CSC 202','Computer Networks',200),(4,1,'CSC 301','Database Systems',300),
 (5,2,'NUR 102','Foundations of Nursing',100),(6,2,'NUR 201','Pharmacology',200),
 (7,3,'BUS 101','Principles of Management',100),(8,3,'BUS 201','Financial Accounting',200),
 (9,4,'MTH 201','Linear Algebra',200),(10,4,'ENG 202','Engineering Mechanics',200),
 (11,5,'BIO 201','Cell Biology',200),(12,5,'BIO 202','Genetics',200);

INSERT IGNORE INTO `academic_terms` (`id`,`year_label`,`semester`,`starts_on`,`ends_on`,`is_current`) VALUES
 (1,'2026/2027',1,'2026-09-14','2027-01-29',1);
INSERT IGNORE INTO `exam_timetable` (`id`,`course_id`,`term_id`,`exam_date`,`exam_time`,`venue`) VALUES
 (1,3,1,DATE_ADD(CURDATE(),INTERVAL 12 DAY),'09:00:00','Hall B'),(2,2,1,DATE_ADD(CURDATE(),INTERVAL 19 DAY),'09:00:00','Lab 2'),(3,9,1,DATE_ADD(CURDATE(),INTERVAL 26 DAY),'13:30:00','Hall A');

INSERT IGNORE INTO `papers` (`id`,`course_id`,`uploaded_by`,`title`,`doc_kind`,`exam_year`,`semester`,`file_path`,`file_size`,`has_answers`,`status`,`views`) VALUES
 (1,3,1,'Computer Networks Semester 1 Examination','past_question',2025,1,'sample-csc202-2025.pdf',2150,0,'published',0),
 (2,2,1,'Data Structures Semester 1 Examination','past_question',2025,1,'sample-csc201-2025.pdf',1985,0,'published',0),
 (3,6,1,'Pharmacology: Drug absorption (lecture notes)','lecture_notes',2026,1,'sample-nur201-notes.pdf',2298,0,'published',0);
UPDATE `courses` c SET paper_count=(SELECT COUNT(*) FROM papers p WHERE p.course_id=c.id AND p.status='published');

INSERT IGNORE INTO `faqs` (`id`,`question`,`answer`,`position`) VALUES
 (1,'What is PrepDeck?','PrepDeck collects your school''s past questions and turns them into flashcards, study guides and games.',1),
 (2,'What does Plus include?','PrepDeck AI, solved answers, study guides, lecture recording, unlimited note uploads, My notes, a custom focus timer and extra games.',2),
 (3,'How do I pay for Plus?','Choose monthly, per semester or yearly on the Plan page. Your school may also confirm payments by hand.',3),
 (4,'Can I upload my own lecture notes?','Yes. Free accounts can upload a few one-page PDFs. Plus accounts can upload unlimited documents of any length.',4);
INSERT IGNORE INTO `site_pages` (`slug`,`title`,`body`) VALUES
 ('terms','Terms of use','By using PrepDeck you agree to use the material for your own study, not to share your account, and not to upload content you do not have the right to share.\n\nEdit this page in Admin > Site content.'),
 ('privacy','Privacy policy','We store your name, email, department and study activity to run PrepDeck. We do not sell your data.\n\nEdit this page in Admin > Site content.'),
 ('refund','Refund policy','Plus payments can be refunded within 7 days if you have not used PrepDeck AI.\n\nEdit this page in Admin > Site content.');
INSERT IGNORE INTO `coupons` (`id`,`code`,`percent_off`,`max_uses`,`is_active`) VALUES (1,'WELCOME10',10,100,1);
INSERT IGNORE INTO `announcements` (`id`,`author_id`,`title`,`body`,`audience`) VALUES
 (1,1,'Welcome to PrepDeck','Past questions for every department are being added. Check the Past questions page and turn on course alerts.','all');

-- Sample flashcard decks (safe to delete)
INSERT INTO decks(owner_id,course_id,title,source,visibility,status,card_count,contains_answers) VALUES
 (1,(SELECT id FROM courses ORDER BY id LIMIT 1),'Key terms: networking basics','manual','shared','active',4,0),
 (1,(SELECT id FROM courses ORDER BY id LIMIT 1),'Solved past questions: set 1','paper','shared','active',2,1);
SET @d1 = (SELECT id FROM decks WHERE title='Key terms: networking basics' ORDER BY id DESC LIMIT 1);
SET @d2 = (SELECT id FROM decks WHERE title='Solved past questions: set 1' ORDER BY id DESC LIMIT 1);
INSERT INTO cards(deck_id,front,back,position) VALUES
 (@d1,'What does TCP guarantee that UDP does not?','Reliable, ordered delivery with retransmission.',0),
 (@d1,'What is an IP address?','A numeric label that identifies a device on a network.',1),
 (@d1,'What does DNS do?','Translates domain names into IP addresses.',2),
 (@d1,'What is a subnet mask?','Splits an IP address into network and host parts.',3),
 (@d2,'Define database normalisation.','Organising tables to reduce redundancy and update anomalies, using normal forms 1NF to BCNF.',0),
 (@d2,'Which TCP/IP layer handles routing?','The internet layer (IP).',1);
