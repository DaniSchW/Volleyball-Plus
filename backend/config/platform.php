<?php

return [
    /*
    | Superadmins of the platform (comma separated e-mail addresses): they
    | always have platform access – clubs and all accounts – even before an
    | account exists. Platform admins from the installer only manage clubs.
    */
    'superadmins' => array_values(array_filter(array_map(
        fn (string $email) => mb_strtolower(trim($email)),
        explode(',', (string) env('SUPERADMIN_EMAILS', 'service@web-schwarz.de')),
    ))),

    // Accounts unused this long get a deletion notice, and are deleted after the notice period.
    'inactive_after_days' => (int) env('ACCOUNT_INACTIVE_DAYS', 730),
    'deletion_notice_days' => (int) env('ACCOUNT_DELETION_NOTICE_DAYS', 14),
];
