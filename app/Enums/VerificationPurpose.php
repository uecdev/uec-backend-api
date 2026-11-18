<?php

namespace App\Enums;

enum VerificationPurpose: string
{
    case Register = 'register';
    case Account = 'account';   // verify an existing, unverified account at login
    case Reset = 'reset';
    case Contact = 'contact';
}
