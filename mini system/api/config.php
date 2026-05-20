<?php

declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'mini_system_db';
const DB_USER = 'root';
const DB_PASS = '';
const GEMINI_MODEL = 'gemini-3.1-flash-lite';

if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', 'AIzaSyAPUIhA_5s0pvmc7QxzKSNHrZjE-PhAlwM');
}