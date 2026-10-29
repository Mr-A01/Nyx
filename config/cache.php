<?php
return ['default' => getenv('CACHE_DRIVER') ?: 'file', 'path' => 'storage/cache'];
