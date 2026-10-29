<?php
return ['default' => getenv('LOG_CHANNEL') ?: 'stack', 'level' => getenv('LOG_LEVEL') ?: 'info', 'path' => 'storage/logs'];
