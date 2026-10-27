<?php

namespace App\Calendar;

use RuntimeException;

/** Google no longer gives access to the account: the person must connect again. */
final class CalendarAccessRevoked extends RuntimeException {}
