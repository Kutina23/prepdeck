<?php
require __DIR__ . '/../app/bootstrap.php';
Auth::logout(); redirect('login.php');
