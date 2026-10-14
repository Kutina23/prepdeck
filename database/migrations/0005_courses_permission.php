<?php
// The "courses" permission: add courses to the school. School admins hold it (they also manage departments,
// terms and exams in Admin > Departments & exams), and lecturers and class reps get it by default so they can
// add courses to the school they belong to. The platform owner can turn it off per role in Platform > Permissions,
// and a school with its own matrix only gets it when the platform owner adds it there (the matrix is the ceiling).
return function (PDO $db): void {
    $db->exec("INSERT IGNORE INTO permissions(code) VALUES ('courses')");
    foreach ([2, 3, 4] as $roleId) {   // class rep, lecturer, school admin
        $db->exec("INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT $roleId,id FROM permissions WHERE code='courses'");
    }
};
