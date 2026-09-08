<?php

declare(strict_types=1);

namespace FachDock\Parent;

enum ParentMagicLinkPurpose: string
{
    case VerifyEmail = 'verify_email';
    case Login = 'login';
}
