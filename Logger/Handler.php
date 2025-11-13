<?php
/**
 * Copyright © Webscale. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Webscale\EventStream\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Handler extends Base
{
    protected $fileName = '/var/log/webscale_eventstream.log';
    protected $loggerType = \Monolog\Logger::INFO;
}
