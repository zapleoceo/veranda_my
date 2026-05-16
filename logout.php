<?php

// Legacy entry kept as a Slim delegator. See login.php for rationale.
// LoginController::logout owns the session-destroy + redirect.
require __DIR__ . '/public/index.php';
