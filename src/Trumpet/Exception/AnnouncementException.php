<?php

declare(strict_types=1);

namespace Trumpet\Exception;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Exception;
use Throwable;

/**
 * Custom exception for announcement operations
 */
class AnnouncementException extends Exception
{
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = "",
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);

        // Log the error
        error_log(sprintf(
            '[Announcement Error] %s in %s:%d',
            $message,
            $this->getFile(),
            $this->getLine()
        ));
    }
}
