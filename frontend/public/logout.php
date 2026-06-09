<?php

require_once __DIR__ . '/../../backend/includes/auth.php';

logout_user();
redirect('index.php');
