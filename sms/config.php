<?php
return [
 'app_name'=>'FoxNetwork SMS',
 'admin_user'=>getenv('SMS_ADMIN_USER') ?: 'admin',
 'admin_password'=>getenv('SMS_ADMIN_PASSWORD') ?: 'change-me-now',
 'api_key'=>getenv('SMS_API_KEY') ?: '',
 'wvs_token'=>getenv('WHEREVERSIM_API_TOKEN') ?: '',
 'wvs_endpoint'=>getenv('WHEREVERSIM_ENDPOINT') ?: 'https://graphql.api.whereversim.com/graphql',
 'default_iccid'=>getenv('WHEREVERSIM_ICCID') ?: '',
 'originator'=>getenv('WHEREVERSIM_ORIGINATOR') ?: '',
];
