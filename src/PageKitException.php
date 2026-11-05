<?php
declare(strict_types=1);

namespace CursorPageKit;

/** Messages are safe for CLI diagnostics; previous exceptions are not. */
final class PageKitException extends \RuntimeException
{
}
