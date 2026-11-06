<?php
// Tiny read-through cache. Uses APCu when available, otherwise files in private/cache/.
// Add ?nocache=1 to a URL to bypass it (used for the with/without-cache benchmark).

function cache_on(){ return CACHE_ENABLED && empty($_GET['nocache']); }

function cache_remember($key, $ttl, $fn){
  if (!cache_on()) return $fn();

  if (function_exists('apcu_fetch') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)) {
    $v = apcu_fetch($key, $hit);
    if ($hit) return $v;
    $v = $fn();
    apcu_store($key, $v, $ttl);
    return $v;
  }

  $dir = STORAGE_PATH . 'cache/';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $file = $dir . md5($key) . '.cache';
  if (is_file($file) && filemtime($file) + $ttl > time()) {
    $v = @unserialize((string)@file_get_contents($file));
    if ($v !== false) return $v;
  }
  $v = $fn();
  $tmp = $file . '.' . bin2hex(random_bytes(4));
  if (@file_put_contents($tmp, serialize($v)) !== false) @rename($tmp, $file); // atomic swap
  return $v;
}