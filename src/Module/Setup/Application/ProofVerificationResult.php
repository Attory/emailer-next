<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

enum ProofVerificationResult: string
{
    case Success = 'success';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case AttemptsExceeded = 'attempts_exceeded';
}
