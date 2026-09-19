<?php
declare(strict_types=1);

/**
 * Normalize an address before validation, comparison, and storage.
 */
function fox_email_normalize(string $email): string
{
    return strtolower(trim($email));
}

/**
 * Public mailbox providers that customers may use for self-service accounts.
 * Business and custom-domain mailboxes are handled by the support team.
 */
function fox_personal_email_domains(): array
{
    return [
        'gmail.com', 'googlemail.com',
        'outlook.com', 'outlook.be', 'outlook.fr', 'outlook.nl',
        'hotmail.com', 'hotmail.be', 'hotmail.fr', 'hotmail.nl', 'hotmail.co.uk',
        'live.com', 'live.be', 'live.fr', 'live.nl', 'live.co.uk', 'msn.com',
        'yahoo.com', 'yahoo.be', 'yahoo.fr', 'yahoo.nl', 'yahoo.co.uk',
        'icloud.com', 'me.com', 'mac.com',
        'proton.me', 'protonmail.com', 'pm.me',
        'aol.com', 'gmx.com', 'gmx.net', 'gmx.de', 'gmx.fr', 'gmx.be',
        'mail.com', 'fastmail.com', 'fastmail.fm',
        'tuta.com', 'tutanota.com', 'tutamail.com', 'keemail.me',
        'zoho.com', 'yandex.com', 'yandex.ru',
        'telenet.be', 'skynet.be', 'proximus.be', 'scarlet.be', 'voo.be',
        'orange.fr', 'wanadoo.fr', 'free.fr', 'laposte.net', 'sfr.fr', 'bbox.fr',
        'ziggo.nl', 'kpnmail.nl', 'planet.nl', 'home.nl', 'btinternet.com',
    ];
}

function fox_email_domain(string $email): string
{
    $email = fox_email_normalize($email);
    $at = strrpos($email, '@');
    return $at === false ? '' : substr($email, $at + 1);
}

function fox_email_is_personal(string $email): bool
{
    $email = fox_email_normalize($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    $domain = fox_email_domain($email);
    if (in_array($domain, fox_personal_email_domains(), true)) return true;

    // Yahoo operates a number of regional public-mail domains.
    return preg_match('/^yahoo\.[a-z]{2,3}(?:\.[a-z]{2})?$/', $domain) === 1;
}

function fox_personal_email_required_message(): string
{
    return 'Use a personal email provider such as Gmail, Yahoo, Outlook, iCloud, or Proton. For a business or custom-domain email, contact info@foxnetwork.be.';
}
