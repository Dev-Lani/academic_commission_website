<?php
// All settings in one place. Change database credentials and business rules here.

// Database
const DB_HOST = 'localhost';
const DB_NAME = 'acw';
const DB_USER = 'root';
const DB_PASS = '';

const APP_TIMEZONE  = 'Asia/Manila';
const CACHE_ENABLED = true; // false = benchmark without the cache

// Filesystem paths (all end with a slash)
define('ROOT_PATH',     dirname(__DIR__) . '/');
define('SRC_PATH',      ROOT_PATH . 'src/');
define('TEMPLATE_PATH', ROOT_PATH . 'templates/');
define('STORAGE_PATH',  ROOT_PATH . 'storage/');
define('PRIVATE_DIR',   STORAGE_PATH . 'outputs/'); // delivered files, never web-accessible

// Business rules
const AGREE_WINDOW_MIN = 30; // gaano katagal sasagot ang commissioner
const PREVIEW_MIN = 10;      // limited viewing window ng client sa output
const REVIEW_HOURS = 48;     // review window bago ang auto-release ng payment
const PLATFORM_FEE_PCT = 10; // flat muna; tiered cut sa Increment 6
const TIER_ORDER   = ['Bronze','Silver','Gold','Platinum'];
const CLIENT_TIERS = ['New','Reliable','Trusted','VIP'];
const TIER_FEE     = ['Bronze'=>10, 'Silver'=>8, 'Gold'=>6, 'Platinum'=>5]; // platform cut per commissioner tier
const TIER_JOBS    = ['Bronze'=>2, 'Silver'=>4, 'Gold'=>99, 'Platinum'=>99]; // max active jobs
