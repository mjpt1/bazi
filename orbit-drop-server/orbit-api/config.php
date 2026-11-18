<?php
// SQLite database kept OUTSIDE the public folder: /home/<user>/orbit-data/orbit.sqlite
// (No password needed. To switch to MySQL later, see config.sample.php.)
$dir = dirname(__DIR__) . '/orbit-data';
if (!is_dir($dir)) mkdir($dir, 0700, true);
return ['dsn' => 'sqlite:' . $dir . '/orbit.sqlite'];
