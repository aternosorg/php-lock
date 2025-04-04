<?php

namespace Aternos\Lock\Storage;

use Exception;

/**
 * An exception thrown by a storage client. This should only be used for known exceptions as the operation will be retried by default.
 */
class StorageException extends Exception
{

}
