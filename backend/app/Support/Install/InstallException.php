<?php

namespace App\Support\Install;

use RuntimeException;

/** An installer step failed; the message is shown to the person installing. */
class InstallException extends RuntimeException {}
