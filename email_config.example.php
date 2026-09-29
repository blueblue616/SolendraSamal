<?php

return [
    'host'       => 'smtp.gmail.com',
    'username'   => 'emirencegumban@gmail.com',
    'password'   => 'racw mwnd znwo wcmx',
    'port'       => 587,
    'encryption' => 'tls',

    'from_email' => 'emirencegumban@gmail.com',
    'from_name'  => 'Solendra Samal',

    'admin_email' => 'emirencegumban@gmail.com',

    // DKIM Configuration (Optional - requires domain setup)
    // To enable DKIM signing, uncomment and configure these settings:
    // 'dkim_domain' => 'yourdomain.com', // Your domain name
    // 'dkim_selector' => 'default', // The selector used in your DKIM DNS record
    // 'dkim_private_key' => '/path/to/private.key', // Path to your DKIM private key file
    // 'dkim_passphrase' => '', // If your private key is passphrase-protected

    // SPF, DKIM, and DMARC Setup Instructions:
    // 
    // 1. SPF (Sender Policy Framework):
    //    Add this TXT record to your domain's DNS:
    //    @  IN  TXT  "v=spf1 include:_spf.google.com ~all"
    //    This authorizes Gmail to send emails on behalf of your domain.
    //
    // 2. DKIM (DomainKeys Identified Mail):
    //    - Generate DKIM keys using a tool like opendkim-genkey
    //    - Add the public key as a TXT record in your DNS:
    //      default._domainkey  IN  TXT  "v=DKIM1; k=rsa; p=YOUR_PUBLIC_KEY"
    //    - Store the private key securely on your server
    //    - Configure the dkim_* settings above with your private key path
    //
    // 3. DMARC (Domain-based Message Authentication, Reporting, and Conformance):
    //    Add this TXT record to your domain's DNS:
    //    @  IN  TXT  "v=DMARC1; p=none; rua=mailto:emirencegumban@gmail.com; ruf=mailto:emirencegumban@gmail.com"
    //    Start with p=none for monitoring, then change to p=quarantine or p=reject after verifying
];
