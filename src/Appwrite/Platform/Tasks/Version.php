<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Http\Http;
use Utopia\CLI\Console;
use Utopia\Platform\Action;

class Version extends Action
{
    public static function getName(): string
    {
        return 'version';
    }

    public function __construct()
    {
        $this
            ->desc('Get the server version')
            ->callback(function () {
                Console::log(Http::getEnv('_APP_VERSION', 'UNKNOWN'));
            });
    }
}
