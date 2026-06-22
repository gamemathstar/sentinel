<?php

return [
    /*
     | Secret used to derive the opaque answer-key mapping token
     | (version_token = HMAC(K_map, item_version_id)). See docs/04 §2.2.
     | In production this is an HSM/KMS-held key; here it defaults to a value derived
     | from APP_KEY so the mapping is stable per environment but not a literal constant.
     */
    'answer_key_map_secret' => env('LEGION_ANSWER_KEY_MAP_SECRET', 'map:'.env('APP_KEY', 'insecure-dev-key')),

    /*
     | Database connection used for the answer-key vault. In production this points at a
     | network-isolated instance whose role the app cannot read. Locally it shares the
     | primary connection but writes to the separate `vault` schema.
     */
    'vault_connection' => env('LEGION_VAULT_CONNECTION', null), // null = default connection
];
