<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature flags
    |--------------------------------------------------------------------------
    |
    | customer_claims: the self-serve missing-transaction claim flow. Decision
    | (2026-08-14): OFF in production — customers contact the merchant, who
    | credits the missed sale through the manual path (backdated entries land
    | on_hold for admin review). Merchant-mediated puts the decision with the
    | party holding the sales records and funding the reward, and removes the
    | public-form spam surface. The claims domain stays dormant behind this
    | flag for a future formal disputes channel.
    |
    */

    'customer_claims' => (bool) env('FEATURE_CUSTOMER_CLAIMS', false),

];
