<?php

namespace App\Exceptions;

/** An upload intent was not ready, already consumed or expired when a create/edit tried to claim it (audit N8). */
class UploadNotConsumable extends \LogicException
{
}
