<?php

// Legacy entry — Google OAuth Cloud Console is registered with
// /auth_callback.php as the redirect URI, so this shim is REQUIRED.
// Delegates to Slim's CallbackController (route /auth_callback.php).
require __DIR__ . '/public/index.php';
