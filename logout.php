<?php
require __DIR__.'/app/bootstrap.php';auth_logout();header('Location: '.url('login'));exit;
