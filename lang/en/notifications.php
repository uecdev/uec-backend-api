<?php

return [
    'greeting' => 'Hello :name,',
    'greeting_generic' => 'Hello,',
    'not_you' => 'If you did not make this change, please contact UEC support immediately.',

    'otp' => [
        'subject' => 'Your UEC verification code is :code',
        'intro' => [
            'register' => 'Use this code to finish creating your UEC account:',
            'account' => 'Use this code to verify your UEC account:',
            'reset' => 'Use this code to reset your UEC password:',
            'contact' => 'Use this code to confirm your new contact details:',
        ],
        'expiry' => 'The code expires in :minutes minutes.',
        'ignore' => 'If you did not request this code, you can safely ignore this email.',
    ],

    'welcome' => [
        'subject' => 'Welcome to the UEC app',
        'body' => 'Your account is ready. Study, worship and grow daily with the Daily Delight, the Sunday School Manual, the UEC Hymnal and more.',
    ],
    'password_changed' => [
        'subject' => 'Your UEC password was changed',
        'body' => 'The password for your UEC account was just changed, and you have been signed out on all devices.',
    ],
    'email_changed' => [
        'subject' => 'Your UEC email address was changed',
        'body' => 'The email address on your UEC account was just updated.',
    ],
    'phone_changed' => [
        'subject' => 'Your UEC phone number was changed',
        'body' => 'The phone number on your UEC account was just updated.',
    ],
    'account_deleted' => [
        'subject' => 'Your UEC account has been deleted',
        'body' => 'Your UEC account and personal data have been deleted as you requested. Any active subscription has been cancelled. We are sorry to see you go.',
    ],

    'payment_receipt' => [
        'subject' => 'Your UEC payment receipt',
        'body' => 'Thank you. We have received your payment.',
        'plan' => 'Plan: :plan',
        'amount' => 'Amount: :amount',
        'reference' => 'Reference: :reference',
        'date' => 'Date: :date',
        'provider' => 'Paid via: :provider',
    ],

    'subscription' => [
        'started' => [
            'subject' => 'Your UEC Premium subscription is active',
            'body' => 'Your :plan subscription is now active. Hymn audio, solfa notation and offline downloads are unlocked.',
            'push' => 'Premium features are now unlocked. Enjoy!',
        ],
        'renewed' => [
            'subject' => 'Your UEC subscription has been renewed',
            'body' => 'Your :plan subscription has been renewed. Your next billing date is :date.',
            'push' => 'Your subscription has been renewed.',
        ],
        'plan_changed' => [
            'subject' => 'Your UEC plan has changed',
            'body' => 'Your subscription is now on the :plan plan. Your next billing date is :date.',
            'push' => 'Your new plan is now active.',
        ],
        'renewal_failed' => [
            'subject' => 'We could not renew your UEC subscription',
            'body' => 'We were unable to charge your card for the :plan plan. Premium access continues for :grace days while we retry; please update your payment method to avoid interruption.',
            'push' => 'Your subscription renewal failed. Tap to review.',
        ],
        'cancelled' => [
            'subject' => 'Your UEC subscription has been cancelled',
            'body' => 'Auto-renewal is off. You will keep premium access until :date.',
            'push' => 'Your subscription has been cancelled.',
        ],
        'expired' => [
            'subject' => 'Your UEC subscription has ended',
            'body' => 'Your premium access has ended. You can subscribe again at any time from the app.',
            'push' => 'Your premium access has ended. Tap to renew.',
        ],
        'plan_change_scheduled' => [
            'subject' => 'Your UEC plan change is scheduled',
            'body' => 'Your plan will change to :new_plan on :date. You will not be charged until then.',
            'push' => 'Your plan change is scheduled.',
        ],
        'reactivated' => [
            'subject' => 'Your UEC subscription will renew',
            'body' => 'Auto-renewal is back on for your subscription. Your next billing date is :date.',
            'push' => 'Your subscription will renew automatically.',
        ],
    ],
];
