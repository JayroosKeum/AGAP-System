<?php

// Configure GEMINI_API_KEY in the web-server/PHP environment. Never place an
// API key in this tracked file or expose it to browser JavaScript.
define('GEMINI_API_KEY', (string) (getenv('GEMINI_API_KEY') ?: ''));
