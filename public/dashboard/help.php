<?php
require __DIR__ . '/../../app/bootstrap.php';
$u = require_login(); $plus = is_plus($u);
$active = 'help'; $title = 'Help'; $scripts = [];
$faqs = rows('SELECT question,answer FROM faqs ORDER BY position,id');
include ROOT . '/includes/dashboard_header.php';
echo page_head('Help', 'Quick answers. Still stuck? ' . (setting('contact_email') ? 'Email ' . setting('contact_email') . '.' : 'Ask your class rep or an admin.'));
if (!$faqs) echo empty_state('No questions have been added yet.');
echo '<div class="list">';
foreach ($faqs as $f) echo '<details class="rowx"><summary><h3 style="display:inline">' . e($f['question']) . '</h3></summary><p style="margin-top:8px;line-height:1.6">' . nl2br(e($f['answer'])) . '</p></details>';
echo '</div>';
include ROOT . '/includes/dashboard_footer.php';
