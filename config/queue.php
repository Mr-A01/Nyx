<?php
return ['default' => getenv('QUEUE_DRIVER') ?: 'file', 'path' => 'storage/tmp/queue'];
